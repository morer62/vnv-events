<?php
namespace App\Services;

use App\Repositories\Connection;

final class MochiConciergeService
{
    private const POLICY='You are Mochi, the internal VNV Events operations assistant. Answer in concise, warm Spanish. Discuss only VNV Events. Treat supplied tool results as the sole source of live facts. Never invent counts, prices, assignments, availability, emotions or promises. Never expose card data, credentials, private notes or data outside the user scope. Rewards cannot be used for tips. Prefer a direct answer and preserve provided links. If data is unavailable, say so plainly. Light humor is occasional, never distracting.';
    private MochiSessionService $sessions;
    private MochiOperationalToolsService $tools;
    public function __construct(private int $ownerId=2,private string $siteKey='vnvevents',private ?Connection $db=null){$this->db??=new Connection();$this->sessions=new MochiSessionService($ownerId,$siteKey,$this->db);$this->tools=new MochiOperationalToolsService($ownerId,$siteKey,$this->db);}

    public function open(int $userId,int $level,?int $sessionId=null): array {$session=$sessionId?$this->sessions->get($sessionId,$userId):$this->sessions->today($userId);return ['session'=>$session,'messages'=>$this->sessions->messages((int)$session->id,$userId),'history'=>$this->sessions->history($userId),'suggestions'=>$this->tools->suggestions($level)];}
    public function newChat(int $userId,int $level): array {$session=$this->sessions->create($userId);return $this->open($userId,$level,(int)$session->id);}
    public function ask(int $authorId,int $level,string $message,?int $sessionId=null): array
    {
        $message=trim($message);if($message==='')throw new \InvalidArgumentException('Escribe un mensaje para Mochi.');if(mb_strlen($message)>3000)throw new \InvalidArgumentException('El mensaje es demasiado largo.');
        $session=$sessionId?$this->sessions->get($sessionId,$authorId):$this->sessions->today($authorId);$sessionId=(int)$session->id;$this->save($sessionId,$authorId,'HUMAN',$message);$tool=$this->tools->select($message,$level);
        try{$result=$this->tools->run($tool,$authorId,$level,$sessionId,$message);$reply=(string)$result['reply'];$items=$result['items']??[];
            if(!$this->isStructuredIntent($tool)&&$items){try{$generated=(new AiJsonGenerator())->generate(self::POLICY,['question'=>$message,'tool'=>$tool,'verified_result'=>['summary'=>$reply,'items'=>$items]],['reply'=>'respuesta breve en español basada exclusivamente en verified_result']);$reply=trim((string)($generated['reply']??$reply));}catch(\Throwable $e){error_log('[Mochi LLM] '.$e->getMessage());}}
            $metadata=['tool'=>$tool,'items'=>$items];
        }catch(\Throwable $e){$reply=$e->getMessage();$metadata=['tool'=>$tool,'items'=>[],'error'=>true];}
        $this->save($sessionId,null,'AGENT',$reply,$metadata);$this->sessions->touch($sessionId);return ['reply'=>$reply,'items'=>$metadata['items'],'tool'=>$tool,'session_id'=>$sessionId];
    }
    public function recent(int $authorId,int $limit=60): array {$session=$this->sessions->today($authorId);return $this->sessions->messages((int)$session->id,$authorId,$limit);}
    private function isStructuredIntent(string $tool): bool {return in_array($tool,['weekend_events','my_events','my_tasks','collections','opportunities','requests','contracts','warehouse','rewards'],true);}
    private function save(int $sessionId,?int $author,string $type,string $message,array $metadata=[]): void {$this->db->query("INSERT INTO automation_conversations(id_owner,site_key,thread_key,id_mochi_session,id_author,author_type,message,metadata_json) VALUES(:owner,:site,:thread,:session,:author,:type,:message,:metadata)");foreach(['owner'=>$this->ownerId,'site'=>$this->siteKey,'thread'=>'mochi:session:'.$sessionId,'session'=>$sessionId,'author'=>$author,'type'=>$type,'message'=>$message,'metadata'=>$metadata?json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null] as $k=>$v)$this->db->bind(':'.$k,$v);$this->db->execute();}
}
