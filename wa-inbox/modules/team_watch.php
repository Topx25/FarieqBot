<?php
// WhatsApp Inbox — team_watch: how the healers / admins follow the cases.
// Every group is read once (not once per case): each case's last message, the team's last answer to it (a reply or a tag),
// who answered and how fast. Cases nobody of the team answered for some days are listed as neglected.

/** The board: healers (cases, answers, speed, last activity) and the cases with their last team contact. Kept 30 minutes. */
function wf_team_watch(array $acc, bool $fresh = false): array {
    $aid = (string) $acc['id']; $cf = WI_DIR . '/team_watch_' . preg_replace('/\W/', '', $aid) . '.json';
    if (!$fresh && is_file($cf) && filemtime($cf) > time() - 1800) return (array) json_decode((string) file_get_contents($cf), true);
    $days = max(1, (int) (wf_bot_run_cfg($aid)['neglect_days'] ?? 3)); $now = time(); $since = $now - 30 * 86400;
    // the cases and every ID of each person
    $cases = []; $owner = [];
    foreach (wf_cases($aid) as $ph => $c) { if (!is_array($c) || in_array($c['status'] ?? '', ['archived', 'removed', 'closed'], true)) continue;
        $cases[$ph] = ['phone' => (string) $ph, 'name' => (string) ($c['name'] ?? '') ?: '+' . $ph, 'no' => (string) ($c['no'] ?? ''), 'status' => (string) ($c['status'] ?? 'new'), 'private' => wf_case_is_private($c), 'last_in' => 0, 'last_team' => 0, 'by' => '', 'waiting' => 0];
        $owner[(string) $ph] = (string) $ph; foreach ((array) ($c['lids'] ?? []) as $l) if (($d = preg_replace('/\D/', '', (string) $l)) !== '') $owner[$d] = (string) $ph; }
    $chats = (array) (wi_gateway('/archive/chats?months=2', null, $acc)['chats'] ?? []);
    foreach ($chats as $ch) { $j = (string) ($ch['jid'] ?? ''); if (!empty($ch['group']) || !str_ends_with($j, '@lid')) continue; $p = wf_phone((string) ($ch['phone'] ?? '')) ?: wf_phone((string) (wi_contact($j, $acc)['phone'] ?? '')); if ($p !== '' && isset($cases[$p])) $owner[explode('@', $j)[0]] = $p; }
    $who = fn($v) => $owner[preg_replace('/\D/', '', explode(':', explode('@', (string) $v)[0])[0])] ?? null;
    $heal = []; $names = []; $resp = [];
    $touch = function (string $ph, string $w, int $t, int $waitFrom) use (&$cases, &$heal, &$resp) { if ($t > $cases[$ph]['last_team']) { $cases[$ph]['last_team'] = $t; $cases[$ph]['by'] = $w; }
        $heal[$w] ??= ['n' => 0, 'cases' => [], 'last' => 0]; $heal[$w]['n']++; $heal[$w]['cases'][$ph] = 1; $heal[$w]['last'] = max($heal[$w]['last'], $t); if ($waitFrom && $t - $waitFrom <= 86400) $resp[$w][] = $t - $waitFrom; };
    // groups: read once each
    foreach ((array) (wi_gateway('/groups', null, $acc)['groups'] ?? []) as $g) { $gj = (string) $g['jid']; $team = wf_team($acc, [$gj => []]); $pending = [];
        foreach ((array) (wi_gateway('/archive/thread?limit=3000&jid=' . rawurlencode($gj), null, $acc)['rows'] ?? []) as $m) { $t = strtotime((string) ($m['time'] ?? '')); if (!$t || $t < $since) continue;
            $out = ($m['dir'] ?? '') === 'out'; $w = $out ? 'owner' : wf_phone((string) ($m['from'] ?? '')); if (!$out && !empty($m['from_name'])) $names[$w] = (string) $m['from_name'];
            if (!$out && ($ph = $who($w)) !== null) { $cases[$ph]['last_in'] = max($cases[$ph]['last_in'], $t); $pending[$ph] ??= $t; continue; }
            if (!$out && !isset($team[$w])) continue;
            $targets = []; if (($ph = $who($m['quote_from'] ?? '')) !== null) $targets[$ph] = 1; foreach ((array) ($m['mentions'] ?? []) as $x) if (($ph = $who($x)) !== null) $targets[$ph] = 1;
            if (preg_match_all('/@(\d{8,})/', (string) ($m['text'] ?? ''), $mm)) foreach ($mm[1] as $x) if (($ph = $who($x)) !== null) $targets[$ph] = 1;
            foreach (array_keys($targets) as $ph) { $touch($ph, $w, $t, (int) ($pending[$ph] ?? 0)); unset($pending[$ph]); } } }
    // private chats of the cases: the number's own phone (owner) and the bot
    foreach ($chats as $ch) { $j = (string) ($ch['jid'] ?? ''); if (!empty($ch['group']) || ($ph = $who($j)) === null) continue; $pend = 0;
        foreach ((array) (wi_gateway('/archive/thread?limit=400&jid=' . rawurlencode($j), null, $acc)['rows'] ?? []) as $m) { $t = strtotime((string) ($m['time'] ?? '')); if (!$t || $t < $since) continue;
            if (($m['dir'] ?? '') === 'in') { $cases[$ph]['last_in'] = max($cases[$ph]['last_in'], $t); $pend = $pend ?: $t; continue; }
            if (($m['via'] ?? '') === 'bot') continue; $touch($ph, 'owner', $t, $pend); $pend = 0; } }
    // the case file: stage changes and notes by people
    foreach ($cases as $ph => &$cs) { $c = wf_case_get($aid, $ph) ?? [];
        foreach (array_merge((array) ($c['status_log'] ?? []), (array) ($c['notes'] ?? [])) as $l) { $t = strtotime((string) ($l['at'] ?? '')); $b = (string) ($l['by'] ?? ''); if ($t && $t >= $since && $b !== '' && $b !== 'bot' && !str_starts_with($b, 'group:') && $t > $cs['last_team']) { $cs['last_team'] = $t; $cs['by'] = $b; } }
        $cs['waiting'] = $cs['last_in'] > $cs['last_team'] ? $cs['last_in'] : 0; // the person wrote after the team's last answer
        $cs['neglected'] = ($cs['last_in'] || $cs['last_team']) && max($cs['last_team'], 0) < $now - $days * 86400 && $cs['last_in'] >= $now - 21 * 86400; }
    unset($cs);
    $hl = []; foreach ($heal as $w => $h) { $r = (array) ($resp[$w] ?? []); sort($r); $hl[] = ['who' => $w, 'name' => $names[$w] ?? '', 'touches' => $h['n'], 'cases' => count($h['cases']), 'last' => $h['last'], 'median_min' => $r ? (int) round($r[(int) floor(count($r) / 2)] / 60) : null]; }
    usort($hl, fn($a, $b) => $b['touches'] <=> $a['touches']);
    $cl = array_values($cases); usort($cl, fn($a, $b) => ($b['neglected'] <=> $a['neglected']) ?: ($a['last_team'] <=> $b['last_team']));
    $out = ['healers' => $hl, 'cases' => $cl, 'names' => $names, 'days' => $days, 'at' => date('c')];
    @file_put_contents($cf, json_encode($out, JSON_UNESCAPED_UNICODE)); @chmod($cf, 0600);
    return $out;
}
/** The weekly team report to the admins: healers by activity, and the neglected cases. */
function wf_team_report(array $acc, callable $tell): void {
    $w = wf_team_watch($acc, true); $nm = fn($x) => $x === 'owner' ? wf_msg('trail_owner') : (($w['names'] ?? [])[$x] ?? '') ;
    $hl = implode("\n", array_map(fn($h) => '• ' . ($h['who'] === 'owner' ? wf_msg('trail_owner') : ($h['name'] !== '' ? $h['name'] : '+' . $h['who'])) . ' — ' . wf_msg('team_line', ['cases' => (string) $h['cases'], 'touches' => (string) $h['touches'], 'speed' => $h['median_min'] === null ? '—' : (string) $h['median_min'], 'last' => date('Y-m-d', (int) $h['last'])]), array_slice($w['healers'], 0, 12)));
    $ng = array_values(array_filter($w['cases'], fn($c) => !empty($c['neglected'])));
    $nl = implode("\n", array_map(fn($c) => '• ' . $c['name'] . ' (ID ' . $c['no'] . ') — ' . ($c['last_team'] ? date('Y-m-d', (int) $c['last_team']) : '—'), array_slice($ng, 0, 15)));
    $tell(wf_msg('team_report', ['name' => (string) ($acc['name'] ?? $acc['id']), 'healers' => $hl ?: '—', 'neglected' => $nl ?: '—', 'count' => (string) count($ng), 'days' => (string) $w['days']]));
}
function wf_api_team_watch(string $api): void {
    if ($api !== 'team_watch') return;
    $acc = wi_current(); if (!$acc) wi_json(['ok' => false, 'error' => 'No number chosen.'], 422);
    if (!wf_case_private_ok()) wi_json(['ok' => false, 'error' => 'Admins only.'], 403); // the team's own report: the owner level
    @set_time_limit(300); wi_json(['ok' => true] + wf_team_watch($acc, !empty($_GET['fresh'])));
}
