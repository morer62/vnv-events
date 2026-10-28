<?php

namespace App\Services;

use App\Repositories\Connection;
use App\Repositories\InvoicesRepository;
use RuntimeException;

final class InvoiceService
{
    private Connection $db;
    private InvoicesRepository $invoices;

    public function __construct()
    {
        $this->db = new Connection();
        $this->invoices = new InvoicesRepository();
    }

    public function ensureForOrder(int $ownerId, int $orderId, ?int $actorId, bool $automatic = false): object
    {
        $existing = $this->invoices->findByOrder($ownerId, $orderId);
        if ($existing) return $this->syncStatus($existing);

        $order = $this->order($ownerId, $orderId);
        $company = $this->company($ownerId);
        $this->db->query('INSERT INTO invoices (id_owner,id_order,invoice_number,status,auto_generated,company_snapshot_json,billing_name,billing_email,billing_phone,billing_address,created_by) VALUES (:owner,:order_id,:number,:status,:automatic,:company,:name,:email,:phone,:address,:actor)');
        $this->db->bind(':owner', $ownerId);
        $this->db->bind(':order_id', $orderId);
        $this->db->bind(':number', 'INV-'.$orderId);
        $this->db->bind(':status', 'DRAFT');
        $this->db->bind(':automatic', $automatic ? 1 : 0);
        $this->db->bind(':company', json_encode($company, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
        $this->db->bind(':name', trim((string)$order->client_name));
        $this->db->bind(':email', (string)$order->client_email);
        $this->db->bind(':phone', (string)$order->client_phone);
        $this->db->bind(':address', (string)($order->billing_address ?: $order->address));
        $this->db->bind(':actor', $actorId);
        $this->db->execute();
        $invoice = $this->invoices->findByOrder($ownerId, $orderId);
        if (!$invoice) throw new RuntimeException('Invoice could not be created.');
        $this->invoices->audit((int)$invoice->id, $actorId, $automatic ? 'AUTO_CREATED' : 'MANUALLY_CREATED', ['order_id'=>$orderId]);
        return $this->syncStatus($invoice);
    }

    public function reconcilePaidOrders(int $ownerId): int
    {
        $this->db->query('SELECT o.id FROM orders o LEFT JOIN invoices i ON i.id_owner=o.id_owner AND i.id_order=o.id WHERE o.id_owner=:owner AND i.id IS NULL AND (EXISTS (SELECT 1 FROM orders_payments p WHERE p.id_order=o.id AND COALESCE(p.is_suborder,0)=0) OR EXISTS (SELECT 1 FROM orders_advances a WHERE a.id_order=o.id AND COALESCE(a.is_suborder,0)=0)) ORDER BY o.id DESC LIMIT 5000');
        $this->db->bind(':owner', $ownerId);
        $created = 0;
        foreach ($this->db->fetchAll() as $row) {
            if ($this->invoices->findByOrder($ownerId, (int)$row->id)) continue;
            $snapshot = $this->buildOrderSnapshot($ownerId, (int)$row->id, null);
            if ($snapshot['totals']['total'] > 0 && $snapshot['totals']['balance'] <= 0.01) {
                $this->ensureForOrder($ownerId, (int)$row->id, null, true);
                $created++;
            }
        }
        return $created;
    }

    public function ensureIfFullyPaid(int $ownerId, int $orderId, ?int $actorId = null): ?object
    {
        $snapshot=$this->buildOrderSnapshot($ownerId,$orderId,null);
        if($snapshot['totals']['total']<=0 || $snapshot['totals']['balance']>0.01)return null;
        return $this->ensureForOrder($ownerId,$orderId,$actorId,true);
    }

    public function refreshStatuses(int $ownerId): void
    {
        $this->db->query('SELECT * FROM invoices WHERE id_owner=:owner ORDER BY id DESC LIMIT 1000');$this->db->bind(':owner',$ownerId);
        foreach($this->db->fetchAll() as $invoice)$this->syncStatus($invoice);
    }

    public function updateEditable(int $ownerId, int $invoiceId, int $actorId, array $data): object
    {
        $invoice = $this->owned($ownerId, $invoiceId);
        $allowed = ['billing_name','billing_email','billing_phone','billing_address','invoice_notes','internal_reference','line_overrides_json'];
        $sets=[]; $values=[];
        foreach ($allowed as $field) if (array_key_exists($field,$data)) { $sets[]="$field=:$field"; $values[$field]=$data[$field]; }
        if (!$sets) return $invoice;
        $this->db->query('UPDATE invoices SET '.implode(',', $sets).' WHERE id=:id AND id_owner=:owner');
        foreach ($values as $key=>$value) $this->db->bind(':'.$key, $value);
        $this->db->bind(':id',$invoiceId); $this->db->bind(':owner',$ownerId); $this->db->execute();
        $this->invoices->audit($invoiceId,$actorId,'INVOICE_DETAILS_UPDATED',array_keys($values));
        return $this->owned($ownerId,$invoiceId);
    }

    public function void(int $ownerId, int $invoiceId, int $actorId, string $reason): object
    {
        $this->owned($ownerId,$invoiceId);
        $this->db->query("UPDATE invoices SET status='VOIDED',void_reason=:reason,voided_at=NOW(),voided_by=:actor WHERE id=:id AND id_owner=:owner");
        $this->db->bind(':reason',trim($reason));$this->db->bind(':actor',$actorId);$this->db->bind(':id',$invoiceId);$this->db->bind(':owner',$ownerId);$this->db->execute();
        $this->invoices->audit($invoiceId,$actorId,'VOIDED',['reason'=>trim($reason)]);
        return $this->owned($ownerId,$invoiceId);
    }

    public function snapshot(int $ownerId, int $invoiceId): array
    {
        $invoice = $this->syncStatus($this->owned($ownerId,$invoiceId));
        $data = $this->buildOrderSnapshot($ownerId,(int)$invoice->id_order,$invoice);
        $data['invoice'] = $invoice;
        $data['company'] = json_decode((string)$invoice->company_snapshot_json,true) ?: [];
        return $data;
    }

    public function issueVersion(int $ownerId, int $invoiceId, int $actorId): array
    {
        $invoice=$this->owned($ownerId,$invoiceId);
        if((string)$invoice->status==='DRAFT'){$this->db->query("UPDATE invoices SET status='UNPAID' WHERE id=:id");$this->db->bind(':id',$invoiceId);$this->db->execute();}
        $snapshot=$this->snapshot($ownerId,$invoiceId);
        $json=json_encode($this->serializable($snapshot),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        $hash=hash('sha256',$json);
        $this->db->query('SELECT * FROM invoice_versions WHERE invoice_id=:invoice AND snapshot_hash=:hash LIMIT 1');
        $this->db->bind(':invoice',$invoiceId);$this->db->bind(':hash',$hash);$existing=$this->db->fetchOne();
        if ($existing) return ['version'=>$existing,'snapshot'=>$snapshot];
        $this->db->query('SELECT COALESCE(MAX(version_number),0)+1 next_version FROM invoice_versions WHERE invoice_id=:invoice');
        $this->db->bind(':invoice',$invoiceId);$number=(int)$this->db->fetchOne()->next_version;
        $this->db->query('INSERT INTO invoice_versions (invoice_id,version_number,snapshot_hash,snapshot_json,generated_by) VALUES (:invoice,:version,:hash,:snapshot,:actor)');
        $this->db->bind(':invoice',$invoiceId);$this->db->bind(':version',$number);$this->db->bind(':hash',$hash);$this->db->bind(':snapshot',$json);$this->db->bind(':actor',$actorId);$this->db->execute();
        $this->invoices->audit($invoiceId,$actorId,'PDF_VERSION_GENERATED',['version'=>$number,'hash'=>$hash]);
        $this->db->query('SELECT * FROM invoice_versions WHERE invoice_id=:invoice AND version_number=:version');$this->db->bind(':invoice',$invoiceId);$this->db->bind(':version',$number);
        return ['version'=>$this->db->fetchOne(),'snapshot'=>$snapshot];
    }

    public function versions(int $invoiceId): array
    {
        $this->db->query('SELECT * FROM invoice_versions WHERE invoice_id=:invoice ORDER BY version_number DESC');$this->db->bind(':invoice',$invoiceId);return $this->db->fetchAll();
    }

    private function syncStatus(object $invoice): object
    {
        if ((string)$invoice->status === 'VOIDED') return $invoice;
        $snapshot=$this->buildOrderSnapshot((int)$invoice->id_owner,(int)$invoice->id_order,$invoice);
        $t=$snapshot['totals'];
        $status='UNPAID';
        if ($t['gross_paid'] > 0 && $t['refunded'] >= $t['gross_paid']-0.01) $status='REFUNDED';
        elseif ($t['balance'] <= 0.01 && $t['net_paid'] > 0) $status='PAID';
        elseif ($t['net_paid'] > 0) $status='PARTIALLY_PAID';
        elseif ((string)$invoice->status === 'DRAFT') $status='DRAFT';
        if ($status !== (string)$invoice->status) {
            $this->db->query('UPDATE invoices SET status=:status WHERE id=:id');$this->db->bind(':status',$status);$this->db->bind(':id',(int)$invoice->id);$this->db->execute();$invoice->status=$status;
        }
        return $invoice;
    }

    private function buildOrderSnapshot(int $ownerId,int $orderId,?object $invoice): array
    {
        $order=$this->order($ownerId,$orderId);
        $this->db->query('SELECT osa.id,osa.quantity,osa.unit_price,osa.variable_price,osa.is_variable,osa.description assigned_description,os.name,os.description service_description,os.price FROM orders_services_assigned osa LEFT JOIN orders_services os ON os.id=osa.id_service WHERE osa.id_order=:id ORDER BY osa.id');
        $this->db->bind(':id',$orderId);$lines=$this->db->fetchAll();
        $overrides=$invoice ? (json_decode((string)($invoice->line_overrides_json??''),true)?:[]) : [];
        $subtotal=0.0;
        foreach($lines as $line){$unit=(float)($line->unit_price>0?$line->unit_price:($line->is_variable==='YES'?$line->variable_price:$line->price));$line->unit_price_effective=$unit;$line->line_total=$unit*(float)$line->quantity;$line->invoice_key='order:'.$line->id;$line->invoice_group='Order #'.$orderId;$line->invoice_description=$overrides[$line->invoice_key]??$overrides[(string)$line->id]??($line->assigned_description?:$line->service_description);$subtotal+=$line->line_total;}
        $calculated=OrderCalculatorService::calculateTotal($order);$discountType=(string)($order->discount_type?:'amount');$discountValue=(float)($order->discount_value??0);$subtotal=(float)$calculated['subtotal'];$discount=(float)$calculated['discount'];$tax=(float)$calculated['tax'];$total=(float)$calculated['total'];
        $this->db->query('SELECT * FROM orders_suborder WHERE id_order=:order_id AND COALESCE(is_archived,0)=0 ORDER BY id');
        $this->db->bind(':order_id',$orderId);$suborders=$this->db->fetchAll();
        foreach($suborders as $suborder){
            $this->db->query('SELECT ssa.id,ssa.quantity,ssa.unit_price,ssa.variable_price,ssa.is_variable,ssa.description assigned_description,os.name,os.description service_description,os.price FROM order_suborder_services_assigned ssa LEFT JOIN orders_services os ON os.id=ssa.id_service WHERE ssa.id_suborder=:id ORDER BY ssa.id');
            $this->db->bind(':id',(int)$suborder->id);$subLines=$this->db->fetchAll();$subSubtotal=0.0;
            foreach($subLines as $line){$unit=(float)($line->unit_price>0?$line->unit_price:($line->is_variable==='YES'?$line->variable_price:$line->price));$line->unit_price_effective=$unit;$line->line_total=$unit*(float)$line->quantity;$line->invoice_key='suborder:'.$suborder->id.':'.$line->id;$line->invoice_group='Suborder #'.$suborder->id;$line->invoice_description=$overrides[$line->invoice_key]??($line->assigned_description?:$line->service_description);$subSubtotal+=$line->line_total;$lines[]=$line;}
            $subDiscountType=strtolower((string)($suborder->discount_type?:'amount'));$subDiscountValue=(float)($suborder->discount_value??0);$subDiscount=in_array($subDiscountType,['percent','percentage'],true)?$subSubtotal*$subDiscountValue/100:$subDiscountValue;$subDiscount=min($subSubtotal,max(0,$subDiscount));$subBase=max(0,$subSubtotal-$subDiscount);$subTax=$subBase*((float)($suborder->tax_percertance??0)/100);
            $subtotal+=$subSubtotal;$discount+=$subDiscount;$tax+=$subTax;$total+=$subBase+$subTax;
        }
        $payments=$this->payments($orderId);$gross=0;$refund=0;$net=0;foreach($payments as $p){$gross+=(float)$p->amount;$refund+=(float)$p->refunded_amount;$net+=(float)$p->net_amount;}
        return ['order'=>$order,'suborders'=>$suborders,'lines'=>$lines,'payments'=>$payments,'totals'=>['subtotal'=>$subtotal,'discount'=>$discount,'discount_type'=>$discountType,'discount_value'=>$discountValue,'tax_rate'=>count($suborders)?null:(float)$order->tax_percentage,'tax'=>$tax,'total'=>$total,'gross_paid'=>$gross,'refunded'=>$refund,'net_paid'=>$net,'balance'=>max(0,$total-$net)]];
    }

    private function payments(int $orderId): array
    {
        $this->db->query("SELECT 'PAYMENT' entry_type,p.id,p.amount,COALESCE(p.refunded_amount,0) refunded_amount,GREATEST(p.amount-COALESCE(p.refunded_amount,0),0) net_amount,COALESCE(p.provider_type,p.method,'Payment') method,COALESCE(p.stripe_charge_id,'') reference_id,p.card_brand,p.card_last4,COALESCE(p.paid_at,p.created_at) paid_at,p.payment_concept note,CASE WHEN COALESCE(p.is_suborder,0)=1 THEN CONCAT('Suborder #',p.id_suborder) ELSE CONCAT('Order #',p.id_order) END source_label FROM orders_payments p LEFT JOIN orders_suborder s ON s.id=p.id_suborder WHERE (COALESCE(p.is_suborder,0)=0 AND p.id_order=:id) OR (COALESCE(p.is_suborder,0)=1 AND s.id_order=:id_sub AND COALESCE(s.is_archived,0)=0) UNION ALL SELECT 'ADVANCE',a.id,a.amount,COALESCE(a.refunded_amount,0),GREATEST(a.amount-COALESCE(a.refunded_amount,0),0),CASE WHEN a.stripe_charge_id IS NULL OR a.stripe_charge_id='' THEN 'Manual advance' ELSE 'Provider advance' END,COALESCE(a.stripe_charge_id,''),NULL,NULL,a.created_at,a.note,CASE WHEN COALESCE(a.is_suborder,0)=1 THEN CONCAT('Suborder #',a.id_suborder) ELSE CONCAT('Order #',a.id_order) END FROM orders_advances a LEFT JOIN orders_suborder s2 ON s2.id=a.id_suborder WHERE (COALESCE(a.is_suborder,0)=0 AND a.id_order=:id2) OR (COALESCE(a.is_suborder,0)=1 AND s2.id_order=:id2_sub AND COALESCE(s2.is_archived,0)=0) ORDER BY paid_at");
        $this->db->bind(':id',$orderId);$this->db->bind(':id_sub',$orderId);$this->db->bind(':id2',$orderId);$this->db->bind(':id2_sub',$orderId);return $this->db->fetchAll();
    }

    private function order(int $ownerId,int $orderId): object
    {
        $this->db->query("SELECT o.*,CONCAT_WS(' ',u.name,u.lastname) client_name,u.email client_email,u.phone client_phone,(SELECT billing_address FROM orders_payments p WHERE p.id_order=o.id AND p.billing_address IS NOT NULL AND p.billing_address<>'' ORDER BY p.id DESC LIMIT 1) billing_address FROM orders o LEFT JOIN users u ON u.id=o.id_client WHERE o.id=:id AND o.id_owner=:owner LIMIT 1");
        $this->db->bind(':id',$orderId);$this->db->bind(':owner',$ownerId);$row=$this->db->fetchOne();if(!$row)throw new RuntimeException('Order not found or access denied.');return $row;
    }

    private function company(int $ownerId): array
    {
        $this->db->query('SELECT company_name,phone,email,address_line1,city,state,zip,country,logo_path FROM institution_profile WHERE id_owner=:owner LIMIT 1');$this->db->bind(':owner',$ownerId);$row=$this->db->fetchOne();return $row?(array)$row:['company_name'=>'VNV Events'];
    }
    private function owned(int $ownerId,int $invoiceId): object { $row=$this->invoices->findOwned($ownerId,$invoiceId);if(!$row)throw new RuntimeException('Invoice not found or access denied.');return $row; }
    private function serializable(mixed $value): mixed { return json_decode(json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),true); }
}
