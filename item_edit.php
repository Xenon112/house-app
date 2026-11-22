<?php
require __DIR__ . '/includes/boot.php';
hs_require_login();
$db = hs_db();
$id = (int)($_GET['id'] ?? 0);
$i = $id ? hs_item($id) : null;
if ($id && !$i) hs_redirect('food.php');

if (hs_is_post()) {
    hs_check_csrf();
    if (isset($_POST['delete']) && $i) {
        $db->prepare('DELETE FROM items WHERE id = ?')->execute([$id]);
        hs_flash($i['name'] . ' removed.');
        hs_redirect('food.php');
    }
    $name = trim((string)($_POST['name'] ?? ''));
    $cat = trim((string)($_POST['category'] ?? '')) ?: 'Other';
    if ($cat === '__other') $cat = trim((string)($_POST['category_new'] ?? '')) ?: 'Other';
    $unit = trim((string)($_POST['unit'] ?? ''));
    $qty = max(0, (float)str_replace(',', '.', (string)($_POST['qty'] ?? '0')));
    $min = max(0, (float)str_replace(',', '.', (string)($_POST['min_qty'] ?? '1')));
    $tgt = trim((string)($_POST['target_qty'] ?? ''));
    $tgt = $tgt === '' ? null : max(0, (float)str_replace(',', '.', $tgt));
    $onList = !empty($_POST['on_list']) ? 1 : 0;
    if ($name === '') {
        hs_flash('Give the food a name.', 'err');
    } else {
        if ($i) {
            $db->prepare('UPDATE items SET name=?, category=?, unit=?, min_qty=?, target_qty=?, on_list=? WHERE id=?')
               ->execute([$name, mb_substr($cat, 0, 40), mb_substr($unit, 0, 20), $min, $tgt, $onList, $id]);
        } else {
            $db->prepare('INSERT INTO items (name, category, unit, qty, min_qty, target_qty, on_list, low_alerted) VALUES (?,?,?,?,?,?,?,?)')
               ->execute([$name, mb_substr($cat, 0, 40), mb_substr($unit, 0, 20), $qty, $min, $tgt, $onList, $qty <= $min ? 1 : 0]);
            $id = (int)$db->lastInsertId();
            // Link recipe ingredients with the same name to this new item
            $db->prepare('UPDATE recipe_ingredients SET item_id = ? WHERE item_id IS NULL AND name = ? COLLATE NOCASE')->execute([$id, $name]);
        }
        if ($i) {
            hs_item_set_qty($id, $qty);   // amount change goes through the low-stock check
            $db->prepare('UPDATE items SET on_list = ? WHERE id = ?')->execute([$onList, $id]);
        }
        hs_flash($i ? 'Saved.' : $name . ' added.');
        hs_redirect('food.php');
    }
}

$cur = $i ?? ['name' => trim((string)($_GET['name'] ?? '')), 'category' => 'Pantry', 'unit' => '', 'qty' => 1, 'min_qty' => 1, 'target_qty' => null, 'on_list' => 0];
$cats = array_values(array_unique(array_merge(HS_CATEGORIES, $db->query('SELECT DISTINCT category FROM items')->fetchAll(PDO::FETCH_COLUMN))));
$catOpts = array_combine($cats, array_map('e', $cats)) + ['__other' => '+ New'];
$units = ['', 'pcs', 'g', 'kg', 'ml', 'l', 'pack', 'can', 'bottle', 'jar'];

hs_header($i ? $i['name'] : 'Add food', 'food', ['back' => 'food.php']);
?>
<form method="post" class="panel" style="max-width:760px">
  <?= hs_csrf_field() ?>
  <label class="lbl" for="name">Name</label>
  <input type="text" id="name" name="name" value="<?= e($cur['name']) ?>" placeholder="e.g. Milk" required maxlength="80" <?= $i ? '' : 'autofocus' ?>>

  <label class="lbl">Where is it kept?</label>
  <?= hs_chips('category', $catOpts, $cur['category']) ?>
  <input type="text" name="category_new" id="catNew" placeholder="New place, e.g. Cellar" style="margin-top:.5rem;display:none" maxlength="40">

  <div class="field-row">
    <div>
      <label class="lbl" for="qty">How many now?</label>
      <div class="stepper" style="justify-content:flex-start">
        <button type="button" class="step" data-field-step="-1" aria-label="Less"><?= hs_icon('minus', 26) ?></button>
        <input type="number" id="qty" name="qty" value="<?= hs_num($cur['qty']) ?>" min="0" step="any" inputmode="decimal" style="width:110px;text-align:center;font-weight:800;font-size:1.3rem">
        <button type="button" class="step" data-field-step="1" aria-label="More"><?= hs_icon('plus', 26) ?></button>
      </div>
    </div>
    <div>
      <label class="lbl" for="unit">Unit</label>
      <select id="unit" name="unit">
        <?php foreach (array_unique(array_merge($units, [$cur['unit']])) as $un): ?>
          <option value="<?= e($un) ?>" <?= $un === $cur['unit'] ? 'selected' : '' ?>><?= $un === '' ? '(count)' : e($un) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
  <div class="field-row">
    <div>
      <label class="lbl" for="min">Alert me when at or below</label>
      <input type="number" id="min" name="min_qty" value="<?= hs_num($cur['min_qty']) ?>" min="0" step="any" inputmode="decimal">
    </div>
    <div>
      <label class="lbl" for="tgt">Normal amount (after shopping)</label>
      <input type="number" id="tgt" name="target_qty" value="<?= $cur['target_qty'] === null ? '' : hs_num($cur['target_qty']) ?>" min="0" step="any" inputmode="decimal" placeholder="optional">
    </div>
  </div>
  <label class="switch-row" style="margin-top:.8rem"><span>Put on the shopping list now</span>
    <input type="checkbox" name="on_list" value="1" <?= $cur['on_list'] ? 'checked' : '' ?>></label>

  <div class="btn-row">
    <button class="btn big grow" type="submit"><?= hs_icon('check', 22) ?> Save</button>
    <?php if ($i): ?><button class="btn big ghost-danger" type="submit" name="delete" value="1" data-confirm="Remove <?= e($i['name']) ?>?"><?= hs_icon('trash', 22) ?></button><?php endif; ?>
  </div>
</form>
<script>
const q = document.getElementById('qty');
document.querySelectorAll('[data-field-step]').forEach(b => b.addEventListener('click', () => {
  q.value = Math.max(0, (parseFloat(q.value) || 0) + parseFloat(b.dataset.fieldStep));
}));
document.querySelectorAll('input[name=category]').forEach(r => r.addEventListener('change', () => {
  const n = document.getElementById('catNew');
  n.style.display = r.value === '__other' ? '' : 'none';
  if (r.value === '__other') n.focus();
}));
</script>
<?php hs_footer();
