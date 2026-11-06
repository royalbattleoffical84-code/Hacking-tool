<?php
define('ORVIX_NO_SESSION', true);
define('ORVIX_JSON', true);
require_once __DIR__ . '/../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function api_fail(int $http, string $code, string $msg): void {
    http_response_code($http);
    echo json_encode(['success' => false, 'error' => ['code' => $code, 'message' => $msg]]);
    exit;
}
function api_input(): array {
    $in = $_POST + $_GET;
    if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
        $j = json_decode(file_get_contents('php://input'), true);
        if (is_array($j)) $in = $j + $in;
    }
    return $in;
}
function api_merchant(array $in): array {
    $tok = $_SERVER['HTTP_X_ORVIX_TOKEN'] ?? ($in['api_token'] ?? ($in['token'] ?? ($in['api_key'] ?? '')));
    if (!is_string($tok) || $tok === '') api_fail(401, 'INVALID_TOKEN', 'Missing API token.');
    $st = db()->prepare('SELECT * FROM merchants WHERE api_key = ?'); $st->execute([$tok]);
    $m = $st->fetch();
    if (!$m) api_fail(401, 'INVALID_TOKEN', 'Invalid API token.');
    if ($m['status'] !== 'active') api_fail(403, 'BLOCKED', 'Account blocked.');
    return $m;
}
