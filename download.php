<?php
/** Serves a pay-link file ONLY for an order whose status is success (set by server-side FamApi verification). */
require_once __DIR__ . '/includes/bootstrap.php';
$id = (string)($_GET['order_id'] ?? '');
$o  = preg_match('/^ORX[0-9A-Z]{6,30}$/', $id) ? get_order($id) : null;
if (!$o || $o['status'] !== 'success') { http_response_code(403); exit('Payment not completed.'); }
$pl = paylink_for_order($o);
if (!$pl || !$pl['file_path'] || !preg_match('/^[a-f0-9]{24}\.[a-z0-9]{2,4}$/', $pl['file_path'])) { http_response_code(404); exit('No file.'); }
$path = ORVIX_FILE_DIR . '/' . $pl['file_path'];
if (!is_file($path)) { http_response_code(404); exit('File missing.'); }
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $pl['file_name'] ?: $pl['file_path']) . '"');
header('Content-Length: ' . filesize($path)); header('X-Content-Type-Options: nosniff');
readfile($path);
