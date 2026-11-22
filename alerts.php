<?php
require __DIR__ . '/includes/boot.php';
$u = hs_require_login();
$db = hs_db();
$st = $db->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 50');
$st->execute([$u['id']]);
$rows = $st->fetchAll();
// Opening the list marks everything read (after loading, so new ones still show highlighted once)
$db->prepare('UPDATE notifications SET read_at = CURRENT_TIMESTAMP WHERE user_id = ? AND read_at IS NULL')->execute([$u['id']]);
$icons = ['assign' => 'chores', 'due' => 'clock', 'low' => 'cart', 'test' => 'phone'];

hs_header('Alerts', 'alerts', ['actions' => '<a class="btn secondary" href="settings.php#alerts">' . hs_icon('phone', 20) . ' Phone alerts</a>']);
?>
<div style="max-width:760px">
<?php foreach ($rows as $n): ?>
  <a class="alert-row<?= $n['read_at'] ? '' : ' unread' ?>" href="<?= e($n['url']) ?>">
    <span class="ico-wrap"><?= hs_icon($icons[$n['kind']] ?? 'bell', 24) ?></span>
    <span><span class="t"><?= e($n['title']) ?></span><br><span class="b"><?= e($n['body']) ?></span></span>
    <span class="when"><?= e(hs_time_ago($n['created_at'])) ?></span>
  </a>
<?php endforeach; ?>
<?php if (!$rows): ?><div class="empty">No alerts yet. You'll see new chores, reminders and low food here.</div><?php endif; ?>
</div>
<?php hs_footer();
