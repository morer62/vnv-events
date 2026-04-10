<?php

use App\Services\DocuSignService;
use App\Repositories\DocumentsLogsRepository;
use App\Repositories\OrdersRepository;

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="contract_signed.pdf"');

$envelopeId = $_GET['envelope_id'] ?? null;

if (!$envelopeId) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'envelope_id is required']);
    exit;
}

$envelopeId = trim(urldecode($envelopeId));

if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $envelopeId)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid envelope_id format']);
    exit;
}

try {
    $docRepo = new DocumentsLogsRepository();
    $docs = $docRepo->getAll();
    $foundDoc = null;
    
    foreach ($docs as $doc) {
        $extra = json_decode($doc->extra ?? '{}', true);
        if (isset($extra['envelope_id']) && $extra['envelope_id'] === $envelopeId) {
            $foundDoc = $doc;
            break;
        }
    }
    
    if (!$foundDoc) {
        http_response_code(404);
        echo json_encode(['error' => 'Document not found']);
        exit;
    }
    
    $docuSignService = new DocuSignService();
    
    if (!$docuSignService->isConfigured()) {
        http_response_code(500);
        echo json_encode(['error' => 'DocuSign is not configured']);
        exit;
    }
    
    $envelopeStatus = $docuSignService->getEnvelopeStatus($envelopeId);
    
    if ($envelopeStatus && in_array(strtolower($envelopeStatus), ['completed', 'signed'])) {
        $tempFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'docusign_' . $envelopeId . '_' . time() . '.pdf';
        
        $downloadResult = $docuSignService->downloadSignedDocument($envelopeId, $tempFile);
        
        if ($downloadResult && file_exists($tempFile)) {
            $pdfContent = file_get_contents($tempFile);
            if ($pdfContent !== false && strlen($pdfContent) > 0) {
                header('Content-Length: ' . strlen($pdfContent));
                echo $pdfContent;
                @unlink($tempFile);
                exit;
            }
        }
    }
    
    header('Content-Type: text/html; charset=utf-8');
    http_response_code(503); // Service Unavailable
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Document Processing</title>
        <style>
            body { font-family: Arial, sans-serif; text-align: center; padding: 50px; }
            .message { max-width: 600px; margin: 0 auto; padding: 30px; background: #f8f9fa; border-radius: 8px; }
            h1 { color: #333; }
            p { color: #666; line-height: 1.6; }
            .envelope-id { font-family: monospace; background: #e9ecef; padding: 5px 10px; border-radius: 4px; }
        </style>
    </head>
    <body>
        <div class="message">
            <h1>⏳ Documento en Procesamiento</h1>
            <p>El documento firmado aún se está procesando en DocuSign.</p>
            <p>Por favor, intenta acceder nuevamente en unos minutos.</p>
            <p><small>Envelope ID: <span class="envelope-id"><?php echo htmlspecialchars($envelopeId); ?></span></small></p>
            <p><a href="javascript:location.reload()">🔄 Recargar</a></p>
        </div>
    </body>
    </html>
    <?php
    exit;
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
