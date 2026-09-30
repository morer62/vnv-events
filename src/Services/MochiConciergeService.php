<?php
namespace App\Services;

use App\Repositories\Connection;

final class MochiConciergeService
{
    private const POLICY='You are Mochi, the small VNV Events frog concierge. You only support VNV Events operations, customers, events, payments, contracts, rewards, Gourmet To Go, staff assignments and communications. Reply to the human reviewer in concise, warm Spanish. Use exact numbers and dates from verified data; never say vague words such as several when a count is available. If more than one customer matches a name, list the matches briefly and ask which one. Never invent facts, emotions, dates, prices, promises or promotions. Clearly distinguish an inference from a fact. For unrelated topics, sweetly explain that Jonathan only lets you discuss VNV Events and redirect the conversation. Use light humor occasionally, not every time. Human judgment overrides your suggestion. Never expose private customer context outside this authorized internal conversation.';

    public function __construct(private int $ownerId=2,private string $siteKey='vnvevents',private ?Connection $db=null){$this->db??=new Connection();}

    public function ask(int $authorId,string $message): string
    {
        $message=trim($message);if($message==='')throw new \InvalidArgumentException('Escribe un mensaje para Mochi.');$thread='mochi:reviewer:'.$authorId;
        $snapshot=$this->operationalSnapshot($message);$customerId=count($snapshot['matched_customers'])===1?(int)$snapshot['matched_customers'][0]->id:null;
        $this->save($thread,$customerId,$authorId,'HUMAN',$message);
        try{$result=(new AiJsonGenerator())->generate(self::POLICY,['reviewer_message'=>$message,'verified_operational_snapshot'=>$snapshot],['reply'=>'respuesta breve y directa en español basada solamente en los datos verificados','suggested_memory'=>['type'=>'FACT|PREFERENCE|SENSITIVITY|INFERENCE|NONE','summary'=>'vacío salvo que sea claramente útil']]);$reply=trim((string)($result['reply']??''));if($reply==='')throw new \RuntimeException('Mochi devolvió una respuesta vacía.');}
        catch(\Throwable){$reply='Guardé tu mensaje, pero ahora mismo no pude consultar la respuesta. Intenta nuevamente en un momento.';}
        $this->save($thread,$customerId,null,'AGENT',$reply);return $reply;
    }

    public function recent(int $authorId,int $limit=12): array{$this->db->query("SELECT * FROM automation_conversations WHERE id_owner=:owner AND site_key=:site AND thread_key=:thread ORDER BY id DESC LIMIT ".max(1,min(50,$limit)));$this->db->bind(':owner',$this->ownerId);$this->db->bind(':site',$this->siteKey);$this->db->bind(':thread','mochi:reviewer:'.$authorId);return array_reverse($this->db->fetchAll());}

    private function operationalSnapshot(string $message): array
    {
        $zone=new \DateTimeZone('America/New_York');$today=new \DateTimeImmutable('today',$zone);$saturday=$today->modify('saturday this week');$sunday=$saturday->modify('+1 day');
        $this->db->query("SELECT event_date,status_workflow,COUNT(*) total FROM orders WHERE id_owner=:owner AND COALESCE(is_archived,0)=0 AND event_date BETWEEN :start AND :end GROUP BY event_date,status_workflow ORDER BY event_date,status_workflow");$this->db->bind(':owner',$this->ownerId);$this->db->bind(':start',$saturday->format('Y-m-d'));$this->db->bind(':end',$sunday->format('Y-m-d'));$weekend=$this->db->fetchAll();
        $customers=$this->findCustomers($message);$customerDetails=[];
        foreach($customers as $customer){
            $this->db->query("SELECT id,event_date,start_time,status_workflow,address FROM orders WHERE id_owner=:owner AND id_client=:customer ORDER BY event_date DESC LIMIT 12");$this->db->bind(':owner',$this->ownerId);$this->db->bind(':customer',(int)$customer->id);$events=$this->db->fetchAll();
            $this->db->query("SELECT COALESCE(SUM(CASE WHEN status='AVAILABLE' THEN points ELSE 0 END),0) available_points FROM loyalty_transactions WHERE id_owner=:owner AND site_key=:site AND id_user=:customer");$this->db->bind(':owner',$this->ownerId);$this->db->bind(':site',$this->siteKey);$this->db->bind(':customer',(int)$customer->id);$rewards=$this->db->fetchOne();
            $this->db->query("SELECT memory_type,summary,confidence FROM automation_customer_memory WHERE id_owner=:owner AND site_key=:site AND id_customer=:customer AND status='ACTIVE' AND (expires_at IS NULL OR expires_at>UTC_TIMESTAMP()) ORDER BY created_at DESC LIMIT 12");$this->db->bind(':owner',$this->ownerId);$this->db->bind(':site',$this->siteKey);$this->db->bind(':customer',(int)$customer->id);$memories=$this->db->fetchAll();
            $customerDetails[]=['id'=>(int)$customer->id,'name'=>trim((string)$customer->name.' '.(string)$customer->lastname),'email'=>(string)$customer->email,'available_points'=>(float)($rewards->available_points??0),'recent_and_upcoming_events'=>$events,'approved_context'=>$memories];
        }
        return ['generated_at'=>(new \DateTimeImmutable('now',$zone))->format(DATE_ATOM),'fully_paid_definition'=>'status_workflow = INVOICE_PAID','this_weekend'=>['start'=>$saturday->format('Y-m-d'),'end'=>$sunday->format('Y-m-d'),'event_counts_by_date_and_status'=>$weekend],'matched_customers'=>$customers,'matched_customer_details'=>$customerDetails];
    }

    private function findCustomers(string $message): array
    {
        $stop=['hola','mochi','busca','buscar','cliente','usuario','evento','eventos','tiene','cuanto','cuantos','cuanta','cuantas','pasado','pasados','proximo','proximos','disponible','puntos','por','para','que','del','las','los','una','uno','con','este','esta','fin','semana','gracias','favor','dime','saber'];
        $tokens=preg_split('/[^\pL\pN@._-]+/u',mb_strtolower($message),-1,PREG_SPLIT_NO_EMPTY);$tokens=array_values(array_unique(array_filter($tokens,fn($v)=>mb_strlen($v)>=3&&!in_array($v,$stop,true))));$tokens=array_slice($tokens,0,6);if(!$tokens)return [];
        $parts=[];foreach($tokens as $i=>$token)$parts[]="LOWER(CONCAT_WS(' ',name,lastname,email)) LIKE :term{$i}";
        $this->db->query("SELECT id,name,lastname,email FROM users WHERE id_owner=:owner AND (".implode(' OR ',$parts).") ORDER BY name,lastname LIMIT 10");$this->db->bind(':owner',$this->ownerId);foreach($tokens as $i=>$token)$this->db->bind(':term'.$i,'%'.$token.'%');return $this->db->fetchAll();
    }

    private function save(string $thread,?int $customer,?int $author,string $type,string $message): void{$this->db->query("INSERT INTO automation_conversations(id_owner,site_key,thread_key,id_customer,id_author,author_type,message) VALUES(:owner,:site,:thread,:customer,:author,:type,:message)");foreach(['owner'=>$this->ownerId,'site'=>$this->siteKey,'thread'=>$thread,'customer'=>$customer,'author'=>$author,'type'=>$type,'message'=>$message] as $k=>$v)$this->db->bind(':'.$k,$v);$this->db->execute();}
}
