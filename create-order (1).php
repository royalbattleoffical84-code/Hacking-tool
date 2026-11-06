<?php
/** Test Payment (logged-in merchant). Server-side cURL -> redirects to checkout.php?order_id=... */
require_once __DIR__ . '/includes/bootstrap.php';
$m = require_live();
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $r = create_orvix_order($m, $_POST['amount'] ?? '', 'test-' . time(), site_url('transactions.php'));
    if ($r['ok']) redirect(site_url('checkout.php?order_id=' . urlencode($r['order']['order_id'])));
    $err = $r['error'];
}
page_header(t('test_payment'), 'test');
?>
<h1><?= e(t('test_payment')) ?></h1>
<?php if ($err): ?><div class="alert err"><?= e($err) ?></div><?php endif; ?>
<div class="card" style="max-width:440px">
  <form method="post"><?= csrf_field() ?>
    <label><?= e(t('amount')) ?> (₹)<input name="amount" inputmode="decimal" placeholder="10" value="<?= e($_POST['amount'] ?? '') ?>" required></label>
    <button class="btn block">Create Order</button>
  </form>
  <p class="muted small">Test payments are real UPI payments and charge the normal fee from your API Wallet.</p>
</div>
<?php page_footer();
