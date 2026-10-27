<?php

require dirname(__DIR__).'/vendor/autoload.php';

use App\Services\AiJsonGenerator;
use App\Services\MochiEstimateWorkflowService;
use App\Services\MochiImageReaderService;

$live = in_array('--live', $argv, true);
if ($live) Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
else $_ENV['OPENAI_TOKEN'] = 'qa-token-not-sent';
$calls = [];
$transport = static function (string $url, array $payload, array $headers, int $timeout) use (&$calls): array {
    $calls[] = compact('url', 'payload', 'headers', 'timeout');
    return [
        'status' => 200,
        'body' => json_encode(['output' => [[
            'type' => 'message',
            'content' => [['type' => 'output_text', 'text' => '{"reply":"ok"}']],
        ]]]),
        'error' => '',
    ];
};

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$gateway = new AiJsonGenerator($transport);
$result = $gateway->generate('Return a verified answer.', ['fact' => 'x'], ['reply' => 'string']);
$assert(($result['reply'] ?? '') === 'ok', 'Responses output was not decoded.');
$assert(($calls[0]['url'] ?? '') === 'https://api.openai.com/v1/responses', 'Wrong OpenAI endpoint.');
$assert(($calls[0]['payload']['model'] ?? '') === 'gpt-6-luna', 'Routine routing must use Luna.');
$assert(($calls[0]['payload']['reasoning']['effort'] ?? '') === 'low', 'Routine reasoning must stay low-cost.');
$assert(($calls[0]['payload']['text']['format']['type'] ?? '') === 'json_schema', 'Structured output is not enabled.');

$gateway->generate('Write.', [], ['reply' => 'string'], ['profile' => 'complex']);
$assert(($calls[1]['payload']['model'] ?? '') === 'gpt-6.1-sol', 'Complex routing must use Sol.');
$assert(($calls[1]['payload']['reasoning']['effort'] ?? '') === 'medium', 'Complex reasoning must use medium effort.');

$root = dirname(__DIR__);
$controller = file_get_contents($root.'/src/views/panel/level1/planner-hub/settings/automation/index.php');
$view = file_get_contents($root.'/src/views/panel/level1/planner-hub/settings/automation/index.twig');
$concierge = file_get_contents($root.'/src/Services/MochiConciergeService.php');
$responder = file_get_contents($root.'/src/Services/MochiConversationResponderService.php');
$images = file_get_contents($root.'/src/Services/CmsImageGenerationService.php');
$estimateWorkflowSource = file_get_contents($root.'/src/Services/MochiEstimateWorkflowService.php');
$actionSource = file_get_contents($root.'/src/Services/MochiActionService.php');
$knowledgeSource = file_get_contents($root.'/src/Services/MochiBusinessKnowledgeService.php');
$assert(!str_contains($controller, 'transcribe_mochi_audio'), 'Audio transcription endpoint still exists.');
$assert(!str_contains($view, 'id="mochi-voice"'), 'Voice button still exists.');
$assert(str_contains($view, 'name="screenshots[]"'), 'Screenshot upload is missing.');
$assert(str_contains($concierge, 'scope_guard'), 'Editorial scope guard is missing.');
$assert(str_contains($responder, "'profile'=>'complex','reasoning_effort'=>'medium'"), 'Visible Mochi replies must use GPT-6.1 Sol with medium reasoning.');
$assert(str_contains($images, "'gpt-image-2'"), 'Retired image model is still the default.');
$assert(str_contains($estimateWorkflowSource, "'id_contract'=>\$contractId"), 'The conversational estimate workflow must assign General Conditions.');
$assert(str_contains($actionSource, "'id_contract'=>\$contractId"), 'The controlled action estimate workflow must assign General Conditions.');
$ordersRepositorySource=file_get_contents($root.'/src/Repositories/OrdersRepository.php');
$assert(str_contains($ordersRepositorySource,"str_starts_with(\$workflow,'INVOICE_')")&&str_contains($ordersRepositorySource,'requiredGeneralConditionsId()'), 'All invoice lifecycle inserts must default to General Conditions in the central order repository.');
$conciergeSource=file_get_contents($root.'/src/Services/MochiConciergeService.php');
$assert(str_contains($conciergeSource,'isOrderContractAssignmentIntent')&&str_contains($conciergeSource,'prepareOrderContractAssignment'), 'Mochi must deterministically resolve contract requests that contain order-access links.');
$assert(str_contains($knowledgeSource, 'estimate_contract_policy'), 'The General Conditions estimate policy is missing from Mochi knowledge.');

$estimateWorkflow = (new ReflectionClass(MochiEstimateWorkflowService::class))->newInstanceWithoutConstructor();
$sanitizeQaServices = new ReflectionMethod($estimateWorkflow, 'sanitizeQaMarkerServices');
$qaServices = $sanitizeQaServices->invoke($estimateWorkflow, ['Prueba', 'QA Moonlight Dessert Cloud 2028'], 'PRUEBA QA 2028 — NO CONTACTAR. Servicio solicitado: QA Moonlight Dessert Cloud 2028.');
$assert($qaServices === ['QA Moonlight Dessert Cloud 2028'], 'QA record labels must not become estimate services.');
$explicitTestService = $sanitizeQaServices->invoke($estimateWorkflow, ['Prueba'], 'PRUEBA QA 2028. Servicio solicitado: Prueba.');
$assert($explicitTestService === ['Prueba'], 'An explicitly requested catalog service named Prueba must remain selectable.');
$applyQuantities = new ReflectionMethod($estimateWorkflow, 'applyQuantities');
$perGuestServices = $applyQuantities->invoke($estimateWorkflow, [['name' => 'QA service', 'description' => 'Test service', 'requirements' => 'Pricing basis: per guest.', 'quantity' => 1, 'is_variable' => 'YES']], 40);
$assert(($perGuestServices[0]['quantity'] ?? 0) === 40, 'Catalog services marked per guest must use the guest count.');
$assert(($perGuestServices[0]['is_variable'] ?? '') === 'YES', 'Catalog variable pricing must survive quantity calculation.');
$clearResolved = new ReflectionMethod($estimateWorkflow, 'clearResolvedServiceUncertainty');
$healedDraft = $clearResolved->invoke($estimateWorkflow, ['uncertain_fields'=>['requested_services','email'],'unresolved_services'=>[]]);
$assert(($healedDraft['uncertain_fields'] ?? []) === ['email'], 'A resolved service selection must not remain blocked as uncertain.');
$discountFromText = new ReflectionMethod($estimateWorkflow, 'discountFromText');
$discount = $discountFromText->invoke($estimateWorkflow, 'Ponle un 5% de descuento al estimado.');
$assert(($discount['type'] ?? '') === 'percentage' && abs((float)($discount['value'] ?? 0)-5.0)<0.001, 'Estimate percentage discount must be captured mathematically.');
$separateEstimates = new ReflectionMethod($estimateWorkflow, 'requestsSeparateEstimates');
$assert($separateEstimates->invoke($estimateWorkflow, 'Crea dos estimados diferentes, uno con Pasta Station y otro con Pizza Station.'), 'Two separate estimates must use the batch workflow.');
$tentativeTimes = new ReflectionMethod($estimateWorkflow, 'tentativeServiceTimes');
$times = $tentativeTimes->invoke($estimateWorkflow, 'The food services may be around 6:30-7, still to be determined.');
$assert(($times['start'] ?? '') === '18:30:00' && ($times['end'] ?? '') === '20:30:00', 'A tentative dinner-service window must become an explicit reviewable draft schedule.');

echo "MOCHI AI contract QA: PASS\n";

if ($live) {
    $result = (new AiJsonGenerator())->generate(
        'Return the verified input unchanged.',
        ['value' => 'connection-ok'],
        ['value' => 'string'],
        ['profile' => 'routine', 'timeout' => 30, 'max_output_tokens' => 256, 'schema_name' => 'connection_probe']
    );
    $assert(($result['value'] ?? '') === 'connection-ok', 'Live OpenAI Responses probe returned an unexpected value.');
    echo "MOCHI live OpenAI Responses probe: PASS\n";

    if (in_array('--complex', $argv, true)) {
        $result = (new AiJsonGenerator())->generate(
            'Return the verified input unchanged.',
            ['value' => 'sol-ok'],
            ['value' => 'string'],
            ['profile' => 'complex', 'timeout' => 45, 'max_output_tokens' => 256, 'schema_name' => 'complex_connection_probe']
        );
        $assert(($result['value'] ?? '') === 'sol-ok', 'Live Sol probe returned an unexpected value.');
        echo "MOCHI live Sol probe: PASS\n";
    }

    if (in_array('--vision', $argv, true)) {
        $path = dirname(__DIR__).'/public/assets/images/vnv-logo.png';
        $text = (new MochiImageReaderService())->extract([
            'name' => basename($path),
            'tmp_name' => $path,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($path),
        ]);
        $assert(trim($text) !== '', 'Live screenshot reader returned no result.');
        echo "MOCHI live screenshot probe: PASS\n";
    }
}
