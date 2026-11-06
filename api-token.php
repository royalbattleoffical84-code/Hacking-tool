<?php
require_once __DIR__ . '/includes/bootstrap.php';
$m = require_live();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    db()->prepare('UPDATE merchants SET api_key = ? WHERE id = ?')->execute([new_api_key(), $m['id']]); log_activity((int)$m['id'], 'token_regenerate');
    flash('ok', 'New ' . t('api_token') . ' generated. The old one no longer works.');
    redirect(site_url('api-token.php'));
}
page_header(t('api_token'), 'api');
?>
<h1><?= e(t('api_token')) ?></h1>
<div class="card">
  <p><span class="badge success">Live</span> <span class="muted small">Your token works because your FAM merchant is connected.</span></p>
  <p class="muted">Keep this token on your server only — never inside an app, website JavaScript or a public repo.</p>
  <p><code class="k" id="tk"><?= e($m['api_key']) ?></code> <button class="btn ghost sm" onclick="navigator.clipboard.writeText(document.getElementById('tk').innerText);this.innerText='Copied ✔'">Copy</button></p>
  <form method="post" onsubmit="return confirm('The old token will stop working. Continue?')"><?= csrf_field() ?><button class="btn red sm">Regenerate</button></form>
</div>
<div class="card"><h3>Endpoints</h3>
  <p><code class="k">POST <?= e(site_url('api/create-order')) ?></code></p>
  <p><code class="k">POST <?= e(site_url('api/order-status')) ?></code></p>
  <a class="btn sm" href="<?= e(site_url('docs.php')) ?>"><?= e(t('docs')) ?></a> <a class="btn ghost sm" href="<?= e(site_url('ai.php')) ?>">Ask the AI to integrate</a></div>
<?php page_footer();
