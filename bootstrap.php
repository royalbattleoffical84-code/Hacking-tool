<?php
/**
 * ORVIX PAY — bootstrap: DB (auto-migrating), session, auth, FamApi client, order logic.
 */
if (!defined('ORVIX')) define('ORVIX', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/labels.php';
require_once __DIR__ . '/themes.php';
require_once __DIR__ . '/schema.php';

date_default_timezone_set(defined('TIMEZONE') ? TIMEZONE : 'Asia/Kolkata');

function is_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443);
}

if (!defined('ORVIX_NO_SESSION') && session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => is_https()]);
    session_start();
}

/* ------------------------------------------------------------------ helpers */
function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function now(): string { return date('Y-m-d H:i:s'); }

function site_url(string $path = ''): string {
    if (defined('SITE_URL') && SITE_URL !== '') {
        $base = rtrim(SITE_URL, '/');
    } else {
        $root = str_replace('\\', '/', realpath(__DIR__ . '/..'));
        $doc  = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '');
        $rel  = ($doc !== '' && strpos($root, $doc) === 0) ? substr($root, strlen($doc)) : '';
        $base = (is_https() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $rel;
    }
    return $base . '/' . ltrim($path, '/');
}
function redirect(string $to): void { header('Location: ' . $to); exit; }
function flash(string $type, string $msg): void { $_SESSION['flash'][] = [$type, $msg]; }

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }
function csrf_check(): void {
    $sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? '');
    if (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string)$sent)) {
        http_response_code(400); die('Security check failed (CSRF). Refresh the page and try again.');
    }
}
function valid_http_url(?string $u): bool {
    return $u !== null && $u !== '' && strlen($u) <= 2000 && preg_match('#^https?://#i', $u) && filter_var($u, FILTER_VALIDATE_URL);
}

/* ----------------------------------------------------------------------- DB */
function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    try {
        $opts = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 10,
        ];
        if (DB_SSL) {
            $opts[PDO::MYSQL_ATTR_SSL_CA] = '/etc/ssl/certs/ca-certificates.crt';
            $opts[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
        }
        $pdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, $opts);
    } catch (Throwable $ex) {
        error_log('ORVIX DB connect failed: ' . $ex->getMessage());
        http_response_code(500);
        if (defined('ORVIX_JSON')) { header('Content-Type: application/json'); die(json_encode(['success' => false, 'error' => ['code' => 'DB_ERROR', 'message' => 'Database connection failed.']])); }
        die('Database connection failed. Check the DB details in config.php.');
    }
    // auto-migrate (once) — adds v2 tables/columns without touching existing data
    try {
        $v = 0;
        try { $v = (int)$pdo->query("SELECT v FROM settings WHERE k = 'schema_v'")->fetchColumn(); } catch (Throwable $ex) { $v = 0; }
        if ($v < ORVIX_SCHEMA_VERSION) orvix_migrate($pdo);
    } catch (Throwable $ex) { error_log('ORVIX migrate: ' . $ex->getMessage()); }
    return $pdo;
}

require_once __DIR__ . '/gmail.php';
require_once __DIR__ . '/wallet.php';
require_once __DIR__ . '/engage.php';

/* --------------------------------------------------------------------- auth */
function current_merchant(): ?array {
    static $m = false;
    if ($m !== false) return $m;
    if (empty($_SESSION['mid'])) return $m = null;
    $st = db()->prepare('SELECT * FROM merchants WHERE id = ? AND status = "active"');
    $st->execute([$_SESSION['mid']]);
    $m = $st->fetch() ?: null;
    return $m = ($m ? maybe_auto_renew($m) : null);
}
/** API key is live only after the merchant connected their FAM account (admins are always live). */
function merchant_live(array $m): bool { return $m['role'] === 'admin' || trim((string)($m['famapi_connection_id'] ?? '')) !== '' || gm_ready($m); }

function require_login(): array {
    $m = current_merchant();
    if (!$m) redirect(site_url('login.php'));
    return $m;
}
/** Onboarding gate (like ZapUPI): nothing but Connect Merchant is usable until the FAM merchant is connected. */
function require_live(): array {
    $m = require_login();
    if (!merchant_live($m)) {
        flash('info', 'Connect your FAM merchant first to activate your account and API token.');
        redirect(site_url('connect.php'));
    }
    return $m;
}
function require_admin(): array {
    $m = require_login();
    if ($m['role'] !== 'admin') { http_response_code(403); die('Admin only.'); }
    return $m;
}
function new_api_key(): string { return 'orvix_live_' . bin2hex(random_bytes(16)); }

/* ---------------------------------------------------------- amount handling */
/** Rule 2: always a plain number. "Rs 1,999" -> 1999.0. Negative / junk -> null. */
function sanitize_amount($raw): ?float {
    if (preg_match('/^\s*-/', (string)$raw)) return null;
    $clean = preg_replace('/[^0-9.]/', '', (string)$raw);
    if ($clean === '' || substr_count($clean, '.') > 1 || !is_numeric($clean)) return null;
    $n = round((float)$clean, 2);
    return $n >= 1 ? $n : null;
}

/* ------------------------------------------------------------ FamApi client */
function fam_result(bool $ok, int $http, string $code, string $message, $data = null): array {
    return ['ok' => $ok, 'http' => $http, 'code' => $code, 'message' => $message, 'data' => $data,
            'transient' => !$ok && ($http === 0 || $http === 429 || $http >= 500)];
}
function famapi_request(string $method, string $path, ?array $body = null, int $retries = 0): array {
    $key = defined('FAMAPI_KEY') ? FAMAPI_KEY : '';
    if ($key === '' || $key === 'YOUR_FAMAPI_KEY') return fam_result(false, 401, 'NO_KEY', 'FamApi key is not set in config.php (YOUR_FAMAPI_KEY).');
    $attempt = 0; $renderFailed = false;
    while (true) {
        // Create-order goes through the Render relay (InfinityFree -> Render -> FamApi). Falls back to direct if Render is unreachable.
        $viaRender = ($method === 'POST' && $path === '/api/v1/orders' && !$renderFailed
            && defined('ORVIX_IMAP_API_URL') && defined('ORVIX_IMAP_API_KEY')
            && trim((string)ORVIX_IMAP_API_URL) !== '' && trim((string)ORVIX_IMAP_API_KEY) !== '');
        $ch = curl_init($viaRender ? rtrim((string)ORVIX_IMAP_API_URL, '/') . '/v1/fam-create-order' : rtrim(FAMAPI_BASE, '/') . $path);
        $headers = ['Accept: application/json', 'X-FamApi-Key: ' . $key];
        if ($viaRender) $headers[] = 'Authorization: Bearer ' . trim((string)ORVIX_IMAP_API_KEY);
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $viaRender ? 30 : 20, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_FOLLOWLOCATION => false];
        if ($method === 'POST') {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode($body ?? new stdClass());
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch); $errno = curl_errno($ch); $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $cerr = curl_error($ch);
        curl_close($ch);

        if ($viaRender && ($raw === false || $errno || in_array($http, [502, 503, 504], true) || stripos((string)$raw, 'RELAY_FAILED') !== false)) {
            error_log('ORVIX Render relay failed (errno ' . $errno . ', HTTP ' . $http . '): ' . $cerr . ' - falling back to direct FamApi');
            $renderFailed = true;
            continue;
        }
        if ($raw === false || $errno) {
            $res = fam_result(false, 0, 'NETWORK', 'Network error: ' . $cerr);
        } else {
            $json = json_decode($raw, true);
            if (!is_array($json))            $res = fam_result(false, $http, 'BAD_RESPONSE', 'Invalid response from FamApi (HTTP ' . $http . ').');
            elseif (!empty($json['success'])) $res = fam_result(true, $http, 'OK', 'OK', $json);
            else $res = fam_result(false, $http, (string)($json['error']['code'] ?? 'ERROR'), (string)($json['error']['message'] ?? 'Unknown FamApi error'));
        }
        if ($res['ok'] || !$res['transient'] || $attempt >= $retries) break;
        $attempt++;
        sleep($res['http'] === 429 ? 2 * $attempt : 1);
    }
    if (!$res['ok']) error_log('ORVIX FamApi ' . $method . ' ' . $path . ' -> ' . $res['http'] . ' ' . $res['code'] . ' ' . $res['message']);
    return $res;
}
/** Rule 3: real handling per the error table. Returns [user-safe message, fatal?]. */
function fam_explain(array $r): array {
    $http = $r['http']; $msg = strtolower($r['message']);
    if ($r['code'] === 'NO_KEY' || $http === 401) return ['The payment service is misconfigured (API key). Please contact the site owner.', true];
    if ($http === 400 && strpos($msg, 'amount') !== false) return ['Invalid amount. Use a plain number (e.g. 499), minimum 1.', true];
    if ($http === 400 && strpos($msg, 'connectionid') !== false) return ['The merchant connection is invalid. Reconnect it from Connect Merchant.', true];
    if ($http === 400) return ['The request was not valid: ' . $r['message'], true];
    if ($http === 403) return ['The monthly order limit of the payment service has been reached. Please contact the site owner.', true];
    if ($http === 404) return ['Order not found at the payment service (wrong id or different key).', true];
    if ($http === 429) return ['Too many requests right now. Retrying shortly…', false];
    if ($http >= 500)  return ['The payment server is busy. Retrying shortly…', false];
    if ($http === 0)   return ['Network is slow or offline. Retrying shortly…', false];
    return ['Something went wrong: ' . $r['message'], true];
}

/* ------------------------------------------------------------- order logic */
function new_order_id(): string { return 'ORX' . date('ymd') . strtoupper(bin2hex(random_bytes(4))); }

/**
 * Create local order + FamApi order. Shared by the merchant API, Test Payment and wallet deposits.
 * $opts: kind ('payment'|'deposit'), conn (connectionId override, used for deposits -> admin's FamPay)
 * Returns ['ok'=>bool, 'order'=>row, 'error'=>msg, 'code'=>str, 'http'=>int]
 */
function create_orvix_order(array $merchant, $amountRaw, ?string $ref = null, ?string $redirectUrl = null, ?string $mobile = null, array $opts = []): array {
    $kind   = $opts['kind'] ?? 'payment';
    $amount = sanitize_amount($amountRaw);
    if ($amount === null) return ['ok' => false, 'http' => 400, 'code' => 'VALIDATION_ERROR', 'error' => 'amount must be a positive number (minimum 1)'];

    if ($kind === 'deposit') {
        $conn = (string)($opts['conn'] ?? '');
    } else {
        if (!merchant_live($merchant)) return ['ok' => false, 'http' => 403, 'code' => 'NOT_LIVE', 'error' => 'API token is not live. Connect your FAM merchant first.'];
        $conn = trim((string)($merchant['famapi_connection_id'] ?? ''));
        $gmOrder = gm_mode($merchant);
        $cap = $gmOrder ? 0 : order_cap_for($merchant);
        if ($cap > 0 && orders_this_month((int)$merchant['id']) >= $cap) {
            return ['ok' => false, 'http' => 403, 'code' => 'PLAN_LIMIT', 'error' => 'Monthly order limit of your plan (' . $cap . ') is reached. Upgrade your plan.'];
        }
        $need = fee_for($merchant, $amount);
        if (!$gmOrder && $need > 0 && (float)$merchant['wallet'] < $need) {
            return ['ok' => false, 'http' => 402, 'code' => 'INSUFFICIENT_WALLET', 'error' => 'API Wallet balance is too low. Deposit funds in API Wallet.'];
        }
    }
    if ($redirectUrl !== null && $redirectUrl !== '' && !valid_http_url($redirectUrl)) {
        return ['ok' => false, 'http' => 400, 'code' => 'VALIDATION_ERROR', 'error' => 'redirect_url must be a valid http(s) URL'];
    }
    $ref    = $ref !== null ? mb_substr(trim($ref), 0, 100) : null;
    $mobile = $mobile !== null ? mb_substr(preg_replace('/[^0-9+]/', '', $mobile), 0, 20) : null;

    if ($kind !== 'deposit' && gm_mode($merchant)) {      // FamPay via Gmail + UTR (no FamApi call)
        $orderId = new_order_id();
        db()->prepare('INSERT INTO orders (order_id, merchant_id, amount, merchant_ref, customer_mobile, redirect_url, famapi_order_id, upi_id, status, kind, pay_method, created_at, expires_at)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$orderId, $merchant['id'], $amount, $ref, $mobile, $redirectUrl ?: null, 'gmail', $merchant['gm_upi'], 'pending', $kind, 'gmail', now(), date('Y-m-d H:i:s', time() + ORDER_EXPIRY_MIN * 60)]);
        return ['ok' => true, 'order' => get_order($orderId)];
    }
    $orderId = new_order_id();
    $body = ['amount' => $amount, 'currency' => 'INR', 'reference' => $orderId];
    if ($conn !== '') $body['connectionId'] = $conn;

    $res = famapi_request('POST', '/api/v1/orders', $body, 1);
    if (!$res['ok']) {
        [$friendly] = fam_explain($res);
        return ['ok' => false, 'http' => $res['http'] ?: 502, 'code' => $res['code'], 'error' => $friendly];
    }
    $fo = $res['data']['order'] ?? [];
    if (empty($fo['id']) || empty($fo['upiId'])) {
        error_log('ORVIX create order: unexpected FamApi payload ' . json_encode($res['data']));
        return ['ok' => false, 'http' => 502, 'code' => 'BAD_RESPONSE', 'error' => 'The payment server returned an incomplete response. Please try again.'];
    }
    db()->prepare('INSERT INTO orders (order_id, merchant_id, amount, merchant_ref, customer_mobile, redirect_url, famapi_order_id, upi_id, status, kind, created_at, expires_at)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$orderId, $merchant['id'], $amount, $ref, $mobile, $redirectUrl ?: null, $fo['id'], $fo['upiId'], 'pending', $kind, now(), date('Y-m-d H:i:s', time() + ORDER_EXPIRY_MIN * 60)]);
    return ['ok' => true, 'order' => get_order($orderId)];
}

function get_order(string $orderId): ?array {
    $st = db()->prepare('SELECT o.*, m.name AS merchant_name, m.business_name, m.logo_url, m.theme_pay, m.plan_until, m.gm_verify
                         FROM orders o JOIN merchants m ON m.id = o.merchant_id WHERE o.order_id = ?');
    $st->execute([$orderId]);
    return $st->fetch() ?: null;
}

/** Compare-and-set status change. Returns true only if THIS call performed the change. */
function change_order_status(array $o, string $new, bool $manual = false): bool {
    $paid = ($new === 'success') ? now() : null;
    $st = db()->prepare('UPDATE orders SET status = ?, paid_at = COALESCE(?, paid_at)' . ($manual ? ', manual = 1' : '') . ' WHERE id = ? AND status = ?');
    $st->execute([$new, $paid, $o['id'], $o['status']]);
    $changed = $st->rowCount() === 1;
    if ($changed && $new === 'success') settle_success(get_order($o['order_id']) ?: $o);
    return $changed;
}

/**
 * Rule 1: the ONLY automatic path to "success" is FamApi's GET /api/v1/orders/:id saying status === "success"
 * (and the amount matching). Returns ['order','check'=>ok|cached|error,'error','transient','fatal'].
 */
function verify_order(array $o): array {
    if (($o['pay_method'] ?? 'famapi') === 'gmail') return gm_verify_order($o);
    if (in_array($o['status'], ['success', 'failed', 'refunded'], true)) {
        return ['order' => $o, 'check' => 'cached', 'error' => null, 'transient' => false, 'fatal' => false];
    }
    if ($o['last_checked'] && (time() - strtotime($o['last_checked'])) < 3) {   // FamApi limit is shared (120/min)
        return ['order' => $o, 'check' => 'cached', 'error' => null, 'transient' => false, 'fatal' => false];
    }
    db()->prepare('UPDATE orders SET last_checked = ? WHERE id = ?')->execute([now(), $o['id']]);

    $res = famapi_request('GET', '/api/v1/orders/' . rawurlencode($o['famapi_order_id']), null, 0);
    if (!$res['ok']) {
        [$msg, $fatal] = fam_explain($res);
        return ['order' => $o, 'check' => 'error', 'error' => $msg, 'transient' => !$fatal, 'fatal' => $fatal];
    }
    $fo = $res['data']['order'] ?? [];
    $remote = (string)($fo['status'] ?? '');
    if (!in_array($remote, ['pending', 'success', 'failed', 'expired', 'refunded'], true)) {
        return ['order' => $o, 'check' => 'error', 'error' => 'Unknown status from the payment server.', 'transient' => true, 'fatal' => false];
    }
    $new = $remote;
    if ($remote === 'success') {
        if (isset($fo['amount']) && abs((float)$fo['amount'] - (float)$o['amount']) > 0.009) {
            error_log('ORVIX amount mismatch on ' . $o['order_id'] . ': local ' . $o['amount'] . ' remote ' . $fo['amount']);
            return ['order' => $o, 'check' => 'error', 'error' => 'Amount could not be verified. Please contact support.', 'transient' => false, 'fatal' => true];
        }
    } elseif ($remote === 'pending' && strtotime($o['expires_at']) < time()) {
        $new = 'expired';
    }
    if ($new !== $o['status']) {
        change_order_status($o, $new, false);
        $o = get_order($o['order_id']) ?: $o;
    }
    return ['order' => $o, 'check' => 'ok', 'error' => null, 'transient' => false, 'fatal' => false];
}

/**
 * Catches payments that were made while the payer's page was closed (and clears stale pending orders):
 * re-verifies this merchant's recent pending / just-expired orders with FamApi (Rule 1 still holds: only FamApi success counts).
 */
function reconcile_pending(int $mid, int $limit = 5): void {
    try {
        $st = db()->prepare("SELECT order_id FROM orders WHERE merchant_id = ? AND created_at >= ? AND (status = 'pending' OR (status = 'expired' AND (last_checked IS NULL OR last_checked < ?))) ORDER BY id DESC LIMIT " . (int)$limit);
        $st->execute([$mid, date('Y-m-d H:i:s', time() - 6 * 3600), date('Y-m-d H:i:s', time() - 120)]);
        $t0 = time();
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $oid) {
            if (time() - $t0 > 8) break;
            if ($o = get_order($oid)) verify_order($o);
        }
    } catch (Throwable $ex) { error_log('ORVIX reconcile: ' . $ex->getMessage()); }
}

require_once __DIR__ . '/layout.php';
