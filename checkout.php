<?php
/**
 * Cashier page. $_GET is only an order id — never proof of payment.
 * Each load first verifies server-side with FamApi; only then is a result shown (Rule 1).
 * The payee UPI ID is NOT displayed (QR / Pay Now button only).
 */
require_once __DIR__ . '/includes/bootstrap.php';
$id = (string)($_GET['order_id'] ?? '');
$o  = preg_match('/^ORX[0-9A-Z]{6,30}$/', $id) ? get_order($id) : null;
if (!$o) { http_response_code(404); page_header('Order', '', true); echo '<div class="cash card c"><div class="big-ic">❓</div><h2>Order not found</h2><p class="muted">Please check the link.</p></div>'; page_footer(true); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['cancel'])) {
    csrf_check();
    $v0 = verify_order($o);                       // never cancel an order that was actually paid
    if ($v0['order']['status'] === 'pending' && !$v0['error']) change_order_status($v0['order'], 'failed');
    redirect(site_url('checkout.php?order_id=' . urlencode($o['order_id'])));
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['utr'])) {
    csrf_check();
    [$uok, $umsg] = gm_submit_utr($o, (string)$_POST['utr']);
    flash($uok ? 'ok' : 'err', $umsg);
    redirect(site_url('checkout.php?order_id=' . urlencode($o['order_id'])));
}
$v   = verify_order($o);
$o   = $v['order'];
$err = $v['error'];
$st  = $o['status'];
$left = max(0, strtotime($o['expires_at']) - time());

$deposit = ($o['kind'] ?? 'payment') === 'deposit';
$gm = ($o['pay_method'] ?? 'famapi') === 'gmail';
$utrMode = $gm && ($o['gm_verify'] ?? 'utr') !== 'auto';
$utrForm = function () { ob_start(); ?>
<form method="post" style="margin-top:12px"><?= csrf_field() ?><label>Paid? Enter your UTR / Transaction ID<input name="utr" maxlength="60" placeholder="e.g. 412345678901" required autocomplete="off"></label><button class="btn block">Verify my payment</button><p class="small muted">Find it in your UPI app under the payment details. Only the real payment for this exact amount is accepted.</p></form>
<?php return ob_get_clean(); };
$prem    = !$deposit && is_premium($o);
$theme   = $deposit ? 'orvix' : resolve_theme($o['theme_pay'] ?? 'orvix', $prem);
$brand   = ($prem && $o['business_name']) ? $o['business_name'] : ($deposit ? 'API Wallet Deposit' : $o['merchant_name']);
$logo    = ($prem && valid_http_url($o['logo_url'] ?? '')) ? $o['logo_url'] : brand_logo();

$pq  = 'pa=' . rawurlencode($o['upi_id']) . '&pn=' . rawurlencode($brand) . '&am=' . number_format((float)$o['amount'], 2, '.', '') . '&cu=INR&tn=' . rawurlencode($o['order_id']);
$upi = 'upi://pay?' . $pq;
$back = $o['redirect_url'] ? $o['redirect_url'] . (strpos($o['redirect_url'], '?') === false ? '?' : '&') . 'order_id=' . urlencode($o['order_id']) : null;

page_header(t('cashier'), '', true, $theme);
?>
<div class="cash card">
  <div class="head">
    <img src="<?= e($logo) ?>" width="46" height="46" alt="" style="border-radius:10px;object-fit:cover">
    <div class="muted small"><?= e(t('cashier')) ?> · <?= e($brand) ?></div>
    <div class="amt"><?= money($o['amount']) ?></div>
    <div class="muted small"><?= e(t('order_id')) ?>: <?= e($o['order_id']) ?></div>
  </div>

<?php if ($st === 'success'): ?>
  <div class="ov-anim ok"><svg viewBox="0 0 120 120" width="112" height="112"><circle class="ring" cx="60" cy="60" r="52"/><path class="tick" d="M36 62l16 16 32-34"/></svg><i class="pulse"></i></div>
  <h2 class="c ov-h">Payment Successful</h2>
  <div class="c ov-amt"><?= money($o['amount']) ?> <span class="muted small">paid</span></div>
  <p class="c muted small">Your payment has been verified.</p>
  <?= delivery_html($o) ?>
  <?php if ($back): ?><a class="btn block" id="ovGo" href="<?= e($back) ?>">Continue</a><p class="c small muted" id="ovCd"></p><?php endif; ?>
  <canvas id="confetti" style="position:fixed;inset:0;width:100%;height:100%;pointer-events:none;z-index:50"></canvas>
<?php elseif (in_array($st, ['failed', 'expired', 'refunded'], true)): ?>
  <div class="ov-anim <?= $st === 'refunded' ? 'rf' : 'bad' ?>"><svg viewBox="0 0 120 120" width="112" height="112"><circle class="ring" cx="60" cy="60" r="52"/><path class="tick" d="<?= $st === 'expired' ? 'M60 32v30l20 12' : ($st === 'refunded' ? 'M78 48H50l10-10M50 48l10 10M42 76h28a14 14 0 0 0 0-28' : 'M42 42l36 36M78 42L42 78') ?>"/></svg><i class="pulse"></i></div>
  <h2 class="c ov-h"><?= $st === 'failed' ? 'Order Cancelled' : ($st === 'expired' ? 'Order Expired' : 'Payment Refunded') ?></h2>
  <div class="c ov-amt"><?= money($o['amount']) ?></div>
  <p class="c muted"><?= $st === 'expired' ? 'This order has expired. Please create a new order.' : ($st === 'refunded' ? 'The amount has been refunded.' : 'The payment was cancelled or not completed.') ?></p>
  <?php if ($gm && $st === 'expired'): ?>
    <?php if (!empty($o['utr']) && empty($o['reject_reason'])): ?><p class="c small"><span class="spin"></span>Verifying UTR <b><?= e($o['utr']) ?></b>…</p><script>setTimeout(function(){location.reload()},8000)</script>
    <?php elseif ($utrMode): ?><?php if (!empty($o['reject_reason'])): ?><div class="alert err small"><?= e(GM_REVIEW_MSG[$o['reject_reason']] ?? 'Payment could not be verified.') ?></div><?php endif; ?><?= $utrForm() ?>
    <?php else: ?><p class="c small muted">Paid already? It is checked automatically for a short time — refresh in a minute. Otherwise contact the merchant.</p><script>setTimeout(function(){location.reload()},15000)</script><?php endif; ?>
  <?php endif; ?>
  <?php if ($back): ?><a class="btn ghost block" href="<?= e($back) ?>">Back</a><?php endif; ?>
<?php else: ?>
  <h2 class="c"><?= e(t('scan_pay')) ?></h2>
  <div class="qr" id="qr"></div>
  <p class="c muted small">Scan with any UPI app, or tap a button below</p>
  <a class="btn block" href="<?= e($upi) ?>"><?= e(t('pay_now')) ?></a>
  <div class="apps">
    <a class="btn ghost sm" href="<?= e('phonepe://pay?' . $pq) ?>">PhonePe</a>
    <a class="btn ghost sm" href="<?= e('tez://upi/pay?' . $pq) ?>">Google Pay</a>
    <a class="btn ghost sm" href="<?= e('paytmmp://pay?' . $pq) ?>">Paytm</a>
    <a class="btn ghost sm" href="<?= e($upi) ?>">Other UPI app</a>
  </div>
  <p class="c small" style="margin-top:12px">⏳ Expires in <span class="timer" id="tm"></span></p>
  <form method="post" class="c" style="margin:6px 0" onsubmit="return confirm('Cancel this payment?')"><?= csrf_field() ?><input type="hidden" name="cancel" value="1"><button class="btn ghost sm" style="color:var(--err)">Cancel order</button></form>
  <p class="c small muted" id="msg"><span class="spin"></span>Waiting for your payment… please keep this page open.</p>
  <?php if ($err): ?><div class="alert warn small"><?= e($err) ?></div><?php endif; ?>
  <div class="alert info small">Pay the exact amount <b><?= money($o['amount']) ?></b>, otherwise the payment cannot be verified.</div>
  <?php if ($gm): ?>
    <?php if (!empty($o['reject_reason'])): ?><div class="alert err small"><?= e(GM_REVIEW_MSG[$o['reject_reason']] ?? 'Payment could not be verified.') ?></div><?php endif; ?>
    <?php if (!empty($o['utr']) && empty($o['reject_reason'])): ?><p class="c small"><span class="spin"></span>Verifying UTR <b><?= e($o['utr']) ?></b>…</p>
    <?php elseif ($utrMode || !empty($o['reject_reason'])): ?><?= $utrForm() ?><?php endif; ?>
  <?php endif; ?>
<?php endif; ?>
<?php if (!$prem): ?><div class="pwr">Powered by <b><?= e(brand_name()) ?></b></div><?php endif; ?>
</div>

<style>
.ov-anim{position:relative;width:112px;height:112px;margin:6px auto 10px}
.ov-anim svg{position:relative;z-index:2;overflow:visible}
.ov-anim .ring{fill:none;stroke-width:7;stroke-linecap:round;stroke-dasharray:327;stroke-dashoffset:327;transform:rotate(-90deg);transform-origin:60px 60px;animation:ovDraw .7s ease-out .1s forwards}
.ov-anim .tick{fill:none;stroke-width:8;stroke-linecap:round;stroke-linejoin:round;stroke-dasharray:110;stroke-dashoffset:110;animation:ovDraw .45s ease-out .75s forwards}
.ov-anim.ok .ring,.ov-anim.ok .tick{stroke:#16a34a}.ov-anim.bad .ring,.ov-anim.bad .tick{stroke:#dc2626}.ov-anim.rf .ring,.ov-anim.rf .tick{stroke:#f59e0b}
.ov-anim .pulse{position:absolute;inset:0;border-radius:50%;z-index:1;animation:ovPulse 1.6s ease-out .8s 2}
.ov-anim.ok .pulse{background:rgba(22,163,74,.25)}.ov-anim.bad .pulse{background:rgba(220,38,38,.22)}.ov-anim.rf .pulse{background:rgba(245,158,11,.25)}
.ov-anim.ok{animation:ovPop .5s cubic-bezier(.2,1.4,.4,1) both}.ov-anim.bad{animation:ovShake .55s ease .85s both}
.ov-h{animation:ovUp .5s ease .9s both}.ov-amt{font-size:26px;font-weight:800;animation:ovUp .5s ease 1s both}
@keyframes ovDraw{to{stroke-dashoffset:0}}@keyframes ovPulse{0%{transform:scale(.8);opacity:.9}100%{transform:scale(1.6);opacity:0}}
@keyframes ovPop{0%{transform:scale(.4);opacity:0}100%{transform:scale(1);opacity:1}}
@keyframes ovShake{0%,100%{transform:translateX(0)}20%{transform:translateX(-8px)}40%{transform:translateX(8px)}60%{transform:translateX(-5px)}80%{transform:translateX(5px)}}
@keyframes ovUp{0%{transform:translateY(12px);opacity:0}100%{transform:translateY(0);opacity:1}}
@media (prefers-reduced-motion:reduce){.ov-anim,.ov-anim *,.ov-h,.ov-amt{animation:none!important;stroke-dashoffset:0!important}}
</style>
<?php if ($st === 'success'): ?>
<script>
(function(){
  var c=document.getElementById('confetti'); if(c && !matchMedia('(prefers-reduced-motion:reduce)').matches){
    var x=c.getContext('2d'),W=c.width=innerWidth,H=c.height=innerHeight,cols=['#16a34a','#2563eb','#f59e0b','#ec4899','#8b5cf6','#06b6d4'],p=[];
    for(var i=0;i<110;i++)p.push({x:W/2,y:H*.35,vx:(Math.random()-.5)*14,vy:-Math.random()*13-3,s:4+Math.random()*6,r:Math.random()*6,vr:(Math.random()-.5)*.4,c:cols[i%6]});
    var t0=Date.now();(function f(){x.clearRect(0,0,W,H);var a=false;p.forEach(function(q){q.vy+=.35;q.x+=q.vx;q.y+=q.vy;q.vx*=.99;q.r+=q.vr;if(q.y<H+20)a=true;x.save();x.translate(q.x,q.y);x.rotate(q.r);x.fillStyle=q.c;x.fillRect(-q.s/2,-q.s/4,q.s,q.s/2);x.restore();});
      if(a&&Date.now()-t0<4500)requestAnimationFrame(f);else x.clearRect(0,0,W,H);})();
    if(navigator.vibrate)try{navigator.vibrate([40,40,80])}catch(e){}
  }
  var go=document.getElementById('ovGo'),cd=document.getElementById('ovCd');
  if(go&&cd){var n=8;(function k(){cd.textContent='Redirecting in '+n+'s…';if(n--<=0){location.href=go.href;return}setTimeout(k,1000)})();}
})();
</script>
<?php endif; ?>
<?php if ($st === 'pending'): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
(function(){
  var UPI = <?= json_encode($upi) ?>, ID = <?= json_encode($o['order_id']) ?>, left = <?= (int)$left ?>;
  try { new QRCode(document.getElementById('qr'), {text: UPI, width: 200, height: 200}); } catch(e) {}
  var tm = document.getElementById('tm'), msg = document.getElementById('msg');
  function fmt(s){ var m=Math.floor(s/60), x=s%60; return m+':'+(x<10?'0':'')+x; }
  tm.textContent = fmt(left);
  setInterval(function(){ if(left>0){ left--; tm.textContent = fmt(left);} }, 1000);
  var fails = 0, stopped = false;
  function note(t, bad){ msg.innerHTML = (bad?'':'<span class="spin"></span>') + t; }
  function poll(){
    if (stopped) return;
    fetch('checkout-status.php?order_id=' + encodeURIComponent(ID), {cache:'no-store'})
      .then(function(r){ return r.json(); })
      .then(function(d){
        fails = 0;
        if (d.reject) { stopped = true; location.reload(); return; }
        if (d.status && d.status !== 'pending' && d.status !== 'unknown') { stopped = true; location.reload(); return; }  // server-verified status only
        if (d.fatal) { stopped = true; note('⚠️ ' + (d.error || 'Error'), true); return; }
        note(d.error ? d.error : 'Waiting for your payment… please keep this page open.');
        setTimeout(poll, d.transient ? 6000 : 4000);
      })
      .catch(function(){            // Rule 4: one network failure is not fatal — retry with backoff
        fails++; note('Network is slow, retrying…');
        setTimeout(poll, Math.min(4000 + fails * 2000, 15000));
      });
  }
  setTimeout(poll, 4000);
})();
</script>
<?php endif; ?>
<?php page_footer(true);
