<?php

namespace App\Services;

use App\Repositories\AiProviderConnectionsRepository;
use App\Repositories\Connection;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class SocialEditorialAgentService
{
    private array $config;
    private Connection $db;

    public function __construct(?array $config = null, ?Connection $db = null)
    {
        $this->config = $config ?? require dirname(__DIR__, 2) . '/config/social_editorial.php';
        $this->db = $db ?? new Connection();
    }

    public function runAll(string $mode = '', int $userId = 0, string $trigger = 'MANUAL'): array
    {
        $mode = $this->normalizeMode($mode ?: (string)($this->config['default_mode'] ?? 'REVIEW_BEFORE_PUBLISH'));
        $this->db->query("INSERT INTO social_editorial_runs(trigger_type,mode,status,created_by) VALUES(:trigger,:mode,'RUNNING',:user)");
        $this->db->bind(':trigger', in_array($trigger, ['MANUAL','SCHEDULE','SYSTEM'], true) ? $trigger : 'MANUAL');
        $this->db->bind(':mode', $mode);$this->db->bind(':user', $userId ?: null);$this->db->execute();
        $runId = (int)$this->db->lastId();$summary = ['run_id'=>$runId,'mode'=>$mode,'brands'=>[]];$failed = 0;
        foreach ((array)($this->config['brands'] ?? []) as $brandKey => $brand) {
            if (empty($brand['enabled'])) continue;
            try {$summary['brands'][$brandKey] = $this->runBrand($runId, $brandKey, $brand, $mode);}
            catch (\Throwable $e) {$failed++;$summary['brands'][$brandKey] = ['brand'=>$brand['name'] ?? $brandKey,'status'=>'FAILED','error'=>$e->getMessage()];}
        }
        $prepared = array_sum(array_map(fn($b)=>(int)($b['prepared']??0),$summary['brands']));
        $status = $failed ? ($prepared ? 'PARTIAL' : 'FAILED') : ($mode === 'REVIEW_BEFORE_PUBLISH' && $prepared ? 'AWAITING_REVIEW' : 'COMPLETED');
        $this->db->query("UPDATE social_editorial_runs SET status=:status,summary_json=:summary,error_message=:error,finished_at=NOW() WHERE id=:id");
        $this->db->bind(':status',$status);$this->db->bind(':summary',json_encode($summary,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        $this->db->bind(':error',$failed ? $failed.' brand run(s) failed.' : null);$this->db->bind(':id',$runId);$this->db->execute();$summary['status']=$status;
        return $summary;
    }

    private function runBrand(int $runId, string $brandKey, array $brand, string $mode): array
    {
        $articles = $this->discover($brand);if (count($articles) < 3) throw new RuntimeException('Fewer than three valid recent articles were found.');
        $ranked = $this->rank($brand, $articles);usort($ranked, fn($a,$b)=>$b['score'] <=> $a['score']);$selected = array_slice($ranked,0,3);
        $candidateIds=[];foreach($ranked as $article){$chosen=in_array((int)$article['id'],array_column($selected,'id'),true);$this->db->query("INSERT INTO social_editorial_candidates(run_id,brand_key,site_key,article_id,article_url,title,score,ranking_reason,selected,metadata_json) VALUES(:run,:brand,:site,:article,:url,:title,:score,:reason,:selected,:metadata)");
            foreach(['run'=>$runId,'brand'=>$brandKey,'site'=>$brand['site_key'],'article'=>$article['id'],'url'=>$article['url'],'title'=>$article['title'],'score'=>$article['score'],'reason'=>mb_substr((string)$article['reason'],0,500),'selected'=>$chosen?1:0,'metadata'=>json_encode($article,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)] as $k=>$v)$this->db->bind(':'.$k,$v);$this->db->execute();$candidateIds[(int)$article['id']]=(int)$this->db->lastId();}
        $drafts=$this->adapt($brand,$selected);$prepared=0;$skipped=0;$tz=new DateTimeZone((string)($this->config['timezone']??'America/New_York'));$base=(new DateTimeImmutable('tomorrow 10:00',$tz));
        foreach($selected as $index=>$article){foreach(['linkedin','facebook'] as $network){$format=$network==='linkedin'?'standard_page_post':'link_post';if($this->alreadyDistributed($brandKey,(int)$article['id'],$network,$format)){$skipped++;continue;}
                $draft=$drafts[(string)$article['id']][$network]??$this->fallbackDraft($brand,$article,$network);$copy=trim((string)($draft['copy']??''));if(!str_contains($copy,$article['url']))$copy.="\n\nRead the original article from {$brand['name']}: {$article['url']}";$days=(int)(($this->config['cadence_days'][$index]??($index*2)));$scheduled=$base->modify('+'.$days.' days')->format('Y-m-d H:i:s');$state=$mode==='AUTO_PUBLISH'?'SCHEDULED':'PREPARED';
                $this->db->query("INSERT INTO social_editorial_publications(run_id,candidate_id,brand_key,site_key,article_id,article_url,network,format,state,headline,copy_text,hashtags_json,image_url,cta_type,scheduled_for) VALUES(:run,:candidate,:brand,:site,:article,:url,:network,:format,:state,:headline,:copy,:hashtags,:image,:cta,:scheduled)");
                foreach(['run'=>$runId,'candidate'=>$candidateIds[(int)$article['id']],'brand'=>$brandKey,'site'=>$brand['site_key'],'article'=>$article['id'],'url'=>$article['url'],'network'=>$network,'format'=>$format,'state'=>$state,'headline'=>mb_substr((string)($draft['headline']??$article['title']),0,255),'copy'=>$copy,'hashtags'=>json_encode(array_slice((array)($draft['hashtags']??[]),0,$network==='linkedin'?5:2)),'image'=>$article['featured_image']?:null,'cta'=>in_array($draft['cta']??'', ['LEARN','DISCUSS','READ','CONTACT','EXPLORE','REGISTER'],true)?$draft['cta']:'READ','scheduled'=>$scheduled] as $k=>$v)$this->db->bind(':'.$k,$v);$this->db->execute();$prepared++;}}
        return ['brand'=>$brand['name'],'status'=>'COMPLETED','reviewed'=>count($articles),'selected'=>array_column($selected,'title'),'prepared'=>$prepared,'skipped_duplicates'=>$skipped];
    }

    public function discover(array $brand): array
    {
        $this->db->query("SELECT c.id,c.title,c.slug,c.excerpt,c.body_html,c.meta_title,c.meta_description,c.canonical_url,c.featured_image_url,c.published_at,c.updated_at,c.schema_json,c.content_type,c.type,cat.name category,r.route
          FROM cms_contents c LEFT JOIN cms_categories cat ON cat.id=c.id_cms_category LEFT JOIN cms_routes r ON r.id_content=c.id AND r.is_main=1 AND r.status='ACTIVE' AND r.site_key=c.site_key
          WHERE c.id_owner=:owner AND c.site_key=:site AND c.status='PUBLISHED' AND c.published_at IS NOT NULL
          AND LOWER(COALESCE(c.content_type,c.type,'')) IN ('blog','post','blog_post','article')
          AND CHAR_LENGTH(TRIM(COALESCE(c.body_html,'')))>=500
          AND LOWER(CONCAT_WS(' ',c.title,c.slug,c.excerpt)) NOT REGEXP 'test|placeholder|lorem ipsum|article direction'
          ORDER BY c.published_at DESC,c.id DESC LIMIT 18");
        $this->db->bind(':owner',(int)$brand['owner_id']);$this->db->bind(':site',(string)$brand['site_key']);$rows=$this->db->fetchAll();$valid=[];$seen=[];
        foreach($rows as $row){$url=$this->canonicalUrl($brand,$row);if(isset($seen[$url])||!$this->urlIsPublic($url))continue;$seen[$url]=true;$plain=trim(preg_replace('/\s+/u',' ',strip_tags((string)$row->body_html)));
            $valid[]=['id'=>(int)$row->id,'title'=>trim((string)$row->title),'url'=>$url,'published_at'=>(string)$row->published_at,'updated_at'=>(string)$row->updated_at,'excerpt'=>trim((string)($row->excerpt?:$row->meta_description)),'content'=>mb_substr($plain,0,12000),'featured_image'=>$this->validAssetUrl((string)$row->featured_image_url),'category'=>(string)$row->category,'metadata'=>json_decode((string)$row->schema_json,true)?:[]];if(count($valid)===6)break;}
        if(count($valid)<6)$valid=$this->discoverRemote($brand,$valid,$seen);
        return $valid;
    }

    private function discoverRemote(array $brand,array $valid,array $seen): array
    {
        $sitemap=rtrim((string)$brand['website'],'/').'/sitemap-blog.xml';$xml=$this->download($sitemap);if($xml==='')return $valid;
        libxml_use_internal_errors(true);$map=simplexml_load_string($xml);if(!$map)return $valid;$entries=[];foreach($map->url as $entry){$url=trim((string)$entry->loc);if($url!=='')$entries[]=['url'=>$url,'lastmod'=>(string)$entry->lastmod];}usort($entries,fn($a,$b)=>strcmp($b['lastmod'],$a['lastmod']));
        $blogRoot=rtrim((string)$brand['website'],'/').rtrim((string)$brand['blog_path'],'/').'/';
        foreach($entries as $entry){$url=$entry['url'];if(rtrim($url,'/').'/'===$blogRoot||!str_starts_with($url,$blogRoot)||isset($seen[$url])||!$this->urlIsPublic($url))continue;$html=$this->download($url);if($html==='')continue;$dom=new \DOMDocument();@$dom->loadHTML($html);$xpath=new \DOMXPath($dom);$title=$this->meta($xpath,'property','og:title')?:trim((string)($xpath->query('//h1')->item(0)?->textContent));$excerpt=$this->meta($xpath,'name','description')?:$this->meta($xpath,'property','og:description');$image=$this->validAssetUrl($this->meta($xpath,'property','og:image'));if($image!==''&&!$this->urlIsPublic($image))$image='';$nodes=$xpath->query('//article//p|//main//p');$parts=[];foreach($nodes as $node){$text=trim(preg_replace('/\s+/u',' ',$node->textContent));if(mb_strlen($text)>=40)$parts[]=$text;}$content=implode("\n\n",$parts);if($title===''||mb_strlen($content)<500||preg_match('/test|placeholder|lorem ipsum|article direction/i',$title.' '.$excerpt))continue;
            $valid[]=['id'=>(int)hexdec(substr(sha1($url),0,7)),'title'=>$title,'url'=>$url,'published_at'=>$entry['lastmod'],'updated_at'=>$entry['lastmod'],'excerpt'=>$excerpt,'content'=>mb_substr($content,0,12000),'featured_image'=>$image,'category'=>'','metadata'=>['source'=>'public_sitemap']];$seen[$url]=true;if(count($valid)===6)break;}
        return $valid;
    }

    private function meta(\DOMXPath $xpath,string $attribute,string $value): string{$node=$xpath->query('//meta[@'.$attribute.'="'.$value.'"]/@content')->item(0);return trim((string)($node?->nodeValue??''));}
    private function download(string $url): string{$ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_USERAGENT=>'VNV-SocialEditorialAgent/1.0']);$body=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);return is_string($body)&&$code>=200&&$code<300?$body:'';}

    private function rank(array $brand,array $articles): array
    {
        foreach($articles as &$a){$length=mb_strlen($a['content']);$a['score']=min(100,45+($length>=1800?15:5)+($a['featured_image']?8:0)+($a['excerpt']?7:0)+($a['category']?5:0)+min(15,(int)(mb_strlen($a['title'])/5)));$a['reason']='Complete, recent brand article with useful editorial depth.';}unset($a);
        try{$provider=$this->preferredProvider((int)$brand['owner_id']);$shape=['rankings'=>array_map(fn($a)=>['article_id'=>$a['id'],'score'=>0,'reason'=>''],$articles)];$ai=(new AiModelGateway())->json((int)$brand['owner_id'],$provider,'You are a social editorial director. Rank only the supplied verified articles. Score 0-100 for brand relevance, usefulness, originality, discussion potential, business and educational value, timeliness, writing, headline, LinkedIn fit, Facebook fit and brand representation. Do not add facts. Return concise reasons.',['brand'=>$brand['name'],'voice'=>$brand['voice'],'articles'=>array_map(fn($a)=>['article_id'=>$a['id'],'title'=>$a['title'],'excerpt'=>$a['excerpt'],'category'=>$a['category'],'content'=>mb_substr($a['content'],0,3500)],$articles)],$shape);$byId=[];foreach((array)($ai['rankings']??[]) as $r)$byId[(int)($r['article_id']??0)]=$r;foreach($articles as &$a)if(isset($byId[$a['id']])){$a['score']=max(0,min(100,(int)$byId[$a['id']]['score']));$a['reason']=mb_substr(trim((string)$byId[$a['id']]['reason']),0,500);}unset($a);}catch(\Throwable $e){foreach($articles as &$a)$a['reason'].=' AI ranking unavailable: '.mb_substr($e->getMessage(),0,160);unset($a);}return $articles;
    }

    private function adapt(array $brand,array $selected): array
    {
        $fallback=[];foreach($selected as $a)$fallback[(string)$a['id']]=['linkedin'=>$this->fallbackDraft($brand,$a,'linkedin'),'facebook'=>$this->fallbackDraft($brand,$a,'facebook')];
        try{$shape=['articles'=>array_map(fn($a)=>['article_id'=>$a['id'],'linkedin'=>['headline'=>'','copy'=>'','hashtags'=>[],'cta'=>'READ'],'facebook'=>['headline'=>'','copy'=>'','hashtags'=>[],'cta'=>'READ']],$selected)];$ai=(new AiModelGateway())->json((int)$brand['owner_id'],$this->preferredProvider((int)$brand['owner_id']),'Adapt each verified source article for the official company Pages. LinkedIn: a substantive professional Page post, concise paragraphs, useful insight, natural original-source link, 2-5 hashtags. Facebook: short hook and 1-3 sentences plus original link, at most 2 hashtags. Never claim native LinkedIn Article publication; the API workflow supports standard company Page posts. Never invent facts.',['brand'=>$brand['name'],'voice'=>$brand['voice'],'articles'=>array_map(fn($a)=>['article_id'=>$a['id'],'title'=>$a['title'],'url'=>$a['url'],'excerpt'=>$a['excerpt'],'content'=>mb_substr($a['content'],0,6000)],$selected)],$shape);foreach((array)($ai['articles']??[]) as $d){$id=(string)(int)($d['article_id']??0);if(isset($fallback[$id]))foreach(['linkedin','facebook'] as $network)if(!empty($d[$network]['copy']))$fallback[$id][$network]=$d[$network];}}catch(\Throwable){}return $fallback;
    }

    private function fallbackDraft(array $brand,array $article,string $network): array
    {
        $excerpt=trim((string)$article['excerpt'])?:mb_substr($article['content'],0,240).'…';$copy=$network==='linkedin'?"{$article['title']}\n\n{$excerpt}\n\nRead the original article and explore more resources from {$brand['name']}: {$article['url']}":"{$article['title']}\n\n{$excerpt}\n\n{$article['url']}";
        return ['headline'=>$article['title'],'copy'=>$copy,'hashtags'=>[],'cta'=>'READ'];
    }

    private function preferredProvider(int $owner): string
    {
        $repo=new AiProviderConnectionsRepository();try{if($repo->credentials($owner,'anthropic'))return 'anthropic';}catch(\Throwable){}return $repo->defaultProvider($owner);
    }
    private function canonicalUrl(array $brand,object $row): string{$canonical=trim((string)$row->canonical_url);$base=rtrim((string)$brand['website'],'/');if(filter_var($canonical,FILTER_VALIDATE_URL)&&str_starts_with(strtolower($canonical),strtolower($base).'/'))return $canonical;$route=trim((string)$row->route);return $base.($route!==''?'/'.ltrim($route,'/'):(string)$brand['blog_path'].rawurlencode((string)$row->slug).'/');}
    private function urlIsPublic(string $url): bool{$ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_NOBODY=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>12,CURLOPT_USERAGENT=>'VNV-SocialEditorialAgent/1.0']);curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$effective=(string)curl_getinfo($ch,CURLINFO_EFFECTIVE_URL);curl_close($ch);return $code>=200&&$code<400&&!preg_match('~(?:localhost|127\.0\.0\.1|staging|test\.)~i',$effective);}
    private function validAssetUrl(string $url): string{return filter_var($url,FILTER_VALIDATE_URL)&&!preg_match('~(?:localhost|127\.0\.0\.1|placeholder)~i',$url)?$url:'';}
    private function alreadyDistributed(string $brand,int $article,string $network,string $format): bool{$this->db->query("SELECT id FROM social_editorial_publications WHERE brand_key=:brand AND article_id=:article AND network=:network AND format=:format AND state NOT IN ('FAILED','SKIPPED') LIMIT 1");foreach(['brand'=>$brand,'article'=>$article,'network'=>$network,'format'=>$format] as $k=>$v)$this->db->bind(':'.$k,$v);return (bool)$this->db->fetchOne();}
    private function normalizeMode(string $mode): string{return $mode==='AUTO_PUBLISH'?'AUTO_PUBLISH':'REVIEW_BEFORE_PUBLISH';}

    public function history(int $limit=100): array{$limit=max(1,min(500,$limit));$this->db->query("SELECT p.*,c.title article_title FROM social_editorial_publications p JOIN social_editorial_candidates c ON c.id=p.candidate_id ORDER BY p.id DESC LIMIT {$limit}");return $this->db->fetchAll();}
}
