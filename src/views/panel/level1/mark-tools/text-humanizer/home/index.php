<?php

use App\Utils\CSRF;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router(); 

// Manejamos la vista del formulario (GET)
$router->get(function () {
    return TemplateResponse::render(__DIR__ . "/index.twig", [
        'humanizedText' => nl2br(html_entity_decode($_SESSION['humanizedText'] ?? '')) ?? null,
        'originalText' => $_SESSION['originalText'] ?? null,
        'keywords' => $_SESSION['keywords'] ?? null,
    ]);
});

// Procesamos el formulario cuando se envía (POST)
$router->post(function () {
    CSRF::validateCSRF(); // Validación CSRF para seguridad

    // Capturar datos del formulario
    $text = $_POST['textInput'] ?? null;
    $minWordCount = 800; 

    // Validar que el texto haya sido ingresado
    if (empty($text)) {
        
        MessageUtil::setMessage("Please enter text to optimize.");
        LocationUtils::redirectInternal("panel/mark-tools/text-humanizer/home");
        return;
    }

    // Configuración de la API externa
    $apiUrl = "https://api.openai.com/v1/chat/completions"; // Asegúrate de que la URL es correcta

    // Datos a enviar a la API (restaurado completamente)
    $data = [
        "model" =>  "gpt-4o-2024-08-06",
        "messages" => [ 
            [
                "role" => "system",
                "content" => "You are a helpful assistant that tries to make the text sound as human as possible. DO NOT modify the titles or subtitles that appear before the colons (':'). Only make changes to the content after the colons,  The text should include informal language, occasional minor errors like typos, grammatical mistakes, and use of uncommon synonyms. Also, make the text feel slightly disjointed, as if it was written quickly. Add more filler words and phrases like 'I think,' 'like,'   and intentionally introduce some typos, such as doubling letters ('llike this'), missing letters ('thn'), or using incorrect words ('their' instead of 'they’re'. 
                
                Detect any empty or filler sentence. Keep shifting into first-person anecdotes from our real event gigs across Miami-Dade and Broward—drop neighborhoods, times, and weekday moments. Show client behaviors that create pressure and describe exactly how I fixed things. Avoid repetitive connectors. Add sharp, reflective questions that make the reader feel I’m thinking out loud.)."
            ],
            [
                "role" => "user",
                "content" => "Please rewrite the following text in a way that sounds natural, human-like, and not too perfect. DO NOT modify the titles or subtitles that appear before the colons (':'). Only make changes to the content after the colons,   Ensure it has at least $minWordCount words, and vary sentence structures while adding more typos and minor mistakes: " . $text
            ]
        ],
        "max_tokens" => 2000,
        "temperature" => 0.95,
    ];

    // Configurar y ejecutar la petición cURL
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer '.$_ENV["OPENAI_TOKEN"] // Cambia esto por tu API Key real
    ]);

    $apiResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Verificamos la respuesta de la API
    if ($httpCode == 200 && $apiResponse) {
        $responseData = json_decode($apiResponse, true);
        $humanizedText = $responseData['choices'][0]['message']['content'] ?? 'Error processing text';
    } else {
        $humanizedText = 'Failed to process text. Please try again.';
    }

   // var_dump($humanizedText);

    // Guardamos los resultados en sesión
    $_SESSION['humanizedText'] = $humanizedText;
    $_SESSION['originalText'] = $text; 

    MessageUtil::setMessage("Text successfully optimized.");
    LocationUtils::redirectInternal("panel/mark-tools/text-humanizer/home");
});

try {
    $router->run();
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
