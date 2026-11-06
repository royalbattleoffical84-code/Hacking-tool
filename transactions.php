<?php
require_once __DIR__ . '/includes/bootstrap.php';
$m = require_live();
reconcile_pending((int)$m['id']);
$per = 25; $p = max(1, (int)($_GET['p'] ?? 1)); $off = ($p - 1) * $per;
$f = $_GET['status'] ?? '';
$where = 'merchant_id = ? AND kind = "payment"'; $args = [$m['id']];
$src = ($_GET['src'] ?? '') === 'link' ? 'link' : '';
if ($src) $where .= ' AND merchant_ref LIKE "paylink:%"';
if (in_array($f, ['pending','success','failed','expired','refunded'], true)) { $where .= ' AND status = ?'; $args[] = $f; }
$tot = db()->prepare("SELECT COUNT(*) FROM orders WHERE $where"); $tot->execute($args); $total = (int)$tot->fetchColumn();
$q = db()->prepare("SELECT * FROM orders WHERE $where ORDER BY id DESC LIMIT $per OFFSET $off"); $q->execute($args); $rows = $q->fetchAll();
page_header($src ? 'Link Payments' : t('transactions'), $src ? 'linktx' : 'transactions');
?>
<h1><?= $src ? 'Link Payments' : e(t('transactions')) ?></h1>
<div class="card">
  <form method="get" class="row" style="margin-bottom:12px"><?php if ($src): ?><input type="hidden" name="src" value="link"><?php endif; ?>
    <select name="status" style="margin:0"><option value="">All statuses</option>
      <?php foreach (['pending','success','failed','expired','refunded'] as $s): ?><option <?= $f===$s?'selected':'' ?> value="<?= $s ?>"><?= ucfirst($s) ?></option><?php endforeach; ?>
    </select><button class="btn sm">Filter</button>
  </form>
  <div class="tw"><table><tr><th><?= e(t('order_id')) ?></th><th>Reference</th><th><?= e(t('amount')) ?></th><th>Fee</th><th><?= e(t('status')) ?></th><th>Created</th><th>Paid</th></tr>
  <?php foreach ($rows as $r): ?>
    <tr><td><a href="<?= e(site_url('checkout.php?order_id=' . urlencode($r['order_id']))) ?>"><?= e($r['order_id']) ?></a></td><td><?= e($r['merchant_ref'] ?? '-') ?></td><td><?= money($r['amount']) ?></td><td><?= $r['status']==='success' ? money($r['fee']) : '-' ?></td><td><?= status_badge($r['status']) ?><?= $r['manual'] ? ' <span class="muted small">manual</span>' : '' ?></td><td><?= e($r['created_at']) ?></td><td><?= e($r['paid_at'] ?? '-') ?></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="7" class="muted">Nothing found.</td></tr><?php endif; ?>
  </table></div>
  <p class="small muted">
    <?php if ($p > 1): ?><a href="?src=<?= e($src) ?>&p=<?= $p-1 ?>&status=<?= e($f) ?>">← Prev</a> <?php endif; ?>
    Page <?= $p ?> · <?= $total ?> orders
    <?php if ($off + $per < $total): ?> <a href="?src=<?= e($src) ?>&p=<?= $p+1 ?>&status=<?= e($f) ?>">Next →</a><?php endif; ?>
  </p>
</div>
<?php page_footer();
