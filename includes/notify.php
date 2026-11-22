<?php
/**
 * Alerts: an in-app list (bell) + phone notifications (web push).
 *
 * Same approach as the Refurbisch dashboard: the push carries no content, it only wakes the
 * service worker (sw.js), which asks push.php?action=next for this device's next alert and shows it.
 * So only a VAPID signature (ES256) is needed, no message encryption and no extra libraries.
 */

const HS_ALERT_KINDS = [
    'assign' => 'A chore is given to me',
    'due'    => 'My chores are due (daily reminder)',
    'low'    => 'Food is running low',
];

function hs_alert_on(int $uid, string $kind): bool {
    return hs_user_setting($uid, 'alert_' . $kind, '1') === '1';
}

/**
 * Create an alert for these people. Push is sent after the page has been delivered.
 * $skipSelf: don't alert the person who did the action.
 */
function hs_notify(array $userIds, string $kind, string $title, string $body = '', string $url = 'index.php', bool $skipSelf = true): void {
    $db = hs_db();
    $me = function_exists('hs_uid') ? hs_uid() : 0;
    $ins = $db->prepare('INSERT INTO notifications (user_id, kind, title, body, url, push) VALUES (?,?,?,?,?,?)');
    foreach (array_unique(array_map('intval', $userIds)) as $uid) {
        if ($uid < 1 || ($skipSelf && $uid === $me)) continue;
        $push = hs_alert_on($uid, $kind) ? 1 : 0;
        $ins->execute([$uid, $kind, $title, $body, $url, $push]);
        if ($push) hs_push_queue($uid);
    }
}

function hs_notify_everyone(string $kind, string $title, string $body = '', string $url = 'index.php', bool $skipSelf = true): void {
    hs_notify(array_keys(hs_members()), $kind, $title, $body, $url, $skipSelf);
}

function hs_unread_count(int $uid): int {
    $st = hs_db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL');
    $st->execute([$uid]);
    return (int)$st->fetchColumn();
}

/* ---------- Push queue: sent at the end of the request so ticking stays instant ---------- */
function hs_push_queue(?int $uid = null): void {
    static $queue = [];
    static $registered = false;
    if ($uid !== null) {
        $queue[$uid] = true;
        if (!$registered) {
            $registered = true;
            register_shutdown_function(function () use (&$queue) {
                if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
                foreach (array_keys($queue) as $u) {
                    try { hs_push_user(hs_db(), (int)$u); } catch (Throwable $e) {}
                }
            });
        }
    }
}

/* ---------- Web push (VAPID) ---------- */
function hs_b64u(string $bin): string {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

/** ['private' => PEM, 'public' => base64url 65-byte P-256 point], created once. */
function hs_push_vapid(): ?array {
    $pem = (string)hs_setting('vapid_private_pem', '');
    $pub = (string)hs_setting('vapid_public_b64u', '');
    if ($pem !== '' && $pub !== '') return ['private' => $pem, 'public' => $pub];
    if (!function_exists('openssl_pkey_new')) return null;
    $opts = ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC];
    // Windows PHP builds need to be told where openssl.cnf is (Linux servers find it themselves)
    if (getenv('OPENSSL_CONF') === false && is_file($cnf = dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf')) $opts['config'] = $cnf;
    $key = @openssl_pkey_new($opts);
    if (!$key) return null;
    $det = openssl_pkey_get_details($key);
    if (empty($det['ec']['x']) || empty($det['ec']['y'])) return null;
    $pub = hs_b64u("\x04" . str_pad($det['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($det['ec']['y'], 32, "\0", STR_PAD_LEFT));
    if (!openssl_pkey_export($key, $pem, null, $opts)) return null;
    hs_set_setting('vapid_private_pem', $pem);
    hs_set_setting('vapid_public_b64u', $pub);
    return ['private' => $pem, 'public' => $pub];
}

/** openssl_sign gives DER; JWT ES256 needs raw 64-byte r||s. */
function hs_der_to_raw(string $der): ?string {
    $pos = 0;
    if (ord($der[$pos++]) !== 0x30) return null;
    $len = ord($der[$pos++]);
    if ($len & 0x80) $pos += $len & 0x7f;
    $parts = [];
    for ($i = 0; $i < 2; $i++) {
        if (ord($der[$pos++]) !== 0x02) return null;
        $l = ord($der[$pos++]);
        $v = ltrim(substr($der, $pos, $l), "\0");
        $pos += $l;
        if (strlen($v) > 32) return null;
        $parts[] = str_pad($v, 32, "\0", STR_PAD_LEFT);
    }
    return $parts[0] . $parts[1];
}

function hs_push_jwt(string $endpoint, array $vapid): ?string {
    $u = parse_url($endpoint);
    if (empty($u['scheme']) || empty($u['host'])) return null;
    $head = hs_b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $body = hs_b64u(json_encode([
        'aud' => $u['scheme'] . '://' . $u['host'],
        'exp' => time() + 12 * 3600,
        'sub' => 'mailto:' . (hs_config()['contact_email'] ?? 'admin@example.com'),
    ]));
    $data = $head . '.' . $body;
    $pk = openssl_pkey_get_private($vapid['private']);
    if (!$pk || !openssl_sign($data, $der, $pk, OPENSSL_ALGO_SHA256)) return null;
    $raw = hs_der_to_raw($der);
    return $raw === null ? null : $data . '.' . hs_b64u($raw);
}

function hs_push_send_one(string $endpoint, array $vapid): int {
    $jwt = hs_push_jwt($endpoint, $vapid);
    if ($jwt === null || !function_exists('curl_init')) return 0;
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => '',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 6,
        CURLOPT_HTTPHEADER => [
            'Authorization: vapid t=' . $jwt . ', k=' . $vapid['public'],
            'TTL: 86400',
            'Urgency: high',
            'Content-Length: 0',
        ],
    ]);
    // Windows PHP has no certificate bundle of its own: use the system's
    if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code;
}

/** Wake every device this person turned alerts on for. Removes devices that are gone. */
function hs_push_user(PDO $db, int $uid): int {
    $vapid = hs_push_vapid();
    if (!$vapid) return 0;
    $st = $db->prepare('SELECT id, endpoint FROM push_subscriptions WHERE user_id = ?');
    $st->execute([$uid]);
    $sent = 0;
    foreach ($st->fetchAll() as $s) {
        $code = hs_push_send_one((string)$s['endpoint'], $vapid);
        if ($code >= 200 && $code < 300) {
            $sent++;
            $db->prepare('UPDATE push_subscriptions SET last_ok_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$s['id']]);
        } elseif ($code === 404 || $code === 410) {
            $db->prepare('DELETE FROM push_subscriptions WHERE id = ?')->execute([$s['id']]);
        }
    }
    return $sent;
}

/* ---------- Background checks (chore reminders), run by cron.php and, as a backup, by normal page visits ---------- */
function hs_run_due_checks(bool $force = false): int {
    $last = (int)hs_setting('due_check_at', '0');
    if (!$force && time() - $last < 300) return 0;
    hs_set_setting('due_check_at', (string)time());
    if ((int)date('G') < (int)(hs_config()['reminder_hour'] ?? 8)) return 0;

    $db = hs_db();
    $today = hs_today();
    $st = $db->prepare("SELECT * FROM chores WHERE active = 1 AND due_date IS NOT NULL AND due_date <= ?
                        AND (reminded_on IS NULL OR reminded_on <> ?)");
    $st->execute([$today, $today]);
    $byUser = [];
    foreach ($st->fetchAll() as $c) {
        $targets = $c['assigned_to'] ? [(int)$c['assigned_to']] : array_keys(hs_members());
        foreach ($targets as $u) $byUser[$u][] = $c;
        $db->prepare('UPDATE chores SET reminded_on = ? WHERE id = ?')->execute([$today, $c['id']]);
    }
    // One alert per person, listing their chores
    foreach ($byUser as $uid => $list) {
        $overdue = array_filter($list, fn($c) => $c['due_date'] < $today);
        $names = array_map(fn($c) => $c['title'], $list);
        $title = count($list) === 1
            ? ($overdue ? 'Overdue: ' : 'To do today: ') . $names[0]
            : count($list) . ' chores to do today' . ($overdue ? ' (' . count($overdue) . ' overdue)' : '');
        $body = count($list) === 1 ? 'Tap to tick it off.' : implode(', ', array_slice($names, 0, 5)) . (count($names) > 5 ? ' …' : '');
        hs_notify([$uid], 'due', $title, $body, 'chores.php?who=me', false);
    }
    return count($byUser);
}
