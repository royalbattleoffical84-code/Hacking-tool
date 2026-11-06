<?php
require_once __DIR__ . '/includes/bootstrap.php';
$m = require_live();
$per = 30; $p = max(1, (int)($_GET['p'] ?? 1)); $off = ($p - 1) * $per;
$t = db()->prepare('SELECT COUNT(*) FROM wallet_txns WHERE merchant_id = ?'); $t->execute([$m['id']]); $total = (int)$t->fetchColumn();
$q = db()->prepare("SELECT * FROM wallet_txns WHERE merchant_id = ? ORDER BY id DESC LIMIT $per OFFSET $off"); $q->execute([$m['id']]); $rows = $q->fetchAll();
page_header('Wallet History', 'wallethist');
?>
<h1>Wallet History</h1>
<div class="card"><p>Balance: <b><?= money($m['wallet']) ?></b> · <a href="<?= e(site_url('wallet.php')) ?>">Add money</a></p>
<div class="tw"><table><tr><th>Date</th><th>Type</th><th>Amount</th><th>Balance</th><th>Note</th></tr>
<?php foreach ($rows as $r): ?><tr><td><?= e($r['created_at']) ?></td><td><?= e(ucfirst($r['type'])) ?></td>
<td style="color:<?= $r['amount'] >= 0 ? 'var(--ok)' : 'var(--err)' ?>"><?= ($r['amount'] >= 0 ? '+' : '−') . money(abs($r['amount'])) ?></td><td><?= money($r['balance_after']) ?></td><td><?= e($r['note']) ?></td></tr>
<?php endforeach; if (!$rows): ?><tr><td colspan="5" class="muted">No wallet activity yet.</td></tr><?php endif; ?></table></div>
<p class="small muted"><?php if ($p > 1): ?><a href="?p=<?= $p - 1 ?>">← Prev</a> <?php endif; ?>Page <?= $p ?> · <?= $total ?> entries<?php if ($off + $per < $total): ?> <a href="?p=<?= $p + 1 ?>">Next →</a><?php endif; ?></p></div>
<?php page_footer();
