<?php
// WhatsApp Inbox — cases (split out of features.php in v3.8, code unchanged)

/* ---------- Cases (follow-up of people in monitored groups) ---------- */
const WF_CASE_STATUSES = ['new' => '🆕 New', 'follow' => '🩺 Under follow-up', 'improving' => '📈 Improving', 'closed' => '✅ Closed'];
function wf_case_groups(string $aid): array { return wi_read('case_groups.json', [])[$aid] ?? []; }
function wf_cases(string $aid): array { return wi_read('cases.json', [])[$aid] ?? []; }
function wf_case_file(string $aid, string $phone): string {
    $dir = WI_DIR . '/cases/' . preg_replace('/[^A-Za-z0-9_-]/', '', $aid);
    if (!is_dir($dir)) { @mkdir($dir, 0700, true); @chmod($dir, 0700); @chmod(dirname($dir), 0700); }
    return $dir . '/' . wf_phone($phone) . '.json';
}
/** Group threads cached for one request (cases sync reads the same group many times). */
function wf_group_rows(array $acc, string $gjid): array {
    static $cache = [];
    $k = $acc['id'] . '|' . $gjid;
    if (!isset($cache[$k])) { $t = wi_gateway('/archive/thread?limit=60000&jid=' . rawurlencode($gjid), null, $acc); $cache[$k] = $t['rows'] ?? []; }
    return $cache[$k];
}
/** Numbers that are never a case: our own numbers, the Inbox users, the admins of the followed groups, and anyone marked as team. */
function wf_team(array $acc, array $groups = []): array {
    $aid = (string) $acc['id']; $t = [];
    $cf = WI_DIR . '/cache_team_' . preg_replace('/\W/', '', $aid) . '_' . substr(md5(json_encode(array_keys($groups))), 0, 8) . '.json';
    if (is_file($cf) && filemtime($cf) > time() - 120) { $c = json_decode((string) file_get_contents($cf), true); if (is_array($c)) return $c + array_map(fn() => 'team', (array) (wi_read('team.json', [])[$aid] ?? [])); }
    foreach ((array) (wi_read('team.json', [])[$aid] ?? []) as $p => $x) $t[(string) $p] = 'team';
    foreach (wi_users() as $u) if (!empty($u['phone'])) $t[wf_phone($u['phone'])] = 'user';
    foreach (wi_accounts() as $a) { $h = wi_gateway('/health', null, $a); $me = wf_phone(explode(':', (string) ($h['account'] ?? ''))[0]); if ($me !== '') $t[$me] = 'own number'; }
    foreach (array_keys($groups) as $gj) { $m = wi_gateway('/groups/members?jid=' . rawurlencode($gj), null, $acc); foreach ($m['members'] ?? [] as $x) if (!empty($x['admin'])) { $t[wf_phone($x['phone'])] = 'group admin'; $t[wf_phone(explode('@', (string) ($x['jid'] ?? ''))[0])] = 'group admin'; } }
    unset($t['']);
    @file_put_contents($cf, json_encode($t)); @chmod($cf, 0600);
    return $t;
}
/** Every number a person is known by: phone + WhatsApp internal IDs (LID) seen for them. */
function wf_case_ids(array $c): array { return array_values(array_unique(array_filter(array_merge([(string) ($c['phone'] ?? '')], (array) ($c['lids'] ?? []))))); }
/**
 * Rebuilds case rows. Monitored groups: every member who writes becomes a case.
 * Other groups: only people you chose to track (from the group page) are followed there.
 */
function wf_cases_sync(array $acc): array {
    $aid = (string) $acc['id'];
    $monitored = wf_case_groups($aid);
    $all = wi_read('cases.json', []);
    $cases = $all[$aid] ?? [];
    $groups = $monitored;
    foreach ($cases as $c) foreach (($c['groups'] ?? []) as $gj => $gn) if (!isset($groups[$gj])) $groups[$gj] = ['name' => $gn, 'tracked_only' => true];
    if (!$groups) return ['groups' => 0, 'cases' => 0];
    $cust = wi_customers();
    $team = wf_team($acc, $monitored);
    // Map every known id (phone or LID) to its case.
    $idMap = []; foreach ($cases as $ph => $c) foreach (wf_case_ids($c) as $id) $idMap[$id] = $ph;
    $seen = [];
    foreach ($groups as $gjid => $g) {
        foreach (wf_group_rows($acc, $gjid) as $m) {
            if (($m['dir'] ?? '') !== 'in' || empty($m['from'])) continue;
            $ph = wf_phone($m['from']); $lid = wf_phone($m['from_lid'] ?? '');
            if ($ph === '') continue;
            $key = $idMap[$ph] ?? ($lid !== '' ? ($idMap[$lid] ?? null) : null);
            if ($key === null) { if (!empty($g['tracked_only']) || isset($team[$ph]) || ($lid !== '' && isset($team[$lid]))) continue; $key = $ph; }
            $c = $cases[$key] ?? ['phone' => $key, 'status' => 'new', 'created' => date('c'), 'notes' => [], 'data' => []];
            if (!isset($seen[$key])) { $seen[$key] = true; $c['msgs'] = 0; $c['media'] = 0; $c['groups'] = array_intersect_key((array) ($c['groups'] ?? []), $groups); $c['first'] = $m['time']; $c['last'] = $m['time']; }
            if ($lid !== '' && $lid !== $key) { $c['lids'] = array_values(array_unique(array_merge((array) ($c['lids'] ?? []), [$lid]))); $idMap[$lid] = $key; }
            if ($ph !== $key && strlen($ph) >= 14) { $c['lids'] = array_values(array_unique(array_merge((array) ($c['lids'] ?? []), [$ph]))); }
            $c['msgs']++;
            if (($m['kind'] ?? 'text') !== 'text') $c['media']++;
            $c['groups'][$gjid] = $g['name'] ?? $gjid;
            if ($m['time'] < $c['first']) $c['first'] = $m['time'];
            if ($m['time'] > $c['last']) { $c['last'] = $m['time']; $c['last_text'] = mb_substr((string) ($m['text'] ?? ''), 0, 160); }
            $nm = (string) ($m['from_name'] ?? ''); if ($nm !== '') $c['name'] = $nm;
            if (isset($cust[$key])) $c['customer'] = $cust[$key];
            $cases[$key] = $c;
        }
    }
    // Only what the sync counts is written back, on the stored cases under the lock: a note, a stage or a new case saved
    // while the groups were being read (this takes a while) is kept.
    $own = ['msgs', 'media', 'groups', 'first', 'last', 'last_text', 'lids', 'name', 'customer'];
    wf_cases_update($aid, function (array $C) use ($seen, $cases, $own) {
        foreach (array_keys($seen) as $key) { if (!isset($C[$key])) { $C[$key] = $cases[$key]; continue; }
            foreach ($own as $f) if (array_key_exists($f, $cases[$key])) $C[$key][$f] = $cases[$key][$f]; }
        return $C; });
    $n = 0;
    foreach ($cases as $ph => $c) if (in_array($c['status'] ?? 'new', ['follow', 'improving'], true)) { wf_case_snapshot($acc, (string) $ph); $n++; }
    return ['groups' => count($groups), 'cases' => count($cases), 'snapshots' => $n];
}
/**
 * Everything belonging to one person, without mixing in other cases:
 * - their own messages in the groups (matched by phone or their LID),
 * - replies that QUOTE one of their messages, and messages that @mention them (explicit links only),
 * - their private chat with us.
 */
function wf_case_messages(array $acc, string $phone): array {
    $aid = (string) $acc['id'];
    $case = wf_cases($aid)[$phone] ?? ['phone' => $phone];
    $r = wi_gateway('/archive/person?ids=' . rawurlencode(implode(',', wf_case_ids($case))), null, $acc);
    $out = [];
    foreach ($r['rows'] ?? [] as $m) $out[] = $m + ['chat' => ($m['src'] ?? '') === 'private' ? 'Private chat' : ($m['chat'] ?? '')];
    return $out;
}
function wf_case_snapshot(array $acc, string $phone): array {
    $msgs = wf_case_messages($acc, $phone);
    $cr = wf_cases((string) $acc['id'])[$phone] ?? [];
    $snap = ['phone' => $phone, 'saved_at' => date('c'), 'name' => $cr['name'] ?? '', 'profile' => $cr['data'] ?? [], 'report' => $cr['report'] ?? '', 'report_at' => $cr['report_at'] ?? null, 'count' => count($msgs), 'messages' => $msgs];
    file_put_contents(wf_case_file((string) $acc['id'], $phone), json_encode($snap, JSON_UNESCAPED_UNICODE)); @chmod(wf_case_file((string) $acc['id'], $phone), 0600);
    return $snap;
}


/** API calls of this area (moved unchanged from the old single switch). */
/** Changes one case without overwriting what others changed meanwhile (the cases store is read and written under a lock). */
/* ---------- The case store: the only place that reads and writes cases.json ---------- */
/** The report with the case ID on top, so the diagnosis group can answer by ID (#الحالة ID …). */
function wf_report_id(string $report, $no): string { $no = (string) $no; return $no === '' || preg_match('/^\D{0,4}ID ' . preg_quote($no, '/') . '\b/u', $report) ? $report : '🆔 ID ' . $no . "\n" . $report; }
/** Saves a new case once so it gets its ID now (before its report is posted). Returns the ID. */
function wf_case_reserve_no(string $aid, string $key, array $draft): string {
    if (!empty($draft['no'])) return (string) $draft['no'];
    wf_case_update($aid, $key, fn(?array $x) => $x ?? $draft);
    return (string) (wf_case_get($aid, $key)['no'] ?? '');
}
function wf_case_get(string $aid, string $key): ?array { $c = wf_cases($aid)[$key] ?? null; return is_array($c) ? $c : null; }
/** Change one number's cases under the lock: $fn gets them and returns them. */
function wf_cases_update(string $aid, callable $fn): void { wi_update('cases.json', function ($A) use ($aid, $fn) { $A[$aid] = $fn((array) ($A[$aid] ?? [])); return $A; }); }
/** Older cases without an ID get one (a write through the store numbers them). Cheap when all have one. */
function wf_cases_number_backfill(): void { foreach ((array) wi_read('cases.json', []) as $cs) foreach ((array) $cs as $c) if (is_array($c) && empty($c['no'])) { wi_update('cases.json', fn($A) => $A); return; } }
/** Change one case under the lock: $fn gets it (null when new) and returns it (null = delete). Returns the saved case. */
function wf_case_update(string $aid, string $key, callable $fn): ?array {
    $out = null; wf_cases_update($aid, function (array $C) use ($key, $fn, &$out) { $c = $fn($C[$key] ?? null); if ($c === null) unset($C[$key]); else $C[$key] = $c; $out = $c; return $C; });
    return $out;
}
function wf_case_put(string $aid, string $ph, ?array $case): void { wi_update('cases.json', function ($A) use ($aid, $ph, $case) { if ($case === null) unset($A[$aid][$ph]); else $A[$aid][$ph] = $case; return $A; }); }
function wf_api_cases(string $api): void {
    switch ($api) {
    /* ----- Cases ----- */
    case 'cases': {
        $aid = wf_aid();
        if (!empty($_GET['sync'])) { @set_time_limit(200); wf_cases_sync(wi_current()); }
        $cases = array_values(wf_cases($aid));
        usort($cases, fn($a, $b) => strcmp((string) ($b['last'] ?? ''), (string) ($a['last'] ?? '')));
        $counts = array_count_values(array_map(fn($c) => $c['status'] ?? 'new', $cases));
        wi_json(['ok' => true, 'cases' => $cases, 'counts' => $counts, 'groups' => wf_case_groups($aid), 'statuses' => wf_case_statuses(wf_aid()), 'case_cfg' => wf_case_cfg(wf_aid())]);
    }
    case 'case': {
        $acc = wi_current(); $ph = wf_phone($_GET['phone'] ?? '');
        $c = wf_cases((string) $acc['id'])[$ph] ?? null;
        if (!$c) wi_json(['ok' => false, 'error' => 'Case not found.']);
        $f = wf_case_file((string) $acc['id'], $ph);
        $snap = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
        $msgs = $snap['messages'] ?? wf_case_messages($acc, $ph);
        if (wi_cases_only()) $msgs = array_values(array_filter($msgs, fn($m) => ($m['src'] ?? '') !== 'private')); // the number's private chats stay hidden
        $reg = wi_read('registry.json', [])[(string) $acc['id']] ?? []; $entries = [];
        foreach ((array) ($c['registry'] ?? []) as $rid) if (isset($reg[$rid])) $entries[] = $reg[$rid];
        foreach ($reg as $e) if (!in_array($e, $entries, true) && in_array($ph, $e['phones'] ?? [], true)) $entries[] = $e; // other reports with this number
        wi_json(['ok' => true, 'case' => $c, 'messages' => $msgs, 'registry' => $entries, 'snapshot_at' => $snap['saved_at'] ?? null, 'statuses' => wf_case_statuses(wf_aid()), 'case_cfg' => wf_case_cfg(wf_aid())]);
    }
    case 'case_team': {
        // "Not a case": a team member / admin. Their number is excluded from automatic cases.
        $in = wf_post(); $acc = wi_current(); $aid = (string) $acc['id']; $ph = wf_phone($in['phone'] ?? '');
        if ($ph === '') wi_json(['ok' => false, 'error' => 'No number.']);
        $t = wi_read('team.json', []); $t[$aid][$ph] = ['at' => date('c'), 'by' => $_SESSION['email']]; wi_write('team.json', $t);
        $all = wi_read('cases.json', []); $c = $all[$aid][$ph] ?? null;
        foreach ((array) ($c['lids'] ?? []) as $l) $t[$aid][$l] = ['at' => date('c'), 'by' => $_SESSION['email']];
        wi_write('team.json', $t);
        if ($c) { $bk = wi_read('cases_removed.json', []); $bk[$aid][$ph] = $c + ['removed_at' => date('c'), 'removed_by' => $_SESSION['email']]; wi_write('cases_removed.json', $bk); wf_case_put($aid, $ph, null); @unlink(wf_case_file($aid, $ph)); }
        wi_log('case_team', ['account' => $aid, 'phone' => $ph]);
        wi_json(['ok' => true]);
    }
    case 'case_new': {
        // A new case from the form: saved as a case, gets its own file, and the report is posted to the diagnosis group.
        $in = wf_post(); $acc = wi_current(); $aid = wf_aid(); @set_time_limit(120);
        $f = fn($k, $n = 300) => trim(mb_substr((string) ($in[$k] ?? ''), 0, $n));
        $name = $f('name', 80); $phone = wi_norm_phone($f('phone', 30)) ?: preg_replace('/\D/', '', $f('phone', 30));
        $join = $f('join', 20) ?: date('d/m/Y');
        $sym = array_values(array_filter(array_map('trim', preg_split('/\R/u', $f('symptoms', 4000)))));
        $sym = array_map(fn($x) => preg_replace('/^\s*\d+\s*[\.\-\)]\s*/u', '', $x), $sym);
        $by = $f('follower', 60) ?: (wi_me()['name'] ?? '');
        $symList = implode("\n\n", array_map(fn($i, $x) => ($i + 1) . '. ' . $x, array_keys($sym), $sym));
        $report = wf_case_report($aid, ['join' => $join, 'name' => $name, 'phone' => $phone, 'age' => $f('age', 20), 'marital' => $f('marital', 40), 'kids' => $f('kids', 20), 'country' => $f('country', 40), 'follower' => $by, 'story' => $f('story', 4000),
            'symptoms' => $f('symptoms', 4000), 'extra' => $f('extra', 2000), 'diagnosis' => $f('diagnosis', 300), 'diag_by' => $f('diag_by', 60) ?: $by, 'placement' => $f('placement', 120)]);
        $report = trim(preg_replace("/\n{3,}/", "\n\n", $report));
        if (!empty($in['preview'])) wi_json(['ok' => true, 'report' => $report]); // the form's live preview
        if ($name === '' || strlen($phone) < 8) wi_json(['ok' => false, 'error' => 'Write the name and a valid phone number.']);
        // 1) the case record
        $c = wf_case_get($aid, $phone) ?? ['phone' => $phone, 'created' => date('c'), 'notes' => [], 'groups' => []];
        $c['name'] = $name; $c['status'] = in_array($in['status'] ?? '', array_keys(wf_case_statuses(wf_aid())), true) ? $in['status'] : 'follow'; $c['manual'] = true;
        $c['data'] = array_filter(['join' => $join, 'age' => $f('age', 20), 'marital' => $f('marital', 40), 'kids' => $f('kids', 20), 'country' => $f('country', 40), 'follower' => $f('follower', 60), 'story' => $f('story', 4000), 'symptoms' => $sym, 'extra' => $f('extra', 2000), 'diagnosis' => $f('diagnosis', 300), 'diag_by' => $f('diag_by', 60), 'placement' => $f('placement', 120), 'tgroup' => wf_jid($f('tgroup', 80)), 'tgroup_name' => $f('tgroup_name', 120), 'follower_phone' => preg_replace('/\D/', '', $f('follower_phone', 20))]);
        if (!empty($c['data']['tgroup'])) $c['groups'][$c['data']['tgroup']] = $c['data']['tgroup_name'] ?: $c['data']['tgroup']; // the case is followed in its treatment group too
        $c['report'] = $report; $c['report_at'] = date('c'); $c['by'] = $_SESSION['email'];
        $c['notes'][] = ['at' => date('c'), 'by' => $_SESSION['email'], 'text' => 'Case added from the form.'];
        // 2) post a copy of the report to the diagnosis group, with the case ID on top
        $c['no'] = wf_case_reserve_no($aid, $phone, $c); $report = wf_report_id($report, $c['no']); $c['report'] = $report;
        $gj = wf_jid((string) ($in['group'] ?? '')); $groups = wf_registry_groups($aid); $sent = null;
        if ($gj === '' && $groups) $gj = (string) array_key_first($groups);
        if (!empty($in['send']) && $gj !== '') {
            $own = null; foreach (wi_accounts() as $a) if (!empty($a['owner']) && strtolower((string) $a['owner']) === strtolower((string) $_SESSION['email'])) $own = $a;
            $r = ['ok' => false];
            if ($own && ($in['send_from'] ?? 'mine') === 'mine') { $r = wi_gateway('/chat/send', ['jid' => $gj, 'text' => $report, 'by' => (string) $_SESSION['email']], $own); if (!empty($r['ok'])) $c['sent_from'] = 'own'; }
            if (empty($r['ok'])) $r = wi_gateway('/chat/send', ['jid' => $gj, 'text' => $report, 'by' => (string) $_SESSION['email']], $acc);
            if (!empty($r['id']) && empty($c['sent_from'])) { $c['diag_msg'] = (string) $r['id']; $c['diag_jid'] = $gj; } // stage changes reply to this report
            $sent = !empty($r['ok']); if ($sent) { $c['groups'][$gj] = $groups[$gj]['name'] ?? $gj; $c['report_msg'] = $r['id'] ?? null; }
        }
        wf_case_put($aid, $phone, $c);
        // 3) the case file (profile + report + every message we already have for this number)
        try { wf_case_snapshot($acc, $phone); } catch (Throwable $e) {}
        // the registry shows it right away (the next scan replaces it with the real group post)
        $reg = wi_read('registry.json', []); $rid = 'M' . strtoupper(bin2hex(random_bytes(8)));
        $reg[$aid][$rid] = ['id' => $rid, 'group' => $gj, 'group_name' => $groups[$gj]['name'] ?? '', 'poster' => wf_phone((string) (wi_me()['phone'] ?? '')), 'poster_name' => wi_me()['name'] ?? '', 'time' => gmdate('Y-m-d\TH:i:s.v\Z'), 'phones' => [$phone], 'items' => [['id' => $rid, 'kind' => 'text', 'time' => gmdate('Y-m-d\TH:i:s.v\Z'), 'text' => $report]], 'text' => $report, 'name_guess' => $name, 'labels' => [], 'manual' => true];
        wi_write('registry.json', $reg);
        wi_log('case_new', ['account' => $aid, 'phone' => $phone, 'sent' => $sent]);
        wi_json(['ok' => true, 'phone' => $phone, 'sent' => $sent, 'group' => $groups[$gj]['name'] ?? '', 'report' => $report]);
    }
    case 'case_set': {
        $in = wf_post(); $acc = wi_current(); $aid = (string) $acc['id']; $ph = wf_phone($in['phone'] ?? '');
        if (!wf_case_get($aid, $ph)) wi_json(['ok' => false, 'error' => 'Case not found.']);
        $email = (string) $_SESSION['email']; $statuses = wf_case_statuses(wf_aid()); $fields = array_keys(wf_case_cfg($aid)['fields']);
        $saved = wf_case_update($aid, $ph, function (?array $c) use ($in, $email, $statuses, $fields) { if ($c === null) return null; // changed on the stored case: nothing saved meanwhile is lost
        if (isset($in['status']) && isset($statuses[$in['status']])) { $c['status_log'][] = ['from' => $c['status'] ?? 'new', 'to' => $in['status'], 'at' => date('c'), 'by' => $email]; $c['status'] = $in['status']; }
        // every field of this number's case form (Cases → Settings) plus the older ones
        if (isset($in['data']) && is_array($in['data'])) foreach (array_unique(array_merge($fields, ['gender', 'age', 'country', 'complaint', 'symptoms', 'diagnosis', 'program', 'started', 'notes'])) as $k) if (isset($in['data'][$k])) $c['data'][$k] = mb_substr(trim((string) $in['data'][$k]), 0, 2000);
        if (trim((string) ($in['note'] ?? '')) !== '') $c['notes'][] = ['text' => mb_substr(trim((string) $in['note']), 0, 2000), 'at' => date('c'), 'by' => $email];
            $c['updated'] = date('c'); return $c; });
        $status = $saved['status'] ?? 'new'; wi_log('case_set', ['account' => $aid, 'phone' => $ph]);
        // Under follow-up: copy all their conversations into the record now.
        $snap = in_array($status, ['follow', 'improving'], true) ? wf_case_snapshot($acc, $ph) : null;
        wi_json(['ok' => true, 'snapshot' => $snap ? $snap['count'] : null]);
    }
    case 'case_analyze': {
        $in = wf_post(); @set_time_limit(220); $acc = wi_current(); $aid = (string) $acc['id']; $ph = wf_phone($in['phone'] ?? '');
        $all = wi_read('cases.json', []);
        if (!isset($all[$aid][$ph])) wi_json(['ok' => false, 'error' => 'Case not found.']);
        $msgs = wf_case_messages($acc, $ph); $conv = '';
        foreach ($msgs as $m) if (trim((string) ($m['text'] ?? '')) !== '' || ($m['kind'] ?? '') !== 'text')
            $conv .= '[' . substr((string) $m['time'], 0, 10) . ' ' . ($m['src'] === 'group' ? 'group' : 'private') . '] ' . ($m['dir'] === 'out' || !empty($m['by']) ? wf_pack($aid)['staff'] : wf_pack($aid)['client']) . ': ' . (trim((string) ($m['text'] ?? '')) !== '' ? mb_substr(preg_replace('/\s+/u', ' ', (string) $m['text']), 0, 600) : '(' . ($m['kind'] ?? 'media') . ')') . "\n";
        if ($conv === '') wi_json(['ok' => false, 'error' => 'No messages from this person yet.']);
        $prompt = wf_prompt('case_analysis', $aid, ['messages' => mb_substr($conv, -26000)]);
        $ai = wi_ai('Case analysis', $prompt, 210, ['subject' => '+' . $ph]);
        if (empty($ai['ok']) || !is_array($ai['data'] ?? null)) wi_json(['ok' => false, 'error' => $ai['error'] ?? 'AI failed.']);
        $all[$aid][$ph]['ai'] = $ai['data'] + ['at' => date('c'), 'model' => trim(($ai['provider'] ?? '') . ' ' . ($ai['model'] ?? '')), 'messages' => count($msgs)];
        foreach (['gender', 'age', 'country'] as $k) if (empty($all[$aid][$ph]['data'][$k]) && !empty($ai['data']['data'][$k])) $all[$aid][$ph]['data'][$k] = mb_substr((string) $ai['data']['data'][$k], 0, 60);
        if (empty($all[$aid][$ph]['data']['complaint']) && !empty($ai['data']['complaint'])) $all[$aid][$ph]['data']['complaint'] = mb_substr((string) $ai['data']['complaint'], 0, 500);
        wf_case_put($aid, $ph, $all[$aid][$ph]); wi_log('case_analyze', ['account' => $aid, 'phone' => $ph]);
        wi_json(['ok' => true, 'ai' => $all[$aid][$ph]['ai']]);
    }
    case 'case_export': {
        $acc = wi_current(); $ph = wf_phone($_GET['phone'] ?? '');
        $c = wf_cases((string) $acc['id'])[$ph] ?? null; if (!$c) { http_response_code(404); exit('Not found'); }
        $f = wf_case_file((string) $acc['id'], $ph); $snap = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
        $msgs = $snap['messages'] ?? wf_case_messages($acc, $ph);
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: attachment; filename="case_' . $ph . '_' . date('Ymd') . '.html"');
        $e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        echo '<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><title>Case ' . $e($ph) . '</title><style>body{font-family:Tahoma,Arial;max-width:900px;margin:20px auto;line-height:1.8}h1{font-size:20px}.m{border-bottom:1px solid #eee;padding:6px 0}.t{color:#666;font-size:12px}.o{background:#f0fdf4}pre{white-space:pre-wrap;font-family:inherit}</style>';
        echo '<h1>سجل الحالة: ' . $e($c['name'] ?? '') . ' (+' . $e($ph) . ')</h1><p>الحالة: ' . $e(wf_case_statuses(wf_aid())[$c['status'] ?? 'new'] ?? '') . ' · أول ظهور: ' . $e(substr((string) ($c['first'] ?? ''), 0, 10)) . ' · آخر: ' . $e(substr((string) ($c['last'] ?? ''), 0, 10)) . '</p>';
        foreach ($c['data'] ?? [] as $k => $v) if ($v !== '') echo '<p><b>' . $e($k) . ':</b> ' . $e($v) . '</p>';
        if (!empty($c['ai'])) echo '<h2>تحليل الذكاء الاصطناعي</h2><pre>' . $e(json_encode($c['ai'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) . '</pre>';
        foreach ($c['notes'] ?? [] as $n) echo '<p>📝 ' . $e($n['text']) . ' <span class="t">' . $e($n['by'] . ' ' . substr($n['at'], 0, 16)) . '</span></p>';
        echo '<h2>المحادثات (' . count($msgs) . ')</h2>';
        foreach ($msgs as $m) echo '<div class="m' . ($m['dir'] === 'out' ? ' o' : '') . '"><span class="t">' . $e(date('d/m/Y H:i', strtotime((string) $m['time']))) . ' · ' . $e($m['chat']) . '</span><pre>' . $e(trim((string) ($m['text'] ?? '')) !== '' ? $m['text'] : '[' . ($m['kind'] ?? '') . ']') . '</pre></div>';
        exit;
    }

    }
}
