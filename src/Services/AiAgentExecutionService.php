<?php
namespace App\Services;

use App\Repositories\AiAgentsRepository;
use App\Repositories\Connection;
use RuntimeException;

final class AiAgentExecutionService
{
    public function __construct(private AiAgentsRepository $repository){}

    public function run(object $agent,int $ownerId,int $userId,string $trigger='MANUAL',array $input=[]): array
    {
        $runId=$this->repository->createRun((int)$agent->id,$ownerId,$trigger,$userId,$input);
        try{
            $output=match((string)$agent->agent_key){
                'estimate_follow_up'=>$this->estimateFollowUp($ownerId),
                'order_auditor'=>$this->orderAudit($ownerId),
                'content_refresh'=>$this->contentRefresh($ownerId),
                'operations_risk'=>$this->operationsRisk($ownerId),
                'event_brief'=>$this->eventBrief($ownerId,$input),
                'social_publisher'=>$this->socialPublisher($ownerId,$input),
                'instagram_carousel'=>$this->instagramCarousel($ownerId,$input),
                'short_video'=>$this->shortVideo($ownerId,$input),
                'meta_lead_estimator'=>$this->metaLeadEstimator($ownerId,$input),
                'lead_qualification'=>$this->leadQualification($ownerId),
                'post_event'=>$this->postEvent($ownerId,$input),
                'reputation'=>$this->reputation($ownerId),
                'client_concierge'=>$this->clientConcierge($ownerId,$input),
                'blog_writer'=>$this->blogOptimizer($ownerId,$input),
                default=>throw new RuntimeException('This agent uses its dedicated module instead of a generic run.'),
            };
            $proposals=$output['proposed_actions']??[];
            $count=0;foreach(array_slice($proposals,0,20) as $action){$this->repository->createApproval($runId,(int)$agent->id,$ownerId,(string)($action['type']??'REVIEW'),(string)($action['title']??'Review proposed action'),$action,$userId);$count++;}
            if(count($proposals)>20)$output['approval_notice']='Only the first 20 priority actions were queued; run the agent again after reviewing them.';
            $output['approval_count']=$count;$status=$count?'AWAITING_APPROVAL':'COMPLETED';
            $this->repository->finishRun($runId,$status,$output);$this->repository->touchAgent((int)$agent->id,'ACTIVE');
            return ['run_id'=>$runId,'status'=>$status,'output'=>$output];
        }catch(\Throwable $e){$this->repository->finishRun($runId,'FAILED',[],$e->getMessage());throw $e;}
    }

    private function rows(string $sql,int $ownerId,array $bindings=[]): array
    {
        $db=new Connection();$db->query($sql);$db->bind(':owner',$ownerId);foreach($bindings as $key=>$value)$db->bind(':'.$key,$value);return $db->fetchAll();
    }
    private function one(string $sql,int $ownerId,array $bindings=[]): ?object
    {
        $rows=$this->rows($sql,$ownerId,$bindings);return $rows[0]??null;
    }
    private function content(int $ownerId,int $id): object
    {
        if($id<=0)throw new RuntimeException('Select CMS content first.');
        $item=$this->one("SELECT id,title,excerpt,body_html,meta_title,meta_description,schema_json,featured_image_url,status FROM cms_contents WHERE id=:id AND id_owner=:owner LIMIT 1",$ownerId,['id'=>$id]);
        if(!$item)throw new RuntimeException('CMS content not found.');return $item;
    }

    private function estimateFollowUp(int $ownerId): array
    {
        $rows=$this->rows("SELECT o.id,o.event_date,o.status_workflow,o.created_at,CONCAT(u.name,' ',u.lastname) AS client_name,u.email,DATEDIFF(o.event_date,CURDATE()) AS days_to_event,DATEDIFF(CURDATE(),DATE(o.created_at)) AS days_open FROM orders o LEFT JOIN users u ON u.id=o.id_client WHERE o.id_owner=:owner AND o.is_archived=0 AND o.status_workflow IN ('INVOICE_DRAFT','INVOICE_READY','INVOICE_PARTIAL') ORDER BY days_open DESC LIMIT 100",$ownerId);$actions=[];
        foreach($rows as $row){$days=(int)$row->days_to_event;$age=(int)$row->days_open;$priority=$days<=7||$age>=7?'URGENT':($age>=2||$days<=30?'FOLLOW_UP':'WAIT');if($priority==='WAIT')continue;$actions[]=['type'=>'SEND_FOLLOW_UP','title'=>"{$row->client_name} has waited {$age} day(s) — write now?",'order_id'=>(int)$row->id,'client_email'=>(string)$row->email,'event_date'=>(string)$row->event_date,'days_open'=>$age,'days_to_event'=>$days,'priority'=>$priority,'draft_message'=>"Hello {$row->client_name}, we are following up regarding your VNV Events estimate for {$row->event_date}. Please let us know if you have any questions or would like to move forward."];}
        return ['summary'=>count($rows).' open estimates reviewed.','items'=>$rows,'proposed_actions'=>$actions];
    }
    private function orderAudit(int $ownerId): array
    {
        $rows=$this->rows("SELECT o.id,o.event_date,o.status_workflow,(o.id_contract IS NULL) AS missing_contract,NOT EXISTS(SELECT 1 FROM document_logs d WHERE d.id_order=o.id AND d.doc_type='contract_signed') AS unsigned_contract,NOT EXISTS(SELECT 1 FROM orders_services_assigned s WHERE s.id_order=o.id) AS missing_services FROM orders o WHERE o.id_owner=:owner AND o.is_archived=0 AND o.event_date>=CURDATE() ORDER BY o.event_date LIMIT 100",$ownerId);$issues=array_values(array_filter($rows,fn($r)=>$r->missing_contract||$r->unsigned_contract||$r->missing_services));$actions=[];
        foreach($issues as $r)$actions[]=['type'=>'REVIEW_ORDER_ISSUES','title'=>'Review missing requirements for order #'.$r->id,'order_id'=>(int)$r->id,'missing_contract'=>(bool)$r->missing_contract,'unsigned_contract'=>(bool)$r->unsigned_contract,'missing_services'=>(bool)$r->missing_services];
        return ['summary'=>count($issues).' upcoming orders need review.','items'=>$issues,'proposed_actions'=>$actions];
    }
    private function contentRefresh(int $ownerId): array
    {
        $rows=$this->rows("SELECT id,title,content_type,status,updated_at,(meta_description IS NULL OR TRIM(meta_description)='') AS missing_meta,(schema_json IS NULL OR TRIM(schema_json)='') AS missing_schema,(featured_image_url IS NULL OR TRIM(featured_image_url)='') AS missing_image FROM cms_contents WHERE id_owner=:owner AND status IN ('PUBLISHED','GENERATED') AND ((meta_description IS NULL OR TRIM(meta_description)='') OR (schema_json IS NULL OR TRIM(schema_json)='') OR (featured_image_url IS NULL OR TRIM(featured_image_url)='')) ORDER BY updated_at LIMIT 100",$ownerId);$actions=[];
        foreach($rows as $r)$actions[]=['type'=>'REVIEW_CONTENT_REFRESH','title'=>'Refresh content: '.$r->title,'content_id'=>(int)$r->id,'missing_meta'=>(bool)$r->missing_meta,'missing_schema'=>(bool)$r->missing_schema,'missing_image'=>(bool)$r->missing_image];
        return ['summary'=>count($rows).' content items need refresh.','items'=>$rows,'proposed_actions'=>$actions];
    }
    private function operationsRisk(int $ownerId): array
    {
        $rows=$this->rows("SELECT o.id,o.event_date,o.address,o.total_team_needed,o.status_workflow,DATEDIFF(o.event_date,CURDATE()) AS days_to_event,(SELECT COUNT(*) FROM orders_staff_invites i WHERE i.id_order=o.id AND i.is_confirmed=1) AS assigned_team FROM orders o WHERE o.id_owner=:owner AND o.is_archived=0 AND o.event_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY) ORDER BY o.event_date LIMIT 100",$ownerId);$risks=array_values(array_filter($rows,fn($r)=>(int)$r->assigned_team<(int)$r->total_team_needed||$r->status_workflow!=='INVOICE_PAID'));$actions=[];
        foreach($risks as $r)$actions[]=['type'=>'REVIEW_OPERATIONAL_RISK','title'=>'Operational risk: order #'.$r->id,'order_id'=>(int)$r->id,'event_date'=>$r->event_date,'assigned_team'=>(int)$r->assigned_team,'team_needed'=>(int)$r->total_team_needed,'workflow'=>$r->status_workflow];
        return ['summary'=>count($risks).' events have operational risks.','items'=>$risks,'proposed_actions'=>$actions];
    }
    private function eventBrief(int $ownerId,array $input): array
    {
        $id=(int)($input['order_id']??0);if($id<=0)throw new RuntimeException('Select an order.');$order=$this->one("SELECT o.*,CONCAT(u.name,' ',u.lastname) AS client_name,u.email FROM orders o LEFT JOIN users u ON u.id=o.id_client WHERE o.id=:id AND o.id_owner=:owner LIMIT 1",$ownerId,['id'=>$id]);if(!$order)throw new RuntimeException('Order not found.');
        $brief=['type'=>'REVIEW_EVENT_BRIEF','title'=>'Review event brief for order #'.$id,'order_id'=>$id,'client'=>$order->client_name,'event_date'=>$order->event_date,'time'=>$order->start_time.'–'.$order->end_time,'address'=>$order->address,'notes'=>$order->notes,'checklist'=>['Confirm team','Confirm equipment','Confirm client timeline','Confirm final payment']];
        return ['summary'=>'Event brief prepared.','items'=>[$brief],'proposed_actions'=>[$brief]];
    }
    private function socialPublisher(int $ownerId,array $input): array
    {
        $networks=array_values(array_intersect(['facebook','instagram','linkedin','youtube'],(array)($input['networks']??[])));if(!$networks)throw new RuntimeException('Select at least one social network.');
        $sourceKind=(string)($input['source_kind']??'content');$source=null;$sourceTitle='';
        if($sourceKind==='video'){$id=(int)($input['media_job_id']??0);$source=$this->one("SELECT id,title,transcript_text,output_url,edit_plan_json FROM ai_agent_media_jobs WHERE id=:id AND id_owner=:owner AND status='COMPLETED'",$ownerId,['id'=>$id]);if(!$source)throw new RuntimeException('Select a completed video project.');$sourceTitle=(string)$source->title;}
        elseif($sourceKind==='trend'){$source=(new AiTrendService())->currentVideoSignals();$sourceTitle='current video signals';}
        else{$source=$this->content($ownerId,(int)($input['content_id']??0));$sourceTitle=(string)$source->title;}
        $shape=[];foreach($networks as $network)$shape[$network]=['copy'=>'','hashtags'=>[],'youtube_title'=>''];
        $draft=(new AiJsonGenerator())->generate('Select the strongest useful extract from the source and adapt it only for the selected networks. Do not copy third-party titles or wording; use trends only as format signals.',['selected_networks'=>$networks,'source_kind'=>$sourceKind,'source'=>$source],$shape);$actions=[];
        foreach($networks as $p)$actions[]=['type'=>'PUBLISH_SOCIAL','title'=>'Review '.ucfirst($p).' post: '.$sourceTitle,'content_id'=>$sourceKind==='content'?(int)$source->id:null,'media_job_id'=>$sourceKind==='video'?(int)$source->id:null,'platform'=>$p,'copy'=>(string)($draft[$p]['copy']??''),'hashtags'=>$draft[$p]['hashtags']??[],'youtube_title'=>(string)($draft[$p]['youtube_title']??''),'image_url'=>$sourceKind==='content'?(string)$source->featured_image_url:'','video_url'=>$sourceKind==='video'?(string)$source->output_url:''];
        return ['summary'=>'Social drafts prepared.','items'=>[$draft],'proposed_actions'=>$actions];
    }
    private function blogOptimizer(int $ownerId,array $input): array
    {
        $c=$this->content($ownerId,(int)($input['content_id']??0));$provider=in_array($input['provider']??'', ['openai','anthropic','gemini'],true)?$input['provider']:'openai';$instructions=trim((string)($input['instructions']??''));
        $draft=(new AiModelGateway())->json($ownerId,$provider,'You are a senior VNV Events editor. Improve the article without changing verified facts. Strengthen clarity, SEO, useful structure and distinct image prompts. Do not publish.',['article'=>$c,'reviewer_instructions'=>$instructions],['title'=>'','excerpt'=>'','body'=>'','meta_title'=>'','meta_description'=>'','thumbnail_prompt'=>'','supporting_image_prompts'=>[]]);
        if(!empty($input['regenerate_image'])){$imageProvider=in_array($input['image_provider']??'', ['openai','gemini'],true)?$input['image_provider']:'gemini';$draft['generated_image']=(new AiProviderImageService())->generate($ownerId,$imageProvider,(string)$draft['thumbnail_prompt'],'16:9');$draft['featured_image_url']=$draft['generated_image']['url'];}
        return ['summary'=>'Article optimization prepared with '.ucfirst($provider).'.','items'=>[$draft],'proposed_actions'=>[['type'=>'PUBLISH_ARTICLE','title'=>'Review optimized article: '.$c->title,'content_id'=>(int)$c->id,'provider'=>$provider]+$draft]];
    }
    private function instagramCarousel(int $ownerId,array $input): array
    {
        $c=$this->content($ownerId,(int)($input['content_id']??0));$selected=array_values(array_filter((array)($input['selected_slides']??[]),fn($slide)=>is_array($slide)&&trim((string)($slide['text']??''))!==''));
        if(!$selected)throw new RuntimeException('Select at least one article passage. The AI will not choose the creative content for you.');
        usort($selected,fn($a,$b)=>(int)($a['page']??0)<=>(int)($b['page']??0));$format=(string)($input['creative_format']??'carousel-square');$single=str_starts_with($format,'single-');
        $d=(new AiJsonGenerator())->generate('Create publication-ready VNV social creative copy from the human-selected excerpts. Preserve their meaning and order. Do not choose different source passages. Return one slide for a single creative, otherwise one slide per selected page.',['content_title'=>$c->title,'human_selected_pages'=>$selected,'format'=>$format,'concept'=>$input['concept']??'','reference_images_or_models'=>$input['reference_urls']??''],['cover_title'=>'','slides'=>[['headline'=>'','body'=>'','visual_prompt'=>'']],'caption'=>'','hashtags'=>[]]);
        if($single){$d['slides']=array_slice((array)($d['slides']??[]),0,1);$d['slides'][0]['source_text']=implode("\n\n",array_column($selected,'text'));$d['slides'][0]['page']=1;}
        else{foreach($selected as $index=>$slide){$d['slides'][$index]['source_text']=$slide['text'];$d['slides'][$index]['page']=$slide['page'];}$d['slides']=array_slice((array)$d['slides'],0,count($selected));}
        if(!empty($input['include_link'])&&trim((string)($input['caption_link']??''))!=='')$d['caption']=trim((string)($d['caption']??''))."\n\n".trim((string)$input['caption_link']);
        $type=$single?'PUBLISH_SOCIAL_CREATIVE':'PUBLISH_CAROUSEL';return ['summary'=>($single?'Single creative':'Carousel').' prepared from human selections.','items'=>[$d],'proposed_actions'=>[['type'=>$type,'title'=>'Review '.($single?'social creative':'carousel').': '.$c->title,'content_id'=>(int)$c->id,'format'=>$format,'platforms'=>(array)($input['networks']??['instagram']),'caption'=>(string)($d['caption']??''),'slides'=>$d['slides']??[],'image_url'=>'','hashtags'=>$d['hashtags']??[],'image_generation'=>['requested'=>!empty($input['generate_images']),'status'=>!empty($input['generate_images'])?'QUEUED':'NOT_REQUESTED','provider'=>'openai','reference_direction'=>(string)($input['reference_urls']??'')]]]];
    }
    private function shortVideo(int $ownerId,array $input=[]): array
    {
        $selected=(int)($input['media_job_id']??0);if($selected<=0)throw new RuntimeException('Select a completed video project.');
        $item=$this->one("SELECT id,title,status,output_url,transcript_text,subtitles_srt,edit_plan_json,created_at FROM ai_agent_media_jobs WHERE id=:id AND id_owner=:owner AND status='COMPLETED' AND output_url IS NOT NULL",$ownerId,['id'=>$selected]);
        if(!$item)throw new RuntimeException('Select a completed video project with a final master.');
        $duration=max(15,min(60,(int)($input['target_duration']??30)));$captionStyle=in_array($input['caption_style']??'', ['kinetic','dynamic','clean'],true)?$input['caption_style']:'kinetic';
        $plan=(new AiJsonGenerator())->generate(
            'Create one high-retention vertical short-video plan from the completed VNV master. Select a coherent excerpt no longer than the target duration. Do not reuse burned-in/native subtitle styling. Create a new social caption script from the spoken transcript, with concise timed phrases and emphasis words. Return planning data only; publication still requires approval.',
            ['title'=>$item->title,'transcript'=>$item->transcript_text,'existing_edit_plan'=>$item->edit_plan_json,'instructions'=>trim((string)($input['reel_instructions']??'')),'target_duration_seconds'=>$duration,'caption_style'=>$captionStyle],
            ['hook'=>'','start_seconds'=>0,'end_seconds'=>$duration,'caption_style'=>$captionStyle,'caption_blocks'=>[['start_seconds'=>0,'end_seconds'=>0,'text'=>'','emphasis_words'=>[]]],'cuts'=>[],'camera_moves'=>[],'transitions'=>[],'post_copy'=>'','hashtags'=>[]]
        );
        $action=['type'=>'REVIEW_SHORT_VIDEO','title'=>'Review short reel: '.$item->title,'media_job_id'=>(int)$item->id,'output_url'=>(string)$item->output_url,'video_studio_url'=>'/panel/growth-hub/video-studio?project='.(int)$item->id,'remove_native_subtitles'=>true,'target_duration'=>$duration,'caption_style'=>$captionStyle,'reel_plan'=>$plan];
        return ['summary'=>'Short reel plan prepared from completed project #'.$item->id.'.','items'=>[$plan],'proposed_actions'=>[$action]];
    }
    private function metaLeadEstimator(int $ownerId,array $input): array
    {
        $id=(int)($input['lead_id']??0);if($id<=0)throw new RuntimeException('Select a CRM lead.');$lead=$this->one("SELECT * FROM crm_leads WHERE id=:id AND id_owner=:owner LIMIT 1",$ownerId,['id'=>$id]);if(!$lead)throw new RuntimeException('CRM lead not found.');$messages=$this->rows("SELECT direction,message,created_at FROM crm_whatsapp_messages WHERE id_lead=:id AND id_owner=:owner ORDER BY created_at LIMIT 100",$ownerId,['id'=>$id]);
        $d=(new AiJsonGenerator())->generate('Extract an estimate brief from an authorized CRM conversation. Mark missing facts.',['lead'=>$lead,'messages'=>$messages],['event_date'=>null,'guest_count'=>null,'location'=>null,'requested_services'=>[],'budget'=>null,'missing_information'=>[],'summary'=>'','draft_reply'=>'']);return ['summary'=>'Estimate draft prepared.','items'=>[$d],'proposed_actions'=>[['type'=>'CREATE_ESTIMATE_DRAFT','title'=>'Review estimate draft for '.$lead->name,'lead_id'=>$id,'draft_message'=>(string)($d['draft_reply']??''),'estimate'=>$d]]];
    }
    private function leadQualification(int $ownerId): array
    {
        $leads=$this->rows("SELECT l.id,l.name,l.email,l.phone,l.comments,l.created_at,COUNT(m.id) AS message_count FROM crm_leads l LEFT JOIN crm_whatsapp_messages m ON m.id_lead=l.id AND m.id_owner=l.id_owner WHERE l.id_owner=:owner AND l.archived='NO' GROUP BY l.id ORDER BY l.created_at DESC LIMIT 100",$ownerId);$items=[];$actions=[];foreach($leads as $l){$score=min(100,20+(int)$l->message_count*10+($l->email?15:0)+($l->phone?15:0)+($l->comments?10:0));$priority=$score>=70?'HIGH':($score>=45?'MEDIUM':'LOW');$item=['lead_id'=>(int)$l->id,'name'=>$l->name,'score'=>$score,'priority'=>$priority,'reason'=>"Profile completeness and {$l->message_count} messages."];$items[]=$item;if($priority!=='LOW')$actions[]=['type'=>'REVIEW_QUALIFIED_LEAD','title'=>"{$priority} lead: {$l->name}"]+$item;}return ['summary'=>count($leads).' CRM leads scored.','items'=>$items,'proposed_actions'=>$actions];
    }
    private function postEvent(int $ownerId,array $input): array
    {
        $id=(int)($input['order_id']??0);if($id<=0)throw new RuntimeException('Select an order.');$order=$this->one("SELECT o.id,o.event_date,o.address,CONCAT(u.name,' ',u.lastname) AS client_name FROM orders o LEFT JOIN users u ON u.id=o.id_client WHERE o.id=:id AND o.id_owner=:owner LIMIT 1",$ownerId,['id'=>$id]);if(!$order)throw new RuntimeException('Order not found.');$photos=$this->rows("SELECT p.photo_url,p.caption,p.uploaded_at FROM event_execution_spaces s JOIN event_execution_photos p ON p.id_space=s.id AND p.deleted_at IS NULL WHERE s.id_order=:id AND s.id_owner=:owner ORDER BY p.uploaded_at LIMIT 100",$ownerId,['id'=>$id]);$d=(new AiJsonGenerator())->generate('Prepare a tasteful private post-event recap without identifying attendees.',['order'=>$order,'photos'=>$photos],['title'=>'','body'=>'','caption'=>'','selected_photo_urls'=>[]]);return ['summary'=>count($photos).' photos reviewed.','items'=>[$d],'proposed_actions'=>[['type'=>'REVIEW_POST_EVENT_RECAP','title'=>'Review recap for order #'.$id,'order_id'=>$id,'body'=>(string)($d['body']??''),'caption'=>(string)($d['caption']??''),'selected_photo_urls'=>$d['selected_photo_urls']??[]]]];
    }
    private function reputation(int $ownerId): array
    {
        $orders=$this->rows("SELECT o.id,o.event_date,CONCAT(u.name,' ',u.lastname) AS client_name,u.email FROM orders o LEFT JOIN users u ON u.id=o.id_client WHERE o.id_owner=:owner AND o.event_date BETWEEN DATE_SUB(CURDATE(),INTERVAL 30 DAY) AND CURDATE() ORDER BY o.event_date DESC LIMIT 50",$ownerId);$actions=[];foreach($orders as $o)$actions[]=['type'=>'SEND_REVIEW_REQUEST','title'=>'Review request for order #'.$o->id,'order_id'=>(int)$o->id,'client_email'=>(string)$o->email,'draft_message'=>"Hello {$o->client_name}, thank you for choosing VNV Events. We would love to hear about your experience when you have a moment."];return ['summary'=>count($orders).' recent events prepared.','items'=>$orders,'proposed_actions'=>$actions];
    }
    private function clientConcierge(int $ownerId,array $input): array
    {
        $id=(int)($input['order_id']??0);$q=trim((string)($input['question']??''));if($id<=0||$q==='')throw new RuntimeException('Select an order and enter a question.');$order=$this->one("SELECT o.id,o.event_date,o.start_time,o.end_time,o.address,o.notes,o.status_workflow,CONCAT(u.name,' ',u.lastname) AS client_name,u.email FROM orders o LEFT JOIN users u ON u.id=o.id_client WHERE o.id=:id AND o.id_owner=:owner LIMIT 1",$ownerId,['id'=>$id]);if(!$order)throw new RuntimeException('Order not found.');$d=(new AiJsonGenerator())->generate('Answer using only verified order data; flag anything needing human confirmation.',['question'=>$q,'order'=>$order],['answer'=>'','requires_human_confirmation'=>true]);return ['summary'=>'Concierge response prepared.','items'=>[$d],'proposed_actions'=>[['type'=>'SEND_CONCIERGE_RESPONSE','title'=>'Review concierge response for order #'.$id,'order_id'=>$id,'client_email'=>(string)$order->email,'question'=>$q,'draft_message'=>(string)($d['answer']??''),'requires_human_confirmation'=>(bool)($d['requires_human_confirmation']??true)]]];
    }
}
