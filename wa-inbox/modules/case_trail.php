<?php
// WhatsApp Inbox — case_trail: who followed a case, where and when.
// The person is found by number and WhatsApp's long ID in the private chat and in every group; a team message counts as
// following the case when it answers or tags the person (sure), or — when switched on — right after the person's own message (likely).

/** The person's IDs: the mobile number and the long WhatsApp IDs seen for it. */
function wf_trail_ids(array $acc, string $ph, array $chats): array {
    $ids = [$ph => 1]; $c = function_exists('wf_case_get') ? wf_case_get((string) $acc['id'], $ph) : null;
    foreach ((array) ($c['lids'] ?? []) as $l) if (($d = preg_replace('/\D/', '', (string) $l)) !== '') $ids[$d] = 1;
    foreach ($chats as $ch) { $j = (string) ($ch['jid'] ?? ''); if (!str_ends_with($j, '@lid')) continue;
        $p = wf_phone((string) ($ch['phone'] ?? '')); if ($p === '') $p = wf_phone((string) (wi_contact($j, $acc)['phone'] ?? '')); if ($p === $ph) $ids[explode('@', $j)[0]] = 1; }
    return $ids;
}
/** The follow-up trail of a person: places, followers in order, and the events (newest last). Kept for 10 minutes. */
function wf_case_trail(array $acc, string $ph, bool $fresh = false): array {
    $aid = (string) $acc['id']; $ph = wf_phone($ph); if ($ph === '') return ['places' => [], 'followers' => [], 'events' => []];
    $cf = WI_DIR . '/trail_' . preg_replace('/\W/', '', $aid) . '_' . $ph . '.json';
    if (!$fresh && is_file($cf) && filemtime($cf) > time() - 600) return (array) json_decode((string) file_get_contents($cf), true);
    $likely = !empty(wf_bot_run_cfg($aid)['trail_likely']);
    // the private chats of the last months (to find the person's long IDs) and every group of the number (no whole-archive read: lighter on the gateway)
    $chats = array_values(array_filter((array) (wi_gateway('/archive/chats?months=3', null, $acc)['chats'] ?? []), fn($c) => empty($c['group'])));
    foreach ((array) (wi_gateway('/groups', null, $acc)['groups'] ?? []) as $g) $chats[] = ['jid' => (string) $g['jid'], 'name' => (string) ($g['name'] ?? $g['subject'] ?? ''), 'group' => true];
    $ids = wf_trail_ids($acc, $ph, $chats); $isMe = fn($v) => isset($ids[preg_replace('/\D/', '', explode(':', explode('@', (string) $v)[0])[0])]);
    $places = []; $ev = []; $names = [];
    $txt = fn($m) => mb_substr(preg_replace('/\s+/u', ' ', trim((string) ($m['text'] ?? ''))) ?: '[' . (string) ($m['kind'] ?? '') . ']', 0, 120);
    foreach ($chats as $ch) { $j = (string) ($ch['jid'] ?? ''); $grp = !empty($ch['group']);
        if (!$grp && !$isMe($j)) continue; // private: only the person's own chat
        $rows = (array) (wi_gateway('/archive/thread?limit=4000&jid=' . rawurlencode($j), null, $acc)['rows'] ?? []);
        $team = $grp ? wf_team($acc, [$j => []]) : []; $own = 0; $ref = 0; $last = ''; $prevMe = 0;
        foreach ($rows as $m) { $t = strtotime((string) ($m['time'] ?? '')); if (!$t) continue; $out = ($m['dir'] ?? '') === 'out';
            if (!$grp) { // the private chat: the person, the bot, or someone on the number's phone
                if ($out) { $who = ($m['via'] ?? '') === 'bot' ? 'bot' : 'owner'; $ev[] = ['t' => $t, 'who' => $who, 'where' => $j, 'text' => $txt($m), 'how' => 'sure']; $ref++; }
                else { $ev[] = ['t' => $t, 'who' => 'person', 'where' => $j, 'text' => $txt($m), 'how' => 'sure']; $own++; }
                $last = max($last, (string) $m['time']); continue; }
            $w = $out ? 'owner' : wf_phone((string) ($m['from'] ?? '')); if (!$out && !empty($m['from_name'])) $names[$w] = (string) $m['from_name'];
            if (!$out && $isMe($w)) { $own++; $prevMe = $t; $last = max($last, (string) $m['time']); $ev[] = ['t' => $t, 'who' => 'person', 'where' => $j, 'text' => $txt($m), 'how' => 'sure']; continue; }
            $tag = $isMe($m['quote_from'] ?? ''); foreach ((array) ($m['mentions'] ?? []) as $x) if ($isMe($x)) $tag = true;
            if (preg_match_all('/@(\d{8,})/', (string) ($m['text'] ?? ''), $mm)) foreach ($mm[1] as $x) if (isset($ids[$x])) $tag = true; // a tag written in the text (old history has no tag list)
            if (!$out && $w === '' && $tag) $w = 'history'; // an old message with no sender kept
            elseif (!$out && !isset($team[$w])) { $prevMe = 0; continue; } // another member spoke in between
            if ($tag) { $ref++; $last = max($last, (string) $m['time']); $ev[] = ['t' => $t, 'who' => $w, 'where' => $j, 'text' => $txt($m), 'how' => 'sure']; }
            elseif ($likely && $prevMe && $t - $prevMe <= 300 && empty($m['quote_from']) && empty($m['mentions'])) { $ev[] = ['t' => $t, 'who' => $w, 'where' => $j, 'text' => $txt($m), 'how' => 'likely']; }
            $prevMe = 0; }
        if ($own || $ref) $places[] = ['jid' => $j, 'name' => $grp ? (string) ($ch['name'] ?? $j) : '', 'group' => $grp, 'own' => $own, 'ref' => $ref, 'last' => $last]; }
    // the case file: stage changes, notes and programs, with who did them
    if ($c = wf_case_get($aid, $ph)) { $st = (array) (wf_case_cfg($aid)['statuses'] ?? []);
        foreach ((array) ($c['status_log'] ?? []) as $l) $ev[] = ['t' => strtotime((string) ($l['at'] ?? '')), 'who' => (string) ($l['by'] ?? ''), 'where' => 'case', 'text' => '→ ' . (string) ($st[$l['to'] ?? ''] ?? ($l['to'] ?? '')), 'how' => 'sure'];
        foreach ((array) ($c['notes'] ?? []) as $n) if (($n['by'] ?? '') !== 'bot') $ev[] = ['t' => strtotime((string) ($n['at'] ?? '')), 'who' => (string) ($n['by'] ?? ''), 'where' => 'case', 'text' => mb_substr((string) ($n['text'] ?? ''), 0, 120), 'how' => 'sure']; }
    usort($ev, fn($a, $b) => $a['t'] <=> $b['t']);
    $fol = []; foreach ($ev as $e) { if (in_array($e['who'], ['person', ''], true)) continue; $k = $e['who']; $fol[$k] ??= ['who' => $k, 'name' => $names[$k] ?? '', 'n' => 0, 'sure' => 0, 'first' => $e['t'], 'last' => $e['t'], 'places' => []];
        $fol[$k]['n']++; if ($e['how'] === 'sure') $fol[$k]['sure']++; $fol[$k]['last'] = $e['t']; $fol[$k]['places'][$e['where']] = 1; }
    foreach ($fol as &$f) $f['places'] = count($f['places']); unset($f);
    usort($places, fn($a, $b) => strcmp($b['last'], $a['last'])); $fol = array_values($fol); usort($fol, fn($a, $b) => $a['first'] <=> $b['first']);
    $out = ['places' => $places, 'followers' => $fol, 'events' => array_slice($ev, -300), 'names' => $names, 'at' => date('c')];
    @file_put_contents($cf, json_encode($out, JSON_UNESCAPED_UNICODE)); @chmod($cf, 0600);
    return $out;
}
/** Short lines for the case report: where the case is followed, and who followed it in order. */
function wf_trail_lines(array $tr): array {
    $pl = []; foreach ($tr['places'] as $p) $pl[] = $p['group'] ? $p['name'] : wf_msg('trail_private');
    $who = function (array $f): string { if ($f['who'] === 'owner') return wf_msg('trail_owner'); if ($f['who'] === 'bot') return wf_msg('trail_bot'); if ($f['who'] === 'history') return wf_msg('trail_history');
        return $f['name'] !== '' ? $f['name'] : (preg_match('/^\d{8,}$/', $f['who']) ? '+' . $f['who'] : $f['who']); };
    $fo = []; foreach ($tr['followers'] as $f) $fo[] = $who($f) . ' (' . date('Y-m-d', $f['first']) . ')';
    return ['places' => implode(' · ', $pl), 'followers' => implode(' ← ', $fo)];
}
function wf_api_case_trail(string $api): void {
    if ($api !== 'case_trail') return;
    $acc = wi_current(); if (!$acc) wi_json(['ok' => false, 'error' => 'No number chosen.'], 422); $ph = wf_phone((string) ($_GET['phone'] ?? ''));
    $c = wf_case_get((string) $acc['id'], $ph); if ($c && wf_case_is_private($c) && !wf_case_private_ok()) wi_json(['ok' => false, 'error' => 'Case not found.']);
    @set_time_limit(120); wi_json(['ok' => true] + wf_case_trail($acc, $ph, !empty($_GET['fresh'])));
}
