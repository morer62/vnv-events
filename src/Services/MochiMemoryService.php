<?php

namespace App\Services;

use App\Repositories\Connection;

/** Compact, persistent conversation memory. It stores no credentials and never replaces live tools. */
final class MochiMemoryService
{
    public function __construct(private int $ownerId,private string $siteKey='vnvevents',private ?Connection $db=null,private ?AiJsonGenerator $ai=null){$this->db??=new Connection();$this->ai??=new AiJsonGenerator();}

    public function context(int $sessionId): array
    {
        try{
            $company=$this->read('COMPANY','shared');$current=$this->read('SESSION',(string)$sessionId);
            $this->db->query("SELECT mm.memory_json,ms.session_date FROM mochi_memories mm JOIN mochi_sessions ms ON mm.scope_type='SESSION' AND CAST(mm.scope_key AS UNSIGNED)=ms.id WHERE mm.id_owner=:owner AND mm.site_key=:site AND ms.id<>:session AND ms.id_user=(SELECT id_user FROM mochi_sessions WHERE id=:session LIMIT 1) ORDER BY ms.session_date DESC LIMIT 7");$this->bind(['owner'=>$this->ownerId,'site'=>$this->siteKey,'session'=>$sessionId]);
            $recent=[];foreach($this->db->fetchAll() as $row){$recent[]=['date'=>(string)$row->session_date,'memory'=>$this->decode((string)$row->memory_json)];}
            return ['company'=>$company,'today'=>$current,'recent_days'=>$recent];
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
            foreach(['preferences_rules','corrections'] as $key)$company[$key]=$this->merge((array)($company[$key]??[]),(array)($updated[$key]??[]),80);
            $company['summary']='Durable VNV operating rules and corrections learned from confirmed conversations.';$this->write('COMPANY','shared',$this->sanitize($company));
        }catch(\Throwable $e){error_log('[Mochi memory capture] '.$e->getMessage());}
    }

    private function read(string $scope,string $key): array {$this->db->query('SELECT memory_json FROM mochi_memories WHERE id_owner=:owner AND site_key=:site AND scope_type=:scope AND scope_key=:key LIMIT 1');$this->bind(['owner'=>$this->ownerId,'site'=>$this->siteKey,'scope'=>$scope,'key'=>$key]);$row=$this->db->fetchOne();return $row?$this->decode((string)$row->memory_json):[];}
    private function write(string $scope,string $key,array $memory): void {$this->db->query('INSERT INTO mochi_memories(id_owner,site_key,scope_type,scope_key,memory_json) VALUES(:owner,:site,:scope,:key,:memory) ON DUPLICATE KEY UPDATE memory_json=VALUES(memory_json),updated_at=NOW()');$this->bind(['owner'=>$this->ownerId,'site'=>$this->siteKey,'scope'=>$scope,'key'=>$key,'memory'=>json_encode($memory,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);$this->db->execute();}
    private function decode(string $json): array {$value=json_decode($json,true);return is_array($value)?$value:[];}
    private function sanitize(array $memory): array {$out=['summary'=>mb_substr($this->redact(trim((string)($memory['summary']??''))),0,1800)];foreach(['topics','verified_facts','preferences_rules','corrections','open_threads'] as $key){$values=array_values(array_filter(array_map(fn($value)=>mb_substr($this->redact(trim((string)$value)),0,420),(array)($memory[$key]??[]))));$out[$key]=array_slice(array_values(array_unique($values)),0,80);}return $out;}
    private function redact(string $text): string {$text=preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/iu','[email omitido]',$text)??$text;$text=preg_replace('/(?<!\d)(?:\+?1[ .-]?)?(?:\(?\d{3}\)?[ .-]?)\d{3}[ .-]?\d{4}(?!\d)/','[teléfono omitido]',$text)??$text;return preg_replace('/\b(password|contrase(?:ñ|n)a|clave|token|api[_ -]?key)\b\s*[:=]?\s*\S+/iu','$1 [omitido]',$text)??$text;}
    private function merge(array $a,array $b,int $limit): array {return array_slice(array_values(array_unique(array_filter(array_merge($a,$b),'is_string'))),-$limit);}
    private function bind(array $values): void {foreach($values as $key=>$value)$this->db->bind(':'.$key,$value);}
}
