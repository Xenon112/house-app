<?php
require __DIR__ . '/includes/boot.php';
$u = hs_require_login();
$db = hs_db();
$id = (int)($_GET['id'] ?? 0);
$c = $id ? hs_chore($id) : null;
if ($id && !$c) hs_redirect('chores.php');

if (hs_is_post()) {
    hs_check_csrf();
    if (isset($_POST['delete']) && $c) {
        $db->prepare('DELETE FROM chores WHERE id = ?')->execute([$id]);
        hs_flash('Chore deleted.');
        hs_redirect('chores.php');
    }
    $title = trim((string)($_POST['title'] ?? ''));
    $room = trim((string)($_POST['room'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));
    $assigned = (int)($_POST['assigned_to'] ?? 0);
    $assigned = isset(hs_members()[$assigned]) ? $assigned : null;
    $rotate = !empty($_POST['rotate']) ? 1 : 0;
    [$rtype, $revery] = array_pad(explode(':', (string)($_POST['repeat'] ?? 'none:1')), 2, '1');
    if ($rtype === 'custom') {
        $rtype = in_array($_POST['custom_unit'] ?? '', ['days', 'weeks', 'months'], true) ? $_POST['custom_unit'] : 'days';
        $revery = max(1, min(365, (int)($_POST['custom_every'] ?? 1)));
    }
    if (!in_array($rtype, ['none', 'days', 'weeks', 'months'], true)) $rtype = 'none';
    $revery = max(1, (int)$revery);
    $due = (string)($_POST['due_date'] ?? '');
    $due = preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) ? $due : hs_today();
    if ($rotate && !$assigned) $assigned = array_key_first(hs_members());

    if ($title === '') {
        hs_flash('Give the chore a name.', 'err');
    } else {
        if ($c) {
            $db->prepare('UPDATE chores SET title=?, room=?, notes=?, assigned_to=?, rotate=?, repeat_type=?, repeat_every=?, due_date=?, active=1, reminded_on=CASE WHEN due_date=? THEN reminded_on ELSE NULL END WHERE id=?')
               ->execute([$title, $room ?: null, $notes ?: null, $assigned, $rotate, $rtype, $revery, $due, $due, $id]);
        } else {
            $db->prepare('INSERT INTO chores (title, room, notes, assigned_to, rotate, repeat_type, repeat_every, due_date, created_by) VALUES (?,?,?,?,?,?,?,?,?)')
               ->execute([$title, $room ?: null, $notes ?: null, $assigned, $rotate, $rtype, $revery, $due, $u['id']]);
            $id = (int)$db->lastInsertId();
        }
        // Tell the person when a chore is newly given to them
        $before = $c ? ($c['assigned_to'] !== null ? (int)$c['assigned_to'] : null) : null;
        if ($assigned && $assigned !== $before) {
            hs_notify([$assigned], 'assign', 'New chore for you: ' . $title,
                $u['name'] . ' gave it to you · due ' . hs_due_phrase($due), 'chores.php?who=me');
        }
        hs_flash($c ? 'Chore saved.' : 'Chore added.');
        hs_redirect('chores.php');
    }
}

$cur = $c ?? ['title' => '', 'room' => '', 'notes' => '', 'assigned_to' => null, 'rotate' => 0, 'repeat_type' => 'none', 'repeat_every' => 1, 'due_date' => hs_today()];
$repKey = $cur['repeat_type'] . ':' . $cur['repeat_every'];
$isCustom = !isset(HS_REPEATS[$repKey]);
$repeatOpts = HS_REPEATS + ['custom' => 'Custom…'];

$who = ['0' => hs_avatar(null, 'sm') . ' Anyone'];
foreach (hs_members() as $mid => $m) $who[$mid] = hs_avatar($mid, 'sm') . ' ' . e($m['name']);

$rooms = $db->query("SELECT DISTINCT room FROM chores WHERE room IS NOT NULL AND room <> '' ORDER BY room")->fetchAll(PDO::FETCH_COLUMN);
$rooms = array_unique(array_merge($rooms, ['Kitchen', 'Bathroom', 'Living room', 'Bedroom', 'Garden', 'Laundry']));

$tomorrow = date('Y-m-d', strtotime('+1 day'));
hs_header($c ? 'Edit chore' : 'New chore', 'chores', ['back' => 'chores.php']);
?>
<form method="post" class="panel" style="max-width:760px">
  <?= hs_csrf_field() ?>
  <label class="lbl" for="title">What needs doing?</label>
  <input type="text" id="title" name="title" value="<?= e($cur['title']) ?>" placeholder="e.g. Take out the bins" required autofocus maxlength="120">

  <label class="lbl">Who does it?</label>
  <?= hs_chips('assigned_to', $who, (string)($cur['assigned_to'] ?? '0')) ?>
  <label class="switch-row" style="margin-top:.4rem"><span>Take turns — passes to the next person each time it's done</span>
    <input type="checkbox" name="rotate" value="1" <?= $cur['rotate'] ? 'checked' : '' ?>></label>

  <label class="lbl">How often?</label>
  <?= hs_chips('repeat', $repeatOpts, $isCustom ? 'custom' : $repKey) ?>
  <div class="field-row" id="customRepeat" style="<?= $isCustom ? '' : 'display:none' ?>">
    <div><label class="lbl" for="ce">Every</label><input type="number" id="ce" name="custom_every" min="1" max="365" value="<?= (int)$cur['repeat_every'] ?>"></div>
    <div><label class="lbl" for="cu">Unit</label><select id="cu" name="custom_unit">
      <?php foreach (['days', 'weeks', 'months'] as $un): ?><option <?= $cur['repeat_type'] === $un ? 'selected' : '' ?>><?= $un ?></option><?php endforeach; ?>
    </select></div>
  </div>

  <label class="lbl" for="due">When is it due<?= $c && $c['repeat_type'] !== 'none' ? ' next' : '' ?>?</label>
  <div class="chips" style="margin-bottom:.5rem">
    <button type="button" class="btn secondary" data-set-date="<?= hs_today() ?>">Today</button>
    <button type="button" class="btn secondary" data-set-date="<?= $tomorrow ?>">Tomorrow</button>
  </div>
  <input type="date" id="due" name="due_date" value="<?= e($cur['due_date'] ?? hs_today()) ?>">

  <label class="lbl" for="room">Room (optional)</label>
  <input type="text" id="room" name="room" value="<?= e($cur['room']) ?>" list="rooms" maxlength="60">
  <datalist id="rooms"><?php foreach ($rooms as $r) echo '<option value="' . e($r) . '">'; ?></datalist>

  <label class="lbl" for="notes">Notes (optional)</label>
  <textarea id="notes" name="notes" maxlength="1000"><?= e($cur['notes']) ?></textarea>

  <div class="btn-row">
    <button class="btn big grow" type="submit"><?= hs_icon('check', 22) ?> Save</button>
    <?php if ($c): ?><button class="btn big ghost-danger" type="submit" name="delete" value="1" data-confirm="Delete this chore?"><?= hs_icon('trash', 22) ?></button><?php endif; ?>
  </div>
</form>
<script>
document.querySelectorAll('input[name=repeat]').forEach(r => r.addEventListener('change', () => {
  document.getElementById('customRepeat').style.display = r.value === 'custom' && r.checked ? '' : 'none';
}));
document.querySelectorAll('[data-set-date]').forEach(b => b.addEventListener('click', () => { document.getElementById('due').value = b.dataset.setDate; }));
</script>
<?php hs_footer();
