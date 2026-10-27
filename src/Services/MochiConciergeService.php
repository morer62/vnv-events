<?php
namespace App\Services;

use App\Repositories\Connection;

final class MochiConciergeService
{
    private const POLICY='You are Mochi, the internal VNV Events operations assistant. Answer in concise, warm Spanish. Discuss only VNV Events. Treat supplied tool results as the sole source of live facts. Never invent counts, prices, assignments, availability, emotions or promises. Never expose card data, credentials, private notes or data outside the user scope. Rewards cannot be used for tips. Prefer a direct answer and preserve provided links. If data is unavailable, say so plainly. Light humor is occasional, never distracting.';
    private MochiSessionService $sessions;
    private MochiOperationalToolsService $tools;
    public function __construct(private int $ownerId=2,private string $siteKey='vnvevents',private ?Connection $db=null){$this->db??=new Connection();$this->sessions=new MochiSessionService($ownerId,$siteKey,$this->db);$this->tools=new MochiOperationalToolsService($ownerId,$siteKey,$this->db);}

    public function open(int $userId,int $level,?int $sessionId=null): array {$session=$sessionId?$this->sessions->get($sessionId,$userId):$this->sessions->today($userId);$estimate=(new MochiEstimateWorkflowService($this->ownerId,$this->siteKey,$this->db))->active($userId,(int)$session->id);$service=(new MochiServiceCatalogWorkflowService($this->ownerId,$this->siteKey,$this->db))->active($userId,(int)$session->id);return ['session'=>$session,'messages'=>$this->sessions->messages((int)$session->id,$userId),'history'=>$this->sessions->history($userId),'suggestions'=>$this->tools->suggestions($level),'estimate_workflow'=>$estimate?['id'=>(int)$estimate->id,'mode'=>$estimate->mode,'status'=>$estimate->status,'current_estimate_id'=>$estimate->current_estimate_id? (int)$estimate->current_estimate_id:null]:null,'service_workflow'=>$service?['id'=>(int)$service->id,'status'=>$service->status,'current_service_id'=>$service->current_service_id? (int)$service->current_service_id:null,'current_product_id'=>$service->current_product_id? (int)$service->current_product_id:null]:null];}
    public function openLatest(int $userId,int $level): array {$session=$this->sessions->latest($userId);return $this->open($userId,$level,(int)$session->id);}
    public function openDate(int $userId,int $level,string $date): array {$session=$this->sessions->today($userId,$date);return $this->open($userId,$level,(int)$session->id);}
    public function newChat(int $userId,int $level): array {$session=$this->sessions->today($userId);return $this->open($userId,$level,(int)$session->id);}
    public function ask(int $authorId,int $level,string $message,?int $sessionId=null): array
    {
        $message=trim($message);if($message==='')throw new \InvalidArgumentException('Escribe un mensaje o adjunta una captura para Mochi.');if(mb_strlen($message)>6000)throw new \InvalidArgumentException('El mensaje y la captura contienen demasiado texto.');
        $session=$sessionId?$this->sessions->get($sessionId,$authorId):$this->sessions->today($authorId);$sessionId=(int)$session->id;
        // Read the existing daily thread before inserting the new turn. The router must see
        // what "eso", "el anterior" and similar follow-ups refer to.
        $recentMessages=$this->sessions->messages($sessionId,$authorId,120);
        $this->save($sessionId,$authorId,'HUMAN',$message);
        if($this->isGreeting($message)){$reply='¡Hola! Claro que sí 😊 Soy Mochi y estoy aquí para ayudarte con VNV Events. Puedo revisar eventos, estimates, clientes, pagos pendientes, servicios, productos y tareas. Cuéntame qué necesitas y lo resolvemos juntos.';$this->save($sessionId,null,'AGENT',$reply,['tool'=>'greeting','items'=>[]]);$this->sessions->touch($sessionId);return ['reply'=>$reply,'items'=>[],'tool'=>'greeting','session_id'=>$sessionId];}
        if($this->isEditorialCreationIntent($message)){
            $reply='Este Mochi opera clientes, eventos, servicios y estimates; no crea ni publica artículos. Usa Growth Hub para contenido editorial. No ejecuté ninguna acción.';
            $this->save($sessionId,null,'AGENT',$reply,['tool'=>'scope_guard','items'=>[]]);$this->sessions->touch($sessionId);
            return ['reply'=>$reply,'items'=>[],'tool'=>'scope_guard','session_id'=>$sessionId];
        }
        $estimateWorkflow=new MochiEstimateWorkflowService($this->ownerId,$this->siteKey,$this->db);$serviceWorkflow=new MochiServiceCatalogWorkflowService($this->ownerId,$this->siteKey,$this->db);
        $activeEstimate=$estimateWorkflow->active($authorId,$sessionId);$activeService=$serviceWorkflow->active($authorId,$sessionId);
        $activeTasks=[
            'estimate'=>$activeEstimate?['active'=>true,'mode'=>(string)$activeEstimate->mode,'status'=>(string)$activeEstimate->status,'current_estimate_id'=>$activeEstimate->current_estimate_id? (int)$activeEstimate->current_estimate_id:null]:['active'=>false],
            'service'=>$activeService?['active'=>true,'status'=>(string)$activeService->status,'current_service_id'=>$activeService->current_service_id? (int)$activeService->current_service_id:null,'current_product_id'=>$activeService->current_product_id? (int)$activeService->current_product_id:null]:['active'=>false],
        ];
        try{$decision=(new MochiConversationRouterService())->decide($message,$recentMessages,$activeTasks,$level);}
        catch(\Throwable $e){error_log('[Mochi context router] '.$e->getMessage());$decision=$this->fallbackDecision($message,$recentMessages,$activeEstimate,$activeService,$level);}
        $route=(string)$decision['route'];$resolvedMessage=(string)$decision['resolved_message'];$routerMetadata=['route'=>$route,'confidence'=>(float)$decision['confidence'],'resolved_message'=>$resolvedMessage];

        if($route==='clarify'){
            $reply=(string)$decision['clarification'];$metadata=['tool'=>'clarify','items'=>[],'router'=>$routerMetadata];
            $this->save($sessionId,null,'AGENT',$reply,$metadata);$this->sessions->touch($sessionId);
            return ['reply'=>$reply,'items'=>[],'tool'=>'clarify','session_id'=>$sessionId];
        }
        if($route==='service_workflow'){
            try{$result=$serviceWorkflow->handle($authorId,$level,$sessionId,$resolvedMessage);$metadata=['tool'=>'service_catalog_workflow','items'=>$result['items']??[],'service_workflow'=>$result['service_workflow']??null,'router'=>$routerMetadata];$this->save($sessionId,null,'AGENT',(string)$result['reply'],$metadata);$this->sessions->touch($sessionId);return ['reply'=>$result['reply'],'items'=>$metadata['items'],'service_workflow'=>$metadata['service_workflow'],'tool'=>'service_catalog_workflow','session_id'=>$sessionId];}
            catch(\Throwable $e){error_log('[Mochi service workflow] '.$e->getMessage());$reply='No pude completar este paso con seguridad. Conservé el borrador del servicio y no apliqué cambios. Detalle: '.$e->getMessage();$this->save($sessionId,null,'AGENT',$reply,['tool'=>'service_catalog_workflow','error'=>true,'error_detail'=>$e->getMessage(),'router'=>$routerMetadata]);$this->sessions->touch($sessionId);return ['reply'=>$reply,'items'=>[],'tool'=>'service_catalog_workflow','session_id'=>$sessionId];}
        }
        if($route==='estimate_workflow'){
            try{$result=$estimateWorkflow->handle($authorId,$level,$sessionId,$resolvedMessage);$metadata=['tool'=>'estimate_workflow','items'=>$result['items']??[],'estimate_workflow'=>$result['estimate_workflow']??null,'copy_url'=>$result['copy_url']??null,'router'=>$routerMetadata];$this->save($sessionId,null,'AGENT',(string)$result['reply'],$metadata);$this->sessions->touch($sessionId);return ['reply'=>$result['reply'],'items'=>$metadata['items'],'estimate_workflow'=>$metadata['estimate_workflow'],'copy_url'=>$metadata['copy_url'],'tool'=>'estimate_workflow','session_id'=>$sessionId];}
            catch(\Throwable $e){error_log('[Mochi estimate workflow] '.$e->getMessage());$reply='No pude completar este paso con seguridad. Conservé el borrador y no creé ni modifiqué el estimate. Detalle: '.$e->getMessage();$this->save($sessionId,null,'AGENT',$reply,['tool'=>'estimate_workflow','items'=>[],'error'=>true,'error_detail'=>$e->getMessage(),'router'=>$routerMetadata]);$this->sessions->touch($sessionId);return ['reply'=>$reply,'items'=>[],'tool'=>'estimate_workflow','session_id'=>$sessionId,'error_detail'=>$e->getMessage()];}
        }
        if($route==='write_action'){
            $actions=new MochiActionService($this->ownerId,$this->siteKey,$this->db);
            if(!$actions->detects($resolvedMessage)){$reply='Entendí que quieres cambiar información, pero no identifiqué con seguridad qué registro ni qué cambio aplicar. Dime exactamente qué deseas crear o modificar.';$this->save($sessionId,null,'AGENT',$reply,['tool'=>'clarify','items'=>[],'router'=>$routerMetadata]);$this->sessions->touch($sessionId);return ['reply'=>$reply,'items'=>[],'tool'=>'clarify','session_id'=>$sessionId];}
            $result=$actions->prepare($authorId,$level,$sessionId,$resolvedMessage);$metadata=['tool'=>'write_action_prepare','items'=>$result['items']??[],'action_draft'=>$result['action_draft']??null,'router'=>$routerMetadata];$this->save($sessionId,null,'AGENT',(string)$result['reply'],$metadata);$this->sessions->touch($sessionId);return ['reply'=>$result['reply'],'items'=>$metadata['items'],'action_draft'=>$metadata['action_draft'],'tool'=>'write_action_prepare','session_id'=>$sessionId];
        }
        $tool=$route;
        try{$result=$this->tools->run($tool,$authorId,$level,$sessionId,$resolvedMessage);$reply=(string)$result['reply'];$items=$result['items']??[];
            $facts=$result['facts']??[];
            try{$natural=(new MochiConversationResponderService())->compose($resolvedMessage,$tool,['summary'=>$reply,'facts'=>$facts,'items'=>$items],$recentMessages);if($natural!=='')$reply=$natural;}catch(\Throwable $e){error_log('[Mochi responder] '.$e->getMessage());}
            $metadata=['tool'=>$tool,'items'=>$items,'facts'=>$facts,'router'=>$routerMetadata];
        }catch(\Throwable $e){$reply=$e->getMessage();$metadata=['tool'=>$tool,'items'=>[],'error'=>true,'router'=>$routerMetadata];}
        $this->save($sessionId,null,'AGENT',$reply,$metadata);$this->sessions->touch($sessionId);return ['reply'=>$reply,'items'=>$metadata['items'],'tool'=>$tool,'session_id'=>$sessionId];
    }

    /** Safe degradation if the context model is unavailable: preserve prior tool context, never guess an active estimate for a question. */
    private function fallbackDecision(string $message,array $recentMessages,?object $activeEstimate,?object $activeService,int $level): array
    {
        $previousTool='';
        foreach(array_reverse($recentMessages) as $row){if(($row->author_type??'')!=='AGENT')continue;$metadata=json_decode((string)($row->metadata_json??''),true)?:[];$previousTool=(string)($metadata['tool']??'');if($previousTool!=='')break;}
        if(preg_match('/^(?:y\s+)?(?:cu[aá]nto|cu[aá]ntos|cu[aá]l|eso|el anterior|contin[uú]a)\b/iu',$message)&&in_array($previousTool,['executed_events','weekend_events','collections','opportunities','requests','contracts','catalog','warehouse','rewards','website'],true))return ['route'=>$previousTool,'resolved_message'=>$message,'confidence'=>0.65,'clarification'=>''];
        if(preg_match('/\b(eventos?|contratos?)\b.{0,100}\b(ejecutad[oa]s?|realizad[oa]s?|completad[oa]s?|gener[oó]|factur[oó]|produjo|ingresos?)\b|\b(cu[aá]nto dinero|ingresos?|facturaci[oó]n)\b.{0,80}\b(eventos?|contratos?)\b/iu',$message))return ['route'=>'executed_events','resolved_message'=>$message,'confidence'=>0.8,'clarification'=>''];
        if(preg_match('/\b(estimate|estimado|cotizaci[oó]n)\b/iu',$message))return ['route'=>'estimate_workflow','resolved_message'=>$message,'confidence'=>0.8,'clarification'=>''];
        if(preg_match('/\b(crear|agregar|modificar|editar|actualizar)\b.{0,80}\b(servicio|service)\b/iu',$message))return ['route'=>'service_workflow','resolved_message'=>$message,'confidence'=>0.8,'clarification'=>''];
        if(($activeEstimate||$activeService)&&preg_match('/[?¿]|\b(cu[aá]nto|cu[aá]ntos|cu[aá]l|dime|ind[ií]came|mu[eé]strame|por qu[eé])\b/iu',$message))return ['route'=>'clarify','resolved_message'=>$message,'confidence'=>0,'clarification'=>'Tengo una tarea anterior abierta, pero esta pregunta parece ser sobre otro tema. ¿A qué información te refieres exactamente?'];
        if($activeService)return ['route'=>'service_workflow','resolved_message'=>$message,'confidence'=>0.6,'clarification'=>''];
        if($activeEstimate)return ['route'=>'estimate_workflow','resolved_message'=>$message,'confidence'=>0.6,'clarification'=>''];
        $tool=$this->tools->select($message,$level);if($tool!=='website')return ['route'=>$tool,'resolved_message'=>$message,'confidence'=>0.6,'clarification'=>''];
        return ['route'=>'clarify','resolved_message'=>$message,'confidence'=>0,'clarification'=>'No pude determinar con seguridad qué información necesitas. ¿Puedes indicarme el tema o el período?'];
    }
    public function confirmAction(int $authorId,int $level,int $sessionId,int $draftId): array {$session=$this->sessions->get($sessionId,$authorId);$result=(new MochiActionService($this->ownerId,$this->siteKey,$this->db))->execute($draftId,$authorId,$level);$metadata=['tool'=>'write_action_execute','items'=>[['title'=>ucfirst(str_replace('_',' ',$result['entity_type'])).' #'.$result['entity_id'],'meta'=>$result['details'],'detail'=>'Creado por Mochi y auditado','url'=>$result['url']]]];$this->save((int)$session->id,null,'AGENT',$result['reply'],$metadata);$this->sessions->touch((int)$session->id);return ['reply'=>$result['reply'],'items'=>$metadata['items'],'session_id'=>(int)$session->id];}
    public function cancelAction(int $authorId,int $sessionId,int $draftId): array {$this->sessions->get($sessionId,$authorId);(new MochiActionService($this->ownerId,$this->siteKey,$this->db))->cancel($draftId,$authorId);$reply='Acción cancelada. No se aplicaron cambios.';$this->save($sessionId,null,'AGENT',$reply,['tool'=>'write_action_cancel','items'=>[]]);return ['reply'=>$reply,'items'=>[],'session_id'=>$sessionId];}
    public function recent(int $authorId,int $limit=60): array {$session=$this->sessions->today($authorId);return $this->sessions->messages((int)$session->id,$authorId,$limit);}
    private function isGreeting(string $message): bool {$plain=mb_strtolower(trim(preg_replace('/[^\pL\pN\s¿?¡!]+/u',' ',$message)??$message));$plain=preg_replace('/\s+/u',' ',$plain)??$plain;return (bool)preg_match('/^(hola|buenos d[ií]as|buenas tardes|buenas noches|hey|hello)(\s+mochi)?([\s¿?¡!]*(puedes|podr[ií]as|me puedes|me podr[ií]as)?\s*(ayudar|ayudarme|orientar|orientarme))?[\s¿?¡!]*$/iu',$plain)||preg_match('/^(puedes|podr[ií]as|me puedes|me podr[ií]as)\s+(ayudar|ayudarme|orientar|orientarme)[\s¿?¡!]*$/iu',$plain);}
    private function isEditorialCreationIntent(string $message): bool {return (bool)preg_match('/\b(crea|crear|escribe|redacta|publica|publicar|genera|generate|write|publish)\b.{0,80}\b(art[ií]culo|blog|blog post|post editorial|contenido editorial)\b|\b(art[ií]culo|blog|blog post|post editorial|contenido editorial)\b.{0,80}\b(crea|crear|escribe|redacta|publica|publicar|genera|generate|write|publish)\b/iu',$message);}
    private function save(int $sessionId,?int $author,string $type,string $message,array $metadata=[]): void {$this->db->query("INSERT INTO automation_conversations(id_owner,site_key,thread_key,id_mochi_session,id_author,author_type,message,metadata_json) VALUES(:owner,:site,:thread,:session,:author,:type,:message,:metadata)");foreach(['owner'=>$this->ownerId,'site'=>$this->siteKey,'thread'=>'mochi:session:'.$sessionId,'session'=>$sessionId,'author'=>$author,'type'=>$type,'message'=>$message,'metadata'=>$metadata?json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null] as $k=>$v)$this->db->bind(':'.$k,$v);$this->db->execute();}
}
