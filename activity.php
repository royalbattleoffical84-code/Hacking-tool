<?php
require_once __DIR__ . '/includes/bootstrap.php';
$m = require_login();
$q = db()->prepare('SELECT * FROM activity_logs WHERE merchant_id = ? ORDER BY id DESC LIMIT 100'); $q->execute([$m['id']]); $rows = $q->fetchAll();
page_header('Activity Logs', 'activity');
?>
<h1>Activity Logs</h1>
<div class="card"><div class="tw"><table><tr><th>Time</th><th>Action</th><th>Detail</th><th>IP</th></tr>
<?php foreach ($rows as $r): ?><tr><td><?= e($r['created_at']) ?></td><td><?= e($r['action']) ?></td><td><?= e($r['detail']) ?></td><td><?= e($r['ip']) ?></td></tr>
<?php endforeach; if (!$rows): ?><tr><td colspan="4" class="muted">No activity yet.</td></tr><?php endif; ?></table></div>
<p class="small muted">Last 100 account events (logins, settings, plans, links). If you see something you don't recognise, change your password.</p></div>
<?php page_footer();
