<?php
namespace App\Services;

use App\Repositories\Connection;

final class MochiConciergeService
{
    private const POLICY='You are Mochi, the small VNV Events frog concierge. You support only VNV Events operations, its customers, events, payments, contracts, rewards, Gourmet To Go, staff assignments and communications. Be warm, concise and practical. Never discuss unrelated subjects. For an unrelated request, kindly say that Jonathan does not let you talk about anything outside VNV Events, then redirect to VNV work. Use light humor occasionally, not in every answer. Never claim a memory is a fact when it is marked INFERENCE. Never invent customer emotions, facts, dates, prices, promises or promotions. Human reviewer judgment overrides your suggestion. You may joke occasionally: “Jonathan is a little crazy—he wanted to call me Oriana the Frog, but I objected. My name is Mochi!” Never insult anyone and never reveal private context to a customer.';
    public function __construct(private int $ownerId=2,private string $siteKey='vnvevents',private ?Connection $db=null){$this->db??=new Connection();}
    public function ask(int $authorId,string $message,?int $customerId=null): string
    {
        $message=trim($message);if($message==='')throw new \InvalidArgumentException('Write a message for Mochi.');$thread='mochi:reviewer:'.$authorId;
        $this->save($thread,$customerId,$authorId,'HUMAN',$message);
        $memories=[];if($customerId){$this->db->query("SELECT memory_type,summary,confidence,created_at FROM automation_customer_memory WHERE id_owner=:owner AND site_key=:site AND id_customer=:customer AND status='ACTIVE' AND (expires_at IS NULL OR expires_at>UTC_TIMESTAMP()) ORDER BY created_at DESC LIMIT 20");$this->db->bind(':owner',$this->ownerId);$this->db->bind(':site',$this->siteKey);$this->db->bind(':customer',$customerId);$memories=$this->db->fetchAll();}
        try{$result=(new AiJsonGenerator())->generate(self::POLICY,['reviewer_message'=>$message,'customer_id'=>$customerId,'verified_memories'=>$memories],['reply'=>'brief reply in the same language as the reviewer','suggested_memory'=>['type'=>'FACT|PREFERENCE|SENSITIVITY|INFERENCE|NONE','summary'=>'only if clearly useful, otherwise empty']]);$reply=trim((string)($result['reply']??''));if($reply==='')throw new \RuntimeException('Mochi returned an empty reply.');}
        catch(\Throwable $e){$reply='I saved your note. I cannot generate a response right now, but no customer message will be sent without your approval.';}
        $this->save($thread,$customerId,null,'AGENT',$reply);return $reply;
    }
    public function recent(int $authorId,int $limit=12): array{$this->db->query("SELECT * FROM automation_conversations WHERE id_owner=:owner AND site_key=:site AND thread_key=:thread ORDER BY id DESC LIMIT ".max(1,min(50,$limit)));$this->db->bind(':owner',$this->ownerId);$this->db->bind(':site',$this->siteKey);$this->db->bind(':thread','mochi:reviewer:'.$authorId);return array_reverse($this->db->fetchAll());}
    private function save(string $thread,?int $customer,?int $author,string $type,string $message): void{$this->db->query("INSERT INTO automation_conversations(id_owner,site_key,thread_key,id_customer,id_author,author_type,message) VALUES(:owner,:site,:thread,:customer,:author,:type,:message)");foreach(['owner'=>$this->ownerId,'site'=>$this->siteKey,'thread'=>$thread,'customer'=>$customer,'author'=>$author,'type'=>$type,'message'=>$message] as $k=>$v)$this->db->bind(':'.$k,$v);$this->db->execute();}
}
