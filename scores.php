<?php
require __DIR__ . '/includes/boot.php';
hs_require_login();
$now = date('Y-m');
$m = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['m'] ?? '')) ? $_GET['m'] : $now;
if ($m > $now) $m = $now;
$isNow = $m === $now;
$counts = hs_month_counts($m);
$best = $counts ? max($counts) : 0;
$total = array_sum($counts);
$totals = hs_award_totals();
$history = hs_award_history();
$prevM = (new DateTimeImmutable($m . '-01'))->modify('-1 month')->format('Y-m');
$nextM = (new DateTimeImmutable($m . '-01'))->modify('+1 month')->format('Y-m');
$daysLeft = (int)(new DateTimeImmutable('last day of this month'))->format('j') - (int)date('j');

hs_header('Scores', 'scores');
?>
<div class="toolbar">
  <div class="seg">
    <a href="chores.php"><?= hs_icon('chores', 20) ?> Chores</a>
    <a href="scores.php" class="active"><?= hs_icon('trophy', 20) ?> Scores</a>
  </div>
</div>

<div style="max-width:760px">
  <div class="month-nav">
    <a class="back-btn" href="?m=<?= $prevM ?>" aria-label="Previous month"><?= hs_icon('back', 24) ?></a>
    <div><b><?= e(hs_month_name($m)) ?></b><br><span class="muted"><?= $total ?> chore<?= $total === 1 ? "" : "s" ?> done<?= $isNow ? ' · ' . $daysLeft . ' days to go' : '' ?></span></div>
    <?php if (!$isNow): ?><a class="back-btn flip" href="?m=<?= $nextM ?>" aria-label="Next month"><?= hs_icon('back', 24) ?></a><?php else: ?><span style="width:52px"></span><?php endif; ?>
  </div>

  <section class="panel">
    <?php $rank = 0; $prev = null; $i = 0;
    foreach ($counts as $uid => $n):
        $i++; if ($n !== $prev) { $rank = $i; $prev = $n; }
        $top = $n > 0 && $n === $best; ?>
      <div class="score-row<?= $top ? ' top' : '' ?>">
        <span class="rank"><?= $top ? hs_icon('trophy', 26) : $rank ?></span>
        <?= hs_avatar($uid) ?>
        <div class="grow">
          <b><?= e(hs_members()[$uid]['name']) ?></b>
          <?php if ($top): ?><span class="badge gold"><?= $isNow ? 'Leading' : 'Champion' ?></span><?php endif; ?>
          <div class="score-bar"><i style="width:<?= $best ? round($n / $best * 100) : 0 ?>%;--c:<?= e(hs_members()[$uid]['color']) ?>"></i></div>
        </div>
        <span class="score-n"><?= $n ?></span>
      </div>
    <?php endforeach; ?>
    <?php if (!$total): ?><p class="muted" style="margin:.4rem 0 0">No chores ticked off <?= $isNow ? 'yet this month. Be the first!' : 'this month.' ?></p><?php endif; ?>
    <?php if ($isNow): ?><p class="muted" style="font-size:.9rem;margin:1rem 0 0">On the 1st, whoever did the most chores this month wins the 🏆 and everyone gets the summary.</p><?php endif; ?>
  </section>

  <h2><?= hs_icon('trophy', 22) ?> Hall of fame</h2>
  <section class="panel">
    <?php if ($totals): ?>
      <?php foreach ($totals as $uid => $n): if (!isset(hs_members()[$uid])) continue; ?>
        <div class="score-row">
          <?= hs_avatar($uid) ?>
          <div class="grow"><b><?= e(hs_members()[$uid]['name']) ?></b></div>
          <span class="trophies" title="<?= $n ?> awards"><?= str_repeat('🏆', min($n, 8)) ?><?= $n > 8 ? ' ×' . $n : '' ?></span>
        </div>
      <?php endforeach; ?>
      <h3 style="margin:1.2rem 0 .4rem;font-size:1rem" class="muted">Past champions</h3>
      <?php foreach ($history as $h): ?>
        <a class="history-row" href="?m=<?= e($h['month']) ?>">
          <span><?= e(hs_month_name($h['month'])) ?></span>
          <span><?= implode(' & ', array_map(fn($u) => e(hs_members()[$u]['name'] ?? '?'), array_keys($h['users']))) ?> · <?= (int)reset($h['users']) ?> chores</span>
        </a>
      <?php endforeach; ?>
    <?php else: ?>
      <p class="muted" style="margin:0">No awards yet — the first one is handed out on the 1st of next month.</p>
    <?php endif; ?>
  </section>
</div>
<?php hs_footer();
