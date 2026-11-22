<?php
require __DIR__ . '/includes/boot.php';
hs_require_login();
$view = ($_GET['view'] ?? '') === 'list' ? 'list' : 'all';

$items = hs_db()->query('SELECT * FROM items ORDER BY name COLLATE NOCASE')->fetchAll();
$shop = hs_shopping_list();
$cats = array_values(array_unique(array_map(fn($i) => $i['category'], $items)));
sort($cats);

hs_header('Food', 'food', ['actions' => '<a class="btn" href="item_edit.php">' . hs_icon('plus', 22) . ' Add food</a>']);
?>
<div class="toolbar">
  <div class="seg">
    <a href="food.php" class="<?= $view === 'all' ? 'active' : '' ?>"><?= hs_icon('food', 20) ?> In stock</a>
    <a href="food.php?view=list" class="<?= $view === 'list' ? 'active' : '' ?>"><?= hs_icon('cart', 20) ?> Shopping list <?= $shop ? '<span class="nav-badge">' . count($shop) . '</span>' : '' ?></a>
  </div>
  <?php if ($view === 'all' && $items): ?>
    <div class="grow"><input type="search" id="foodSearch" placeholder="Search food…" aria-label="Search food"></div>
  <?php endif; ?>
</div>

<?php if ($view === 'list'): ?>
  <p class="muted" style="margin:.2rem 0 .8rem">Tap the tick when you've bought it — the stock goes back to the normal amount.</p>
  <div class="tick-list" style="max-width:760px"><?php foreach ($shop as $i) echo hs_list_row($i); ?></div>
  <?php if (!$shop): ?><div class="empty">The shopping list is empty. Everything is stocked.</div><?php endif; ?>
<?php else: ?>
  <?php if ($cats): ?>
    <div class="cat-chips" id="catChips">
      <button type="button" class="active" data-cat="">All</button>
      <?php foreach ($cats as $cat): ?><button type="button" data-cat="<?= e($cat) ?>"><?= e($cat) ?></button><?php endforeach; ?>
    </div>
  <?php endif; ?>
  <div class="item-grid" id="itemGrid"><?php foreach ($items as $i) echo hs_item_card($i); ?></div>
  <?php if (!$items): ?><div class="empty">No food added yet. <a href="item_edit.php">Add your first item</a></div><?php endif; ?>
<?php endif; ?>
<?php hs_footer();
