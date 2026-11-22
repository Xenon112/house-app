<?php
require __DIR__ . '/includes/boot.php';
hs_require_login();
$show = in_array($_GET['show'] ?? '', ['can', 'cant'], true) ? $_GET['show'] : 'all';

$recipes = [];
$counts = ['all' => 0, 'can' => 0, 'cant' => 0];
foreach (hs_db()->query('SELECT * FROM recipes ORDER BY title COLLATE NOCASE')->fetchAll() as $r) {
    $r['st'] = hs_recipe_status((int)$r['id']);
    $counts['all']++;
    if ($r['st']['can'] === true) $counts['can']++;
    if ($r['st']['can'] === false) $counts['cant']++;
    $recipes[] = $r;
}
// Ready to cook first, then the ones missing the fewest things
usort($recipes, fn($a, $b) => [$a['st']['can'] === true ? 0 : 1, count($a['st']['missing']), mb_strtolower($a['title'])]
                           <=> [$b['st']['can'] === true ? 0 : 1, count($b['st']['missing']), mb_strtolower($b['title'])]);
$list = array_filter($recipes, fn($r) => $show === 'all' || ($show === 'can' ? $r['st']['can'] === true : $r['st']['can'] === false));

function hs_names(array $ings, int $max = 3): string {
    $n = array_map(fn($x) => $x['item_name'] ?? $x['name'], $ings);
    return e(implode(', ', array_slice($n, 0, $max)) . (count($n) > $max ? ' +' . (count($n) - $max) : ''));
}

hs_header('Recipes', 'recipes', ['actions' => '<a class="btn" href="recipe_edit.php">' . hs_icon('plus', 22) . ' New recipe</a>']);
?>
<?php if ($recipes): ?>
  <div class="toolbar">
    <div class="seg">
      <a href="recipes.php" class="<?= $show === 'all' ? 'active' : '' ?>">All <span class="count"><?= $counts['all'] ?></span></a>
      <a href="?show=can" class="<?= $show === 'can' ? 'active' : '' ?>"><?= hs_icon('check', 18) ?> Can cook <span class="count"><?= $counts['can'] ?></span></a>
      <a href="?show=cant" class="<?= $show === 'cant' ? 'active' : '' ?>"><?= hs_icon('cart', 18) ?> Missing food <span class="count"><?= $counts['cant'] ?></span></a>
    </div>
    <div class="grow"><input type="search" id="recipeSearch" placeholder="Search recipes or ingredients…" aria-label="Search recipes"></div>
  </div>
  <div class="recipe-grid" id="recipeGrid">
  <?php foreach ($list as $r):
      $st = $r['st'];
      $pct = $st['tracked'] ? round($st['have'] / $st['tracked'] * 100) : 0;
      $by = hs_members()[(int)$r['created_by']]['name'] ?? ''; ?>
    <a class="recipe-card <?= $st['can'] === true ? 'can' : ($st['can'] === false ? 'cant' : '') ?>" href="recipe.php?id=<?= (int)$r['id'] ?>"
       data-name="<?= e(mb_strtolower($r['title'] . ' ' . implode(' ', array_column($st['ings'], 'name')))) ?>">
      <?php if ($st['can'] === true): ?><span class="cook-badge ok"><?= hs_icon('check', 16) ?> Ready to cook</span>
      <?php elseif ($st['can'] === false): ?><span class="cook-badge miss"><?= hs_icon('cart', 16) ?> Missing <?= count($st['missing']) ?></span><?php endif; ?>
      <h3><?= e($r['title']) ?></h3>
      <div class="recipe-facts">
        <?php if ($r['minutes']): ?><span><?= hs_icon('clock', 16) ?> <?= (int)$r['minutes'] ?> min</span><?php endif; ?>
        <?php if ($r['servings']): ?><span><?= hs_icon('serves', 16) ?> <?= (int)$r['servings'] ?></span><?php endif; ?>
        <?php if ($by): ?><span><?= hs_avatar((int)$r['created_by'], 'xs') ?> <?= e($by) ?></span><?php endif; ?>
      </div>
      <?php if ($st['missing']): ?>
        <span class="why miss">Need: <?= hs_names($st['missing']) ?></span>
      <?php elseif ($st['low']): ?>
        <span class="why low">Will run low: <?= hs_names($st['low']) ?></span>
      <?php elseif ($st['can'] === null): ?>
        <span class="why">No ingredients linked to your food yet</span>
      <?php endif; ?>
      <?php if ($st['tracked']): ?>
        <div class="have-bar"><i style="width:<?= $pct ?>%"></i></div>
        <span class="have-txt">You have <?= $st['have'] ?> of <?= $st['tracked'] ?> ingredients</span>
      <?php endif; ?>
    </a>
  <?php endforeach; ?>
  </div>
  <?php if (!$list): ?><div class="empty"><?= $show === 'can' ? 'Nothing can be cooked with what is in stock right now.' : 'Every recipe can be cooked right now!' ?></div><?php endif; ?>
<?php else: ?>
  <div class="empty">No recipes yet. Everyone in the house can add one. <a href="recipe_edit.php">Add the first recipe</a></div>
<?php endif; ?>
<?php hs_footer();
