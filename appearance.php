<?php
require_once __DIR__ . '/includes/bootstrap.php';
$m = require_live();
$themes = orvix_themes(); $prem = is_premium($m);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $tp = $_POST['theme_pay'] ?? ''; $tn = $_POST['theme_panel'] ?? '';
    $bad = false;
    foreach ([$tp, $tn] as $k) if (!isset($themes[$k]) || ($themes[$k][1] && !$prem)) $bad = true;
    if ($bad) flash('err', 'Premium themes need the Premium plan.');
    else { db()->prepare('UPDATE merchants SET theme_pay = ?, theme_panel = ? WHERE id = ?')->execute([$tp, $tn, $m['id']]); log_activity((int)$m['id'], 'theme_change', $tp . '/' . $tn); flash('ok', 'Themes saved.'); }
    redirect(site_url('appearance.php'));
}
function theme_picker(string $name, string $current, array $themes, bool $prem): void {
    echo '<div class="tgrid">';
    foreach ($themes as $k => $t) {
        $locked = $t[1] && !$prem;
        echo '<label class="tcard" ' . ($locked ? 'style="opacity:.55"' : '') . '><input type="radio" name="' . e($name) . '" value="' . e($k) . '" ' . ($current === $k ? 'checked' : '') . ($locked ? ' disabled' : '') . '>';
        echo '<div class="sw"><i style="background:' . e($t[2]) . '"></i><i style="background:' . e($t[3]) . '"></i><i style="background:' . e($t[8]) . '"></i><i style="background:' . e($t[9]) . '"></i></div>';
        echo '<b>' . e($t[0]) . '</b>' . ($t[1] ? '<span class="pr">👑 Premium</span>' : '') . '</label>';
    }
    echo '</div>';
}
page_header(t('themes'), 'themes');
?>
<h1><?= e(t('themes')) ?></h1>
<?php if (!$prem): ?><div class="alert info">6 premium themes are locked. <a href="<?= e(site_url('premium.php')) ?>"><b>Get Premium</b></a> to use all 12.</div><?php endif; ?>
<form method="post"><?= csrf_field() ?>
  <div class="card"><h2>Payment page (<?= e(t('cashier')) ?>) theme</h2>
    <p class="muted small">What your customers see when they pay. <a href="<?= e(site_url('preview.php')) ?>" target="_blank">Preview</a></p>
    <?php theme_picker('theme_pay', resolve_theme($m['theme_pay'], $prem), $themes, $prem); ?></div>
  <div class="card"><h2>Panel (gateway) theme</h2>
    <p class="muted small">The look of this merchant panel — only you see it.</p>
    <?php theme_picker('theme_panel', resolve_theme($m['theme_panel'], $prem), $themes, $prem); ?></div>
  <button class="btn">Save themes</button>
</form>
<?php page_footer();
