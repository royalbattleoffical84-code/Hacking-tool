<?php
require_once __DIR__ . '/includes/bootstrap.php';
$m = require_live();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';
    if ($do === 'subscribe') {
        [$ok, $msg] = subscribe_plan((int)$m['id'], (int)($_POST['plan_id'] ?? 0));  if ($ok) log_activity((int)$m['id'], 'plan_subscribe', (string)(int)($_POST['plan_id'] ?? 0));
        flash($ok ? 'ok' : 'err', $msg);
    } elseif ($do === 'auto') {
        db()->prepare('UPDATE merchants SET auto_renew = ? WHERE id = ?')->execute([empty($_POST['auto_renew']) ? 0 : 1, $m['id']]);
        flash('ok', 'Auto-renew ' . (empty($_POST['auto_renew']) ? 'turned off.' : 'turned on.'));
    }
    redirect(site_url('premium.php'));
}
$m = merchant_by_id((int)$m['id']);
$cur = merchant_plan($m); $plans = active_plans();
$used = orders_this_month((int)$m['id']); $cap = order_cap_for($m);
$hist = db()->prepare('SELECT * FROM subscriptions WHERE merchant_id = ? ORDER BY id DESC LIMIT 10'); $hist->execute([$m['id']]); $subs = $hist->fetchAll();
function pct($n) { return rtrim(rtrim(number_format((float)$n, 2), '0'), '.'); }
page_header(t('premium'), 'premium');
?>
<h1>👑 <?= e(t('premium')) ?></h1>

<div class="card">
  <div class="grid g3">
    <div class="stat"><div class="v"><?= is_premium($m) ? e(plan_name($m)) . ' 👑' : 'Free' ?></div><div class="l">Current plan</div></div>
    <div class="stat"><div class="v"><?= is_premium($m) ? e(date('d M Y', strtotime($m['plan_until']))) : '—' ?></div><div class="l"><?= is_premium($m) ? 'Valid until (' . max(0, (int)ceil((strtotime($m['plan_until']) - time()) / 86400)) . ' days left)' : 'No active subscription' ?></div></div>
    <div class="stat"><div class="v"><?= (int)$used ?><?= $cap > 0 ? ' / ' . $cap : '' ?></div><div class="l">Orders this month<?= $cap > 0 ? '' : ' (unlimited)' ?></div></div>
  </div>
  <p class="muted small" style="margin-top:10px">Fee on your plan: <b><?= pct(fee_pct_for($m)) ?>%</b> per successful order. <?= e(t('wallet')) ?> balance: <b><?= money($m['wallet']) ?></b> · <a href="<?= e(site_url('wallet.php')) ?>">Deposit</a></p>
  <?php if ($m['plan_id']): ?>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="auto">
    <label style="display:flex;gap:8px;align-items:center;margin:8px 0 0"><input type="checkbox" name="auto_renew" value="1" style="width:auto;margin:0" <?= $m['auto_renew'] ? 'checked' : '' ?> onchange="this.form.submit()"> Auto-renew from <?= e(t('wallet')) ?> when the plan expires</label>
  </form>
  <?php endif; ?>
</div>

<h2>Choose a plan</h2>
<div class="grid g3">
  <div class="card <?= !is_premium($m) ? '' : '' ?>"><h3>Free</h3>
    <p><b style="font-size:26px">₹0</b></p>
    <ul class="small"><li>Fee: <b><?= pct(setting_num('fee_percent')) ?>%</b> per order</li><li><?= (int)setting_num('free_monthly_orders') > 0 ? (int)setting_num('free_monthly_orders') . ' orders / month' : 'Unlimited orders' ?></li><li>6 themes</li><li>"Powered by ORVIX PAY" on Cashier page</li></ul>
    <button class="btn ghost block" disabled><?= !is_premium($m) ? 'Current plan' : 'Free after expiry' ?></button></div>
  <?php foreach ($plans as $p): $isCur = $cur && (int)$cur['id'] === (int)$p['id']; ?>
  <div class="card" style="<?= $isCur ? 'border-color:var(--p)' : '' ?>"><h3><?= e($p['name']) ?> 👑</h3>
    <p><b style="font-size:26px"><?= money($p['price']) ?></b> <span class="muted">/ <?= (int)$p['days'] ?> days</span></p>
    <ul class="small"><li>Fee: <b><?= pct($p['fee_percent']) ?>%</b> per order</li><li><?= (int)$p['monthly_orders'] > 0 ? (int)$p['monthly_orders'] . ' orders / month' : 'Unlimited orders' ?></li><li>All 12 themes</li><li>Your name &amp; logo on Cashier page, no ORVIX branding</li><?php if ($p['perks']): ?><li><?= e($p['perks']) ?></li><?php endif; ?></ul>
    <form method="post" onsubmit="return confirm('Pay <?= e(money($p['price'])) ?> from your API Wallet?')"><?= csrf_field() ?><input type="hidden" name="do" value="subscribe"><input type="hidden" name="plan_id" value="<?= (int)$p['id'] ?>">
      <button class="btn block"><?= $isCur ? 'Renew / Extend' : ($cur ? 'Switch to this plan' : 'Subscribe') ?></button></form>
  </div>
  <?php endforeach; ?>
</div>
<p class="muted small">Plans are paid from your <?= e(t('wallet')) ?>. Renewing the same plan adds days to the end of your current period; switching to another plan starts it right away (unused days are not refunded).</p>

<div class="card"><h2>Subscription history</h2><div class="tw"><table><tr><th>Date</th><th>Plan</th><th>Paid</th><th>Days</th><th>Valid until</th><th>Source</th></tr>
<?php foreach ($subs as $s): ?><tr><td><?= e($s['started_at']) ?></td><td><?= e($s['plan_name']) ?></td><td><?= money($s['price']) ?></td><td><?= (int)$s['days'] ?></td><td><?= e($s['ends_at']) ?></td><td><?= e($s['source']) ?></td></tr>
<?php endforeach; if (!$subs): ?><tr><td colspan="6" class="muted">No subscriptions yet.</td></tr><?php endif; ?></table></div></div>
<?php page_footer();
