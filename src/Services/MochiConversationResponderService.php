<?php

namespace App\Services;

final class MochiConversationResponderService
{
    public function __construct(private ?AiJsonGenerator $ai=null){$this->ai??=new AiJsonGenerator();}

    /** @param object[] $recentMessages */
    public function compose(string $question,string $tool,array $verifiedResult,array $recentMessages): string
    {
        $history=[];
        foreach(array_slice($recentMessages,-10) as $row){
            $metadata=json_decode((string)($row->metadata_json??''),true)?:[];
            $history[]=[
                'role'=>($row->author_type??'')==='AGENT'?'assistant':'user',
                'message'=>mb_substr(trim((string)($row->message??'')),0,700),
                'tool'=>(string)($metadata['tool']??''),
            ];
        }
        $system='You are Mochi, the internal VNV Events operations assistant. Write concise, warm, natural Spanish. '
            .'Answer the user\'s exact current question as part of an ongoing conversation; do not repeat a full report when a focused follow-up was asked. '
            .'Use verified_result as the sole source of live business facts and numbers. You may explain the meaning of supplied metrics and calculate only direct arithmetic from them. '
            .'Do not invent causes, payments, balances, services, availability or actions. If the data does not establish a cause, distinguish what is known from what would require a detailed reconciliation. '
            .'Do not mention routing, tools, JSON or internal implementation. Prefer one short paragraph; use bullets only when they materially improve a list.';
        $generated=$this->ai->generate($system,[
            'current_question'=>$question,
            'recent_dialogue'=>$history,
            'source'=>$tool,
            'verified_result'=>$verifiedResult,
        ],['reply'=>'direct conversational answer in Spanish based only on verified_result'],[
            'profile'=>'conversational','reasoning_effort'=>'low','timeout'=>24,
            'max_output_tokens'=>650,'schema_name'=>'mochi_conversational_reply',
        ]);
        return trim((string)($generated['reply']??''));
    }
}
