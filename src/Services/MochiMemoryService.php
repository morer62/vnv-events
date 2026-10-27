<?php

namespace App\Services;

use App\Repositories\Connection;

/** Compact, persistent conversation memory. It stores no credentials and never replaces live tools. */
final class MochiMemoryService
{
    public function __construct(private int $ownerId,private string $siteKey='vnvevents',private ?Connection $db=null,private ?AiJsonGenerator $ai=null){$this->db??=new Connection();$this->ai??=new AiJsonGenerator();}

    public function context(int $sessionId,string $query=''): array
    {
        try{
            $company=$this->read('COMPANY','shared');$current=$this->read('SESSION',(string)$sessionId);
            $this->db->query("SELECT mm.memory_json,ms.session_date FROM mochi_memories mm JOIN mochi_sessions ms ON mm.scope_type='SESSION' AND CAST(mm.scope_key AS UNSIGNED)=ms.id WHERE mm.id_owner=:owner AND mm.site_key=:site AND ms.id<>:session AND ms.id_user=(SELECT id_user FROM mochi_sessions WHERE id=:session LIMIT 1) ORDER BY ms.session_date DESC LIMIT 7");$this->bind(['owner'=>$this->ownerId,'site'=>$this->siteKey,'session'=>$sessionId]);
            $recent=[];foreach($this->db->fetchAll() as $row){$recent[]=['date'=>(string)$row->session_date,'memory'=>$this->decode((string)$row->memory_json)];}
            return ['company'=>$company,'today'=>$current,'recent_days'=>$recent,'shared_learning_journal'=>$this->journalContext($query)];
        }catch(\Throwable $e){error_log('[Mochi memory read] '.$e->getMessage());return [];}
    }

    public function capture(int $sessionId,string $answer,array $metadata): void
    {
        try{
            $this->db->query("SELECT message FROM automation_conversations WHERE id_mochi_session=:session AND author_type='HUMAN' ORDER BY id DESC LIMIT 1");$this->db->bind(':session',$sessionId);$question=(string)($this->db->fetchOne()->message??'');
            if($question==='')return;
            $current=$this->read('SESSION',(string)$sessionId);
            $updated=$this->ai->generate(
                'Maintain compact operational memory for the ongoing VNV Events conversation. Write Spanish. Keep only information explicitly stated by the user or verified by tool facts. Preserve topic changes, decisions, corrections, preferences and unfinished threads. Never store passwords, tokens, card or bank data, private contact details, or speculative claims. A tool fact may be summarized with its source. Replace superseded information instead of accumulating contradictions.',
                ['current_memory'=>$current,'latest_turn'=>['user'=>$question,'assistant'=>$answer,'tool'=>(string)($metadata['tool']??''),'verified_facts'=>(array)($metadata['facts']??[])]],
                ['summary'=>'brief running summary','topics'=>['short topic'],'verified_facts'=>['fact plus source'],'preferences_rules'=>['durable preference or rule'],'corrections'=>['correction that supersedes an earlier assumption'],'open_threads'=>['unfinished question or task']],
                ['profile'=>'routine','reasoning_effort'=>'low','timeout'=>20,'max_output_tokens'=>900,'schema_name'=>'mochi_daily_memory']
            );
            $updated=$this->sanitize($updated);$this->write('SESSION',(string)$sessionId,$updated);
            $company=$this->read('COMPANY','shared');
            foreach(['topics','verified_facts','preferences_rules','corrections'] as $key)$company[$key]=$this->merge((array)($company[$key]??[]),(array)($updated[$key]??[]),160);
            $company['open_threads']=array_slice((array)($updated['open_threads']??[]),-80);
            $company['summary']='Memoria empresarial única de VNV: decisiones, reglas, correcciones, temas y hechos verificados aprendidos a través de todas las conversaciones diarias.';
            $this->write('COMPANY','shared',$this->sanitize($company));
            $this->appendJournal($sessionId,$updated,$metadata);
        }catch(\Throwable $e){error_log('[Mochi memory capture] '.$e->getMessage());}
    }

    private function appendJournal(int $sessionId,array $memory,array $metadata): void
    {
        $this->db->query('SELECT session_date FROM mochi_sessions WHERE id=:session AND id_owner=:owner AND site_key=:site LIMIT 1');$this->bind(['session'=>$sessionId,'owner'=>$this->ownerId,'site'=>$this->siteKey]);$session=$this->db->fetchOne();$date=(string)($session->session_date??date('Y-m-d'));$tool=mb_substr(trim((string)($metadata['tool']??'')),0,180)?:null;
        $categories=['topics'=>'TOPIC','verified_facts'=>'VERIFIED_FACT','preferences_rules'=>'PREFERENCE','corrections'=>'CORRECTION','open_threads'=>'OPEN_THREAD'];
        foreach($categories as $key=>$category)foreach((array)($memory[$key]??[]) as $entry){$content=mb_substr($this->redact(trim((string)$entry)),0,1200);if($content==='')continue;$hash=hash('sha256',$category.'|'.mb_strtolower($content));$this->db->query('INSERT INTO mochi_learning_journal(id_owner,site_key,id_session,memory_date,category,content,content_hash,source_tool,is_verified) VALUES(:owner,:site,:session,:memory_date,:category,:content,:content_hash,:source_tool,:verified) ON DUPLICATE KEY UPDATE last_seen_at=NOW(),occurrence_count=occurrence_count+1,id_session=VALUES(id_session),memory_date=VALUES(memory_date),source_tool=VALUES(source_tool),is_verified=GREATEST(is_verified,VALUES(is_verified))');$this->bind(['owner'=>$this->ownerId,'site'=>$this->siteKey,'session'=>$sessionId,'memory_date'=>$date,'category'=>$category,'content'=>$content,'content_hash'=>$hash,'source_tool'=>$tool,'verified'=>$category==='VERIFIED_FACT'?1:0]);$this->db->execute();}
    }

    private function journalContext(string $query): array
    {
        $base="SELECT memory_date,category,content,source_tool,is_verified,occurrence_count FROM mochi_learning_journal WHERE id_owner=:owner AND site_key=:site";$params=['owner'=>$this->ownerId,'site'=>$this->siteKey];$terms=$this->searchTerms($query);$rows=[];
        if($terms){$where=[];foreach($terms as $i=>$term){$where[]='LOWER(content) LIKE :term'.$i;$params['term'.$i]='%'.$term.'%';}$this->db->query($base.' AND ('.implode(' OR ',$where).') ORDER BY is_verified DESC,last_seen_at DESC LIMIT 60');$this->bind($params);$rows=$this->db->fetchAll();}
        $this->db->query($base." AND category IN ('PREFERENCE','CORRECTION','OPEN_THREAD') ORDER BY last_seen_at DESC LIMIT 40");$this->bind(['owner'=>$this->ownerId,'site'=>$this->siteKey]);$durable=$this->db->fetchAll();
        $seen=[];$out=[];foreach(array_merge($rows,$durable) as $row){$key=$row->category.'|'.$row->content;if(isset($seen[$key]))continue;$seen[$key]=true;$out[]=['date'=>(string)$row->memory_date,'category'=>(string)$row->category,'content'=>(string)$row->content,'source_tool'=>(string)$row->source_tool,'verified'=>(bool)$row->is_verified,'seen'=>(int)$row->occurrence_count];if(count($out)>=80)break;}return $out;
    }

    private function searchTerms(string $query): array
    {
        $parts=preg_split('/[^\pL\pN]+/u',mb_strtolower($query),-1,PREG_SPLIT_NO_EMPTY)?:[];$stop=['para','como','cuanto','cuantos','cual','cuales','este','esta','estos','estas','sobre','donde','cuando','porque','quiero','puedes','podrias','mochi','events','evento','eventos'];return array_slice(array_values(array_unique(array_filter($parts,fn($term)=>mb_strlen($term)>=4&&!in_array($term,$stop,true)))),0,6);
    }

    private function read(string $scope,string $key): array {$this->db->query('SELECT memory_json FROM mochi_memories WHERE id_owner=:owner AND site_key=:site AND scope_type=:scope AND scope_key=:key LIMIT 1');$this->bind(['owner'=>$this->ownerId,'site'=>$this->siteKey,'scope'=>$scope,'key'=>$key]);$row=$this->db->fetchOne();return $row?$this->decode((string)$row->memory_json):[];}
    private function write(string $scope,string $key,array $memory): void {$this->db->query('INSERT INTO mochi_memories(id_owner,site_key,scope_type,scope_key,memory_json) VALUES(:owner,:site,:scope,:key,:memory) ON DUPLICATE KEY UPDATE memory_json=VALUES(memory_json),updated_at=NOW()');$this->bind(['owner'=>$this->ownerId,'site'=>$this->siteKey,'scope'=>$scope,'key'=>$key,'memory'=>json_encode($memory,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);$this->db->execute();}
    private function decode(string $json): array {$value=json_decode($json,true);return is_array($value)?$value:[];}
    private function sanitize(array $memory): array {$out=['summary'=>mb_substr($this->redact(trim((string)($memory['summary']??''))),0,1800)];foreach(['topics','verified_facts','preferences_rules','corrections','open_threads'] as $key){$values=array_values(array_filter(array_map(fn($value)=>mb_substr($this->redact(trim((string)$value)),0,420),(array)($memory[$key]??[]))));$out[$key]=array_slice(array_values(array_unique($values)),0,80);}return $out;}
    private function redact(string $text): string {$text=preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/iu','[email omitido]',$text)??$text;$text=preg_replace('/(?<!\d)(?:\+?1[ .-]?)?(?:\(?\d{3}\)?[ .-]?)\d{3}[ .-]?\d{4}(?!\d)/','[teléfono omitido]',$text)??$text;return preg_replace('/\b(password|contrase(?:ñ|n)a|clave|token|api[_ -]?key)\b\s*[:=]?\s*\S+/iu','$1 [omitido]',$text)??$text;}
    private function merge(array $a,array $b,int $limit): array {return array_slice(array_values(array_unique(array_filter(array_merge($a,$b),'is_string'))),-$limit);}
    private function bind(array $values): void {foreach($values as $key=>$value)$this->db->bind(':'.$key,$value);}
}
