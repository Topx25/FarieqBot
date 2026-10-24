<?php
/* ---------- File library clean-up: keep only the useful passages, strip names of people outside the team, references, groups, sites and sheikhs ---------- */
function wf_clean_install(): void {
    $m = wi_db(); if (!$m) return; wf_docs_install();
    $have = []; $r = $m->query("SHOW COLUMNS FROM wi_learn_chunks"); while ($x = $r->fetch_assoc()) $have[$x['Field']] = 1;
    if (!isset($have['orig'])) $m->query("ALTER TABLE wi_learn_chunks ADD orig MEDIUMTEXT NULL, ADD dropped TINYINT NOT NULL DEFAULT 0, ADD topic VARCHAR(160) NOT NULL DEFAULT ''");
    if (!isset($have['verdict'])) $m->query("ALTER TABLE wi_learn_chunks ADD verdict VARCHAR(10) NOT NULL DEFAULT '', ADD verdict_why VARCHAR(300) NOT NULL DEFAULT ''"); // checked against the team's answers in the groups
    $have = []; $r = $m->query("SHOW COLUMNS FROM wi_learn_docs"); while ($x = $r->fetch_assoc()) $have[$x['Field']] = 1;
    if (!isset($have['cleaned_at'])) $m->query("ALTER TABLE wi_learn_docs ADD cleaned_at DATETIME NULL, ADD clean_kept INT NOT NULL DEFAULT 0, ADD clean_dropped INT NOT NULL DEFAULT 0");
}
/** Names that may stay in the text: admins of the groups the bot follows, Inbox users, the team list, and names added by hand. */
function wf_clean_names(string $base): array {
    $names = []; $acc = wi_accounts()[$base] ?? null;
    if ($acc) { $c = wf_bot_run_cfg($base); $gs = (array) ($c['groups'] ?? []) + (array) (wi_read('case_groups.json', [])[$base] ?? []);
        foreach (array_keys($gs) as $gj) { $mm = wi_gateway('/groups/members?jid=' . rawurlencode((string) $gj), null, $acc); foreach ($mm['members'] ?? [] as $x) if (!empty($x['admin']) && trim((string) ($x['name'] ?? '')) !== '') $names[] = trim($x['name']); } }
    if ($acc) foreach (wi_account_people($acc) as $u) if ($u['name'] !== $u['email']) $names[] = trim($u['name']); // team of this number only
    foreach ((array) (wi_read('team.json', [])[$base] ?? []) as $x) if (!empty($x['name'])) $names[] = trim($x['name']);
    foreach ((array) (wi_read('learning.json', [])[$base]['clean_keep'] ?? []) as $n) $names[] = $n;
    return array_values(array_unique(array_filter($names)));
}
function wf_clean_state(string $base, array $patch): void { wi_update('learning.json', function ($L) use ($base, $patch) { $L[$base]['clean'] = $patch + ($L[$base]['clean'] ?? []); return $L; }); }
/** Cleans passages not cleaned yet (orig IS NULL), 6 per AI call, until the time budget runs out. Returns ['ok', 'partial']. */
function wf_clean_run(string $base, array $ids, int $budget): array {
    wi_ctx_set('ai_acc', wi_accounts()[$base] ?? null);
    $m = wi_db(); if (!$m) return ['ok' => false, 'error' => 'Database not available.']; wf_clean_install(); $t0 = time();
    $names = wf_clean_names($base); $keep = $names ? implode('، ', array_slice($names, 0, 150)) : '(none)';
    $where = "c.base = '" . $m->real_escape_string($base) . "' AND c.orig IS NULL" . ($ids ? " AND c.doc_id IN (" . implode(',', array_map('intval', $ids)) . ")" : '');
    $total = (int) $m->query("SELECT COUNT(*) FROM wi_learn_chunks c WHERE $where")->fetch_row()[0];
    $st0 = wi_read('learning.json', [])[$base]['clean'] ?? []; $done = !empty($st0['running']) ? (int) ($st0['done'] ?? 0) : 0;
    wf_clean_state($base, ['running' => true, 'total' => $done + $total, 'done' => $done, 'at' => date('c'), 'error' => '']);
    $kept = 0; $dropped = 0; $fails = 0; $docs = [];
    while (time() - $t0 < $budget) {
        $m = wi_db(); $rows = $m->query("SELECT c.id, c.doc_id, c.text FROM wi_learn_chunks c WHERE $where ORDER BY c.doc_id, c.idx LIMIT 6")->fetch_all(MYSQLI_ASSOC); if (!$rows) break;
        $list = ''; foreach ($rows as $i => $r) $list .= "\n### " . ($i + 1) . "\n" . $r['text'] . "\n";
        $prompt = wf_prompt('library_clean', $base, ['allowed_names' => $keep, 'passages' => $list]);
        $ai = wi_ai('Clean library files', $prompt, 240, ['subject' => 'doc ' . $rows[0]['doc_id']]);
        $m = wi_db(); if (!$m) return ['ok' => false, 'error' => 'Database not available.']; // the AI call can outlast the DB's idle timeout: reconnect
        $items = []; foreach ((array) ($ai['data']['items'] ?? []) as $it) $items[(int) ($it['n'] ?? 0)] = $it;
        if (empty($ai['ok']) || !$items) { if (++$fails >= 3) { wf_clean_state($base, ['running' => false, 'error' => (string) ($ai['error'] ?? 'AI gave no answer.')]); return ['ok' => false, 'error' => $ai['error'] ?? 'AI failed']; } continue; }
        $up = $m->prepare("UPDATE wi_learn_chunks SET orig = text, text = ?, words = ?, dropped = ?, topic = ? WHERE id = ?");
        foreach ($rows as $i => $r) { $it = $items[$i + 1] ?? null; $docs[(int) $r['doc_id']] = 1;
            if ($it === null) { $txt = $r['text']; $drop = 0; $tp = ''; } // not answered: leave the text, mark as checked
            else { $drop = empty($it['keep']) ? 1 : 0; $txt = $drop ? $r['text'] : trim((string) ($it['text'] ?? '')); if (!$drop && mb_strlen($txt) < 20) { $drop = 1; $txt = $r['text']; } $tp = mb_substr(trim((string) ($it['topic'] ?? '')), 0, 160); }
            if ($drop && wf_has_quran($r['text'], $base) && mb_strlen($r['text']) > 80) { $drop = 0; $txt = $r['text']; } // never throw away passages with verses
            $w = $drop ? '' : implode(' ', wf_words($txt)); $id = (int) $r['id'];
            $up->bind_param('ssisi', $txt, $w, $drop, $tp, $id); $up->execute(); $drop ? $dropped++ : $kept++; $done++; }
        wf_clean_state($base, ['done' => $done]);
    }
    $m = wi_db(); foreach (array_keys($docs) as $d) $m->query("UPDATE wi_learn_docs SET cleaned_at = NOW(), clean_kept = (SELECT COUNT(*) FROM wi_learn_chunks WHERE doc_id = $d AND orig IS NOT NULL AND dropped = 0), clean_dropped = (SELECT COUNT(*) FROM wi_learn_chunks WHERE doc_id = $d AND dropped = 1) WHERE id = $d");
    $left = (int) $m->query("SELECT COUNT(*) FROM wi_learn_chunks c WHERE $where")->fetch_row()[0];
    wf_clean_state($base, ['running' => $left > 0, 'done' => $done, 'at' => date('c'), 'kept' => (int) (($st0['kept'] ?? 0) * !empty($st0['running'])) + $kept, 'dropped' => (int) (($st0['dropped'] ?? 0) * !empty($st0['running'])) + $dropped]);
    return ['ok' => true, 'partial' => $left > 0, 'kept' => $kept, 'dropped' => $dropped];
}
function wf_clean_undo(string $base, int $doc): void {
    $m = wi_db(); wf_clean_install();
    $r = $m->query("SELECT id, orig FROM wi_learn_chunks WHERE doc_id = $doc AND base = '" . $m->real_escape_string($base) . "' AND orig IS NOT NULL")->fetch_all(MYSQLI_ASSOC);
    $up = $m->prepare("UPDATE wi_learn_chunks SET text = ?, words = ?, orig = NULL, dropped = 0, topic = '' WHERE id = ?");
    foreach ($r as $x) { $w = implode(' ', wf_words($x['orig'])); $id = (int) $x['id']; $up->bind_param('ssi', $x['orig'], $w, $id); $up->execute(); }
    $m->query("UPDATE wi_learn_docs SET cleaned_at = NULL, clean_kept = 0, clean_dropped = 0 WHERE id = $doc AND base = '" . $m->real_escape_string($base) . "'");
}
function wf_api_learn_clean(string $api): void {
    if ($api !== 'learn_clean') return;
    $base = wf_aid(); if (!wi_db()) wi_json(['ok' => false, 'error' => 'Database not available.']); wf_clean_install();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') { $in = wf_post(); $do = (string) ($in['do'] ?? '');
        if ($do === 'start') { $ids = array_values(array_filter(array_map('intval', (array) ($in['ids'] ?? []))));
            if (!empty($in['redo'])) foreach ($ids as $d) wf_clean_undo($base, $d);
            wf_clean_state($base, ['running' => true, 'done' => 0, 'total' => 0, 'kept' => 0, 'dropped' => 0, 'error' => '']);
            wi_job_add('learn_clean', $base, ['base' => $base, 'ids' => $ids], (string) $_SESSION['email']); wi_log('learn_clean', ['docs' => $ids ?: 'all']); wi_json(['ok' => true]); }
        if ($do === 'undo') { wf_clean_undo($base, (int) ($in['id'] ?? 0)); wi_log('learn_clean_undo', ['id' => (int) ($in['id'] ?? 0)]); wi_json(['ok' => true]); }
        if ($do === 'restore_one' || $do === 'drop_one') { $m = wi_db(); $id = (int) ($in['chunk'] ?? 0); $b = $m->real_escape_string($base);
            $m->query($do === 'restore_one' ? "UPDATE wi_learn_chunks SET dropped = 0, words = '' WHERE id = $id AND base = '$b'" : "UPDATE wi_learn_chunks SET dropped = 1, words = '', orig = IFNULL(orig, text) WHERE id = $id AND base = '$b'");
            if ($do === 'restore_one') { $x = $m->query("SELECT text FROM wi_learn_chunks WHERE id = $id AND base = '$b'")->fetch_row(); if ($x) { $w = $m->real_escape_string(implode(' ', wf_words($x[0]))); $m->query("UPDATE wi_learn_chunks SET words = '$w' WHERE id = $id"); } }
            wi_json(['ok' => true]); }
        if ($do === 'names') { wi_update('learning.json', function ($L) use ($base, $in) { $L[$base]['clean_keep'] = array_values(array_filter(array_map(fn($x) => trim(mb_substr($x, 0, 80)), preg_split('/[\n,،]+/u', (string) ($in['names'] ?? ''))))); return $L; }); wi_json(['ok' => true]); }
        wi_json(['ok' => false, 'error' => 'Unknown action.']); }
    $L = wi_read('learning.json', [])[$base] ?? [];
    wi_json(['ok' => true, 'state' => $L['clean'] ?? null, 'manual' => $L['clean_keep'] ?? [], 'names' => isset($_GET['names']) ? wf_clean_names($base) : null]);
}

/* ---------- Uploaded files checked against our groups ----------
 * Each passage of an uploaded file is compared with the closest real answers of the team (the collected exchanges, groups first).
 * agree = the team says the same (used, ranked first) · conflict = the team does it differently (never used) · unrelated = nothing to compare (used as before). */
function wf_doc_verify_run(string $base, int $budget = 600): array {
    $m = wi_db(); if (!$m) return ['ok' => false, 'error' => 'Database not available.']; wf_clean_install(); $t0 = time(); $done = 0; $calls = 0;
    $pairs = array_values(array_filter(wf_pairs($base), fn($p) => mb_strlen((string) $p['a']) >= 25)); if (!$pairs) return ['ok' => false, 'error' => 'Collect the conversations first (step 1).'];
    $pw = array_map(fn($p) => array_flip(wf_words((string) $p['q'] . ' ' . (string) $p['a'])), $pairs);
    while (time() - $t0 < $budget) {
        $st = $m->prepare("SELECT c.id, c.text, c.words FROM wi_learn_chunks c JOIN wi_learn_docs d ON d.id = c.doc_id WHERE c.base = ? AND d.status = 'active' AND c.dropped = 0 AND c.verdict = '' AND c.words <> '' ORDER BY c.id LIMIT 8");
        $st->bind_param('s', $base); $st->execute(); $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC); if (!$rows) break;
        $txt = ''; $ids = [];
        foreach ($rows as $c) { $want = array_flip(explode(' ', (string) $c['words'])); $sc = [];
            foreach ($pw as $i => $w) { $h = count(array_intersect_key($w, $want)); if ($h >= 3) $sc[$i] = $h + (!empty($pairs[$i]['group']) ? 1 : 0); }
            arsort($sc); $team = array_slice(array_keys($sc), 0, 3); $ids[] = (int) $c['id'];
            if (!$team) { $m->query("UPDATE wi_learn_chunks SET verdict = 'unrelated' WHERE id = " . (int) $c['id']); $done++; continue; }
            $txt .= '### P' . $c['id'] . "\nFILE SAYS: " . mb_substr((string) $c['text'], 0, 1200) . "\nTEAM ANSWERS:\n" . implode("\n", array_map(fn($i) => '- Q: ' . mb_substr(preg_replace('/\s+/u', ' ', (string) $pairs[$i]['q']), 0, 200) . ' → A: ' . mb_substr(preg_replace('/\s+/u', ' ', (string) $pairs[$i]['a']), 0, 500), $team)) . "\n\n"; }
        if ($txt === '') continue;
        $ai = wi_ai('Check files against the groups', wf_prompt('doc_verify', $base, ['passages' => $txt]), 180, ['subject' => count($rows) . ' passages', 'prefer' => function_exists('wf_fast_model') ? wf_fast_model(true) : '']); $calls++;
        if (empty($ai['ok'])) return ['ok' => false, 'error' => $ai['error'] ?? 'AI failed.', 'checked' => $done];
        foreach ((array) ($ai['data']['items'] ?? []) as $x) { $id = (int) preg_replace('/\D/', '', (string) ($x['id'] ?? '')); $v = (string) ($x['verdict'] ?? '');
            if (!in_array($id, $ids, true) || !in_array($v, ['agree', 'conflict', 'unrelated'], true)) continue;
            $why = mb_substr((string) ($x['why'] ?? ''), 0, 300); $u = $m->prepare("UPDATE wi_learn_chunks SET verdict = ?, verdict_why = ? WHERE id = ?"); $u->bind_param('ssi', $v, $why, $id); $u->execute(); $done++; }
        $m->query("UPDATE wi_learn_chunks SET verdict = 'unrelated' WHERE verdict = '' AND id IN (" . implode(',', $ids) . ")"); // the model skipped it: nothing to compare
    }
    $left = (int) ($m->query("SELECT COUNT(*) FROM wi_learn_chunks c JOIN wi_learn_docs d ON d.id = c.doc_id WHERE c.base = '" . $m->real_escape_string($base) . "' AND d.status = 'active' AND c.dropped = 0 AND c.verdict = '' AND c.words <> ''")->fetch_row()[0] ?? 0);
    return ['ok' => true, 'checked' => $done, 'calls' => $calls, 'left' => $left];
}

