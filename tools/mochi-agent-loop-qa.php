<?php

require dirname(__DIR__).'/vendor/autoload.php';

use App\Services\MochiAgentLoopService;

$_ENV['OPENAI_TOKEN'] = 'qa-token';
$_ENV['OPENAI_COMPLEX_MODEL'] = 'gpt-6.1-sol';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$requests = [];
$responses = [
    [
        'id' => 'resp_step_1',
        'output' => [[
            'type' => 'function_call',
            'call_id' => 'call_events',
            'name' => 'read_vnv_data',
            'arguments' => json_encode(['topic' => 'executed_events', 'query' => 'Cuántos eventos se ejecutaron en 2026 y cuánto se pagó']),
        ]],
    ],
    [
        'id' => 'resp_step_2',
        'output' => [[
            'type' => 'function_call',
            'call_id' => 'call_catalog',
            'name' => 'read_vnv_data',
            'arguments' => json_encode(['topic' => 'catalog', 'query' => 'Qué alternativas de comida ofrece VNV Gourmet para una boda']),
        ]],
    ],
    [
        'id' => 'resp_final',
        'output' => [[
            'type' => 'message',
            'content' => [['type' => 'output_text', 'text' => 'Este año se ejecutaron 12 eventos. Para la boda, VNV Gourmet ofrece dos alternativas verificadas.']],
        ]],
    ],
];
$transport = static function (string $url, array $payload) use (&$requests, &$responses): array {
    $requests[] = ['url' => $url, 'payload' => $payload];
    $body = array_shift($responses);
    return ['status' => 200, 'body' => json_encode($body), 'error' => ''];
};
$toolCalls = [];
$executor = static function (string $name, array $arguments) use (&$toolCalls): array {
    $toolCalls[] = [$name, $arguments];
    if (($arguments['topic'] ?? '') === 'executed_events') {
        return ['reply' => '12 eventos ejecutados.', 'facts' => ['event_count' => 12, 'paid' => 1000], 'items' => []];
    }
    return ['reply' => '2 alternativas verificadas.', 'facts' => ['alternatives' => 2], 'items' => [['title' => 'Pasta Station', 'url' => '/pasta-station']]];
};

$service = new MochiAgentLoopService(2, 'vnvevents', null, $transport, $executor);
$result = $service->run(99, 1, 123, 'Compara lo ejecutado este año y dame ideas de comida para una boda.', [], [], ['estimate' => ['active' => false], 'service' => ['active' => false]]);

$assert(count($requests) === 3, 'The agent must continue until the final answer after multiple tools.');
$assert(count($toolCalls) === 2, 'Both requested tools must execute.');
$assert(($requests[0]['url'] ?? '') === 'https://api.openai.com/v1/responses', 'Wrong Responses endpoint.');
$assert(($requests[0]['payload']['model'] ?? '') === 'gpt-6.1-sol', 'MOCHI must use the fixed Sol model.');
$assert(($requests[0]['payload']['reasoning']['effort'] ?? '') === 'medium', 'MOCHI must use medium reasoning.');
$topics = $requests[0]['payload']['tools'][0]['parameters']['properties']['topic']['enum'] ?? [];
$assert(in_array('payroll_payments', $topics, true), 'Payroll payment history must be available to the agent.');
$assert(in_array('crm', $topics, true), 'CRM data must be available to the agent.');
$assert(in_array('warehouse', $topics, true), 'Storage inventory must be available to the agent.');
$assert(!isset($requests[0]['payload']['previous_response_id']), 'A new chain must not invent a previous response id.');
$assert(($requests[1]['payload']['previous_response_id'] ?? '') === 'resp_step_1', 'First tool output must continue the same Responses chain.');
$assert(($requests[2]['payload']['previous_response_id'] ?? '') === 'resp_step_2', 'Second tool output must continue the same Responses chain.');
$assert(($requests[1]['payload']['input'][0]['type'] ?? '') === 'function_call_output', 'Tool result must be returned as function_call_output.');
$assert(($result['openai_response_id'] ?? '') === 'resp_final', 'The final response id must be persisted for the next user turn.');
$assert(($result['facts']['read_vnv_data:executed_events']['event_count'] ?? 0) === 12, 'Facts from the first tool must remain available.');
$assert(($result['facts']['read_vnv_data:catalog']['alternatives'] ?? 0) === 2, 'Facts from the second tool must remain available.');
$assert(count($result['items'] ?? []) === 1, 'UI cards from tool output must be preserved.');

$continuedRequests = [];
$continuedTransport = static function (string $url, array $payload) use (&$continuedRequests): array {
    $continuedRequests[] = $payload;
    return ['status' => 200, 'body' => json_encode([
        'id' => 'resp_next_turn',
        'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Sí, seguimos hablando del mismo análisis.']]]],
    ]), 'error' => ''];
};
$previousMessage = (object)[
    'author_type' => 'AGENT',
    'message' => 'Respuesta anterior',
    'metadata_json' => json_encode(['openai_response_id' => 'resp_final']),
];
$continued = new MochiAgentLoopService(2, 'vnvevents', null, $continuedTransport, $executor);
$continued->run(99, 1, 123, '¿Y cuál de esas opciones conviene más?', [$previousMessage], [], []);
$assert(($continuedRequests[0]['previous_response_id'] ?? '') === 'resp_final', 'The next user turn must inherit the same OpenAI response chain.');
$assert(count($continuedRequests[0]['input'] ?? []) === 1, 'A continued turn should send only the new user message.');

fwrite(STDOUT, "PASS: multi-tool loop, Sol Medium, and persistent response chain verified.\n");

$literalToolCalls = [];
$literalResponses = [
    [
        'id' => 'resp_literal_tool',
        'output' => [[
            'type' => 'function_call',
            'call_id' => 'call_literal_payroll',
            'name' => 'read_vnv_data',
            'arguments' => json_encode([
                'topic' => 'payroll_payments',
                'query' => 'Consultar solamente enero de 2026.',
            ]),
        ]],
    ],
    [
        'id' => 'resp_literal_final',
        'output' => [[
            'type' => 'message',
            'content' => [['type' => 'output_text', 'text' => 'Consulta completada.']],
        ]],
    ],
];
$literalTransport = static function (string $url, array $payload) use (&$literalResponses): array {
    return ['status' => 200, 'body' => json_encode(array_shift($literalResponses)), 'error' => ''];
};
$literalExecutor = static function (string $name, array $arguments) use (&$literalToolCalls): array {
    $literalToolCalls[] = [$name, $arguments];
    return ['reply' => 'Consulta completada.', 'facts' => [], 'items' => []];
};
$literalQuestion = '¿Cuál ha sido el empleado al que más dinero le hemos pagado este año 2026?';
(new MochiAgentLoopService(2, 'vnvevents', null, $literalTransport, $literalExecutor))
    ->run(99, 1, 123, $literalQuestion, [], [], []);
$assert(
    ($literalToolCalls[0][1]['query'] ?? '') === $literalQuestion,
    'Payroll must execute the literal current question instead of a model-narrowed period.'
);

fwrite(STDOUT, "PASS: literal payroll and inventory questions remain authoritative.\n");

$catalogToolCalls = [];
$catalogResponses = [
    [
        'id' => 'resp_catalog_tool',
        'output' => [[
            'type' => 'function_call',
            'call_id' => 'call_literal_catalog',
            'name' => 'read_vnv_data',
            'arguments' => json_encode([
                'topic' => 'catalog',
                'query' => 'Consultar precio, opciones, inclusiones y mínimos del grazing table.',
            ]),
        ]],
    ],
    [
        'id' => 'resp_catalog_final',
        'output' => [[
            'type' => 'message',
            'content' => [['type' => 'output_text', 'text' => 'El precio es $450.']],
        ]],
    ],
];
$catalogTransport = static function (string $url, array $payload) use (&$catalogResponses): array {
    return ['status' => 200, 'body' => json_encode(array_shift($catalogResponses)), 'error' => ''];
};
$catalogExecutor = static function (string $name, array $arguments) use (&$catalogToolCalls): array {
    $catalogToolCalls[] = [$name, $arguments];
    return ['reply' => 'Grazing Table: $450.', 'facts' => [], 'items' => []];
};
$catalogQuestion = 'ok, cuanto vale un grazing table?';
(new MochiAgentLoopService(2, 'vnvevents', null, $catalogTransport, $catalogExecutor))
    ->run(99, 1, 123, $catalogQuestion, [], [], []);
$assert(
    ($catalogToolCalls[0][1]['query'] ?? '') === $catalogQuestion,
    'The internal estimate catalog must execute the literal service-price question.'
);

fwrite(STDOUT, "PASS: estimate-catalog questions retain their literal service name.\n");

$workflowCalls = [];
$workflowResponses = [
    [
        'id' => 'resp_workflow_tool',
        'output' => [[
            'type' => 'function_call',
            'call_id' => 'call_estimate',
            'name' => 'work_on_estimate',
            'arguments' => json_encode(['message' => 'Expanded model interpretation that must not replace the user selection.']),
        ]],
    ],
    [
        'id' => 'resp_workflow_final',
        'output' => [[
            'type' => 'message',
            'content' => [['type' => 'output_text', 'text' => 'Model paraphrase that must not replace the workflow result.']],
        ]],
    ],
];
$workflowTransport = static function (string $url, array $payload) use (&$workflowResponses): array {
    return ['status' => 200, 'body' => json_encode(array_shift($workflowResponses)), 'error' => ''];
};
$workflowExecutor = static function (string $name, array $arguments) use (&$workflowCalls): array {
    $workflowCalls[] = [$name, $arguments];
    return ['reply' => 'Estimate #123 creado correctamente.', 'facts' => [], 'items' => [], 'estimate_workflow' => ['id'=>9,'status'=>'ACTIVE']];
};
$workflowResult = (new MochiAgentLoopService(2, 'vnvevents', null, $workflowTransport, $workflowExecutor))
    ->run(99, 1, 123, 'el primero', [], [], ['estimate'=>['active'=>true]]);
$assert(($workflowCalls[0][1]['message'] ?? '') === 'el primero', 'Estimate workflow must receive the literal user selection.');
$assert(($workflowResult['reply'] ?? '') === 'Estimate #123 creado correctamente.', 'The deterministic workflow result must remain the visible answer.');

fwrite(STDOUT, "PASS: estimate workflow selections and results cannot be rewritten by the model.\n");
