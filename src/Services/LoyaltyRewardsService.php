<?php

namespace App\Services;

use App\Repositories\Connection;
use RuntimeException;

final class LoyaltyRewardsService
{
    private Connection $db;
    public function __construct() { $this->db = new Connection(); }

    public function settings(int $ownerId, string $siteKey='vnvevents'): object
    {
        $this->db->query('SELECT * FROM loyalty_settings WHERE id_owner=:owner AND site_key=:site LIMIT 1');
        $this->db->bind(':owner',$ownerId); $this->db->bind(':site',$siteKey);
        $row=$this->db->fetchOne();
        if($row) return $row;
        return (object)['id_owner'=>$ownerId,'site_key'=>$siteKey,'reward_percent'=>(float)($_ENV['LOYALTY_REWARD_PERCENT']??10),'point_value'=>(float)($_ENV['LOYALTY_POINT_DOLLAR_VALUE']??1),'release_hours'=>(int)($_ENV['LOYALTY_RELEASE_HOURS']??48),'status'=>'ACTIVE'];
    }

    public function saveSettings(int $ownerId,string $siteKey,array $input,int $actorId): void
    {
        $percent=max(0,min(100,(float)($input['reward_percent']??10)));
        $value=max(.0001,(float)($input['point_value']??1));
        $hours=max(0,min(8760,(int)($input['release_hours']??48)));
        $this->db->query("INSERT INTO loyalty_settings (id_owner,site_key,reward_percent,point_value,release_hours,status,updated_by) VALUES (:owner,:site,:percent,:value,:hours,'ACTIVE',:actor) ON DUPLICATE KEY UPDATE reward_percent=VALUES(reward_percent),point_value=VALUES(point_value),release_hours=VALUES(release_hours),updated_by=VALUES(updated_by),status='ACTIVE'");
        foreach(['owner'=>$ownerId,'site'=>$siteKey,'percent'=>$percent,'value'=>$value,'hours'=>$hours,'actor'=>$actorId] as $key=>$valueToBind)$this->db->bind(':'.$key,$valueToBind);
        $this->db->execute();
    }

    public function balance(int $ownerId,int $userId,string $siteKey='vnvevents'): array
    {
        $this->releaseDue($ownerId,$siteKey,$userId);
        $this->db->query("SELECT COALESCE(SUM(CASE WHEN status IN ('AVAILABLE','REDEEMED') THEN points ELSE 0 END),0) available_points,COALESCE(SUM(CASE WHEN status='PENDING' THEN points ELSE 0 END),0) pending_points,COALESCE(SUM(CASE WHEN transaction_type='EARN' AND status NOT IN ('CANCELLED','REVERSED') THEN points ELSE 0 END),0) lifetime_earned,ABS(COALESCE(SUM(CASE WHEN transaction_type='REDEEM' AND status='REDEEMED' THEN points ELSE 0 END),0)) lifetime_redeemed,ABS(COALESCE(SUM(CASE WHEN transaction_type='REVERSE' THEN points ELSE 0 END),0)) lifetime_reversed FROM loyalty_transactions WHERE id_owner=:owner AND site_key=:site AND id_user=:user");
        $this->db->bind(':owner',$ownerId);$this->db->bind(':site',$siteKey);$this->db->bind(':user',$userId);
        $row=$this->db->fetchOne();$settings=$this->settings($ownerId,$siteKey);
        return ['available_points'=>(float)($row->available_points??0),'pending_points'=>(float)($row->pending_points??0),'available_value'=>round((float)($row->available_points??0)*(float)$settings->point_value,2),'pending_value'=>round((float)($row->pending_points??0)*(float)$settings->point_value,2),'lifetime_earned'=>(float)($row->lifetime_earned??0),'lifetime_redeemed'=>(float)($row->lifetime_redeemed??0),'lifetime_reversed'=>(float)($row->lifetime_reversed??0),'point_value'=>(float)$settings->point_value];
    }

    public function history(int $ownerId,int $userId,string $siteKey='vnvevents'): array
    {
        $this->db->query('SELECT * FROM loyalty_transactions WHERE id_owner=:owner AND site_key=:site AND id_user=:user ORDER BY created_at DESC,id DESC');
        $this->db->bind(':owner',$ownerId);$this->db->bind(':site',$siteKey);$this->db->bind(':user',$userId);return $this->db->fetchAll();
    }

    public function adjust(int $ownerId,int $userId,float $points,string $reason,int $actorId,string $siteKey='vnvevents'): void
    {
        if(abs($points)<.0001 || trim($reason)==='') throw new RuntimeException('Points and a reason are required.');
        $settings=$this->settings($ownerId,$siteKey);$status=$points>0?'AVAILABLE':'REDEEMED';
        if($points<0 && abs($points)>$this->balance($ownerId,$userId,$siteKey)['available_points']+.0001) throw new RuntimeException('The adjustment exceeds the available balance.');
        $this->insertLedger($ownerId,$siteKey,$userId,'ADJUST',$status,$points,round($points*(float)$settings->point_value,2),(float)$settings->point_value,null,null,null,null,'Manual adjustment: '.trim($reason),$actorId,null,null);
    }

    public function earnForEventOrder(int $orderId,?int $paymentId=null): ?int
    {
        $this->db->query("SELECT o.*,COALESCE(SUM(op.amount-COALESCE(op.refunded_amount,0)),0) paid FROM orders o LEFT JOIN orders_payments op ON op.id_order=o.id AND (op.id_suborder IS NULL OR op.id_suborder=0) AND COALESCE(op.is_suborder,0)=0 AND COALESCE(op.is_refunded,0)=0 WHERE o.id=:id GROUP BY o.id");$this->db->bind(':id',$orderId);$order=$this->db->fetchOne();if(!$order)return null;
        $eligible=max(0,(float)$order->paid);if($eligible<=0 || !in_array((string)$order->status_workflow,['INVOICE_PAID'],true))return null;
        $settings=$this->settings((int)$order->id_owner,'vnvevents');$points=round($eligible*((float)$settings->reward_percent/100)/(float)$settings->point_value,4);if($points<=0)return null;
        $completion=$this->eventCompletionAt($order);$availableAt=$completion->modify('+'.(int)$settings->release_hours.' hours');$now=new \DateTimeImmutable('now',new \DateTimeZone('America/New_York'));$status=$availableAt<=$now?'AVAILABLE':'PENDING';
        try{return $this->insertLedger((int)$order->id_owner,'vnvevents',(int)$order->id_client,'EARN',$status,$points,round($points*(float)$settings->point_value,2),(float)$settings->point_value,(float)$settings->reward_percent,$eligible,'EVENT_ORDER',$orderId,'Reward for fully paid event order #'.$orderId,0,$paymentId,$availableAt->format('Y-m-d H:i:s'),null,null,$completion->format('Y-m-d H:i:s'),$status==='AVAILABLE'?date('Y-m-d H:i:s'):null);}catch(\PDOException $e){if((string)$e->getCode()==='23000')return null;throw $e;}
    }

    public function earnForStoreOrder(int $orderId, ?int $paymentId=null): ?int
    {
        $this->db->query("SELECT so.*,COALESCE(SUM(CASE WHEN sp.status='PAID' THEN sp.amount ELSE 0 END),0) paid FROM store_orders so LEFT JOIN store_payments sp ON sp.id_store_order=so.id WHERE so.id=:id GROUP BY so.id");
        $this->db->bind(':id',$orderId);$order=$this->db->fetchOne();
        if(!$order || (int)($order->id_user??0)<=0 || (string)$order->payment_status!=='PAID')return null;
        $eligible=max(0,(float)$order->paid);if($eligible<=0)return null;
        $settings=$this->settings((int)$order->id_owner,(string)($order->site_key?:'vnvevents'));
        $points=round($eligible*((float)$settings->reward_percent/100)/(float)$settings->point_value,4);if($points<=0)return null;
        $completion=$this->storeCompletionAt($order);$availableAt=$completion?$completion->modify('+'.(int)$settings->release_hours.' hours'):null;
        $status=$availableAt&&$availableAt<=new \DateTimeImmutable('now',new \DateTimeZone('America/New_York'))?'AVAILABLE':'PENDING';
        try{return $this->insertLedger((int)$order->id_owner,(string)($order->site_key?:'vnvevents'),(int)$order->id_user,'EARN',$status,$points,round($points*(float)$settings->point_value,2),(float)$settings->point_value,(float)$settings->reward_percent,$eligible,'STORE_ORDER',$orderId,'Reward for VNV Gourmet To Go order #'.$orderId,0,$paymentId,$availableAt?->format('Y-m-d H:i:s'),null,null,$completion?->format('Y-m-d H:i:s'),$status==='AVAILABLE'?date('Y-m-d H:i:s'):null);}catch(\PDOException $e){if((string)$e->getCode()==='23000')return null;throw $e;}
    }

    public function sourceCompleted(string $sourceType,int $sourceId,?string $completedAt=null): void
    {
        $completedAt=$completedAt?:date('Y-m-d H:i:s');
        $this->db->query("SELECT * FROM loyalty_transactions WHERE transaction_type='EARN' AND source_type=:type AND source_id=:id LIMIT 1");$this->db->bind(':type',$sourceType);$this->db->bind(':id',$sourceId);$earn=$this->db->fetchOne();if(!$earn)return;
        $settings=$this->settings((int)$earn->id_owner,(string)$earn->site_key);$completion=new \DateTimeImmutable($completedAt,new \DateTimeZone('America/New_York'));$available=$completion->modify('+'.(int)$settings->release_hours.' hours');
        $this->db->query("UPDATE loyalty_transactions SET source_completed_at=:completed,available_at=:available,updated_at=NOW() WHERE id=:id AND status='PENDING'");$this->db->bind(':completed',$completion->format('Y-m-d H:i:s'));$this->db->bind(':available',$available->format('Y-m-d H:i:s'));$this->db->bind(':id',(int)$earn->id);$this->db->execute();
    }

    public function releaseDue(int $ownerId,string $siteKey='vnvevents',?int $userId=null): int
    {
        $this->reconcileInvalidSources($ownerId,$siteKey,$userId);
        $reverseSql="UPDATE loyalty_transactions r JOIN loyalty_transactions e ON e.id=r.parent_transaction_id SET r.status='REDEEMED',r.updated_at=NOW() WHERE r.id_owner=:owner AND r.site_key=:site AND r.transaction_type='REVERSE' AND r.status='PENDING' AND e.available_at IS NOT NULL AND e.available_at<=NOW()".($userId?' AND r.id_user=:user':'');
        $this->db->query($reverseSql);$this->db->bind(':owner',$ownerId);$this->db->bind(':site',$siteKey);if($userId)$this->db->bind(':user',$userId);$this->db->execute();
        $sql="UPDATE loyalty_transactions SET status='AVAILABLE',released_at=COALESCE(released_at,NOW()),updated_at=NOW() WHERE id_owner=:owner AND site_key=:site AND transaction_type='EARN' AND status='PENDING' AND available_at IS NOT NULL AND available_at<=NOW()".($userId?' AND id_user=:user':'');
        $this->db->query($sql);$this->db->bind(':owner',$ownerId);$this->db->bind(':site',$siteKey);if($userId)$this->db->bind(':user',$userId);$this->db->execute();return $this->db->rowCount();
    }

    public function reconcileInvalidSources(int $ownerId,string $siteKey='vnvevents',?int $userId=null): int
    {
        $sql="SELECT lt.* FROM loyalty_transactions lt WHERE lt.id_owner=:owner AND lt.site_key=:site AND lt.transaction_type='EARN' AND lt.status IN ('PENDING','AVAILABLE')".($userId?' AND lt.id_user=:user':'');
        $this->db->query($sql);$this->db->bind(':owner',$ownerId);$this->db->bind(':site',$siteKey);if($userId)$this->db->bind(':user',$userId);$earns=$this->db->fetchAll();$count=0;
        foreach($earns as $earn){$desired=$this->desiredEligibleAmount($earn);$current=max(0,(float)$earn->eligible_amount);if($desired+0.009>=$current)continue;$this->db->query("SELECT COALESCE(SUM(eligible_amount),0) amount FROM loyalty_transactions WHERE parent_transaction_id=:parent AND transaction_type='REVERSE'");$this->db->bind(':parent',(int)$earn->id);$already=(float)($this->db->fetchOne()->amount??0);$delta=max(0,$current-$desired-$already);$points=round($delta*((float)$earn->reward_percent_snapshot/100)/(float)$earn->point_value_snapshot,4);if($points<=0)continue;$key='reconcile:'.$earn->id.':'.number_format($desired,2,'.','');$reverseStatus=$earn->status==='PENDING'?'PENDING':'REDEEMED';
            try{$this->insertLedger((int)$earn->id_owner,(string)$earn->site_key,(int)$earn->id_user,'REVERSE',$reverseStatus,-$points,-round($points*(float)$earn->point_value_snapshot,2),(float)$earn->point_value_snapshot,(float)$earn->reward_percent_snapshot,$delta,null,null,'Reward adjustment for refund or cancellation',0,null,null,(int)$earn->id,$key);$count++;}catch(\PDOException $e){if((string)$e->getCode()!=='23000')throw $e;}
        }return $count;
    }

    public function reserve(int $ownerId,int $userId,float $requestedPoints,float $maximumDiscount,string $targetType,int $targetId,string $siteKey='vnvevents'): array
    {
        if($requestedPoints<=0||$maximumDiscount<=0)throw new RuntimeException('Enter a valid reward amount.');
        $this->db->beginTransaction();
        try{
            $settings=$this->settings($ownerId,$siteKey);$value=(float)$settings->point_value;
            $this->db->query("SELECT COALESCE(SUM(points),0) points FROM loyalty_transactions WHERE id_owner=:owner AND site_key=:site AND id_user=:user AND status IN ('AVAILABLE','REDEEMED') FOR UPDATE");
            $this->db->bind(':owner',$ownerId);$this->db->bind(':site',$siteKey);$this->db->bind(':user',$userId);$available=(float)($this->db->fetchOne()->points??0);
            $this->db->query("SELECT COALESCE(SUM(points),0) points FROM loyalty_redemption_reservations WHERE id_owner=:owner AND site_key=:site AND id_user=:user AND status='HELD' AND expires_at>NOW() FOR UPDATE");
            $this->db->bind(':owner',$ownerId);$this->db->bind(':site',$siteKey);$this->db->bind(':user',$userId);$held=(float)($this->db->fetchOne()->points??0);
            $points=min($requestedPoints,max(0,$available-$held),$maximumDiscount/$value);if($points<=0)throw new RuntimeException('No available rewards can be applied.');
            $money=round($points*$value,2);$token=$this->uuid();
            $this->db->query("INSERT INTO loyalty_redemption_reservations (reservation_token,id_owner,site_key,id_user,target_type,target_id,points,monetary_value,status,expires_at) VALUES (:token,:owner,:site,:user,:type,:target,:points,:money,'HELD',DATE_ADD(NOW(),INTERVAL 20 MINUTE))");
            foreach(['token'=>$token,'owner'=>$ownerId,'site'=>$siteKey,'user'=>$userId,'type'=>$targetType,'target'=>$targetId,'points'=>$points,'money'=>$money] as $k=>$v)$this->db->bind(':'.$k,$v);$this->db->execute();$this->db->commit();return ['token'=>$token,'points'=>$points,'discount'=>$money];
        }catch(\Throwable $e){$this->db->rollback();throw $e;}
    }

    public function commitReservation(string $token,int $actorId=0): ?int
    {
        $this->db->beginTransaction();try{$this->db->query("SELECT * FROM loyalty_redemption_reservations WHERE reservation_token=:token FOR UPDATE");$this->db->bind(':token',$token);$r=$this->db->fetchOne();if(!$r||$r->status!=='HELD')throw new RuntimeException('Reward reservation is no longer available.');
            $settings=$this->settings((int)$r->id_owner,(string)$r->site_key);$id=$this->insertLedger((int)$r->id_owner,(string)$r->site_key,(int)$r->id_user,'REDEEM','REDEEMED',-(float)$r->points,-(float)$r->monetary_value,(float)$settings->point_value,null,null,(string)$r->target_type,(int)$r->target_id,'Rewards applied to '.$r->target_type.' #'.$r->target_id,$actorId,null,null);
            $this->db->query("UPDATE loyalty_redemption_reservations SET status='COMMITTED',committed_transaction_id=:transaction WHERE id=:id AND status='HELD'");$this->db->bind(':transaction',$id);$this->db->bind(':id',(int)$r->id);$this->db->execute();$this->db->commit();return $id;
        }catch(\Throwable $e){$this->db->rollback();throw $e;}
    }

    public function releaseReservation(string $token): void { $this->db->query("UPDATE loyalty_redemption_reservations SET status='RELEASED' WHERE reservation_token=:token AND status='HELD'");$this->db->bind(':token',$token);$this->db->execute(); }

    public function reverseForEventPayment(string $providerPaymentId, float $refundedAmount): ?int
    {
        if ($providerPaymentId === '' || $refundedAmount <= 0) return null;
        $this->db->query("SELECT op.id payment_id,op.id_order,op.amount,o.id_owner,o.id_client FROM orders_payments op JOIN orders o ON o.id=op.id_order WHERE op.stripe_charge_id=:charge AND (op.id_suborder IS NULL OR op.id_suborder=0) AND COALESCE(op.is_suborder,0)=0 LIMIT 1");
        $this->db->bind(':charge', $providerPaymentId);
        $payment = $this->db->fetchOne();
        if (!$payment) return null;

        $settings = $this->settings((int)$payment->id_owner, 'vnvevents');
        $eligibleRefund = min((float)$payment->amount, $refundedAmount);
        $points = round($eligibleRefund * ((float)$settings->reward_percent / 100) / (float)$settings->point_value, 4);
        if ($points <= 0) return null;

        $this->db->query("SELECT status FROM loyalty_transactions WHERE id_owner=:owner AND site_key='vnvevents' AND transaction_type='EARN' AND source_type='EVENT_ORDER' AND source_id=:order LIMIT 1");
        $this->db->bind(':owner', (int)$payment->id_owner);
        $this->db->bind(':order', (int)$payment->id_order);
        $earn = $this->db->fetchOne();
        if (!$earn) return null;
        $status = $earn->status === 'PENDING' ? 'PENDING' : 'REDEEMED';
        try {
            return $this->insertLedger((int)$payment->id_owner, 'vnvevents', (int)$payment->id_client, 'REVERSE', $status, -$points, -round($points * (float)$settings->point_value, 2), (float)$settings->point_value, (float)$settings->reward_percent, $eligibleRefund, 'EVENT_PAYMENT_REFUND', (int)$payment->payment_id, 'Reward reversal for refunded event payment #' . $payment->payment_id, 0, (int)$payment->payment_id, null);
        } catch (\PDOException $e) {
            if ((string)$e->getCode() === '23000') return null;
            throw $e;
        }
    }

    private function eventCompletionAt(object $order): \DateTimeImmutable { $time=trim((string)($order->end_time??''))?:'23:59:59';return new \DateTimeImmutable((string)$order->event_date.' '.$time,new \DateTimeZone('America/New_York')); }
    private function storeCompletionAt(object $order): ?\DateTimeImmutable { if(!in_array((string)$order->status,['DELIVERED','COMPLETED'],true))return null;$value=(string)($order->reward_completed_at??$order->updated_at??'now');return new \DateTimeImmutable($value,new \DateTimeZone('America/New_York')); }
    private function desiredEligibleAmount(object $earn): float { if($earn->source_type==='EVENT_ORDER'){$this->db->query("SELECT o.status_workflow,COALESCE(SUM(CASE WHEN COALESCE(op.is_refunded,0)=0 THEN GREATEST(0,op.amount-COALESCE(op.refunded_amount,0)) ELSE 0 END),0) eligible FROM orders o LEFT JOIN orders_payments op ON op.id_order=o.id AND (op.id_suborder IS NULL OR op.id_suborder=0) AND COALESCE(op.is_suborder,0)=0 WHERE o.id=:id GROUP BY o.id");$this->db->bind(':id',(int)$earn->source_id);$row=$this->db->fetchOne();if(!$row||in_array((string)$row->status_workflow,['CANCELLED','INVOICE_CANCELLED'],true))return 0;return min((float)$earn->eligible_amount,(float)$row->eligible);}if($earn->source_type==='STORE_ORDER'){$this->db->query("SELECT so.status,so.payment_status,COALESCE(SUM(CASE WHEN sp.status='PAID' THEN GREATEST(0,sp.amount-COALESCE(sp.refunded_amount,0)) ELSE 0 END),0) eligible FROM store_orders so LEFT JOIN store_payments sp ON sp.id_store_order=so.id WHERE so.id=:id GROUP BY so.id");$this->db->bind(':id',(int)$earn->source_id);$row=$this->db->fetchOne();if(!$row||$row->status==='CANCELLED'||$row->payment_status==='REFUNDED')return 0;return min((float)$earn->eligible_amount,(float)$row->eligible);}return (float)$earn->eligible_amount; }
    private function uuid(): string { $d=random_bytes(16);$d[6]=chr((ord($d[6])&0x0f)|0x40);$d[8]=chr((ord($d[8])&0x3f)|0x80);return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($d),4)); }

    private function insertLedger(int $owner,string $site,int $user,string $type,string $status,float $points,float $money,float $pointValue,?float $percent,?float $eligible,?string $sourceType,?int $sourceId,string $description,int $actor,?int $paymentId,?string $availableAt,?int $parentId=null,?string $reconciliationKey=null,?string $completedAt=null,?string $releasedAt=null): int
    {
        $this->db->query('INSERT INTO loyalty_transactions (id_owner,site_key,id_user,transaction_type,status,points,monetary_value,point_value_snapshot,reward_percent_snapshot,eligible_amount,source_type,source_id,source_payment_id,parent_transaction_id,reconciliation_key,description,earned_at,source_completed_at,available_at,released_at,created_by) VALUES (:owner,:site,:user,:type,:status,:points,:money,:point_value,:percent,:eligible,:source_type,:source_id,:payment,:parent,:reconciliation,:description,NOW(),:completed_at,:available_at,:released_at,:actor)');
        foreach(['owner'=>$owner,'site'=>$site,'user'=>$user,'type'=>$type,'status'=>$status,'points'=>$points,'money'=>$money,'point_value'=>$pointValue,'percent'=>$percent,'eligible'=>$eligible,'source_type'=>$sourceType,'source_id'=>$sourceId,'payment'=>$paymentId,'parent'=>$parentId,'reconciliation'=>$reconciliationKey,'description'=>$description,'completed_at'=>$completedAt,'available_at'=>$availableAt,'released_at'=>$releasedAt,'actor'=>$actor] as $key=>$value)$this->db->bind(':'.$key,$value);
        $this->db->execute();return (int)$this->db->lastId();
    }
}
