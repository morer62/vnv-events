<?php

namespace App\Services;

final class MochiConversationRouterService
{
    private const ROUTES=[
        'estimate_workflow','service_workflow','executed_events','weekend_events','collections',
        'opportunities','requests','contracts','contract_terms','catalog','warehouse','rewards','website',
        'my_events','my_tasks','customer_orders','write_action','clarify',
    ];

    public function __construct(private ?AiJsonGenerator $ai=null){$this->ai??=new AiJsonGenerator();}

    /** @param object[] $recentMessages */
    public function decide(string $message,array $recentMessages,array $activeTasks,int $level): array
    {
        $history=[];$topicIndex=[];$recentStart=max(0,count($recentMessages)-18);
        foreach($recentMessages as $index=>$row){
            $metadata=json_decode((string)($row->metadata_json??''),true)?:[];
            $entry=[
                'role'=>($row->author_type??'')==='AGENT'?'assistant':'user',
                'message'=>mb_substr(trim((string)($row->message??'')),0,$index>=$recentStart?1200:320),
                'tool'=>(string)($metadata['tool']??''),
            ];
            if($index>=$recentStart){
                $entry['facts']=array_slice((array)($metadata['facts']??[]),0,16,true);
                $entry['items']=array_map(static fn(array $item): array=>[
                    'title'=>mb_substr((string)($item['title']??''),0,140),
                    'meta'=>mb_substr((string)($item['meta']??''),0,180),
                    'detail'=>mb_substr((string)($item['detail']??''),0,240),
                ],array_slice(array_values(array_filter((array)($metadata['items']??[]),'is_array')),0,6));
                $history[]=$entry;
            }else{$topicIndex[]=$entry;}
        }
        $system='You route one message inside the persistent daily VNV Events operations conversation. Multiple topics and unfinished tasks may coexist and the user may switch topics freely. '
            .'Use recent_messages, their structured items, and earlier_topic_index to resolve references such as "eso", "lo pagado", "los cinco anteriores", "el anterior" or "continúa". '
            .'Select the tool that produced the referenced structured result so it can re-read current database facts. '
            .'An active task is only resumable context, never the default destination: select estimate_workflow only when the current message actually continues or changes that estimate. '
            .'Questions about completed/executed events, annual event counts, revenue produced by those events, or follow-ups to those results use executed_events. '
            .'Use weekend_events only for a specific agenda, weekend, date range or upcoming-event request. '
            .'Use contract_terms when the user asks what a contract, agreement, clause, policy or terms say, including setup access or arrival times. Use contracts only for signature status, missing signatures or contract lists. '
            .'Use write_action only for an explicit request to create or change company data. '
            .'When the intended route cannot be determined safely, select clarify. Never guess that an unrelated message belongs to an active task.';
        $allowedRoutes=$level===1?self::ROUTES:($level===4?['my_events','my_tasks','catalog','website','clarify']:($level===5?['my_events','customer_orders','catalog','website','rewards','clarify']:['clarify']));
        $decision=$this->ai->generate($system,[
            'current_message'=>$message,
            'recent_messages'=>$history,
            'earlier_topic_index'=>$topicIndex,
            'active_tasks'=>$activeTasks,
            'user_level'=>$level,
            'allowed_routes'=>$allowedRoutes,
        ],[
            'route'=>'one exact value from allowed_routes',
            'resolved_message'=>'standalone version of current_message with references resolved only from recent_messages, or the original message',
            'confidence'=>'number from 0 to 1',
            'clarification'=>'short Spanish question when route is clarify, otherwise empty string',
        ],[
            'profile'=>'complex','reasoning_effort'=>'medium','timeout'=>30,
            'max_output_tokens'=>500,'schema_name'=>'mochi_context_route',
        ]);
        $route=(string)($decision['route']??'clarify');
        $confidence=(float)($decision['confidence']??0);
        if(!in_array($route,$allowedRoutes,true)||$confidence<0.58)$route='clarify';
        $clarification=trim((string)($decision['clarification']??''));
        if($route==='clarify'&&$clarification==='')$clarification='No pude determinar con seguridad a qué información te refieres. ¿Puedes indicarme el tema o el período?';
        return [
            'route'=>$route,
            'resolved_message'=>trim((string)($decision['resolved_message']??''))?:$message,
            'confidence'=>$confidence,
            'clarification'=>$clarification,
        ];
    }
}
