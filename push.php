<?php
// Phone alerts for this device.
//   GET  ?action=key              → {"key": public VAPID key}
//   GET  ?action=next&ep=<sha256> → next alert for this device (called by sw.js when a push arrives)
//   POST action=subscribe         → endpoint: this device gets alerts
//   POST action=unsubscribe       → endpoint: stop for this device
//   POST action=test              → send a test alert to my devices
require __DIR__ . '/includes/boot.php';
$u = hs_user();
if (!$u) hs_json(['error' => 'Please log in again.'], 401);
$uid = (int)$u['id'];
$db = hs_db();
$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');

if ($action === 'key') {
    $v = hs_push_vapid();
    hs_json($v ? ['key' => $v['public']] : ['error' => 'Phone alerts are not available on this server (PHP OpenSSL missing).']);
}

if ($action === 'next') {
    $ep = (string)($_GET['ep'] ?? '');
    $st = $db->prepare('SELECT id, last_seen_id FROM push_subscriptions WHERE endpoint_hash = ? AND user_id = ?');
    $st->execute([$ep, $uid]);
    $sub = $st->fetch();
    $after = $sub ? (int)$sub['last_seen_id'] : 0;
    // Oldest alert this device has not shown yet (from the last day), else the newest one
    $st = $db->prepare("SELECT id, title, body, url, kind FROM notifications WHERE user_id = ? AND push = 1 AND id > ?
                        AND created_at >= datetime('now', '-1 day') ORDER BY id LIMIT 1");
    $st->execute([$uid, $after]);
    $n = $st->fetch();
    if (!$n) {
        $st = $db->prepare('SELECT id, title, body, url, kind FROM notifications WHERE user_id = ? AND push = 1 ORDER BY id DESC LIMIT 1');
        $st->execute([$uid]);
        $n = $st->fetch();
    }
    if ($n && $sub) $db->prepare('UPDATE push_subscriptions SET last_seen_id = MAX(last_seen_id, ?) WHERE id = ?')->execute([$n['id'], $sub['id']]);
    hs_json(['item' => $n ? ['id' => (int)$n['id'], 'title' => $n['title'], 'text' => $n['body'], 'url' => $n['url'], 'kind' => $n['kind']] : null]);
}

if (!hs_is_post()) hs_json(['error' => 'POST only'], 405);
hs_check_csrf();
$endpoint = trim((string)($_POST['endpoint'] ?? ''));

if ($action === 'subscribe') {
    if (!preg_match('~^https://~', $endpoint) || strlen($endpoint) > 1000) hs_json(['error' => 'Invalid subscription'], 400);
    $hash = hash('sha256', $endpoint);
    $max = (int)$db->query('SELECT COALESCE(MAX(id), 0) FROM notifications')->fetchColumn();
    $db->prepare('INSERT INTO push_subscriptions (user_id, endpoint, endpoint_hash, user_agent, last_seen_id) VALUES (?,?,?,?,?)
                  ON CONFLICT(endpoint_hash) DO UPDATE SET user_id = excluded.user_id, user_agent = excluded.user_agent')
       ->execute([$uid, $endpoint, $hash, mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255), $max]);
    hs_json(['ok' => true]);
}

if ($action === 'unsubscribe') {
    $db->prepare('DELETE FROM push_subscriptions WHERE endpoint_hash = ? AND user_id = ?')->execute([hash('sha256', $endpoint), $uid]);
    hs_json(['ok' => true]);
}

if ($action === 'test') {
    $db->prepare("INSERT INTO notifications (user_id, kind, title, body, url, push, read_at) VALUES (?, 'test', ?, ?, 'settings.php', 1, CURRENT_TIMESTAMP)")
       ->execute([$uid, 'Alerts are working', 'This device will get chore and food alerts.']);
    $sent = hs_push_user($db, $uid);
    hs_json(['ok' => $sent > 0, 'sent' => $sent]);
}

hs_json(['error' => 'Unknown action'], 400);
