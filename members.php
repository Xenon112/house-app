<?php
require __DIR__ . '/includes/boot.php';
$me = hs_require_admin();
$db = hs_db();
const HS_COLORS = ['#c86a35', '#8a4b2f', '#1f9d5f', '#2f7fd6', '#8b5cf6', '#d6457a', '#0f9aa8', '#b8860b'];

$edit = null;
if (isset($_GET['id'])) {
    $st = $db->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([(int)$_GET['id']]);
    $edit = $st->fetch() ?: null;
}

if (hs_is_post()) {
    hs_check_csrf();
    $id = (int)($_POST['id'] ?? 0);
    if (isset($_POST['delete'])) {
        if ($id === (int)$me['id']) hs_flash("You can't remove yourself.", 'err');
        else { $db->prepare('DELETE FROM users WHERE id = ?')->execute([$id]); hs_flash('Removed.'); }
        hs_redirect('members.php');
    }
    $name = trim((string)($_POST['name'] ?? ''));
    $username = trim((string)($_POST['username'] ?? ''));
    $pass = (string)($_POST['password'] ?? '');
    $role = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'member';
    if ($id === (int)$me['id']) $role = 'admin';   // keep at least yourself as admin
    $color = in_array($_POST['color'] ?? '', HS_COLORS, true) ? $_POST['color'] : HS_COLORS[0];
    $back = 'members.php' . ($id ? '?id=' . $id : '');
    if ($name === '' || !preg_match('/^[\w.\-]{2,30}$/u', $username)) {
        hs_flash('Fill in a name and a username (letters, numbers, . - _).', 'err');
        hs_redirect($back);
    }
    if ((!$id || $pass !== '') && mb_strlen($pass) < 6) {
        hs_flash('The password needs at least 6 characters.', 'err');
        hs_redirect($back);
    }
    try {
        if ($id) {
            $db->prepare('UPDATE users SET name=?, username=?, role=?, color=? WHERE id=?')->execute([$name, $username, $role, $color, $id]);
            if ($pass !== '') {
                $db->prepare('UPDATE users SET pass_hash=? WHERE id=?')->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
                $db->prepare('DELETE FROM remember_tokens WHERE user_id = ?')->execute([$id]);
            }
            hs_flash('Saved.');
        } else {
            $db->prepare('INSERT INTO users (name, username, pass_hash, role, color) VALUES (?,?,?,?,?)')
               ->execute([$name, $username, password_hash($pass, PASSWORD_DEFAULT), $role, $color]);
            hs_flash($name . ' added. They can log in with "' . $username . '" and the password you chose.');
        }
    } catch (PDOException $e) {
        hs_flash('That username is already taken.', 'err');
        hs_redirect($back);
    }
    hs_redirect('members.php');
}

$cur = $edit ?? ['id' => 0, 'name' => '', 'username' => '', 'role' => 'member', 'color' => HS_COLORS[count(hs_members()) % count(HS_COLORS)]];
$colorOpts = [];
foreach (HS_COLORS as $c) $colorOpts[$c] = '';

hs_header('Household', 'settings', ['back' => 'settings.php']);
?>
<div style="max-width:760px">
<section class="panel">
  <?php foreach (hs_members() as $m): ?>
    <div class="member-row">
      <?= hs_avatar((int)$m['id']) ?>
      <div class="grow"><b><?= e($m['name']) ?></b><br><span class="muted"><?= e($m['username']) ?><?= $m['role'] === 'admin' ? ' · admin' : '' ?></span></div>
      <a class="btn secondary" href="members.php?id=<?= (int)$m['id'] ?>"><?= hs_icon('edit', 20) ?> Edit</a>
    </div>
  <?php endforeach; ?>
</section>

<form method="post" class="panel">
  <?= hs_csrf_field() ?><input type="hidden" name="id" value="<?= (int)$cur['id'] ?>">
  <h2 style="margin-top:0"><?= $edit ? 'Edit ' . e($edit['name']) : 'Add a person' ?></h2>
  <div class="field-row">
    <div><label class="lbl" for="n">Name</label><input type="text" id="n" name="name" value="<?= e($cur['name']) ?>" required maxlength="40"></div>
    <div><label class="lbl" for="un">Username (to log in)</label><input type="text" id="un" name="username" value="<?= e($cur['username']) ?>" autocapitalize="none" required maxlength="30"></div>
  </div>
  <label class="lbl" for="pw">Password<?= $edit ? ' (leave empty to keep)' : '' ?></label>
  <input type="password" id="pw" name="password" autocomplete="new-password" <?= $edit ? '' : 'required' ?> minlength="6">
  <label class="lbl">Colour</label>
  <div class="chips color-chips">
    <?php foreach (HS_COLORS as $k => $c): ?>
      <input type="radio" name="color" id="col<?= $k ?>" value="<?= $c ?>" <?= $c === $cur['color'] ? 'checked' : '' ?>><label for="col<?= $k ?>" style="--c:<?= $c ?>" aria-label="Colour <?= $k + 1 ?>"></label>
    <?php endforeach; ?>
  </div>
  <label class="lbl">Can manage people?</label>
  <?= hs_chips('role', ['member' => 'No, normal member', 'admin' => 'Yes, admin'], $cur['role']) ?>
  <div class="btn-row">
    <button class="btn big grow" type="submit"><?= hs_icon('check', 22) ?> <?= $edit ? 'Save' : 'Add person' ?></button>
    <?php if ($edit && (int)$edit['id'] !== (int)$me['id']): ?>
      <button class="btn big ghost-danger" type="submit" name="delete" value="1" data-confirm="Remove <?= e($edit['name']) ?>? Their chores become 'anyone'."><?= hs_icon('trash', 22) ?></button>
    <?php endif; ?>
    <?php if ($edit): ?><a class="btn big secondary" href="members.php">Cancel</a><?php endif; ?>
  </div>
</form>
</div>
<?php hs_footer();
