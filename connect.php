<?php
require_once __DIR__ . '/includes/bootstrap.php';
$m = require_login();
$err = ''; $widget = '';
$connRe = '/^conn_[A-Za-z0-9_\-]{3,100}$/';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';
    if ($do === 'start') {
        $res = famapi_request('POST', '/api/v1/orders/connect-tokens', [
            'externalRef' => 'merchant-' . $m['id'],
            'label'       => mb_substr($m['name'], 0, 80) . ' · ' . brand_name(),
            'redirectUrl' => site_url('connect.php?returned=1'),
        ], 1);
        if ($res['ok'] && !empty($res['data']['widgetUrl'])) $widget = (string)$res['data']['widgetUrl'];
        else [$err] = fam_explain($res);
    } elseif ($do === 'save') {
        $cid = trim($_POST['connection_id'] ?? '');
        if (!preg_match($connRe, $cid)) $err = 'Connection ID must start with "conn_".';
        else {
            db()->prepare('UPDATE merchants SET famapi_connection_id = ?, connected_at = ? WHERE id = ?')->execute([$cid, now(), $m['id']]); log_activity((int)$m['id'], 'connect_fampay');
            flash('ok', 'FAM merchant connected. Your API token is now live!');
            redirect(site_url('dashboard.php'));
        }
    } elseif ($do === 'gm_unlock') {
        $fee = round(setting_num('gm_unlock_fee'), 2); $pdo = db();
        if ($fee > 0 && empty($m['gm_unlocked'])) {
            $pdo->beginTransaction();
            $st = $pdo->prepare('UPDATE merchants SET wallet = wallet - ?, gm_unlocked = 1 WHERE id = ? AND gm_unlocked = 0 AND wallet >= ?'); $st->execute([$fee, $m['id'], $fee]);
            if ($st->rowCount() !== 1) { $pdo->rollBack(); $err = 'Not enough balance in ' . t('wallet') . '. Deposit ' . money($fee) . ' and try again.'; }
            else {
                $b = $pdo->prepare('SELECT wallet FROM merchants WHERE id = ?'); $b->execute([$m['id']]);
                $pdo->prepare('INSERT INTO wallet_txns (merchant_id, type, amount, balance_after, note, ref, created_at) VALUES (?,?,?,?,?,?,?)')->execute([$m['id'], 'unlock', -$fee, (float)$b->fetchColumn(), 'Unlock: connect your own FamPay account', null, now()]);
                $pdo->commit(); log_activity((int)$m['id'], 'gmail_unlock'); flash('ok', 'Unlocked! Now connect your FamPay account.'); redirect(site_url('connect.php'));
            }
        }
    } elseif ($do === 'gm_verify') {
        db()->prepare('UPDATE merchants SET gm_verify = ? WHERE id = ?')->execute([($_POST['gm_verify'] ?? '') === 'auto' ? 'auto' : 'utr', $m['id']]);
        flash('ok', 'Verification method saved.'); redirect(site_url('connect.php'));
    } elseif ($do === 'gm_save') {
        $upi = trim($_POST['gm_upi'] ?? ''); $em = strtolower(trim($_POST['gm_email'] ?? '')); $pw = preg_replace('/\s+/', '', (string)($_POST['gm_pass'] ?? ''));
        $hits = array_values(array_filter($_SESSION['gm_tries'] ?? [], fn($t) => $t > time() - 600)); $hits[] = time(); $_SESSION['gm_tries'] = $hits;
        $busy = db()->prepare("SELECT COUNT(*) FROM orders WHERE merchant_id = ? AND pay_method = 'gmail' AND status = 'pending' AND (expires_at > ? OR utr IS NOT NULL)"); $busy->execute([$m['id'], now()]);
        $dupe = db()->prepare('SELECT COUNT(*) FROM merchants WHERE id <> ? AND (LOWER(gm_email) = ? OR LOWER(gm_upi) = ?)'); $dupe->execute([$m['id'], strtolower(trim($_POST['gm_email'] ?? '')), strtolower($upi)]);
        if (!gm_unlocked($m)) $err = 'Unlock this feature first.';
        elseif (count($hits) > 10) $err = 'Too many attempts. Try again in a few minutes.';
        elseif ((int)$busy->fetchColumn() > 0) $err = 'You have payments in progress. Try again when they are finished.';
        elseif ((int)$dupe->fetchColumn() > 0) $err = 'This Gmail or UPI ID is already connected to another account.';
        elseif (!preg_match('/^[A-Za-z0-9._\-]{2,60}@[A-Za-z]{2,30}$/', $upi)) $err = 'Enter your FamPay UPI ID (like name@fam).';
        elseif (!filter_var($em, FILTER_VALIDATE_EMAIL) || !preg_match('/@(gmail|googlemail)\.com$/', $em)) $err = 'Enter the Gmail address that receives your FamPay payment e-mails.';
        elseif (!preg_match('/^[A-Za-z0-9]{16}$/', $pw)) $err = 'Gmail App Password must be 16 letters (create it in Google Account → Security → App passwords).';
        else {
            [$gok, $gmsg] = gm_selftest($em, $pw);
            if (!$gok) $err = $gmsg;
            else {
                db()->prepare('UPDATE merchants SET gm_upi = ?, gm_email = ?, gm_pass = ?, gm_ok = 1, gm_err = NULL, pay_mode = ? WHERE id = ?')->execute([$upi, $em, gm_encrypt($pw), 'gmail', $m['id']]);
                log_activity((int)$m['id'], 'connect_gmail', $em);
                flash('ok', 'FamPay (Gmail) connected. Your API token is now live!');
                redirect(site_url('dashboard.php'));
            }
        }
    } elseif ($do === 'gm_disconnect') {
        $busy = db()->prepare("SELECT COUNT(*) FROM orders WHERE merchant_id = ? AND pay_method = 'gmail' AND status = 'pending' AND (expires_at > ? OR utr IS NOT NULL)"); $busy->execute([$m['id'], now()]);
        if ((int)$busy->fetchColumn() > 0) { flash('err', 'You have payments in progress. Try again when they are finished.'); redirect(site_url('connect.php')); }
        db()->prepare('UPDATE merchants SET gm_ok = 0, gm_err = NULL, gm_pass = NULL, pay_mode = ? WHERE id = ?')->execute(['famapi', $m['id']]);
        log_activity((int)$m['id'], 'disconnect_gmail');
        flash('ok', 'Gmail disconnected.'); redirect(site_url('connect.php'));
    } elseif ($do === 'mode') {
        $to = ($_POST['mode'] ?? '') === 'gmail' ? 'gmail' : 'famapi';
        db()->prepare('UPDATE merchants SET pay_mode = ? WHERE id = ?')->execute([$to, $m['id']]);
        flash('ok', 'Payment method switched to ' . ($to === 'gmail' ? 'FamPay (Gmail)' : 'FamApi') . '.'); redirect(site_url('connect.php'));
    } elseif ($do === 'disconnect') {
        db()->prepare('UPDATE merchants SET famapi_connection_id = NULL, connected_at = NULL WHERE id = ?')->execute([$m['id']]); log_activity((int)$m['id'], 'disconnect_fampay');
        flash('ok', 'FAM merchant disconnected. Your API token is no longer live.');
        redirect(site_url('connect.php'));
    }
}
// coming back from the connect widget: look for a conn_... id in ANY returned parameter
if (isset($_GET['returned'])) {
    foreach ($_GET as $k => $val) {
        if (is_string($val) && preg_match($connRe, $val)) {
            db()->prepare('UPDATE merchants SET famapi_connection_id = ?, connected_at = ? WHERE id = ?')->execute([$val, now(), $m['id']]);
            log_activity((int)$m['id'], 'connect_fampay');
            flash('ok', 'FAM merchant connected. Your API token is now live!');
            echo '<script>try{if(window.top!==window.self){window.top.location=' . json_encode(site_url('dashboard.php')) . ';}else{location=' . json_encode(site_url('dashboard.php')) . ';}}catch(e){location=' . json_encode(site_url('dashboard.php')) . ';}</script>';
            exit;
        }
    }
}
$m = merchant_by_id((int)$m['id']);
$live = merchant_live($m);
page_header(t('connect'), 'connect');
?>
<script>if(window.top!==window.self&&location.search.indexOf('returned')>-1){window.top.location=location.href;}</script>
<h1><?= e(t('connect')) ?></h1>
<div class="provlogos">
  <div class="pl on"><img src="<?= e(site_url('assets/fampay.png')) ?>" alt="FamPay"><b>FamPay</b><span class="badge success">Available</span></div>
  <div class="pl off"><img src="<?= e(site_url('assets/phonepe.png')) ?>" alt="PhonePe"><b>PhonePe</b><span class="badge pending">Coming soon</span></div>
  <div class="pl off"><img src="<?= e(site_url('assets/paytm.png')) ?>" alt="Paytm"><b>Paytm</b><span class="badge pending">Coming soon</span></div>
</div>
<?php if ($err): ?><div class="alert err"><?= e($err) ?></div><?php endif; ?>
<?php if (isset($_GET['returned']) && !$live): ?>
  <div class="alert warn">You came back from the connect page but the connection ID was not received automatically. Paste it below.</div>
<?php endif; ?>

<?php if ($m['role'] === 'admin' && !$live): ?>
  <div class="alert info"><b>Admin:</b> API Wallet deposits go to the FamPay account connected here. If you leave this empty, deposits go to the account that owns your FamApi key.</div>
<?php endif; ?>

<div class="card">
  <h2><img src="<?= e(site_url('assets/fampay.png')) ?>" alt="" width="26" height="26" style="border-radius:7px;vertical-align:-6px"> Connect your FAM merchant</h2>
  <?php if (gm_ready($m)): ?>
    <span class="badge success">Connected</span> <?= gm_mode($m) ? '<span class="badge success">Active method</span>' : '' ?>
    <p>Gmail: <b><?= e($m['gm_email']) ?></b> · UPI ID: <b><?= e($m['gm_upi']) ?></b></p>
    <p class="muted small">Payers pay your UPI ID by QR / UPI app. The payment is confirmed automatically from your FamPay payment e-mail in Gmail (read-only). Your App Password is stored encrypted.</p>
    <div class="row">
    <?php if (!gm_mode($m)): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="mode"><input type="hidden" name="mode" value="gmail"><button class="btn sm">Use this method</button></form><?php endif; ?>
    <?php if (gm_mode($m) && trim((string)$m['famapi_connection_id']) !== ''): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="mode"><input type="hidden" name="mode" value="famapi"><button class="btn ghost sm">Switch to FamApi</button></form><?php endif; ?>
    <form method="post" onsubmit="return confirm('Disconnect Gmail?')"><?= csrf_field() ?><input type="hidden" name="do" value="gm_disconnect"><button class="btn ghost sm">Disconnect Gmail</button></form></div>
  <?php elseif (!gm_unlocked($m)): ?>
    <p class="muted">Collect payments straight into <b>your own FamPay account</b> — no platform fee on those orders. One-time unlock: <b><?= e(money(setting_num('gm_unlock_fee'))) ?></b> from your <?= e(t('wallet')) ?> (balance <?= e(money($m['wallet'])) ?>).</p>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="gm_unlock"><button class="btn">Unlock for <?= e(money(setting_num('gm_unlock_fee'))) ?></button></form>
  <?php else: ?>
    <?php if (!empty($m['gm_err'])): ?><div class="alert warn"><b>Needs attention:</b> <?= e($m['gm_err']) ?> New orders on this method are paused until you reconnect below.</div><?php endif; ?>
    <p class="muted">Enter your Gmail, its App Password and your FamPay UPI ID — right here, no outside page needed. Payments are confirmed from the FamPay e-mails in your Gmail (read-only, App Password stored encrypted).</p>
    <div class="step"><i>1</i><div>Google Account → Security → turn ON <b>2-Step Verification</b>, then create an <b>App password</b>. Gmail → Settings → Forwarding and POP/IMAP → <b>Enable IMAP</b>.</div></div>
    <div class="step"><i>2</i><div>FamPay must send payment e-mails to this Gmail address.</div></div>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="gm_save">
      <label>FamPay UPI ID<input name="gm_upi" placeholder="yourname@fam" required value="<?= e($_POST['gm_upi'] ?? '') ?>"></label>
      <label>Gmail address<input name="gm_email" type="email" placeholder="you@gmail.com" required value="<?= e($_POST['gm_email'] ?? '') ?>"></label>
      <label>Gmail App Password (16 letters)<input name="gm_pass" type="password" autocomplete="off" placeholder="abcd efgh ijkl mnop" required></label>
      <button class="btn">Connect FamPay (Gmail)</button></form>
  <?php endif; ?>
</div>
<?php if ($m['famapi_connection_id']): ?>
  <div class="card">
    <span class="badge success">Connected</span> <span class="badge success">API Live</span>
    <p>Connection ID: <code class="k"><?= e($m['famapi_connection_id']) ?></code></p>
    <p class="muted small">Connected on <?= e($m['connected_at']) ?>. All your orders are collected in this FAM merchant account.</p>
    <form method="post" onsubmit="return confirm('Disconnect? Your API token will stop working.')"><?= csrf_field() ?><input type="hidden" name="do" value="disconnect"><button class="btn ghost sm">Disconnect</button></form>
  </div>
<?php else: ?>
  <details class="card"<?= ($widget || $err) ? ' open' : '' ?>><summary><b>Advanced: connect through the FamApi hosted page instead</b></summary>
    <div class="step"><i>1</i><div>Tap <b>Connect FAM Merchant</b>, then open the FamApi page.</div></div>
    <div class="step"><i>2</i><div>Enter your FamPay login details there. They go straight to FamApi and never touch ORVIX PAY's server.</div></div>
    <div class="step"><i>3</i><div>Once connected your <b><?= e(t('api_token')) ?></b> goes live. Connecting is free.</div></div>
    <?php if (!$widget): ?>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="start"><button class="btn"><img src="<?= e(site_url('assets/fampay.png')) ?>" alt="" width="20" height="20" style="border-radius:5px;vertical-align:-5px;margin-right:6px">Connect FAM Merchant</button></form>
    <?php else: ?>
      <p class="small muted">Tap the button, fill the FamApi page, and you come back here automatically. If a connection ID (conn_…) is shown instead, paste it below.</p>
      <a class="btn" href="<?= e($widget) ?>">Open FamApi connect page</a>
      <form id="autosave" method="post" style="display:none"><?= csrf_field() ?><input type="hidden" name="do" value="save"><input type="hidden" name="connection_id" id="autoid"></form>
      <script>
      (function(){
        var origin; try { origin = new URL(<?= json_encode($widget) ?>).origin; } catch(e) { return; }
        window.addEventListener('message', function(ev){
          if (ev.origin !== origin) return;                     // only the FamApi widget itself
          var raw = typeof ev.data === 'string' ? ev.data : JSON.stringify(ev.data || {});
          var mt = raw.match(/conn_[A-Za-z0-9_\-]{3,100}/);
          if (mt) { document.getElementById('autoid').value = mt[0]; document.getElementById('autosave').submit(); }
        });
      })();
      </script>
    <?php endif; ?>
  </details>
  <div class="card">
    <h3>Already connected? Paste your Connection ID</h3>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="save">
      <div class="row"><label>Connection ID<input name="connection_id" placeholder="conn_xxxxxxxx"></label><button class="btn sm">Save &amp; go live</button></div>
    </form>
  </div>
<?php endif; ?>
<?php page_footer();
