<?php
/** ORVIX PAY AI Assistant backend. Render proxies Gemini; only AI Assistant is affected. */
define('ORVIX_JSON', true);
require_once __DIR__ . '/includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function ai_out(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

try {
    $merchant = current_merchant();
    if (!$merchant) ai_out(['ok' => false, 'error' => 'Please login again and reopen AI Assistant.'], 401);
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') ai_out(['ok' => false, 'error' => 'POST only.'], 405);

    $sentCsrf = (string)($_SERVER['HTTP_X_CSRF'] ?? '');
    if (!isset($_SESSION['csrf']) || !hash_equals((string)$_SESSION['csrf'], $sentCsrf)) {
        ai_out(['ok' => false, 'error' => 'Security check failed. Refresh the page and try again.'], 400);
    }

    $window = $_SESSION['ai_win'] ?? ['t' => time(), 'n' => 0];
    if (!is_array($window) || time() - (int)($window['t'] ?? 0) > 3600) $window = ['t' => time(), 'n' => 0];
    if ((int)($window['n'] ?? 0) >= 30) ai_out(['ok' => false, 'error' => 'Hourly AI limit reached. Please try again later.'], 429);
    $window['n'] = (int)($window['n'] ?? 0) + 1;
    $_SESSION['ai_win'] = $window;

    $input = json_decode(file_get_contents('php://input') ?: '', true);
    $messages = [];
    foreach (array_slice((array)($input['messages'] ?? []), -12) as $item) {
        if (!is_array($item)) continue;
        $role = ($item['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
        $content = mb_substr(trim((string)($item['content'] ?? '')), 0, 4000);
        if ($content !== '') $messages[] = ['role' => $role, 'content' => $content];
    }
    if (!$messages || end($messages)['role'] !== 'user') ai_out(['ok' => false, 'error' => 'Please enter a message and try again.'], 400);

    // bootstrap.php loads the root config.php, where these constants are defined.
    $renderBase = defined('ORVIX_IMAP_API_URL') ? rtrim((string)ORVIX_IMAP_API_URL, '/') : '';
    $bridgeKey = defined('ORVIX_IMAP_API_KEY') ? trim((string)ORVIX_IMAP_API_KEY) : '';
    if (!valid_http_url($renderBase) || $bridgeKey === '') {
        error_log('ORVIX AI: Render URL/key missing from config.php');
        ai_out(['ok' => false, 'error' => 'AI backend configuration is missing. Check the Render URL and bridge key in config.php.'], 503);
    }
    if (!function_exists('curl_init')) {
        error_log('ORVIX AI: PHP cURL extension is unavailable');
        ai_out(['ok' => false, 'error' => 'This hosting account does not have PHP cURL enabled. Contact hosting support.'], 503);
    }

    $context = "\n\nUSER CONTEXT: merchant API " . (merchant_live($merchant) ? 'is LIVE' : 'is NOT live yet (they must open Connect Merchant first)')
        . '; plan ' . (is_premium($merchant) ? 'Premium' : 'Free') . '. Site: ' . site_url() . '. Never ask for their real API token.';
    $payload = [
        // Do not pass the Admin-panel model here; Render's GEMINI_MODEL controls the model.
        'messages' => array_merge(
            [['role' => 'system', 'content' => ai_system_prompt() . $context]],
            $messages
        ),
    ];

    session_write_close();
    $curl = curl_init($renderBase . '/v1/ai-chat');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_TIMEOUT => 55,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $bridgeKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
    ]);
    $raw = curl_exec($curl);
    $http = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if ($raw === false) {
        error_log('ORVIX AI Render cURL error: ' . $curlError);
        ai_out(['ok' => false, 'error' => 'Could not connect to the AI backend. Check the Render service and try again.'], 502);
    }

    $response = json_decode((string)$raw, true);
    if ($http < 200 || $http >= 300 || !is_array($response) || empty($response['ok']) || !is_string($response['reply'] ?? null) || trim($response['reply']) === '') {
        $code = is_array($response) ? (string)($response['error'] ?? 'unknown_error') : 'invalid_response';
        error_log('ORVIX AI Render response HTTP ' . $http . ', code=' . preg_replace('/[^a-z0-9_-]/i', '', $code));
        $friendly = [
            'unauthorized' => 'Render bridge key mismatch. Make sure config.php and Render ORVIX_IMAP_API_KEY match.',
            'ai_not_configured' => 'Gemini API key is missing in Render Environment.',
            'ai_key_invalid' => 'Gemini API key was rejected. Check GEMINI_API_KEY in Render Environment.',
            'ai_provider_error' => 'Gemini returned an error. Check the model name and Render logs.',
            'ai_upstream_unreachable' => 'Render could not reach Gemini. Check Render logs and Gemini service status.',
            'invalid_messages' => 'The message format was not accepted. Refresh AI Assistant and try again.',
        ];
        ai_out(['ok' => false, 'error' => $friendly[$code] ?? ('AI backend error (HTTP ' . $http . '). Check Render logs.')], 502);
    }

    ai_out(['ok' => true, 'reply' => $response['reply']]);
} catch (Throwable $error) {
    error_log('ORVIX AI PHP exception: ' . $error->getMessage());
    ai_out(['ok' => false, 'error' => 'The AI request failed inside the website. Check InfinityFree Error Logs.'], 500);
}
