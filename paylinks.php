<?php
/** Pay by Link (fixed amount) and Static Paylink (payer enters amount) — both with optional after-payment delivery. */
require_once __DIR__ . '/includes/bootstrap.php';
$m = require_live();
$T = (($PL_TYPE ?? 'fixed') === 'static') ? 'static' : 'fixed';
$self = $T === 'static' ? 'static-paylink.php' : 'paylinks.php';
function new_paylink_code(): string { $a = 'abcdefghjkmnpqrstuvwxyz23456789'; $c = ''; for ($i = 0; $i < 8; $i++) $c .= $a[random_int(0, strlen($a) - 1)]; return $c; }
function own_link(int $mid, int $id, string $T): ?array { $s = db()->prepare('SELECT * FROM paylinks WHERE id = ? AND merchant_id = ? AND type = ?'); $s->execute([$id, $mid, $T]); return $s->fetch() ?: null; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? ''; $id = (int)($_POST['id'] ?? 0);
    if ($do === 'save') {
        $old = $id ? own_link((int)$m['id'], $id, $T) : null;
        $title = mb_substr(trim($_POST['title'] ?? ''), 0, 120);
        $amt = $T === 'fixed' ? sanitize_amount($_POST['amount'] ?? '') : null;
        $one = ($T === 'fixed' && !empty($_POST['one_time'])) ? 1 : 0;
        $min = $T === 'static' ? (sanitize_amount($_POST['min_amount'] ?? '1') ?? 1) : 1;
        [$d, $derr] = delivery_from_post($old);
        $n = db()->prepare('SELECT COUNT(*) FROM paylinks WHERE merchant_id = ? AND type = ?'); $n->execute([$m['id'], $T]);
        if ($title === '' || ($T === 'fixed' && $amt === null)) flash('err', $T === 'fixed' ? 'Enter a title and a valid amount.' : 'Enter a title.');
        elseif ($derr) flash('err', $derr);
        elseif ($id && !$old) flash('err', 'Link not found.');
        elseif (!$id && (int)$n->fetchColumn() >= 100) flash('err', 'Link limit (100) reached. Delete old links.');
        elseif ($old) {
            db()->prepare('UPDATE paylinks SET one_time=?, title=?, amount=?, min_amount=?, deliver_url=?, deliver_text=?, file_name=?, file_path=? WHERE id=? AND merchant_id=?')
                ->execute([$one, $title, $amt, $min, $d['url'], $d['text'], $d['fname'], $d['fpath'], $id, $m['id']]); flash('ok', 'Link updated.');
        } else {
            $code = new_paylink_code();
            db()->prepare('INSERT INTO paylinks (merchant_id, code, type, title, amount, min_amount, active, uses, created_at, deliver_url, deliver_text, file_name, file_path, one_time) VALUES (?,?,?,?,?,?,1,0,?,?,?,?,?,?)')
                ->execute([$m['id'], $code, $T, $title, $amt, $min, now(), $d['url'], $d['text'], $d['fname'], $d['fpath'], $one]);
            log_activity((int)$m['id'], $T . '_link_create', $code); flash('ok', 'Payment link created.');
        }
    } elseif ($do === 'toggle') {
        db()->prepare('UPDATE paylinks SET active = 1 - active WHERE id = ? AND merchant_id = ? AND type = ?')->execute([$id, $m['id'], $T]); flash('ok', 'Updated.');
    } elseif ($do === 'delete') {
        if ($old = own_link((int)$m['id'], $id, $T)) {
            if ($old['file_path']) @unlink(ORVIX_FILE_DIR . '/' . $old['file_path']);
            db()->prepare('DELETE FROM paylinks WHERE id = ? AND merchant_id = ?')->execute([$id, $m['id']]); log_activity((int)$m['id'], 'paylink_delete', (string)$id); flash('ok', 'Deleted.');
        }
    }
    redirect(site_url($self));
}
$q = db()->prepare('SELECT * FROM paylinks WHERE merchant_id = ? AND type = ? ORDER BY id DESC LIMIT 100'); $q->execute([$m['id'], $T]); $rows = $q->fetchAll();
$ed = null; if (!empty($_GET['edit'])) foreach ($rows as $x) if ((int)$x['id'] === (int)$_GET['edit']) $ed = $x;
$ttl = $T === 'static' ? 'Static Paylink' : 'Pay by Link';
page_header($ttl, $T === 'static' ? 'static' : 'paylinks');
?>
<h1><?= e($ttl) ?></h1>
<div class="card" style="max-width:560px"><h2><?= $ed ? 'Edit link' : 'Create payment link' ?></h2>
<form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="do" value="save"><input type="hidden" name="id" value="<?= (int)($ed['id'] ?? 0) ?>">
  <label>Title / product name<input name="title" maxlength="120" required value="<?= e($ed['title'] ?? '') ?>" placeholder="e.g. Course fee"></label>
  <?php if ($T === 'fixed'): ?><label>Amount (₹)<input name="amount" inputmode="decimal" required value="<?= e(isset($ed['amount']) ? rtrim(rtrim(number_format((float)$ed['amount'], 2, '.', ''), '0'), '.') : '') ?>" placeholder="499"></label>
  <?php else: ?><label>Minimum amount (₹) — payer enters any amount ≥ this<input name="min_amount" inputmode="decimal" value="<?= e(rtrim(rtrim(number_format((float)($ed['min_amount'] ?? 1), 2, '.', ''), '0'), '.')) ?>"></label><?php endif; ?>
  <?php if ($T === 'fixed'): ?><label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="one_time" value="1" style="width:auto;margin:0" <?= !$ed || $ed['one_time'] ? 'checked' : '' ?>> One-time link (after it is paid, opening it shows "Payment Received")</label><?php endif; ?>
  <?= delivery_fields_html($ed) ?>
  <button class="btn"><?= $ed ? 'Update' : 'Create link' ?></button><?php if ($ed): ?> <a class="btn ghost" href="<?= e(site_url($self)) ?>">Cancel</a><?php endif; ?></form>
<p class="muted small">After a verified payment the buyer sees the link / message / file you add above, on the payment-success page. Money goes to your connected FamPay; the normal fee is charged from your API Wallet.</p></div>
<div class="card"><h2>Your links</h2><div class="tw"><table><tr><th>Title</th><th><?= $T === 'fixed' ? 'Amount' : 'Min' ?></th><th>Delivery</th><th>Started</th><th>Link</th><th></th></tr>
<?php foreach ($rows as $r): $u = site_url('pay.php?c=' . $r['code']); ?>
<tr><td><?= e($r['title']) ?> <?= $r['active'] ? '' : '<span class="badge pending">off</span>' ?></td><td><?= money($T === 'fixed' ? $r['amount'] : $r['min_amount']) ?></td>
<td class="small"><?= implode(' + ', array_filter([$r['deliver_url'] ? 'link' : '', $r['file_path'] ? 'file' : '', $r['deliver_text'] ? 'note' : ''])) ?: '-' ?></td><td><?= (int)$r['uses'] ?></td>
<td><code class="k"><?= e($u) ?></code> <button type="button" class="btn ghost sm" onclick="navigator.clipboard&&navigator.clipboard.writeText(<?= e(json_encode($u)) ?>);this.textContent='Copied'">Copy</button></td>
<td><a class="btn ghost sm" href="?edit=<?= (int)$r['id'] ?>">Edit</a>
<form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn ghost sm"><?= $r['active'] ? 'Disable' : 'Enable' ?></button></form>
<form method="post" style="display:inline" onsubmit="return confirm('Delete this link?')"><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn red sm">Delete</button></form></td></tr>
<?php endforeach; if (!$rows): ?><tr><td colspan="6" class="muted">No links yet.</td></tr><?php endif; ?></table></div>
<p class="small muted">See results in <a href="<?= e(site_url('transactions.php?src=link')) ?>">Link Payments</a>.</p></div>
<?php page_footer();
