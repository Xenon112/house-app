<?php
// Scheduled task (Plesk → Scheduled Tasks → "Fetch a URL", every 15 minutes):
//   https://your-site/cron.php?key=<cron_key from config.php>
// Sends the daily chore reminders. Without it, reminders still go out whenever someone opens the app.
require __DIR__ . '/includes/boot.php';
$key = (string)(hs_config()['cron_key'] ?? '');
if ($key === '' || str_starts_with($key, 'CHANGE-ME') || !hash_equals($key, (string)($_GET['key'] ?? ''))) {
    http_response_code(403);
    exit('forbidden');
}
$n = hs_run_due_checks(true);
// Housekeeping: old alerts and expired logins
hs_db()->exec("DELETE FROM notifications WHERE created_at < datetime('now', '-60 days')");
hs_db()->prepare('DELETE FROM remember_tokens WHERE expires_at < ?')->execute([time()]);
header('Content-Type: text/plain');
echo "ok, reminded $n people\n";
