<?php
// Login: sessions + "keep me signed in" cookie (so a kitchen tablet stays logged in), CSRF, login rate limit.

const HS_REMEMBER_COOKIE = 'hs_remember';
const HS_REMEMBER_DAYS = 90;

function hs_is_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function hs_session_start(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('hs_sess');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'secure' => hs_is_https(), 'httponly' => true, 'samesite' => 'Lax',
    ]);
    session_start();
}

function hs_user(): ?array {
    static $user = false;
    if ($user !== false) return $user;
    hs_session_start();
    $uid = (int)($_SESSION['uid'] ?? 0);
    if ($uid < 1) $uid = hs_remember_restore();
    $user = null;
    if ($uid > 0) {
        $st = hs_db()->prepare('SELECT id, username, name, role, color FROM users WHERE id = ?');
        $st->execute([$uid]);
        $user = $st->fetch() ?: null;
        if (!$user) unset($_SESSION['uid']);
    }
    return $user;
}

function hs_uid(): int { return (int)(hs_user()['id'] ?? 0); }
function hs_is_admin(): bool { return (hs_user()['role'] ?? '') === 'admin'; }

function hs_require_login(): array {
    $u = hs_user();
    if (!$u) {
        if (hs_users_count() === 0) hs_redirect('setup.php');
        hs_redirect('login.php');
    }
    return $u;
}

function hs_require_admin(): array {
    $u = hs_require_login();
    if ($u['role'] !== 'admin') {
        http_response_code(403);
        exit('Only the house admin can open this page.');
    }
    return $u;
}

function hs_users_count(): int {
    return (int)hs_db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
}

function hs_login_user(int $uid, bool $remember): void {
    hs_session_start();
    session_regenerate_id(true);
    $_SESSION['uid'] = $uid;
    if ($remember) {
        $sel = bin2hex(random_bytes(9));
        $val = bin2hex(random_bytes(32));
        hs_db()->prepare('INSERT INTO remember_tokens (user_id, selector, validator_hash, expires_at) VALUES (?,?,?,?)')
            ->execute([$uid, $sel, hash('sha256', $val), time() + HS_REMEMBER_DAYS * 86400]);
        hs_set_remember_cookie($sel . ':' . $val, time() + HS_REMEMBER_DAYS * 86400);
    }
}

function hs_set_remember_cookie(string $value, int $expires): void {
    setcookie(HS_REMEMBER_COOKIE, $value, [
        'expires' => $expires, 'path' => '/', 'secure' => hs_is_https(), 'httponly' => true, 'samesite' => 'Lax',
    ]);
}

function hs_remember_restore(): int {
    $c = (string)($_COOKIE[HS_REMEMBER_COOKIE] ?? '');
    if (!preg_match('/^([a-f0-9]{18}):([a-f0-9]{64})$/', $c, $m)) return 0;
    $db = hs_db();
    $st = $db->prepare('SELECT id, user_id, validator_hash, expires_at FROM remember_tokens WHERE selector = ?');
    $st->execute([$m[1]]);
    $t = $st->fetch();
    if (!$t || $t['expires_at'] < time() || !hash_equals($t['validator_hash'], hash('sha256', $m[2]))) {
        hs_set_remember_cookie('', time() - 3600);
        return 0;
    }
    // Rotate the token on every use. The old one stays valid for 2 more minutes so two requests at the
    // same moment (page + phone-alert worker) don't log the device out.
    $db->prepare('UPDATE remember_tokens SET expires_at = MIN(expires_at, ?) WHERE id = ?')->execute([time() + 120, $t['id']]);
    $db->prepare('DELETE FROM remember_tokens WHERE expires_at < ?')->execute([time()]);
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$t['user_id'];
    $sel = bin2hex(random_bytes(9));
    $val = bin2hex(random_bytes(32));
    $db->prepare('INSERT INTO remember_tokens (user_id, selector, validator_hash, expires_at) VALUES (?,?,?,?)')
        ->execute([$t['user_id'], $sel, hash('sha256', $val), time() + HS_REMEMBER_DAYS * 86400]);
    hs_set_remember_cookie($sel . ':' . $val, time() + HS_REMEMBER_DAYS * 86400);
    return (int)$t['user_id'];
}

function hs_logout(): void {
    hs_session_start();
    $c = (string)($_COOKIE[HS_REMEMBER_COOKIE] ?? '');
    if (preg_match('/^([a-f0-9]{18}):/', $c, $m)) {
        hs_db()->prepare('DELETE FROM remember_tokens WHERE selector = ?')->execute([$m[1]]);
    }
    hs_set_remember_cookie('', time() - 3600);
    $_SESSION = [];
    session_destroy();
}

/* ---------- Login rate limit: 8 failed tries per IP in 15 minutes ---------- */
function hs_client_ip(): string { return (string)($_SERVER['REMOTE_ADDR'] ?? '0'); }

function hs_login_blocked(): bool {
    $db = hs_db();
    $db->prepare('DELETE FROM login_attempts WHERE at < ?')->execute([time() - 900]);
    $st = $db->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ?');
    $st->execute([hs_client_ip()]);
    return (int)$st->fetchColumn() >= 8;
}

function hs_login_failed(): void {
    hs_db()->prepare('INSERT INTO login_attempts (ip, at) VALUES (?, ?)')->execute([hs_client_ip(), time()]);
}

/* ---------- CSRF ---------- */
function hs_csrf_token(): string {
    hs_session_start();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
    return $_SESSION['csrf'];
}

function hs_csrf_field(): string {
    return '<input type="hidden" name="_csrf" value="' . e(hs_csrf_token()) . '">';
}

function hs_check_csrf(): void {
    $t = (string)($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!hash_equals(hs_csrf_token(), $t)) {
        http_response_code(419);
        exit('This page expired. Go back, reload and try again.');
    }
}

function hs_is_post(): bool { return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'; }
