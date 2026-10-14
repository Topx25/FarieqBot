<?php
/* ---------- The guide grows by itself: new symptoms / related words / remedies… found in chats, the cache and the cleaned files ---------- */
function wf_grow_install(): void {
    $m = wi_db(); if (!$m) return; wf_learn_install();
    $have = []; $r = $m->query("SHOW COLUMNS FROM wi_concept_terms"); while ($x = $r->fetch_assoc()) $have[$x['Field']] = 1;
    if (!isset($have['status'])) $m->query("ALTER TABLE wi_concept_terms ADD status ENUM('active','suggested') NOT NULL DEFAULT 'active', ADD seen INT NOT NULL DEFAULT 0, ADD source VARCHAR(30) NOT NULL DEFAULT '', ADD added_at DATETIME NULL");
    $have = []; $r = $m->query("SHOW COLUMNS FROM wi_answer_cache"); while ($x = $r->fetch_assoc()) $have[$x['Field']] = 1;
    if (!isset($have['concept_id'])) $m->query("ALTER TABLE wi_answer_cache ADD concept_id INT NULL, ADD review TINYINT NOT NULL DEFAULT 0, ADD KEY cc (concept_id)");
}
function wf_grow_cfg(string $base): array { return (wi_read('learning.json', [])[$base]['grow'] ?? []) + ['auto' => true, 'hour' => 2, 'auto_approve' => false, 'min_seen' => 3, 'chats' => true, 'cache' => true, 'files' => true, 'last' => null]; }
function wf_grow_save(string $base, array $patch): void { wi_update('learning.json', function ($L) use ($base, $patch) { $L[$base]['grow'] = $patch + wf_grow_cfg($base); return $L; }); }
/** Links cache answers to the concept they talk about (no AI: the guide's own words). */
function wf_grow_link_cache(string $base): int {
    $m = wi_db(); $b = $m->real_escape_string($base); $n = 0;
    foreach ($m->query("SELECT id, question, answer FROM wi_answer_cache WHERE base = '$b' AND concept_id IS NULL LIMIT 2000")->fetch_all(MYSQLI_ASSOC) as $x) {
        $c = wf_guide_match($base, $x['question'] . ' ' . mb_substr($x['answer'], 0, 400)); $cid = $c ? (int) $c[0]['id'] : 0;
        $m->query("UPDATE wi_answer_cache SET concept_id = $cid WHERE id = " . (int) $x['id']); if ($cid) $n++; }
    return $n;
}
function wf_grow_run(string $base, string $by = 'schedule'): array {
    wi_ctx_set('ai_acc', wi_accounts()[$base] ?? null);
    $m = wi_db(); if (!$m) return ['ok' => false, 'error' => 'Database not available.']; wf_grow_install();
    $G = wf_grow_cfg($base); $b = $m->real_escape_string($base); $since = $G['last']['at'] ?? date('c', time() - 7 * 86400); $sinceT = strtotime($since) ?: time() - 7 * 86400;
    $texts = [];
    if ($G['chats'] && ($acc = wi_accounts()[$base] ?? null)) {
        foreach (wi_gateway('/archive?months=' . (int) wi_sys('months_recent') . '&limit=20000', null, $acc)['rows'] ?? [] as $r) {
            $t = is_numeric($r['time'] ?? null) ? (int) ($r['time'] > 2e10 ? $r['time'] / 1000 : $r['time']) : (strtotime((string) ($r['time'] ?? '')) ?: 0); if ($t < $sinceT) continue;
            $x = trim((string) ($r['text'] ?? '')); if (mb_strlen($x) < 15 || wf_cache_personal($x) || preg_match('#https?://#', $x)) continue;
            $texts[] = (($r['dir'] ?? '') === 'in' ? 'Q: ' : 'Team: ') . mb_substr(preg_replace('/\s+/u', ' ', $x), 0, 220); }
        $texts = array_slice(array_values(array_unique($texts)), 0, 160);
    }
    if ($G['cache']) foreach ($m->query("SELECT question, answer FROM wi_answer_cache WHERE base = '$b' AND created_at >= NOW() - INTERVAL " . max(60, time() - $sinceT) . " SECOND ORDER BY id DESC LIMIT 40")->fetch_all(MYSQLI_ASSOC) as $x) $texts[] = 'Q: ' . mb_substr($x['question'], 0, 160) . ' | A: ' . mb_substr($x['answer'], 0, 220);
    if ($G['files']) foreach ($m->query("SELECT c.topic, c.text FROM wi_learn_chunks c WHERE c.base = '$b' AND c.orig IS NOT NULL AND c.dropped = 0 ORDER BY RAND() LIMIT 25")->fetch_all(MYSQLI_ASSOC) as $x) $texts[] = 'File [' . $x['topic'] . ']: ' . mb_substr($x['text'], 0, 300);
    $linked = wf_grow_link_cache($base);
    if (!$texts) { $res = ['at' => date('c'), 'by' => $by, 'terms' => 0, 'concepts' => 0, 'auto' => 0, 'read' => 0, 'linked' => $linked, 'error' => '']; wf_grow_save($base, ['last' => $res]); return ['ok' => true] + $res; }
    $cons = $m->query("SELECT id, name, explain_text FROM wi_concepts WHERE base = '$b' AND status = 'active'")->fetch_all(MYSQLI_ASSOC); $byName = []; $guide = '';
    foreach ($cons as $c) { $byName[wf_norm($c['name'])] = (int) $c['id']; $ws = array_column($m->query("SELECT term FROM wi_concept_terms WHERE concept_id = " . (int) $c['id'])->fetch_all(MYSQLI_ASSOC), 'term');
        $guide .= '- ' . $c['name'] . ': ' . mb_substr((string) $c['explain_text'], 0, 160) . ' | words: ' . implode('، ', array_slice($ws, 0, 40)) . "\n"; }
    $all = array_column($m->query("SELECT name FROM wi_concepts WHERE base = '$b'")->fetch_all(MYSQLI_ASSOC), 'name');
    $prompt = wf_prompt('guide_grow', $base, ['guide' => $guide ?: "(empty)\n", 'relations' => json_encode(WF_RELATIONS, JSON_UNESCAPED_UNICODE), 'existing' => implode('، ', $all), 'texts' => implode("\n", $texts)]);
    $ai = wi_ai('Guide growth', $prompt, 240, ['subject' => 'guide']);
    $m = wi_db(); if (!$m) return ['ok' => false, 'error' => 'Database not available.']; // reconnect after the long AI call
    if (empty($ai['ok']) || !is_array($ai['data'] ?? null)) { $res = ['at' => $G['last']['at'] ?? null, 'tried' => date('c'), 'by' => $by, 'error' => (string) ($ai['error'] ?? 'AI gave no answer.')]; wf_grow_save($base, ['last' => $res + (array) $G['last']]); return ['ok' => false, 'error' => $res['error']]; }
    $nt = 0; $auto = 0; $nc = 0;
    $find = $m->prepare("SELECT id, status, seen FROM wi_concept_terms WHERE concept_id = ? AND norm = ? LIMIT 1");
    $ins = $m->prepare("INSERT INTO wi_concept_terms (concept_id, term, norm, relation, status, seen, source, added_at) VALUES (?,?,?,?,?,?,'auto',NOW())");
    foreach ((array) ($ai['data']['terms'] ?? []) as $t) {
        $cid = $byName[wf_norm((string) ($t['concept'] ?? ''))] ?? 0; $term = trim(mb_substr((string) ($t['term'] ?? ''), 0, 190)); $norm = wf_norm($term); if (!$cid || $norm === '' || mb_strlen($norm) < 2) continue;
        $rel = isset(WF_RELATIONS[$t['relation'] ?? '']) ? $t['relation'] : 'related'; $cnt = max(1, (int) ($t['count'] ?? 1));
        $find->bind_param('is', $cid, $norm); $find->execute(); $ex = $find->get_result()->fetch_assoc();
        if ($ex) { if ($ex['status'] === 'suggested') { $seen = (int) $ex['seen'] + $cnt; $st = $G['auto_approve'] && $seen >= $G['min_seen'] ? 'active' : 'suggested'; if ($st === 'active') $auto++; $m->query("UPDATE wi_concept_terms SET seen = $seen, status = '$st' WHERE id = " . (int) $ex['id']); } continue; }
        $st = $G['auto_approve'] && $cnt >= $G['min_seen'] ? 'active' : 'suggested'; if ($st === 'active') $auto++;
        $ins->bind_param('issssi', $cid, $term, $norm, $rel, $st, $cnt); $ins->execute(); $nt++;
    }
    foreach (array_slice((array) ($ai['data']['concepts'] ?? []), 0, 3) as $c) {
        $name = trim(mb_substr((string) ($c['name'] ?? ''), 0, 190)); if ($name === '' || in_array($name, $all, true)) continue;
        $ex = mb_substr((string) ($c['explain'] ?? ''), 0, 1000); $hint = mb_substr((string) ($c['hint'] ?? ''), 0, 1000); $who = 'AI';
        $st = $m->prepare("INSERT INTO wi_concepts (base, name, explain_text, answer_hint, status, created_by, created_at) VALUES (?,?,?,?,'suggested',?,NOW())"); $st->bind_param('sssss', $base, $name, $ex, $hint, $who); $st->execute(); $id = (int) $m->insert_id;
        $i2 = $m->prepare("INSERT INTO wi_concept_terms (concept_id, term, norm, relation, source, added_at) VALUES (?,?,?,?,'auto',NOW())");
        foreach ((array) ($c['terms'] ?? []) as $t) { $term = trim((string) ($t['term'] ?? '')); $norm = wf_norm($term); if ($norm === '') continue; $rel = isset(WF_RELATIONS[$t['relation'] ?? '']) ? $t['relation'] : 'related'; $i2->bind_param('isss', $id, $term, $norm, $rel); $i2->execute(); }
        $nc++;
    }
    $res = ['at' => date('c'), 'by' => $by, 'terms' => $nt, 'auto' => $auto, 'concepts' => $nc, 'read' => count($texts), 'linked' => $linked, 'error' => ''];
    wf_grow_save($base, ['last' => $res]); wi_log('guide_grow', ['base' => $base] + $res);
    return ['ok' => true] + $res;
}
function wf_api_guide_grow(string $api): void {
    if ($api !== 'learn_grow') return;
    $base = wf_aid(); if (!wi_db()) wi_json(['ok' => false, 'error' => 'Database not available.']); wf_grow_install(); $m = wi_db();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') { $in = wf_post(); $do = (string) ($in['do'] ?? '');
        if ($do === 'run') { wi_job_add('guide_grow', $base, ['base' => $base, 'by' => (string) $_SESSION['email']], (string) $_SESSION['email']); wi_update('learning.json', function ($L) use ($base) { $L[$base]['grow']['running'] = date('c'); return $L; }); wi_json(['ok' => true]); }
        if ($do === 'settings') { wf_grow_save($base, ['auto' => !empty($in['auto']), 'hour' => max(0, min(23, (int) ($in['hour'] ?? 2))), 'auto_approve' => !empty($in['auto_approve']), 'min_seen' => max(2, min(20, (int) ($in['min_seen'] ?? 3))), 'chats' => !empty($in['chats']), 'cache' => !empty($in['cache']), 'files' => !empty($in['files'])]); wi_json(['ok' => true]); }
        if ($do === 'term') { $id = (int) ($in['id'] ?? 0); $ok = $m->query("SELECT t.id FROM wi_concept_terms t JOIN wi_concepts c ON c.id = t.concept_id WHERE t.id = $id AND c.base = '" . $m->real_escape_string($base) . "'")->fetch_row(); if (!$ok) wi_json(['ok' => false, 'error' => 'Not found.']);
            if (!empty($in['accept'])) $m->query("UPDATE wi_concept_terms SET status = 'active' WHERE id = $id"); else $m->query("DELETE FROM wi_concept_terms WHERE id = $id");
            wi_json(['ok' => true]); }
        if ($do === 'all') { $cid = (int) ($in['concept'] ?? 0); $ok = $m->query("SELECT id FROM wi_concepts WHERE id = $cid AND base = '" . $m->real_escape_string($base) . "'")->fetch_row(); if (!$ok) wi_json(['ok' => false, 'error' => 'Not found.']);
            $m->query(!empty($in['accept']) ? "UPDATE wi_concept_terms SET status = 'active' WHERE concept_id = $cid AND status = 'suggested'" : "DELETE FROM wi_concept_terms WHERE concept_id = $cid AND status = 'suggested'"); wi_json(['ok' => true]); }
        wi_json(['ok' => false, 'error' => 'Unknown action.']); }
    $G = wf_grow_cfg($base); $G['running'] = wi_read('learning.json', [])[$base]['grow']['running'] ?? null;
    wi_json(['ok' => true, 'grow' => $G, 'review' => (int) $m->query("SELECT COUNT(*) FROM wi_answer_cache WHERE base = '" . $m->real_escape_string($base) . "' AND review = 1")->fetch_row()[0]]);
}
