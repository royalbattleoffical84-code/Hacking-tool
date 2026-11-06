<?php
require_once __DIR__ . '/includes/bootstrap.php';
if (current_merchant()) redirect(site_url('dashboard.php'));
page_header('UPI Payment Gateway');
?>
<section class="hero">
  <img src="<?= e(brand_logo()) ?>" alt="<?= e(brand_name()) ?>">
  <h1>India's UPI Payment Gateway<br>powered by your FamPay</h1>
  <p>Connect your FAM merchant, get your API token and start accepting UPI payments on your website or app. 12 themes, API Wallet, Refer &amp; Earn and an AI assistant to help you integrate.</p>
  <a class="btn" href="<?= e(site_url('register.php')) ?>"><?= e(t('register')) ?> as <?= e(t('merchant')) ?></a>
  <a class="btn ghost" href="<?= e(site_url('login.php')) ?>"><?= e(t('login')) ?></a>
</section>
<section class="feat">
  <div class="card"><div class="ic">🔗</div><h3><?= e(t('connect')) ?></h3><p class="muted">Connect your FAM merchant once. Your API token goes live the moment it is connected. Free.</p></div>
  <div class="card"><div class="ic">⚡</div><h3>Verified payments</h3><p class="muted">Every payment is verified server-side. A redirect alone is never treated as proof of payment.</p></div>
  <div class="card"><div class="ic">👛</div><h3><?= e(t('wallet')) ?></h3><p class="muted">Deposit once, pay a tiny fee per successful order. Premium merchants get lower fees and exclusive themes.</p></div>
  <div class="card"><div class="ic">🎨</div><h3>12 Themes</h3><p class="muted">Pick your own <?= e(t('cashier')) ?> page theme and panel theme.</p></div>
  <div class="card"><div class="ic">🤖</div><h3><?= e(t('ai')) ?></h3><p class="muted">Tell the AI your stack and get ready-to-paste integration code.</p></div>
  <div class="card"><div class="ic">🎁</div><h3><?= e(t('refer')) ?></h3><p class="muted">Invite other merchants and earn a share of their fees.</p></div>
</section>
<?php page_footer();
