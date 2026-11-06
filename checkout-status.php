<?php
/** Polling endpoint for checkout.php. Har call server-side FamApi verify karta hai. */
define('ORVIX_NO_SESSION', true);
define('ORVIX_JSON', true);
require_once __DIR__ . '/includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$id = (string)($_GET['order_id'] ?? '');
if (!preg_match('/^ORX[0-9A-Z]{6,30}$/', $id)) { http_response_code(400); echo json_encode(['status' => 'unknown', 'fatal' => true, 'error' => 'Invalid order id.']); exit; }
$o = get_order($id);
if (!$o) { http_response_code(404); echo json_encode(['status' => 'unknown', 'fatal' => true, 'error' => 'Order not found.']); exit; }
$v = verify_order($o);
echo json_encode([
    'status'    => $v['order']['status'],
    'error'     => $v['error'],
    'transient' => $v['transient'],
    'fatal'     => $v['fatal'],
    'reject'    => $v['order']['reject_reason'] ?? null,
]);
