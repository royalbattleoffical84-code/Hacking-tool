<?php
/** Cashier theme preview with demo data (no order is created). */
require_once __DIR__ . '/includes/bootstrap.php';
$m = require_login();
$themes = orvix_themes(); $k = $_GET['theme'] ?? resolve_theme($m['theme_pay'], is_premium($m));
if (!isset($themes[$k])) $k = 'orvix';
page_header('Preview', '', true, $k);
?>
<div class="cash card">
  <div class="head"><img src="<?= e(brand_logo()) ?>" width="46" height="46" alt="" style="border-radius:10px">
    <div class="muted small"><?= e(t('cashier')) ?> · Demo Store</div><div class="amt">₹499.00</div><div class="muted small"><?= e(t('order_id')) ?>: ORX000000DEMO</div></div>
  <h2 class="c"><?= e(t('scan_pay')) ?></h2>
  <div class="qr"><svg width="176" height="176" viewBox="0 0 8 8" shape-rendering="crispEdges"><rect width="8" height="8" fill="#fff"/><path d="M0 0h3v3H0zM5 0h3v3H5zM0 5h3v3H0zM4 4h1v1H4zM6 5h2v1H6zM5 7h3v1H5z" fill="#000"/></svg></div>
  <a class="btn block" href="#"><?= e(t('pay_now')) ?></a>
  <p class="c small" style="margin-top:14px">⏳ Expires in <span class="timer">14:59</span></p>
  <p class="pwr">Theme: <?= e($themes[$k][0]) ?></p>
</div>
<p class="c small" style="padding-bottom:20px"><?php foreach ($themes as $key => $t): ?><a href="?theme=<?= e($key) ?>" style="margin:0 6px"><?= e($t[0]) ?></a><?php endforeach; ?></p>
<?php page_footer(true);
