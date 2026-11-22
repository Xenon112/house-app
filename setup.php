<?php
// First start: create the admin account. Needs the setup_key from config.php, and only works while there are no accounts.
require __DIR__ . '/includes/boot.php';
if (hs_users_count() > 0) hs_redirect('login.php');

$err = '';
if (hs_is_post()) {
    hs_check_csrf();
    $key = (string)($_POST['setup_key'] ?? '');
    $name = trim((string)($_POST['name'] ?? ''));
    $user = trim((string)($_POST['username'] ?? ''));
    $pass = (string)($_POST['password'] ?? '');
    $cfgKey = (string)(hs_config()['setup_key'] ?? '');
    if ($cfgKey === '' || str_starts_with($cfgKey, 'CHANGE-ME')) {
        $err = 'Set your own setup_key in config.php first.';
    } elseif (!hash_equals($cfgKey, $key)) {
        $err = 'The setup key is not right (see config.php).';
    } elseif ($name === '' || !preg_match('/^[\w.\-]{2,30}$/u', $user)) {
        $err = 'Fill in your name and a username (letters, numbers, . - _).';
    } elseif (mb_strlen($pass) < 6) {
        $err = 'The password needs at least 6 characters.';
    } else {
        hs_db()->prepare("INSERT INTO users (username, name, pass_hash, role, color) VALUES (?,?,?, 'admin', '#c86a35')")
            ->execute([$user, $name, password_hash($pass, PASSWORD_DEFAULT)]);
        hs_login_user((int)hs_db()->lastInsertId(), true);
        hs_flash('Welcome! Add the rest of the house under Settings → Household.');
        hs_redirect('index.php');
    }
}
hs_head('Set up');
?>
<body>
<div class="login-wrap">
  <form class="login-box" method="post">
    <?= hs_csrf_field() ?>
    <div class="logo"><img src="assets/icons/logo.svg" width="64" height="64" alt=""></div>
    <h1>Set up your house</h1>
    <p class="sub">Create the first account. You can add everyone else afterwards.</p>
    <?php if ($err): ?><div class="flash err"><?= e($err) ?></div><?php endif; ?>
    <label class="lbl" for="k">Setup key (from config.php)</label>
    <input type="password" id="k" name="setup_key" required>
    <label class="lbl" for="n">Your name</label>
    <input type="text" id="n" name="name" value="<?= e($_POST['name'] ?? '') ?>" required maxlength="40">
    <label class="lbl" for="u">Username</label>
    <input type="text" id="u" name="username" value="<?= e($_POST['username'] ?? '') ?>" autocapitalize="none" required maxlength="30">
    <label class="lbl" for="p">Password</label>
    <input type="password" id="p" name="password" autocomplete="new-password" required minlength="6">
    <button class="btn big block" type="submit" style="margin-top:1.2rem">Create account</button>
  </form>
</div>
</body></html>
