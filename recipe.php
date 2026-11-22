<?php
require __DIR__ . '/includes/boot.php';
hs_require_login();
$db = hs_db();
$id = (int)($_GET['id'] ?? 0);
$st = $db->prepare('SELECT * FROM recipes WHERE id = ?');
$st->execute([$id]);
$r = $st->fetch();
if (!$r) hs_redirect('recipes.php');
$status = hs_recipe_status($id);
$ings = $status['ings'];

if (hs_is_post()) {
    hs_check_csrf();
    $act = (string)($_POST['act'] ?? '');
    if ($act === 'cooked') {
        // Use up what the recipe needs (only ingredients linked to food in stock, in the stock's unit)
        $used = 0;
        foreach ($ings as $x) {
            if ($x['item_id'] && $x['stock'] !== null && $x['need'] > 0) {
                hs_item_set_qty((int)$x['item_id'], (float)$x['stock'] - $x['need']);
                $used++;
            }
        }
        hs_notify_everyone('done', hs_user()['name'] . ' cooked ' . $r['title'], $used ? 'The ingredients were taken out of stock.' : '', 'recipe.php?id=' . $id);
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
$missing = count($status['missing']);
$canCook = count(array_filter($ings, fn($x) => $x['item_id'] && $x['need'] > 0));
$by = hs_members()[(int)$r['created_by']]['name'] ?? '';
$su = fn($x) => $x['stock_unit'] ? ' ' . e($x['stock_unit']) : '';

hs_header($r['title'], 'recipes', ['back' => 'recipes.php',
    'actions' => '<a class="btn secondary" href="recipe_edit.php?id=' . $id . '">' . hs_icon('edit', 20) . ' Edit</a>']);
?>
<div class="recipe-facts" style="margin-bottom:1rem;font-size:1rem">
  <?php if ($r['minutes']): ?><span><?= hs_icon('clock', 18) ?> <?= (int)$r['minutes'] ?> min</span><?php endif; ?>
  <?php if ($r['servings']): ?><span><?= hs_icon('serves', 18) ?> Serves <?= (int)$r['servings'] ?></span><?php endif; ?>
  <?php if ($by): ?><span><?= hs_avatar((int)$r['created_by'], 'xs') ?> Added by <?= e($by) ?></span><?php endif; ?>
</div>

<?php if ($status['can'] === true): ?>
  <div class="cook-banner ok"><?= hs_icon('check', 26) ?><div><b>You can cook this now.</b>
    <?= $status['low'] ? '<br>Afterwards these will be low and go on the shopping list: ' . e(implode(', ', array_map(fn($x) => $x['item_name'], $status['low']))) . '.' : '<br>Everything is in stock.' ?></div></div>
<?php elseif ($status['can'] === false): ?>
  <div class="cook-banner miss"><?= hs_icon('cart', 26) ?><div><b>Can't cook this yet — <?= $missing ?> ingredient<?= $missing > 1 ? 's' : '' ?> missing:</b>
    <br><?= e(implode(', ', array_map(fn($x) => $x['item_name'], $status['missing']))) ?></div>
    <form method="post"><?= hs_csrf_field() ?><input type="hidden" name="act" value="list">
      <button class="btn" type="submit"><?= hs_icon('cart', 20) ?> Add to shopping list</button></form></div>
<?php endif; ?>

<div class="cook-cols">
  <section class="panel">
    <h2 style="margin-top:0">Ingredients</h2>
    <?php foreach ($ings as $x):
        $amt = $x['amount'] !== null ? hs_num($x['amount']) . ($x['unit'] ? ' ' . $x['unit'] : '') . ' ' : '';
        if ($x['have'] === null) $hint = '<small class="na">not in your food list</small>';
        elseif ($x['have'] && $x['low_after']) $hint = '<small class="low">in stock: ' . hs_num($x['stock']) . $su($x) . ' — will be low after cooking</small>';
        elseif ($x['have']) $hint = '<small class="ok">in stock: ' . hs_num($x['stock']) . $su($x) . '</small>';
        else $hint = '<small class="miss">' . ((float)$x['stock'] > 0 ? 'not enough — only ' . hs_num($x['stock']) . $su($x) : 'out of stock') . '</small>'; ?>
      <div class="ing-row" data-check>
        <button type="button" class="tick" aria-label="Got <?= e($x['name']) ?>"><?= hs_icon('check', 26) ?></button>
        <span class="ing-text"><?= e($amt) ?><?= e($x['name']) ?><?= $hint ?></span>
      </div>
    <?php endforeach; ?>
    <?php if (!$ings): ?><p class="muted">No ingredients listed.</p><?php endif; ?>
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
