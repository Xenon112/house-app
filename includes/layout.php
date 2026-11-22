<?php
// Page frame: sidebar on wide screens (tablet landscape, computer), big bottom tab bar on phones / tablet portrait.

const HS_BUILD = '20261122e';

function hs_head(string $title): void {
    $app = e(hs_config()['app_name'] ?? 'Our House');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">';
    echo '<meta name="csrf-token" content="' . e(hs_csrf_token()) . '">';
    echo '<title>' . e($title) . ' · ' . $app . '</title>';
    echo '<link rel="icon" type="image/svg+xml" href="assets/icons/favicon.svg">';
    echo '<link rel="apple-touch-icon" href="assets/icons/apple-touch-icon.png">';
    echo '<link rel="manifest" href="manifest.webmanifest">';
    echo '<meta name="theme-color" content="#8a4b2f">';
    echo '<meta name="apple-mobile-web-app-capable" content="yes"><meta name="mobile-web-app-capable" content="yes">';
    echo '<meta name="apple-mobile-web-app-title" content="' . $app . '">';
    // Theme before paint (Auto / Light / Dark), same as the dashboard
    echo '<script>try{var t=localStorage.getItem("hs-theme");if(t==="light"||t==="dark")document.documentElement.dataset.theme=t}catch(e){}</script>';
    echo '<link rel="stylesheet" href="assets/css/fonts.css">';
    echo '<link rel="stylesheet" href="assets/css/app.css?v=' . HS_BUILD . '">';
    echo '</head>';
}

function hs_header(string $title, string $active = '', array $opts = []): void {
    $u = hs_user();
    // Backup for the scheduled task: chore reminders also go out when someone opens the app
    try { hs_run_due_checks(); } catch (Throwable $e) {}
    hs_head($title);
    $app = e(hs_config()['app_name'] ?? 'Our House');
    $unread = $u ? hs_unread_count((int)$u['id']) : 0;
    $nav = [
        'home'    => ['Home', 'index.php', 'home'],
        'chores'  => ['Chores', 'chores.php', 'chores'],
        'food'    => ['Food', 'food.php', 'food'],
        'recipes' => ['Recipes', 'recipes.php', 'recipes'],
        'scores'  => ['Scores', 'scores.php', 'trophy'],
        'alerts'  => ['Alerts', 'alerts.php', 'bell'],
    ];
    echo '<body class="has-nav">';
    echo '<div class="shell"><aside class="sidebar">';
    echo '<a class="brand" href="index.php"><img src="assets/icons/logo.svg" width="40" height="40" alt=""><span>' . $app . '</span></a>';
    echo '<nav class="nav">';
    foreach ($nav as $k => [$label, $href, $ico]) {
        $badge = ($k === 'alerts' && $unread) ? '<span class="nav-badge">' . $unread . '</span>' : '';
        echo '<a href="' . $href . '" class="' . ($active === $k ? 'active' : '') . '"><span class="nav-ico">' . hs_icon($ico, 22) . '</span><span class="nav-label">' . $label . '</span>' . $badge . '</a>';
    }
    echo '</nav>';
    echo '<div class="sidebar-foot">';
    if ($u) echo '<div class="who">' . hs_avatar((int)$u['id']) . '<span>' . e($u['name']) . '</span></div>';
    echo '<div class="foot-row">';
    echo '<a class="foot-btn' . ($active === 'settings' ? ' active' : '') . '" href="settings.php" title="Settings">' . hs_icon('settings', 20) . '</a>';
    echo '<button type="button" class="foot-btn" data-theme-toggle title="Light / dark">' . hs_icon('moon', 20) . '</button>';
    echo '<a class="foot-btn" href="logout.php" title="Log out">' . hs_icon('logout', 20) . '</a>';
    echo '</div></div></aside>';

    echo '<main class="main">';
    echo '<header class="top">';
    if (!empty($opts['back'])) echo '<a class="back-btn" href="' . e($opts['back']) . '" aria-label="Back">' . hs_icon('back', 26) . '</a>';
    echo '<h1>' . e($title) . '</h1>';
    if (!empty($opts['actions'])) echo '<div class="top-actions">' . $opts['actions'] . '</div>';
    echo '<a class="top-settings" href="settings.php" aria-label="Settings">' . hs_icon('settings', 24) . '</a>';
    echo '</header><div class="content">';
    if ($f = hs_flash()) echo '<div class="flash ' . e($f['type']) . '">' . e($f['msg']) . '</div>';
}

function hs_footer(): void {
    $u = hs_user();
    $unread = $u ? hs_unread_count((int)$u['id']) : 0;
    $cur = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $tabs = [
        ['index.php', 'Home', 'home', ['index.php']],
        ['chores.php', 'Chores', 'chores', ['chores.php', 'chore_edit.php', 'scores.php']],
        ['food.php', 'Food', 'food', ['food.php', 'item_edit.php']],
        ['recipes.php', 'Recipes', 'recipes', ['recipes.php', 'recipe.php', 'recipe_edit.php']],
        ['alerts.php', 'Alerts', 'bell', ['alerts.php']],
    ];
    echo '</div></main></div>';
    echo '<nav class="tabbar">';
    foreach ($tabs as [$href, $label, $ico, $pages]) {
        $b = ($ico === 'bell' && $unread) ? '<span class="nav-badge">' . $unread . '</span>' : '';
        echo '<a href="' . $href . '" class="' . (in_array($cur, $pages, true) ? 'active' : '') . '">' . hs_icon($ico, 26) . '<span>' . $label . '</span>' . $b . '</a>';
    }
    echo '</nav>';
    echo '<div class="toast" id="toast" role="status" aria-live="polite"></div>';
    echo '<script src="assets/js/app.js?v=' . HS_BUILD . '"></script>';
    echo '</body></html>';
}

/** Big touch-friendly choice chips (radio buttons that look like buttons). */
function hs_chips(string $name, array $options, $selected): string {
    $h = '<div class="chips">';
    foreach ($options as $val => $label) {
        $id = 'c_' . $name . '_' . preg_replace('/\W/', '_', (string)$val);
        $h .= '<input type="radio" name="' . e($name) . '" id="' . e($id) . '" value="' . e($val) . '"' . ((string)$val === (string)$selected ? ' checked' : '') . '>'
            . '<label for="' . e($id) . '">' . $label . '</label>';
    }
    return $h . '</div>';
}
