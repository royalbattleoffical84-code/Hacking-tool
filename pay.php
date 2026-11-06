<?php
/** Public pay-link page (fixed or static). Payment is still verified server-side via the normal checkout flow. */
require_once __DIR__ . '/includes/bootstrap.php';
$code = preg_replace('/[^a-z0-9]/', '', strtolower((string)($_GET['c'] ?? '')));
$st = db()->prepare('SELECT p.*, m.* , p.id AS pid, p.type AS ptype, p.title AS ptitle FROM paylinks p JOIN merchants m ON m.id = p.merchant_id WHERE p.code = ? AND p.active = 1 AND m.status = ?');
$st->execute([$code, 'active']); $row = $code !== '' ? $st->fetch() : false;
$theme = $row ? resolve_theme($row['theme_pay'] ?? 'orvix', is_premium($row)) : 'orvix';
$err = '';
$oneTime = $row && $row['ptype'] === 'fixed' && !empty($row['one_time']);
$paid = false;
if ($oneTime) { $c = db()->prepare("SELECT COUNT(*) FROM orders WHERE merchant_ref = ? AND status = 'success'"); $c->execute(['paylink:' . $code]); $paid = (int)$c->fetchColumn() > 0; }

/** Creates (or reuses the still-open) order for this link. Returns [checkout url|null, error]. */
function pl_start(array $row, string $code, ?float $amt): array {
    $_SESSION['pl_hits'] = array_values(array_filter($_SESSION['pl_hits'] ?? [], fn($t) => $t > time() - 3600));
    if ($amt === null) return [null, 'Enter a valid amount.'];
    if ($row['ptype'] === 'static' && $amt < (float)$row['min_amount']) return [null, 'Minimum amount is ' . money($row['min_amount']) . '.'];
    $po = null;
    if (!empty($row['one_time'])) {   // one-time link: everybody shares the single open order
        $q = db()->prepare("SELECT order_id FROM orders WHERE merchant_ref = ? AND status = 'pending' AND expires_at > ? ORDER BY id DESC LIMIT 1");
        $q->execute(['paylink:' . $code, date('Y-m-d H:i:s', time() + 60)]); $oid = $q->fetchColumn(); $po = $oid ? get_order($oid) : null;
    } elseif (!empty($_SESSION['pl_order'][$code])) { $po = get_order($_SESSION['pl_order'][$code]); }
    if ($po && $po['status'] === 'pending' && strtotime($po['expires_at']) > time() + 60 && abs((float)$po['amount'] - $amt) < 0.001) return [site_url('checkout.php?order_id=' . urlencode($po['order_id'])), ''];
    if (count($_SESSION['pl_hits']) >= 10) return [null, 'Too many attempts. Please try again later.'];
    $_SESSION['pl_hits'][] = time();
    $r = create_orvix_order($row, $amt, 'paylink:' . $code, site_url('pay.php?c=' . $code));
    if ($r['ok']) {
        $_SESSION['pl_order'][$code] = $r['order']['order_id'];
        db()->prepare('UPDATE paylinks SET uses = uses + 1 WHERE id = ?')->execute([$row['pid']]);
        return [site_url('checkout.php?order_id=' . urlencode($r['order']['order_id'])), ''];
    }
    return [null, in_array($r['code'] ?? '', ['NOT_LIVE', 'INSUFFICIENT_WALLET', 'PLAN_LIMIT'], true) ? 'This payment link is temporarily unavailable.' : $r['error']];
}
$bot = (bool)preg_match('/bot|crawl|spider|preview|facebookexternalhit|whatsapp|telegram|slack|discord|curl|wget/i', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));

if ($row && !$paid && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $amt = $row['ptype'] === 'fixed' ? sanitize_amount($row['amount']) : sanitize_amount($_POST['amount'] ?? '');
    [$go, $err] = pl_start($row, $code, $amt);
    if ($go) redirect($go);
} elseif ($row && !$paid && $row['ptype'] === 'fixed' && $_SERVER['REQUEST_METHOD'] === 'GET' && !$bot) {
    // fixed link: open the payment page directly
    [$go, $err] = pl_start($row, $code, sanitize_amount($row['amount']));
    if ($go) redirect($go);
}
page_header('Pay', '', true, $theme);
?>
<div class="cash card">
<?php if ($paid): ?>
  <div class="head"><h2><?= e($row['business_name'] ?: $row['name']) ?></h2><div class="muted"><?= e($row['ptitle']) ?></div><div class="amt"><?= money($row['amount']) ?></div></div>
  <div class="big-ic">✅</div><h2 class="c">Payment Received</h2><p class="c muted">This payment link has already been paid.</p>
<?php elseif (!$row): ?>
  <div class="head"><div class="big-ic">🔗</div><h2>Link not available</h2></div><p class="muted c">This payment link is invalid or has been disabled.</p>
<?php else: ?>
  <div class="head"><?php if (!empty($row['logo_url']) && valid_http_url($row['logo_url']) && is_premium($row)): ?><img src="<?= e($row['logo_url']) ?>" alt="" width="56" height="56" style="border-radius:12px"><?php endif; ?>
    <h2><?= e($row['business_name'] ?: $row['name']) ?></h2><div class="muted"><?= e($row['ptitle']) ?></div>
    <?php if ($row['ptype'] === 'fixed'): ?><div class="amt"><?= money($row['amount']) ?></div><?php endif; ?></div>
  <?php if ($err): ?><div class="alert err"><?= e($err) ?></div><?php endif; ?>
  <form method="post"><?= csrf_field() ?>
    <?php if ($row['ptype'] === 'static'): ?><label>Amount (₹)<input name="amount" inputmode="decimal" required placeholder="Min <?= e(rtrim(rtrim(number_format((float)$row['min_amount'], 2, '.', ''), '0'), '.')) ?>"></label><?php endif; ?>
    <button class="btn block">Pay<?= $row['ptype'] === 'fixed' ? ' ' . e(money($row['amount'])) : '' ?></button></form>
<?php endif; ?>
  <div class="pwr">Powered by <?= e(brand_name()) ?> · Build by SHIVAM KASHYAP 👑</div>
</div>
<?php page_footer(true);
