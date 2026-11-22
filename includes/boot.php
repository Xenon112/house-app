<?php
declare(strict_types=1);

function hs_config(): array {
    static $c = null;
    if ($c === null) {
        $f = __DIR__ . '/../config.php';
        if (!is_file($f)) {
            http_response_code(500);
            exit('Setup: copy config.sample.php to config.php and fill it in.');
        }
        $c = require $f;
    }
    return $c;
}

date_default_timezone_set(hs_config()['timezone'] ?? 'Europe/Berlin');
ini_set('display_errors', '0');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/notify.php';
require_once __DIR__ . '/chores.php';
require_once __DIR__ . '/food.php';
require_once __DIR__ . '/layout.php';

// Session before any output (the CSRF token lives in it)
hs_session_start();
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: SAMEORIGIN');

function e($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function hs_today(): string { return date('Y-m-d'); }

function hs_redirect(string $url): never {
    header('Location: ' . $url);
    exit;
}

function hs_json($data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data);
    exit;
}

function hs_flash(?string $msg = null, string $type = 'ok'): ?array {
    if ($msg !== null) {
        $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

/** "Today", "Tomorrow", "Yesterday", "Mon 24 Nov", "3 days overdue" */
function hs_due_label(?string $date): string {
    if (!$date) return '';
    $d = new DateTimeImmutable($date);
    $t = new DateTimeImmutable(hs_today());
    $diff = (int)$t->diff($d)->format('%r%a');
    if ($diff === 0) return 'Today';
    if ($diff === 1) return 'Tomorrow';
    if ($diff === -1) return 'Yesterday';
    if ($diff < 0) return (-$diff) . ' days overdue';
    if ($diff < 7) return $d->format('l');
    return $d->format('D j M');
}

/** For sentences: "today", "tomorrow", "on Sunday", "on Mon 1 Dec". */
function hs_due_phrase(?string $date): string {
    $l = hs_due_label($date);
    if (in_array($l, ['Today', 'Tomorrow', 'Yesterday'], true) || str_contains($l, 'overdue')) return strtolower($l);
    return 'on ' . $l;
}

function hs_num($n): string {
    $n = (float)$n;
    return $n == floor($n) ? (string)(int)$n : rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
}

/** $ts is a database timestamp (SQLite CURRENT_TIMESTAMP = UTC). */
function hs_time_ago(string $ts): string {
    $t = strtotime($ts . ' UTC');
    $s = time() - $t;
    if ($s < 60) return 'just now';
    if ($s < 3600) return floor($s / 60) . ' min ago';
    if ($s < 86400) return floor($s / 3600) . ' h ago';
    if ($s < 7 * 86400) return floor($s / 86400) . ' d ago';
    return date('j M', $t);
}
