<?php
/** Page layout: public top-nav pages, or app layout with ZapUPI-style sidebar for logged-in merchants. */

function status_badge(string $s): string { return '<span class="badge ' . e($s) . '">' . e(ucfirst($s)) . '</span>'; }
function money($n): string { return '₹' . number_format((float)$n, 2); }

/** ZapUPI-style grouped menu. Item: [key, label, file, locked-until-connected]. Group: [icon, label, [items]]. */
function admin_menu(): array {
    return [
        ['fa-gauge-high', 'Overview', 'admin.php?tab=overview', 'admin:overview', false, null],
        ['fa-users', 'Merchants', 'admin.php?tab=merchants', 'admin:merchants', false, null],
        ['fa-receipt', 'Orders', 'admin.php?tab=orders', 'admin:orders', false, null],
        ['fa-crown', 'Plans', 'admin.php?tab=plans', 'admin:plans', false, null],
        ['fa-wallet', 'Wallet & Rates', 'admin.php?tab=rates', 'admin:rates', false, null],
        ['fa-plug', 'Providers', 'admin.php?tab=providers', 'admin:providers', false, null],
        ['fa-id-badge', 'Branding', 'admin.php?tab=branding', 'admin:branding', false, null],
        ['fa-rectangle-ad', 'Popups & Ticker', 'admin.php?tab=popups', 'admin:popups', false, null],
        ['fa-gift', 'Mystery Box', 'admin.php?tab=mystery', 'admin:mystery', false, null],
        ['fa-robot', 'AI Assistant', 'admin.php?tab=ai', 'admin:ai', false, null],
        ['fa-link', 'Pay by Link', 'paylinks.php', 'paylinks', false, null],
        ['fa-qrcode', 'Static Paylink', 'static-paylink.php', 'static', false, null],
        ['fa-clipboard-list', 'Activity Logs', 'activity.php', 'activity', false, null],
        ['fa-right-left', 'Merchant Panel', 'dashboard.php', 'dashboard', false, null],
    ];
}
function sidebar_menu(array $m, string $active = ''): array {
    if ($m['role'] === 'admin' && str_starts_with($active, 'admin')) return admin_menu();
    $menu = [
        ['fa-gauge-high', 'Dashboard', 'dashboard.php', 'dashboard', true, null],
        ['fa-wallet', 'API Wallet', null, null, true, [
            ['wallet', 'Add Money', 'wallet.php', true], ['wallethist', 'Wallet History', 'wallet-history.php', true]]],
        ['fa-link', 'Pay by Link', null, null, true, [
            ['paylinks', 'Create Link', 'paylinks.php', true], ['linktx', 'Link Payments', 'transactions.php?src=link', true]]],
        ['fa-qrcode', 'Static Paylink', 'static-paylink.php', 'static', true, null],
        ['fa-cart-shopping', 'Cashier Settings', null, null, true, [
            ['themes', 'Cashier Theme', 'appearance.php', true], ['settings', 'Business Profile', 'settings.php', false]]],
        ['fa-clock-rotate-left', 'Transaction History', null, null, true, [
            ['transactions', 'All Transactions', 'transactions.php', true], ['test', 'Test Payment', 'create-order.php', true]]],
        ['fa-code', 'Developers', null, null, false, [
            ['api', 'API Token', 'api-token.php', true], ['docs', 'API Docs', 'docs.php', false], ['ai', 'AI Assistant', 'ai.php', false]]],
        ['fa-mobile-screen-button', 'Recharge Portal', null, null, true, [
            ['recharge', 'Mobile Recharge', 'recharge.php', true]]],
        ['fa-gears', 'System Settings', null, null, false, [
            ['connect', 'Connect FamPay', 'connect.php', false], ['account', 'Account Settings', 'settings.php#account', false]]],
        ['fa-user-group', 'Referral Income', 'refer.php', 'refer', true, null],
        ['fa-palette', 'Customize Page', 'appearance.php', 'themes2', true, null],
        ['fa-crown', 'Subscription', 'premium.php', 'premium', true, null],
        ['fa-clipboard-list', 'Activity Logs', 'activity.php', 'activity', false, null],
    ];
    if ($m['role'] === 'admin') $menu[] = ['fa-shield-halved', 'Admin Panel', 'admin.php', 'admin', false, null];
    return $menu;
}

function page_header(string $title, string $active = '', bool $bare = false, ?string $themeKey = null): void {
    $m = current_merchant();
    if ($themeKey === null) $themeKey = $m ? resolve_theme($m['theme_panel'] ?? 'orvix', is_premium($m)) : 'orvix';
    ?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($title) ?> · <?= e(brand_name()) ?></title>
<link rel="icon" href="<?= e(site_url('assets/favicon.png')) ?>">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?= e(site_url('assets/style.css')) ?>?v=3">
<style><?= theme_css($themeKey) ?></style>
</head><body>
<?php if ($m && !$bare): $live = merchant_live($m); ?>
<input type="checkbox" id="sb" class="sbt">
<div class="app">
  <aside class="side">
    <a class="brand" href="<?= e(site_url('dashboard.php')) ?>"><img src="<?= e(brand_logo()) ?>" alt="" width="36" height="36"><span><?= e(brand_name()) ?></span></a>
    <nav>
    <?php foreach (sidebar_menu($m, $active) as [$ic, $label, $file, $key, $needLive, $kids]):
        if ($kids === null):
            $locked = $needLive && !$live; ?>
      <a class="mi <?= $active === $key ? 'on' : '' ?>" href="<?= e(site_url($locked ? 'connect.php' : $file)) ?>"><i class="fa-solid <?= $ic ?>"></i><span><?= e($label) ?></span><?= $locked ? '<i class="fa-solid fa-lock lk"></i>' : '' ?></a>
    <?php else:
            $open = false; foreach ($kids as $k) if ($k[0] === $active) $open = true; ?>
      <details class="grp" <?= $open ? 'open' : '' ?>><summary class="mi"><i class="fa-solid <?= $ic ?>"></i><span><?= e($label) ?></span><i class="fa-solid fa-chevron-down car"></i></summary>
        <?php foreach ($kids as [$ck, $cl, $cf, $cLive]): $locked = $cLive && !$live; ?>
        <a class="sub <?= $active === $ck ? 'on' : '' ?>" href="<?= e(site_url($locked ? 'connect.php' : $cf)) ?>"><?= e($cl) ?><?= $locked ? ' <i class="fa-solid fa-lock lk"></i>' : '' ?></a>
        <?php endforeach; ?>
      </details>
    <?php endif; endforeach; ?>
      <a class="mi" href="<?= e(site_url('logout.php')) ?>"><i class="fa-solid fa-right-from-bracket"></i><span>Logout</span></a>
    </nav>
    <div class="sfoot">Build by SHIVAM KASHYAP 👑</div>
  </aside>
  <label for="sb" class="ov"></label>
  <div class="content">
    <div class="topbar"><label for="sb" class="burger"><i class="fa-solid fa-bars"></i></label>
      <span class="tt"><?= e(brand_name()) ?> Gateway</span>
      <span class="chip"><i class="fa-solid fa-wallet"></i> <?= money($m['wallet'] ?? 0) ?></span>
      <a class="av" href="<?= e(site_url('settings.php')) ?>" title="<?= e($m['name']) ?>"><img src="<?= e(!empty($m['logo_url']) && valid_http_url($m['logo_url']) ? $m['logo_url'] : brand_logo()) ?>" alt=""></a></div>
    <main class="wrap">
<?php elseif (!$bare): ?>
<header class="nav"><div class="wrap nav-in">
  <a class="brand" href="<?= e(site_url()) ?>"><img src="<?= e(brand_logo()) ?>" alt="" width="34" height="34"><span><?= e(brand_name()) ?></span></a>
  <input type="checkbox" id="nt" class="nt"><label for="nt" class="burger">☰</label>
  <nav class="links">
    <a href="<?= e(site_url('docs.php')) ?>"><?= e(t('docs')) ?></a>
    <a href="<?= e(site_url('login.php')) ?>"><?= e(t('login')) ?></a>
    <a class="btn sm" href="<?= e(site_url('register.php')) ?>"><?= e(t('register')) ?></a>
  </nav>
</div></header>
<main class="wrap pub">
<?php else: ?>
<main class="wrap">
<?php endif;
    if (!empty($_SESSION['flash'])) {
        foreach ($_SESSION['flash'] as [$type, $msg]) echo '<div class="alert ' . e($type) . '">' . e($msg) . '</div>';
        unset($_SESSION['flash']);
    }
}

function page_footer(bool $bare = false): void {
    $m = current_merchant();
    echo '</main>';
    if ($m && !$bare) { echo '</div></div>'; if (merchant_live($m)) echo mystery_widget($m); }
    elseif (!$bare) echo '<footer class="foot"><div class="wrap">© ' . date('Y') . ' ' . e(brand_name()) . ' · Fast UPI payments with FamPay</div></footer>';
    echo '</body></html>';
}
