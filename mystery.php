<?php
require_once __DIR__ . '/includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
$m = current_merchant();
if (!$m || !merchant_live($m)) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Please log in and connect FamPay first.']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals(csrf_token(), (string)($_SERVER['HTTP_X_CSRF'] ?? ''))) {
    http_response_code(400); echo json_encode(['ok' => false, 'error' => 'Invalid request. Refresh the page.']); exit;
}
[$ok, $msg, $r] = mb_open($m);
if (!$ok) { echo json_encode(['ok' => false, 'error' => $msg]); exit; }
echo json_encode(['ok' => true, 'label' => $r['label'], 'rtype' => $r['type'], 'value' => $r['value'], 'left' => mb_left((int)$m['id'])]);
