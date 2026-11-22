<?php
// Copy this file to config.php and change the values marked CHANGE.
return [
    'app_name'      => 'Our House',
    'timezone'      => 'Europe/Berlin',

    // Where the SQLite database lives. Best: a folder OUTSIDE httpdocs, e.g. '/var/www/vhosts/example.com/house-data'.
    // Inside the app folder it is protected by data/.htaccess (Apache) — keep that file.
    'data_dir'      => __DIR__ . '/data',

    // CHANGE: needed once on setup.php to create the first (admin) account.
    'setup_key'     => 'CHANGE-ME-setup',

    // CHANGE: secret for cron.php (Plesk scheduled task: fetch https://your-site/cron.php?key=THIS every 15 minutes).
    'cron_key'      => 'CHANGE-ME-cron',

    // Contact address sent with phone alerts (required by the push services, not shown to anyone).
    'contact_email' => 'admin@example.com',

    // Chore reminders are sent from this hour on (0–23), once a day while a chore is due or overdue.
    'reminder_hour' => 8,
];
