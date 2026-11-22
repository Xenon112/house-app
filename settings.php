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
    } elseif ($act === 'tg_token' && hs_is_admin()) {
        $r = hs_tg_save_token((string)($_POST['token'] ?? ''));
        hs_flash($r['ok'] ? 'House bot @' . $r['bot'] . ' connected. Now connect your own Telegram below.' : 'Telegram: ' . $r['error'], $r['ok'] ? 'ok' : 'err');
    } elseif ($act === 'tg_remove' && hs_is_admin()) {
        hs_set_setting('tg_token', null);
        hs_set_setting('tg_bot', null);
        $db->exec("DELETE FROM user_settings WHERE k IN ('tg_chat', 'tg_name', 'tg_code')");
        hs_flash('Telegram bot removed.');
    } elseif ($act === 'tg_check') {
        hs_remember_app_url();
        $r = hs_tg_check_links();
        if (!$r['ok']) hs_flash('Telegram: ' . $r['error'], 'err');
        elseif (isset($r['linked'][$uid])) hs_flash('Telegram connected! Check your messages.');
        else hs_flash('Not found yet. Open the bot with button 1, press Start (or send any message after it), then try again.', 'err');
    } elseif ($act === 'tg_test') {
        $ok = hs_tg_send($uid, '🔔 <b>Telegram alerts are working</b>' . "\n" . 'You will get your house alerts here.');
        hs_flash($ok ? 'Test message sent.' : 'Could not send. Disconnect and connect again.', $ok ? 'ok' : 'err');
    } elseif ($act === 'tg_unlink') {
        hs_set_user_setting($uid, 'tg_chat', null);
        hs_set_user_setting($uid, 'tg_name', null);
        hs_flash('Telegram disconnected.');
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
$tgBot = hs_tg_bot();
$tgChat = hs_tg_chat($uid);
$tgName = (string)hs_user_setting($uid, 'tg_name', '');

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
</section>

<section class="panel" id="telegram">
  <h2 style="margin-top:0"><?= hs_icon('telegram', 22) ?> Telegram</h2>
  <?php if ($tgBot === ''): ?>
    <?php if (hs_is_admin()): ?>
      <p class="muted">Get every alert as a Telegram message too. Set up one bot for the whole house:</p>
      <ol class="steps-list">
        <li>In Telegram, open <b>@BotFather</b> and send <code>/newbot</code>.</li>
        <li>Give it a name (e.g. <i><?= e(hs_config()['app_name'] ?? 'Our House') ?></i>) and a username ending in <i>bot</i>.</li>
        <li>BotFather replies with a <b>token</b> like <code>123456789:ABC…</code> — paste it here.</li>
      </ol>
      <form method="post" class="field-row" style="align-items:end">
        <?= hs_csrf_field() ?><input type="hidden" name="act" value="tg_token">
        <div><label class="lbl" for="tgt">Bot token</label><input type="password" id="tgt" name="token" autocomplete="off" required></div>
        <div><button class="btn big block" type="submit">Connect bot</button></div>
      </form>
    <?php else: ?>
      <p class="muted" style="margin:0">Not set up yet — ask the house admin to add a Telegram bot in Settings.</p>
    <?php endif; ?>
  <?php elseif ($tgChat !== ''): ?>
    <p><b style="color:var(--ok)">✓ Connected</b><?= $tgName !== '' ? ' as ' . e($tgName) : '' ?> — your alerts also arrive from <a href="https://t.me/<?= e($tgBot) ?>" target="_blank" rel="noopener">@<?= e($tgBot) ?></a>.</p>
    <div class="btn-row">
      <form method="post"><?= hs_csrf_field() ?><input type="hidden" name="act" value="tg_test"><button class="btn secondary" type="submit">Send a test message</button></form>
      <form method="post"><?= hs_csrf_field() ?><input type="hidden" name="act" value="tg_unlink"><button class="btn ghost-danger" type="submit">Disconnect</button></form>
    </div>
  <?php else: ?>
    <p class="muted">Get your alerts as Telegram messages. Two taps:</p>
    <div class="btn-row" style="margin-top:.4rem">
      <a class="btn big" href="https://t.me/<?= e($tgBot) ?>?start=<?= e(hs_tg_code($uid)) ?>" target="_blank" rel="noopener"><?= hs_icon('telegram', 22) ?> 1. Open Telegram and press Start</a>
      <form method="post"><?= hs_csrf_field() ?><input type="hidden" name="act" value="tg_check"><button class="btn big secondary" type="submit">2. Done — connect me</button></form>
    </div>
  <?php endif; ?>
  <?php if ($tgBot !== '' && hs_is_admin()): ?>
    <form method="post" style="margin-top:1rem"><?= hs_csrf_field() ?><input type="hidden" name="act" value="tg_remove">
      <span class="muted" style="font-size:.88rem">House bot: @<?= e($tgBot) ?> · </span>
      <button class="linkish" type="submit" data-confirm="Remove the house bot? Everyone's Telegram alerts stop.">remove bot</button></form>
  <?php endif; ?>
</section>

<section class="panel">
  <h2 style="margin-top:0"><?= hs_icon('bell', 22) ?> What to alert me about</h2>
  <p class="muted" style="margin-top:-.2rem">For phone alerts and Telegram. Everything also shows under Alerts in the app.</p>
  <form method="post">
    <?= hs_csrf_field() ?><input type="hidden" name="act" value="alerts">
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
