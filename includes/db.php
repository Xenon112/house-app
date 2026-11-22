<?php
// SQLite database: one file, created on first use. No database server needed.

function hs_db(): PDO {
    static $db = null;
    if ($db) return $db;
    $dir = rtrim((string)(hs_config()['data_dir'] ?? (__DIR__ . '/../data')), '/\\');
    if (!is_dir($dir)) @mkdir($dir, 0770, true);
    if (!is_file($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess',
            "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n");
    }
    // Secret file name (from the setup key): even if a server ignores .htaccess, nobody can guess the URL
    $file = 'house-' . substr(hash('sha256', 'db|' . (hs_config()['setup_key'] ?? '')), 0, 24) . '.sqlite';
    $db = new PDO('sqlite:' . $dir . '/' . $file, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db->exec('PRAGMA journal_mode = WAL; PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000;');
    hs_migrate($db);
    return $db;
}

function hs_migrate(PDO $db): void {
    $v = (int)$db->query('PRAGMA user_version')->fetchColumn();
    if ($v < 1) hs_migrate_v1($db);
    if ($v < 2) {
        // Monthly chore champion
        $db->exec('CREATE TABLE IF NOT EXISTS awards (
            month TEXT NOT NULL,            -- YYYY-MM
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            chores INTEGER NOT NULL,
            PRIMARY KEY (month, user_id)
        ); PRAGMA user_version = 2;');
    }
}

function hs_migrate_v1(PDO $db): void {
    $db->exec(<<<SQL
    CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE COLLATE NOCASE,
        name TEXT NOT NULL,
        pass_hash TEXT NOT NULL,
        role TEXT NOT NULL DEFAULT 'member',
        color TEXT NOT NULL DEFAULT '#c86a35',
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE IF NOT EXISTS settings (k TEXT PRIMARY KEY, v TEXT);
    CREATE TABLE IF NOT EXISTS user_settings (
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        k TEXT NOT NULL, v TEXT,
        PRIMARY KEY (user_id, k)
    );
    CREATE TABLE IF NOT EXISTS remember_tokens (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        selector TEXT NOT NULL UNIQUE,
        validator_hash TEXT NOT NULL,
        expires_at INTEGER NOT NULL
    );
    CREATE TABLE IF NOT EXISTS login_attempts (ip TEXT NOT NULL, at INTEGER NOT NULL);
    CREATE INDEX IF NOT EXISTS ix_login_attempts ON login_attempts(ip, at);

    CREATE TABLE IF NOT EXISTS chores (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        room TEXT,
        notes TEXT,
        assigned_to INTEGER REFERENCES users(id) ON DELETE SET NULL,
        rotate INTEGER NOT NULL DEFAULT 0,
        repeat_type TEXT NOT NULL DEFAULT 'none',   -- none | days | weeks | months
        repeat_every INTEGER NOT NULL DEFAULT 1,
        due_date TEXT,
        active INTEGER NOT NULL DEFAULT 1,
        reminded_on TEXT,
        created_by INTEGER,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE IF NOT EXISTS chore_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        chore_id INTEGER NOT NULL REFERENCES chores(id) ON DELETE CASCADE,
        user_id INTEGER,
        done_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        prev_due TEXT, prev_assigned INTEGER, prev_active INTEGER
    );

    CREATE TABLE IF NOT EXISTS items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        category TEXT NOT NULL DEFAULT 'Pantry',
        qty REAL NOT NULL DEFAULT 0,
        unit TEXT NOT NULL DEFAULT '',
        min_qty REAL NOT NULL DEFAULT 1,
        target_qty REAL,
        on_list INTEGER NOT NULL DEFAULT 0,
        low_alerted INTEGER NOT NULL DEFAULT 0,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS recipes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        servings INTEGER,
        minutes INTEGER,
        steps TEXT NOT NULL DEFAULT '',
        notes TEXT,
        created_by INTEGER,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE IF NOT EXISTS recipe_ingredients (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        recipe_id INTEGER NOT NULL REFERENCES recipes(id) ON DELETE CASCADE,
        item_id INTEGER REFERENCES items(id) ON DELETE SET NULL,
        name TEXT NOT NULL,
        amount REAL,
        unit TEXT NOT NULL DEFAULT '',
        sort INTEGER NOT NULL DEFAULT 0
    );

    CREATE TABLE IF NOT EXISTS notifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        kind TEXT NOT NULL,
        title TEXT NOT NULL,
        body TEXT NOT NULL DEFAULT '',
        url TEXT NOT NULL DEFAULT 'index.php',
        push INTEGER NOT NULL DEFAULT 1,
        read_at TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    );
    CREATE INDEX IF NOT EXISTS ix_notif_user ON notifications(user_id, id);

    CREATE TABLE IF NOT EXISTS push_subscriptions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        endpoint TEXT NOT NULL,
        endpoint_hash TEXT NOT NULL UNIQUE,
        user_agent TEXT,
        last_seen_id INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_ok_at TEXT
    );
    PRAGMA user_version = 1;
    SQL);
}

function hs_setting(string $k, $default = null) {
    $st = hs_db()->prepare('SELECT v FROM settings WHERE k = ?');
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return $v === false ? $default : $v;
}

function hs_set_setting(string $k, ?string $v): void {
    hs_db()->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v')->execute([$k, $v]);
}

function hs_user_setting(int $uid, string $k, $default = null) {
    $st = hs_db()->prepare('SELECT v FROM user_settings WHERE user_id = ? AND k = ?');
    $st->execute([$uid, $k]);
    $v = $st->fetchColumn();
    return $v === false ? $default : $v;
}

function hs_set_user_setting(int $uid, string $k, ?string $v): void {
    hs_db()->prepare('INSERT INTO user_settings (user_id, k, v) VALUES (?, ?, ?) ON CONFLICT(user_id, k) DO UPDATE SET v = excluded.v')
        ->execute([$uid, $k, $v]);
}

/** All household members, ordered for display and for chore rotation. */
function hs_members(): array {
    static $m = null;
    if ($m === null) {
        $m = [];
        foreach (hs_db()->query('SELECT id, username, name, role, color FROM users ORDER BY id') as $r) $m[(int)$r['id']] = $r;
    }
    return $m;
}
