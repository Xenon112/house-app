<?php
require __DIR__ . '/includes/boot.php';
hs_require_login();
$recipes = hs_db()->query('SELECT * FROM recipes ORDER BY title COLLATE NOCASE')->fetchAll();

hs_header('Recipes', 'recipes', ['actions' => '<a class="btn" href="recipe_edit.php">' . hs_icon('plus', 22) . ' New recipe</a>']);
?>
<?php if ($recipes): ?>
  <div class="toolbar"><div class="grow"><input type="search" id="recipeSearch" placeholder="Search recipes…" aria-label="Search recipes"></div></div>
  <div class="recipe-grid" id="recipeGrid">
  <?php foreach ($recipes as $r):
      $ings = hs_recipe_ingredients((int)$r['id']);
      $tracked = array_filter($ings, fn($x) => $x['have'] !== null);
      $have = count(array_filter($tracked, fn($x) => $x['have']));
      $pct = $tracked ? round($have / count($tracked) * 100) : 0; ?>
    <a class="recipe-card" href="recipe.php?id=<?= (int)$r['id'] ?>" data-name="<?= e(mb_strtolower($r['title'] . ' ' . implode(' ', array_column($ings, 'name')))) ?>">
      <h3><?= e($r['title']) ?></h3>
      <div class="recipe-facts">
        <?php if ($r['minutes']): ?><span><?= hs_icon('clock', 16) ?> <?= (int)$r['minutes'] ?> min</span><?php endif; ?>
        <?php if ($r['servings']): ?><span><?= hs_icon('serves', 16) ?> <?= (int)$r['servings'] ?></span><?php endif; ?>
        <span><?= hs_icon('food', 16) ?> <?= count($ings) ?> ingredients</span>
      </div>
      <?php if ($tracked): ?>
        <div class="have-bar"><i style="width:<?= $pct ?>%"></i></div>
        <span class="have-txt"><?= $have === count($tracked) ? 'You have everything' : "You have $have of " . count($tracked) ?></span>
      <?php endif; ?>
    </a>
  <?php endforeach; ?>
  </div>
<?php else: ?>
  <div class="empty">No recipes yet. <a href="recipe_edit.php">Add your first recipe</a></div>
<?php endif; ?>
<?php hs_footer();
