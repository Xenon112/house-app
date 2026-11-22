<?php
// Chores: tick done → repeating chores move to their next date, "take turns" chores pass to the next person.

const HS_REPEATS = [
    'none:1'   => 'Once',
    'days:1'   => 'Every day',
    'weeks:1'  => 'Every week',
    'weeks:2'  => 'Every 2 weeks',
    'months:1' => 'Every month',
];

function hs_repeat_label(string $type, int $every): string {
    $k = $type . ':' . $every;
    if (isset(HS_REPEATS[$k])) return HS_REPEATS[$k];
    $unit = ['days' => 'days', 'weeks' => 'weeks', 'months' => 'months'][$type] ?? 'days';
    return "Every $every $unit";
}

/** Next date after today, keeping the chore's rhythm (e.g. always on Mondays). */
function hs_next_due(?string $due, string $type, int $every): string {
    $every = max(1, $every);
    $today = new DateTimeImmutable(hs_today());
    $d = $due ? new DateTimeImmutable($due) : $today;
    $guard = 0;
    do {
        $d = $d->modify("+$every $type");
    } while ($d <= $today && ++$guard < 1000);
    return $d->format('Y-m-d');
}

function hs_chore(int $id): ?array {
    $st = hs_db()->prepare('SELECT * FROM chores WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function hs_next_member(?int $current): ?int {
    $ids = array_keys(hs_members());
    if (!$ids) return null;
    $i = array_search($current, $ids, true);
    return $ids[$i === false ? 0 : ($i + 1) % count($ids)];
}

/** Tick a chore off. Returns the log id (for Undo) or 0. */
function hs_chore_done(int $id, int $uid): int {
    $db = hs_db();
    $c = hs_chore($id);
    if (!$c || !$c['active']) return 0;
    $db->beginTransaction();
    $db->prepare('INSERT INTO chore_log (chore_id, user_id, prev_due, prev_assigned, prev_active) VALUES (?,?,?,?,?)')
       ->execute([$id, $uid, $c['due_date'], $c['assigned_to'], $c['active']]);
    $logId = (int)$db->lastInsertId();
    $newAssignee = $c['assigned_to'] !== null ? (int)$c['assigned_to'] : null;
    if ($c['repeat_type'] === 'none') {
        $db->prepare('UPDATE chores SET active = 0, reminded_on = NULL WHERE id = ?')->execute([$id]);
    } else {
        $next = hs_next_due($c['due_date'], $c['repeat_type'], (int)$c['repeat_every']);
        if ($c['rotate']) $newAssignee = hs_next_member($newAssignee);
        $db->prepare('UPDATE chores SET due_date = ?, assigned_to = ?, reminded_on = NULL WHERE id = ?')
           ->execute([$next, $newAssignee, $id]);
    }
    $db->commit();
    if ($c['rotate'] && $newAssignee && $newAssignee !== (int)$c['assigned_to']) {
        $c2 = hs_chore($id);
        hs_notify([$newAssignee], 'assign', 'Your turn: ' . $c['title'], 'Due ' . hs_due_phrase($c2['due_date']) . '.', 'chores.php?who=me');
    }
    return $logId;
}

/** Undo a tick (within a day): the chore goes back to how it was. */
function hs_chore_undo(int $logId): bool {
    $db = hs_db();
    $st = $db->prepare("SELECT * FROM chore_log WHERE id = ? AND done_at >= datetime('now', '-1 day')");
    $st->execute([$logId]);
    $l = $st->fetch();
    if (!$l) return false;
    $db->prepare('UPDATE chores SET due_date = ?, assigned_to = ?, active = ? WHERE id = ?')
       ->execute([$l['prev_due'], $l['prev_assigned'], $l['prev_active'], $l['chore_id']]);
    $db->prepare('DELETE FROM chore_log WHERE id = ?')->execute([$logId]);
    return true;
}

/** Active chores, optionally only one person's (their own + "anyone" chores). */
function hs_chores_open(?int $onlyUser = null): array {
    $sql = 'SELECT * FROM chores WHERE active = 1';
    $args = [];
    if ($onlyUser) { $sql .= ' AND (assigned_to = ? OR assigned_to IS NULL)'; $args[] = $onlyUser; }
    $sql .= " ORDER BY CASE WHEN due_date IS NULL THEN 1 ELSE 0 END, due_date, title COLLATE NOCASE";
    $st = hs_db()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

/** Ticks of the last 24 hours (shown as "Done", can be undone). */
function hs_chores_recent_done(?int $onlyUser = null): array {
    $sql = "SELECT l.id AS log_id, l.user_id, l.done_at, c.id, c.title, c.room, c.repeat_type, c.repeat_every, c.due_date
            FROM chore_log l JOIN chores c ON c.id = l.chore_id
            WHERE l.done_at >= datetime('now', '-1 day')";
    $args = [];
    if ($onlyUser) { $sql .= ' AND l.user_id = ?'; $args[] = $onlyUser; }
    $sql .= ' ORDER BY l.id DESC LIMIT 30';
    $st = hs_db()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

function hs_avatar(?int $uid, string $size = ''): string {
    $m = $uid ? (hs_members()[$uid] ?? null) : null;
    if (!$m) return '<span class="avatar anyone ' . $size . '" title="Anyone">' . hs_icon('users', 16) . '</span>';
    $ini = mb_strtoupper(mb_substr($m['name'], 0, 1));
    return '<span class="avatar ' . $size . '" style="--c:' . e($m['color']) . '" title="' . e($m['name']) . '">' . e($ini) . '</span>';
}

/** One chore row with the big tick button. */
function hs_chore_row(array $c, bool $done = false): string {
    $due = $c['due_date'] ?? null;
    $state = '';
    if (!$done && $due) {
        if ($due < hs_today()) $state = 'overdue';
        elseif ($due === hs_today()) $state = 'today';
    }
    $who = $done ? (int)$c['user_id'] : ($c['assigned_to'] !== null ? (int)$c['assigned_to'] : null);
    $meta = [];
    if ($c['room']) $meta[] = e($c['room']);
    if ($done) {
        $meta[] = 'Done ' . hs_time_ago($c['done_at']) . ' by ' . e(hs_members()[$who]['name'] ?? '?');
    } else {
        if ($due) $meta[] = '<span class="due ' . $state . '">' . e(hs_due_label($due)) . '</span>';
        if ($c['repeat_type'] !== 'none') $meta[] = hs_icon('repeat', 14) . ' ' . e(hs_repeat_label($c['repeat_type'], (int)$c['repeat_every']));
        if (!empty($c['rotate'])) $meta[] = 'takes turns';
    }
    $tickAttr = $done
        ? 'data-undo="' . (int)$c['log_id'] . '" aria-label="Undo: ' . e($c['title']) . '"'
        : 'data-done="' . (int)$c['id'] . '" aria-label="Done: ' . e($c['title']) . '"';
    return '<div class="tick-row' . ($done ? ' is-done' : '') . ($state ? ' ' . $state : '') . '">'
        . '<button type="button" class="tick" ' . $tickAttr . '>' . hs_icon('check', 30) . '</button>'
        . '<a class="tick-body" href="chore_edit.php?id=' . (int)$c['id'] . '">'
        . '<span class="tick-title">' . e($c['title']) . '</span>'
        . '<span class="tick-meta">' . implode('<i>·</i>', $meta) . '</span>'
        . '</a>'
        . hs_avatar($who)
        . '</div>';
}
