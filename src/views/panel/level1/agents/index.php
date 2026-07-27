<?php

use App\Repositories\AiAgentsRepository;
use App\Repositories\AiAgentConnectionsRepository;
use App\Services\AiAgentRegistry;
use App\Services\AiVideoRenderService;
use App\Services\LoginService;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $session = LoginService::getSession();
    $ownerId = (int)$session->getOwner();
    $repository = new AiAgentsRepository();
    $definitions = AiAgentRegistry::definitions();
    $ready = $repository->storageReady();
    $agents = [];

    if ($ready) {
        $repository->seed($ownerId, $definitions);
        $socialAgent=$repository->find($ownerId,'social_publisher');$socialConnections=[];
        if($socialAgent)foreach((new AiAgentConnectionsRepository())->all($ownerId,(int)$socialAgent->id) as $connection)if(in_array($connection->status,['CONFIGURED','VERIFIED'],true))$socialConnections[$connection->platform]=true;
        $aiReady=trim((string)($_ENV['OPENAI_TOKEN']??''))!=='';$ffmpegReady=(new AiVideoRenderService())->available();
        foreach ($repository->allForOwner($ownerId) as $agent) {
            $definition = $definitions[$agent->agent_key] ?? [];
            $agent->icon = $definition['icon'] ?? 'cpu';
            $agent->requires = $definition['requires'] ?? [];
            $agent->external_ready=match($agent->agent_key){
                'social_publisher','instagram_carousel'=>!empty($socialConnections['facebook'])||!empty($socialConnections['instagram'])||!empty($socialConnections['linkedin']),
                'video_studio','short_video'=>$ffmpegReady&&$aiReady,
                'blog_writer'=>$aiReady,
                default=>true,
            };
            $agent->availability_label=$agent->external_ready?'Operational':'Drafts ready · connector needed';
            $agents[] = $agent;
        }
    } else {
        foreach ($definitions as $key => $definition) {
            $agents[] = (object)array_merge($definition, [
                'agent_key' => $key,
                'run_count' => 0,
                'pending_approvals' => 0,
                'last_run_at' => null,
            ]);
        }
    }

    return TemplateResponse::render(__DIR__ . '/index.twig', [
        'agents' => $agents,
        'storageReady' => $ready,
        'sqlFile' => 'db/vnv_ai_agents_required.sql',
        'openAiReady' => trim((string)($_ENV['OPENAI_TOKEN'] ?? '')) !== '',
    ]);
});

$router->run();
