<?php
require_once __DIR__ . '/includes/bootstrap.php';
$base = site_url('api');
$php = <<<'CODE'
<?php
// 1) create-order.php  (server-side only)
$ch = curl_init('{{BASE}}/create-order');
curl_setopt_array($ch, [
  CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
  CURLOPT_POSTFIELDS => http_build_query([
    'api_token'       => 'YOUR_ORVIX_API_TOKEN',
    'amount'          => 499,                       // plain number
    'order_id'        => 'INV-1001',                // your own reference
    'redirect_url'    => 'https://yoursite.com/thanks.php',
    'customer_mobile' => '9999999999',
  ]),
]);
$res = json_decode(curl_exec($ch), true);
curl_close($ch);
if (empty($res['success'])) die($res['error']['message'] ?? 'Could not create order');
header('Location: ' . $res['order']['payment_url']);   // Cashier page

// 2) thanks.php — NEVER trust the redirect. Verify on the server:
$ch = curl_init('{{BASE}}/order-status');
curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
  CURLOPT_POSTFIELDS => http_build_query(['api_token' => 'YOUR_ORVIX_API_TOKEN', 'order_id' => $_GET['order_id'] ?? ''])]);
$res = json_decode(curl_exec($ch), true);
if (!empty($res['success']) && $res['order']['status'] === 'success') { /* fulfil the order */ }
CODE;
$node = <<<'CODE'
// Node 18+ (Express route) — keep the token in an env var
const BASE = '{{BASE}}';
app.post('/pay', async (req, res) => {
  const r = await fetch(BASE + '/create-order', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ api_token: process.env.ORVIX_TOKEN, amount: 499, order_id: 'INV-1001',
                           redirect_url: 'https://yoursite.com/thanks' })
  }).then(r => r.json());
  if (!r.success) return res.status(400).send(r.error.message);
  res.redirect(r.order.payment_url);
});
app.get('/thanks', async (req, res) => {
  const r = await fetch(BASE + '/order-status', { method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ api_token: process.env.ORVIX_TOKEN, order_id: req.query.order_id }) }).then(r => r.json());
  res.send(r.success && r.order.status === 'success' ? 'Paid ✔' : 'Not paid yet');
});
CODE;
$py = <<<'CODE'
import os, requests
BASE = "{{BASE}}"
TOKEN = os.environ["ORVIX_TOKEN"]

r = requests.post(BASE + "/create-order", json={"api_token": TOKEN, "amount": 499, "order_id": "INV-1001"}).json()
if not r.get("success"): raise SystemExit(r["error"]["message"])
print("Send customer to:", r["order"]["payment_url"])

s = requests.post(BASE + "/order-status", json={"api_token": TOKEN, "order_id": r["order"]["order_id"]}).json()
print(s["order"]["status"])        # pending | success | failed | expired | refunded
CODE;
$hook = <<<'CODE'
<?php // orvix-webhook.php
$body = file_get_contents('php://input');
$sig  = $_SERVER['HTTP_X_ORVIX_SIGNATURE'] ?? '';
if (!hash_equals(hash_hmac('sha256', $body, 'YOUR_ORVIX_API_TOKEN'), $sig)) { http_response_code(401); exit; }
$d = json_decode($body, true);   // {event, order_id, reference, amount, status, paid_at}
// Treat as a hint: confirm with /order-status before fulfilling.
http_response_code(200);
CODE;
$fix = fn($s) => str_replace('{{BASE}}', $base, $s);
page_header(t('docs'), 'docs');
?>
<h1><?= e(t('docs')) ?></h1>
<div class="alert warn"><b>Golden rule:</b> a customer landing on your <code>redirect_url</code> is never proof of payment. Fulfil an order only after <code>order-status</code> returns <code>status = "success"</code> from your own server.</div>
<div class="alert info">Stuck? Ask the <a href="<?= e(site_url('ai.php')) ?>"><b><?= e(t('ai')) ?></b></a> — tell it your stack and it writes the integration for you.</div>

<div class="card"><h2>Getting started</h2>
  <div class="step"><i>1</i><div><?= e(t('register')) ?> as a <?= e(t('merchant')) ?>.</div></div>
  <div class="step"><i>2</i><div><b><?= e(t('connect')) ?></b> — connect your FAM merchant. Your <?= e(t('api_token')) ?> only goes live after this.</div></div>
  <div class="step"><i>3</i><div>Deposit into <b><?= e(t('wallet')) ?></b> (a small fee is charged from it per successful order).</div></div>
  <div class="step"><i>4</i><div>Copy your <b><?= e(t('api_token')) ?></b>, create orders from your server, verify with <code>order-status</code>.</div></div>
</div>

<div class="card"><h2>1. Create Order</h2>
<p><code class="k">POST <?= e($base) ?>/create-order</code> <span class="muted small">(or <code>/create-order.php</code>)</span></p>
<div class="tw"><table><tr><th>Field</th><th>Required</th><th>Detail</th></tr>
<tr><td>api_token</td><td>Yes</td><td>Your <?= e(t('api_token')) ?> (or header <code>X-Orvix-Token</code>)</td></tr>
<tr><td>amount</td><td>Yes</td><td>Number, min 1 (499). Formats like "₹1,999" are cleaned automatically</td></tr>
<tr><td>order_id</td><td>No</td><td>Your own reference (max 100 chars)</td></tr>
<tr><td>redirect_url</td><td>No</td><td>http(s) URL the customer is sent back to</td></tr>
<tr><td>customer_mobile</td><td>No</td><td>Customer's mobile number</td></tr></table></div>
<p>Success (201):</p>
<pre>{ "success": true, "order": { "order_id": "ORX2611...", "amount": 499, "status": "pending", "reference": "INV-1001",
  "payment_url": "<?= e(site_url('checkout.php?order_id=ORX...')) ?>", "expires_at": "2026-11-02 12:15:00" } }</pre>
<p>Send the customer to <code>payment_url</code> — the <?= e(t('cashier')) ?> page (QR + Pay Now, in your chosen theme).</p></div>

<div class="card"><h2>2. Order Status</h2>
<p><code class="k">POST <?= e($base) ?>/order-status</code> <span class="muted small">(or <code>/order-status.php</code>)</span> — fields: <code>api_token</code>, <code>order_id</code> (the <code>ORX…</code> id from step 1)</p>
<p>Each call verifies live. Poll every 3–5 seconds, never faster. status: <code>pending | success | failed | expired | refunded</code>.</p>
<pre>{ "success": true, "order": { "order_id": "ORX...", "amount": 499, "status": "success", "reference": "INV-1001", "paid_at": "..." }, "verified": true }</pre></div>

<div class="card"><h2>3. Webhook (optional)</h2>
<p>Set a Webhook URL in <?= e(t('settings')) ?>. On every successful order we send a signed POST. It is a hint only — confirm with <code>order-status</code>. One attempt, 5 s timeout; no retries.</p>
<pre><?= e($hook) ?></pre></div>

<div class="card"><h2>Code examples</h2>
<h3>PHP</h3><pre><?= e($fix($php)) ?></pre>
<h3>Node.js</h3><pre><?= e($fix($node)) ?></pre>
<h3>Python</h3><pre><?= e($fix($py)) ?></pre></div>

<div class="card"><h2>Errors</h2>
<p>Shape: <code>{ "success": false, "error": { "code", "message" } }</code></p>
<div class="tw"><table><tr><th>HTTP</th><th>Code</th><th>Meaning</th></tr>
<tr><td>400</td><td>VALIDATION_ERROR</td><td>Bad amount or redirect_url</td></tr>
<tr><td>401</td><td>INVALID_TOKEN</td><td>Token missing or wrong</td></tr>
<tr><td>402</td><td>INSUFFICIENT_WALLET</td><td>Deposit into <?= e(t('wallet')) ?></td></tr>
<tr><td>403</td><td>NOT_LIVE / BLOCKED</td><td><?= e(t('connect')) ?> first / account blocked</td></tr>
<tr><td>404</td><td>NOT_FOUND</td><td>Unknown order_id</td></tr>
<tr><td>429 / 5xx</td><td>RATE_LIMITED / INTERNAL_ERROR</td><td>Retry with backoff</td></tr></table></div></div>
<?php page_footer();
