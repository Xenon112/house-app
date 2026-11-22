<?php
require __DIR__ . '/includes/boot.php';
hs_require_login();
$db = hs_db();
$id = (int)($_GET['id'] ?? 0);
$st = $db->prepare('SELECT * FROM recipes WHERE id = ?');
$st->execute([$id]);
$r = $st->fetch();
if (!$r) hs_redirect('recipes.php');
$ings = hs_recipe_ingredients($id);

if (hs_is_post()) {
    hs_check_csrf();
    $act = (string)($_POST['act'] ?? '');
    if ($act === 'cooked') {
        // Use up what the recipe needs (only ingredients linked to food in stock)
        $used = 0;
        foreach ($ings as $x) {
            if ($x['item_id'] && $x['stock'] !== null && (float)$x['amount'] > 0) {
                hs_item_set_qty((int)$x['item_id'], (float)$x['stock'] - (float)$x['amount']);
                $used++;
            }
        }
        hs_flash($used ? "Enjoy! Took $used ingredient" . ($used > 1 ? 's' : '') . ' out of stock.' : 'Enjoy!');
    } elseif ($act === 'list') {
        $n = 0;
        foreach ($ings as $x) {
            if ($x['have'] === false) {
                $db->prepare('UPDATE items SET on_list = 1 WHERE id = ?')->execute([$x['item_id']]);
                $n++;
            }
        }
        hs_flash($n ? "$n item" . ($n > 1 ? 's' : '') . ' added to the shopping list.' : 'Nothing missing.');
    }
    hs_redirect('recipe.php?id=' . $id);
}

$steps = array_values(array_filter(array_map('trim', preg_split('/\R/', (string)$r['steps']))));
$missing = count(array_filter($ings, fn($x) => $x['have'] === false));
$canCook = count(array_filter($ings, fn($x) => $x['item_id'] && (float)$x['amount'] > 0));

hs_header($r['title'], 'recipes', ['back' => 'recipes.php',
    'actions' => '<a class="btn secondary" href="recipe_edit.php?id=' . $id . '">' . hs_icon('edit', 20) . ' Edit</a>']);
?>
<div class="recipe-facts" style="margin-bottom:1rem;font-size:1rem">
  <?php if ($r['minutes']): ?><span><?= hs_icon('clock', 18) ?> <?= (int)$r['minutes'] ?> min</span><?php endif; ?>
  <?php if ($r['servings']): ?><span><?= hs_icon('serves', 18) ?> Serves <?= (int)$r['servings'] ?></span><?php endif; ?>
</div>

<div class="cook-cols">
  <section class="panel">
    <h2 style="margin-top:0">Ingredients</h2>
    <?php foreach ($ings as $x):
        $amt = $x['amount'] !== null ? hs_num($x['amount']) . ($x['unit'] ? ' ' . $x['unit'] : '') . ' ' : '';
        if ($x['have'] === null) $hint = '<small class="na">not tracked in stock</small>';
        elseif ($x['have']) $hint = '<small class="ok">in stock: ' . hs_num($x['stock']) . ($x['stock_unit'] ? ' ' . e($x['stock_unit']) : '') . '</small>';
        else $hint = '<small class="miss">missing — ' . hs_num($x['stock']) . ($x['stock_unit'] ? ' ' . e($x['stock_unit']) : '') . ' in stock</small>'; ?>
      <div class="ing-row" data-check>
        <button type="button" class="tick" aria-label="Got <?= e($x['name']) ?>"><?= hs_icon('check', 26) ?></button>
        <span class="ing-text"><?= e($amt) ?><?= e($x['name']) ?><?= $hint ?></span>
      </div>
    <?php endforeach; ?>
    <?php if (!$ings): ?><p class="muted">No ingredients listed.</p><?php endif; ?>
    <div class="btn-row">
      <?php if ($missing): ?>
        <form method="post"><?= hs_csrf_field() ?><input type="hidden" name="act" value="list">
          <button class="btn secondary" type="submit"><?= hs_icon('cart', 20) ?> Add <?= $missing ?> missing to shopping list</button></form>
      <?php endif; ?>
    </div>
  </section>

  <section class="panel">
    <h2 style="margin-top:0">Steps</h2>
    <?php foreach ($steps as $n => $s): ?>
      <div class="step-row" data-check>
        <button type="button" class="tick" aria-label="Step <?= $n + 1 ?> done"><?= hs_icon('check', 26) ?></button>
        <span class="step-num"><?= $n + 1 ?></span>
        <span class="step-text"><?= e(preg_replace('/^\d+[.)]\s*/', '', $s)) ?></span>
      </div>
    <?php endforeach; ?>
    <?php if (!$steps): ?><p class="muted">No steps written yet.</p><?php endif; ?>
    <?php if ($r['notes']): ?><p class="muted" style="margin-top:1rem;white-space:pre-line"><?= e($r['notes']) ?></p><?php endif; ?>
    <?php if ($canCook): ?>
      <form method="post" class="btn-row"><?= hs_csrf_field() ?><input type="hidden" name="act" value="cooked">
        <button class="btn big block" type="submit" data-confirm="Take the ingredients of this recipe out of your food stock?"><?= hs_icon('pot', 22) ?> I cooked this — update stock</button></form>
    <?php endif; ?>
  </section>
</div>
<?php hs_footer();
