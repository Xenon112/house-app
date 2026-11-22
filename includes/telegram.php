<?php
/**
 * Telegram alerts — one bot for the whole house, each person links their own chat.
 *
 * Admin: makes a bot with @BotFather and pastes the token in Settings (once).
 * Each person: Settings → "Connect Telegram" opens the bot with a personal code (t.me/<bot>?start=<code>),
 * they press Start, then "Check" in the app. The app reads the bot's new messages (getUpdates, same as the
 * Refurbisch dashboard) and links every person whose code it finds. No webhook needed.
 */

function hs_tg_token(): string { return trim((string)hs_setting('tg_token', '')); }
function hs_tg_bot(): string { return trim((string)hs_setting('tg_bot', '')); }
function hs_tg_chat(int $uid): string { return trim((string)hs_user_setting($uid, 'tg_chat', '')); }

/** Raw Bot API call. */
function hs_tg_api(string $method, array $params = [], ?string $token = null): array {
    $token = $token ?? hs_tg_token();
    if (!preg_match('/^\d+:[A-Za-z0-9_-]{20,}$/', $token)) return ['ok' => false, 'error' => 'The bot token looks wrong (it looks like 123456789:ABC…).'];
    if (!function_exists('curl_init')) return ['ok' => false, 'error' => 'PHP curl is missing on this server.'];
    $ch = curl_init('https://api.telegram.org/bot' . $token . '/' . $method);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($params, JSON_UNESCAPED_UNICODE),
    ]);
    if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) return ['ok' => false, 'error' => 'Telegram not reachable: ' . $err];
    $j = json_decode((string)$raw, true) ?: [];
    if (empty($j['ok'])) return ['ok' => false, 'error' => (string)($j['description'] ?? 'Telegram error')];
    return ['ok' => true, 'result' => $j['result'] ?? null];
}

/** Admin saves the bot token: check it with getMe and remember the bot's @name and the app's address. */
function hs_tg_save_token(string $token): array {
    $token = trim($token);
    $r = hs_tg_api('getMe', [], $token);
    if (!$r['ok']) return $r;
    hs_set_setting('tg_token', $token);
    hs_set_setting('tg_bot', (string)($r['result']['username'] ?? ''));
    hs_remember_app_url();
    return ['ok' => true, 'bot' => (string)($r['result']['username'] ?? '')];
}

/** Address of the app (for links in Telegram messages), taken from the current request. */
function hs_remember_app_url(): void {
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '') return;
    $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    hs_set_setting('app_url', (hs_is_https() ? 'https://' : 'http://') . $host . $dir . '/');
}

function hs_app_url(string $path = ''): string {
    $base = (string)(hs_config()['base_url'] ?? '') ?: (string)hs_setting('app_url', '');
    return $base === '' ? '' : rtrim($base, '/') . '/' . ltrim($path, '/');
}

/** Personal link code for "Connect Telegram". */
function hs_tg_code(int $uid): string {
    $c = (string)hs_user_setting($uid, 'tg_code', '');
    if (!preg_match('/^[a-z0-9]{16}$/', $c)) {
        $c = substr(bin2hex(random_bytes(8)), 0, 16);
        hs_set_user_setting($uid, 'tg_code', $c);
    }
    return $c;
}

/**
 * Link everyone whose "/start <code>" is in these updates. Returns [user_id => chat display name].
 * Kept separate from the API call so it can be tested without Telegram.
 */
function hs_tg_link_from_updates(array $updates): array {
    $codes = [];
    foreach (hs_db()->query("SELECT user_id, v FROM user_settings WHERE k = 'tg_code'") as $r) $codes[(string)$r['v']] = (int)$r['user_id'];
    $linked = [];
    foreach ($updates as $u) {
        $m = $u['message'] ?? null;
        if (!$m || ($m['chat']['type'] ?? '') !== 'private') continue;
        if (!preg_match('~^/start\s+([a-z0-9]{16})\b~', (string)($m['text'] ?? ''), $mm)) continue;
        $uid = $codes[$mm[1]] ?? 0;
        if (!$uid) continue;
        $name = trim(($m['chat']['first_name'] ?? '') . ' ' . ($m['chat']['last_name'] ?? ''));
        hs_set_user_setting($uid, 'tg_chat', (string)$m['chat']['id']);
        hs_set_user_setting($uid, 'tg_name', $name);
        hs_set_user_setting($uid, 'tg_code', null);   // a code works once
        $linked[$uid] = $name;
    }
    return $linked;
}

/** "Check" button: read the bot's new messages and link people. */
function hs_tg_check_links(): array {
    $r = hs_tg_api('getUpdates', ['limit' => 100, 'timeout' => 0, 'allowed_updates' => ['message']]);
    if (!$r['ok']) return $r;
    $updates = (array)$r['result'];
    $linked = hs_tg_link_from_updates($updates);
    // Mark these messages as read so the list stays short
    if ($updates) {
        $last = end($updates);
        hs_tg_api('getUpdates', ['offset' => (int)$last['update_id'] + 1, 'limit' => 1, 'timeout' => 0]);
    }
    foreach ($linked as $uid => $name) {
        hs_tg_send($uid, '✅ <b>Connected!</b>' . "\n" . 'You will get your house alerts here, ' . hs_tg_e(hs_members()[$uid]['name'] ?? '') . '.');
    }
    return ['ok' => true, 'linked' => $linked];
}

function hs_tg_e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function hs_tg_send(int $uid, string $html): bool {
    $chat = hs_tg_chat($uid);
    if ($chat === '' || hs_tg_token() === '') return false;
    $r = hs_tg_api('sendMessage', ['chat_id' => $chat, 'text' => $html, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true]);
    // The person blocked the bot or deleted the chat: unlink
    if (!$r['ok'] && preg_match('/blocked|chat not found|deactivated/i', $r['error'])) hs_set_user_setting($uid, 'tg_chat', null);
    return $r['ok'];
}

/** An alert as a Telegram message: bold title, text, link to the page. */
function hs_tg_send_alert(int $uid, string $kind, string $title, string $body, string $url): bool {
    $icon = ['assign' => '📋', 'due' => '⏰', 'low' => '🛒', 'done' => '✅', 'recipe' => '🍝', 'summary' => '🏆', 'test' => '🔔'];
    $msg = ($icon[$kind] ?? '🏠') . ' <b>' . hs_tg_e($title) . '</b>';
    if ($body !== '') $msg .= "\n" . hs_tg_e($body);
    $link = hs_app_url($url);
    if ($link !== '') $msg .= "\n\n" . '<a href="' . hs_tg_e($link) . '">Open the house app</a>';
    return hs_tg_send($uid, $msg);
}
