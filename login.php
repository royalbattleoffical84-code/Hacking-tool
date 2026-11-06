<?php
require_once __DIR__ . '/includes/bootstrap.php';
if ($cm = current_merchant()) redirect(site_url(merchant_live($cm) ? 'dashboard.php' : 'connect.php'));
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $_SESSION['fails'] = $_SESSION['fails'] ?? 0;
    if ($_SESSION['fails'] >= 5 && (time() - ($_SESSION['fail_t'] ?? 0)) < 60) {
        $err = 'Too many attempts. Try again in a minute.';
    } else {
        $email = strtolower(trim($_POST['email'] ?? ''));
        $st = db()->prepare('SELECT * FROM merchants WHERE email = ?'); $st->execute([$email]);
        $m = $st->fetch();
        if ($m && password_verify((string)($_POST['password'] ?? ''), $m['password_hash'])) {
            if ($m['status'] !== 'active') $err = 'This account is blocked.';
            else {
                session_regenerate_id(true);
                $_SESSION['mid'] = (int)$m['id']; $_SESSION['fails'] = 0; log_activity((int)$m['id'], 'login');
                // ZapUPI-style onboarding: not connected => straight to Connect Merchant
                redirect(site_url(merchant_live($m) ? 'dashboard.php' : 'connect.php'));
            }
        } else { $_SESSION['fails']++; $_SESSION['fail_t'] = time(); $err = 'Wrong email or password.'; }
    }
}
page_header(t('login'));
?>
<div class="auth card">
  <h1><?= e(t('merchant')) ?> <?= e(t('login')) ?></h1>
  <?php if ($err): ?><div class="alert err"><?= e($err) ?></div><?php endif; ?>
  <form method="post"><?= csrf_field() ?>
    <label>Email<input type="email" name="email" required></label>
    <label>Password<input type="password" name="password" required></label>
    <button class="btn block"><?= e(t('login')) ?></button>
  </form>
  <p class="muted c small">New here? <a href="<?= e(site_url('register.php')) ?>"><?= e(t('register')) ?></a></p>
</div>
<?php page_footer();
