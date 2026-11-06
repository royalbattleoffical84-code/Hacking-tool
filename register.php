<?php
require_once __DIR__ . '/includes/bootstrap.php';
if (current_merchant()) redirect(site_url('dashboard.php'));
$err = '';
$refCode = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $_GET['ref'] ?? ($_POST['ref'] ?? '')));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $name  = trim($_POST['name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $mob   = mb_substr(preg_replace('/[^0-9+]/', '', $_POST['mobile'] ?? ''), 0, 20);
    $pass  = (string)($_POST['password'] ?? '');
    if (mb_strlen($name) < 2 || mb_strlen($name) > 120) $err = 'Name must be 2-120 characters.';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $err = 'Enter a valid email.';
    elseif (strlen($pass) < 8) $err = 'Password must be at least 8 characters.';
    else {
        $st = db()->prepare('SELECT id FROM merchants WHERE email = ?'); $st->execute([$email]);
        if ($st->fetch()) $err = 'This email is already registered. Please login.';
        else {
            $by = null;
            if ($refCode !== '') { $r = db()->prepare('SELECT id FROM merchants WHERE ref_code = ?'); $r->execute([$refCode]); $by = $r->fetchColumn() ?: null; }
            db()->prepare('INSERT INTO merchants (name,email,mobile,password_hash,role,status,api_key,referred_by,created_at) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([$name, $email, $mob ?: null, password_hash($pass, PASSWORD_DEFAULT), 'merchant', 'active', new_api_key(), $by, now()]);
            session_regenerate_id(true);
            $_SESSION['mid'] = (int)db()->lastInsertId(); log_activity((int)$_SESSION['mid'], 'register');
            flash('ok', 'Account created! Connect your FAM merchant to activate your API token.');
            redirect(site_url('connect.php'));
        }
    }
}
page_header(t('register'));
?>
<div class="auth card">
  <h1><?= e(t('merchant')) ?> <?= e(t('register')) ?></h1>
  <?php if ($err): ?><div class="alert err"><?= e($err) ?></div><?php endif; ?>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="ref" value="<?= e($refCode) ?>">
    <label>Full Name<input name="name" value="<?= e($_POST['name'] ?? '') ?>" required></label>
    <label>Email<input type="email" name="email" value="<?= e($_POST['email'] ?? '') ?>" required></label>
    <label>Mobile (optional)<input name="mobile" inputmode="tel" value="<?= e($_POST['mobile'] ?? '') ?>"></label>
    <label>Password (min 8 characters)<input type="password" name="password" minlength="8" required></label>
    <?php if ($refCode): ?><p class="muted small">Referral code: <b><?= e($refCode) ?></b></p><?php endif; ?>
    <button class="btn block"><?= e(t('register')) ?></button>
  </form>
  <p class="muted c small">Already have an account? <a href="<?= e(site_url('login.php')) ?>"><?= e(t('login')) ?></a></p>
</div>
<?php page_footer();
