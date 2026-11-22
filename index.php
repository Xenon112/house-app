<?php
require __DIR__ . '/includes/boot.php';
$u = hs_require_login();
$uid = (int)$u['id'];

$today = hs_today();
$mine = hs_chores_open($uid);
$dueMine = array_values(array_filter($mine, fn($c) => $c['due_date'] === null || $c['due_date'] <= $today));
$allOpen = hs_chores_open();
$dueAll = count(array_filter($allOpen, fn($c) => $c['due_date'] !== null && $c['due_date'] <= $today));
$shop = hs_shopping_list();
$cookable = hs_recipes_cookable();
$doneToday = hs_chores_recent_done($uid);

$h = (int)date('G');
$greet = $h < 12 ? 'Good morning' : ($h < 18 ? 'Good afternoon' : 'Good evening');

hs_header('Home', 'home');
?>
<p class="hello"><?= e($greet) ?>, <?= e($u['name']) ?> · <?= e(date('l j F')) ?></p>

<div class="tiles">
  <a class="tile<?= $dueAll ? ' alert' : '' ?>" href="chores.php">
    <span class="ico-wrap"><?= hs_icon('chores', 24) ?></span>
    <span class="big"><?= $dueAll ?></span><span class="lab">chores due</span>
  </a>
  <a class="tile<?= $shop ? ' alert' : '' ?>" href="food.php?view=list">
    <span class="ico-wrap"><?= hs_icon('cart', 24) ?></span>
    <span class="big"><?= count($shop) ?></span><span class="lab">to buy</span>
  </a>
  <a class="tile" href="recipes.php?show=can">
    <span class="ico-wrap"><?= hs_icon('recipes', 24) ?></span>
    <span class="big"><?= $cookable ?></span><span class="lab">can cook now</span>
  </a>
</div>

<div class="home-cols">
  <section>
    <h2>My chores today <span class="count"><?= count($dueMine) ?></span></h2>
    <div class="tick-list">
      <?php foreach ($dueMine as $c) echo hs_chore_row($c); ?>
      <?php foreach (array_slice($doneToday, 0, 5) as $c) echo hs_chore_row($c, true); ?>
    </div>
    <?php if (!$dueMine && !$doneToday): ?>
      <div class="empty">Nothing to do today. <a href="chore_edit.php">Add a chore</a></div>
    <?php elseif (!$dueMine): ?>
      <p class="muted" style="margin-top:.8rem">All done for today — nice work!</p>
    <?php endif; ?>
  </section>
  <section>
    <h2>Shopping list <span class="count"><?= count($shop) ?></span></h2>
    <div class="tick-list">
      <?php foreach (array_slice($shop, 0, 8) as $i) echo hs_list_row($i); ?>
    </div>
    <?php if (!$shop): ?><div class="empty">Nothing is running low.</div><?php endif; ?>
    <?php if (count($shop) > 8): ?><p><a href="food.php?view=list">See all <?= count($shop) ?> →</a></p><?php endif; ?>

    <?php $counts = hs_month_counts(date('Y-m')); $best = max($counts ?: [0]); ?>
    <h2><?= hs_icon('trophy', 22) ?> <?= e(date('F')) ?> scores</h2>
    <a class="panel" href="scores.php" style="display:block;color:inherit">
      <?php foreach (array_slice($counts, 0, 4, true) as $mid => $n): ?>
        <div class="score-row<?= $n > 0 && $n === $best ? ' top' : '' ?>">
          <?= hs_avatar($mid, 'sm') ?>
          <div class="grow"><b><?= e(hs_members()[$mid]['name']) ?></b>
            <div class="score-bar"><i style="width:<?= $best ? round($n / $best * 100) : 0 ?>%;--c:<?= e(hs_members()[$mid]['color']) ?>"></i></div></div>
          <span class="score-n" style="font-size:1.25rem"><?= $n > 0 && $n === $best ? '🏆 ' : '' ?><?= $n ?></span>
        </div>
      <?php endforeach; ?>
    </a>
  </section>
</div>
<?php hs_footer();
