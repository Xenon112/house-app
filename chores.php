<?php
require __DIR__ . '/includes/boot.php';
$u = hs_require_login();
$who = ($_GET['who'] ?? 'all') === 'me' ? 'me' : 'all';
$only = $who === 'me' ? (int)$u['id'] : null;

$today = hs_today();
$week = date('Y-m-d', strtotime('+7 days'));
$groups = ['Overdue' => [], 'Today' => [], 'This week' => [], 'Later' => []];
foreach (hs_chores_open($only) as $c) {
    $d = $c['due_date'];
    if ($d !== null && $d < $today) $groups['Overdue'][] = $c;
    elseif ($d === null || $d === $today) $groups['Today'][] = $c;
    elseif ($d <= $week) $groups['This week'][] = $c;
    else $groups['Later'][] = $c;
}
$done = hs_chores_recent_done($only);

hs_header('Chores', 'chores', ['actions' => '<a class="btn" href="chore_edit.php">' . hs_icon('plus', 22) . ' New chore</a>']);
?>
<div class="toolbar">
  <div class="seg">
    <a href="?who=all" class="<?= $who === 'all' ? 'active' : '' ?>"><?= hs_icon('users', 20) ?> Everyone</a>
    <a href="?who=me" class="<?= $who === 'me' ? 'active' : '' ?>"><?= hs_avatar((int)$u['id'], 'sm') ?> Mine</a>
  </div>
  <a class="btn secondary" href="scores.php"><?= hs_icon('trophy', 20) ?> Scores</a>
</div>

<?php $any = false;
foreach ($groups as $label => $list): if (!$list) continue; $any = true; ?>
  <h2><?= e($label) ?> <span class="count"><?= count($list) ?></span></h2>
  <div class="tick-list"><?php foreach ($list as $c) echo hs_chore_row($c); ?></div>
<?php endforeach; ?>

<?php if (!$any): ?>
  <div class="empty" style="margin-top:1rem">No chores <?= $who === 'me' ? 'for you' : 'yet' ?>. <a href="chore_edit.php">Add one</a></div>
<?php endif; ?>

<?php if ($done): ?>
  <h2>Done in the last 24 hours <span class="count"><?= count($done) ?></span></h2>
  <p class="muted" style="margin:-.4rem 0 .6rem;font-size:.9rem">Ticked by mistake? Tap the tick again to undo.</p>
  <div class="tick-list"><?php foreach ($done as $c) echo hs_chore_row($c, true); ?></div>
<?php endif; ?>
<?php hs_footer();
