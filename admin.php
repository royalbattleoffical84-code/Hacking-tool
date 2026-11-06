<?php
require_once __DIR__ . '/includes/bootstrap.php';
$a = require_admin();
$tab = $_GET['tab'] ?? 'overview';
$tabs = ['overview' => 'Overview', 'merchants' => 'Merchants', 'orders' => 'Orders', 'plans' => 'Plans', 'rates' => 'Wallet & Rates', 'providers' => 'Providers', 'branding' => 'Branding', 'popups' => 'Popups & Ticker', 'mystery' => 'Mystery Box', 'ai' => 'AI Assistant'];
if (!isset($tabs[$tab])) $tab = 'overview';
function num($k, $min = 0, $max = 1000000) { $v = (float)preg_replace('/[^0-9.]/', '', (string)($_POST[$k] ?? '0')); return max($min, min($max, $v)); }
function back(string $tab, string $type = 'ok', string $msg = 'Saved.'): void { flash($type, $msg); redirect(site_url('admin.php?tab=' . $tab)); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? ''; $id = (int)($_POST['id'] ?? 0);
    switch ($do) {
        case 'block': case 'unblock':
            if ($id && $id !== (int)$a['id']) db()->prepare('UPDATE merchants SET status = ? WHERE id = ?')->execute([$do === 'block' ? 'blocked' : 'active', $id]);
            back('merchants', 'ok', 'Updated.');
        case 'wallet_adjust':
            $amt = (float)preg_replace('/[^0-9.\-]/', '', (string)($_POST['amount'] ?? '0'));
            if ($id && $amt != 0 && abs($amt) <= 10000000 && merchant_by_id($id)) { wallet_add($id, round($amt, 2), 'manual', 'Admin: ' . mb_substr(trim($_POST['note'] ?? 'adjustment'), 0, 150)); back('merchants', 'ok', 'Wallet updated.'); }
            back('merchants', 'err', 'Enter a non-zero amount.');
        case 'grant_plan':
            [$ok, $msg] = grant_plan($id, (int)($_POST['plan_id'] ?? 0)); back('merchants', $ok ? 'ok' : 'err', $msg);
        case 'clear_plan':
            db()->prepare('UPDATE merchants SET plan_until = NULL, plan_id = NULL, auto_renew = 0 WHERE id = ?')->execute([$id]); back('merchants', 'ok', 'Plan removed.');
        case 'set_conn':
            $cid = trim($_POST['connection_id'] ?? '');
            if ($cid !== '' && !preg_match('/^conn_[A-Za-z0-9_\-]{3,100}$/', $cid)) back('merchants', 'err', 'Connection ID must start with conn_');
            db()->prepare('UPDATE merchants SET famapi_connection_id = ?, connected_at = ? WHERE id = ?')->execute([$cid ?: null, $cid ? now() : null, $id]);
            back('merchants', 'ok', 'Connection updated.');
        case 'order_status':      // manual success / fail (like PayX)
            $to = $_POST['to'] ?? ''; $oid = (int)($_POST['oid'] ?? 0);
            $r = db()->prepare('SELECT order_id FROM orders WHERE id = ?'); $r->execute([$oid]); $ord = ($oc = $r->fetchColumn()) ? get_order($oc) : null;
            if (!$ord) back('orders', 'err', 'Order not found.');
            if ($to === 'success' && $ord['status'] !== 'success') { change_order_status($ord, 'success', true); back('orders', 'ok', 'Order marked SUCCESS (fee / wallet / webhook applied once).'); }
            if ($to === 'failed' && in_array($ord['status'], ['pending', 'expired'], true)) { change_order_status($ord, 'failed', true); back('orders', 'ok', 'Order marked FAILED.'); }
            back('orders', 'err', 'That change is not allowed for this order.');
        case 'plan_save':
            $name = mb_substr(trim($_POST['name'] ?? ''), 0, 60);
            if ($name === '') back('plans', 'err', 'Plan name is required.');
            $f = [$name, num('price'), max(1, (int)num('days', 1, 3650)), num('fee_percent', 0, 100), (int)num('monthly_orders'), mb_substr(trim($_POST['perks'] ?? ''), 0, 255) ?: null, empty($_POST['active']) ? 0 : 1, (int)num('sort_order')];
            if ($id) db()->prepare('UPDATE plans SET name=?, price=?, days=?, fee_percent=?, monthly_orders=?, perks=?, active=?, sort_order=? WHERE id=?')->execute(array_merge($f, [$id]));
            else db()->prepare('INSERT INTO plans (name, price, days, fee_percent, monthly_orders, perks, active, sort_order) VALUES (?,?,?,?,?,?,?,?)')->execute($f);
            back('plans');
        case 'plan_delete':
            $c = db()->prepare('SELECT COUNT(*) FROM merchants WHERE plan_id = ? AND plan_until > ?'); $c->execute([$id, now()]);
            if ((int)$c->fetchColumn() > 0) back('plans', 'err', 'Merchants are subscribed to this plan. Deactivate it instead (existing subscribers keep it until expiry).');
            db()->prepare('DELETE FROM plans WHERE id = ?')->execute([$id]); back('plans', 'ok', 'Plan deleted.');
        case 'rates':
            foreach (['fee_percent' => [0, 100], 'fee_flat' => [0, 10000], 'min_deposit' => [1, 1000000], 'deposit_bonus' => [0, 100], 'ref_percent' => [0, 100], 'free_monthly_orders' => [0, 10000000], 'gm_unlock_fee' => [0, 100000]] as $k => [$lo, $hi]) setting_set($k, (string)num($k, $lo, $hi));
            setting_set('support_link', mb_substr(trim($_POST['support_link'] ?? ''), 0, 300));
            back('rates');
        case 'branding':
            setting_set('site_name', mb_substr(trim($_POST['site_name'] ?? ''), 0, 40));
            $cur = trim(setting('site_logo'));
            if (!empty($_POST['logo_reset'])) { foreach (glob(__DIR__ . '/assets/brand_logo.*') ?: [] as $f) @unlink($f); setting_set('site_logo', ''); }
            $f = $_FILES['logo'] ?? null;
            if ($f && $f['error'] !== UPLOAD_ERR_NO_FILE) {
                $info = ($f['error'] === UPLOAD_ERR_OK && is_uploaded_file($f['tmp_name'])) ? @getimagesize($f['tmp_name']) : false;
                $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? null;
                if (!$ext || $f['size'] > 1024 * 1024) back('branding', 'err', 'Logo must be a PNG, JPG or WEBP image up to 1 MB.');
                foreach (glob(__DIR__ . '/assets/brand_logo.*') ?: [] as $old) @unlink($old);
                if (!move_uploaded_file($f['tmp_name'], __DIR__ . '/assets/brand_logo.' . $ext)) back('branding', 'err', 'Could not save the logo (assets folder not writable).');
                setting_set('site_logo', 'assets/brand_logo.' . $ext . '?v=' . time());
            }
            log_activity((int)$a['id'], 'admin_branding'); back('branding');
        case 'ticker':
            setting_set('ticker_text', mb_substr(trim($_POST['ticker_text'] ?? ''), 0, 300)); log_activity((int)$a['id'], 'admin_ticker'); back('popups');
        case 'popup_save':
            $title = mb_substr(trim($_POST['title'] ?? ''), 0, 120);
            if ($title === '') back('popups', 'err', 'Title is required.');
            $img = trim($_POST['image_url'] ?? ''); if ($img !== '' && !valid_http_url($img)) back('popups', 'err', 'Image must be an http(s) URL.');
            $bu = trim($_POST['btn_url'] ?? ''); if ($bu !== '' && !valid_popup_link($bu)) back('popups', 'err', 'Button link must be http(s), #mystery, or a page like premium.php');
            $dt = function ($k) { $v = trim($_POST[$k] ?? ''); $t = $v !== '' ? strtotime($v) : false; return $t ? date('Y-m-d H:i:s', $t) : null; };
            $aud = in_array($_POST['audience'] ?? '', ['all', 'free', 'premium'], true) ? $_POST['audience'] : 'all';
            $fr = in_array($_POST['frequency'] ?? '', ['session', 'daily', 'once', 'always'], true) ? $_POST['frequency'] : 'daily';
            $f = [$title, mb_substr(trim($_POST['body'] ?? ''), 0, 2000), $img ?: null, mb_substr(trim($_POST['btn_text'] ?? ''), 0, 40) ?: null, $bu ?: null, $dt('starts_at'), $dt('ends_at'), $aud, $fr, empty($_POST['active']) ? 0 : 1];
            if ($id) db()->prepare('UPDATE popups SET title=?, body=?, image_url=?, btn_text=?, btn_url=?, starts_at=?, ends_at=?, audience=?, frequency=?, active=? WHERE id=?')->execute(array_merge($f, [$id]));
            else db()->prepare('INSERT INTO popups (title, body, image_url, btn_text, btn_url, starts_at, ends_at, audience, frequency, active, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute(array_merge($f, [now()]));
            back('popups');
        case 'popup_delete':
            db()->prepare('DELETE FROM popups WHERE id = ?')->execute([$id]); back('popups', 'ok', 'Popup deleted.');
        case 'mystery':
            setting_set('mb_enabled', empty($_POST['mb_enabled']) ? '0' : '1');
            setting_set('mb_title', mb_substr(trim($_POST['mb_title'] ?? 'Mystery Box'), 0, 60));
            $ea = trim($_POST['mb_ends_at'] ?? ''); setting_set('mb_ends_at', $ea !== '' && strtotime($ea) ? date('Y-m-d H:i:s', strtotime($ea)) : '');
            setting_set('mb_daily_boxes', (string)max(1, min(20, (int)num('mb_daily_boxes', 1, 20))));
            $rw = mb_substr(trim($_POST['mb_rewards'] ?? ''), 0, 3000);
            if ($rw !== '' && !mb_rewards($rw)) back('mystery', 'err', 'No valid reward lines. Format: Label|wallet|5|10');
            setting_set('mb_rewards', $rw); back('mystery');
        case 'ai_gemini':
            setting_set('ai_endpoint', 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions'); setting_set('ai_model', 'gemini-3.8-flash');
            back('ai', 'ok', 'Gemini preset applied. Now paste your Google AI Studio key and Save.');
        case 'ai':
            $ep = trim($_POST['ai_endpoint'] ?? '');
            if ($ep !== '' && !valid_http_url($ep)) back('ai', 'err', 'Endpoint must be an http(s) URL.');
            setting_set('ai_endpoint', $ep); setting_set('ai_model', mb_substr(trim($_POST['ai_model'] ?? 'gemini-3.8-flash'), 0, 80));
            if (!empty($_POST['ai_key_clear'])) setting_set('ai_key', ''); elseif (trim($_POST['ai_key'] ?? '') !== '') setting_set('ai_key', trim($_POST['ai_key']));
            setting_set('ai_prompt', !empty($_POST['ai_prompt_reset']) ? '' : mb_substr(trim($_POST['ai_prompt'] ?? ''), 0, 20000));
            back('ai');
    }
    redirect(site_url('admin.php'));
}
function p2($n) { return rtrim(rtrim(number_format((float)$n, 2), '0'), '.'); }
page_header(t('admin'), 'admin:' . $tab);
?>
<h1>🛡️ <?= e(t('admin')) ?></h1>
<div class="prov"><?php foreach ($tabs as $k => $l): ?><a href="?tab=<?= $k ?>"><span class="<?= $tab === $k ? 'on' : '' ?>"><?= e($l) ?></span></a><?php endforeach; ?></div>

<?php if ($tab === 'overview'):
    $o = db()->query('SELECT COUNT(*) c, COALESCE(SUM(CASE WHEN status="success" AND kind="payment" THEN amount END),0) vol, COALESCE(SUM(fee),0) fees FROM orders')->fetch();
    $mc = db()->query('SELECT COUNT(*) c, COALESCE(SUM(wallet),0) w, COALESCE(SUM(CASE WHEN plan_until > "' . now() . '" THEN 1 ELSE 0 END),0) subs FROM merchants')->fetch();
    $dep = db()->query('SELECT COALESCE(SUM(amount),0) FROM orders WHERE kind="deposit" AND status="success"')->fetchColumn();
    $err = setting('schema_err'); ?>
<?php if ($err): ?><div class="alert warn"><b>Database upgrade notes:</b><pre style="white-space:pre-wrap"><?= e($err) ?></pre></div><?php endif; ?>
<div class="grid g4">
  <div class="card stat"><div class="v"><?= (int)$mc['c'] ?></div><div class="l"><?= e(t('merchant')) ?>s (<?= (int)$mc['subs'] ?> subscribed)</div></div>
  <div class="card stat"><div class="v"><?= money($o['vol']) ?></div><div class="l">Verified payment volume</div></div>
  <div class="card stat"><div class="v"><?= money($o['fees']) ?></div><div class="l">Fees earned</div></div>
  <div class="card stat"><div class="v"><?= money($dep) ?></div><div class="l">Wallet deposits received</div></div>
</div>
<div class="card"><p class="muted">Merchant wallet balances (liability): <b><?= money($mc['w']) ?></b> · Orders total: <b><?= (int)$o['c'] ?></b></p>
  <p class="muted small">Wallet deposits are collected in the FamPay account connected under <a href="<?= e(site_url('connect.php')) ?>"><?= e(t('connect')) ?></a> by the admin (or the FamApi key owner's own account if none is connected).</p></div>

<?php elseif ($tab === 'merchants'):
    $ms = db()->query('SELECT m.*, (SELECT COUNT(*) FROM orders o WHERE o.merchant_id=m.id AND o.kind="payment") oc FROM merchants m ORDER BY m.id DESC LIMIT 200')->fetchAll();
    $pl = db()->query('SELECT * FROM plans ORDER BY sort_order, price')->fetchAll(); ?>
<div class="card"><h2><?= e(t('merchant')) ?>s</h2>
<?php foreach ($ms as $r): ?>
<details class="d"><summary><?= e($r['name']) ?><?= $r['role'] === 'admin' ? ' 🛡️' : '' ?> · <?= e($r['email']) ?> · <?= money($r['wallet']) ?> · <?= $r['famapi_connection_id'] ? 'Live' : 'Not connected' ?> · <?= is_premium($r) ? '👑 ' . e(plan_name($r)) : 'Free' ?> <?= $r['status'] === 'blocked' ? '· BLOCKED' : '' ?></summary>
  <p class="muted small">ID <?= (int)$r['id'] ?> · <?= (int)$r['oc'] ?> orders · joined <?= e($r['created_at']) ?> · plan until <?= e($r['plan_until'] ?? '-') ?></p>
  <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="do" value="wallet_adjust">
    <label>Wallet ± ₹ (e.g. 500 or -200)<input name="amount" inputmode="decimal"></label><label>Note<input name="note" placeholder="reason"></label><button class="btn sm">Adjust</button></form>
  <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="do" value="grant_plan">
    <label>Give plan (free)<select name="plan_id"><?php foreach ($pl as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?> (<?= (int)$p['days'] ?>d)</option><?php endforeach; ?></select></label><button class="btn sm">Grant</button></form>
  <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="do" value="set_conn">
    <label>Connection ID (blank = disconnect)<input name="connection_id" value="<?= e($r['famapi_connection_id']) ?>" placeholder="conn_..."></label><button class="btn sm">Save</button></form>
  <div class="row" style="margin-top:6px">
    <?php if ($r['id'] != $a['id']): ?>
    <form method="post" style="flex:0 0 auto"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="do" value="<?= $r['status'] === 'active' ? 'block' : 'unblock' ?>"><button class="btn ghost sm"><?= $r['status'] === 'active' ? 'Block' : 'Unblock' ?></button></form><?php endif; ?>
    <?php if (is_premium($r)): ?><form method="post" style="flex:0 0 auto" onsubmit="return confirm('Remove plan?')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="do" value="clear_plan"><button class="btn ghost sm">Remove plan</button></form><?php endif; ?>
  </div>
</details>
<?php endforeach; ?></div>

<?php elseif ($tab === 'orders'):
    $per = 30; $pg = max(1, (int)($_GET['p'] ?? 1)); $off = ($pg - 1) * $per;
    $f = $_GET['status'] ?? ''; $kind = $_GET['kind'] ?? ''; $qq = trim($_GET['q'] ?? '');
    $w = '1=1'; $args = [];
    if (in_array($f, ['pending', 'success', 'failed', 'expired', 'refunded'], true)) { $w .= ' AND o.status = ?'; $args[] = $f; }
    if (in_array($kind, ['payment', 'deposit'], true)) { $w .= ' AND o.kind = ?'; $args[] = $kind; }
    if ($qq !== '') { $w .= ' AND (o.order_id LIKE ? OR o.merchant_ref LIKE ?)'; $args[] = "%$qq%"; $args[] = "%$qq%"; }
    $c = db()->prepare("SELECT COUNT(*) FROM orders o WHERE $w"); $c->execute($args); $total = (int)$c->fetchColumn();
    $q = db()->prepare("SELECT o.*, m.name mn FROM orders o JOIN merchants m ON m.id=o.merchant_id WHERE $w ORDER BY o.id DESC LIMIT $per OFFSET $off"); $q->execute($args); $os = $q->fetchAll(); ?>
<div class="card"><h2>All orders</h2>
<form method="get" class="row" style="margin-bottom:10px"><input type="hidden" name="tab" value="orders">
  <label>Search<input name="q" value="<?= e($qq) ?>" placeholder="order id / reference"></label>
  <label>Status<select name="status"><option value="">All</option><?php foreach (['pending', 'success', 'failed', 'expired', 'refunded'] as $s): ?><option <?= $f === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select></label>
  <label>Type<select name="kind"><option value="">All</option><option <?= $kind === 'payment' ? 'selected' : '' ?>>payment</option><option <?= $kind === 'deposit' ? 'selected' : '' ?>>deposit</option></select></label>
  <button class="btn sm">Filter</button></form>
<div class="tw"><table><tr><th><?= e(t('order_id')) ?></th><th><?= e(t('merchant')) ?></th><th>Type</th><th><?= e(t('amount')) ?></th><th><?= e(t('status')) ?></th><th>Created</th><th>Manual action</th></tr>
<?php foreach ($os as $r): ?><tr><td><a href="<?= e(site_url('checkout.php?order_id=' . urlencode($r['order_id']))) ?>" target="_blank"><?= e($r['order_id']) ?></a></td><td><?= e($r['mn']) ?></td><td><?= e($r['kind']) ?></td><td><?= money($r['amount']) ?></td><td><?= status_badge($r['status']) ?><?= $r['manual'] ? ' <span class="muted small">manual</span>' : '' ?></td><td><?= e($r['created_at']) ?></td>
<td style="white-space:nowrap">
<?php if ($r['status'] !== 'success'): ?><form method="post" style="display:inline" onsubmit="return confirm('Mark this order as SUCCESS? Fee/wallet/webhook will be applied.')"><?= csrf_field() ?><input type="hidden" name="do" value="order_status"><input type="hidden" name="oid" value="<?= (int)$r['id'] ?>"><input type="hidden" name="to" value="success"><button class="btn green sm">Success</button></form><?php endif; ?>
<?php if (in_array($r['status'], ['pending', 'expired'], true)): ?><form method="post" style="display:inline" onsubmit="return confirm('Mark this order as FAILED?')"><?= csrf_field() ?><input type="hidden" name="do" value="order_status"><input type="hidden" name="oid" value="<?= (int)$r['id'] ?>"><input type="hidden" name="to" value="failed"><button class="btn red sm">Fail</button></form><?php endif; ?>
</td></tr><?php endforeach; if (!$os): ?><tr><td colspan="7" class="muted">No orders.</td></tr><?php endif; ?></table></div>
<p class="small muted"><?php if ($pg > 1): ?><a href="?tab=orders&p=<?= $pg - 1 ?>&status=<?= e($f) ?>&kind=<?= e($kind) ?>&q=<?= e($qq) ?>">← Prev</a> <?php endif; ?>Page <?= $pg ?> · <?= $total ?> orders<?php if ($off + $per < $total): ?> <a href="?tab=orders&p=<?= $pg + 1 ?>&status=<?= e($f) ?>&kind=<?= e($kind) ?>&q=<?= e($qq) ?>">Next →</a><?php endif; ?></p></div>

<?php elseif ($tab === 'plans'):
    $pl = db()->query('SELECT p.*, (SELECT COUNT(*) FROM merchants m WHERE m.plan_id=p.id AND m.plan_until > "' . now() . '") subs FROM plans p ORDER BY sort_order, price')->fetchAll(); ?>
<div class="alert info">Plans are what merchants see on the <b><?= e(t('premium')) ?></b> page. Any paid plan unlocks the 6 premium themes and removes ORVIX branding from the Cashier page. Price is charged from the merchant's <?= e(t('wallet')) ?>. "Orders / month" 0 = unlimited.</div>
<?php foreach (array_merge($pl, [['id' => 0, 'name' => '', 'price' => 0, 'days' => 30, 'fee_percent' => 0.5, 'monthly_orders' => 0, 'perks' => '', 'active' => 1, 'sort_order' => count($pl) + 1, 'subs' => 0]]) as $p): ?>
<div class="card"><h3><?= $p['id'] ? e($p['name']) . ' <span class="muted small">(' . (int)$p['subs'] . ' active subscribers)</span>' : '➕ Add new plan' ?></h3>
<form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="plan_save"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
  <div class="row"><label>Name<input name="name" value="<?= e($p['name']) ?>" required></label><label>Price (₹)<input name="price" value="<?= e(p2($p['price'])) ?>"></label><label>Days<input name="days" value="<?= (int)$p['days'] ?>"></label></div>
  <div class="row"><label>Fee % per order<input name="fee_percent" value="<?= e(p2($p['fee_percent'])) ?>"></label><label>Orders / month<input name="monthly_orders" value="<?= (int)$p['monthly_orders'] ?>"></label><label>Sort<input name="sort_order" value="<?= (int)$p['sort_order'] ?>"></label></div>
  <label>Extra perk text (optional)<input name="perks" value="<?= e($p['perks']) ?>"></label>
  <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="active" value="1" style="width:auto;margin:0" <?= $p['active'] ? 'checked' : '' ?>> Active (visible to merchants)</label>
  <button class="btn sm"><?= $p['id'] ? 'Save' : 'Add plan' ?></button></form>
<?php if ($p['id']): ?><form method="post" onsubmit="return confirm('Delete this plan?')" style="margin-top:8px"><?= csrf_field() ?><input type="hidden" name="do" value="plan_delete"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn ghost sm">Delete</button></form><?php endif; ?>
</div><?php endforeach; ?>

<?php elseif ($tab === 'rates'): ?>
<div class="card"><h2>Wallet &amp; Rates</h2>
<form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="rates">
  <div class="row"><label>Free plan fee % per successful order<input name="fee_percent" value="<?= e(p2(setting_num('fee_percent'))) ?>"></label><label>Flat fee ₹ per order (all plans)<input name="fee_flat" value="<?= e(p2(setting_num('fee_flat'))) ?>"></label></div>
  <div class="row"><label>Minimum deposit ₹<input name="min_deposit" value="<?= e(p2(setting_num('min_deposit'))) ?>"></label><label>Deposit bonus %<input name="deposit_bonus" value="<?= e(p2(setting_num('deposit_bonus'))) ?>"></label></div>
  <div class="row"><label>Free plan orders / month (0 = unlimited)<input name="free_monthly_orders" value="<?= e(p2(setting_num('free_monthly_orders'))) ?>"></label><label>Referral share % of the fee<input name="ref_percent" value="<?= e(p2(setting_num('ref_percent'))) ?>"></label></div>
  <label>Unlock fee ₹ — merchants pay once (from API Wallet) to connect their OWN FamPay/Gmail; 0 = free<input name="gm_unlock_fee" value="<?= e(p2(setting_num('gm_unlock_fee'))) ?>"></label>
  <label>Support link (optional)<input name="support_link" value="<?= e(setting('support_link')) ?>" placeholder="https://t.me/yourchannel"></label>
  <button class="btn">Save rates</button></form>
<p class="muted small">The fee is deducted from the merchant's <?= e(t('wallet')) ?> after each verified successful order. Orders are refused when the balance can't cover the fee. Paid plans use their own fee % (set in Plans).</p></div>

<?php elseif ($tab === 'providers'): ?>
<div class="card"><h2>Providers</h2>
<div class="tw"><table><tr><th>Provider</th><th>Status</th></tr>
<tr><td><img src="<?= e(site_url('assets/fampay.png')) ?>" width="22" height="22" style="border-radius:6px;vertical-align:-6px"> FamPay (via FamApi)</td><td><span class="badge success">Active</span></td></tr>
<tr><td><img src="<?= e(site_url('assets/fampay.png')) ?>" width="22" height="22" style="border-radius:6px;vertical-align:-6px"> FamPay (Gmail auto-verify)</td><td><span class="badge success">Active</span> <span class="muted small">merchants connect their own Gmail on the Connect page</span></td></tr>
<tr><td><img src="<?= e(site_url('assets/phonepe.png')) ?>" width="22" height="22" style="border-radius:6px;vertical-align:-6px"> PhonePe</td><td><span class="badge pending">Not integrated</span></td></tr>
<tr><td><img src="<?= e(site_url('assets/paytm.png')) ?>" width="22" height="22" style="border-radius:6px;vertical-align:-6px"> Paytm</td><td><span class="badge pending">Not integrated</span></td></tr></table></div>
<p class="muted">FamApi only supports FamPay accounts. To add PhonePe / Paytm (or any other merchant app) a verification API for that provider is needed — share its API documentation and the provider can be added here. They are shown as "soon" on the merchants' Connect page.</p></div>

<?php elseif ($tab === 'branding'): ?>
<div class="card" style="max-width:560px"><h2>Gateway name &amp; logo</h2>
<form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="do" value="branding">
  <label>Gateway name (shown on the website, panels and payment pages)<input name="site_name" maxlength="40" value="<?= e(setting('site_name')) ?>" placeholder="<?= e(SITE_NAME) ?>"></label>
  <p><img src="<?= e(brand_logo()) ?>" alt="" width="72" height="72" style="border-radius:14px;object-fit:cover;border:1px solid var(--line)"></p>
  <label>New logo (PNG / JPG / WEBP, max 1 MB, square works best)<input type="file" name="logo" accept="image/png,image/jpeg,image/webp"></label>
  <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="logo_reset" value="1" style="width:auto;margin:0"> Go back to the default logo</label>
  <button class="btn">Save branding</button></form>
<p class="muted small">Leave the name empty to use the default (<?= e(SITE_NAME) ?>). Merchants on a Premium plan can still show their own business name and logo on their payment page.</p></div>

<?php elseif ($tab === 'popups'):
  $pl = db()->query('SELECT * FROM popups ORDER BY id DESC LIMIT 50')->fetchAll();
  $ed = null; if (!empty($_GET['edit'])) foreach ($pl as $x) if ((int)$x['id'] === (int)$_GET['edit']) $ed = $x;
  $dtv = fn($v) => $v ? date('Y-m-d\TH:i', strtotime($v)) : ''; ?>
<div class="card"><h2>Live Status ticker (dashboard)</h2>
<form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="ticker">
  <label>Scrolling text shown on every merchant dashboard (empty = hidden)<input name="ticker_text" maxlength="300" value="<?= e(setting('ticker_text')) ?>"></label><button class="btn sm">Save ticker</button></form></div>
<div class="card"><h2><?= $ed ? 'Edit popup' : 'New popup / ad' ?></h2>
<form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="popup_save"><input type="hidden" name="id" value="<?= (int)($ed['id'] ?? 0) ?>">
  <label>Title<input name="title" required maxlength="120" value="<?= e($ed['title'] ?? '') ?>"></label>
  <label>Text<textarea name="body" style="min-height:80px"><?= e($ed['body'] ?? '') ?></textarea></label>
  <label>Image URL (optional, https)<input name="image_url" value="<?= e($ed['image_url'] ?? '') ?>"></label>
  <div class="row"><label>Button text<input name="btn_text" maxlength="40" value="<?= e($ed['btn_text'] ?? '') ?>" placeholder="Spin Now"></label>
  <label>Button link (https://…, premium.php, or #mystery)<input name="btn_url" value="<?= e($ed['btn_url'] ?? '') ?>"></label></div>
  <div class="row"><label>Start<input type="datetime-local" name="starts_at" value="<?= e($dtv($ed['starts_at'] ?? null)) ?>"></label><label>End<input type="datetime-local" name="ends_at" value="<?= e($dtv($ed['ends_at'] ?? null)) ?>"></label></div>
  <div class="row"><label>Show to<select name="audience"><?php foreach (['all' => 'Everyone', 'free' => 'Free users', 'premium' => 'Premium users'] as $k => $l): ?><option value="<?= $k ?>" <?= ($ed['audience'] ?? 'all') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
  <label>How often<select name="frequency"><?php foreach (['daily' => 'Once a day', 'session' => 'Once per session', 'once' => 'Only once ever', 'always' => 'Every page load'] as $k => $l): ?><option value="<?= $k ?>" <?= ($ed['frequency'] ?? 'daily') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label></div>
  <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="active" value="1" style="width:auto;margin:0" <?= !$ed || $ed['active'] ? 'checked' : '' ?>> Active</label>
  <button class="btn"><?= $ed ? 'Update' : 'Create' ?> popup</button><?php if ($ed): ?> <a class="btn ghost" href="?tab=popups">Cancel</a><?php endif; ?></form>
<p class="muted small">Only the newest matching active popup is shown to a merchant (dashboard).</p></div>
<div class="card"><h2>Popups</h2><div class="tw"><table><tr><th>ID</th><th>Title</th><th>For</th><th>When</th><th>Active</th><th></th></tr>
<?php foreach ($pl as $x): ?><tr><td><?= (int)$x['id'] ?></td><td><?= e($x['title']) ?></td><td><?= e($x['audience']) ?></td><td><?= e($x['frequency']) ?></td><td><?= $x['active'] ? 'Yes' : 'No' ?></td>
<td><a class="btn ghost sm" href="?tab=popups&edit=<?= (int)$x['id'] ?>">Edit</a>
<form method="post" style="display:inline" onsubmit="return confirm('Delete?')"><?= csrf_field() ?><input type="hidden" name="do" value="popup_delete"><input type="hidden" name="id" value="<?= (int)$x['id'] ?>"><button class="btn red sm">Delete</button></form></td></tr>
<?php endforeach; if (!$pl): ?><tr><td colspan="6" class="muted">No popups yet.</td></tr><?php endif; ?></table></div></div>

<?php elseif ($tab === 'mystery'):
  $op = db()->query('SELECT o.*, m.email FROM mystery_opens o LEFT JOIN merchants m ON m.id = o.merchant_id ORDER BY o.id DESC LIMIT 30')->fetchAll(); ?>
<div class="card"><h2>Mystery Box</h2>
<form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="mystery">
  <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="mb_enabled" value="1" style="width:auto;margin:0" <?= setting('mb_enabled') === '1' ? 'checked' : '' ?>> Enabled (floating box on merchant pages)</label>
  <div class="row"><label>Title<input name="mb_title" value="<?= e(setting('mb_title')) ?>"></label>
  <label>Ends at (empty = daily countdown)<input type="datetime-local" name="mb_ends_at" value="<?= e(setting('mb_ends_at') ? date('Y-m-d\TH:i', strtotime(setting('mb_ends_at'))) : '') ?>"></label>
  <label>Boxes per merchant per day<input name="mb_daily_boxes" value="<?= e(setting('mb_daily_boxes')) ?>"></label></div>
  <label>Rewards — one per line: <code>Label|type|value|weight</code> (type = wallet, plan or none; for plan, value = plan ID)<textarea name="mb_rewards" style="min-height:140px"><?= e(trim(setting('mb_rewards')) !== '' ? setting('mb_rewards') : mb_default_rewards()) ?></textarea></label>
  <button class="btn">Save</button></form>
<p class="muted small">Wallet rewards are real money credited from YOUR budget — keep the amounts and chances low. Weight = relative chance.</p></div>
<div class="card"><h2>Recent opens</h2><div class="tw"><table><tr><th>When</th><th>Merchant</th><th>Reward</th></tr>
<?php foreach ($op as $o): ?><tr><td><?= e($o['opened_at']) ?></td><td><?= e($o['email'] ?? $o['merchant_id']) ?></td><td><?= e($o['label']) ?></td></tr><?php endforeach; if (!$op): ?><tr><td colspan="3" class="muted">None yet.</td></tr><?php endif; ?></table></div></div>

<?php else: ?>
<div class="card"><h2>AI Assistant settings</h2>
<form method="post" style="margin-bottom:12px"><?= csrf_field() ?><input type="hidden" name="do" value="ai_gemini"><button class="btn ghost sm">Use Google Gemini preset</button></form>
<form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="ai">
  <label>Endpoint (OpenAI-compatible chat completions URL)<input name="ai_endpoint" value="<?= e(setting('ai_endpoint')) ?>"></label>
  <div class="row"><label>Model<input name="ai_model" value="<?= e(setting('ai_model')) ?>"></label>
  <label>API key <?= setting('ai_key') !== '' ? '(saved — leave blank to keep)' : '(not set)' ?><input name="ai_key" type="password" autocomplete="off" placeholder="paste to replace"></label></div>
  <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="ai_key_clear" value="1" style="width:auto;margin:0"> Remove the saved key</label>
  <label>Admin prompt — instructions &amp; knowledge the AI uses to guide merchants (empty = default prompt with full ORVIX PAY API docs)<textarea name="ai_prompt" style="min-height:260px"><?= e(setting('ai_prompt') !== '' ? setting('ai_prompt') : default_ai_prompt()) ?></textarea></label>
  <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="ai_prompt_reset" value="1" style="width:auto;margin:0"> Reset the prompt to the default</label>
  <button class="btn">Save AI settings</button></form>
<p class="muted small">Default is Google Gemini (OpenAI-compatible endpoint). Get a key from aistudio.google.com and paste it above; if the model name is rejected, change it to one listed in AI Studio. Any OpenAI-compatible provider also works. The key is stored server-side and never shown to merchants.</p></div>
<?php endif; ?>
<?php page_footer();
