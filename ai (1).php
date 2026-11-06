<?php
require_once __DIR__ . '/includes/bootstrap.php';
$m = require_login();
page_header(t('ai'), 'ai');
?>
<h1>🤖 <?= e(t('ai')) ?></h1>
<p class="muted small">Tell me your stack (PHP, Node, Python, WordPress, Android app, Telegram bot…) and I'll give you complete code to connect ORVIX PAY, step by step.</p>
<div class="card chat">
  <div class="msgs" id="msgs"><div class="bub a">Hi! 👋 I can help you connect ORVIX PAY to your project. What are you building, and which language/framework do you use?</div></div>
  <div class="row" style="margin:8px 0">
    <?php foreach (['PHP website', 'Node.js / Express', 'Python / Flask', 'WordPress / WooCommerce', 'Android WebView app', 'Telegram bot'] as $s): ?>
      <button type="button" class="btn ghost sm" style="flex:0 0 auto" onclick="ask(this.innerText + ' — how do I connect ORVIX PAY?')"><?= e($s) ?></button>
    <?php endforeach; ?>
  </div>
  <form id="f" class="row" style="flex-wrap:nowrap">
    <input id="q" placeholder="Type your question…" autocomplete="off" style="margin:0;flex:1">
    <button class="btn" id="go" style="flex:0 0 auto">Send</button>
  </form>
</div>
<script>
var CSRF = <?= json_encode(csrf_token()) ?>, hist = [], busy = false;
var box = document.getElementById('msgs');
// Resolve beside this page, so this also works when ORVIX PAY is installed in a subfolder.
var AI_CHAT_URL = new URL('./ai-assistant.php', window.location.href).href;
function addMsg(role, text){
  var d = document.createElement('div'); d.className = 'bub ' + (role === 'user' ? 'u' : 'a');
  text.split('```').forEach(function(part, i){
    if (i % 2) { var p = document.createElement('pre'); p.textContent = part.replace(/^[a-zA-Z0-9+#_-]*\n/, ''); d.appendChild(p); }
    else if (part) d.appendChild(document.createTextNode(part));
  });
  box.appendChild(d); box.scrollTop = box.scrollHeight; return d;
}
function ask(text){
  if (busy || !text.trim()) return;
  busy = true; document.getElementById('go').disabled = true;
  addMsg('user', text); hist.push({role: 'user', content: text});
  var wait = addMsg('assistant', 'Thinking…');
  fetch(AI_CHAT_URL, {
    method: 'POST', credentials: 'same-origin', cache: 'no-store',
    headers: {'Content-Type': 'application/json', 'X-CSRF': CSRF, 'Accept': 'application/json'},
    body: JSON.stringify({messages: hist})
  })
  .then(function(r){
    return r.text().then(function(raw){
      var d;
      try { d = JSON.parse(raw); }
      catch (_) { throw new Error('Server returned a non-JSON response (HTTP ' + r.status + '). Check that ai-assistant.php is uploaded in the same folder.'); }
      if (!r.ok && !d.error) throw new Error('Request failed (HTTP ' + r.status + ').');
      return d;
    });
  })
  .then(function(d){
    wait.remove();
    if (d.ok) { hist.push({role: 'assistant', content: d.reply}); addMsg('assistant', d.reply); }
    else { hist.pop(); addMsg('assistant', '⚠️ ' + (d.error || 'Something went wrong.')); }
  })
  .catch(function(err){
    wait.remove(); hist.pop();
    addMsg('assistant', '⚠️ ' + (err && err.message ? err.message : 'Network request failed.') + '\nEndpoint: ' + AI_CHAT_URL);
  })
  .finally(function(){ busy = false; document.getElementById('go').disabled = false; });
}
document.getElementById('f').addEventListener('submit', function(e){ e.preventDefault(); var q = document.getElementById('q'); var v = q.value; q.value = ''; ask(v); });
</script>
<?php page_footer();
