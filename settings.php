<?php
require __DIR__ . '/includes/boot.php';
$u = hs_require_login();
$uid = (int)$u['id'];
$db = hs_db();

if (hs_is_post()) {
    hs_check_csrf();
    $act = (string)($_POST['act'] ?? '');
    if ($act === 'alerts') {
        foreach (array_keys(HS_ALERT_KINDS) as $k) hs_set_user_setting($uid, 'alert_' . $k, !empty($_POST['alert_' . $k]) ? '1' : '0');
        hs_flash('Alert choices saved.');
    } elseif ($act === 'password') {
        $st = $db->prepare('SELECT pass_hash FROM users WHERE id = ?');
        $st->execute([$uid]);
        $new = (string)($_POST['new'] ?? '');
        if (!password_verify((string)($_POST['old'] ?? ''), (string)$st->fetchColumn())) hs_flash('Your current password is not right.', 'err');
        elseif (mb_strlen($new) < 6) hs_flash('The new password needs at least 6 characters.', 'err');
        else {
            $db->prepare('UPDATE users SET pass_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $uid]);
            hs_flash('Password changed.');
        }
    }
    hs_redirect('settings.php');
}

$devices = $db->prepare('SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ?');
$devices->execute([$uid]);
$devices = (int)$devices->fetchColumn();

hs_header('Settings', 'settings');
?>
<div style="max-width:760px">

<section class="panel" id="alerts">
  <h2 style="margin-top:0"><?= hs_icon('phone', 22) ?> Phone alerts</h2>
  <p class="muted" id="pushStatus">Checking this device…</p>
  <div class="btn-row" style="margin-top:.6rem">
    <button type="button" class="btn big" id="pushOn" hidden><?= hs_icon('bell', 22) ?> Turn on alerts on this device</button>
    <button type="button" class="btn secondary" id="pushTest" hidden>Send a test alert</button>
    <button type="button" class="btn secondary" id="pushOff" hidden>Turn off on this device</button>
  </div>
  <p class="muted" style="font-size:.88rem;margin-top:1rem">
    <b>iPhone / iPad:</b> open this site in Safari, tap Share → <i>Add to Home Screen</i>, open the app from the home screen, then turn alerts on here.<br>
    <b>Android / computer:</b> just tap the button and allow notifications.
    <?= $devices ? '<br>You get alerts on ' . $devices . ' device' . ($devices > 1 ? 's' : '') . '.' : '' ?>
  </p>

  <form method="post" style="margin-top:.6rem">
    <?= hs_csrf_field() ?><input type="hidden" name="act" value="alerts">
    <label class="lbl">Alert me when…</label>
    <?php foreach (HS_ALERT_KINDS as $k => $label): ?>
      <label class="switch-row"><span><?= e($label) ?></span>
        <input type="checkbox" name="alert_<?= $k ?>" value="1" <?= hs_alert_on($uid, $k) ? 'checked' : '' ?>></label>
    <?php endforeach; ?>
    <button class="btn" type="submit" style="margin-top:.8rem">Save</button>
  </form>
</section>

<section class="panel">
  <h2 style="margin-top:0">Look</h2>
  <div class="chips" id="themeChips">
    <input type="radio" name="theme" id="th_auto" value="auto"><label for="th_auto">Auto</label>
    <input type="radio" name="theme" id="th_light" value="light"><label for="th_light"><?= hs_icon('sun', 18) ?> Light</label>
    <input type="radio" name="theme" id="th_dark" value="dark"><label for="th_dark"><?= hs_icon('moon', 18) ?> Dark</label>
  </div>
</section>

<?php if (hs_is_admin()): ?>
<section class="panel">
  <h2 style="margin-top:0"><?= hs_icon('users', 22) ?> Household</h2>
  <p class="muted">Add everyone who lives here so you can give them chores.</p>
  <a class="btn secondary" href="members.php">Manage people</a>
</section>
<?php endif; ?>

<section class="panel">
  <h2 style="margin-top:0">My password</h2>
  <form method="post">
    <?= hs_csrf_field() ?><input type="hidden" name="act" value="password">
    <div class="field-row">
      <div><label class="lbl" for="old">Current</label><input type="password" id="old" name="old" autocomplete="current-password" required></div>
      <div><label class="lbl" for="new">New</label><input type="password" id="new" name="new" autocomplete="new-password" minlength="6" required></div>
    </div>
    <button class="btn" type="submit" style="margin-top:.8rem">Change password</button>
  </form>
</section>

<a class="btn ghost-danger block big" href="logout.php"><?= hs_icon('logout', 22) ?> Log out <?= e($u['name']) ?></a>
</div>
<?php hs_footer();
