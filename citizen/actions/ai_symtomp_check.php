<?php
/**
 * DengResQ | AI Symptom First-Check Proxy
 * File: citizen/actions/ai_symptom_check.php
 *
 * Proxies requests to the Anthropic API for the AI symptom chatbot.
 * The session check ensures only logged-in citizens can use this.
 */

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']); exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Method not allowed']); exit();
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['messages'])) {
    echo json_encode(['error' => 'Invalid request']); exit();
}

$apiKey = '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'; // Get from console.groq.com → API Keys

// Build messages array with system prompt prepended (Groq uses OpenAI format)
$messages = [];
$systemPrompt = $input['system'] ?? '';
if ($systemPrompt) {
    $messages[] = ['role' => 'system', 'content' => $systemPrompt];
}
foreach ($input['messages'] as $msg) {
    $messages[] = $msg;
}

$payload = json_encode([
    'model'       => 'llama-3.3-70b-versatile', // fast & capable on Groq
    'max_tokens'  => 1024,
    'temperature' => 0.5,
    'messages'    => $messages,
]);

$ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ],
]);

$raw      = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Convert Groq's OpenAI-format response → Anthropic-style so the frontend works unchanged
$groq = json_decode($raw, true);
if (isset($groq['choices'][0]['message']['content'])) {
    $converted = [
        'content' => [
            ['type' => 'text', 'text' => $groq['choices'][0]['message']['content']]
        ]
    ];
    http_response_code(200);
    echo json_encode($converted);
} else {
    // Pass through error so frontend shows connection message
    http_response_code($httpCode);
    echo $raw;
}
