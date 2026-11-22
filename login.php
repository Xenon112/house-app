<?php
require __DIR__ . '/includes/boot.php';
if (hs_users_count() === 0) hs_redirect('setup.php');
if (hs_user()) hs_redirect('index.php');

$err = '';
$username = '';
if (hs_is_post()) {
    hs_check_csrf();
    $username = trim((string)($_POST['username'] ?? ''));
    $pass = (string)($_POST['password'] ?? '');
    if (hs_login_blocked()) {
        $err = 'Too many tries. Wait 15 minutes and try again.';
    } else {
        $st = hs_db()->prepare('SELECT id, pass_hash FROM users WHERE username = ?');
        $st->execute([$username]);
        $row = $st->fetch();
        if ($row && password_verify($pass, $row['pass_hash'])) {
            if (password_needs_rehash($row['pass_hash'], PASSWORD_DEFAULT)) {
                hs_db()->prepare('UPDATE users SET pass_hash = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $row['id']]);
            }
            hs_login_user((int)$row['id'], !empty($_POST['remember']));
            hs_redirect('index.php');
        }
        hs_login_failed();
        $err = 'Wrong name or password.';
    }
}
$app = hs_config()['app_name'] ?? 'Our House';
hs_head('Log in');
?>
<body>
<div class="login-wrap">
  <form class="login-box" method="post">
    <?= hs_csrf_field() ?>
    <div class="logo"><img src="assets/icons/logo.svg" width="64" height="64" alt=""></div>
    <h1><?= e($app) ?></h1>
    <p class="sub">Chores, food and recipes for the whole house</p>
    <?php if ($err): ?><div class="flash err"><?= e($err) ?></div><?php endif; ?>
    <label class="lbl" for="u">Username</label>
    <input type="text" id="u" name="username" value="<?= e($username) ?>" autocomplete="username" autocapitalize="none" required autofocus>
    <label class="lbl" for="p">Password</label>
    <input type="password" id="p" name="password" autocomplete="current-password" required>
    <label class="switch-row" style="margin-top:.6rem"><span>Keep me signed in on this device</span>
      <input type="checkbox" name="remember" value="1" checked></label>
    <button class="btn big block" type="submit" style="margin-top:1rem">Log in</button>
  </form>
</div>
</body></html>
