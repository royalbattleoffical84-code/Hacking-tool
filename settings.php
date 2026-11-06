<?php
require_once __DIR__ . '/includes/bootstrap.php';
$m = require_login();
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';
    if ($do === 'profile') {
        $name = trim($_POST['name'] ?? ''); $biz = trim($_POST['business_name'] ?? ''); $logo = trim($_POST['logo_url'] ?? '');
        $hook = trim($_POST['webhook_url'] ?? ''); $mob = mb_substr(preg_replace('/[^0-9+]/', '', $_POST['mobile'] ?? ''), 0, 20);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) $err = 'Name must be 2-120 characters.';
        elseif ($logo !== '' && !valid_http_url($logo)) $err = 'Logo URL must start with http:// or https://';
        elseif ($hook !== '' && !valid_http_url($hook)) $err = 'Webhook URL must start with http:// or https://';
        else {
            db()->prepare('UPDATE merchants SET name=?, business_name=?, logo_url=?, webhook_url=?, mobile=? WHERE id=?')
                ->execute([$name, mb_substr($biz, 0, 120) ?: null, $logo ?: null, $hook ?: null, $mob ?: null, $m['id']]);
            log_activity((int)$m['id'], 'settings_save'); flash('ok', 'Settings saved.'); redirect(site_url('settings.php'));
        }
    } elseif ($do === 'password') {
        $new = (string)($_POST['new'] ?? '');
        if (!password_verify((string)($_POST['old'] ?? ''), $m['password_hash'])) $err = 'Current password is wrong.';
        elseif (strlen($new) < 8) $err = 'New password must be at least 8 characters.';
        else {
            db()->prepare('UPDATE merchants SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $m['id']]);
            log_activity((int)$m['id'], 'password_change'); flash('ok', 'Password changed.'); redirect(site_url('settings.php'));
        }
    }
}
$m = merchant_by_id((int)$m['id']);
page_header(t('settings'), 'settings');
?>
<h1><?= e(t('settings')) ?></h1>
<?php if ($err): ?><div class="alert err"><?= e($err) ?></div><?php endif; ?>
<div class="card"><h2>Profile &amp; Webhook</h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="profile">
    <label>Your name<input name="name" value="<?= e($m['name']) ?>" required></label>
    <label>Mobile<input name="mobile" value="<?= e($m['mobile']) ?>"></label>
    <label>Business name <?= is_premium($m) ? '' : '(shown on the Cashier page for Premium merchants)' ?><input name="business_name" value="<?= e($m['business_name']) ?>"></label>
    <label>Logo URL <?= is_premium($m) ? '' : '(Premium)' ?><input name="logo_url" placeholder="https://…/logo.png" value="<?= e($m['logo_url']) ?>"></label>
    <label>Webhook URL (optional) — we POST a signed JSON on every successful order<input name="webhook_url" placeholder="https://yoursite.com/orvix-webhook.php" value="<?= e($m['webhook_url']) ?>"></label>
    <button class="btn">Save</button>
  </form>
  <p class="muted small">Webhook header <code>X-Orvix-Signature</code> = HMAC-SHA256 of the body using your API token. Treat it as a hint and still verify with order-status.</p>
</div>
<div class="card"><h2>Change password</h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="password">
    <label>Current password<input type="password" name="old" required></label>
    <label>New password (min 8)<input type="password" name="new" minlength="8" required></label>
    <button class="btn ghost">Change password</button>
  </form>
</div>
<?php page_footer();
