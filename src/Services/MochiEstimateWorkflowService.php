<?php
namespace App\Services;

use App\Repositories\Connection;
use App\Repositories\OrdersRepository;
use App\Repositories\OrdersServiceRepository;
use App\Repositories\OrdersServicesAssignedRepository;
use App\Utils\LocationUtils;
use RuntimeException;

final class MochiEstimateWorkflowService
{
    private const CREATE_RE='/\b(create|crear|crea|nuevo|new)\b.*\b(estimate|estimado|cotizaci[oó]n)\b|\b(estimate|estimado|cotizaci[oó]n)\b.*\b(create|crear|crea|nuevo|new)\b/iu';
    private const MODIFY_RE='/\b(modify|edit|update|modificar|editar|cambiar|actualizar)\b.*\b(estimate|estimado|cotizaci[oó]n)\b|\b(estimate|estimado|cotizaci[oó]n)\b.*\b(modify|edit|update|modificar|editar|cambiar|actualizar)\b/iu';
    private const APPROVE_RE='/^(s[ií]|yes|correcto|correct|looks good|se ve bien|créalo|crealo|create it|hazlo|aprobado|approve)[.!\s]*$/iu';

    public function __construct(private int $ownerId=2,private string $siteKey='vnvevents',private ?Connection $db=null){$this->db??=new Connection();}

    public function active(int $userId,int $sessionId): ?object
    {
        $this->db->query("SELECT * FROM mochi_estimate_workflows WHERE id_owner=:owner AND site_key=:site AND id_user=:user AND id_session=:session AND status NOT IN ('CANCELLED','COMPLETED') LIMIT 1");
        $this->bind(['owner'=>$this->ownerId,'site'=>$this->siteKey,'user'=>$userId,'session'=>$sessionId]);
        return $this->db->fetchOne() ?: null;
    }

    public function shouldHandle(int $userId,int $sessionId,string $message): bool
    {
        return (bool)($this->active($userId,$sessionId)||preg_match(self::CREATE_RE,$message)||preg_match(self::MODIFY_RE,$message));
    }

    public function handle(int $userId,int $level,int $sessionId,string $message): array
    {
        if($level!==1)throw new RuntimeException('El workflow de estimates de Mochi está disponible únicamente para Level 1.');
        $workflow=$this->active($userId,$sessionId);
        if(preg_match('/\b(cancel|cancelar|start over|empezar de nuevo|reiniciar)\b/iu',$message)){
            if($workflow)$this->setStatus((int)$workflow->id,'CANCELLED');
            return $this->result('Cancelé el borrador. No se creó ni modificó ningún estimate.',null,[],'idle');
        }
        if(!$workflow){
            $mode=preg_match(self::MODIFY_RE,$message)?'MODIFY':'CREATE';
            $workflow=$this->create($userId,$sessionId,$mode);
            $reply=$mode==='CREATE'
                ? "Claro 😊 Envíame todo lo que tengas: texto, conversación, email, notas dictadas o una captura. Yo organizo la información, reviso el CRM, duplicados y la fecha antes de crear nada."
                : "Claro. Envíame el número del estimate, nombre, email o teléfono del cliente y dime qué deseas cambiar.";
            if($this->isStarterOnly($message,$mode))return $this->result($reply,$workflow,[],$mode==='CREATE'?'creating':'editing');
        }
        $draft=$this->decode($workflow->draft_json);
        if($workflow->mode==='CREATE'&&preg_match('/\b(request|solicitud)\b/iu',$message)){
            $requestDraft=$this->requestDraft($message);
            if($requestDraft)$draft=array_replace($draft,$requestDraft);
        }
        if((int)($workflow->current_estimate_id??0)>0){
            if(preg_match('/\b(send it|send|enviar|env[ií]alo|m[aá]ndalo)\b/iu',$message))return $this->sendEstimate($workflow,$draft,$userId);
            if(preg_match('/\b(create another|crear otro|otro estimate|otro estimado)\b/iu',$message)){
                $this->reset((int)$workflow->id,'CREATE');
                return $this->result('Perfecto. Envíame todo lo que tengas para el próximo estimate.',$this->active($userId,$sessionId),[],'creating');
            }
            if($workflow->status==='ACTIVE'&&preg_match(self::APPROVE_RE,trim($message))){
                $id=(int)$workflow->current_estimate_id;$order=$this->ownedOrder($id);$total=(float)OrderCalculatorService::calculateTotal($order)['total'];$public=$this->publicUrl($id,(int)$order->id_client);
                return $this->result("Perfecto 😊 Estimate #{$id} está listo. ¿Quieres crear otro estimate?",$workflow,$this->postCreationItems($id,$public,$total,(string)($draft['customer_name']??'')),'success',['copy_url'=>$public]);
            }
        }
        if($workflow->status==='REVIEW'&&preg_match('/\b(crear separado|crearlo separado|create separate|create it separately)\b/iu',$message)){
            $draft['force_separate']=true;
            $this->saveDraft((int)$workflow->id,$draft,'REVIEW');
            return $this->createEstimate($this->reload((int)$workflow->id),$draft,$userId);
        }
        if($workflow->mode==='MODIFY'&&!$workflow->current_estimate_id){
            $matches=$this->findEstimates($message);
            if(!$matches)return $this->result('No encontré un estimate activo con esos datos. Prueba con el número, email, teléfono o nombre completo.',$workflow,[],'editing');
            if(count($matches)>1)return $this->result('Encontré varios estimates. Selecciona uno o escríbeme su número.',$workflow,$this->estimateItems($matches),'editing');
            $workflow=$this->selectEstimate($workflow,$matches[0]);$draft=$this->decode($workflow->draft_json);
            return $this->result('Encontré Estimate #'.$matches[0]->id.'. ¿Qué deseas cambiar?',$workflow,$this->estimateItems($matches),'editing');
        }
        if(preg_match(self::APPROVE_RE,trim($message))&&$workflow->status==='REVIEW')return $this->createEstimate($workflow,$draft,$userId);
        $parsed=$this->parse($draft,$message);
        $removeServices=array_values(array_filter(array_map('trim',(array)($parsed['remove_services']??[]))));
        $draft=array_replace_recursive($draft,$parsed);
        $draft=$this->normalize($draft);
        if($removeServices)$draft['services_to_remove']=$removeServices;
        if($workflow->current_estimate_id){
            $draft['current_estimate_id']=(int)$workflow->current_estimate_id;
            $draft['services']=$this->applyQuantities($this->resolveServices((array)($draft['requested_services']??[])),(int)($draft['guest_count']??0));$draft['unresolved_services']=$this->unresolvedServices((array)($draft['requested_services']??[]),$draft['services']);
            $this->saveDraft((int)$workflow->id,$draft,'REVIEW');
            $summary=$this->summary($draft,true);
            return $this->result($summary."\n\n¿Aplico estos cambios al estimate?",$this->reload((int)$workflow->id),[],'editing');
        }
        $draft=$this->enrich($draft);
        $missing=$this->missing($draft);
        $status=$missing?'COLLECTING':'REVIEW';
        $this->saveDraft((int)$workflow->id,$draft,$status);
        $reply=$this->summary($draft,false);
        if($missing)$reply.="\n\nPara continuar necesito: ".implode(', ',$missing).'.';
        else $reply.="\n\n¿Está correcto? Cuando me digas “sí”, crearé el estimate con los precios actuales.";
        return $this->result($reply,$this->reload((int)$workflow->id),$draft['matches']??[],'creating');
    }

    private function parse(array $current,string $message): array
    {
        try{return (new AiJsonGenerator())->generate(
            'Update a VNV Events estimate draft from the latest Spanish or English user message. Preserve existing values unless explicitly corrected. Explicit user clarification overrides image extraction. Never invent unreadable or missing facts. When the user confirms or corrects uncertain data, remove that field from uncertain_fields. For a CRM contact conflict set contact_resolution only when the user explicitly chooses use_crm or update_crm. Dates YYYY-MM-DD, times HH:MM:SS. Customer-facing service/product names and estimate content remain English. Return JSON only.',
            ['current_draft'=>$current,'latest_message'=>$message,'today'=>(new \DateTimeImmutable('now',new \DateTimeZone('America/New_York')))->format('Y-m-d')],
            ['customer_name'=>'string or null','email'=>'string or null','phone'=>'string or null','event_date'=>'YYYY-MM-DD or null','start_time'=>'HH:MM:SS or null','end_time'=>'HH:MM:SS or null','venue'=>'string or null','address'=>'string or null','city'=>'string or null','guest_count'=>'integer or null','event_type'=>'English string or null','requested_services'=>['English service names explicitly requested'],'notes'=>'English customer-facing notes or null','remove_services'=>['English service names explicitly removed'],'uncertain_fields'=>['field names that remain uncertain'],'contact_resolution'=>'use_crm, update_crm, or null','estimate_identifier'=>'number/name/email/phone when supplied or null']
        );}catch(\Throwable){return ['notes'=>$message];}
    }

    private function normalize(array $draft): array
    {
        foreach(['customer_name','email','phone','event_date','start_time','end_time','venue','address','city','event_type','notes'] as $key){if(isset($draft[$key])&&is_string($draft[$key]))$draft[$key]=trim($draft[$key]);}
        $draft['phone']=$this->phone((string)($draft['phone']??''));
        $services=array_values(array_unique(array_filter(array_map('trim',(array)($draft['requested_services']??[])))));
        $removed=array_map('mb_strtolower',(array)($draft['remove_services']??[]));
        $draft['requested_services']=array_values(array_filter($services,fn($name)=>!in_array(mb_strtolower($name),$removed,true)));
        unset($draft['remove_services']);return $draft;
    }

    private function enrich(array $draft): array
    {
        $customers=$this->findCustomers($draft);$draft['customer_matches']=array_map(fn($c)=>(int)$c->id,$customers);
        if(count($customers)===1){$c=$customers[0];$draft['customer_id']=(int)$c->id;$draft['customer_name']=$draft['customer_name']?:trim($c->name.' '.$c->lastname);$crmEmail=trim((string)$c->email);$crmPhone=$this->phone((string)$c->phone);$incomingEmail=trim((string)($draft['email']??''));$incomingPhone=$this->phone((string)($draft['phone']??''));$conflicts=[];if($incomingEmail!==''&&$crmEmail!==''&&strcasecmp($incomingEmail,$crmEmail)!==0)$conflicts['email']=['incoming'=>$incomingEmail,'crm'=>$crmEmail];if($incomingPhone!==''&&$crmPhone!==''&&$incomingPhone!==$crmPhone)$conflicts['phone']=['incoming'=>$incomingPhone,'crm'=>$crmPhone];if(($draft['contact_resolution']??null)==='use_crm'){$draft['email']=$crmEmail;$draft['phone']=$crmPhone;$conflicts=[];}elseif(($draft['contact_resolution']??null)==='update_crm'){$draft['update_customer_contact']=true;$conflicts=[];}else{$draft['crm_conflicts']=$conflicts;}$draft['email']=$draft['email']?:$crmEmail;$draft['phone']=$draft['phone']?:$crmPhone;}
        $draft['services']=$this->applyQuantities($this->resolveServices((array)($draft['requested_services']??[])),(int)($draft['guest_count']??0));$draft['unresolved_services']=$this->unresolvedServices((array)($draft['requested_services']??[]),$draft['services']);
        $draft['possible_duplicates']=$this->duplicates($draft);
        $draft['conflicts']=$this->conflicts($draft);
        return $draft;
    }

    private function createEstimate(object $workflow,array $draft,int $actor): array
    {
        if($workflow->current_estimate_id)return $this->applyChanges($workflow,$draft,$actor);
        $draft=$this->enrich($draft);$missing=$this->missing($draft);if($missing)throw new RuntimeException('Todavía faltan datos para crear el estimate: '.implode(', ',$missing).'.');
        if(!empty($draft['possible_duplicates'])&&empty($draft['force_separate']))return $this->result('Encontré un estimate posiblemente duplicado. Selecciona el existente o dime explícitamente “crear separado”.',$workflow,$draft['possible_duplicates'],'creating');
        $customerId=(int)($draft['customer_id']??0);
        if(!$customerId){
            $user=StoreCustomerService::findOrCreateLevel5User($this->ownerId,(string)$draft['customer_name'],(string)$draft['email'],(string)($draft['phone']??''));
            if(!$user)throw new RuntimeException('No pude crear el cliente. Conservé el borrador para reintentar.');$customerId=(int)$user->id;$draft['customer_id']=$customerId;
        }
        if(!empty($draft['update_customer_contact']))$this->syncCustomerContact($customerId,$draft,$actor);
        $notes=$this->englishNotes($draft);
        $orderId=(new OrdersRepository())->addWithExplicitOwner(['id_owner'=>$this->ownerId,'id_user'=>$actor,'id_client'=>$customerId,'event_date'=>$draft['event_date'],'address'=>$this->address($draft),'start_time'=>$draft['start_time'],'end_time'=>$draft['end_time'],'setup_minutes'=>60,'payment_status'=>'pending','status_workflow'=>'INVOICE_DRAFT','notes'=>$notes,'discount_type'=>'amount','discount_value'=>0,'tax_percentage'=>0,'payment_split_type'=>2,'payment_split_percent_1'=>50,'payment_split_percent_2'=>50,'total_team_needed'=>0,'is_archived'=>0]);
        if(!$orderId)throw new RuntimeException('No pude crear el estimate. Conservé el borrador para reintentar.');
        $assigned=new OrdersServicesAssignedRepository();foreach((array)$draft['services'] as $service){$qty=max(1,(int)($service['quantity']??1));$price=(float)$service['price'];$assigned->add(['id_order'=>$orderId,'id_service'=>(int)$service['id'],'quantity'=>$qty,'unit_price'=>$price,'description'=>$service['description']??null,'subtotal'=>$qty*$price,'id_owner'=>$this->ownerId,'is_variable'=>'NO','variable_price'=>null]);}
        $order=(object)(new OrdersRepository())->getByIdWithoutOwnershipCheck($orderId);$totals=OrderCalculatorService::calculateTotal($order);$public=$this->publicUrl($orderId,$customerId);
        $draft['current_estimate_id']=$orderId;$draft['total']=$totals['total'];$draft['public_url']=$public;
        if(!empty($draft['source_request_id'])){$this->db->query("UPDATE event_requests SET id_user=:customer,status='CONVERTED',updated_at=NOW() WHERE id=:request AND id_owner=:owner");$this->bind(['customer'=>$customerId,'request'=>(int)$draft['source_request_id'],'owner'=>$this->ownerId]);$this->db->execute();}
        $this->saveDraft((int)$workflow->id,$draft,'ACTIVE',$orderId);$this->setMode((int)$workflow->id,'MODIFY');$this->audit($actor,'CREATE_ESTIMATE',$orderId,['draft'=>$draft,'totals'=>$totals]);
        $items=$this->postCreationItems($orderId,$public,(float)$totals['total'],(string)$draft['customer_name']);
        return $this->result("Perfecto 😊 Estimate #{$orderId} está creado para {$draft['customer_name']}.\nTotal: $".number_format($totals['total'],2)."\n\nAquí puedes ver exactamente lo que verá el cliente. ¿Quieres modificar algo o está bien así?",$this->reload((int)$workflow->id),$items,'editing',['copy_url'=>$public]);
    }

    private function applyChanges(object $workflow,array $draft,int $actor): array
    {
        $id=(int)$workflow->current_estimate_id;$order=$this->ownedOrder($id);$before=OrderCalculatorService::calculateTotal($order);$beforeServices=$this->assignedServices($id);$sets=[];$params=['id'=>$id,'owner'=>$this->ownerId];foreach(['event_date','start_time','end_time','address','notes'] as $field)if(!empty($draft[$field])){$sets[]="$field=:$field";$params[$field]=$field==='address'?$this->address($draft):$draft[$field];}
        if($sets){$this->db->query('UPDATE orders SET '.implode(',',$sets).' WHERE id=:id AND id_owner=:owner');$this->bind($params);$this->db->execute();}
        if(!empty($draft['services_to_remove'])){foreach($this->resolveAssignedServices($id,$draft['services_to_remove']) as $service){$this->db->query('DELETE FROM orders_services_assigned WHERE id_order=:order AND id_service=:service AND id_owner=:owner');$this->bind(['order'=>$id,'service'=>$service['id'],'owner'=>$this->ownerId]);$this->db->execute();}}
        if(!empty($draft['requested_services'])){foreach($this->applyQuantities($this->resolveServices($draft['requested_services']),(int)($draft['guest_count']??0)) as $service){$qty=max(1,(int)$service['quantity']);$this->db->query('SELECT id FROM orders_services_assigned WHERE id_order=:order AND id_service=:service LIMIT 1');$this->bind(['order'=>$id,'service'=>$service['id']]);if($existing=$this->db->fetchOne()){$this->db->query('UPDATE orders_services_assigned SET quantity=:qty,unit_price=:price,description=:description,subtotal=:subtotal WHERE id=:id AND id_owner=:owner');$this->bind(['qty'=>$qty,'price'=>$service['price'],'description'=>$service['description'],'subtotal'=>$qty*$service['price'],'id'=>(int)$existing->id,'owner'=>$this->ownerId]);$this->db->execute();}else{(new OrdersServicesAssignedRepository())->add(['id_order'=>$id,'id_service'=>$service['id'],'quantity'=>$qty,'unit_price'=>$service['price'],'description'=>$service['description'],'subtotal'=>$qty*$service['price'],'id_owner'=>$this->ownerId,'is_variable'=>'NO','variable_price'=>null]);}}}
        $updatedOrder=$this->ownedOrder($id);$after=OrderCalculatorService::calculateTotal($updatedOrder);$afterServices=$this->assignedServices($id);$changes=[];foreach(['event_date'=>'Fecha','start_time'=>'Inicio','end_time'=>'Final','address'=>'Ubicación'] as $field=>$label){$old=(string)($order->$field??'');$new=(string)($updatedOrder->$field??'');if($old!==$new)$changes[]=$label.': '.$old.' → '.$new;}$oldNames=implode(', ',array_column($beforeServices,'name'));$newNames=implode(', ',array_column($afterServices,'name'));if($oldNames!==$newNames)$changes[]='Servicios: '.$oldNames.' → '.$newNames;if((float)$before['total']!==(float)$after['total'])$changes[]='Total: $'.number_format($before['total'],2).' → $'.number_format($after['total'],2);$draft['total']=$after['total'];$draft['public_url']=$this->publicUrl($id,(int)$order->id_client);unset($draft['services_to_remove']);$this->saveDraft((int)$workflow->id,$draft,'ACTIVE',$id);$this->audit($actor,'UPDATE_ESTIMATE',$id,['before_total'=>$before['total'],'after_total'=>$after['total'],'changes'=>$changes]);
        $items=$this->postCreationItems($id,(string)$draft['public_url'],(float)$after['total'],(string)($draft['customer_name']??''));
        return $this->result("Actualicé Estimate #{$id}.\n".($changes?implode("\n",$changes):'Los datos quedaron confirmados sin cambios de valor.')."\n\nEl mismo enlace público ya refleja los cambios. ¿Deseas modificar algo más?",$this->reload((int)$workflow->id),$items,'editing',['copy_url'=>$draft['public_url']]);
    }

    private function sendEstimate(object $workflow,array $draft,int $actor): array
    {
        $id=(int)$workflow->current_estimate_id;if(!$id)throw new RuntimeException('Primero selecciona o crea un estimate.');$order=$this->ownedOrder($id);$this->db->query('SELECT id,name,lastname,email FROM users WHERE id=:id AND id_owner=:owner AND level=5 LIMIT 1');$this->bind(['id'=>(int)$order->id_client,'owner'=>$this->ownerId]);$customer=$this->db->fetchOne();if(!$customer||!filter_var($customer->email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('El cliente no tiene un email válido.');$total=OrderCalculatorService::calculateTotal($order)['total'];$url=$this->publicUrl($id,(int)$customer->id);
        $body='<p>Hello '.htmlspecialchars(trim($customer->name.' '.$customer->lastname),ENT_QUOTES,'UTF-8').',</p><p>Your VNV Events estimate #'.$id.' is ready for review.</p><p><strong>Estimate total: $'.number_format($total,2).'</strong></p><p><a href="'.htmlspecialchars($url,ENT_QUOTES,'UTF-8').'">Review your estimate</a></p><p>Warmly,<br>VNV Events</p>';
        $sent=EmailServiceFactory::sendWithOwnerProvider($this->ownerId,(string)$customer->email,'Your VNV Events Estimate #'.$id,$body,true);if(empty($sent['success']))throw new RuntimeException('No pude enviar el estimate. Conservé todo listo para reintentar.');$this->audit($actor,'SEND_ESTIMATE',$id,['recipient'=>$customer->email,'public_url'=>$url]);$this->setStatus((int)$workflow->id,'COMPLETED');
        return $this->result("Estimate #{$id} fue enviado a {$customer->email} ✅\n¿Quieres crear otro estimate?",$this->reload((int)$workflow->id),[],'success',['copy_url'=>$url]);
    }

    private function summary(array $d,bool $editing): string
    {
        $services=array_map(fn($s)=>'• '.(is_array($s)?($s['name']??''):$s),(array)($d['services']??$d['requested_services']??[]));
        $lines=[$editing?'Estos son los cambios que entendí:':'Esto es lo que entendí:','',"Cliente\n".($d['customer_name']??'—')."\n".($d['email']??'—')."\n".($d['phone']??'—'),'',"Evento\n".($d['event_date']??'—').' · '.($d['start_time']??'—').'–'.($d['end_time']??'—')."\n".$this->address($d).(!empty($d['guest_count'])?"\n".$d['guest_count'].' invitados':''),'',"Servicios solicitados\n".($services?implode("\n",$services):'—')];
        if($editing&&!empty($d['services_to_remove']))$lines[]="\nQuitar\n• ".implode("\n• ",$d['services_to_remove']);if(!empty($d['notes']))$lines[]="\nNotas\n".$d['notes'];if(!empty($d['possible_duplicates']))$lines[]="\n⚠️ Encontré un posible estimate duplicado.";if(!empty($d['conflicts']))$lines[]="\n⚠️ Hay otro evento cercano en fecha/hora; no bloquea automáticamente, pero requiere revisión.";if(!empty($d['crm_conflicts'])){foreach($d['crm_conflicts'] as $field=>$values)$lines[]="\n⚠️ Conflicto de contacto ({$field}): captura/mensaje {$values['incoming']} · CRM {$values['crm']}. Indícame si uso el dato del CRM o si actualizo el CRM.";}if(!empty($d['uncertain_fields']))$lines[]="\n⚠️ Necesito confirmar: ".implode(', ',$d['uncertain_fields']).'.';return implode("\n",$lines);
    }

    private function missing(array $d): array {$map=['customer_name'=>'nombre del cliente','email'=>'email','event_date'=>'fecha','start_time'=>'hora de inicio','end_time'=>'hora final','services'=>'al menos un servicio'];$missing=[];foreach($map as $key=>$label)if(empty($d[$key]))$missing[]=$label;if(empty($d['address'])&&empty($d['venue']))$missing[]='dirección o venue';if(!empty($d['unresolved_services']))$missing[]='confirmar el servicio: '.implode(', ',$d['unresolved_services']);if(!empty($d['uncertain_fields']))$missing[]='confirmar datos dudosos: '.implode(', ',$d['uncertain_fields']);if(!empty($d['crm_conflicts']))$missing[]='resolver el conflicto de contacto con CRM';return $missing;}
    private function findCustomers(array $d): array {$where=[];$p=['owner'=>$this->ownerId];if(!empty($d['email'])){$where[]='LOWER(email)=LOWER(:email)';$p['email']=$d['email'];}if(!empty($d['phone'])){$where[]="REPLACE(REPLACE(REPLACE(REPLACE(phone,'(',''),')',''),'-',''),' ','')=:phone";$p['phone']=$this->phone($d['phone']);}if(!empty($d['customer_name'])){$where[]="LOWER(CONCAT_WS(' ',name,lastname))=LOWER(:name)";$p['name']=$d['customer_name'];}if(!$where)return [];$this->db->query('SELECT id,name,lastname,email,phone FROM users WHERE id_owner=:owner AND level=5 AND is_active=1 AND ('.implode(' OR ',$where).') LIMIT 5');$this->bind($p);return $this->db->fetchAll();}
    private function syncCustomerContact(int $customerId,array $draft,int $actor): void {$this->db->query('SELECT email,phone FROM users WHERE id=:id AND id_owner=:owner AND level=5');$this->bind(['id'=>$customerId,'owner'=>$this->ownerId]);$before=$this->db->fetchOne();if(!$before)return;$email=trim((string)($draft['email']??$before->email));$phone=$this->phone((string)($draft['phone']??$before->phone));$changes=[];if($email!==''&&strcasecmp($email,(string)$before->email)!==0){$this->db->query('SELECT id FROM users WHERE id_owner=:owner AND level=5 AND id<>:id AND LOWER(email)=LOWER(:email) LIMIT 1');$this->bind(['owner'=>$this->ownerId,'id'=>$customerId,'email'=>$email]);if($this->db->fetchOne())throw new RuntimeException('Ese email ya pertenece a otro cliente. Revisa el contacto antes de crear el estimate.');$changes['email']=['old'=>$before->email,'new'=>$email];}if($phone!==''&&$phone!==$this->phone((string)$before->phone))$changes['phone']=['old'=>$before->phone,'new'=>$phone];if(!$changes)return;$sets=[];$params=['id'=>$customerId,'owner'=>$this->ownerId];foreach($changes as $field=>$values){$sets[]="$field=:$field";$params[$field]=$values['new'];}$this->db->query('UPDATE users SET '.implode(',',$sets).' WHERE id=:id AND id_owner=:owner');$this->bind($params);$this->db->execute();$this->audit($actor,'UPDATE_CUSTOMER_CONTACT',$customerId,$changes,'customer');}
    private function resolveServices(array $names): array {$out=[];foreach($names as $name){$this->db->query("SELECT id,name,price,description FROM orders_services WHERE id_owner=:owner AND is_archived=0 AND (LOWER(name)=LOWER(:exact) OR LOWER(name) LIKE LOWER(:like)) ORDER BY LOWER(name)=LOWER(:exact) DESC,ABS(CHAR_LENGTH(name)-CHAR_LENGTH(:length_name)),id LIMIT 1");$this->bind(['owner'=>$this->ownerId,'exact'=>$name,'like'=>'%'.$name.'%','length_name'=>$name]);$s=$this->db->fetchOne()?:$this->fuzzyService((string)$name);if($s)$out[]=['id'=>(int)$s->id,'name'=>$s->name,'price'=>(float)$s->price,'description'=>$s->description,'quantity'=>1,'_requested'=>(string)$name];}return $out;}
    private function fuzzyService(string $requested): ?object {$this->db->query('SELECT id,name,price,description FROM orders_services WHERE id_owner=:owner AND is_archived=0');$this->db->bind(':owner',$this->ownerId);$needle=$this->serviceTokens($requested);$best=null;$bestScore=0.0;foreach($this->db->fetchAll() as $candidate){$tokens=$this->serviceTokens((string)$candidate->name);$union=array_unique(array_merge($needle,$tokens));$score=$union?count(array_intersect($needle,$tokens))/count($union):0;if($score>$bestScore){$bestScore=$score;$best=$candidate;}}return $bestScore>=0.5?$best:null;}
    private function serviceTokens(string $value): array {$words=preg_split('/[^\pL\pN]+/u',mb_strtolower($value),-1,PREG_SPLIT_NO_EMPTY)?:[];return array_values(array_unique(array_map(fn($word)=>mb_strlen($word)>3&&str_ends_with($word,'s')?mb_substr($word,0,-1):$word,$words)));}
    private function unresolvedServices(array $requested,array $resolved): array {$found=array_map(fn($service)=>(string)($service['_requested']??''),$resolved);return array_values(array_filter($requested,fn($name)=>!in_array((string)$name,$found,true)));}
    private function applyQuantities(array $services,int $guests): array {foreach($services as &$service){$perGuest=str_contains(mb_strtolower(($service['name']??'').' '.($service['description']??'')),'per guest');$service['quantity']=$perGuest&&$guests>0?$guests:max(1,(int)($service['quantity']??1));}unset($service);return $services;}
    private function requestDraft(string $message): array {$id=preg_match('/(?:request|solicitud)\s*#?\s*(\d+)/iu',$message,$m)?(int)$m[1]:0;$latest=(bool)preg_match('/\b(latest|última|ultimo|último|más reciente)\b/iu',$message);$name='';if(!$id&&!$latest&&preg_match('/(?:request|solicitud)(?:\s+de|\s+for)?\s+([\pL][\pL\s\'-]{2,80})/iu',$message,$m))$name=trim($m[1]);$sql="SELECT * FROM event_requests WHERE id_owner=:owner AND is_archived=0";$params=['owner'=>$this->ownerId];if($id){$sql.=' AND id=:id';$params['id']=$id;}elseif($name!==''){$sql.=' AND LOWER(full_name) LIKE LOWER(:name)';$params['name']='%'.$name.'%';}$sql.=' ORDER BY created_at DESC LIMIT 1';$this->db->query($sql);$this->bind($params);$r=$this->db->fetchOne();if(!$r)return [];$services=json_decode((string)$r->selected_services,true);if(!is_array($services))$services=preg_split('/[,;\n]+/',(string)$r->selected_services)?:[];return ['source_request_id'=>(int)$r->id,'customer_id'=>$r->id_user? (int)$r->id_user:null,'customer_name'=>$r->full_name,'email'=>$r->email,'phone'=>$this->phone((string)$r->phone),'event_date'=>$r->event_date,'start_time'=>$r->event_time,'address'=>$r->event_address,'guest_count'=>$r->guest_count? (int)$r->guest_count:null,'requested_services'=>array_values(array_filter(array_map(fn($v)=>is_array($v)?(string)($v['name']??$v['title']??''):(string)$v,$services))),'notes'=>$r->details];}
    private function duplicates(array $d): array {if(empty($d['customer_id'])||empty($d['event_date']))return [];$this->db->query("SELECT o.id,o.event_date,o.start_time,o.address,o.status_workflow FROM orders o WHERE o.id_owner=:owner AND o.id_client=:client AND o.event_date=:day AND o.is_archived=0 ORDER BY o.id DESC LIMIT 5");$this->bind(['owner'=>$this->ownerId,'client'=>$d['customer_id'],'day'=>$d['event_date']]);return $this->estimateItems($this->db->fetchAll());}
    private function conflicts(array $d): array {if(empty($d['event_date'])||empty($d['start_time'])||empty($d['end_time']))return [];$this->db->query("SELECT id,event_date,start_time,end_time,address FROM orders WHERE id_owner=:owner AND event_date=:day AND is_archived=0 AND COALESCE(status_workflow,'')<>'INVOICE_DRAFT' AND start_time<:end AND end_time>:start LIMIT 8");$this->bind(['owner'=>$this->ownerId,'day'=>$d['event_date'],'start'=>$d['start_time'],'end'=>$d['end_time']]);return array_map(fn($r)=>['id'=>(int)$r->id,'address'=>$r->address,'start_time'=>$r->start_time,'end_time'=>$r->end_time],$this->db->fetchAll());}
    private function findEstimates(string $q): array {$q=trim($q);$id=preg_match('/#?\s*(\d{1,9})/',$q,$m)?(int)$m[1]:0;$phone=$this->phone($q);$this->db->query("SELECT o.*,CONCAT_WS(' ',u.name,u.lastname) customer_name,u.email,u.phone FROM orders o JOIN users u ON u.id=o.id_client WHERE o.id_owner=:owner AND o.is_archived=0 AND COALESCE(o.status_workflow,'')='INVOICE_DRAFT' AND ((:id>0 AND o.id=:id) OR LOWER(u.email)=LOWER(:q) OR LOWER(CONCAT_WS(' ',u.name,u.lastname)) LIKE LOWER(:like) OR (:phone<>'' AND REPLACE(REPLACE(REPLACE(REPLACE(u.phone,'(',''),')',''),'-',''),' ','')=:phone)) ORDER BY o.id DESC LIMIT 8");$this->bind(['owner'=>$this->ownerId,'id'=>$id,'q'=>$q,'like'=>'%'.$q.'%','phone'=>$phone]);return $this->db->fetchAll();}
    private function selectEstimate(object $w,object $o): object {$services=$this->assignedServices((int)$o->id);$draft=['customer_id'=>(int)$o->id_client,'customer_name'=>$o->customer_name??'','email'=>$o->email??'','phone'=>$this->phone($o->phone??''),'event_date'=>$o->event_date,'start_time'=>$o->start_time,'end_time'=>$o->end_time,'address'=>$o->address,'requested_services'=>array_column($services,'name'),'services'=>$services,'notes'=>$o->notes??''];$this->saveDraft((int)$w->id,$draft,'ACTIVE',(int)$o->id);return $this->reload((int)$w->id);}
    private function assignedServices(int $orderId): array {$this->db->query('SELECT s.id,s.name,a.quantity,a.unit_price price,a.description FROM orders_services_assigned a JOIN orders_services s ON s.id=a.id_service WHERE a.id_order=:order AND a.id_owner=:owner ORDER BY a.id');$this->bind(['order'=>$orderId,'owner'=>$this->ownerId]);return array_map(fn($s)=>['id'=>(int)$s->id,'name'=>$s->name,'price'=>(float)$s->price,'description'=>$s->description,'quantity'=>(int)$s->quantity],$this->db->fetchAll());}
    private function resolveAssignedServices(int $orderId,array $names): array {$assigned=$this->assignedServices($orderId);return array_values(array_filter($assigned,function(array $service)use($names){foreach($names as $name)if(mb_stripos($service['name'],$name)!==false||mb_stripos($name,$service['name'])!==false)return true;return false;}));}
    private function estimateItems(array $rows): array {return array_map(fn($o)=>['title'=>'Estimate #'.$o->id,'meta'=>($o->event_date??'').' · '.($o->address??''),'detail'=>($o->customer_name??'').' · '.($o->status_workflow??''),'url'=>$this->internalEstimateUrl((int)$o->id)],$rows);}
    private function postCreationItems(int $orderId,string $publicUrl,float $total,string $customerName): array {return [
        ['title'=>'Ver Estimate','meta'=>'Vista pública del cliente · $'.number_format($total,2),'detail'=>$customerName,'url'=>$publicUrl,'kind'=>'public'],
        ['title'=>'Copiar enlace de pago','meta'=>'Comparte la misma página segura con el cliente','detail'=>$publicUrl,'url'=>$publicUrl,'kind'=>'copy'],
        ['title'=>'Editar Estimate #'.$orderId,'meta'=>'Vista administrativa interna','detail'=>'Solo para el equipo VNV','url'=>$this->internalEstimateUrl($orderId),'kind'=>'internal'],
    ];}
    private function internalEstimateUrl(int $orderId): string {return LocationUtils::pathFor('panel/planner-hub/management/orders/orders/edit?id='.$orderId);}
    private function publicUrl(int $orderId,int $customerId): string {$exp=time()+60*60*24*30;$data=['order_id'=>$orderId,'user_id'=>$customerId,'exp'=>$exp];$data['hash']=hash_hmac('sha256',json_encode($data),$_ENV['VNV_SECRET_KEY']??'mySuperSecretKey');return rtrim((string)($_ENV['APP_URL']??'https://vnvevents.com'),'/').'/order-access?token='.urlencode(base64_encode(json_encode($data)));}
    private function englishNotes(array $d): string {$parts=[];if(!empty($d['event_type']))$parts[]='Event type: '.$d['event_type'];if(!empty($d['guest_count']))$parts[]='Guest count: '.$d['guest_count'];if(!empty($d['venue']))$parts[]='Venue: '.$d['venue'];if(!empty($d['notes']))$parts[]=$d['notes'];$parts[]='Created by Mochi for review. No customer email was sent automatically.';return implode("\n",$parts);}
    private function address(array $d): string {$parts=[];foreach([$d['venue']??'',$d['address']??'',$d['city']??''] as $part){$part=trim((string)$part,' ,');if($part===''||array_filter($parts,fn($existing)=>mb_stripos($existing,$part)!==false||mb_stripos($part,$existing)!==false))continue;$parts[]=$part;}return implode(', ',$parts);}
    private function phone(string $value): string {return preg_replace('/\D+/','',$value)??'';}
    private function isStarterOnly(string $message,string $mode): bool {$plain=mb_strtolower(trim(preg_replace('/[^\pL\pN\s]+/u',' ',$message)??$message));$plain=preg_replace('/\s+/',' ',$plain)??$plain;$starters=$mode==='CREATE'?['create an estimate','create estimate','create another estimate','crear un estimate','crear estimate','crear otro estimate','crear un estimado','nuevo estimate','new estimate','necesito crear un estimate','i need to create an estimate']:['modify an estimate','modify estimate','modificar un estimate','editar estimate','actualizar estimate','i need to modify an existing estimate'];return in_array($plain,$starters,true);}
    private function create(int $user,int $session,string $mode): object {$this->db->query("INSERT INTO mochi_estimate_workflows(id_owner,site_key,id_user,id_session,mode,status,draft_json) VALUES(:owner,:site,:user,:session,:mode,'COLLECTING','{}') ON DUPLICATE KEY UPDATE mode=VALUES(mode),status='COLLECTING',draft_json='{}',current_estimate_id=NULL,last_error_code=NULL");$this->bind(['owner'=>$this->ownerId,'site'=>$this->siteKey,'user'=>$user,'session'=>$session,'mode'=>$mode]);$this->db->execute();return $this->active($user,$session);}
    private function reset(int $id,string $mode): void {$this->db->query("UPDATE mochi_estimate_workflows SET mode=:mode,status='COLLECTING',draft_json='{}',current_estimate_id=NULL,last_error_code=NULL WHERE id=:id");$this->bind(['id'=>$id,'mode'=>$mode]);$this->db->execute();}
    private function saveDraft(int $id,array $draft,string $status,?int $estimate=null): void {$this->db->query('UPDATE mochi_estimate_workflows SET draft_json=:draft,status=:status,current_estimate_id=COALESCE(:estimate,current_estimate_id),last_error_code=NULL WHERE id=:id');$this->bind(['id'=>$id,'draft'=>json_encode($draft,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'status'=>$status,'estimate'=>$estimate]);$this->db->execute();}
    private function reload(int $id): object {$this->db->query('SELECT * FROM mochi_estimate_workflows WHERE id=:id');$this->db->bind(':id',$id);return $this->db->fetchOne();}
    private function setStatus(int $id,string $status): void {$this->db->query('UPDATE mochi_estimate_workflows SET status=:status WHERE id=:id');$this->bind(['id'=>$id,'status'=>$status]);$this->db->execute();}
    private function setMode(int $id,string $mode): void {$this->db->query('UPDATE mochi_estimate_workflows SET mode=:mode WHERE id=:id');$this->bind(['id'=>$id,'mode'=>$mode]);$this->db->execute();}
    private function decode(string $json): array {return json_decode($json,true)?:[];}
    private function ownedOrder(int $id): object {$this->db->query('SELECT * FROM orders WHERE id=:id AND id_owner=:owner AND is_archived=0');$this->bind(['id'=>$id,'owner'=>$this->ownerId]);$o=$this->db->fetchOne();if(!$o)throw new RuntimeException('Estimate no disponible.');return $o;}
    private function audit(int $user,string $action,int $id,array $fields,string $entityType='estimate'): void {$this->db->query("INSERT INTO mochi_action_audit(id_owner,site_key,id_user,action_key,entity_type,entity_id,action_source,fields_json,status) VALUES(:owner,:site,:user,:action,:type,:entity,'MOCHI',:fields,'SUCCEEDED')");$this->bind(['owner'=>$this->ownerId,'site'=>$this->siteKey,'user'=>$user,'action'=>$action,'type'=>$entityType,'entity'=>(string)$id,'fields'=>json_encode($fields,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);$this->db->execute();}
    private function result(string $reply,?object $workflow,array $items,string $state,array $extra=[]): array {return ['reply'=>$reply,'items'=>$items,'estimate_workflow'=>$workflow?['id'=>(int)$workflow->id,'mode'=>$workflow->mode,'status'=>$workflow->status,'current_estimate_id'=>$workflow->current_estimate_id? (int)$workflow->current_estimate_id:null,'state'=>$state]:null]+$extra;}
    private function resultLinksText(): string {return "\nEl enlace y las acciones están listos abajo.";}
    private function bind(array $values): void {foreach($values as $key=>$value)$this->db->bind(':'.$key,$value);}
}
