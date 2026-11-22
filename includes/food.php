<?php
// Food stock: amounts, "low" when at or below the alert amount, shopping list, cooking recipes uses it up.

const HS_CATEGORIES = ['Fridge', 'Freezer', 'Pantry', 'Fruit & veg', 'Drinks', 'Bathroom & cleaning', 'Other'];

function hs_item(int $id): ?array {
    $st = hs_db()->prepare('SELECT * FROM items WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** How much one tap on + / − changes: 100 for grams and millilitres, otherwise 1. */
function hs_item_step(array $i): float {
    return in_array(mb_strtolower(trim((string)$i['unit'])), ['g', 'ml'], true) ? 100.0 : 1.0;
}

function hs_item_low(array $i): bool {
    return (float)$i['qty'] <= (float)$i['min_qty'];
}

/** Set a new amount. Alerts everyone once when it drops to the alert amount. Returns the updated item. */
function hs_item_set_qty(int $id, float $qty): ?array {
    $db = hs_db();
    $i = hs_item($id);
    if (!$i) return null;
    $qty = max(0, round($qty, 2));
    $wasLow = hs_item_low($i);
    $nowLow = $qty <= (float)$i['min_qty'];
    $alert = $nowLow && !$wasLow && !$i['low_alerted'];
    // More in stock than before and no longer low → it was bought, take it off the shopping list
    $bought = !$nowLow && $qty > (float)$i['qty'];
    $db->prepare('UPDATE items SET qty = ?, low_alerted = ?, on_list = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
       ->execute([$qty, $nowLow ? ($alert ? 1 : (int)$i['low_alerted']) : 0, $bought ? 0 : (int)$i['on_list'], $id]);
    if ($alert) {
        $left = $qty > 0 ? hs_num($qty) . ($i['unit'] ? ' ' . $i['unit'] : '') . ' left' : 'none left';
        hs_notify_everyone('low', 'Running low: ' . $i['name'], ucfirst($left) . ' — it is on the shopping list.', 'food.php?view=list', false);
    }
    return hs_item($id);
}

/** "Bought it": back to the normal amount. */
function hs_item_restock(int $id): ?array {
    $i = hs_item($id);
    if (!$i) return null;
    $target = $i['target_qty'] !== null && (float)$i['target_qty'] > (float)$i['min_qty']
        ? (float)$i['target_qty']
        : max((float)$i['qty'], (float)$i['min_qty']) + 1;
    hs_db()->prepare('UPDATE items SET on_list = 0 WHERE id = ?')->execute([$id]);
    return hs_item_set_qty($id, $target);
}

function hs_shopping_list(): array {
    return hs_db()->query('SELECT * FROM items WHERE qty <= min_qty OR on_list = 1 ORDER BY category, name COLLATE NOCASE')->fetchAll();
}

function hs_find_item_by_name(string $name): ?int {
    $st = hs_db()->prepare('SELECT id FROM items WHERE name = ? COLLATE NOCASE LIMIT 1');
    $st->execute([trim($name)]);
    $id = $st->fetchColumn();
    return $id ? (int)$id : null;
}

/** Ingredients of a recipe with stock info. */
function hs_recipe_ingredients(int $rid): array {
    $st = hs_db()->prepare('SELECT ri.*, i.qty AS stock, i.unit AS stock_unit, i.name AS item_name
                            FROM recipe_ingredients ri LEFT JOIN items i ON i.id = ri.item_id
                            WHERE ri.recipe_id = ? ORDER BY ri.sort, ri.id');
    $st->execute([$rid]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        if ($r['item_id'] === null || $r['stock'] === null) $r['have'] = null;           // not tracked in stock
        else $r['have'] = (float)$r['stock'] >= (float)($r['amount'] ?: 0.0001);
        $out[] = $r;
    }
    return $out;
}

function hs_item_card(array $i): string {
    $low = hs_item_low($i);
    $unit = $i['unit'] !== '' ? '<span class="unit">' . e($i['unit']) . '</span>' : '';
    return '<div class="item-card' . ($low ? ' low' : '') . '" data-item="' . (int)$i['id'] . '" data-name="' . e(mb_strtolower($i['name'])) . '" data-cat="' . e($i['category']) . '">'
        . '<a class="item-main" href="item_edit.php?id=' . (int)$i['id'] . '">'
        . '<span class="item-name">' . e($i['name']) . '</span>'
        . '<span class="item-sub">' . e($i['category']) . ($low ? ' <span class="badge low">Low</span>' : '') . '</span>'
        . '</a>'
        . '<div class="stepper">'
        . '<button type="button" class="step" data-step="-' . hs_num(hs_item_step($i)) . '" aria-label="Less ' . e($i['name']) . '">' . hs_icon('minus', 26) . '</button>'
        . '<span class="qty"><b>' . hs_num($i['qty']) . '</b>' . $unit . '</span>'
        . '<button type="button" class="step plus" data-step="' . hs_num(hs_item_step($i)) . '" aria-label="More ' . e($i['name']) . '">' . hs_icon('plus', 26) . '</button>'
        . '</div></div>';
}

function hs_list_row(array $i): string {
    $need = $i['target_qty'] !== null && (float)$i['target_qty'] > (float)$i['qty']
        ? 'buy ' . hs_num((float)$i['target_qty'] - (float)$i['qty']) . ($i['unit'] ? ' ' . $i['unit'] : '')
        : '';
    $meta = [e($i['category']), hs_num($i['qty']) . ($i['unit'] ? ' ' . e($i['unit']) : '') . ' left'];
    if ($need) $meta[] = e($need);
    return '<div class="tick-row shop">'
        . '<button type="button" class="tick" data-bought="' . (int)$i['id'] . '" aria-label="Bought ' . e($i['name']) . '">' . hs_icon('check', 30) . '</button>'
        . '<a class="tick-body" href="item_edit.php?id=' . (int)$i['id'] . '">'
        . '<span class="tick-title">' . e($i['name']) . '</span>'
        . '<span class="tick-meta">' . implode('<i>·</i>', $meta) . '</span></a></div>';
}
