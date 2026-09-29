<?php

namespace App\Services;

use App\Repositories\Connection;

final class GourmetRecurringOrderService
{
    public function __construct(private ?Connection $db = null)
    {
        $this->db ??= new Connection();
    }

    /**
     * Creates the recurrence parent, immutable item configuration and a limited
     * occurrence horizon. Monetary values supplied here are snapshots only;
     * billing must recalculate them before charging.
     */
    public function create(array $recurrence, array $items): int
    {
        $this->validate($recurrence, $items);
        $timezone = new \DateTimeZone($recurrence['timezone'] ?? 'America/New_York');
        $leadHours = max(1, (int)($recurrence['payment_lead_hours'] ?? 48));
        $occurrences = $this->buildOccurrences($recurrence, $timezone, $leadHours);
        if (!$occurrences) {
            throw new \DomainException('The recurrence did not produce any eligible deliveries.');
        }

        $now = gmdate('Y-m-d H:i:s');
        $this->db->beginTransaction();
        try {
            $this->db->query('INSERT INTO store_recurring_orders
                (id_owner,site_key,id_user,id_client,saved_payment_method_id,title,status,recurrence_type,interval_value,weekdays_json,timezone,local_delivery_time,start_date,end_date,occurrence_limit,payment_lead_hours,delivery_address_json,delivery_instructions,expected_subtotal,expected_delivery_fee,expected_tax,expected_total,currency,next_occurrence_at_utc,created_at,updated_at)
                VALUES (:owner,:site,:user,:client,:method,:title,\'ACTIVE\',:type,:interval,:weekdays,:timezone,:time,:start,:end,:limit_count,:lead,:address,:instructions,:subtotal,:delivery,:tax,:total,:currency,:next_occurrence,:created,:updated)');
            $bindings = [
                ':owner'=>(int)$recurrence['id_owner'], ':site'=>(string)$recurrence['site_key'],
                ':user'=>$recurrence['id_user'] ?? null, ':client'=>$recurrence['id_client'] ?? null,
                ':method'=>(int)$recurrence['saved_payment_method_id'], ':title'=>(string)$recurrence['title'],
                ':type'=>strtoupper((string)$recurrence['recurrence_type']), ':interval'=>max(1,(int)($recurrence['interval_value'] ?? 1)),
                ':weekdays'=>json_encode(array_values($recurrence['weekdays'] ?? [])), ':timezone'=>$timezone->getName(),
                ':time'=>(string)$recurrence['local_delivery_time'], ':start'=>(string)$recurrence['start_date'],
                ':end'=>$recurrence['end_date'] ?? null, ':limit_count'=>$recurrence['occurrence_limit'] ?? null,
                ':lead'=>$leadHours, ':address'=>json_encode($recurrence['delivery_address'], JSON_UNESCAPED_SLASHES),
                ':instructions'=>$recurrence['delivery_instructions'] ?? null,
                ':subtotal'=>(float)($recurrence['expected_subtotal'] ?? 0), ':delivery'=>(float)($recurrence['expected_delivery_fee'] ?? 0),
                ':tax'=>(float)($recurrence['expected_tax'] ?? 0), ':total'=>(float)($recurrence['expected_total'] ?? 0),
                ':currency'=>strtoupper((string)($recurrence['currency'] ?? 'USD')), ':next_occurrence'=>$occurrences[0]['scheduled_at_utc'],
                ':created'=>$now, ':updated'=>$now,
            ];
            foreach ($bindings as $key=>$value) $this->db->bind($key,$value,is_int($value)?\PDO::PARAM_INT:null);
            $this->db->execute();
            $recurrenceId = (int)$this->db->lastId();

            foreach ($items as $item) {
                $this->db->query('INSERT INTO store_recurring_order_items
                    (recurring_order_id,id_product,id_product_variation,product_name_snapshot,variation_name_snapshot,configuration_json,quantity,servings,expected_unit_price,created_at,updated_at)
                    VALUES (:parent,:product,:variation,:name,:variation_name,:configuration,:quantity,:servings,:price,:created,:updated)');
                $values = [':parent'=>$recurrenceId,':product'=>(int)$item['id_product'],':variation'=>$item['id_product_variation']??null,':name'=>(string)$item['product_name_snapshot'],':variation_name'=>$item['variation_name_snapshot']??null,':configuration'=>json_encode($item['configuration']??[],JSON_UNESCAPED_SLASHES),':quantity'=>max(1,(int)($item['quantity']??1)),':servings'=>$item['servings']??null,':price'=>(float)($item['expected_unit_price']??0),':created'=>$now,':updated'=>$now];
                foreach($values as $key=>$value) $this->db->bind($key,$value,is_int($value)?\PDO::PARAM_INT:null);
                $this->db->execute();
            }

            foreach ($occurrences as $index=>$occurrence) {
                $key = sprintf('gourmet-recurring-%d-%04d', $recurrenceId, $index + 1);
                $this->db->query('INSERT INTO store_recurring_occurrences
                    (recurring_order_id,sequence_number,scheduled_at_utc,local_scheduled_at,timezone,charge_at_utc,status,expected_subtotal,expected_delivery_fee,expected_tax,expected_total,payment_status,idempotency_key,created_at,updated_at)
                    VALUES (:parent,:sequence,:scheduled_utc,:scheduled_local,:timezone,:charge,\'SCHEDULED\',:subtotal,:delivery,:tax,:total,\'PENDING\',:idempotency,:created,:updated)');
                $values=[':parent'=>$recurrenceId,':sequence'=>$index+1,':scheduled_utc'=>$occurrence['scheduled_at_utc'],':scheduled_local'=>$occurrence['local_scheduled_at'],':timezone'=>$timezone->getName(),':charge'=>$occurrence['charge_at_utc'],':subtotal'=>(float)($recurrence['expected_subtotal']??0),':delivery'=>(float)($recurrence['expected_delivery_fee']??0),':tax'=>(float)($recurrence['expected_tax']??0),':total'=>(float)($recurrence['expected_total']??0),':idempotency'=>$key,':created'=>$now,':updated'=>$now];
                foreach($values as $binding=>$value) $this->db->bind($binding,$value,is_int($value)?\PDO::PARAM_INT:null);
                $this->db->execute();
            }
            $this->db->commit();
            return $recurrenceId;
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function skipOccurrence(int $occurrenceId, int $userId): bool
    {
        return $this->updateOwnedFutureOccurrence($occurrenceId, $userId, "status='SKIPPED'", "o.status='SCHEDULED' AND o.payment_status='PENDING'");
    }

    public function cancelOccurrence(int $occurrenceId, int $userId): bool
    {
        return $this->updateOwnedFutureOccurrence($occurrenceId, $userId, "status='CANCELLED'", "o.status='SCHEDULED' AND o.payment_status='PENDING'");
    }

    public function rescheduleOccurrence(int $occurrenceId, int $userId, string $localDateTime): bool
    {
        $this->db->query('SELECT o.*,r.payment_lead_hours FROM store_recurring_occurrences o INNER JOIN store_recurring_orders r ON r.id=o.recurring_order_id WHERE o.id=:id AND r.id_user=:user AND o.status=\'SCHEDULED\' AND o.payment_status=\'PENDING\' LIMIT 1');
        $this->db->bind(':id',$occurrenceId,\PDO::PARAM_INT); $this->db->bind(':user',$userId,\PDO::PARAM_INT);
        $row=$this->db->fetchOne(); if(!$row) return false;
        $local=new \DateTimeImmutable($localDateTime,new \DateTimeZone($row->timezone));
        if($local<=new \DateTimeImmutable('now',new \DateTimeZone($row->timezone))) throw new \DomainException('Delivery must be in the future.');
        $utc=$local->setTimezone(new \DateTimeZone('UTC'));
        $charge=$utc->modify('-'.max(1,(int)$row->payment_lead_hours).' hours');
        $this->db->query('UPDATE store_recurring_occurrences SET local_scheduled_at=:local,scheduled_at_utc=:utc,charge_at_utc=:charge,updated_at=UTC_TIMESTAMP() WHERE id=:id');
        $this->db->bind(':local',$local->format('Y-m-d H:i:s')); $this->db->bind(':utc',$utc->format('Y-m-d H:i:s')); $this->db->bind(':charge',$charge->format('Y-m-d H:i:s')); $this->db->bind(':id',$occurrenceId,\PDO::PARAM_INT); $this->db->execute();
        return $this->db->rowCount()===1;
    }

    public function setStatus(int $recurrenceId, int $userId, string $status): bool
    {
        $status=strtoupper($status); if(!in_array($status,['ACTIVE','PAUSED','CANCELLED'],true)) throw new \InvalidArgumentException('Unsupported recurrence status.');
        $extra=$status==='CANCELLED'?',cancelled_at=UTC_TIMESTAMP()':($status==='PAUSED'?',paused_at=UTC_TIMESTAMP()':',paused_at=NULL');
        $this->db->beginTransaction();
        try {
            $this->db->query("UPDATE store_recurring_orders SET status=:status {$extra},updated_at=UTC_TIMESTAMP() WHERE id=:id AND id_user=:user AND status NOT IN ('CANCELLED','COMPLETED')");
            $this->db->bind(':status',$status); $this->db->bind(':id',$recurrenceId,\PDO::PARAM_INT); $this->db->bind(':user',$userId,\PDO::PARAM_INT); $this->db->execute();
            if($status==='CANCELLED'){
                $this->db->query("UPDATE store_recurring_occurrences SET status='CANCELLED',updated_at=UTC_TIMESTAMP() WHERE recurring_order_id=:id AND status='SCHEDULED' AND payment_status='PENDING'");
                $this->db->bind(':id',$recurrenceId,\PDO::PARAM_INT); $this->db->execute();
            }
            $this->db->commit(); return true;
        }catch(\Throwable $e){$this->db->rollback();throw $e;}
    }

    /** Change the schedule for pending deliveries only; paid/history rows never move. */
    public function rescheduleFuture(int $recurrenceId,int $userId,string $effectiveDate,string $localTime,array $weekdays): int
    {
        $this->db->query("SELECT * FROM store_recurring_orders WHERE id=:id AND id_user=:user AND status IN ('ACTIVE','PAUSED') LIMIT 1");
        $this->db->bind(':id',$recurrenceId,\PDO::PARAM_INT);$this->db->bind(':user',$userId,\PDO::PARAM_INT);$parent=$this->db->fetchOne();
        if(!$parent) throw new \DomainException('Recurring order not found or cannot be changed.');
        $weekdays=array_values(array_unique(array_filter(array_map('intval',$weekdays),fn($day)=>$day>=1&&$day<=7)));
        if(!$weekdays) throw new \InvalidArgumentException('Select at least one delivery weekday.');
        $timezone=new \DateTimeZone((string)$parent->timezone); $cursor=new \DateTimeImmutable($effectiveDate.' '.$localTime,$timezone);
        $this->db->query("SELECT * FROM store_recurring_occurrences WHERE recurring_order_id=:parent AND status='SCHEDULED' AND payment_status='PENDING' AND local_scheduled_at>=:effective ORDER BY sequence_number");
        $this->db->bind(':parent',$recurrenceId,\PDO::PARAM_INT);$this->db->bind(':effective',$cursor->format('Y-m-d 00:00:00'));$rows=$this->db->fetchAll()?:[];
        if(!$rows) return 0;
        $this->db->beginTransaction();
        try{
            foreach($rows as $row){
                while(!in_array((int)$cursor->format('N'),$weekdays,true)) $cursor=$cursor->modify('+1 day');
                $utc=$cursor->setTimezone(new \DateTimeZone('UTC'));$charge=$utc->modify('-'.max(1,(int)$parent->payment_lead_hours).' hours');
                $this->db->query("UPDATE store_recurring_occurrences SET local_scheduled_at=:local,scheduled_at_utc=:utc,charge_at_utc=:charge,updated_at=UTC_TIMESTAMP() WHERE id=:id AND status='SCHEDULED' AND payment_status='PENDING'");
                $this->db->bind(':local',$cursor->format('Y-m-d H:i:s'));$this->db->bind(':utc',$utc->format('Y-m-d H:i:s'));$this->db->bind(':charge',$charge->format('Y-m-d H:i:s'));$this->db->bind(':id',(int)$row->id,\PDO::PARAM_INT);$this->db->execute();
                $cursor=$cursor->modify('+1 day');
            }
            $this->db->query("UPDATE store_recurring_orders SET recurrence_type='SELECTED_WEEKDAYS',interval_value=1,weekdays_json=:weekdays,local_delivery_time=:time,updated_at=UTC_TIMESTAMP() WHERE id=:id AND id_user=:user");
            $this->db->bind(':weekdays',json_encode($weekdays));$this->db->bind(':time',$localTime);$this->db->bind(':id',$recurrenceId,\PDO::PARAM_INT);$this->db->bind(':user',$userId,\PDO::PARAM_INT);$this->db->execute();
            $this->db->commit();return count($rows);
        }catch(\Throwable $e){$this->db->rollback();throw $e;}
    }

    private function updateOwnedFutureOccurrence(int $occurrenceId,int $userId,string $set,string $conditions): bool
    {
        $this->db->query("UPDATE store_recurring_occurrences o INNER JOIN store_recurring_orders r ON r.id=o.recurring_order_id SET o.{$set},o.updated_at=UTC_TIMESTAMP() WHERE o.id=:id AND r.id_user=:user AND {$conditions}");
        $this->db->bind(':id',$occurrenceId,\PDO::PARAM_INT); $this->db->bind(':user',$userId,\PDO::PARAM_INT); $this->db->execute(); return $this->db->rowCount()===1;
    }

    private function validate(array $recurrence,array $items): void
    {
        foreach(['id_owner','site_key','saved_payment_method_id','title','recurrence_type','local_delivery_time','start_date','delivery_address'] as $field) if(empty($recurrence[$field])) throw new \InvalidArgumentException("Missing recurrence field: {$field}");
        if(!$items) throw new \InvalidArgumentException('At least one recurring item is required.');
        if(!in_array(strtoupper((string)$recurrence['recurrence_type']),['WEEKLY','SELECTED_WEEKDAYS','INTERVAL_DAYS','INTERVAL_WEEKS'],true)) throw new \InvalidArgumentException('Unsupported recurrence type.');
    }

    private function buildOccurrences(array $rule,\DateTimeZone $timezone,int $leadHours): array
    {
        $start=new \DateTimeImmutable($rule['start_date'].' '.$rule['local_delivery_time'],$timezone);
        $end=!empty($rule['end_date'])?new \DateTimeImmutable($rule['end_date'].' 23:59:59',$timezone):$start->modify('+'.max(1,(int)($rule['horizon_weeks']??12)).' weeks');
        $limit=max(1,(int)($rule['occurrence_limit']??1000)); $type=strtoupper((string)$rule['recurrence_type']); $interval=max(1,(int)($rule['interval_value']??1)); $weekdays=array_map('intval',$rule['weekdays']??[]); $out=[];
        for($candidate=$start;$candidate<=$end && count($out)<$limit;$candidate=$candidate->modify('+1 day')){
            $days=(int)$start->setTime(0,0)->diff($candidate->setTime(0,0))->format('%a'); $weeks=intdiv($days,7); $weekday=(int)$candidate->format('N');
            $matches=match($type){'SELECTED_WEEKDAYS'=>in_array($weekday,$weekdays,true)&&$weeks%$interval===0,'INTERVAL_DAYS'=>$days%$interval===0,'INTERVAL_WEEKS'=>$weekday===(int)$start->format('N')&&$weeks%$interval===0,default=>$weekday===(int)$start->format('N')&&$weeks%$interval===0};
            if(!$matches) continue;
            $utc=$candidate->setTimezone(new \DateTimeZone('UTC')); $charge=$utc->modify("-{$leadHours} hours");
            $out[]=['local_scheduled_at'=>$candidate->format('Y-m-d H:i:s'),'scheduled_at_utc'=>$utc->format('Y-m-d H:i:s'),'charge_at_utc'=>$charge->format('Y-m-d H:i:s')];
        }
        return $out;
    }
}
