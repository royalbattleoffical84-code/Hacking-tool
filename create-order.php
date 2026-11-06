<?php
require_once __DIR__ . '/_auth.php';
if (!in_array($_SERVER['REQUEST_METHOD'], ['POST', 'GET'], true)) api_fail(405, 'METHOD', 'Use POST.');
$in = api_input();
$m  = api_merchant($in);
$r  = create_orvix_order($m, $in['amount'] ?? '', isset($in['order_id']) ? (string)$in['order_id'] : null,
        isset($in['redirect_url']) ? (string)$in['redirect_url'] : null,
        isset($in['customer_mobile']) ? (string)$in['customer_mobile'] : null);
if (!$r['ok']) api_fail($r['http'] >= 400 ? $r['http'] : 400, $r['code'] ?? 'ERROR', $r['error']);
$o = $r['order'];
http_response_code(201);
echo json_encode(['success' => true, 'order' => [
    'order_id'    => $o['order_id'],
    'amount'      => (float)$o['amount'],
    'status'      => $o['status'],
    'reference'   => $o['merchant_ref'],
    'payment_url' => site_url('checkout.php?order_id=' . urlencode($o['order_id'])),
    'expires_at'  => $o['expires_at'],
]]);
