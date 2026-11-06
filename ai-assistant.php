<?php
/** ORVIX PAY AI Assistant bridge. Logged-in merchants only. */
define('ORVIX_JSON', true);
require_once __DIR__ . '/includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

function ai_out(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    ai_out(['ok' => false, 'error' => 'POST only.'], 405);
}
$m = current_merchant();
if (!$m) ai_out(['ok' => false, 'error' => 'Please login again, then refresh the AI page.'], 401);
$sent = (string)($_SERVER['HTTP_X_CSRF'] ?? '');
if (!isset($_SESSION['csrf']) || !hash_equals((string)$_SESSION['csrf'], $sent)) {
    ai_out(['ok' => false, 'error' => 'Security check failed. Refresh the AI page and try again.'], 400);
}

$input = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($input)) ai_out(['ok' => false, 'error' => 'Invalid request body. Refresh and try again.'], 400);
$msgs = [];
foreach (array_slice((array)($input['messages'] ?? []), -12) as $item) {
    if (!is_array($item)) continue;
    $role = ($item['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
    $content = trim((string)($item['content'] ?? ''));
    if (function_exists('mb_substr')) $content = mb_substr($content, 0, 4000, 'UTF-8');
    else $content = substr($content, 0, 4000);
    if ($content !== '') $msgs[] = ['role' => $role, 'content' => $content];
}
if (!$msgs || end($msgs)['role'] !== 'user') ai_out(['ok' => false, 'error' => 'Please enter a message first.'], 400);

// These two constants are configured in the existing root config.php; no other project files are changed.
$base = defined('ORVIX_IMAP_API_URL') ? rtrim((string)ORVIX_IMAP_API_URL, '/') : '';
$key  = defined('ORVIX_IMAP_API_KEY') ? trim((string)ORVIX_IMAP_API_KEY) : '';
if (!valid_http_url($base) || $key === '') {
    error_log('ORVIX AI: bridge URL/key is not configured in config.php.');
    ai_out(['ok' => false, 'error' => 'AI bridge is not configured. Check ORVIX_IMAP_API_URL and ORVIX_IMAP_API_KEY in config.php.'], 503);
}
if (!function_exists('curl_init')) {
    ai_out(['ok' => false, 'error' => 'PHP cURL is unavailable on this hosting account.'], 503);
}

$ctx = "ORVIX PAY merchant context: status " . (merchant_live($m) ? 'LIVE' : 'not live yet') .
       '; plan ' . (is_premium($m) ? 'Premium' : 'Free') . '. Site: ' . site_url() .
       '. Never request or expose the merchant API token, passwords, or private keys.';
$system = trim(ai_system_prompt()) . "\n\n" . $ctx;
$payload = ['messages' => array_merge([['role' => 'system', 'content' => $system]], $msgs)];
$body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($body === false) ai_out(['ok' => false, 'error' => 'Could not encode the AI request.'], 500);

// Release PHP session lock while waiting for Render/Gemini.
session_write_close();
$ch = curl_init($base . '/v1/ai-chat');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_TIMEOUT => 55,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Accept: application/json',
        'Authorization: Bearer ' . $key,
    ],
    CURLOPT_POSTFIELDS => $body,
]);
$raw = curl_exec($ch);
$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);
if ($raw === false) {
    error_log('ORVIX AI bridge cURL error: ' . $curlError);
    ai_out(['ok' => false, 'error' => 'Could not reach the AI bridge. Please check the Render service and try again.'], 502);
}
$data = json_decode((string)$raw, true);
if (!is_array($data)) {
    error_log('ORVIX AI bridge returned invalid JSON; HTTP ' . $http . '.');
    ai_out(['ok' => false, 'error' => 'AI bridge returned an invalid response (HTTP ' . $http . '). Check the Render logs.'], 502);
}
if ($http < 200 || $http >= 300 || empty($data['ok'])) {
    $message = trim((string)($data['error'] ?? 'AI service request failed.'));
    error_log('ORVIX AI bridge HTTP ' . $http . ': ' . substr($message, 0, 300));
    ai_out(['ok' => false, 'error' => $message !== '' ? $message : 'AI service request failed.'], $http >= 400 && $http <= 599 ? $http : 502);
}
$reply = $data['reply'] ?? null;
if (!is_string($reply) || trim($reply) === '') {
    error_log('ORVIX AI bridge response did not contain reply.');
    ai_out(['ok' => false, 'error' => 'AI service returned an empty reply.'], 502);
}
ai_out(['ok' => true, 'reply' => $reply]);
