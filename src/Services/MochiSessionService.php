<?php
namespace App\Services;

use App\Repositories\Connection;

final class MochiSessionService
{
    private const ZONE='America/New_York';
    public function __construct(private int $ownerId=2,private string $siteKey='vnvevents',private ?Connection $db=null){$this->db??=new Connection();}

    public function today(int $userId,?string $dateOverride=null): object
    {
        $date=$dateOverride ?: (new \DateTimeImmutable('now',new \DateTimeZone(self::ZONE)))->format('Y-m-d');
        $this->db->query("UPDATE mochi_sessions SET status='ARCHIVED' WHERE id_owner=:owner AND site_key=:site AND id_user=:user AND session_date<>:day AND status='ACTIVE'");$this->bind(['owner'=>$this->ownerId,'site'=>$this->siteKey,'user'=>$userId,'day'=>$date]);$this->db->execute();
        $this->db->query("SELECT * FROM mochi_sessions WHERE id_owner=:owner AND site_key=:site AND id_user=:user AND session_date=:day AND sequence_no=1 LIMIT 1");$this->bind(['owner'=>$this->ownerId,'site'=>$this->siteKey,'user'=>$userId,'day'=>$date]);$row=$this->db->fetchOne();
        if($row)return $row;
        $this->db->query("INSERT INTO mochi_sessions(id_owner,site_key,id_user,session_date,sequence_no,title,status) VALUES(:owner,:site,:user,:day,1,:title,'ACTIVE')");$this->bind(['owner'=>$this->ownerId,'site'=>$this->siteKey,'user'=>$userId,'day'=>$date,'title'=>'Hoy · '.$date]);$this->db->execute();
        return $this->get((int)$this->db->lastId(),$userId);
    }

    public function create(int $userId): object
    {
        $date=(new \DateTimeImmutable('now',new \DateTimeZone(self::ZONE)))->format('Y-m-d');
        $this->db->query("SELECT COALESCE(MAX(sequence_no),0)+1 next_sequence FROM mochi_sessions WHERE id_owner=:owner AND site_key=:site AND id_user=:user AND session_date=:day");$this->bind(['owner'=>$this->ownerId,'site'=>$this->siteKey,'user'=>$userId,'day'=>$date]);$sequence=(int)$this->db->fetchOne()->next_sequence;
        $this->db->query("INSERT INTO mochi_sessions(id_owner,site_key,id_user,session_date,sequence_no,title,status) VALUES(:owner,:site,:user,:day,:sequence,:title,'ACTIVE')");$this->bind(['owner'=>$this->ownerId,'site'=>$this->siteKey,'user'=>$userId,'day'=>$date,'sequence'=>$sequence,'title'=>'Nueva conversación · '.$sequence]);$this->db->execute();return $this->get((int)$this->db->lastId(),$userId);
    }

    public function get(int $id,int $userId): object {$this->db->query("SELECT * FROM mochi_sessions WHERE id=:id AND id_owner=:owner AND site_key=:site AND id_user=:user LIMIT 1");$this->bind(['id'=>$id,'owner'=>$this->ownerId,'site'=>$this->siteKey,'user'=>$userId]);$row=$this->db->fetchOne();if(!$row)throw new \RuntimeException('Conversación no disponible.');return $row;}
    public function history(int $userId,int $limit=30): array {$this->db->query("SELECT s.*,COUNT(c.id) message_count FROM mochi_sessions s LEFT JOIN automation_conversations c ON c.id_mochi_session=s.id WHERE s.id_owner=:owner AND s.site_key=:site AND s.id_user=:user GROUP BY s.id ORDER BY s.session_date DESC,s.sequence_no DESC LIMIT ".max(1,min(100,$limit)));$this->bind(['owner'=>$this->ownerId,'site'=>$this->siteKey,'user'=>$userId]);return $this->db->fetchAll();}
    public function messages(int $sessionId,int $userId,int $limit=60): array {$this->get($sessionId,$userId);$this->db->query("SELECT id,author_type,message,metadata_json,created_at FROM automation_conversations WHERE id_mochi_session=:session ORDER BY id DESC LIMIT ".max(1,min(100,$limit)));$this->db->bind(':session',$sessionId);return array_reverse($this->db->fetchAll());}
    public function touch(int $sessionId): void {$this->db->query("UPDATE mochi_sessions SET last_message_at=UTC_TIMESTAMP() WHERE id=:id AND id_owner=:owner AND site_key=:site");$this->bind(['id'=>$sessionId,'owner'=>$this->ownerId,'site'=>$this->siteKey]);$this->db->execute();}
    private function bind(array $values): void {foreach($values as $key=>$value)$this->db->bind(':'.$key,$value);}
}
