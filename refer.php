<?php
require_once __DIR__ . '/includes/bootstrap.php';
$m = require_live();
$code = ensure_ref_code($m);
$cnt = db()->prepare('SELECT COUNT(*) FROM merchants WHERE referred_by = ?'); $cnt->execute([$m['id']]);
$earn = db()->prepare('SELECT COALESCE(SUM(amount),0) FROM wallet_txns WHERE merchant_id = ? AND type = "referral"'); $earn->execute([$m['id']]);
$link = site_url('register.php?ref=' . $code);
page_header(t('refer'), 'refer');
?>
<h1>🎁 <?= e(t('refer')) ?></h1>
<div class="grid g3">
  <div class="card stat"><div class="v"><?= (int)$cnt->fetchColumn() ?></div><div class="l">Merchants referred</div></div>
  <div class="card stat"><div class="v"><?= money($earn->fetchColumn()) ?></div><div class="l">Total earned</div></div>
  <div class="card stat"><div class="v"><?= e(rtrim(rtrim(number_format(setting_num('ref_percent'), 2), '0'), '.')) ?>%</div><div class="l">Share of their fees</div></div>
</div>
<div class="card"><h3>Your referral link</h3>
  <p><code class="k" id="rl"><?= e($link) ?></code> <button class="btn ghost sm" onclick="navigator.clipboard.writeText(document.getElementById('rl').innerText);this.innerText='Copied ✔'">Copy</button></p>
  <p class="muted small">You earn <?= e(rtrim(rtrim(number_format(setting_num('ref_percent'), 2), '0'), '.')) ?>% of the fee on every successful order of merchants who register with your link. Credited instantly to your <?= e(t('wallet')) ?>.</p></div>
<?php page_footer();
