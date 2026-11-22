<?php
// Monthly chore scores: who ticked off the most chores. On the 1st of the month the winner of the
// month before gets the award (a trophy) and everyone gets the summary.

/** First and last moment of a month (local time) as database timestamps (UTC). */
function hs_month_bounds(string $ym): array {
    $tz = new DateTimeZone(date_default_timezone_get());
    $start = new DateTimeImmutable($ym . '-01 00:00:00', $tz);
    $end = $start->modify('+1 month');
    $utc = new DateTimeZone('UTC');
    return [$start->setTimezone($utc)->format('Y-m-d H:i:s'), $end->setTimezone($utc)->format('Y-m-d H:i:s')];
}

function hs_month_name(string $ym): string {
    return (new DateTimeImmutable($ym . '-01'))->format('F Y');
}

/** [user_id => chores done] for everyone in the house, most first. */
function hs_month_counts(string $ym): array {
    [$a, $b] = hs_month_bounds($ym);
    $counts = array_fill_keys(array_keys(hs_members()), 0);
    $st = hs_db()->prepare('SELECT user_id, COUNT(*) n FROM chore_log WHERE done_at >= ? AND done_at < ? GROUP BY user_id');
    $st->execute([$a, $b]);
    foreach ($st->fetchAll() as $r) if (isset($counts[(int)$r['user_id']])) $counts[(int)$r['user_id']] = (int)$r['n'];
    arsort($counts);
    return $counts;
}

/** [user_id => number of awards]. */
function hs_award_totals(): array {
    $t = [];
    foreach (hs_db()->query('SELECT user_id, COUNT(*) n FROM awards GROUP BY user_id ORDER BY n DESC') as $r) $t[(int)$r['user_id']] = (int)$r['n'];
    return $t;
}

/** Past winners, newest first: [['month' => 'YYYY-MM', 'users' => [id => chores]], …] */
function hs_award_history(int $limit = 12): array {
    $out = [];
    foreach (hs_db()->query('SELECT month, user_id, chores FROM awards ORDER BY month DESC, user_id') as $r) {
        $out[$r['month']]['month'] = $r['month'];
        $out[$r['month']]['users'][(int)$r['user_id']] = (int)$r['chores'];
    }
    return array_slice(array_values($out), 0, $limit);
}

/** Hand out last month's award once (called by the reminder check / cron). Returns the month awarded or ''. */
function hs_award_check(): string {
    $prev = (new DateTimeImmutable('first day of last month'))->format('Y-m');
    if ((string)hs_setting('awarded_month', '') >= $prev) return '';
    hs_set_setting('awarded_month', $prev);
    $counts = hs_month_counts($prev);
    $best = $counts ? max($counts) : 0;
    if ($best < 1) return '';
    $winners = array_keys(array_filter($counts, fn($n) => $n === $best));
    $ins = hs_db()->prepare('INSERT OR IGNORE INTO awards (month, user_id, chores) VALUES (?,?,?)');
    foreach ($winners as $w) $ins->execute([$prev, $w, $best]);

    $names = array_map(fn($w) => hs_members()[$w]['name'] ?? '?', $winners);
    $title = '🏆 ' . (new DateTimeImmutable($prev . '-01'))->format('F') . ' champion: ' . implode(' & ', $names) . " ($best chores)";
    $board = [];
    foreach ($counts as $uid => $n) $board[] = (hs_members()[$uid]['name'] ?? '?') . ' ' . $n;
    hs_notify_everyone('summary', $title, 'Scores: ' . implode(' · ', $board) . '. A new month starts now — good luck!', 'scores.php?m=' . $prev, false);
    return $prev;
}
