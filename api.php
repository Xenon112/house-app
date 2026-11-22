<?php
// Quick actions from the tick buttons and +/− steppers (JSON, POST only).
require __DIR__ . '/includes/boot.php';
if (!hs_user()) hs_json(['error' => 'Please log in again.'], 401);
if (!hs_is_post()) hs_json(['error' => 'POST only'], 405);
hs_check_csrf();

$a = (string)($_POST['action'] ?? '');
$id = (int)($_POST['id'] ?? 0);

switch ($a) {
    case 'chore_done':
        $c = hs_chore($id);
        $log = hs_chore_done($id, hs_uid());
        if (!$log) hs_json(['error' => 'Already done.'], 409);
        $c2 = hs_chore($id);
        $msg = $c2['active'] ? 'Done! Next ' . hs_due_phrase($c2['due_date']) : 'Done!';
        if ($c2['active'] && $c['rotate'] && $c2['assigned_to']) $msg .= ' · ' . (hs_members()[(int)$c2['assigned_to']]['name'] ?? '') . "'s turn";
        if ($c['assigned_to'] !== null && (int)$c['assigned_to'] !== hs_uid()) $msg = 'Thanks for doing ' . (hs_members()[(int)$c['assigned_to']]['name'] ?? '') . '\'s chore! ' . $msg;
        hs_json(['ok' => true, 'log' => $log, 'msg' => $msg]);

    case 'chore_take':
        if (!hs_chore_take($id, hs_uid())) hs_json(['error' => 'It is already yours.'], 409);
        hs_json(['ok' => true, 'msg' => 'It\'s yours now — thanks!']);

    case 'chore_undo':
        hs_json(['ok' => hs_chore_undo($id)]);

    case 'item_step':
        $i = hs_item($id);
        if (!$i) hs_json(['error' => 'Not found'], 404);
        $step = (float)($_POST['step'] ?? 0);
        $i = hs_item_set_qty($id, (float)$i['qty'] + ($step < 0 ? -1 : 1) * hs_item_step($i));
        hs_json(['ok' => true, 'qty' => hs_num($i['qty']), 'low' => hs_item_low($i)]);

    case 'item_bought':
        $before = hs_item($id);
        $i = hs_item_restock($id);
        if (!$i) hs_json(['error' => 'Not found'], 404);
        hs_notify_everyone('done', hs_user()['name'] . ' bought ' . $i['name'], 'Now ' . hs_num($i['qty']) . ($i['unit'] ? ' ' . $i['unit'] : '') . ' in stock — no need to buy it.', 'food.php');
        hs_json(['ok' => true, 'msg' => 'Bought ' . $i['name'] . ' — now ' . hs_num($i['qty']) . ($i['unit'] ? ' ' . $i['unit'] : ''),
                 'undo' => ['id' => $id, 'qty' => (float)$before['qty'], 'on_list' => (int)$before['on_list']]]);

    case 'item_set':   // undo for "bought"
        $i = hs_item($id);
        if (!$i) hs_json(['error' => 'Not found'], 404);
        hs_db()->prepare('UPDATE items SET qty = ?, on_list = ?, low_alerted = 1 WHERE id = ?')
            ->execute([max(0, (float)($_POST['qty'] ?? 0)), (int)!empty($_POST['on_list']), $id]);
        hs_json(['ok' => true]);

    case 'item_list':  // put on / take off the shopping list
        hs_db()->prepare('UPDATE items SET on_list = ? WHERE id = ?')->execute([(int)!empty($_POST['on']), $id]);
        hs_json(['ok' => true]);
}
hs_json(['error' => 'Unknown action'], 400);
