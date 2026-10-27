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
