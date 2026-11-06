<?php
require_once __DIR__ . '/_auth.php';
$in = api_input();
$m  = api_merchant($in);
$id = (string)($in['order_id'] ?? '');
if (!preg_match('/^ORX[0-9A-Z]{6,30}$/', $id)) api_fail(400, 'VALIDATION_ERROR', 'order_id is invalid.');
$o = get_order($id);
if (!$o || (int)$o['merchant_id'] !== (int)$m['id']) api_fail(404, 'NOT_FOUND', 'Order not found.');
$v = verify_order($o);               // server-side FamApi verification
if ($v['check'] === 'error' && $v['fatal']) api_fail(502, 'VERIFY_FAILED', $v['error']);
$o = $v['order'];
echo json_encode(['success' => true, 'order' => [
    'order_id'  => $o['order_id'],
    'amount'    => (float)$o['amount'],
    'status'    => $o['status'],
    'reference' => $o['merchant_ref'],
    'paid_at'   => $o['paid_at'],
], 'verified' => $v['check'] !== 'error']);
