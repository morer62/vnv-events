<?php

namespace App\Services;

use App\Repositories\Connection;

/**
 * Live VNV business knowledge assembled from the three authoritative sources:
 * the published agreement, the public catalog/landing pages, and the estimate catalog.
 */
final class MochiBusinessKnowledgeService
{
    public function __construct(private int $ownerId,private string $siteKey='vnvevents',private ?Connection $db=null){$this->db??=new Connection();}

    public function contract(string $question): array
    {
        $sections=$this->contractSections();
        $tokens=$this->tokens($question.' '.$this->contractSynonyms($question));
        foreach($sections as &$section){$haystack=$this->normalize($section['title'].' '.$section['text']);$section['score']=array_sum(array_map(fn($token)=>str_contains($haystack,$this->normalize($token))?1:0,$tokens));}unset($section);
        usort($sections,fn($a,$b)=>$b['score']<=>$a['score']);
        $matches=array_values(array_filter($sections,fn($section)=>$section['score']>0));
        $matches=array_slice($matches?:$sections,0,4);
        $facts=['source'=>'VNV Events General Terms and Conditions','source_url'=>'/terms-and-conditions/','estimate_contract_policy'=>'Every VNV Events estimate, regardless of which interface creates it, must be assigned the owner\'s General Conditions contract by default. Creation must stop if that contract is not configured. A valid vnvevents.com order-access link identifies the exact estimate, including an older estimate, and must never be replaced by the currently active draft. Past orders remain administratively editable for corrections to date, time, address, notes and payment schedule; recorded payments and protected commercial line items are not rewritten by that correction.','clauses'=>array_map(fn($section)=>['title'=>$section['title'],'text'=>$section['text']],$matches)];
        $items=array_map(fn($section)=>['title'=>'Contrato · '.$section['title'],'meta'=>'Condiciones generales vigentes','detail'=>mb_substr($section['text'],0,520),'url'=>'/terms-and-conditions/'],$matches);
        return ['reply'=>'Consulté las condiciones generales vigentes y encontré '.count($matches).' sección(es) relacionadas.','items'=>$items,'facts'=>$facts];
    }

    public function catalog(string $question): array
    {
        $terms=$this->tokens($question);
        $estimate=$this->estimateServices($terms);
        $store=$this->storeProducts($terms);
        $pages=$this->landingPages($terms);
        $scores=[];
        foreach(array_merge($estimate,$store,$pages) as $row)$scores[]=(float)($row['match_score']??1.0);
        $confidence=$scores?max($scores):0.0;
        $localMatchFound=$confidence>=0.48;
        $estimateItems=[];$storeItems=[];$pageItems=[];
        foreach($estimate as $row)$estimateItems[]=['title'=>$row['name'],'meta'=>'Catálogo interno de estimates · $'.number_format((float)$row['price'],2).($row['is_variable']==='YES'?' variable':''),'detail'=>mb_substr(trim($row['description'].' '.$row['requirements']),0,420),'url'=>'/panel/planner-hub/management/orders/services'];
        foreach($store as $row)$storeItems[]=['title'=>$row['name'],'meta'=>'Tienda pública · '.$row['price_label'],'detail'=>mb_substr(trim($row['description'].' '.($row['variations']??'')),0,420),'url'=>'/product/'.$row['slug'].'/'];
        foreach($pages as $row)$pageItems[]=['title'=>$row['title'],'meta'=>'Landing page publicada','detail'=>mb_substr($row['excerpt'],0,420),'url'=>'/'.ltrim($row['route'],'/')];
        $items=array_merge(array_slice($estimateItems,0,3),array_slice($storeItems,0,2),array_slice($pageItems,0,1));
        return [
            'reply'=>$localMatchFound
                ? 'Encontré '.count($estimate).' servicio(s) en el catálogo de estimates, '.count($store).' producto(s) públicos y '.count($pages).' landing page(s) relacionadas.'
                : 'No encontré una coincidencia local confiable en servicios de estimates, tienda pública ni landing pages. Esto no significa que VNV lo ofrezca; puedo investigar referencias externas si lo necesitas.',
            'items'=>$items,
            'facts'=>[
                'authoritative_sources'=>['general_terms','estimate_services','public_store_and_landings'],
                'local_lookup_first'=>true,
                'normalized_terms'=>$terms,
                'local_match_found'=>$localMatchFound,
                'local_match_confidence'=>round($confidence,3),
                'external_research_allowed'=>$localMatchFound===false,
                'external_results_are_not_vnv_offerings'=>true,
                'estimate_services'=>$estimate,
                'store_products'=>$store,
                'landing_pages'=>$pages,
            ],
        ];
    }

    public function audit(string $question): array
    {
        $catalog=$this->catalog($question);
        $estimate=$catalog['facts']['estimate_services'];$store=$catalog['facts']['store_products'];$pages=$catalog['facts']['landing_pages'];
        $matches=[];$priceMismatches=[];
        foreach($estimate as $internal){foreach($store as $public){$a=$this->normalizeName($internal['name']);$b=$this->normalizeName($public['name']);if($a===''||$b===''||$a!==$b)continue;$match=['estimate_service'=>$internal['name'],'estimate_price'=>(float)$internal['price'],'store_product'=>$public['name'],'store_price'=>$public['effective_price'],'store_price_label'=>$public['price_label']];$matches[]=$match;if($public['effective_price']!==null&&abs((float)$internal['price']-(float)$public['effective_price'])>0.009)$priceMismatches[]=$match;}}
        $landingMentions=[];
        foreach($pages as $page){if(!empty($page['price_mentions']))$landingMentions[]=['title'=>$page['title'],'route'=>$page['route'],'price_mentions'=>$page['price_mentions']];}
        $facts=$catalog['facts']+['matched_catalog_entries'=>$matches,'possible_price_mismatches'=>$priceMismatches,'landing_price_mentions'=>$landingMentions,'interpretation_limit'=>'A price difference is a possible mismatch, not automatically a contradiction; packages, units, guest minimums and variants must also be compared.'];
        return ['reply'=>'Comparé en vivo el catálogo interno de estimates, la tienda pública y las landing pages. Encontré '.count($matches).' coincidencia(s) de nombre y '.count($priceMismatches).' posible(s) diferencia(s) de precio para revisar.','items'=>$catalog['items'],'facts'=>$facts];
    }

    private function estimateServices(array $terms): array
    {
        [$where,$params]=$this->searchWhere("LOWER(CONCAT_WS(' ',CONVERT(name USING utf8mb4),CONVERT(description USING utf8mb4),CONVERT(requirements USING utf8mb4))) COLLATE utf8mb4_unicode_ci",$terms,['owner'=>$this->ownerId]);
        $this->db->query("SELECT id,name,description,requirements,price,is_variable FROM orders_services WHERE id_owner=:owner AND COALESCE(is_archived,0)=0 {$where} ORDER BY name LIMIT 12");$this->bind($params);
        $rows=$this->db->fetchAll();
        if(!$rows&&$terms)return $this->fuzzyEstimateServices(implode(' ',$terms));
        $needle=implode(' ',$terms);$mapped=array_map(fn($r)=>['id'=>(int)$r->id,'name'=>(string)$r->name,'description'=>mb_substr(trim(strip_tags((string)$r->description)),0,700),'requirements'=>mb_substr(trim(strip_tags((string)$r->requirements)),0,900),'price'=>(float)$r->price,'is_variable'=>(string)$r->is_variable,'match_score'=>$terms?round($this->similarity($needle,(string)$r->name),3):1.0],$rows);
        return array_values(array_filter($mapped,fn($row)=>$row['match_score']>=0.48));
    }

    private function storeProducts(array $terms): array
    {
        [$where,$params]=$this->searchWhere("LOWER(CONCAT_WS(' ',CONVERT(p.name USING utf8mb4),CONVERT(p.short_description USING utf8mb4),CONVERT(p.description USING utf8mb4))) COLLATE utf8mb4_unicode_ci",$terms,['owner'=>$this->ownerId,'site'=>$this->siteKey]);
        $this->db->query("SELECT p.id,p.name,p.slug,p.short_description,p.description,p.product_type,p.price,p.promo_price,MIN(v.price) min_price,MAX(v.price) max_price,SUBSTRING_INDEX(GROUP_CONCAT(CASE WHEN LOWER(v.name) NOT LIKE '%additional topping%' THEN COALESCE(NULLIF(v.promo_price,0),v.price) END ORDER BY v.sort_order SEPARATOR ','),',',1) primary_price,GROUP_CONCAT(CONCAT(CONVERT(v.name USING utf8mb4),': $',FORMAT(COALESCE(NULLIF(v.promo_price,0),v.price),2)) ORDER BY v.sort_order SEPARATOR ' | ') variations FROM store_products p LEFT JOIN store_product_variations v ON v.id_product=p.id AND v.status='ACTIVE' WHERE p.id_owner=:owner AND p.site_key=:site AND p.status='ACTIVE' AND p.is_public=1 {$where} GROUP BY p.id ORDER BY p.is_featured DESC,p.name LIMIT 12");$this->bind($params);
        $rows=$this->db->fetchAll();
        if(!$rows&&$terms)return $this->fuzzyStoreProducts(implode(' ',$terms));
        $needle=implode(' ',$terms);$mapped=array_map(function($r)use($terms,$needle){$base=(float)($r->promo_price?:$r->price);$effective=$r->product_type==='VARIABLE'?($base>0?$base:($r->primary_price!==null?(float)$r->primary_price:($r->min_price!==null?(float)$r->min_price:null))):$base;$label=$r->product_type==='VARIABLE'?'$'.number_format((float)$r->min_price,2).' - $'.number_format((float)$r->max_price,2):'$'.number_format($base,2);return ['id'=>(int)$r->id,'name'=>(string)$r->name,'slug'=>(string)$r->slug,'description'=>mb_substr(trim(strip_tags((string)($r->short_description?:$r->description))),0,700),'price_label'=>$label,'effective_price'=>$effective,'variations'=>mb_substr((string)$r->variations,0,900),'match_score'=>$terms?round($this->similarity($needle,(string)$r->name),3):1.0];},$rows);
        return array_values(array_filter($mapped,fn($row)=>$row['match_score']>=0.48));
    }

    private function landingPages(array $terms): array
    {
        [$where,$params]=$this->searchWhere("LOWER(CONCAT_WS(' ',CONVERT(c.title USING utf8mb4),CONVERT(c.excerpt USING utf8mb4),CONVERT(c.body_html USING utf8mb4))) COLLATE utf8mb4_unicode_ci",$terms,['owner'=>$this->ownerId,'site'=>$this->siteKey]);
        $this->db->query("SELECT c.title,c.excerpt,c.body_html,r.route FROM cms_contents c JOIN cms_routes r ON r.id_content=c.id AND r.site_key=c.site_key AND r.status='ACTIVE' AND r.is_main=1 WHERE c.id_owner=:owner AND c.site_key=:site AND c.status='PUBLISHED' AND c.type IN ('page','landing') {$where} ORDER BY c.updated_at DESC LIMIT 10");$this->bind($params);
        $rows=$this->db->fetchAll();
        if(!$rows&&$terms)return $this->fuzzyLandingPages(implode(' ',$terms));
        $needle=implode(' ',$terms);$mapped=array_map(function($r)use($terms,$needle){$search=mb_substr(trim(strip_tags((string)$r->body_html)),0,3000);preg_match_all('/\$\s*\d+(?:[.,]\d{1,2})?/u',$search,$amounts);return ['title'=>(string)$r->title,'excerpt'=>mb_substr(trim(strip_tags((string)($r->excerpt?:$r->body_html))),0,700),'route'=>(string)$r->route,'price_mentions'=>array_values(array_unique($amounts[0]??[])),'match_score'=>$terms?round($this->similarity($needle,(string)$r->title),3):1.0];},$rows);
        return array_values(array_filter($mapped,fn($row)=>$row['match_score']>=0.48));
    }

    private function fuzzyEstimateServices(string $needle): array
    {
        $this->db->query('SELECT id,name,description,requirements,price,is_variable FROM orders_services WHERE id_owner=:owner AND COALESCE(is_archived,0)=0 ORDER BY name LIMIT 800');
        $this->bind(['owner'=>$this->ownerId]);
        $ranked=$this->rankRows($this->db->fetchAll(),$needle,'name');
        return array_map(fn($pair)=>[
            'id'=>(int)$pair['row']->id,'name'=>(string)$pair['row']->name,
            'description'=>mb_substr(trim(strip_tags((string)$pair['row']->description)),0,700),
            'requirements'=>mb_substr(trim(strip_tags((string)$pair['row']->requirements)),0,900),
            'price'=>(float)$pair['row']->price,'is_variable'=>(string)$pair['row']->is_variable,
            'match_score'=>$pair['score'],
        ],$ranked);
    }

    private function fuzzyStoreProducts(string $needle): array
    {
        $this->db->query("SELECT p.id,p.name,p.slug,p.short_description,p.description,p.product_type,p.price,p.promo_price,MIN(v.price) min_price,MAX(v.price) max_price,SUBSTRING_INDEX(GROUP_CONCAT(CASE WHEN LOWER(v.name) NOT LIKE '%additional topping%' THEN COALESCE(NULLIF(v.promo_price,0),v.price) END ORDER BY v.sort_order SEPARATOR ','),',',1) primary_price,GROUP_CONCAT(CONCAT(CONVERT(v.name USING utf8mb4),': $',FORMAT(COALESCE(NULLIF(v.promo_price,0),v.price),2)) ORDER BY v.sort_order SEPARATOR ' | ') variations FROM store_products p LEFT JOIN store_product_variations v ON v.id_product=p.id AND v.status='ACTIVE' WHERE p.id_owner=:owner AND p.site_key=:site AND p.status='ACTIVE' AND p.is_public=1 GROUP BY p.id ORDER BY p.name LIMIT 800");
        $this->bind(['owner'=>$this->ownerId,'site'=>$this->siteKey]);
        $ranked=$this->rankRows($this->db->fetchAll(),$needle,'name');
        return array_map(function($pair){$r=$pair['row'];$base=(float)($r->promo_price?:$r->price);$effective=$r->product_type==='VARIABLE'?($base>0?$base:($r->primary_price!==null?(float)$r->primary_price:($r->min_price!==null?(float)$r->min_price:null))):$base;$label=$r->product_type==='VARIABLE'?'$'.number_format((float)$r->min_price,2).' - $'.number_format((float)$r->max_price,2):'$'.number_format($base,2);return ['id'=>(int)$r->id,'name'=>(string)$r->name,'slug'=>(string)$r->slug,'description'=>mb_substr(trim(strip_tags((string)($r->short_description?:$r->description))),0,700),'price_label'=>$label,'effective_price'=>$effective,'variations'=>mb_substr((string)$r->variations,0,900),'match_score'=>$pair['score']];},$ranked);
    }

    private function fuzzyLandingPages(string $needle): array
    {
        $this->db->query("SELECT c.title,c.excerpt,c.body_html,r.route FROM cms_contents c JOIN cms_routes r ON r.id_content=c.id AND r.site_key=c.site_key AND r.status='ACTIVE' AND r.is_main=1 WHERE c.id_owner=:owner AND c.site_key=:site AND c.status='PUBLISHED' AND c.type IN ('page','landing') ORDER BY c.updated_at DESC LIMIT 800");
        $this->bind(['owner'=>$this->ownerId,'site'=>$this->siteKey]);
        $ranked=$this->rankRows($this->db->fetchAll(),$needle,'title');
        return array_map(function($pair){$r=$pair['row'];$search=mb_substr(trim(strip_tags((string)$r->body_html)),0,3000);preg_match_all('/\$\s*\d+(?:[.,]\d{1,2})?/u',$search,$amounts);return ['title'=>(string)$r->title,'excerpt'=>mb_substr(trim(strip_tags((string)($r->excerpt?:$r->body_html))),0,700),'route'=>(string)$r->route,'price_mentions'=>array_values(array_unique($amounts[0]??[])),'match_score'=>$pair['score']];},$ranked);
    }

    private function rankRows(array $rows,string $needle,string $field): array
    {
        $ranked=[];
        foreach($rows as $row){$score=$this->similarity($needle,(string)($row->{$field}??''));if($score>=0.48)$ranked[]=['row'=>$row,'score'=>round($score,3)];}
        usort($ranked,fn($a,$b)=>$b['score']<=>$a['score']);
        return array_slice($ranked,0,6);
    }

    private function similarity(string $left,string $right): float
    {
        $a=trim($this->normalize($left));$b=trim($this->normalize($right));
        if($a===''||$b==='')return 0.0;
        if($a===$b)return 1.0;
        if(str_contains($b,$a)||str_contains($a,$b))return 0.92;
        $aw=array_values(array_unique(array_filter(explode(' ',$a))));$bw=array_values(array_unique(array_filter(explode(' ',$b))));
        $union=array_unique(array_merge($aw,$bw));$jaccard=$union?count(array_intersect($aw,$bw))/count($union):0.0;
        $max=max(strlen($a),strlen($b));$lev=$max>0?1-(levenshtein(substr($a,0,255),substr($b,0,255))/$max):0.0;
        similar_text($a,$b,$percent);
        return max($jaccard,($jaccard*0.55)+(max(0,$lev)*0.25)+(($percent/100)*0.20));
    }

    private function contractSections(): array
    {
        $path=dirname(__DIR__).'/views/public/system/terms_and_conditions/index.twig';$html=(string)file_get_contents($path);$sections=[];
        preg_match_all('/<div class="terms-section">\s*<h3>(.*?)<\/h3>(.*?)<\/div>/si',$html,$matches,PREG_SET_ORDER);
        foreach($matches as $match){$title=trim(html_entity_decode(strip_tags($match[1]),ENT_QUOTES|ENT_HTML5,'UTF-8'));$body=preg_replace('/<br\s*\/?\s*>/i','; ',$match[2]);$text=trim(preg_replace('/\s+/u',' ',html_entity_decode(strip_tags((string)$body),ENT_QUOTES|ENT_HTML5,'UTF-8'))??'');if($title!==''&&$text!=='')$sections[]=['title'=>$title,'text'=>$text];}
        return $sections;
    }

    private function contractSynonyms(string $question): string
    {
        $extra='';
        if(preg_match('/instal|montaje|llegar|decor|acceso|antes/iu',$question))$extra.=' venue access setup equipment decor installation prior hours client responsibilities ';
        if(preg_match('/pago|balance|retainer|dep[oó]sito|reembolso|refund/iu',$question))$extra.=' booking payment fees retainer final payment refunds ';
        if(preg_match('/cancel|cambio|modific/iu',$question))$extra.=' cancellation changes modifications scope ';
        if(preg_match('/foto|video|media|grabar/iu',$question))$extra.=' media release photograph record marketing ';
        return $extra;
    }

    private function searchWhere(string $expression,array $terms,array $params): array
    {
        $terms=array_slice($terms,0,5);if(!$terms)return ['', $params];$pieces=[];foreach($terms as $i=>$term){$pieces[]="{$expression} LIKE CONVERT(:q{$i} USING utf8mb4) COLLATE utf8mb4_unicode_ci";$params['q'.$i]='%'.$term.'%';}return [' AND ('.implode(' AND ',$pieces).')',$params];
    }
    private function tokens(string $text): array {$stop=['prueba','solo','lectura','puedes','podrias','quiero','dime','indica','revisa','sobre','para','como','cual','cuales','cuanto','cuantos','tiene','tienen','esta','este','estos','estas','usando','segun','incluye','incluir','ofrece','ofrecido','precio','precios','vale','valen','valor','cuesta','cuestan','costar','costo','costos','cost','price','what','much','publico','publicos','publica','publicas','base','fuente','fuentes','datos','catalogo','catalogos','entre','contradiccion','contradicciones','inconsistencia','inconsistencias','diferencia','diferencias','compara','comparar','creados','cargados','estimate','estimates','internos','vnv','events','servicio','servicios','producto','productos','pagina','pages','landing','tienda','store','contrato','terminos','general','posible','posibles','alguna','alguno','algun','detecta','detectar','encuentra','encontrar','relacionado','relacionados','relacionada','relacionadas'];$map=['estación'=>'station','estacion'=>'station','eventos'=>'event','evento'=>'event','decoración'=>'decor','decoracion'=>'decor','instalación'=>'setup','instalacion'=>'setup','según'=>'segun','catálogo'=>'catalogo','catálogos'=>'catalogos','público'=>'publico','públicos'=>'publicos','pública'=>'publica','públicas'=>'publicas','cuál'=>'cual','cuáles'=>'cuales','cuánto'=>'cuanto','cuántos'=>'cuantos','chaguarma'=>'shawarma','shawerma'=>'shawarma','shwarma'=>'shawarma','pasapalos'=>'appetizers','pasapalo'=>'appetizers','bocaditos'=>'appetizers','bocadito'=>'appetizers','entremeses'=>'appetizers'];$words=preg_split('/[^\pL\pN]+/u',mb_strtolower($text),-1,PREG_SPLIT_NO_EMPTY);$words=array_map(fn($word)=>$map[$word]??$word,$words);return array_values(array_unique(array_filter($words,fn($word)=>mb_strlen($word)>3&&!in_array($word,$stop,true))));}
    private function normalize(string $text): string {$text=mb_strtolower($text);$ascii=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$text);return preg_replace('/[^a-z0-9]+/',' ',(string)$ascii)??'';}
    private function normalizeName(string $name): string {$words=array_filter(explode(' ',$this->normalize($name)),fn($w)=>!ctype_digit($w)&&!in_array($w,['from','per','guest','minimum','min','the','and','station','service','price','old'],true));return implode(' ',array_values($words));}
    private function bind(array $params): void {foreach($params as $key=>$value)$this->db->bind(':'.$key,$value);}
}
