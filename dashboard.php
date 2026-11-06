<?php
require_once __DIR__ . '/includes/bootstrap.php';
$m = require_live();
reconcile_pending((int)$m['id']);
$q = db()->prepare('SELECT COUNT(*) c, COALESCE(SUM(CASE WHEN status="success" THEN 1 ELSE 0 END),0) ok,
  COALESCE(SUM(CASE WHEN status="pending" THEN 1 ELSE 0 END),0) pend,
  COALESCE(SUM(CASE WHEN status IN ("failed","expired") THEN 1 ELSE 0 END),0) bad,
  COALESCE(SUM(CASE WHEN status="success" THEN amount END),0) vol,
  COALESCE(SUM(CASE WHEN status="success" AND created_at >= ? THEN amount END),0) today
  FROM orders WHERE merchant_id = ? AND kind = "payment"');
$q->execute([date('Y-m-d 00:00:00'), $m['id']]); $s = $q->fetch();
$rec = db()->prepare('SELECT * FROM orders WHERE merchant_id = ? AND kind = "payment" ORDER BY id DESC LIMIT 8'); $rec->execute([$m['id']]); $rows = $rec->fetchAll();
page_header(t('dashboard'), 'dashboard');
?>
<h1>Account Overview</h1>
<p class="muted" style="margin:-8px 0 14px">Welcome back, <?= e($m['name']) ?>! Here's what's happening today.</p>
<?php $tk = trim(setting('ticker_text')); if ($tk !== ''): ?>
<div class="tick"><b><i class="fa-solid fa-bullhorn"></i> Live Status Updates</b><div class="mq"><span><?= e($tk) ?></span></div></div>
<?php endif; ?>
<div class="sys">
  <div class="card"><div class="ico"><i class="fa-solid fa-signal"></i></div><div><div class="l">API STATUS</div><div class="v ok">Live</div></div></div>
  <div class="card"><div class="ico"><i class="fa-solid fa-bolt"></i></div><div><div class="l">API COLLECTION</div><div class="v ok">Instant</div></div></div>
  <div class="card"><div class="ico"><i class="fa-solid fa-user-check"></i></div><div><div class="l">ACCOUNT STATUS</div><div class="v <?= $m['status'] === 'active' ? 'ok' : 'off' ?>"><?= $m['status'] === 'active' ? 'Active' : e(ucfirst($m['status'])) ?></div></div></div>
</div>
<?php if (setting_num('fee_percent') > 0 && (float)$m['wallet'] < 10 && $m['role'] !== 'admin'): ?>
  <div class="alert warn">Your <?= e(t('wallet')) ?> is low (<?= money($m['wallet']) ?>). New orders are rejected when the balance can't cover the fee. <a href="<?= e(site_url('wallet.php')) ?>"><b>Deposit now</b></a></div>
<?php endif; ?>
<?php if (is_premium($m) && strtotime($m['plan_until']) - time() < 3 * 86400): ?>
  <div class="alert warn">Your <b><?= e(plan_name($m)) ?></b> plan expires on <?= e(date('d M Y', strtotime($m['plan_until']))) ?>. <a href="<?= e(site_url('premium.php')) ?>"><b>Renew</b></a></div>
<?php endif; ?>
<div class="grid g4">
  <div class="card stat"><div class="v"><?= money($m['wallet']) ?></div><div class="l"><?= e(t('wallet')) ?></div></div>
  <div class="card stat"><div class="v"><?= money($s['today']) ?></div><div class="l">Collected today</div></div>
  <div class="card stat"><div class="v"><?= money($s['vol']) ?></div><div class="l">Total collected</div></div>
  <div class="card stat"><div class="v"><?= (int)$s['ok'] ?> / <?= (int)$s['c'] ?></div><div class="l">Successful / total orders</div></div>
</div>
<div class="grid g2">
  <div class="card"><h3>API status</h3><p><span class="badge success">Live</span> · Plan: <b><?= is_premium($m) ? e(plan_name($m)) . ' 👑' : 'Free' ?></b></p>
    <p class="muted small">Fee per successful order: <?= e(rtrim(rtrim(number_format(fee_pct_for($m), 2), '0'), '.')) ?>%<?= setting_num('fee_flat') > 0 ? ' + ' . money(setting_num('fee_flat')) : '' ?></p>
    <a class="btn sm" href="<?= e(site_url('api-token.php')) ?>">Get API Token</a> <a class="btn ghost sm" href="<?= e(site_url('ai.php')) ?>">Ask AI to integrate</a></div>
  <div class="card"><h3>Pending</h3><p class="stat"><span class="v"><?= (int)$s['pend'] ?></span> <span class="muted">pending · <?= (int)$s['bad'] ?> failed/expired</span></p>
    <a class="btn ghost sm" href="<?= e(site_url('transactions.php')) ?>">View <?= e(t('transactions')) ?></a></div>
</div>
<div class="card">
  <h2>Recent <?= e(t('transactions')) ?></h2>
  <div class="tw"><table><tr><th><?= e(t('order_id')) ?></th><th><?= e(t('amount')) ?></th><th><?= e(t('status')) ?></th><th>Date</th></tr>
  <?php foreach ($rows as $r): ?>
    <tr><td><?= e($r['order_id']) ?></td><td><?= money($r['amount']) ?></td><td><?= status_badge($r['status']) ?></td><td><?= e($r['created_at']) ?></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="4" class="muted">No orders yet.</td></tr><?php endif; ?>
  </table></div>
</div>
<?php $pp = popup_for($m); if ($pp) echo popup_html($pp); ?>
<?php page_footer();
