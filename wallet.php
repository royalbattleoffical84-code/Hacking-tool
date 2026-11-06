<?php
/** API Wallet, fees, premium, referral, settlement and webhooks. */

/* ---------------------------------------------------------------- settings */
function setting_defaults(): array {
    return [
        'fee_percent'          => '1',     // % of each successful order, charged from the merchant's API Wallet
        'fee_flat'             => '0',     // flat Rs per successful order
        'free_monthly_orders'  => '100',   // monthly order cap on the Free plan (0 = unlimited)
        'min_deposit'          => '100',
        'deposit_bonus'        => '0',     // % extra credited on deposit
        'ref_percent'          => '30',    // % of the fee shared with the referrer
        'ai_endpoint'          => 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions',   // Google Gemini (OpenAI-compatible)
        'ai_model'             => 'gemini-3.8-flash',
        'ai_key'               => '',
        'ai_prompt'            => '',      // empty => default prompt (default_ai_prompt())
        'support_link'         => '',
        'gm_unlock_fee'        => '0',     // one-time fee (from API Wallet) to unlock "connect your own Gmail/Fam"; 0 = free
        'site_name'            => '',      // gateway name shown everywhere (empty = SITE_NAME from config.php)
        'site_logo'            => '',      // uploaded logo path, or empty for assets/logo.png
        'ticker_text'          => 'Welcome to ORVIX PAY — fast UPI payments with your FamPay account.',
        'mb_enabled'           => '0',     // Mystery Box is OFF until the admin enables it
        'mb_title'             => 'Mystery Box',
        'mb_ends_at'           => '',
        'mb_daily_boxes'       => '1',
        'mb_rewards'           => '',
    ];
}
function settings_all(bool $reload = false): array {
    static $cache = null;
    if ($cache === null || $reload) {
        $cache = setting_defaults();
        try { foreach (db()->query('SELECT k, v FROM settings')->fetchAll() as $r) $cache[$r['k']] = (string)$r['v']; }
        catch (Throwable $ex) { error_log('ORVIX settings: ' . $ex->getMessage()); }
    }
    return $cache;
}
function setting(string $k, string $default = ''): string { $a = settings_all(); return $a[$k] ?? $default; }
function setting_set(string $k, string $v): void {
    $u = db()->prepare('UPDATE settings SET v = ? WHERE k = ?'); $u->execute([$v, $k]);
    if ($u->rowCount() === 0) {
        $c = db()->prepare('SELECT COUNT(*) FROM settings WHERE k = ?'); $c->execute([$k]);
        if ((int)$c->fetchColumn() === 0) db()->prepare('INSERT INTO settings (k, v) VALUES (?, ?)')->execute([$k, $v]);
    }
    settings_all(true);
}
function setting_num(string $k): float { return max(0.0, (float)preg_replace('/[^0-9.]/', '', setting($k, '0'))); }

/* ------------------------------------------------------------------ plans */
function plan_by_id(?int $id): ?array {
    if (!$id) return null;
    $st = db()->prepare('SELECT * FROM plans WHERE id = ?'); $st->execute([$id]);
    return $st->fetch() ?: null;
}
/** Subscription is active when plan_until is in the future. Any paid plan unlocks premium themes + branding. */
function is_premium(array $m): bool { return !empty($m['plan_until']) && strtotime($m['plan_until']) > time(); }
/** Active paid plan row, or null (Free). */
function merchant_plan(array $m): ?array { return is_premium($m) ? plan_by_id((int)($m['plan_id'] ?? 0)) : null; }
function plan_name(array $m): string { $p = merchant_plan($m); return $p ? $p['name'] : (is_premium($m) ? 'Premium' : 'Free'); }
function fee_pct_for(array $m): float { $p = merchant_plan($m); return $p ? (float)$p['fee_percent'] : setting_num('fee_percent'); }
function fee_for(array $m, float $amount): float {
    if ($m['role'] === 'admin') return 0.0;
    return round($amount * fee_pct_for($m) / 100 + setting_num('fee_flat'), 2);
}
/** Monthly order cap: 0 = unlimited. */
function order_cap_for(array $m): int {
    if ($m['role'] === 'admin') return 0;
    $p = merchant_plan($m); return $p ? (int)$p['monthly_orders'] : (int)setting_num('free_monthly_orders');
}
function orders_this_month(int $mid): int {
    $st = db()->prepare('SELECT COUNT(*) FROM orders WHERE merchant_id = ? AND kind = "payment" AND created_at >= ?');
    $st->execute([$mid, date('Y-m-01 00:00:00')]);
    return (int)$st->fetchColumn();
}
function active_plans(): array { return db()->query('SELECT * FROM plans WHERE active = 1 ORDER BY sort_order, price')->fetchAll(); }

/** Buy / renew / switch a plan from the API Wallet. Returns [ok, message]. */
function subscribe_plan(int $mid, int $planId, string $source = 'wallet'): array {
    $plan = plan_by_id($planId); $m = merchant_by_id($mid);
    if (!$plan || !$m || !(int)$plan['active']) return [false, 'This plan is not available.'];
    $price = (float)$plan['price'];
    $pdo = db(); $pdo->beginTransaction();
    try {
        if ($source === 'wallet' && $price > 0) {
            $st = $pdo->prepare('UPDATE merchants SET wallet = wallet - ? WHERE id = ? AND wallet >= ?');   // atomic balance check
            $st->execute([$price, $mid, $price]);
            if ($st->rowCount() !== 1) { $pdo->rollBack(); return [false, 'Not enough balance in ' . t('wallet') . '. Deposit more and try again.']; }
            $bal = $pdo->prepare('SELECT wallet FROM merchants WHERE id = ?'); $bal->execute([$mid]);
            $pdo->prepare('INSERT INTO wallet_txns (merchant_id, type, amount, balance_after, note, ref, created_at) VALUES (?,?,?,?,?,?,?)')
                ->execute([$mid, 'plan', -$price, (float)$bal->fetchColumn(), 'Plan: ' . $plan['name'] . ' (' . $plan['days'] . ' days)', null, now()]);
        }
        // same plan still active => extend; otherwise start now
        $base = (is_premium($m) && (int)$m['plan_id'] === $planId) ? strtotime($m['plan_until']) : time();
        $ends = date('Y-m-d H:i:s', $base + (int)$plan['days'] * 86400);
        $pdo->prepare('UPDATE merchants SET plan_id = ?, plan_until = ? WHERE id = ?')->execute([$planId, $ends, $mid]);
        $pdo->prepare('INSERT INTO subscriptions (merchant_id, plan_id, plan_name, price, days, started_at, ends_at, source) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$mid, $planId, $plan['name'], $price, (int)$plan['days'], now(), $ends, $source]);
        $pdo->commit();
        return [true, $plan['name'] . ' plan active until ' . $ends . '.'];
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('ORVIX subscribe: ' . $ex->getMessage());
        return [false, 'Could not activate the plan. Please try again.'];
    }
}
/** Lazy auto-renew: if the plan expired and auto_renew is on and the wallet can pay, renew it. */
function maybe_auto_renew(array $m): array {
    if (empty($m['auto_renew']) || is_premium($m) || empty($m['plan_id'])) return $m;
    $plan = plan_by_id((int)$m['plan_id']);
    if ($plan && (int)$plan['active'] && (float)$m['wallet'] >= (float)$plan['price']) {
        [$ok] = subscribe_plan((int)$m['id'], (int)$plan['id']);
        if ($ok) return merchant_by_id((int)$m['id']) ?: $m;
    }
    return $m;
}
/** Admin grant (no charge). */
function grant_plan(int $mid, int $planId): array { return subscribe_plan($mid, $planId, 'admin'); }
function merchant_by_id(int $id): ?array {
    $st = db()->prepare('SELECT * FROM merchants WHERE id = ?'); $st->execute([$id]);
    return $st->fetch() ?: null;
}

/* ----------------------------------------------------------------- wallet */
/** Atomic credit/debit + ledger row. Returns the new balance. */
function wallet_add(int $mid, float $amount, string $type, string $note = '', ?string $ref = null): float {
    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE merchants SET wallet = wallet + ? WHERE id = ?')->execute([$amount, $mid]);
        $b = $pdo->prepare('SELECT wallet FROM merchants WHERE id = ?'); $b->execute([$mid]);
        $bal = (float)$b->fetchColumn();
        $pdo->prepare('INSERT INTO wallet_txns (merchant_id, type, amount, balance_after, note, ref, created_at) VALUES (?,?,?,?,?,?,?)')
            ->execute([$mid, $type, $amount, $bal, mb_substr($note, 0, 255), $ref, now()]);
        if ($own) $pdo->commit();
        return $bal;
    } catch (Throwable $ex) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}
function admin_connection_id(): string {
    $r = db()->query('SELECT famapi_connection_id FROM merchants WHERE role = "admin" AND famapi_connection_id IS NOT NULL AND famapi_connection_id <> "" ORDER BY id LIMIT 1')->fetchColumn();
    return $r ? (string)$r : '';
}

/* ------------------------------------------------------------- settlement */
/**
 * Runs exactly once per order when it becomes "success" (guarded by orders.settled).
 * payment: charge fee, pay referral share, send webhook.  deposit: credit API Wallet (+bonus).
 */
function settle_success(array $o): void {
    $g = db()->prepare('UPDATE orders SET settled = 1 WHERE id = ? AND settled = 0'); $g->execute([$o['id']]);
    if ($g->rowCount() !== 1) return;
    $m = merchant_by_id((int)$o['merchant_id']); if (!$m) return;

    try {
        if (($o['kind'] ?? 'payment') === 'deposit') {
            wallet_add((int)$m['id'], (float)$o['amount'], 'deposit', 'Wallet deposit', $o['order_id']);
            $bonus = round((float)$o['amount'] * setting_num('deposit_bonus') / 100, 2);
            if ($bonus > 0) wallet_add((int)$m['id'], $bonus, 'bonus', 'Deposit bonus', $o['order_id']);
            return;
        }
        $fee = (($o['pay_method'] ?? 'famapi') === 'gmail') ? 0.0 : fee_for($m, (float)$o['amount']);   // own-account orders: no platform fee (like PayX direct mode)
        if ($fee > 0) {
            wallet_add((int)$m['id'], -$fee, 'fee', 'Fee for order ' . $o['order_id'], $o['order_id']);
            db()->prepare('UPDATE orders SET fee = ? WHERE id = ?')->execute([$fee, $o['id']]);
            $ref = (int)($m['referred_by'] ?? 0);
            $share = round($fee * setting_num('ref_percent') / 100, 2);
            if ($ref > 0 && $share > 0 && merchant_by_id($ref)) wallet_add($ref, $share, 'referral', 'Referral share from ' . $m['name'], $o['order_id']);
        }
    } catch (Throwable $ex) { error_log('ORVIX settle ' . $o['order_id'] . ': ' . $ex->getMessage()); }

    send_webhook(get_order($o['order_id']) ?: $o, $m);
}

/** Best-effort, signed POST to the merchant's webhook. Merchants must still verify via order-status. */
function send_webhook(array $o, array $m): void {
    $url = trim((string)($m['webhook_url'] ?? ''));
    if (!valid_http_url($url)) return;
    $body = json_encode([
        'event' => 'order.success', 'order_id' => $o['order_id'], 'reference' => $o['merchant_ref'],
        'amount' => (float)$o['amount'], 'status' => 'success', 'paid_at' => $o['paid_at'],
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Orvix-Signature: ' . hash_hmac('sha256', $body, $m['api_key'])],
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 200 && $code < 300) db()->prepare('UPDATE orders SET webhook_sent = 1 WHERE id = ?')->execute([$o['id']]);
    else error_log('ORVIX webhook failed for ' . $o['order_id'] . ' HTTP ' . $code);
}

/* --------------------------------------------------------------- referral */
function ensure_ref_code(array $m): string {
    if (!empty($m['ref_code'])) return $m['ref_code'];
    for ($i = 0; $i < 10; $i++) {
        $code = strtoupper(substr(bin2hex(random_bytes(4)), 0, 7));
        $c = db()->prepare('SELECT COUNT(*) FROM merchants WHERE ref_code = ?'); $c->execute([$code]);
        if ((int)$c->fetchColumn() === 0) {
            db()->prepare('UPDATE merchants SET ref_code = ? WHERE id = ?')->execute([$code, $m['id']]);
            return $code;
        }
    }
    return '';
}

/* --------------------------------------------------------------------- AI */
function default_ai_prompt(): string {
    $site = site_url();
    return <<<TXT
You are the ORVIX PAY AI Assistant. You help merchants/developers connect ORVIX PAY (a UPI payment gateway powered by FamPay) to their own website, app or bot. Reply in the user's language (Hinglish is fine). Be short, practical, and give complete copy-paste code.

RULES
- Never ask for or repeat a real API token. Use the placeholder YOUR_ORVIX_API_TOKEN.
- A customer returning to the redirect_url is NOT proof of payment. Always call order-status from the server and fulfil only when status == "success".
- Amount must be a plain number (499), never "Rs 499" or "1,999".
- The API token only goes live after the merchant connects their FAM merchant (menu: Connect Merchant). API calls fail with NOT_LIVE until then.
- Each successful order charges a small fee from the merchant's API Wallet; if the wallet is empty create-order returns INSUFFICIENT_WALLET (deposit via API Wallet menu).

ONBOARDING STEPS
1) Register -> 2) Connect Merchant (connect FamPay) -> 3) copy API Token from the API Token menu -> 4) create orders from your server -> 5) verify with order-status.

API (base: {$site})
POST {$site}/api/create-order.php   (also works as /api/create-order)
  fields: api_token (or header X-Orvix-Token), amount (number >= 1), order_id (your reference, optional), redirect_url (optional), customer_mobile (optional)
  201 -> {"success":true,"order":{"order_id","amount","status":"pending","reference","payment_url","expires_at"}}
  Send the customer to payment_url (the Cashier page).
POST {$site}/api/order-status.php   (also /api/order-status)
  fields: api_token, order_id (the ORX... id returned by create-order)
  200 -> {"success":true,"order":{"order_id","amount","status","reference","paid_at"},"verified":true}
  status: pending | success | failed | expired | refunded. Poll every 3-5 seconds, never faster.
Errors: {"success":false,"error":{"code","message"}} - 400 VALIDATION_ERROR, 401 INVALID_TOKEN, 402 INSUFFICIENT_WALLET, 403 NOT_LIVE/BLOCKED, 404 NOT_FOUND, 429/5xx retry with backoff.
WEBHOOK (optional): set a URL in Settings. On success we POST JSON {event:"order.success",order_id,reference,amount,status,paid_at} with header X-Orvix-Signature = HMAC-SHA256(body, api_token). Treat it only as a hint and still verify with order-status.

When the user tells you their stack (PHP, Node, Python, WordPress, Android WebView, Telegram bot...), give the full working code for create-order and a verify/poll page for that stack.
TXT;
}
function ai_system_prompt(): string {
    $p = trim(setting('ai_prompt'));
    return $p !== '' ? $p : default_ai_prompt();
}

/** Gateway name / logo — editable in Admin → Branding. */
function brand_name(): string { $n = trim(setting('site_name')); return $n !== '' ? $n : SITE_NAME; }
function brand_logo(): string {
    $l = trim(setting('site_logo'));
    return ($l !== '' && preg_match('#^assets/brand_logo\.(png|jpg|webp)(\?v=\d+)?$#', $l)) ? site_url($l) : site_url('assets/logo.png');
}
