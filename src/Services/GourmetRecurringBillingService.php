<?php

namespace App\Services;

use App\Repositories\ClientSavedPaymentMethodsRepository;
use App\Repositories\Connection;
use App\Repositories\PaymentProvidersRepository;
use App\Services\Delivery\DeliveryPricingService;
use App\Services\Payment\PaymentProviderFactory;
use App\Services\Payment\StripeProvider;

final class GourmetRecurringPersistenceException extends \RuntimeException {}

final class GourmetRecurringBillingService
{
    public function __construct(private ?Connection $db=null){$this->db??=new Connection();}

    /** @return array{processed:int,paid:int,failed:int,dry_run:bool} */
    public function processDue(int $limit=20,bool $dryRun=false): array
    {
        $result=['processed'=>0,'paid'=>0,'failed'=>0,'dry_run'=>$dryRun];
        $this->db->query("SELECT o.id FROM store_recurring_occurrences o INNER JOIN store_recurring_orders r ON r.id=o.recurring_order_id WHERE o.status='SCHEDULED' AND o.payment_status='PENDING' AND o.charge_at_utc<=UTC_TIMESTAMP() AND r.status='ACTIVE' ORDER BY o.charge_at_utc,o.id LIMIT :limit");
        $this->db->bind(':limit',max(1,min(100,$limit)),\PDO::PARAM_INT); $ids=array_map(fn($row)=>(int)$row->id,$this->db->fetchAll());
        foreach($ids as $id){
            $result['processed']++; if($dryRun)continue;
            try{$this->processOccurrence($id);$result['paid']++;}
            catch(GourmetRecurringPersistenceException $e){$result['failed']++;error_log($e->getMessage());}
            catch(\Throwable $e){$this->markFailed($id,$e->getMessage());$result['failed']++;}
        }
        return $result;
    }

    public function processOccurrence(int $occurrenceId): int
    {
        $occurrence=$this->claim($occurrenceId);
        if(!$occurrence) throw new \RuntimeException('Occurrence is no longer eligible.');
        $parent=$this->fetchOne('SELECT * FROM store_recurring_orders WHERE id=:id',[':id'=>(int)$occurrence->recurring_order_id]);
        if(!$parent||$parent->status!=='ACTIVE') throw new \DomainException('Recurring order is not active.');
        $method=(new ClientSavedPaymentMethodsRepository())->getActiveByIdForBusiness((int)$parent->saved_payment_method_id,(int)$parent->id_owner);
        if(!$method||strtolower((string)$method->payment_provider)!=='stripe'||empty($method->provider_customer_id)||empty($method->provider_payment_method_id)) throw new \DomainException('A valid saved Stripe PaymentMethod is required.');
        $items=$this->priceItems($parent);
        $subtotal=round(array_sum(array_column($items,'line_total')),2);
        $address=json_decode((string)$parent->delivery_address_json,true)?:[];
        $delivery=$this->calculateDelivery($parent,$address,(string)$occurrence->local_scheduled_at);
        $settings=(new DeliveryPricingService())->settings((int)$parent->id_owner,(string)$parent->site_key);
        $tax=round(($subtotal+$delivery['customer_fee'])*max(0,(float)($settings['tax_rate_percent']??0))/100,2);
        $total=round($subtotal+$delivery['customer_fee']+$tax,2);
        $expected=max(.01,(float)$occurrence->expected_total); $changePercent=abs($total-$expected)/$expected*100;
        if($changePercent>max(0,(float)($settings['material_price_change_percent']??10))) throw new \DomainException('The recalculated total changed materially and requires customer approval.');
        $credentials=(new PaymentProvidersRepository())->getActiveProviderForOwner((int)$parent->id_owner);
        $provider=$credentials?PaymentProviderFactory::create($credentials):null;
        if(!$provider instanceof StripeProvider) throw new \RuntimeException('An active Stripe provider is required for recurring billing.');
        $charge=$provider->chargeCustomerWithPaymentMethod((string)$method->provider_customer_id,(string)$method->provider_payment_method_id,$total,[
            'description'=>'VNV Gourmet recurring delivery #'.$occurrenceId,
            'idempotency_key'=>(string)$occurrence->idempotency_key,
            'customer_email'=>$parent->id_user?(string)($this->fetchOne('SELECT email FROM users WHERE id=:id',[':id'=>(int)$parent->id_user])->email??''):'',
            'recurring_order_id'=>(string)$parent->id,'occurrence_id'=>(string)$occurrenceId,
        ]);
        if(!$charge||!$charge->paid) throw new \RuntimeException('Stripe automatic payment failed.');
        try{
            return $this->createPaidOrder($parent,$occurrence,$items,$address,$delivery,$subtotal,$tax,$total,$charge);
        }catch(\Throwable $e){
            $this->execute("UPDATE store_recurring_occurrences SET status='SCHEDULED',payment_status='PENDING',last_error=:error,updated_at=UTC_TIMESTAMP() WHERE id=:id AND id_store_order IS NULL",[':error'=>'Stripe succeeded; order persistence will retry safely: '.mb_substr($e->getMessage(),0,1500),':id'=>$occurrenceId]);
            throw new GourmetRecurringPersistenceException('Occurrence '.$occurrenceId.' payment succeeded but order persistence must retry: '.$e->getMessage(),0,$e);
        }
    }

    private function claim(int $id): ?object
    {
        $this->db->beginTransaction();
        try{
            $row=$this->fetchOne("SELECT o.* FROM store_recurring_occurrences o INNER JOIN store_recurring_orders r ON r.id=o.recurring_order_id WHERE o.id=:id AND o.status='SCHEDULED' AND o.payment_status='PENDING' AND o.charge_at_utc<=UTC_TIMESTAMP() AND r.status='ACTIVE' FOR UPDATE",[':id'=>$id]);
            if(!$row){$this->db->rollback();return null;}
            $this->execute("UPDATE store_recurring_occurrences SET status='PROCESSING_PAYMENT',payment_status='PROCESSING',payment_attempts=payment_attempts+1,updated_at=UTC_TIMESTAMP() WHERE id=:id",[':id'=>$id]);
            $this->db->commit(); return $row;
        }catch(\Throwable $e){$this->db->rollback();throw $e;}
    }

    private function priceItems(object $parent): array
    {
        $rows=$this->fetchAll('SELECT ri.*,p.status product_status,p.is_public,p.purchase_mode,p.allow_recurring_purchase,p.price,p.promo_price,v.price variation_price,v.promo_price variation_promo,fp.included_servings,fp.maximum_servings,fp.additional_person_price FROM store_recurring_order_items ri INNER JOIN store_products p ON p.id=ri.id_product LEFT JOIN store_product_variations v ON v.id=ri.id_product_variation LEFT JOIN store_product_food_profiles fp ON fp.id_product=p.id AND fp.id_owner=:owner AND fp.site_key=:site WHERE ri.recurring_order_id=:parent',[':owner'=>(int)$parent->id_owner,':site'=>(string)$parent->site_key,':parent'=>(int)$parent->id]);
        if(!$rows) throw new \DomainException('Recurring order has no products.'); $out=[];
        foreach($rows as $row){
            if($row->product_status!=='ACTIVE'||!(int)$row->is_public||$row->purchase_mode!=='DIRECT'||!(int)$row->allow_recurring_purchase) throw new \DomainException($row->product_name_snapshot.' is not available for recurring purchase.');
            $base=$row->id_product_variation?(float)($row->variation_promo??$row->variation_price):(float)($row->promo_price??$row->price);
            $servings=(int)($row->servings??0); if($row->maximum_servings!==null&&$servings>(int)$row->maximum_servings) throw new \DomainException('Requested servings exceed the current product limit.');
            $base+=max(0,$servings-(int)($row->included_servings??$servings))*(float)($row->additional_person_price??0);
            $qty=max(1,(int)$row->quantity); $out[]=['source'=>$row,'unit_price'=>round($base,2),'quantity'=>$qty,'line_total'=>round($base*$qty,2)];
        }
        return $out;
    }

    private function calculateDelivery(object $parent,array $address,string $requestedAt): array
    {
        return (new DeliveryPricingService())->quoteDelivery((int)$parent->id_owner,(string)$parent->site_key,[
            'address_1'=>(string)($address['address_1']??$address['address']??''),'address_2'=>(string)($address['address_2']??''),
            'city'=>(string)($address['city']??''),'state'=>(string)($address['state']??''),'zip'=>(string)($address['zip']??$address['postal_code']??''),'country'=>(string)($address['country']??'US')
        ],$requestedAt,'RECURRING');
    }

    private function createPaidOrder(object $parent,object $occurrence,array $items,array $address,array $delivery,float $subtotal,float $tax,float $total,object $charge): int
    {
        $user=$parent->id_user?$this->fetchOne('SELECT * FROM users WHERE id=:id',[':id'=>(int)$parent->id_user]):null;
        $this->db->beginTransaction();
        try{
            $customerName=trim((string)($user->name??'').' '.(string)($user->lastname??''))?:'VNV Gourmet customer';
            $this->execute("INSERT INTO store_orders (id_owner,site_key,id_user,recurring_order_id,recurring_occurrence_id,public_token,guest_name,guest_email,guest_phone,city,pricing_mode,fulfillment_method,delivery_timing,requested_delivery_at,requested_delivery_at_utc,requested_delivery_timezone,promised_delivery_at,items_count,meals_count,subtotal,discount,delivery_provider_cost,delivery_fee,delivery_margin,delivery_quote_id,delivery_pricing_source,delivery_distance_miles,delivery_markup_percent,delivery_reference_quoted_at,tax_total,total,payment_status,status,shipping_address_1,shipping_address_2,shipping_city,shipping_state,shipping_zip,shipping_country,shipping_instructions,notes,created_at,updated_at) VALUES (:owner,:site,:user,:recurrence,:occurrence,:token,:name,:email,:phone,:city,'SUBSCRIPTION','DELIVERY','SCHEDULED',:delivery_local,:delivery_utc,:timezone,:promised,:items_count,:meals_count,:subtotal,0,:provider_cost,:delivery_fee,:margin,:quote_id,:pricing_source,:distance,:markup,:quoted_at,:tax,:total,'PAID','PROCESSING',:address1,:address2,:shipping_city,:state,:zip,:country,:instructions,:notes,UTC_TIMESTAMP(),UTC_TIMESTAMP())",[
                ':owner'=>(int)$parent->id_owner,':site'=>(string)$parent->site_key,':user'=>$parent->id_user,':recurrence'=>(int)$parent->id,':occurrence'=>(int)$occurrence->id,':token'=>bin2hex(random_bytes(32)),':name'=>$customerName,':email'=>(string)($user->email??''),':phone'=>(string)($user->phone??''),':city'=>(string)($address['city']??''),':delivery_local'=>(string)$occurrence->local_scheduled_at,':delivery_utc'=>(string)$occurrence->scheduled_at_utc,':timezone'=>(string)$occurrence->timezone,':promised'=>(string)$occurrence->local_scheduled_at,':items_count'=>count($items),':meals_count'=>array_sum(array_column($items,'quantity')),':subtotal'=>$subtotal,':provider_cost'=>$delivery['provider_cost'],':delivery_fee'=>$delivery['customer_fee'],':margin'=>$delivery['delivery_margin'],':quote_id'=>$delivery['quote_id']??null,':pricing_source'=>$delivery['pricing_source']??null,':distance'=>$delivery['distance_miles']??null,':markup'=>$delivery['markup_percent']??null,':quoted_at'=>$delivery['queried_at']??null,':tax'=>$tax,':total'=>$total,':address1'=>(string)($address['address_1']??$address['address']??''),':address2'=>$address['address_2']??null,':shipping_city'=>$address['city']??null,':state'=>$address['state']??null,':zip'=>$address['zip']??$address['postal_code']??null,':country'=>$address['country']??'US',':instructions'=>$parent->delivery_instructions,':notes'=>'Generated from VNV Gourmet recurrence #'.$parent->id
            ]);
            $orderId=(int)$this->db->lastId(); if(!$orderId) throw new \RuntimeException('Recurring store order could not be created.');
            if(!empty($delivery['quote_id']))$this->execute('UPDATE store_delivery_quotes SET id_store_order=:order WHERE id=:quote',[':order'=>$orderId,':quote'=>(int)$delivery['quote_id']]);
            foreach($items as $priced){$row=$priced['source'];$this->execute("INSERT INTO store_order_items (id_owner,site_key,id_store_order,id_product,id_product_variation,product_name_snapshot,variation_name_snapshot,configuration_snapshot,unit_price,pricing_mode,quantity,servings,line_total,created_at) VALUES (:owner,:site,:order_id,:product,:variation,:name,:variation_name,:configuration,:unit_price,'SUBSCRIPTION',:quantity,:servings,:line_total,UTC_TIMESTAMP())",[':owner'=>(int)$parent->id_owner,':site'=>(string)$parent->site_key,':order_id'=>$orderId,':product'=>(int)$row->id_product,':variation'=>$row->id_product_variation,':name'=>$row->product_name_snapshot,':variation_name'=>$row->variation_name_snapshot,':configuration'=>$row->configuration_json,':unit_price'=>$priced['unit_price'],':quantity'=>$priced['quantity'],':servings'=>$row->servings,':line_total'=>$priced['line_total']]);}
            $this->execute("INSERT INTO store_payments (id_owner,site_key,id_store_order,id_user,payment_method,payment_type,external_payment_id,external_reference,amount,currency,status,payer_name,payer_email,raw_response,paid_at,created_at) VALUES (:owner,:site,:order_id,:user,'stripe','FULL',:payment_id,:reference,:amount,:currency,'PAID',:name,:email,:raw,UTC_TIMESTAMP(),UTC_TIMESTAMP())",[':owner'=>(int)$parent->id_owner,':site'=>(string)$parent->site_key,':order_id'=>$orderId,':user'=>$parent->id_user,':payment_id'=>$charge->id,':reference'=>$charge->payment_method,':amount'=>$total,':currency'=>$parent->currency,':name'=>$customerName,':email'=>$user->email??null,':raw'=>json_encode($charge->raw)]);
            $this->execute("UPDATE store_recurring_occurrences SET id_store_order=:order_id,status='CONFIRMED',payment_status='PAID',actual_subtotal=:subtotal,actual_delivery_provider_cost=:provider_cost,actual_delivery_fee=:delivery_fee,actual_delivery_margin=:margin,actual_tax=:tax,actual_total=:total,amount_charged=:charged,charged_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id",[':order_id'=>$orderId,':subtotal'=>$subtotal,':provider_cost'=>$delivery['provider_cost'],':delivery_fee'=>$delivery['customer_fee'],':margin'=>$delivery['delivery_margin'],':tax'=>$tax,':total'=>$total,':charged'=>$total,':id'=>(int)$occurrence->id]);
            $this->execute('INSERT INTO store_order_workflow (id_owner,id_store_order,created_at,updated_at) VALUES (:owner,:order_id,UTC_TIMESTAMP(),UTC_TIMESTAMP())',[':owner'=>(int)$parent->id_owner,':order_id'=>$orderId]);
            $this->db->commit();
            $this->notifyPaymentConfirmed($parent, $occurrence, $user, $orderId, $total);
            return $orderId;
        }catch(\Throwable $e){$this->db->rollback();throw $e;}
    }

    private function markFailed(int $id,string $message): void
    {
        $message=mb_substr($message,0,2000);
        $this->execute("UPDATE store_recurring_occurrences o INNER JOIN store_recurring_orders r ON r.id=o.recurring_order_id SET o.status='PAYMENT_ACTION_REQUIRED',o.payment_status='FAILED',o.last_error=:error,o.updated_at=UTC_TIMESTAMP(),r.status='PAYMENT_ACTION_REQUIRED',r.updated_at=UTC_TIMESTAMP() WHERE o.id=:id",[':error'=>$message,':id'=>$id]);
        try {
            $context=$this->fetchOne('SELECT r.id_owner,r.id_user,o.local_scheduled_at FROM store_recurring_occurrences o INNER JOIN store_recurring_orders r ON r.id=o.recurring_order_id WHERE o.id=:id',[':id'=>$id]);
            $user=$context&&$context->id_user?$this->fetchOne('SELECT email,name FROM users WHERE id=:id',[':id'=>(int)$context->id_user]):null;
            if($context&&$user&&!empty($user->email)){
                EmailServiceFactory::sendWithOwnerProvider((int)$context->id_owner,(string)$user->email,'Payment action required for your VNV Gourmet delivery','<p>Hello '.htmlspecialchars((string)($user->name?:'there')).',</p><p>We could not complete the automatic payment for your VNV Gourmet To Go delivery scheduled for <strong>'.htmlspecialchars((string)$context->local_scheduled_at).'</strong>.</p><p>The delivery has <strong>not</strong> entered preparation. Please sign in to update your payment method and retry securely.</p>',true);
            }
        } catch (\Throwable $ignored) {
            error_log('Recurring payment failure notification could not be sent for occurrence '.$id.': '.$ignored->getMessage());
        }
    }

    private function notifyPaymentConfirmed(object $parent, object $occurrence, ?object $user, int $orderId, float $total): void
    {
        if(!$user||empty($user->email)) return;
        try {
            EmailServiceFactory::sendWithOwnerProvider((int)$parent->id_owner,(string)$user->email,'Your VNV Gourmet delivery is confirmed','<p>Hello '.htmlspecialchars((string)($user->name?:'there')).',</p><p>Your automatic payment of <strong>$'.number_format($total,2).'</strong> was approved.</p><p>Your delivery is scheduled for <strong>'.htmlspecialchars((string)$occurrence->local_scheduled_at).'</strong>. Order #'.$orderId.' is now entering the preparation workflow.</p>',true);
        } catch (\Throwable $ignored) {
            error_log('Recurring payment confirmation could not be sent for order '.$orderId.': '.$ignored->getMessage());
        }
    }

    private function fetchOne(string $sql,array $bindings): ?object{$this->db->query($sql);foreach($bindings as $k=>$v)$this->db->bind($k,$v,is_int($v)?\PDO::PARAM_INT:null);$row=$this->db->fetchOne();return $row?:null;}
    private function fetchAll(string $sql,array $bindings): array{$this->db->query($sql);foreach($bindings as $k=>$v)$this->db->bind($k,$v,is_int($v)?\PDO::PARAM_INT:null);return $this->db->fetchAll()?:[];}
    private function execute(string $sql,array $bindings): void{$this->db->query($sql);foreach($bindings as $k=>$v)$this->db->bind($k,$v,is_int($v)?\PDO::PARAM_INT:null);$this->db->execute();}
}
