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
    private const CREATE_RE='/\b(create|crear|crea|cr[eé]ame|prep[aá]rame|hazme|armame|[aá]rmame|nuevo|new)\b.*\b(estimate|estimado|cotizaci[oó]n)\b|\b(estimate|estimado|cotizaci[oó]n)\b.*\b(create|crear|crea|cr[eé]ame|prep[aá]rame|hazme|armame|[aá]rmame|nuevo|new)\b/iu';
    private const MODIFY_RE='/\b(modify|edit|update|modificar|editar|cambiar|actualizar)\b.*\b(estimate|estimado|cotizaci[oó]n)\b|\b(estimate|estimado|cotizaci[oó]n)\b.*\b(modify|edit|update|modificar|editar|cambiar|actualizar)\b/iu';
    private const APPROVE_RE='/^(?:(?:s[ií]|yes|correcto|correct|looks good|se ve bien|aprobado|approve)(?:[,.!\s]+(?:aplica|aplicar|aplica los|aplica esos|haz|realiza|guarda|confirma)(?:\s+(?:los|esos|estos))?(?:\s+cambios)?)?|(?:aplica|aplicar|haz|realiza|guarda|confirma)(?:\s+(?:los|esos|estos))?\s+cambios|cr[eé]alo|crealo|create it|hazlo)[.!\s]*$/iu';

    public function __construct(private int $ownerId=2,private string $siteKey='vnvevents',private ?Connection $db=null){$this->db??=new Connection();}

    public function active(int $userId,int $sessionId): ?object
    {
        $this->db->query("SELECT * FROM mochi_estimate_workflows WHERE id_owner=:owner AND site_key=:site AND id_user=:user AND id_session=:session AND status NOT IN ('CANCELLED','COMPLETED') LIMIT 1");
        $this->bind(['owner'=>$this->ownerId,'site'=>$this->siteKey,'user'=>$userId,'session'=>$sessionId]);
        return $this->db->fetchOne() ?: null;
    }

    public function shouldHandle(int $userId,int $sessionId,string $message): bool
    {
        $imageEstimate=str_contains($message,'[Captura ')&&preg_match('/\b(estimate|estimado|cotizaci[oó]n)\b/iu',$message);
        return (bool)($this->active($userId,$sessionId)||preg_match(self::CREATE_RE,$message)||preg_match(self::MODIFY_RE,$message)||$imageEstimate);
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
                $id=(int)$workflow->current_estimate_id;$order=$this->ownedOrder($id);$total=(float)OrderCalculatorService::calculateTotal($order)['total'];$public=(string)($draft['public_url']??$this->publicUrl($id,(int)$order->id_client));
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
            if($this->isEstimateLookupOnly($message))return $this->result('Encontré Estimate #'.$matches[0]->id.'. ¿Qué deseas cambiar?',$workflow,$this->estimateItems($matches),'editing');
        }
        if(preg_match(self::APPROVE_RE,trim($message))&&$workflow->status==='REVIEW')return $this->createEstimate($workflow,$draft,$userId);
        $selectedSuggestion=$this->selectedServiceSuggestion($draft,$message);
        if($selectedSuggestion!==null){
            $pending=(string)($draft['pending_service']??'');
            $draft['requested_services']=array_values(array_unique(array_map(
                fn($name)=>$this->matchesServiceName((string)$name,$pending)?$selectedSuggestion:(string)$name,
                (array)($draft['requested_services']??[])
            )));
            unset($draft['pending_service'],$draft['service_suggestions']);
            $message='El servicio correcto es '.$selectedSuggestion.'. '.$message;
        }
        $parsed=$this->messageFallbacks($this->parse($draft,$message),$message);
        if(!empty($parsed['phone'])&&preg_match('/\b(agrega(?:le)?|a[nñ]ade(?:le)?|pon(?:le)?|cambia|actualiza|modifica)\b.*\b(tel[eé]fono|phone|n[uú]mero)\b|\b(tel[eé]fono|phone|n[uú]mero)\b.*\b(agrega(?:le)?|a[nñ]ade(?:le)?|pon(?:le)?|cambia|actualiza|modifica)\b/iu',$message))$parsed['update_customer_contact']=true;
        $replaceServices=(bool)preg_match('/\b(solo|solamente|únicamente|unicamente|nada más|nada mas|más nada|mas nada|only|nothing else)\b/iu',$message);
        $parsedServices=array_values(array_filter(array_map('trim',(array)($parsed['requested_services']??[]))));
        $parsedCustom=(array)($parsed['custom_services']??[]);
        unset($parsed['requested_services'],$parsed['custom_services']);
        $removeServices=array_values(array_filter(array_map('trim',(array)($parsed['remove_services']??[]))));
        if($workflow->current_estimate_id){$explicit=$this->explicitUpdateFields($message,(array)($draft['requested_services']??[]),$parsedServices,$parsedCustom,$removeServices);$draft['explicit_update_fields']=array_values(array_unique(array_merge((array)($draft['explicit_update_fields']??[]),$explicit)));}
        $draft=array_replace_recursive($draft,$this->meaningfulParsedValues($parsed));
        if($parsedServices)$draft['requested_services']=$replaceServices?$parsedServices:array_values(array_unique(array_merge((array)($draft['requested_services']??[]),$parsedServices)));
        if($parsedCustom)$draft['custom_services']=$this->mergeCustomServices((array)($draft['custom_services']??[]),$parsedCustom);
        if($replaceServices&&!empty($draft['requested_services'])){$keep=(array)$draft['requested_services'];$draft['custom_services']=array_values(array_filter((array)($draft['custom_services']??[]),fn($service)=>$this->nameInList((string)($service['name']??''),$keep)));}
        if($replaceServices&&count($parsedServices)===1&&preg_match('/\$\s*(\d+(?:\.\d{1,2})?)|(?:a|at|por)\s+(\d+(?:\.\d{1,2})?)\s*(?:d[oó]lares|dollars)/iu',$message,$priceMatch)){$price=(float)($priceMatch[1]?:$priceMatch[2]);$draft['custom_services']=$this->mergeCustomServices((array)($draft['custom_services']??[]),[['name'=>$parsedServices[0],'price'=>$price,'description'=>null,'is_variable'=>true,'is_per_guest'=>(bool)preg_match('/\b(por persona|por invitado|per person|per guest)\b/iu',$message)]]);}
        $uncertain=array_values(array_filter((array)($draft['uncertain_fields']??[]),'is_string'));
        if(!str_contains($message,'[Captura '))foreach($parsed as $field=>$value){if($field==='uncertain_fields'||$field==='contact_resolution'||$field==='estimate_identifier'||$field==='remove_services')continue;if($value!==null&&$value!==''&&$value!==[])$uncertain=array_values(array_diff($uncertain,[$field]));}
        $draft['uncertain_fields']=$uncertain;
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
        if(!$missing)return $this->createEstimate($this->reload((int)$workflow->id),$draft,$userId);
        $reply=$this->missingReply($draft,$missing);
        return $this->result($reply,$this->reload((int)$workflow->id),$this->serviceSuggestionItems($draft),'creating');
    }

    private function parse(array $current,string $message): array
    {
        try{return (new AiJsonGenerator())->generate(
            'Update a VNV Events estimate draft from the latest Spanish or English user message. Preserve existing values unless explicitly corrected. Explicit user clarification overrides image extraction. Never invent unreadable or missing facts. If the user says only, nothing else, solo or más nada, requested_services must contain only the newly requested service. custom_services is only for a service that the user explicitly wants to create because it is not in the catalog; capture its English name, description, explicit price, whether that catalog price is variable, and whether quantity is per guest. A variable service keeps the stated amount as the estimate-specific price. Email is the first required identity field because the application uses it to find or create the CRM customer. Event type, phone, guest count, venue, address, city and notes are optional unless guest count is required to calculate an explicitly per-guest service. Leave event_type null when it was not explicitly supplied because the application will use Social Event. Only customer_name (when no CRM customer exists), email, event_date, start_time, end_time and requested_services may otherwise be treated as required or uncertain. When the user confirms or corrects uncertain data, remove that field from uncertain_fields. For a CRM contact conflict set contact_resolution only when the user explicitly chooses use_crm or update_crm. Dates YYYY-MM-DD, times HH:MM:SS. Customer-facing service content remains English. Return JSON only.',
            ['current_draft'=>$current,'latest_message'=>$message,'today'=>(new \DateTimeImmutable('now',new \DateTimeZone('America/New_York')))->format('Y-m-d')],
            ['customer_name'=>'string or null','email'=>'string or null','phone'=>'string or null','event_date'=>'YYYY-MM-DD or null','start_time'=>'HH:MM:SS or null','end_time'=>'HH:MM:SS or null','venue'=>'string or null','address'=>'string or null','city'=>'string or null','guest_count'=>'integer or null','event_type'=>'English string or null','requested_services'=>['English service names explicitly requested'],'custom_services'=>[['name'=>'English service name','price'=>'explicit numeric price or null','description'=>'English description or null','is_variable'=>'boolean or null','is_per_guest'=>'boolean']],'notes'=>'English customer-facing notes or null','remove_services'=>['English service names explicitly removed'],'uncertain_fields'=>['field names that remain uncertain'],'contact_resolution'=>'use_crm, update_crm, or null','estimate_identifier'=>'number/name/email/phone when supplied or null']
        );}catch(\Throwable){return ['notes'=>$message];}
    }

    private function messageFallbacks(array $parsed,string $message): array
    {
        if(empty($parsed['email'])&&preg_match('/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b/iu',$message,$match))$parsed['email']=$match[0];
        if(empty($parsed['customer_name'])&&preg_match('/(?:para|cliente|nombre)\s*:?\s*([\pL][\pL\s\'\-]{2,80}?)(?=\s*,?\s*(?:correo|email|tel[eé]fono|fecha|el\s+\d)|$)/iu',$message,$match))$parsed['customer_name']=trim($match[1],' ,');
        $custom=(array)($parsed['custom_services']??[]);
        if(count($custom)===1&&is_array($custom[0])&&(float)($custom[0]['price']??0)<=0&&preg_match('/(?:precio(?:\s+variable|\s+fijo)?\s*:?\s*|\$\s*)(\d+(?:\.\d{1,2})?)/iu',$message,$match)){$custom[0]['price']=(float)$match[1];$parsed['custom_services']=$custom;}
        return $parsed;
    }

    private function meaningfulParsedValues(array $parsed): array
    {
        return array_filter($parsed,static function($value,$key){
            if(in_array($key,['contact_resolution','estimate_identifier'],true))return $value!==null&&$value!=='';
            return $value!==null&&$value!==''&&$value!==[];
        },ARRAY_FILTER_USE_BOTH);
    }

    private function normalize(array $draft): array
    {
        foreach(['customer_name','email','phone','event_date','start_time','end_time','venue','address','city','event_type','notes'] as $key){if(isset($draft[$key])&&is_string($draft[$key]))$draft[$key]=trim($draft[$key]);}
        if(empty($draft['event_type']))$draft['event_type']='Social Event';
        $blockingUncertain=['customer_name','email','event_date','start_time','end_time','requested_services','services'];
        $draft['uncertain_fields']=array_values(array_filter(
            (array)($draft['uncertain_fields']??[]),
            static fn($field)=>is_string($field)&&in_array($field,$blockingUncertain,true)
        ));
        $draft['phone']=$this->phone((string)($draft['phone']??''));
        $services=array_values(array_unique(array_filter(array_map('trim',(array)($draft['requested_services']??[])))));
        $removed=array_values(array_filter(array_map('trim',(array)($draft['remove_services']??[]))));
        $draft['requested_services']=array_values(array_filter($services,fn($name)=>!array_filter($removed,fn($remove)=>$this->matchesServiceName((string)$name,(string)$remove))));
        $custom=[];foreach((array)($draft['custom_services']??[]) as $service){if(!is_array($service))continue;$name=trim((string)($service['name']??''));if($name==='')continue;$custom[mb_strtolower($name)]=['name'=>$name,'price'=>isset($service['price'])?(float)$service['price']:null,'description'=>trim((string)($service['description']??'')),'is_variable'=>array_key_exists('is_variable',$service)&&$service['is_variable']!==null?(bool)$service['is_variable']:true,'is_per_guest'=>!empty($service['is_per_guest'])];}$draft['custom_services']=array_values($custom);
        unset($draft['remove_services']);return $draft;
    }

    private function enrich(array $draft): array
    {
        $customers=$this->findCustomers($draft);$draft['customer_matches']=array_map(fn($c)=>(int)$c->id,$customers);
        if(count($customers)===1){$c=$customers[0];$draft['customer_id']=(int)$c->id;$draft['customer_name']=($draft['customer_name']??'')?:trim($c->name.' '.$c->lastname);$crmEmail=trim((string)$c->email);$crmPhone=$this->phone((string)$c->phone);$incomingEmail=trim((string)($draft['email']??''));$incomingPhone=$this->phone((string)($draft['phone']??''));$conflicts=[];if($incomingEmail!==''&&$crmEmail!==''&&strcasecmp($incomingEmail,$crmEmail)!==0)$conflicts['email']=['incoming'=>$incomingEmail,'crm'=>$crmEmail];if($incomingPhone!==''&&$crmPhone!==''&&$incomingPhone!==$crmPhone)$conflicts['phone']=['incoming'=>$incomingPhone,'crm'=>$crmPhone];if(($draft['contact_resolution']??null)==='use_crm'){$draft['email']=$crmEmail;$draft['phone']=$crmPhone;$conflicts=[];}elseif(($draft['contact_resolution']??null)==='update_crm'){$draft['update_customer_contact']=true;$conflicts=[];}else{$draft['crm_conflicts']=$conflicts;}$draft['email']=($draft['email']??'')?:$crmEmail;$draft['phone']=($draft['phone']??'')?:$crmPhone;}
        $draft['services']=$this->applyQuantities(array_merge($this->resolveServices($this->catalogRequestedServices((array)($draft['requested_services']??[]),(array)($draft['custom_services']??[]))),$this->customDraftServices((array)($draft['custom_services']??[]))),(int)($draft['guest_count']??0));$draft['unresolved_services']=$this->unresolvedServices((array)($draft['requested_services']??[]),$draft['services']);
        if(!empty($draft['unresolved_services'])){
            $draft['pending_service']=(string)$draft['unresolved_services'][0];
            $draft['service_suggestions']=$this->serviceCandidates($draft['pending_service']);
        }else unset($draft['pending_service'],$draft['service_suggestions']);
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
        $assigned=new OrdersServicesAssignedRepository();foreach((array)$draft['services'] as $service){if((int)($service['id']??0)<=0)$service['id']=$this->createCustomService($service,$actor);$qty=max(1,(int)($service['quantity']??1));$price=(float)$service['price'];$variable=($service['is_variable']??'NO')==='YES';$assigned->add(['id_order'=>$orderId,'id_service'=>(int)$service['id'],'quantity'=>$qty,'unit_price'=>$price,'description'=>$service['description']??null,'subtotal'=>$qty*$price,'id_owner'=>$this->ownerId,'is_variable'=>$variable?'YES':'NO','variable_price'=>$variable?$price:null]);}
        $order=(object)(new OrdersRepository())->getByIdWithoutOwnershipCheck($orderId);$totals=OrderCalculatorService::calculateTotal($order);$public=$this->publicUrl($orderId,$customerId);
        $draft['current_estimate_id']=$orderId;$draft['total']=$totals['total'];$draft['public_url']=$public;
        if(!empty($draft['source_request_id'])){$this->db->query("UPDATE event_requests SET id_user=:customer,status='CONVERTED',updated_at=NOW() WHERE id=:request AND id_owner=:owner");$this->bind(['customer'=>$customerId,'request'=>(int)$draft['source_request_id'],'owner'=>$this->ownerId]);$this->db->execute();}
        $this->saveDraft((int)$workflow->id,$draft,'ACTIVE',$orderId);$this->setMode((int)$workflow->id,'MODIFY');$this->audit($actor,'CREATE_ESTIMATE',$orderId,['draft'=>$draft,'totals'=>$totals]);
        $items=$this->postCreationItems($orderId,$public,(float)$totals['total'],(string)$draft['customer_name']);
        return $this->result("Perfecto 😊 Estimate #{$orderId} está creado para {$draft['customer_name']}.\nTotal: $".number_format($totals['total'],2)."\n\nAquí puedes ver exactamente lo que verá el cliente. ¿Quieres modificar algo o está bien así?",$this->reload((int)$workflow->id),$items,'editing',['copy_url'=>$public]);
    }

    private function applyChanges(object $workflow,array $draft,int $actor): array
    {
        $id=(int)$workflow->current_estimate_id;$order=$this->ownedOrder($id);$before=OrderCalculatorService::calculateTotal($order);$beforeServices=$this->assignedServices($id);$explicit=(array)($draft['explicit_update_fields']??[]);$sets=[];$params=['id'=>$id,'owner'=>$this->ownerId];foreach(['event_date','start_time','end_time','address','notes'] as $field)if(in_array($field,$explicit,true)&&!empty($draft[$field])){$sets[]="$field=:$field";$params[$field]=$field==='address'?$this->address($draft):$draft[$field];}
        if(!empty($draft['update_customer_contact']))$this->syncCustomerContact((int)$order->id_client,$draft,$actor);
        if($sets){$this->db->query('UPDATE orders SET '.implode(',',$sets).' WHERE id=:id AND id_owner=:owner');$this->bind($params);$this->db->execute();}
        if(in_array('services',$explicit,true)&&!empty($draft['services_to_remove'])){foreach($this->resolveAssignedServices($id,$draft['services_to_remove']) as $service){$this->db->query('DELETE FROM orders_services_assigned WHERE id_order=:order AND id_service=:service AND id_owner=:owner');$this->bind(['order'=>$id,'service'=>$service['id'],'owner'=>$this->ownerId]);$this->db->execute();}}
        if(in_array('services',$explicit,true)&&!empty($draft['requested_services'])){foreach($this->applyQuantities(array_merge($this->resolveServices($this->catalogRequestedServices($draft['requested_services'],(array)($draft['custom_services']??[]))),$this->customDraftServices((array)($draft['custom_services']??[]))),(int)($draft['guest_count']??0)) as $service){if((int)($service['id']??0)<=0)$service['id']=$this->createCustomService($service,$actor);$qty=max(1,(int)$service['quantity']);$variable=($service['is_variable']??'NO')==='YES';$this->db->query('SELECT id FROM orders_services_assigned WHERE id_order=:order AND id_service=:service LIMIT 1');$this->bind(['order'=>$id,'service'=>$service['id']]);if($existing=$this->db->fetchOne()){$this->db->query('UPDATE orders_services_assigned SET quantity=:qty,unit_price=:price,description=:description,subtotal=:subtotal,is_variable=:variable,variable_price=:variable_price WHERE id=:id AND id_owner=:owner');$this->bind(['qty'=>$qty,'price'=>$service['price'],'description'=>$service['description'],'subtotal'=>$qty*$service['price'],'variable'=>$variable?'YES':'NO','variable_price'=>$variable?(float)$service['price']:null,'id'=>(int)$existing->id,'owner'=>$this->ownerId]);$this->db->execute();}else{(new OrdersServicesAssignedRepository())->add(['id_order'=>$id,'id_service'=>$service['id'],'quantity'=>$qty,'unit_price'=>$service['price'],'description'=>$service['description'],'subtotal'=>$qty*$service['price'],'id_owner'=>$this->ownerId,'is_variable'=>$variable?'YES':'NO','variable_price'=>$variable?(float)$service['price']:null]);}}}
        $updatedOrder=$this->ownedOrder($id);$after=OrderCalculatorService::calculateTotal($updatedOrder);$afterServices=$this->assignedServices($id);$changes=[];foreach(['event_date'=>'Fecha','start_time'=>'Inicio','end_time'=>'Final','address'=>'Ubicación'] as $field=>$label){$old=(string)($order->$field??'');$new=(string)($updatedOrder->$field??'');if($old!==$new)$changes[]=$label.': '.$old.' → '.$new;}$oldNames=implode(', ',array_column($beforeServices,'name'));$newNames=implode(', ',array_column($afterServices,'name'));if($oldNames!==$newNames)$changes[]='Servicios: '.$oldNames.' → '.$newNames;if((float)$before['total']!==(float)$after['total'])$changes[]='Total: $'.number_format($before['total'],2).' → $'.number_format($after['total'],2);$draft['total']=$after['total'];$draft['public_url']=(string)($draft['public_url']??$this->publicUrl($id,(int)$order->id_client));unset($draft['services_to_remove'],$draft['explicit_update_fields']);$this->saveDraft((int)$workflow->id,$draft,'ACTIVE',$id);$this->audit($actor,'UPDATE_ESTIMATE',$id,['before_total'=>$before['total'],'after_total'=>$after['total'],'changes'=>$changes]);
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
        $services=array_map(function($s){if(!is_array($s))return '• '.$s;$detail='• '.($s['name']??'');if(!empty($s['_custom']))$detail.=' · nuevo · '.(($s['is_variable']??'NO')==='YES'?'variable':'fijo').' · $'.number_format((float)($s['price']??0),2).(!empty($s['is_per_guest'])?' por persona':'');return $detail;},(array)($d['services']??$d['requested_services']??[]));
        $lines=[$editing?'Estos son los cambios que entendí:':'Esto es lo que entendí:','',"Cliente\n".($d['customer_name']??'—')."\n".($d['email']??'—')."\n".($d['phone']??'—'),'',"Evento\n".($d['event_date']??'—').' · '.($d['start_time']??'—').'–'.($d['end_time']??'—')."\n".$this->address($d).(!empty($d['guest_count'])?"\n".$d['guest_count'].' invitados':''),'',"Servicios solicitados\n".($services?implode("\n",$services):'—')];
        if($editing&&!empty($d['services_to_remove']))$lines[]="\nQuitar\n• ".implode("\n• ",$d['services_to_remove']);if(!empty($d['notes']))$lines[]="\nNotas\n".$d['notes'];if(!empty($d['possible_duplicates']))$lines[]="\n⚠️ Encontré un posible estimate duplicado.";if(!empty($d['conflicts']))$lines[]="\n⚠️ Hay otro evento cercano en fecha/hora; no bloquea automáticamente, pero requiere revisión.";if(!empty($d['crm_conflicts'])){foreach($d['crm_conflicts'] as $field=>$values)$lines[]="\n⚠️ Conflicto de contacto ({$field}): captura/mensaje {$values['incoming']} · CRM {$values['crm']}. Indícame si uso el dato del CRM o si actualizo el CRM.";}if(!empty($d['uncertain_fields']))$lines[]="\n⚠️ Necesito confirmar: ".implode(', ',$d['uncertain_fields']).'.';return implode("\n",$lines);
    }

    private function missing(array $d): array
    {
        $missing=[];
        if(empty($d['email'])||!filter_var($d['email'],FILTER_VALIDATE_EMAIL))$missing[]='correo válido del cliente';
        if(empty($d['customer_id'])&&empty($d['customer_name']))$missing[]='nombre del cliente';
        foreach((array)($d['custom_services']??[]) as $service){
            $name=(string)($service['name']??'el servicio nuevo');
            if(trim((string)($service['description']??''))==='')$missing[]='descripción de '.$name;
            if((float)($service['price']??0)<=0)$missing[]='precio de '.$name;
            if(!empty($service['is_per_guest'])&&(int)($d['guest_count']??0)<=0)$missing[]='cantidad de invitados para calcular '.$name;
        }
        foreach(['event_date'=>'fecha del evento','start_time'=>'hora de inicio','end_time'=>'hora final','services'=>'servicio'] as $key=>$label)if(empty($d[$key]))$missing[]=$label;
        if(!empty($d['unresolved_services']))$missing[]='confirmar el servicio solicitado';
        $labels=['customer_name'=>'nombre del cliente','email'=>'correo del cliente','event_date'=>'fecha del evento','start_time'=>'hora de inicio','end_time'=>'hora final','requested_services'=>'servicio','services'=>'servicio'];
        foreach(array_values(array_filter((array)($d['uncertain_fields']??[]),'is_string')) as $field){$label=$labels[$field]??'un dato de la captura';if(!in_array($label,$missing,true)&&!in_array($label==='correo del cliente'?'correo válido del cliente':$label,$missing,true))$missing[]='confirmar '.$label;}
        if(!empty($d['crm_conflicts']))$missing[]='elegir si conservamos el contacto del CRM o lo actualizamos';
        return array_values(array_unique($missing));
    }
    private function findCustomers(array $d): array {$email=trim((string)($d['email']??''));if(!filter_var($email,FILTER_VALIDATE_EMAIL))return [];$this->db->query('SELECT id,name,lastname,email,phone FROM users WHERE id_owner=:owner AND level=5 AND is_active=1 AND LOWER(email)=LOWER(:email) LIMIT 2');$this->bind(['owner'=>$this->ownerId,'email'=>$email]);return $this->db->fetchAll();}
    private function syncCustomerContact(int $customerId,array $draft,int $actor): void {$this->db->query('SELECT email,phone FROM users WHERE id=:id AND id_owner=:owner AND level=5');$this->bind(['id'=>$customerId,'owner'=>$this->ownerId]);$before=$this->db->fetchOne();if(!$before)return;$email=trim((string)($draft['email']??$before->email));$phone=$this->phone((string)($draft['phone']??$before->phone));$changes=[];if($email!==''&&strcasecmp($email,(string)$before->email)!==0){$this->db->query('SELECT id FROM users WHERE id_owner=:owner AND level=5 AND id<>:id AND LOWER(email)=LOWER(:email) LIMIT 1');$this->bind(['owner'=>$this->ownerId,'id'=>$customerId,'email'=>$email]);if($this->db->fetchOne())throw new RuntimeException('Ese email ya pertenece a otro cliente. Revisa el contacto antes de crear el estimate.');$changes['email']=['old'=>$before->email,'new'=>$email];}if($phone!==''&&$phone!==$this->phone((string)$before->phone))$changes['phone']=['old'=>$before->phone,'new'=>$phone];if(!$changes)return;$sets=[];$params=['id'=>$customerId,'owner'=>$this->ownerId];foreach($changes as $field=>$values){$sets[]="$field=:$field";$params[$field]=$values['new'];}$this->db->query('UPDATE users SET '.implode(',',$sets).' WHERE id=:id AND id_owner=:owner');$this->bind($params);$this->db->execute();$this->audit($actor,'UPDATE_CUSTOMER_CONTACT',$customerId,$changes,'customer');}
    private function resolveServices(array $names): array {$out=[];foreach($names as $name){$this->db->query("SELECT id,name,price,description FROM orders_services WHERE id_owner=:owner AND is_archived=0 AND (LOWER(name)=LOWER(:exact) OR LOWER(name) LIKE LOWER(:like)) ORDER BY LOWER(name)=LOWER(:exact) DESC,ABS(CHAR_LENGTH(name)-CHAR_LENGTH(:length_name)),id LIMIT 1");$this->bind(['owner'=>$this->ownerId,'exact'=>$name,'like'=>'%'.$name.'%','length_name'=>$name]);$s=$this->db->fetchOne()?:$this->fuzzyService((string)$name);if($s)$out[]=['id'=>(int)$s->id,'name'=>$s->name,'price'=>(float)$s->price,'description'=>$s->description,'quantity'=>1,'_requested'=>(string)$name];}return $out;}
    private function customDraftServices(array $services): array {$out=[];foreach($services as $service){if(!is_array($service)||empty($service['name'])||empty($service['description'])||(float)($service['price']??0)<=0||!array_key_exists('is_variable',$service)||$service['is_variable']===null)continue;$out[]=['id'=>0,'name'=>(string)$service['name'],'price'=>(float)$service['price'],'description'=>(string)$service['description'],'quantity'=>1,'is_variable'=>!empty($service['is_variable'])?'YES':'NO','variable_price'=>!empty($service['is_variable'])?(float)$service['price']:null,'is_per_guest'=>!empty($service['is_per_guest']),'_custom'=>true,'_requested'=>(string)$service['name']];}return $out;}
    private function mergeCustomServices(array $current,array $incoming): array {$merged=[];foreach(array_merge($current,$incoming) as $service){if(!is_array($service))continue;$name=trim((string)($service['name']??''));if($name==='')continue;$key=mb_strtolower($name);$updates=array_filter($service,static fn($value)=>$value!==null&&$value!=='');$merged[$key]=array_replace($merged[$key]??[],$updates,['name'=>$name]);}return array_values($merged);}
    private function catalogRequestedServices(array $requested,array $custom): array {$customNames=array_values(array_filter(array_map(fn($service)=>is_array($service)?(string)($service['name']??''):'',$custom)));return array_values(array_filter($requested,fn($name)=>!$this->nameInList((string)$name,$customNames)));}
    private function nameInList(string $name,array $names): bool {foreach($names as $candidate)if($this->matchesServiceName($name,(string)$candidate)||mb_strtolower(trim($name))===mb_strtolower(trim((string)$candidate)))return true;return false;}
    private function createCustomService(array $service,int $actor): int {$this->db->query('SELECT id FROM orders_services WHERE id_owner=:owner AND is_archived=0 AND LOWER(name)=LOWER(:name) LIMIT 1');$this->bind(['owner'=>$this->ownerId,'name'=>$service['name']]);if($existing=$this->db->fetchOne())return (int)$existing->id;$this->db->query('INSERT INTO orders_services(name,description,price,requirements,id_owner,is_archived,is_variable) VALUES(:name,:description,:price,NULL,:owner,0,:variable)');$this->bind(['name'=>$service['name'],'description'=>$service['description'],'price'=>(float)$service['price'],'owner'=>$this->ownerId,'variable'=>($service['is_variable']??'YES')==='YES'?'YES':'NO']);$this->db->execute();$id=(int)$this->db->lastId();if(!$id)throw new RuntimeException('No pude crear el servicio nuevo. Conservé el borrador para reintentar.');$this->audit($actor,'CREATE_SERVICE',$id,['name'=>$service['name'],'description'=>$service['description'],'price'=>(float)$service['price'],'is_variable'=>$service['is_variable']??'YES'],'service');return $id;}
    private function fuzzyService(string $requested): ?object
    {
        $candidates=$this->serviceCandidates($requested,1);
        return $candidates&&($candidates[0]->_score??0)>=0.72?$candidates[0]:null;
    }
    private function serviceCandidates(string $requested,int $limit=3): array
    {
        $this->db->query('SELECT id,name,price,description FROM orders_services WHERE id_owner=:owner AND is_archived=0');$this->db->bind(':owner',$this->ownerId);
        $ranked=[];foreach($this->db->fetchAll() as $candidate){$score=$this->serviceSimilarity($requested,(string)$candidate->name);if($score<0.42)continue;$candidate->_score=$score;$ranked[]=$candidate;}
        usort($ranked,fn($a,$b)=>($b->_score<=>$a->_score)?:strcmp((string)$a->name,(string)$b->name));return array_slice($ranked,0,max(1,$limit));
    }
    private function serviceSimilarity(string $left,string $right): float
    {
        $a=$this->serviceKey($left);$b=$this->serviceKey($right);if($a===''||$b==='')return 0.0;if($a===$b)return 1.0;
        $length=max(strlen($a),strlen($b));$edit=$length?1-(levenshtein($a,$b)/$length):0.0;
        $ta=$this->serviceTokens($left);$tb=$this->serviceTokens($right);$union=array_unique(array_merge($ta,$tb));$token=$union?count(array_intersect($ta,$tb))/count($union):0.0;
        similar_text($a,$b,$percent);return max($edit,$token,((float)$percent)/100);
    }
    private function serviceKey(string $value): string {$value=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',mb_strtolower($value))?:mb_strtolower($value);return preg_replace('/[^a-z0-9]+/','',$value)??'';}
    private function serviceTokens(string $value): array {$words=preg_split('/[^\pL\pN]+/u',mb_strtolower($value),-1,PREG_SPLIT_NO_EMPTY)?:[];return array_values(array_unique(array_map(fn($word)=>mb_strlen($word)>3&&str_ends_with($word,'s')?mb_substr($word,0,-1):$word,$words)));}
    private function matchesServiceName(string $left,string $right): bool {return $this->serviceSimilarity($left,$right)>=0.72;}
    private function unresolvedServices(array $requested,array $resolved): array {$found=array_map(fn($service)=>(string)($service['_requested']??''),$resolved);return array_values(array_filter($requested,fn($name)=>!in_array((string)$name,$found,true)));}
    private function applyQuantities(array $services,int $guests): array {foreach($services as &$service){$copy=mb_strtolower(($service['name']??'').' '.($service['description']??''));$perGuest=!empty($service['is_per_guest'])||(bool)preg_match('/\b(?:per guest|per person|guest minimum|minimum[^.]{0,20}guests?|p\s*\/\s*p)\b/iu',$copy);$service['is_per_guest']=$perGuest;$service['quantity']=$perGuest&&$guests>0?$guests:max(1,(int)($service['quantity']??1));}unset($service);return $services;}
    private function requestDraft(string $message): array {$id=preg_match('/(?:request|solicitud)\s*#?\s*(\d+)/iu',$message,$m)?(int)$m[1]:0;$latest=(bool)preg_match('/\b(latest|última|ultimo|último|más reciente)\b/iu',$message);$name='';if(!$id&&!$latest&&preg_match('/(?:request|solicitud)(?:\s+de|\s+for)\s+([\pL][\pL\s\'-]{2,80})/iu',$message,$m))$name=trim($m[1]);if(!$id&&!$latest&&$name==='')return [];$sql="SELECT * FROM event_requests WHERE id_owner=:owner AND is_archived=0";$params=['owner'=>$this->ownerId];if($id){$sql.=' AND id=:id';$params['id']=$id;}elseif($name!==''){$sql.=' AND LOWER(full_name) LIKE LOWER(:name)';$params['name']='%'.$name.'%';}$sql.=' ORDER BY created_at DESC LIMIT 1';$this->db->query($sql);$this->bind($params);$r=$this->db->fetchOne();if(!$r)return [];$services=json_decode((string)$r->selected_services,true);if(!is_array($services))$services=preg_split('/[,;\n]+/',(string)$r->selected_services)?:[];return ['source_request_id'=>(int)$r->id,'customer_id'=>$r->id_user? (int)$r->id_user:null,'customer_name'=>$r->full_name,'email'=>$r->email,'phone'=>$this->phone((string)$r->phone),'event_date'=>$r->event_date,'start_time'=>$r->event_time,'address'=>$r->event_address,'guest_count'=>$r->guest_count? (int)$r->guest_count:null,'requested_services'=>array_values(array_filter(array_map(fn($v)=>is_array($v)?(string)($v['name']??$v['title']??''):(string)$v,$services))),'notes'=>$r->details];}
    private function duplicates(array $d): array {if(empty($d['customer_id'])||empty($d['event_date']))return [];$this->db->query("SELECT o.id,o.event_date,o.start_time,o.address,o.status_workflow FROM orders o WHERE o.id_owner=:owner AND o.id_client=:client AND o.event_date=:day AND o.is_archived=0 ORDER BY o.id DESC LIMIT 5");$this->bind(['owner'=>$this->ownerId,'client'=>$d['customer_id'],'day'=>$d['event_date']]);return $this->estimateItems($this->db->fetchAll());}
    private function conflicts(array $d): array {if(empty($d['event_date'])||empty($d['start_time'])||empty($d['end_time']))return [];$this->db->query("SELECT id,event_date,start_time,end_time,address FROM orders WHERE id_owner=:owner AND event_date=:day AND is_archived=0 AND COALESCE(status_workflow,'')<>'INVOICE_DRAFT' AND start_time<:end AND end_time>:start LIMIT 8");$this->bind(['owner'=>$this->ownerId,'day'=>$d['event_date'],'start'=>$d['start_time'],'end'=>$d['end_time']]);return array_map(fn($r)=>['id'=>(int)$r->id,'address'=>$r->address,'start_time'=>$r->start_time,'end_time'=>$r->end_time],$this->db->fetchAll());}
    private function findEstimates(string $q): array
    {
        $q=trim($q);$where=[];$params=['owner'=>$this->ownerId];
        if(preg_match('/\b(?:estimate|estimado|cotizaci[oó]n)\s*#?\s*(\d{1,9})\b/iu',$q,$m)){$where[]='o.id=:estimate_id';$params['estimate_id']=(int)$m[1];}
        if(preg_match('/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b/iu',$q,$m)){$where[]='LOWER(u.email)=LOWER(:email)';$params['email']=$m[0];}
        if(preg_match('/(?:\+?1[\s.\-]?)?(?:\(?\d{3}\)?[\s.\-]?)\d{3}[\s.\-]?\d{4}/',$q,$m)){$where[]="RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(u.phone,''),'(',''),')',''),'-',''),' ',''),'+',''),10)=:phone";$params['phone']=substr($this->phone($m[0]),-10);}
        $name=$this->estimateCustomerName($q);if($name!==''){$tokens=preg_split('/\s+/u',$name,-1,PREG_SPLIT_NO_EMPTY)?:[];$nameWhere=[];foreach(array_slice($tokens,0,4) as $index=>$token){$key='name_'.$index;$nameWhere[]="LOWER(CONCAT_WS(' ',u.name,u.lastname)) LIKE LOWER(:{$key})";$params[$key]='%'.$token.'%';}if($nameWhere)$where[]='('.implode(' AND ',$nameWhere).')';}
        $date=$this->estimateEventDate($q);if($date!==null){$where[]='o.event_date=:event_date';$params['event_date']=$date;}
        if(!$where)return [];
        $this->db->query("SELECT o.*,CONCAT_WS(' ',u.name,u.lastname) customer_name,u.email,u.phone FROM orders o JOIN users u ON u.id=o.id_client WHERE o.id_owner=:owner AND o.is_archived=0 AND COALESCE(o.status_workflow,'')='INVOICE_DRAFT' AND (".implode(' OR ',$where).") ORDER BY o.id DESC LIMIT 8");$this->bind($params);return $this->db->fetchAll();
    }

    private function explicitUpdateFields(string $message,array $currentServices,array $services,array $customServices,array $removeServices): array
    {
        $fields=[];
        if(preg_match('/\b(fecha|d[ií]a|date)\b/iu',$message)||$this->estimateEventDate($message)!==null)$fields[]='event_date';
        if(preg_match('/\b(hora\s+de\s+inicio|empieza|comienza|start(?:ing)?\s+time)\b/iu',$message))$fields[]='start_time';
        if(preg_match('/\b(hora\s+(?:de\s+)?(?:fin|final)|termina|finaliza|end(?:ing)?\s+time)\b/iu',$message))$fields[]='end_time';
        if(preg_match('/\b(direcci[oó]n|ubicaci[oó]n|venue|address|location)\b/iu',$message))$fields[]='address';
        if(preg_match('/\b(nota|notas|note|notes)\b/iu',$message))$fields[]='notes';
        if(preg_match('/\b(invitados?|personas?|guests?|attendees?)\b/iu',$message))$fields[]='services';
        $serviceNames=fn(array $names)=>array_values(array_map(fn($name)=>mb_strtolower(trim((string)$name)),array_filter($names,fn($name)=>is_string($name)&&trim($name)!=='')));
        if(($services&&$serviceNames($services)!==$serviceNames($currentServices))||$customServices||$removeServices||preg_match('/\b(servicio|service|paquete|package)\b/iu',$message))$fields[]='services';
        return array_values(array_unique($fields));
    }

    private function estimateCustomerName(string $message): string
    {
        if(!preg_match('/(?:se\s+llama|cliente(?:\s+que\s+se\s+llama)?|nombre)\s*:?[\s]+([\pL][\pL\s\'\-]{1,80}?)(?=\s+(?:la\s+clienta|el\s+cliente|el\s+evento|evento|correo|email|tel[eé]fono|fecha|direcci[oó]n)\b|[,.;]|$)/iu',$message,$match))return '';
        return trim(preg_replace('/\s+/u',' ',$match[1])??$match[1]);
    }

    private function estimateEventDate(string $message): ?string
    {
        if(preg_match('/\b(20\d{2})-(\d{2})-(\d{2})\b/',$message,$match))return $match[0];
        $months=['enero'=>1,'febrero'=>2,'marzo'=>3,'abril'=>4,'mayo'=>5,'junio'=>6,'julio'=>7,'agosto'=>8,'septiembre'=>9,'setiembre'=>9,'octubre'=>10,'noviembre'=>11,'diciembre'=>12];
        if(!preg_match('/\b(?:el\s+)?(\d{1,2})\s+de\s+([a-záéíóú]+)(?:\s+de\s+(20\d{2}))?\b/iu',$message,$match))return null;$month=$months[mb_strtolower($match[2])]??null;if(!$month)return null;$year=isset($match[3])?(int)$match[3]:(int)date('Y');return checkdate($month,(int)$match[1],$year)?sprintf('%04d-%02d-%02d',$year,$month,(int)$match[1]):null;
    }

    private function isEstimateLookupOnly(string $message): bool
    {
        return !preg_match('/\b(agrega(?:le)?|a[nñ]ade(?:le)?|pon(?:le)?|quita(?:le)?|remueve|cambia|actualiza|modifica)\b/iu',$message);
    }
    private function selectEstimate(object $w,object $o): object {$services=$this->assignedServices((int)$o->id);$draft=['customer_id'=>(int)$o->id_client,'customer_name'=>$o->customer_name??'','email'=>$o->email??'','phone'=>$this->phone($o->phone??''),'event_date'=>$o->event_date,'start_time'=>$o->start_time,'end_time'=>$o->end_time,'address'=>$o->address,'requested_services'=>array_column($services,'name'),'services'=>$services,'notes'=>$o->notes??''];$this->saveDraft((int)$w->id,$draft,'ACTIVE',(int)$o->id);return $this->reload((int)$w->id);}
    private function assignedServices(int $orderId): array {$this->db->query('SELECT s.id,s.name,a.quantity,a.unit_price price,a.description FROM orders_services_assigned a JOIN orders_services s ON s.id=a.id_service WHERE a.id_order=:order AND a.id_owner=:owner ORDER BY a.id');$this->bind(['order'=>$orderId,'owner'=>$this->ownerId]);return array_map(fn($s)=>['id'=>(int)$s->id,'name'=>$s->name,'price'=>(float)$s->price,'description'=>$s->description,'quantity'=>(int)$s->quantity],$this->db->fetchAll());}
    private function resolveAssignedServices(int $orderId,array $names): array {$assigned=$this->assignedServices($orderId);return array_values(array_filter($assigned,function(array $service)use($names){foreach($names as $name)if($this->matchesServiceName((string)$service['name'],(string)$name))return true;return false;}));}
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
    private function selectedServiceSuggestion(array $draft,string $message): ?string
    {
        $suggestions=(array)($draft['service_suggestions']??[]);if(!$suggestions)return null;$plain=mb_strtolower(trim($message));
        $ordinals=['primero'=>0,'primera'=>0,'1'=>0,'segundo'=>1,'segunda'=>1,'2'=>1,'tercero'=>2,'tercera'=>2,'3'=>2];
        foreach($ordinals as $word=>$index)if(preg_match('/\b'.preg_quote($word,'/').'\b/u',$plain)&&isset($suggestions[$index]))return $this->candidateField($suggestions[$index],'name');
        foreach($suggestions as $candidate){$name=$this->candidateField($candidate,'name');if($this->serviceSimilarity($plain,$name)>=0.72)return $name;}
        return null;
    }
    private function serviceSuggestionItems(array $draft): array
    {
        return array_map(fn($service)=>['title'=>$this->candidateField($service,'name'),'meta'=>'Servicio parecido · $'.number_format((float)$this->candidateField($service,'price'),2),'detail'=>'Escribe el nombre o “el primero/segundo/tercero”'],(array)($draft['service_suggestions']??[]));
    }
    private function missingReply(array $draft,array $missing): string
    {
        $pending=(string)($draft['pending_service']??'');$suggestions=(array)($draft['service_suggestions']??[]);
        if($pending!==''&&$suggestions){$names=array_map(fn($service)=>$this->candidateField($service,'name'),$suggestions);return 'No encontré “'.$pending.'” exactamente. ¿Quisiste decir '.implode(', ',array_slice($names,0,-1)).(count($names)>1?' o ':'').end($names).'? Puedes responder con el nombre o “el primero”.';}
        if($pending!=='')return 'No encontré “'.$pending.'” en el catálogo. Si es un servicio nuevo, envíame en una sola respuesta: nombre, descripción breve y precio. Lo crearé como precio variable para este estimate.';
        return 'Ya tengo el resto. Solo necesito '.implode(', ',$missing).'. Puedes enviarlo todo en una sola respuesta.';
    }
    private function candidateField(mixed $candidate,string $field): string {return (string)(is_array($candidate)?($candidate[$field]??''):($candidate->$field??''));}
    private function isStarterOnly(string $message,string $mode): bool {$plain=mb_strtolower(trim(preg_replace('/[^\pL\pN\s]+/u',' ',$message)??$message));$plain=preg_replace('/\s+/',' ',$plain)??$plain;$starters=$mode==='CREATE'?['create an estimate','create estimate','create another estimate','crear un estimate','crear estimate','crear otro estimate','crear un estimado','nuevo estimate','new estimate','necesito crear un estimate','i need to create an estimate']:['modify an estimate','modify estimate','modificar un estimate','modificar un estimate existente','necesito modificar un estimate existente','editar estimate','actualizar estimate','i need to modify an existing estimate'];return in_array($plain,$starters,true);}
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
