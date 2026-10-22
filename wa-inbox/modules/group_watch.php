<?php
// WhatsApp Inbox — group_watch: nobody answered a member's question in a group, or a new case nobody took — the admins are told.
// Only the groups with talk in the last hours are read (a short read each), every alert is sent once.

function wf_group_watch(array $acc, callable $tell): array {
    $aid = (string) $acc['id']; $c = wf_bot_run_cfg($aid); if (empty($c['watch_on'])) return ['off' => true];
    $mins = max(5, (int) ($c['watch_minutes'] ?? 30)); $now = time(); $S = (array) (wi_read('group_watch.json', [])[$aid] ?? []); $sent = (array) ($S['sent'] ?? []); $alerts = [];
    $since = $now - 3 * 3600; $me = preg_replace('/\D/', '', explode(':', (string) (wi_gateway('/status', null, $acc)['account']['id'] ?? ''))[0]);
    $skip = array_flip((array) ($c['watch_skip'] ?? []));
    $fol = []; if (function_exists('wf_team_watch')) foreach ((array) (wf_team_watch($acc)['cases'] ?? []) as $cw) if (preg_match('/^\d{8,}$/', (string) ($cw['by'] ?? ''))) $fol[(string) $cw['phone']] = (string) $cw['by']; // the healer who follows each case
    $tags = [];
    foreach ((array) (wi_gateway('/archive/chats?months=1', null, $acc)['chats'] ?? []) as $ch) {
        if (empty($ch['group']) || isset($skip[(string) $ch['jid']]) || strtotime((string) ($ch['last']['time'] ?? '')) < $since) continue;
        $gj = (string) $ch['jid']; $team = wf_team($acc, [$gj => []]); if (!$team) continue; // a group with no team members known: nothing to watch
        $rows = (array) (wi_gateway('/archive/thread?limit=250&jid=' . rawurlencode($gj), null, $acc)['rows'] ?? []); $open = []; $lastTeam = '';
        foreach ($rows as $m) { $t = strtotime((string) ($m['time'] ?? '')); if (!$t || $t < $since) continue; $out = ($m['dir'] ?? '') === 'out'; $w = $out ? $me : wf_phone((string) ($m['from'] ?? ''));
            $tx = trim((string) ($m['text'] ?? ''));
            if (!$out && isset($team[$w]) && preg_match('/^\d{8,}$/', $w)) $lastTeam = $w; // the team member last active in this group
            if (!$out && !isset($team[$w])) { if ($tx !== '' && (wf_is_question($tx) || count(wf_words($tx)) >= 8) && !wf_is_ack($aid, $tx)) $open[$w] = ['t' => $t, 'id' => (string) ($m['id'] ?? ''), 'text' => $tx, 'name' => (string) ($m['from_name'] ?? '')]; continue; }
            // a team or bot message answers the person it replies to or tags; a message to nobody in particular counts for the last person who asked
            $to = []; if (($q = wf_phone((string) ($m['quote_from'] ?? ''))) !== '') $to[$q] = 1; foreach ((array) ($m['mentions'] ?? []) as $x) $to[wf_phone((string) $x)] = 1;
            if (preg_match_all('/@(\d{8,})/', $tx, $mm)) foreach ($mm[1] as $x) $to[$x] = 1;
            if ($to) { foreach (array_keys($to) as $x) unset($open[$x]); } elseif ($open) { $lastAsk = array_key_last($open); if ($t - $open[$lastAsk]['t'] < 600) unset($open[$lastAsk]); } }
        foreach ($open as $w => $q) { if ($now - $q['t'] < $mins * 60 || isset($sent[$q['id']])) continue; $sent[$q['id']] = $now; $hp = $fol[$w] ?? $lastTeam; $tg = $hp !== '' ? ' @' . $hp : ''; if ($hp !== '') $tags[$hp] = 1;
            $alerts[] = wf_msg('group_watch_alert', ['group' => (string) ($ch['name'] ?? ''), 'who' => $q['name'] !== '' ? $q['name'] : '+' . $w, 'mins' => (string) (int) round(($now - $q['t']) / 60), 'text' => mb_substr(preg_replace('/\s+/u', ' ', $q['text']), 0, 160)]) . $tg; } }
    // new cases nobody of the team talked to
    $cm = max(1, (int) ($c['watch_case_hours'] ?? 2));
    foreach (wf_cases($aid) as $ph => $cs) { if (!is_array($cs) || ($cs['status'] ?? 'new') !== 'new' || wf_case_is_private($cs) || ($cs['by'] ?? '') === 'analysis') continue; $ct = strtotime((string) ($cs['created'] ?? '')); $key = 'case:' . $ph;
        if (!$ct || $ct < $now - 3 * 86400 || $now - $ct < $cm * 3600 || isset($sent[$key]) || !empty($cs['status_log']) || array_filter((array) ($cs['notes'] ?? []), fn($n) => ($n['by'] ?? 'bot') !== 'bot')) continue;
        $sent[$key] = $now; $hp = $fol[(string) $ph] ?? ''; foreach (array_filter(array_map('wf_phone', explode(',', (string) ($cs['reviewers'] ?? '')))) as $rv) $tags[$rv] = 1; if ($hp !== '') $tags[$hp] = 1;
        $alerts[] = wf_msg('case_untaken_alert', ['name' => (string) ($cs['name'] ?? '') ?: '+' . $ph, 'no' => (string) ($cs['no'] ?? ''), 'hours' => (string) (int) round(($now - $ct) / 3600)]) . ($hp !== '' ? ' @' . $hp : ''); }
    foreach ($sent as $k => $t) if ($t < $now - 7 * 86400) unset($sent[$k]);
    wi_update('group_watch.json', function ($G) use ($aid, $sent) { $G[$aid]['sent'] = $sent; $G[$aid]['at'] = date('c'); return $G; });
    if ($alerts) { $msg = wf_msg('group_watch_head', ['name' => (string) ($acc['name'] ?? $aid)]) . "\n\n" . implode("\n\n", array_slice($alerts, 0, 8));
        $to = (string) ($c['watch_to'] ?? ''); // a group chosen in Bot → Switch on / off gets the alert; else the admins privately
        if (preg_match('/^[0-9-]{6,40}@g\.us$/', $to)) wi_gateway('/chat/send', ['jid' => $to, 'text' => $msg, 'via' => 'bot', 'mentions' => array_map(fn($p) => $p . (strlen($p) >= 14 ? '@lid' : '@s.whatsapp.net'), array_keys($tags))], $acc); else $tell($msg); } // the healer of each case is tagged in the group
    return ['alerts' => count($alerts)];
}
