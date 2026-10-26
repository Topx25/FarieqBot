<?php
// WhatsApp Inbox — Clients (SaaS): monthly usage per number and reminders before a subscription ends.
// The Clients page itself is in index.php (?p=clients, super admin only).

/** Counts this month's usage of every number (messages in/out, bot replies, stored media, AI calls). Run hourly by the cron. */
function wf_usage_refresh(): void {
    if (function_exists('wf_ai_report_cron')) { try { wf_ai_report_cron(); } catch (Throwable $e) { wi_catch($e, 'ai report'); } }
    $U = wi_read('usage.json', []); $m = date('Y-m'); $root = dirname(__DIR__, 2);
    foreach (wi_accounts() as $aid => $a) {
        $in = $out = $bot = 0; $f = wf_cb_archive_dir((string) $aid) . '/' . $m . '.jsonl';
        if (is_readable($f) && ($h = fopen($f, 'r'))) { while (($l = fgets($h)) !== false) {
            if (strpos($l, '"dir":"in"') !== false) $in++; elseif (strpos($l, '"dir":"out"') !== false) { $out++; if (strpos($l, '"via":"bot"') !== false) $bot++; } } fclose($h); }
        $bytes = 0; $d = $root . '/storage/inbox_media/' . $aid;
        if (is_dir($d)) foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS)) as $fi) $bytes += (int) $fi->getSize();
        $U[$aid][$m] = array_merge((array) ($U[$aid][$m] ?? []), ['in' => $in, 'out' => $out, 'bot' => $bot, 'media_bytes' => $bytes, 'at' => date('c')]);
    }
    // AI: the task log keeps only the latest entries, so new tasks are added to the month's counters as they appear.
    $last = (string) ($U['_ai_last'] ?? ''); $newest = $last;
    foreach (wi_ai_tasks() as $t) { $at = (string) ($t['at'] ?? ''); $aid = (string) ($t['account'] ?? '');
        if ($at === '' || $aid === '' || strcmp($at, $last) <= 0) continue; $mm = date('Y-m', (int) strtotime($at));
        $U[$aid][$mm]['ai_calls'] = (int) ($U[$aid][$mm]['ai_calls'] ?? 0) + 1;
        $U[$aid][$mm]['ai_tokens'] = (int) ($U[$aid][$mm]['ai_tokens'] ?? 0) + (int) ($t['tokens_in'] ?? 0) + (int) ($t['tokens_out'] ?? 0);
        // per model too (the super admin's AI cost estimate)
        $md = (string) (($t['model'] ?? '') ?: 'unknown'); $mr = (array) ($U['_models'][$mm][$md] ?? []);
        $U['_models'][$mm][$md] = ['calls' => (int) ($mr['calls'] ?? 0) + 1, 'in' => (int) ($mr['in'] ?? 0) + (int) ($t['tokens_in'] ?? 0), 'out' => (int) ($mr['out'] ?? 0) + (int) ($t['tokens_out'] ?? 0)];
        if (strcmp($at, $newest) > 0) $newest = $at; }
    $U['_ai_last'] = $newest;
    // six months kept; an older month stays until its half year's report is saved
    foreach ($U as $k => $v) if ($k !== '_ai_last' && is_array($v)) { krsort($v); $keep = array_slice($v, 0, 6, true);
        foreach (array_slice($v, 6, null, true) as $mm => $x) if (function_exists('wf_ai_report_ok') && !wf_ai_report_ok(wf_ai_period((string) $mm))) $keep[$mm] = $x; $U[$k] = $keep; }
    wi_write('usage.json', $U);
}
/** One client's usage for a month, summed over their numbers. */
function wf_client_usage(string $email, ?string $month = null): array {
    $month ??= date('Y-m'); $U = wi_read('usage.json', []); $o = ['in' => 0, 'out' => 0, 'bot' => 0, 'media_bytes' => 0, 'ai_calls' => 0, 'ai_tokens' => 0, 'at' => null];
    foreach (array_keys(wi_owned_accounts($email)) as $aid) { $x = (array) ($U[$aid][$month] ?? []);
        foreach (['in', 'out', 'bot', 'media_bytes', 'ai_calls', 'ai_tokens'] as $k) $o[$k] += (int) ($x[$k] ?? 0); if (!empty($x['at'])) $o['at'] = $x['at']; }
    return $o;
}
/** Reminders: 7, 3 and 1 days before the end date and the day after it ended — to the admins and (if switched on) the client. Once each. */
function wf_client_reminders(callable $tellAdmins): void {
    $changed = false; $all = wi_users();
    foreach ($all as &$cu) { if (($cu['role'] ?? '') !== 'client' || empty($cu['expires']) || empty($cu['active'])) continue;
        $s = wi_client_state($cu); $key = $s['state'] === 'expiring' && in_array($s['days'], [7, 3, 1], true) ? 'd' . $s['days'] : ($s['state'] === 'expired' && $s['days'] === -1 ? 'ended' : '');
        if ($key === '') continue; $tag = $cu['expires'] . ':' . $key; if (in_array($tag, (array) ($cu['notified'] ?? []), true)) continue;
        $vars = ['name' => ($cu['name'] ?? '') ?: $cu['email'], 'days' => max(0, (int) $s['days']), 'date' => (string) $cu['expires'], 'email' => (string) $cu['email']];
        $tellAdmins(wf_msg($key === 'ended' ? 'client_ended_admin' : 'client_expiring_admin', $vars));
        if (!empty(wi_sys('notify_clients')) && !empty($cu['phone'])) wi_send_whatsapp((string) $cu['phone'], wf_msg($key === 'ended' ? 'client_ended' : 'client_expiring', $vars));
        $cu['notified'] = array_slice(array_merge((array) ($cu['notified'] ?? []), [$tag]), -10); $changed = true; }
    unset($cu); if ($changed) wi_save_users($all);
}
