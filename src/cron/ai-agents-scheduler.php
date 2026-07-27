<?php

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Repositories\AiAgentsRepository;
use App\Services\AiAgentExecutionService;
use Dotenv\Dotenv;

Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();

$repository = new AiAgentsRepository();
if (!$repository->storageReady()) {
    fwrite(STDERR, "AI agent storage is not installed.\n");
    exit(1);
}

$service = new AiAgentExecutionService($repository);
foreach ($repository->dueScheduledAgents() as $agent) {
    if (!$repository->claimScheduled((int)$agent->id,(string)$agent->schedule_expression)) continue;
    try {
        $result = $service->run($agent, (int)$agent->id_owner, 0, 'SCHEDULE');
        echo sprintf("[%s] %s: %s (run #%d)\n", date('c'), $agent->agent_key, $result['status'], $result['run_id']);
    } catch (\Throwable $error) {
        fwrite(STDERR, sprintf("[%s] %s failed: %s\n", date('c'), $agent->agent_key, $error->getMessage()));
    }
}
