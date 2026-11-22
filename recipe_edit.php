<?php
require __DIR__ . '/includes/boot.php';
$u = hs_require_login();
$db = hs_db();
$id = (int)($_GET['id'] ?? 0);
$r = null;
if ($id) {
    $st = $db->prepare('SELECT * FROM recipes WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch() ?: null;
    if (!$r) hs_redirect('recipes.php');
}

if (hs_is_post()) {
    hs_check_csrf();
    if (isset($_POST['delete']) && $r) {
        $db->prepare('DELETE FROM recipes WHERE id = ?')->execute([$id]);
        hs_flash('Recipe deleted.');
        hs_redirect('recipes.php');
    }
    $title = trim((string)($_POST['title'] ?? ''));
    $minutes = (int)($_POST['minutes'] ?? 0) ?: null;
    $servings = (int)($_POST['servings'] ?? 0) ?: null;
    $steps = trim((string)($_POST['steps'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));
    if ($title === '') {
        hs_flash('Give the recipe a name.', 'err');
    } else {
        $db->beginTransaction();
        if ($r) {
            $db->prepare('UPDATE recipes SET title=?, minutes=?, servings=?, steps=?, notes=? WHERE id=?')
               ->execute([$title, $minutes, $servings, $steps, $notes ?: null, $id]);
            $db->prepare('DELETE FROM recipe_ingredients WHERE recipe_id = ?')->execute([$id]);
        } else {
            $db->prepare('INSERT INTO recipes (title, minutes, servings, steps, notes, created_by) VALUES (?,?,?,?,?,?)')
               ->execute([$title, $minutes, $servings, $steps, $notes ?: null, $u['id']]);
            $id = (int)$db->lastInsertId();
        }
        $ins = $db->prepare('INSERT INTO recipe_ingredients (recipe_id, item_id, name, amount, unit, sort) VALUES (?,?,?,?,?,?)');
        $names = (array)($_POST['ing_name'] ?? []);
        foreach ($names as $k => $n) {
            $n = trim((string)$n);
            if ($n === '') continue;
            $amt = trim(str_replace(',', '.', (string)($_POST['ing_amount'][$k] ?? '')));
            $unit = trim((string)($_POST['ing_unit'][$k] ?? ''));
            // Linked to stock when the name matches a food item
            $ins->execute([$id, hs_find_item_by_name($n), mb_substr($n, 0, 80), $amt === '' ? null : (float)$amt, mb_substr($unit, 0, 20), $k]);
        }
        $db->commit();
        hs_flash('Recipe saved.');
        hs_redirect('recipe.php?id=' . $id);
    }
}

$cur = $r ?? ['title' => '', 'minutes' => '', 'servings' => '', 'steps' => '', 'notes' => ''];
$ings = $r ? hs_recipe_ingredients($id) : [];
if (!$ings) $ings = [['name' => '', 'amount' => null, 'unit' => '']];
$itemNames = $db->query('SELECT name, unit FROM items ORDER BY name COLLATE NOCASE')->fetchAll();

hs_header($r ? 'Edit recipe' : 'New recipe', 'recipes', ['back' => $r ? 'recipe.php?id=' . $id : 'recipes.php']);
?>
<form method="post" class="panel" style="max-width:860px">
  <?= hs_csrf_field() ?>
  <label class="lbl" for="title">Recipe name</label>
  <input type="text" id="title" name="title" value="<?= e($cur['title']) ?>" placeholder="e.g. Spaghetti Bolognese" required maxlength="120">
  <div class="field-row">
    <div><label class="lbl" for="min">Minutes</label><input type="number" id="min" name="minutes" min="0" value="<?= e($cur['minutes']) ?>" inputmode="numeric"></div>
    <div><label class="lbl" for="srv">Serves</label><input type="number" id="srv" name="servings" min="0" value="<?= e($cur['servings']) ?>" inputmode="numeric"></div>
  </div>

  <label class="lbl">Ingredients</label>
  <p class="muted" style="margin:-.2rem 0 .6rem;font-size:.9rem">Pick a name from your food list to link it — then the app knows if you have it and can take it out of stock when you cook.</p>
  <div id="ings">
    <?php foreach ($ings as $x): ?>
      <div class="ing-edit">
        <input type="text" name="ing_name[]" value="<?= e($x['name']) ?>" placeholder="Ingredient" list="foodNames" maxlength="80">
        <input type="text" name="ing_amount[]" value="<?= $x['amount'] === null ? '' : hs_num($x['amount']) ?>" placeholder="Amount" inputmode="decimal">
        <input type="text" name="ing_unit[]" value="<?= e($x['unit']) ?>" placeholder="Unit" maxlength="20">
        <button type="button" class="rm" aria-label="Remove ingredient"><?= hs_icon('trash', 20) ?></button>
      </div>
    <?php endforeach; ?>
  </div>
  <datalist id="foodNames"><?php foreach ($itemNames as $n) echo '<option value="' . e($n['name']) . '">'; ?></datalist>
  <button type="button" class="btn secondary" id="addIng"><?= hs_icon('plus', 20) ?> Add ingredient</button>

  <label class="lbl" for="steps">Steps — one per line</label>
  <textarea id="steps" name="steps" rows="8" placeholder="Boil water&#10;Cook the pasta for 10 minutes&#10;…"><?= e($cur['steps']) ?></textarea>

  <label class="lbl" for="notes">Notes (optional)</label>
  <textarea id="notes" name="notes" rows="3"><?= e($cur['notes']) ?></textarea>

  <div class="btn-row">
    <button class="btn big grow" type="submit"><?= hs_icon('check', 22) ?> Save recipe</button>
    <?php if ($r): ?><button class="btn big ghost-danger" type="submit" name="delete" value="1" data-confirm="Delete this recipe?"><?= hs_icon('trash', 22) ?></button><?php endif; ?>
  </div>
</form>
<script>
(function () {
  const box = document.getElementById('ings');
  const units = <?= json_encode(array_column($itemNames, 'unit', 'name'), JSON_HEX_TAG) ?>;
  document.getElementById('addIng').addEventListener('click', () => {
    const row = box.querySelector('.ing-edit').cloneNode(true);
    row.querySelectorAll('input').forEach(i => i.value = '');
    box.appendChild(row);
    row.querySelector('input').focus();
  });
  box.addEventListener('click', (e) => {
    const b = e.target.closest('.rm');
    if (!b) return;
    if (box.children.length > 1) b.closest('.ing-edit').remove();
    else b.closest('.ing-edit').querySelectorAll('input').forEach(i => i.value = '');
  });
  // Picking a food fills in its unit
  box.addEventListener('change', (e) => {
    if (e.target.name !== 'ing_name[]') return;
    const u = units[e.target.value];
    const unitInput = e.target.closest('.ing-edit').querySelector('[name="ing_unit[]"]');
    if (u && !unitInput.value) unitInput.value = u;
  });
})();
</script>
<?php hs_footer();
