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
    // entries: every time the person was added to / left a group (WhatsApp's membership events), and how often they came back
    $joins = []; foreach ($chats as $ch) { if (empty($ch['group'])) continue; foreach ((array) (wi_gateway('/groups/events?jid=' . rawurlencode((string) $ch['jid']), null, $acc)['events'] ?? []) as $e) if ($isMe($e['member'] ?? '')) $joins[] = ['g' => (string) $ch['jid'], 'name' => (string) ($ch['name'] ?? ''), 't' => strtotime((string) ($e['time'] ?? '')), 'action' => (string) ($e['action'] ?? '')]; }
    usort($joins, fn($a, $b) => $a['t'] <=> $b['t']); $adds = array_values(array_filter($joins, fn($j) => $j['action'] === 'add')); $per = []; foreach ($adds as $j) $per[$j['g']] = ($per[$j['g']] ?? 0) + 1;
    $entry = ['adds' => count($adds), 'back' => array_sum(array_map(fn($n) => max(0, $n - 1), $per)), 'left' => count(array_filter($joins, fn($j) => $j['action'] === 'remove')), 'list' => array_slice($joins, -30)];
    // sessions: the written texts (ruqyah sessions) sent to the person — by their tag, their title or their opening words
    $lt = []; foreach (wf_lib_texts($aid) as $x) { $k = ['code' => str_replace(['-', ' ', '_'], '', wf_intake_norm(trim((string) ($x['code'] ?? '')))), 'title' => (string) ($x['title'] ?? ''), 'open' => mb_substr(wf_intake_norm(preg_replace('/\s+/u', ' ', (string) ($x['text'] ?? ''))), 0, 40)]; if ($k['title'] !== '') $lt[] = $k; }
    $sess = []; foreach ($ev as $e) { $n = wf_intake_norm((string) $e['text']); if ($n === '') continue; $nc = str_replace(['-', ' ', '_'], '', $n); // a tag typed with spaces or dashes
        foreach ($lt as $k) if (($k['code'] !== '' && mb_strpos($nc, $k['code']) !== false) || (mb_strlen($k['open']) >= 25 && mb_strpos($n, mb_substr($k['open'], 0, 25)) === 0)) { $d = date('Y-m-d', $e['t']); $sess[$k['title'] . '|' . $d] = ['title' => $k['title'], 'day' => $d]; break; } }
    $byT = []; foreach ($sess as $x) $byT[$x['title']] = ($byT[$x['title']] ?? 0) + 1; arsort($byT);
    $sessions = ['count' => count($sess), 'titles' => $byT];
    // the course: from the first sign of the person (entry, message or case) to the case closing, else to the last contact
    $cc = wf_case_get($aid, $ph); $tt = array_filter([$joins[0]['t'] ?? 0, $ev[0]['t'] ?? 0, $cc ? strtotime((string) ($cc['created'] ?? '')) : 0]);
    $endT = 0; $closed = false; foreach ((array) ($cc['status_log'] ?? []) as $l) if (in_array($l['to'] ?? '', ['closed', 'archived'], true)) { $endT = strtotime((string) $l['at']); $closed = true; }
    if (!$endT && $ev) $endT = (int) end($ev)['t'];
    $span = ['from' => $tt ? min($tt) : 0, 'to' => $endT, 'closed' => $closed, 'days' => ($tt && $endT) ? (int) max(1, round(($endT - min($tt)) / 86400)) : 0];
    $fol = []; foreach ($ev as $e) { if (in_array($e['who'], ['person', ''], true)) continue; $k = $e['who']; $fol[$k] ??= ['who' => $k, 'name' => $names[$k] ?? '', 'n' => 0, 'sure' => 0, 'first' => $e['t'], 'last' => $e['t'], 'places' => []];
        $fol[$k]['n']++; if ($e['how'] === 'sure') $fol[$k]['sure']++; $fol[$k]['last'] = $e['t']; $fol[$k]['places'][$e['where']] = 1; }
    foreach ($fol as &$f) $f['places'] = count($f['places']); unset($f);
    usort($places, fn($a, $b) => strcmp($b['last'], $a['last'])); $fol = array_values($fol); usort($fol, fn($a, $b) => $a['first'] <=> $b['first']);
    $out = ['places' => $places, 'followers' => $fol, 'events' => array_slice($ev, -300), 'names' => $names, 'entry' => $entry, 'sessions' => $sessions, 'span' => $span, 'at' => date('c')];
    @file_put_contents($cf, json_encode($out, JSON_UNESCAPED_UNICODE)); @chmod($cf, 0600);
    return $out;
}
/** Short lines for the case report: where the case is followed, and who followed it in order. */
function wf_trail_lines(array $tr): array {
    $pl = []; foreach ($tr['places'] as $p) $pl[] = $p['group'] ? $p['name'] : wf_msg('trail_private');
    $who = function (array $f): string { if ($f['who'] === 'owner') return wf_msg('trail_owner'); if ($f['who'] === 'bot') return wf_msg('trail_bot'); if ($f['who'] === 'history') return wf_msg('trail_history');
        return $f['name'] !== '' ? $f['name'] : (preg_match('/^\d{8,}$/', $f['who']) ? '+' . $f['who'] : $f['who']); };
    $fo = []; foreach ($tr['followers'] as $f) $fo[] = $who($f) . ' (' . date('Y-m-d', $f['first']) . ')';
    $sp = (array) ($tr['span'] ?? []); $en = (array) ($tr['entry'] ?? []); $se = (array) ($tr['sessions'] ?? []);
    $top = implode(', ', array_map(fn($t, $n) => $t . ($n > 1 ? ' ×' . $n : ''), array_keys(array_slice((array) ($se['titles'] ?? []), 0, 3, true)), array_slice((array) ($se['titles'] ?? []), 0, 3)));
    return ['places' => implode(' · ', $pl), 'followers' => implode(' ← ', $fo),
        'course' => !empty($sp['from']) ? wf_msg('trail_course', ['from' => date('Y-m-d', (int) $sp['from']), 'to' => date('Y-m-d', (int) $sp['to']), 'days' => (string) ($sp['days'] ?? 0), 'state' => !empty($sp['closed']) ? wf_msg('trail_closed') : wf_msg('trail_open')]) : '—',
        'entry' => wf_msg('trail_entry', ['adds' => (string) ($en['adds'] ?? 0), 'back' => (string) ($en['back'] ?? 0), 'left' => (string) ($en['left'] ?? 0)]),
        'sessions' => wf_msg('trail_sessions', ['n' => (string) ($se['count'] ?? 0), 'top' => $top !== '' ? $top : '—'])];
}
function wf_api_case_trail(string $api): void {
    if ($api !== 'case_trail') return;
    $acc = wi_current(); if (!$acc) wi_json(['ok' => false, 'error' => 'No number chosen.'], 422); $ph = wf_phone((string) ($_GET['phone'] ?? ''));
    $c = wf_case_get((string) $acc['id'], $ph); if ($c && wf_case_is_private($c) && !wf_case_private_ok()) wi_json(['ok' => false, 'error' => 'Case not found.']);
    @set_time_limit(120); wi_json(['ok' => true] + wf_case_trail($acc, $ph, !empty($_GET['fresh'])));
}
