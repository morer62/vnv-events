<?php

namespace App\Services;

use App\Repositories\Connection;
use RuntimeException;

/**
 * Stateful Responses API tool loop for the VNV operations conversation.
 *
 * The model may read several approved sources before answering. It never
 * receives SQL and every write remains a draft handled by the existing
 * confirmation flow.
 */
final class MochiAgentLoopService
{
    private const ENDPOINT = 'https://api.openai.com/v1/responses';
    private const MAX_TOOL_CALLS = 6;

    /** @var null|callable(string,array,array,int):array{status:int,body:string,error:string} */
    private $transport;

    /** @var null|callable(string,array):array */
    private $toolExecutor;

    private ?MochiOperationalToolsService $operational = null;
    private ?MochiEstimateWorkflowService $estimates = null;
    private ?MochiServiceCatalogWorkflowService $services = null;
    private ?MochiActionService $actions = null;

    public function __construct(
        private int $ownerId = 2,
        private string $siteKey = 'vnvevents',
        private ?Connection $db = null,
        ?callable $transport = null,
        ?callable $toolExecutor = null
    ) {
        $this->transport = $transport;
        $this->toolExecutor = $toolExecutor;
        if ($toolExecutor === null) {
            $this->db ??= new Connection();
            $this->operational = new MochiOperationalToolsService($ownerId, $siteKey, $this->db);
            $this->estimates = new MochiEstimateWorkflowService($ownerId, $siteKey, $this->db);
            $this->services = new MochiServiceCatalogWorkflowService($ownerId, $siteKey, $this->db);
            $this->actions = new MochiActionService($ownerId, $siteKey, $this->db);
        }
    }

    /** @param object[] $recentMessages */
    public function run(
        int $userId,
        int $level,
        int $sessionId,
        string $message,
        array $recentMessages,
        array $memory,
        array $activeTasks
    ): array {
        $key = trim((string)($_ENV['OPENAI_TOKEN'] ?? $_ENV['OPENAI_API_KEY'] ?? ''));
        if ($key === '') {
            throw new RuntimeException('OPENAI_TOKEN is not configured.');
        }

        $instructions = $this->instructions($level, $memory, $activeTasks);
        // Web search is deliberately unavailable until the local catalog has
        // explicitly reported that it has no reliable match. This makes the
        // local-first rule enforceable instead of merely instructional.
        $webSearchAllowed = false;
        $tools = $this->toolDefinitions($level, $webSearchAllowed);
        $previousResponseId = $this->previousResponseId($recentMessages);
        $input = $previousResponseId !== ''
            ? [['role' => 'user', 'content' => $message]]
            : $this->conversationInput($recentMessages, $message);

        $response = $this->createResponse($key, $instructions, $tools, $input, $previousResponseId, $userId);
        if ($previousResponseId !== '' && $this->isMissingPreviousResponse($response)) {
            $response = $this->createResponse($key, $instructions, $tools, $this->conversationInput($recentMessages, $message), '', $userId);
        }
        $this->assertResponse($response);

        $aggregate = [
            'items' => [],
            'facts' => [],
            'tool_calls' => [],
            'tool_trace' => [],
            'estimate_workflow' => null,
            'service_workflow' => null,
            'action_draft' => null,
            'copy_url' => null,
            'workflow_reply' => null,
        ];
        $totalCalls = 0;

        while (true) {
            $web=$this->webSearchEvidence($response);
            if($web['calls']){
                $aggregate['tool_calls'][]='web_search';
                foreach($web['calls'] as $call)$aggregate['tool_trace'][]=['tool'=>'web_search','arguments'=>$call];
                $aggregate['items']=$this->mergeItems($aggregate['items'],$web['items']);
                $aggregate['facts']['web_search']=['external_sources'=>$web['sources'],'not_vnv_catalog_evidence'=>true];
            }
            $calls = $this->functionCalls($response);
            if (!$calls) {
                $reply = (string)($aggregate['workflow_reply'] ?? '');
                if ($reply === '') {
                    $reply = $this->outputText($response);
                }
                if ($reply === '') {
                    throw new RuntimeException('OpenAI Responses returned no conversational answer.');
                }
                return $aggregate + [
                    'reply' => $reply,
                    'tool' => $aggregate['tool_calls'] ? 'agent:'.implode(',', array_values(array_unique($aggregate['tool_calls']))) : 'agent:conversation',
                    'openai_response_id' => (string)($response['id'] ?? ''),
                ];
            }

            $outputs = [];
            foreach ($calls as $call) {
                $totalCalls++;
                if ($totalCalls > self::MAX_TOOL_CALLS) {
                    throw new RuntimeException('Mochi exceeded the safe tool-call limit for one message.');
                }
                $name = (string)($call['name'] ?? '');
                $arguments = json_decode((string)($call['arguments'] ?? '{}'), true);
                if (!is_array($arguments)) {
                    $arguments = [];
                }
                if ($name === 'read_vnv_data') {
                    $arguments['query'] = $this->effectiveReadQuery(
                        (string)($arguments['topic'] ?? ''),
                        $message,
                        (string)($arguments['query'] ?? '')
                    );
                }
                if (in_array($name, ['work_on_estimate', 'work_on_service_or_product'], true)) {
                    $arguments['message'] = $message;
                }
                $result = $this->executeTool($name, $arguments, $userId, $level, $sessionId);
                if ($name === 'read_vnv_data'
                    && (string)($arguments['topic'] ?? '') === 'catalog'
                    && array_key_exists('local_match_found', (array)($result['facts'] ?? []))
                    && ($result['facts']['local_match_found'] ?? true) === false) {
                    $webSearchAllowed = true;
                }
                $toolKey = $name === 'read_vnv_data' ? $name.':'.(string)($arguments['topic'] ?? 'unknown') : $name;
                $aggregate['tool_calls'][] = $toolKey;
                $aggregate['tool_trace'][] = ['tool'=>$toolKey,'arguments'=>$arguments];
                $aggregate['items'] = $this->mergeItems($aggregate['items'], (array)($result['items'] ?? []));
                if (!empty($result['facts'])) {
                    $aggregate['facts'][$toolKey] = $result['facts'];
                }
                foreach (['estimate_workflow', 'service_workflow', 'action_draft', 'copy_url'] as $field) {
                    if (array_key_exists($field, $result) && $result[$field] !== null) {
                        $aggregate[$field] = $result[$field];
                    }
                }
                if (in_array($name, ['work_on_estimate', 'work_on_service_or_product'], true)
                    && trim((string)($result['reply'] ?? '')) !== '') {
                    $aggregate['workflow_reply'] = (string)$result['reply'];
                }
                $outputs[] = [
                    'type' => 'function_call_output',
                    'call_id' => (string)($call['call_id'] ?? ''),
                    'output' => json_encode($this->safeToolOutput($result), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ];
            }

            $responseId = (string)($response['id'] ?? '');
            if ($responseId === '') {
                throw new RuntimeException('OpenAI Responses omitted the response id required by the tool loop.');
            }
            $tools = $this->toolDefinitions($level, $webSearchAllowed);
            $response = $this->createResponse($key, $instructions, $tools, $outputs, $responseId, $userId);
            $this->assertResponse($response);
        }
    }

    private function instructions(int $level, array $memory, array $activeTasks): string
    {
        $context = json_encode([
            'persistent_memory' => $memory,
            'active_tasks' => $activeTasks,
            'user_level' => $level,
            'today' => (new \DateTimeImmutable('now', new \DateTimeZone('America/New_York')))->format('Y-m-d'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return 'You are Mochi, the internal VNV Events operations assistant inside one continuous daily conversation. '
            .'Write concise, warm, natural Spanish and answer the exact current question. The user may change topics freely and later return to any prior topic; an active estimate or service draft is resumable context, never the automatic destination of a new message. '
            .'Use the dialogue and persistent memory to resolve follow-ups such as "eso", "los anteriores", "lo pagado" or "continúa". Do not repeat a full report when a focused answer is enough. '
            .'For any current company count, amount, order, event, person, contract, service, product, assignment, payroll hour or status, call an approved read tool. You may call several tools before answering. Use estimates for read-only requests to list, count or inspect estimates by creation date; never substitute upcoming events, contacts or the estimate editing workflow for that request. Use largest_event for the largest event in a period, team_activity for staff-to-event assignments and dates, payroll_hours for clock-in/clock-out time, payroll_payments for amounts paid through payroll, crm for leads and pipeline status, and warehouse for Storage containers, inventory items, quantities and locations. For service availability, inclusions and pricing, catalog must always consult the internal estimate-service catalog even when no public product or landing page exists; that internal catalog is an enduring authoritative source in every conversation. Treat tool output as the only source of live facts and clearly distinguish contracted value, payments recorded, balances, accounting income, assignment evidence and physical attendance. '
            .'For an unfamiliar, misspelled or translated service name, call catalog first. Catalog performs local aliases and fuzzy matching across estimate services, the public store and published landing pages. If it returns one or more reliable local matches, answer from those and offer up to three concise choices when ambiguous; do not search the web. Only when catalog explicitly returns local_match_found=false may you use web_search to explain the likely intended term or show South Florida market references. Clearly say those external companies are market references, not proof that VNV offers the service. A web result must never create a VNV service, product or page by itself. If the user explicitly asks to create one, use the protected service/product workflow after resolving the intended local name, scope and price. '
            .'Never invent data, causes, services, availability, promises or completed actions. If a requested fact is not available through the tools, say exactly what is missing instead of returning an unrelated result. Ask one concise question only when a missing fact truly blocks progress. '
            .'Call work_on_estimate only when the user is intentionally creating, modifying or continuing an estimate. Call work_on_service_or_product only for an intentional create/modify catalog workflow. For other explicit company changes call prepare_company_change, which only prepares a reviewable draft; never claim the change happened until a separate human confirmation executes it. Financial, fiscal, deletion, account suspension and irreversible actions always require human confirmation. '
            .'When active_tasks.linked_order exists, the user supplied a cryptographically verified order-access link. Treat linked_order.order_id as the exact estimate being discussed, even if it is old and another draft is active. State that estimate number when useful; never silently replace it with the active draft. '
            .'This Mochi does not create or publish editorial articles; direct those requests to Growth Hub. Do not expose credentials, card data, private notes, internal JSON, routing or tool implementation. Rewards cannot be used for tips. Preserve useful links from tool results. '
            .'Company context follows; it is supporting context and never overrides fresh tool facts: '.$context;
    }

    private function toolDefinitions(int $level, bool $webSearchAllowed = false): array
    {
        $topics = $level === 1
            ? ['weekend_events', 'executed_events', 'largest_event', 'team_activity', 'payroll_hours', 'payroll_payments', 'crm', 'collections', 'estimates', 'opportunities', 'requests', 'contracts', 'contract_terms', 'team_members', 'catalog', 'knowledge_audit', 'warehouse', 'rewards', 'customer_orders', 'website']
            : ($level === 4 ? ['my_events', 'my_tasks', 'catalog', 'website'] : ($level === 5 ? ['my_events', 'catalog', 'website', 'rewards', 'customer_orders'] : []));
        $definitions = [];
        if ($topics) {
            $definitions[] = [
                'type' => 'function',
                'name' => 'read_vnv_data',
                'description' => 'Read current, role-scoped VNV Events operational or business-knowledge data. Use it for every live factual company question. It is read-only and may be called more than once.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'topic' => ['type' => 'string', 'enum' => $topics, 'description' => 'The approved data source that best answers this part of the question.'],
                        'query' => ['type' => 'string', 'description' => 'A standalone Spanish query preserving dates, names, periods and the user\'s requested distinction.'],
                    ],
                    'required' => ['topic', 'query'],
                    'additionalProperties' => false,
                ],
                'strict' => true,
            ];
        }
        if ($level === 1 && $webSearchAllowed) {
            $definitions[] = [
                'type'=>'web_search',
                'search_context_size'=>'low',
                'user_location'=>[
                    'type'=>'approximate','country'=>'US','city'=>'Miami','region'=>'Florida','timezone'=>'America/New_York',
                ],
            ];
        }
        if ($level === 1) {
            $definitions[] = $this->messageTool('work_on_estimate', 'Create, modify, or continue an estimate only when the user explicitly intends that workflow.');
            $definitions[] = $this->messageTool('work_on_service_or_product', 'Create, modify, or continue an internal service/store product workflow only when the user explicitly intends it.');
            $definitions[] = $this->messageTool('prepare_company_change', 'Prepare a reversible review draft for an explicit company-data change. This never executes the change. Do not use it for suspension, deletion, financial, fiscal or irreversible actions; explain that those require a dedicated reviewed workflow.');
        }
        return $definitions;
    }

    private function messageTool(string $name, string $description): array
    {
        return [
            'type' => 'function',
            'name' => $name,
            'description' => $description,
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'message' => ['type' => 'string', 'description' => 'The complete standalone user instruction with references resolved from the conversation.'],
                ],
                'required' => ['message'],
                'additionalProperties' => false,
            ],
            'strict' => true,
        ];
    }

    private function executeTool(string $name, array $arguments, int $userId, int $level, int $sessionId): array
    {
        if ($this->toolExecutor !== null) {
            return ($this->toolExecutor)($name, $arguments);
        }
        return match ($name) {
            'read_vnv_data' => $this->operational->run((string)($arguments['topic'] ?? ''), $userId, $level, $sessionId, (string)($arguments['query'] ?? '')),
            'work_on_estimate' => $this->estimates->handle($userId, $level, $sessionId, (string)($arguments['message'] ?? '')),
            'work_on_service_or_product' => $this->services->handle($userId, $level, $sessionId, (string)($arguments['message'] ?? '')),
            'prepare_company_change' => $this->prepareAction($userId, $level, $sessionId, (string)($arguments['message'] ?? '')),
            default => throw new RuntimeException('Mochi requested an unknown tool: '.$name),
        };
    }

    private function effectiveReadQuery(string $topic, string $currentMessage, string $resolvedQuery): string
    {
        $currentMessage = trim($currentMessage);
        $resolvedQuery = trim($resolvedQuery);
        if ($currentMessage === '') {
            return $resolvedQuery;
        }

        // These sources depend on exact names, periods and inventory wording.
        // Keep the literal current request authoritative unless it is clearly a
        // contextual follow-up that needs the model to resolve its references.
        if (in_array($topic, ['payroll_hours', 'payroll_payments', 'crm', 'estimates', 'warehouse', 'catalog', 'knowledge_audit'], true)
            && !$this->isContextualFollowUp($currentMessage)) {
            return $currentMessage;
        }

        return $resolvedQuery !== '' ? $resolvedQuery : $currentMessage;
    }

    private function isContextualFollowUp(string $message): bool
    {
        $plain = mb_strtolower(trim($message));
        $plain = strtr($plain, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);

        $words = count(preg_split('/\s+/u', $plain, -1, PREG_SPLIT_NO_EMPTY));
        return $words <= 10 && (bool)preg_match(
            '/(?:^\s*(?:y|no)\b|\b(?:eso|esa|ese|esas|esos|aquello|'
            .'anterior|anteriores|le|les|lo\s+pagado|tambien|ahi|alli)\b)/u',
            $plain
        );
    }

    private function prepareAction(int $userId, int $level, int $sessionId, string $message): array
    {
        if (!$this->actions->detects($message)) {
            return ['reply' => 'No identifiqué con seguridad un cambio compatible con el flujo protegido.', 'items' => [], 'facts' => ['draft_prepared' => false]];
        }
        return $this->actions->prepare($userId, $level, $sessionId, $message);
    }

    /** @param object[] $recentMessages */
    private function conversationInput(array $recentMessages, string $message): array
    {
        $input = [];
        foreach (array_slice($recentMessages, -40) as $row) {
            $text = trim((string)($row->message ?? ''));
            if ($text === '') {
                continue;
            }
            $input[] = [
                'role' => ($row->author_type ?? '') === 'AGENT' ? 'assistant' : 'user',
                'content' => mb_substr($text, 0, 4000),
            ];
        }
        $input[] = ['role' => 'user', 'content' => $message];
        return $input;
    }

    /** @param object[] $recentMessages */
    private function previousResponseId(array $recentMessages): string
    {
        foreach (array_reverse($recentMessages) as $row) {
            if (($row->author_type ?? '') !== 'AGENT') {
                continue;
            }
            $metadata = json_decode((string)($row->metadata_json ?? ''), true);
            $id = is_array($metadata) ? trim((string)($metadata['openai_response_id'] ?? '')) : '';
            if ($id !== '') {
                return $id;
            }
        }
        return '';
    }

    private function createResponse(string $key, string $instructions, array $tools, array $input, string $previousResponseId, int $userId): array
    {
        $payload = [
            'model' => trim((string)($_ENV['OPENAI_COMPLEX_MODEL'] ?? 'gpt-6.1-sol')),
            'instructions' => $instructions,
            'input' => $input,
            'tools' => $tools,
            'tool_choice' => 'auto',
            'parallel_tool_calls' => false,
            'reasoning' => ['effort' => 'medium'],
            'max_output_tokens' => 1800,
            'store' => true,
            'safety_identifier' => hash('sha256', $this->siteKey.':'.$this->ownerId.':'.$userId),
            'prompt_cache_key' => 'mochi:'.$this->siteKey.':'.$this->ownerId,
        ];
        if ($previousResponseId !== '') {
            $payload['previous_response_id'] = $previousResponseId;
        }
        $headers = ['Authorization: Bearer '.$key, 'Content-Type: application/json'];
        $last = ['status' => 0, 'body' => '', 'error' => ''];
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $last = $this->request(self::ENDPOINT, $payload, $headers, 45);
            if ($last['status'] >= 200 && $last['status'] < 300) {
                break;
            }
            if (!in_array($last['status'], [408, 409, 429, 500, 502, 503, 504], true)) {
                break;
            }
            usleep(250000 * ($attempt + 1));
        }
        $decoded = json_decode((string)$last['body'], true);
        if (!is_array($decoded)) {
            $decoded = [];
        }
        $decoded['_http_status'] = (int)$last['status'];
        $decoded['_transport_error'] = (string)$last['error'];
        return $decoded;
    }

    private function assertResponse(array $response): void
    {
        $status = (int)($response['_http_status'] ?? 0);
        if ($status >= 200 && $status < 300) {
            return;
        }
        $code = trim((string)($response['error']['code'] ?? 'HTTP_'.$status));
        $detail = trim((string)($response['error']['message'] ?? $response['_transport_error'] ?? ''));
        throw new RuntimeException('OpenAI Responses failed ['.$code.']'.($detail !== '' ? ': '.mb_substr($detail, 0, 300) : '.'));
    }

    private function isMissingPreviousResponse(array $response): bool
    {
        $status = (int)($response['_http_status'] ?? 0);
        $message = mb_strtolower((string)($response['error']['message'] ?? ''));
        return in_array($status, [400, 404], true) && str_contains($message, 'previous_response_id');
    }

    private function functionCalls(array $response): array
    {
        return array_values(array_filter((array)($response['output'] ?? []), static fn($item): bool => is_array($item) && ($item['type'] ?? '') === 'function_call'));
    }

    private function outputText(array $response): string
    {
        if (isset($response['output_text']) && is_string($response['output_text'])) {
            return trim($response['output_text']);
        }
        $parts = [];
        foreach ((array)($response['output'] ?? []) as $item) {
            if (($item['type'] ?? '') !== 'message') {
                continue;
            }
            foreach ((array)($item['content'] ?? []) as $content) {
                if (in_array((string)($content['type'] ?? ''), ['output_text', 'text'], true)) {
                    $parts[] = trim((string)($content['text'] ?? ''));
                }
            }
        }
        return trim(implode("\n", array_filter($parts)));
    }

    private function webSearchEvidence(array $response): array
    {
        $calls=[];$sources=[];$items=[];
        foreach((array)($response['output']??[]) as $item){
            if(($item['type']??'')==='web_search_call'){
                $action=(array)($item['action']??[]);
                $calls[]=['type'=>(string)($action['type']??'search'),'queries'=>(array)($action['queries']??[]),'query'=>(string)($action['query']??'')];
            }
            if(($item['type']??'')!=='message')continue;
            foreach((array)($item['content']??[]) as $content){
                foreach((array)($content['annotations']??[]) as $annotation){
                    if(($annotation['type']??'')!=='url_citation')continue;
                    $url=trim((string)($annotation['url']??''));if($url===''||!preg_match('#^https?://#i',$url))continue;
                    $title=trim((string)($annotation['title']??''))?:parse_url($url,PHP_URL_HOST);
                    if(isset($sources[$url]))continue;
                    $sources[$url]=['title'=>$title,'url'=>$url];
                    $items[]=['title'=>$title,'meta'=>'Referencia externa · no es catálogo de VNV','detail'=>'Fuente consultada por MOCHI después de no encontrar una coincidencia local confiable.','url'=>$url];
                }
            }
        }
        return ['calls'=>$calls,'sources'=>array_values($sources),'items'=>$items];
    }

    private function safeToolOutput(array $result): array
    {
        return [
            'summary' => mb_substr((string)($result['reply'] ?? ''), 0, 5000),
            'facts' => (array)($result['facts'] ?? []),
            'items' => array_slice((array)($result['items'] ?? []), 0, 20),
            'workflow' => $result['estimate_workflow'] ?? $result['service_workflow'] ?? null,
            'action_draft' => $result['action_draft'] ?? null,
            'copy_url' => $result['copy_url'] ?? null,
        ];
    }

    private function mergeItems(array $current, array $new): array
    {
        $seen = [];
        $merged = [];
        foreach (array_merge($current, $new) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $key = sha1(json_encode([$item['title'] ?? '', $item['url'] ?? '', $item['meta'] ?? '']));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $merged[] = $item;
            if (count($merged) >= 24) {
                break;
            }
        }
        return $merged;
    }

    /** @return array{status:int,body:string,error:string} */
    private function request(string $url, array $payload, array $headers, int $timeout): array
    {
        if ($this->transport !== null) {
            return ($this->transport)($url, $payload, $headers, $timeout);
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => $body === false ? '' : (string)$body, 'error' => $error];
    }
}
