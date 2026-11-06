<?php
/** Activity logs, admin popups/ads, Mystery Box. */

/* ----------------------------------------------------------- activity logs */
function log_activity(?int $mid, string $action, string $detail = ''): void {
    try {
        db()->prepare('INSERT INTO activity_logs (merchant_id, action, detail, ip, ua, created_at) VALUES (?,?,?,?,?,?)')
            ->execute([(int)$mid, mb_substr($action, 0, 60), mb_substr($detail, 0, 255), substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
                       mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 160), now()]);
    } catch (Throwable $ex) { error_log('ORVIX log_activity: ' . $ex->getMessage()); }
}

/* ------------------------------------------------------------------ popups */
function popup_for(array $m): ?array {
    try {
        $st = db()->prepare('SELECT * FROM popups WHERE active = 1 AND (starts_at IS NULL OR starts_at <= ?) AND (ends_at IS NULL OR ends_at >= ?) ORDER BY id DESC LIMIT 10');
        $st->execute([now(), now()]);
        foreach ($st->fetchAll() as $p) {
            $aud = $p['audience'];
            if ($aud === 'all' || ($aud === 'premium' && is_premium($m)) || ($aud === 'free' && !is_premium($m))) return $p;
        }
    } catch (Throwable $ex) { error_log('ORVIX popup_for: ' . $ex->getMessage()); }
    return null;
}
function valid_popup_link(string $u): bool {
    return $u === '#mystery' || valid_http_url($u) || (bool)preg_match('#^[A-Za-z0-9_\-/.]+\.php(\?[A-Za-z0-9_=&\-]*)?(\#[A-Za-z0-9_\-]*)?$#', $u);
}
function popup_html(array $p): string {
    $link = (string)($p['btn_url'] ?? '');
    $href = $link === '#mystery' ? '#' : (valid_http_url($link) ? $link : ($link !== '' ? site_url($link) : '#'));
    ob_start(); ?>
<div class="modal" id="adm"><div class="mcard">
  <button type="button" class="mx" id="adx" aria-label="Close">×</button>
  <h2 style="padding-right:36px"><?= e($p['title']) ?></h2>
  <?php if (trim((string)$p['body']) !== ''): ?><p class="muted"><?= nl2br(e($p['body'])) ?></p><?php endif; ?>
  <?php if (valid_http_url($p['image_url'] ?? '')): ?>
    <div class="adimg" id="adimg"><span class="spin"></span>Loading image…</div>
    <img src="<?= e($p['image_url']) ?>" alt="" id="adpic" style="display:none;width:100%;border-radius:12px;margin-bottom:12px">
  <?php endif; ?>
  <?php if (trim((string)$p['btn_text']) !== ''): ?><a class="btn block" id="adbtn" href="<?= e($href) ?>" <?= $link === '#mystery' ? '' : (valid_http_url($link) ? 'target="_blank" rel="noopener"' : '') ?>><?= e($p['btn_text']) ?></a><?php endif; ?>
</div></div>
<script>
(function(){
  var id = <?= (int)$p['id'] ?>, freq = <?= json_encode($p['frequency']) ?>, key = 'orvix_pop_' + id, show = true;
  try {
    var today = new Date().toISOString().slice(0, 10);
    if (freq === 'once' && localStorage.getItem(key)) show = false;
    if (freq === 'daily' && localStorage.getItem(key) === today) show = false;
    if (freq === 'session' && sessionStorage.getItem(key)) show = false;
  } catch (e) {}
  var el = document.getElementById('adm'); if (!show) return;
  function done(){ el.classList.remove('show'); try { var t = new Date().toISOString().slice(0, 10);
      if (freq === 'once') localStorage.setItem(key, '1'); if (freq === 'daily') localStorage.setItem(key, t); if (freq === 'session') sessionStorage.setItem(key, '1'); } catch (e) {} }
  document.getElementById('adx').onclick = done; el.addEventListener('click', function(ev){ if (ev.target === el) done(); });
  var pic = document.getElementById('adpic'), ph = document.getElementById('adimg');
  if (pic) { pic.onload = function(){ ph.style.display = 'none'; pic.style.display = 'block'; }; pic.onerror = function(){ ph.style.display = 'none'; }; if (pic.complete && pic.naturalWidth) pic.onload(); }
  var b = document.getElementById('adbtn');
  if (b) b.addEventListener('click', function(ev){ if (b.getAttribute('href') === '#') { ev.preventDefault(); done(); if (window.openMystery) window.openMystery(); } else done(); });
  setTimeout(function(){ el.classList.add('show'); }, 600);
})();
</script>
<?php return ob_get_clean();
}

/* ------------------------------------------------------------- mystery box */
function mb_config(): array {
    return ['enabled' => setting('mb_enabled') === '1', 'title' => setting('mb_title', 'Mystery Box') ?: 'Mystery Box',
            'ends' => trim(setting('mb_ends_at')), 'daily' => max(1, (int)setting_num('mb_daily_boxes'))];
}
function mb_is_open(): bool { $c = mb_config(); return $c['enabled'] && ($c['ends'] === '' || strtotime($c['ends']) > time()); }
function mb_default_rewards(): string {
    return "₹2 Wallet Cash|wallet|2|30\n₹5 Wallet Cash|wallet|5|10\n₹10 Wallet Cash|wallet|10|3\nBetter luck next time|none|0|57";
}
/** Lines: Label|type|value|weight  (type = wallet | plan | none). Invalid lines are skipped. */
function mb_rewards(?string $raw = null): array {
    $raw = $raw ?? (trim(setting('mb_rewards')) !== '' ? setting('mb_rewards') : mb_default_rewards());
    $out = [];
    foreach (preg_split('/\r?\n/', $raw) as $line) {
        $p = array_map('trim', explode('|', $line));
        if (count($p) < 4 || $p[0] === '' || !in_array($p[1], ['wallet', 'plan', 'none'], true)) continue;
        $w = (int)$p[3]; $v = (float)$p[2];
        if ($w < 1 || $v < 0 || $v > 10000) continue;
        $out[] = ['label' => mb_substr($p[0], 0, 120), 'type' => $p[1], 'value' => $v, 'weight' => $w];
    }
    return $out;
}
function mb_used_today(int $mid): int {
    $st = db()->prepare('SELECT COUNT(*) FROM mystery_opens WHERE merchant_id = ? AND day = ?'); $st->execute([$mid, date('Y-m-d')]);
    return (int)$st->fetchColumn();
}
function mb_left(int $mid): int { return max(0, mb_config()['daily'] - mb_used_today($mid)); }

/** Opens one box. Returns [ok, message, reward|null]. Admin budget = the rewards the admin configured. */
function mb_open(array $m): array {
    if (!mb_is_open()) return [false, 'The Mystery Box is not active right now.', null];
    $rewards = mb_rewards();
    if (!$rewards) return [false, 'No rewards are configured.', null];
    $used = mb_used_today((int)$m['id']);
    if ($used >= mb_config()['daily']) return [false, 'No boxes left today. Come back tomorrow!', null];

    $total = array_sum(array_column($rewards, 'weight')); $roll = random_int(1, $total); $pick = $rewards[0];
    foreach ($rewards as $r) { if ($roll <= $r['weight']) { $pick = $r; break; } $roll -= $r['weight']; }

    try {   // unique (merchant, day, slot) => two parallel requests cannot both take the same slot
        db()->prepare('INSERT INTO mystery_opens (merchant_id, day, slot, label, rtype, rvalue, opened_at) VALUES (?,?,?,?,?,?,?)')
            ->execute([$m['id'], date('Y-m-d'), $used + 1, $pick['label'], $pick['type'], $pick['value'], now()]);
    } catch (Throwable $ex) { return [false, 'Please try again.', null]; }

    try {
        if ($pick['type'] === 'wallet' && $pick['value'] > 0) wallet_add((int)$m['id'], round($pick['value'], 2), 'reward', 'Mystery Box: ' . $pick['label']);
        elseif ($pick['type'] === 'plan') subscribe_plan((int)$m['id'], (int)$pick['value'], 'reward');
    } catch (Throwable $ex) { error_log('ORVIX mystery reward: ' . $ex->getMessage()); }
    log_activity((int)$m['id'], 'mystery_box', $pick['label']);
    return [true, 'ok', $pick];
}

function mystery_widget(array $m): string {
    if (!mb_is_open()) return '';
    $c = mb_config(); $left = mb_left((int)$m['id']);
    $target = $c['ends'] !== '' ? strtotime($c['ends']) : strtotime('tomorrow');
    $labels = array_unique(array_column(mb_rewards(), 'label'));
    ob_start(); ?>
<div class="mbox <?= $left < 1 ? 'dim' : '' ?>" id="mbox" role="button" tabindex="0" aria-label="Mystery Box">
  <div class="cube">?</div><?php if ($left > 0): ?><span class="bdg" id="mbdg"><?= (int)$left ?></span><?php endif; ?>
  <div class="tpill" id="mtm">--</div>
</div>
<div class="modal" id="mmod"><div class="mcard c">
  <button type="button" class="mx" id="mxx" aria-label="Close">×</button>
  <div class="cube big" id="mcube">?</div>
  <h2><?= e($c['title']) ?></h2>
  <p class="muted" id="mtxt"><?= $left > 0 ? 'You have <b id="mleft">' . (int)$left . '</b> box(es) to open today.' : 'No boxes left today. Come back tomorrow!' ?></p>
  <button class="btn block" id="mopen" <?= $left < 1 ? 'disabled' : '' ?>>Open the box</button>
  <p class="small muted" style="margin-top:12px">Possible rewards: <?= e(implode(' · ', $labels)) ?></p>
</div></div>
<script>
(function(){
  var end = <?= (int)$target ?> * 1000, CSRF = <?= json_encode(csrf_token()) ?>;
  var box = document.getElementById('mbox'), mod = document.getElementById('mmod'), tm = document.getElementById('mtm');
  function tick(){ var s = Math.max(0, Math.floor((end - Date.now()) / 1000)), d = Math.floor(s / 86400), h = Math.floor(s % 86400 / 3600), m = Math.floor(s % 3600 / 60), x = s % 60;
    function p(n){ return (n < 10 ? '0' : '') + n; } tm.textContent = (d > 0 ? d + 'd ' : '') + p(h) + ':' + p(m) + ':' + p(x); }
  tick(); setInterval(tick, 1000);
  window.openMystery = function(){ mod.classList.add('show'); };
  box.onclick = window.openMystery; box.onkeydown = function(e){ if (e.key === 'Enter') window.openMystery(); };
  document.getElementById('mxx').onclick = function(){ mod.classList.remove('show'); };
  mod.addEventListener('click', function(e){ if (e.target === mod) mod.classList.remove('show'); });
  var btn = document.getElementById('mopen'), txt = document.getElementById('mtxt'), cube = document.getElementById('mcube');
  btn.onclick = function(){
    btn.disabled = true; cube.classList.add('shake'); txt.textContent = 'Opening…';
    fetch('mystery.php', {method: 'POST', headers: {'X-CSRF': CSRF}}).then(function(r){ return r.json(); }).then(function(d){
      cube.classList.remove('shake');
      if (!d.ok) { txt.textContent = d.error || 'Could not open.'; return; }
      cube.textContent = d.rtype === 'none' ? '🍀' : '🎉';
      txt.innerHTML = ''; var b = document.createElement('b'); b.style.fontSize = '20px'; b.textContent = d.label; txt.appendChild(b);
      var bd = document.getElementById('mbdg'); if (bd) { if (d.left > 0) bd.textContent = d.left; else { bd.remove(); box.classList.add('dim'); } }
      if (d.left > 0) { btn.disabled = false; btn.textContent = 'Open another (' + d.left + ' left)'; } else { btn.textContent = 'Come back tomorrow'; }
    }).catch(function(){ cube.classList.remove('shake'); txt.textContent = 'Network problem. Try again.'; btn.disabled = false; });
  };
})();
</script>
<?php return ob_get_clean();
}


/* ------------------------------------------- pay-link delivery (file / link / note after payment) */
const ORVIX_FILE_DIR = __DIR__ . '/../private_files';
function paylink_for_order(array $o): ?array {
    if (!preg_match('/^paylink:([a-z0-9]{4,16})$/', (string)($o['merchant_ref'] ?? ''), $mm)) return null;
    $st = db()->prepare('SELECT * FROM paylinks WHERE code = ? AND merchant_id = ?'); $st->execute([$mm[1], $o['merchant_id']]);
    return $st->fetch() ?: null;
}
/** Reads deliver_url / deliver_text / file from $_POST+$_FILES. Returns [data, error]. $old = existing row (keeps its file). */
function delivery_from_post(?array $old = null): array {
    $url = trim($_POST['deliver_url'] ?? ''); $txt = mb_substr(trim($_POST['deliver_text'] ?? ''), 0, 2000);
    if ($url !== '' && !valid_http_url($url)) return [null, 'Delivery link must be an http(s) URL.'];
    $fn = $old['file_name'] ?? null; $fp = $old['file_path'] ?? null;
    if (!empty($_POST['remove_file']) && $fp) { @unlink(ORVIX_FILE_DIR . '/' . $fp); $fn = $fp = null; }
    $f = $_FILES['deliver_file'] ?? null;
    if ($f && $f['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) return [null, 'File upload failed (max 5 MB).'];
        if ($f['size'] > 5 * 1024 * 1024) return [null, 'File is too big (max 5 MB). Put a download link instead.'];
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['pdf', 'zip', 'txt', 'png', 'jpg', 'jpeg', 'mp3', 'mp4', 'doc', 'docx', 'xls', 'xlsx', 'apk'], true)) return [null, 'File type not allowed. Allowed: pdf, zip, txt, png, jpg, mp3, mp4, doc(x), xls(x), apk.'];
        if (!is_dir(ORVIX_FILE_DIR)) @mkdir(ORVIX_FILE_DIR, 0755, true);
        @file_put_contents(ORVIX_FILE_DIR . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        $name = bin2hex(random_bytes(12)) . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], ORVIX_FILE_DIR . '/' . $name)) return [null, 'Could not save the file on the server.'];
        if ($fp) @unlink(ORVIX_FILE_DIR . '/' . $fp);
        $fn = mb_substr(preg_replace('/[^A-Za-z0-9._ \-]/', '_', basename($f['name'])), 0, 120); $fp = $name;
    }
    return [['url' => $url ?: null, 'text' => $txt ?: null, 'fname' => $fn, 'fpath' => $fp], null];
}
function delivery_fields_html(?array $r = null): string {
    ob_start(); ?>
  <label>After payment: download link (optional, https)<input name="deliver_url" value="<?= e($r['deliver_url'] ?? '') ?>" placeholder="https://drive.google.com/…"></label>
  <label>After payment: message / code / instructions (optional)<textarea name="deliver_text" style="min-height:70px"><?= e($r['deliver_text'] ?? '') ?></textarea></label>
  <label>After payment: file (optional, max 5 MB)<input type="file" name="deliver_file">
    <?php if (!empty($r['file_name'])): ?><span class="small muted">Current: <?= e($r['file_name']) ?></span><label style="display:flex;gap:8px;align-items:center;margin:4px 0 0"><input type="checkbox" name="remove_file" value="1" style="width:auto;margin:0"> Remove file</label><?php endif; ?></label>
<?php return ob_get_clean();
}
/** Shown on the cashier success page. Only called when the order status is success (set by server-side verification). */
function delivery_html(array $o): string {
    $pl = paylink_for_order($o); if (!$pl) return '';
    $has = $pl['deliver_url'] || $pl['deliver_text'] || $pl['file_path']; if (!$has) return '';
    $h = '<div class="alert ok" style="margin-top:12px"><b>🎁 Your purchase</b>';
    if ($pl['deliver_text']) $h .= '<p style="margin:8px 0;white-space:pre-wrap;word-break:break-word">' . e($pl['deliver_text']) . '</p>';
    $h .= '</div>';
    if ($pl['deliver_url'] && valid_http_url($pl['deliver_url'])) $h .= '<a class="btn block" style="margin-bottom:8px" target="_blank" rel="noopener" href="' . e($pl['deliver_url']) . '">Open link</a>';
    if ($pl['file_path']) $h .= '<a class="btn block" href="' . e(site_url('download.php?order_id=' . urlencode($o['order_id']))) . '">Download ' . e($pl['file_name'] ?: 'file') . '</a>';
    return $h;
}
