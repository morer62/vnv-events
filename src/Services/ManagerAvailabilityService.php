<?php
namespace App\Services;

use App\Repositories\Connection;
use DateTimeImmutable;
use RuntimeException;

final class ManagerAvailabilityService
{
    public const AVAILABLE='AVAILABLE';
    public const CONFLICT='MANAGER_SCHEDULING_CONFLICT';
    public const REVIEW='NEEDS_MANUAL_REVIEW';
    private Connection $db;
    private int $transitionMinutes;

    public function __construct(?Connection $db=null)
    {
        $this->db=$db??new Connection();
        $configuredHours=$_ENV['VNV_MANAGER_TRANSITION_HOURS']??null;
        $this->transitionMinutes=$configuredHours!==null&&$configuredHours!==''
            ? max(0,(int)round((float)$configuredHours*60))
            : max(0,(int)($_ENV['VNV_MANAGER_TRANSITION_MINUTES']??180));
    }

    public function transitionMinutes(): int
    {
        return $this->transitionMinutes;
    }

    public function evaluate(int $ownerId,string $date,string $start,string $end,int $setupMinutes=60,?int $managerId=null,?int $excludeOrderId=null): array
    {
        $window=$this->window($date,$start,$end,$setupMinutes);
        $managers=$managerId ? $this->managerRows($ownerId,$managerId) : $this->eligibleManagers($ownerId);
        if(!$managers)return ['status'=>self::REVIEW,'reason_code'=>'NO_ELIGIBLE_MANAGERS','message'=>'No eligible Main Manager is configured.','eligible'=>[],'window'=>$window];
        $results=[];
        foreach($managers as $manager)$results[]=$this->evaluateManager($ownerId,$manager,$window,$excludeOrderId);
        $available=array_values(array_filter($results,fn($r)=>$r['available']));
        return ['status'=>$available?self::AVAILABLE:self::CONFLICT,'reason_code'=>$available?'MANAGER_AVAILABLE':'NO_MANAGER_MEETS_OPERATIONAL_WINDOW','message'=>$available?'At least one Main Manager meets setup and Transition Time requirements.':'No Main Manager currently meets overlap, declared availability and Transition Time requirements.','eligible'=>$results,'suggested_manager_id'=>$available[0]['manager_id']??null,'window'=>$window];
    }

    public function evaluateOrder(object $order,?int $managerId=null): array
    {
        return $this->evaluate((int)$order->id_owner,(string)$order->event_date,(string)$order->start_time,(string)$order->end_time,(int)($order->setup_minutes??60),$managerId??($order->main_manager_id? (int)$order->main_manager_id:null),(int)$order->id);
    }

    public function record(int $ownerId,string $contextType,?int $contextId,array $result,?int $managerId=null,?int $checkedBy=null): int
    {
        $w=$result['window'];$this->db->query("INSERT INTO manager_availability_checks(id_owner,context_type,context_id,manager_id,event_date,start_time,end_time,setup_minutes,status,reason_code,details_json,checked_by) VALUES(:owner,:type,:context,:manager,:date,:start,:end,:setup,:status,:reason,:details,:by)");
        foreach(['owner'=>$ownerId,'type'=>$contextType,'context'=>$contextId,'manager'=>$managerId,'date'=>$w['date'],'start'=>$w['start_time'],'end'=>$w['end_time'],'setup'=>$w['setup_minutes'],'status'=>$result['status'],'reason'=>$result['reason_code'],'details'=>json_encode($result,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),'by'=>$checkedBy] as $k=>$v)$this->db->bind(':'.$k,$v);
        $this->db->execute();return (int)$this->db->lastId();
    }

    public function notifyLevelOne(int $ownerId,string $message,string $link='panel/lead-intake'): void
    {
        $this->db->query("SELECT id FROM users WHERE (id=:owner OR id_owner=:owner) AND level=1 AND LOWER(email)=LOWER(:email) AND is_active=1 ORDER BY id=:owner DESC LIMIT 1");$this->db->bind(':owner',$ownerId);$this->db->bind(':email',$_ENV['VNV_DEFAULT_MANAGER_EMAIL']??'info@vnvevents.com');$admin=$this->db->fetchOne();if(!$admin)return;
        $this->db->query("INSERT INTO notifications(id_user,mensaje,link,leido,`timestamp`) VALUES(:user,:message,:link,'NO',NOW())");$this->db->bind(':user',(int)$admin->id);$this->db->bind(':message',mb_substr($message,0,120));$this->db->bind(':link',$link);$this->db->execute();
    }

    private function evaluateManager(int $ownerId,object $manager,array $w,?int $excludeOrderId): array
    {
        $id=(int)$manager->id;$reasons=[];
        $this->db->query("SELECT id,note FROM manager_availability WHERE id_owner=:owner AND manager_id=:manager AND availability='UNAVAILABLE' AND starts_at < :end AND ends_at > :start LIMIT 1");
        foreach(['owner'=>$ownerId,'manager'=>$id,'start'=>$w['setup_start'],'end'=>$w['event_end']] as $k=>$v)$this->db->bind(':'.$k,$v);
        if($row=$this->db->fetchOne())$reasons[]=['code'=>'MARKED_UNAVAILABLE','message'=>$row->note?:'Manager marked this period unavailable.'];
        $sql="SELECT id,event_date,start_time,end_time,setup_minutes FROM orders WHERE id_owner=:owner AND main_manager_id=:manager AND is_archived=0 AND event_date BETWEEN DATE_SUB(:date,INTERVAL 1 DAY) AND DATE_ADD(:date,INTERVAL 1 DAY)";
        if($excludeOrderId)$sql.=" AND id<>:exclude";$this->db->query($sql);$this->db->bind(':owner',$ownerId);$this->db->bind(':manager',$id);$this->db->bind(':date',$w['date']);if($excludeOrderId)$this->db->bind(':exclude',$excludeOrderId);
        foreach($this->db->fetchAll() as $order){$other=$this->window((string)$order->event_date,(string)$order->start_time,(string)$order->end_time,(int)($order->setup_minutes??60));$required=$this->transitionMinutes*60;$newStart=strtotime($w['setup_start']);$newEnd=strtotime($w['event_end']);$oldStart=strtotime($other['setup_start']);$oldEnd=strtotime($other['event_end']);if(!($newEnd+$required<=$oldStart||$oldEnd+$required<=$newStart))$reasons[]=['code'=>'TRANSITION_OR_OVERLAP_CONFLICT','order_id'=>(int)$order->id,'message'=>'Conflicts with order #'.$order->id.' including '.$this->transitionMinutes.' minutes Transition Time.'];}
        return ['manager_id'=>$id,'name'=>trim(($manager->name??'').' '.($manager->lastname??'')),'email'=>$manager->email??'','available'=>!$reasons,'reasons'=>$reasons];
    }

    private function eligibleManagers(int $ownerId): array
    {
        $this->db->query("SELECT u.id,u.name,u.lastname,u.email,u.level FROM users u INNER JOIN event_manager_profiles p ON p.manager_id=u.id AND p.id_owner=:owner AND p.is_event_manager=1 WHERE u.id_owner=:owner AND u.level=4 AND u.is_active=1 ORDER BY u.name,u.lastname");$this->db->bind(':owner',$ownerId);return $this->db->fetchAll();
    }

    private function managerRows(int $ownerId,?int $id=null,bool $fallback=false): array
    {
        if($id===null)return [];
        $sql="SELECT u.id,u.name,u.lastname,u.email,u.level FROM users u LEFT JOIN event_manager_profiles p ON p.manager_id=u.id AND p.id_owner=:owner WHERE u.id=:id AND u.is_active=1 AND ((u.id_owner=:owner AND u.level=4 AND p.is_event_manager=1) OR (u.level=1 AND (u.id=:owner OR LOWER(u.email)=LOWER(:email)))) LIMIT 1";
        $this->db->query($sql);$this->db->bind(':owner',$ownerId);$this->db->bind(':id',$id);$this->db->bind(':email',$_ENV['VNV_DEFAULT_MANAGER_EMAIL']??'info@vnvevents.com');$row=$this->db->fetchOne();return $row?[$row]:[];
    }

    private function window(string $date,string $start,string $end,int $setupMinutes): array
    {
        if(!$date||!$start||!$end)throw new RuntimeException('Event date, start time and end time are required.');$setupMinutes=max(0,min(720,$setupMinutes?:60));$s=new DateTimeImmutable($date.' '.$start);$e=new DateTimeImmutable($date.' '.$end);if($e<=$s)$e=$e->modify('+1 day');$setup=$s->modify('-'.$setupMinutes.' minutes');return ['date'=>$date,'start_time'=>$s->format('H:i:s'),'end_time'=>$e->format('H:i:s'),'setup_minutes'=>$setupMinutes,'setup_start'=>$setup->format('Y-m-d H:i:s'),'event_end'=>$e->format('Y-m-d H:i:s'),'transition_minutes'=>$this->transitionMinutes];
    }
}

