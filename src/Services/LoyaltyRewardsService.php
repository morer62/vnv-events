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
        return (object)['id_owner'=>$ownerId,'site_key'=>$siteKey,'reward_percent'=>(float)($_ENV['LOYALTY_REWARD_PERCENT']??10),'point_value'=>(float)($_ENV['LOYALTY_POINT_DOLLAR_VALUE']??1),'release_days'=>(int)($_ENV['LOYALTY_RELEASE_DAYS']??5),'status'=>'ACTIVE'];
    }

    public function saveSettings(int $ownerId,string $siteKey,array $input,int $actorId): void
    {
        $percent=max(0,min(100,(float)($input['reward_percent']??10)));
        $value=max(.0001,(float)($input['point_value']??1));
        $days=max(0,min(365,(int)($input['release_days']??5)));
        $this->db->query("INSERT INTO loyalty_settings (id_owner,site_key,reward_percent,point_value,release_days,status,updated_by) VALUES (:owner,:site,:percent,:value,:days,'ACTIVE',:actor) ON DUPLICATE KEY UPDATE reward_percent=VALUES(reward_percent),point_value=VALUES(point_value),release_days=VALUES(release_days),updated_by=VALUES(updated_by),status='ACTIVE'");
        foreach(['owner'=>$ownerId,'site'=>$siteKey,'percent'=>$percent,'value'=>$value,'days'=>$days,'actor'=>$actorId] as $key=>$valueToBind)$this->db->bind(':'.$key,$valueToBind);
        $this->db->execute();
    }

    public function balance(int $ownerId,int $userId,string $siteKey='vnvevents'): array
    {
        $this->releaseDue($ownerId,$siteKey,$userId);
        $this->db->query("SELECT COALESCE(SUM(CASE WHEN status IN ('AVAILABLE','REDEEMED') THEN points ELSE 0 END),0) available_points,COALESCE(SUM(CASE WHEN status='PENDING' THEN points ELSE 0 END),0) pending_points,COALESCE(SUM(CASE WHEN transaction_type='EARN' AND status NOT IN ('CANCELLED','REVERSED') THEN points ELSE 0 END),0) lifetime_earned,ABS(COALESCE(SUM(CASE WHEN transaction_type='REDEEM' AND status='REDEEMED' THEN points ELSE 0 END),0)) lifetime_redeemed FROM loyalty_transactions WHERE id_owner=:owner AND site_key=:site AND id_user=:user");
        $this->db->bind(':owner',$ownerId);$this->db->bind(':site',$siteKey);$this->db->bind(':user',$userId);
        $row=$this->db->fetchOne();$settings=$this->settings($ownerId,$siteKey);
        return ['available_points'=>(float)($row->available_points??0),'pending_points'=>(float)($row->pending_points??0),'available_value'=>round((float)($row->available_points??0)*(float)$settings->point_value,2),'pending_value'=>round((float)($row->pending_points??0)*(float)$settings->point_value,2),'lifetime_earned'=>(float)($row->lifetime_earned??0),'lifetime_redeemed'=>(float)($row->lifetime_redeemed??0),'point_value'=>(float)$settings->point_value];
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
        $eventDate=new \DateTimeImmutable((string)$order->event_date.' 23:59:59',new \DateTimeZone('America/New_York'));$availableAt=$eventDate->modify('+'.(int)$settings->release_days.' days');$status=$availableAt<=new \DateTimeImmutable('now',new \DateTimeZone('America/New_York'))?'AVAILABLE':'PENDING';
        try{return $this->insertLedger((int)$order->id_owner,'vnvevents',(int)$order->id_client,'EARN',$status,$points,round($points*(float)$settings->point_value,2),(float)$settings->point_value,(float)$settings->reward_percent,$eligible,'EVENT_ORDER',$orderId,'Reward for fully paid event order #'.$orderId,0,$paymentId,$availableAt->format('Y-m-d H:i:s'));}catch(\PDOException $e){if((string)$e->getCode()==='23000')return null;throw $e;}
    }

    public function releaseDue(int $ownerId,string $siteKey='vnvevents',?int $userId=null): int
    {
        $sql="UPDATE loyalty_transactions SET status='AVAILABLE',updated_at=NOW() WHERE id_owner=:owner AND site_key=:site AND transaction_type='EARN' AND status='PENDING' AND available_at<=NOW()".($userId?' AND id_user=:user':'');
        $this->db->query($sql);$this->db->bind(':owner',$ownerId);$this->db->bind(':site',$siteKey);if($userId)$this->db->bind(':user',$userId);$this->db->execute();return $this->db->rowCount();
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
        $this->db->beginTransaction();try{$this->db->query("SELECT * FROM loyalty_redemption_reservations WHERE reservation_token=:token AND expires_at>NOW() FOR UPDATE");$this->db->bind(':token',$token);$r=$this->db->fetchOne();if(!$r||$r->status!=='HELD')throw new RuntimeException('Reward reservation expired.');
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

    private function uuid(): string { $d=random_bytes(16);$d[6]=chr((ord($d[6])&0x0f)|0x40);$d[8]=chr((ord($d[8])&0x3f)|0x80);return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($d),4)); }

    private function insertLedger(int $owner,string $site,int $user,string $type,string $status,float $points,float $money,float $pointValue,?float $percent,?float $eligible,?string $sourceType,?int $sourceId,string $description,int $actor,?int $paymentId,?string $availableAt): int
    {
        $this->db->query('INSERT INTO loyalty_transactions (id_owner,site_key,id_user,transaction_type,status,points,monetary_value,point_value_snapshot,reward_percent_snapshot,eligible_amount,source_type,source_id,source_payment_id,description,earned_at,available_at,created_by) VALUES (:owner,:site,:user,:type,:status,:points,:money,:point_value,:percent,:eligible,:source_type,:source_id,:payment,:description,NOW(),:available_at,:actor)');
        foreach(['owner'=>$owner,'site'=>$site,'user'=>$user,'type'=>$type,'status'=>$status,'points'=>$points,'money'=>$money,'point_value'=>$pointValue,'percent'=>$percent,'eligible'=>$eligible,'source_type'=>$sourceType,'source_id'=>$sourceId,'payment'=>$paymentId,'description'=>$description,'available_at'=>$availableAt,'actor'=>$actor] as $key=>$value)$this->db->bind(':'.$key,$value);
        $this->db->execute();return (int)$this->db->lastId();
    }
}
