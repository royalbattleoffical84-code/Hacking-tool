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

// These constants are configured in the existing root config.php.
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
// Up to 3 tries: Render can drop the first connection while it is waking up or redeploying.
$raw = false; $http = 0; $curlError = ''; $curlErrno = 0;
for ($try = 1; $try <= 3; $try++) {
    $ch = curl_init($base . '/v1/ai-chat');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 20,
        // Must be longer than the Render/Gemini 60-second upstream budget.
        CURLOPT_TIMEOUT => 75,
        CURLOPT_FOLLOWLOCATION => false,
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
    $curlErrno = curl_errno($ch);
    curl_close($ch);

    // Success or a real answer from our bridge: stop.
    if ($raw !== false && !in_array($http, [502, 503, 504], true)) break;
    // Request timed out after a long wait: do not repeat (would take too long).
    if ($raw === false && $curlErrno === 28) break;
    error_log('ORVIX AI bridge try ' . $try . ' failed (errno ' . $curlErrno . ', HTTP ' . $http . '): ' . $curlError);
    // Render gateway errors that carry our own JSON are real answers, keep them.
    if ($raw !== false) { $j = json_decode((string)$raw, true); if (is_array($j) && isset($j['error'])) break; }
    if ($try < 3) sleep(2);
}
if ($raw === false) {
    error_log('ORVIX AI bridge cURL error (' . $curlErrno . '): ' . $curlError);
    ai_out(['ok' => false, 'error' => $curlErrno === 28
        ? 'AI response timed out. Please try again.'
        : 'Could not reach the AI bridge. Please try again in a few seconds. (code ' . $curlErrno . ')'], 502);
}
$data = json_decode((string)$raw, true);
if (!is_array($data)) {
    error_log('ORVIX AI bridge returned invalid JSON; HTTP ' . $http . '.');
    ai_out(['ok' => false, 'error' => 'AI bridge returned an invalid response (HTTP ' . $http . '). Check the Render logs.'], 502);
}
if ($http < 200 || $http >= 300 || empty($data['ok'])) {
    $message = trim((string)($data['error'] ?? 'AI service request failed.'));
    error_log('ORVIX AI bridge HTTP ' . $http . ': ' . substr($message, 0, 300));
    $friendly = [
        'ai_provider_busy'               => 'AI is busy right now. Please try again in a moment.',
        'ai_provider_timeout'            => 'AI took too long to respond. Please try again.',
        'ai_upstream_unreachable'        => 'Could not reach the AI service. Please try again.',
        'ai_model_or_endpoint_not_found' => 'AI model is not available. Please contact support.',
        'ai_key_invalid'                 => 'AI key is invalid. Please contact support.',
        'ai_not_configured'              => 'AI is not configured yet.',
        'ai_provider_error'              => 'AI service error. Please try again.',
        'invalid_messages'               => 'Invalid message. Refresh and try again.',
        'unauthorized'                   => 'AI bridge key mismatch. Check ORVIX_IMAP_API_KEY in config.php.',
    ];
    $message = $friendly[$message] ?? $message;
    ai_out(['ok' => false, 'error' => $message !== '' ? $message : 'AI service request failed.'], $http >= 400 && $http <= 599 ? $http : 502);
}
$reply = $data['reply'] ?? null;
if (!is_string($reply) || trim($reply) === '') {
    error_log('ORVIX AI bridge response did not contain reply.');
    ai_out(['ok' => false, 'error' => 'AI service returned an empty reply.'], 502);
}
ai_out(['ok' => true, 'reply' => $reply]);
