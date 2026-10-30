<?php
// WhatsApp Inbox — Learning (v3.15): a guide of concepts + linked words, and an answer cache, to make the bot more accurate and use fewer tokens.
// Tables (MySQL): wi_concepts, wi_concept_terms, wi_answer_cache. "base" = the number whose bot uses them.

function wf_learn_install(): bool {
    $m = wi_db(); if (!$m) return false;
    return (bool) ($m->query("CREATE TABLE IF NOT EXISTS wi_concepts (
        id INT AUTO_INCREMENT PRIMARY KEY, base VARCHAR(40) NOT NULL, name VARCHAR(190) NOT NULL, explain_text TEXT NULL, answer_hint TEXT NULL,
        status ENUM('active','suggested','off') NOT NULL DEFAULT 'active', created_by VARCHAR(190) NOT NULL DEFAULT '', created_at DATETIME NULL, hits INT NOT NULL DEFAULT 0, KEY b (base, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci")
    && $m->query("CREATE TABLE IF NOT EXISTS wi_concept_terms (
        id INT AUTO_INCREMENT PRIMARY KEY, concept_id INT NOT NULL, term VARCHAR(190) NOT NULL, norm VARCHAR(190) NOT NULL, relation VARCHAR(40) NOT NULL DEFAULT 'related', KEY c (concept_id), KEY n (norm)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci")
    && $m->query("CREATE TABLE IF NOT EXISTS wi_answer_cache (
        id INT AUTO_INCREMENT PRIMARY KEY, base VARCHAR(40) NOT NULL, qnorm VARCHAR(600) NOT NULL, question TEXT NULL, answer TEXT NOT NULL, source VARCHAR(20) NOT NULL DEFAULT 'ai',
        status ENUM('active','off') NOT NULL DEFAULT 'active', hits INT NOT NULL DEFAULT 0, tokens_saved INT NOT NULL DEFAULT 0, created_at DATETIME NULL, last_used DATETIME NULL, expires_at DATETIME NULL, KEY b (base, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"));
}
/* ---------- The knowledge map as TOPICS: each concept has a full explanation, answer guidance, its search forms (terms) and its source chunks ---------- */
/** The chunks behind each topic, and the 'draft' state (a new map is built as drafts and switched on together). */
function wf_kb_topics_db(): bool {
    $m = wi_db(); if (!$m || !wf_learn_install()) return false; static $ok = null; if ($ok !== null) return $ok;
    $r = $m->query("SHOW COLUMNS FROM wi_concepts LIKE 'status'"); $t = $r ? (string) ($r->fetch_assoc()['Type'] ?? '') : '';
    if ($t !== '' && !str_contains($t, "'draft'")) $m->query("ALTER TABLE wi_concepts MODIFY status ENUM('active','suggested','off','draft') NOT NULL DEFAULT 'active'");
    return $ok = (bool) $m->query("CREATE TABLE IF NOT EXISTS wi_concept_chunks (base VARCHAR(40) NOT NULL, concept_id INT NOT NULL, chunk_id INT NOT NULL, PRIMARY KEY (concept_id, chunk_id), KEY b (base)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
/** Arabic text as search keys: no diacritics or tatweel, one alef, ta marbuta as ha, alef maqsura as ya, no leading article; the words joined by one space. */
function wf_kb_norm(string $s): string {
    $s = mb_strtolower(preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $s));
    $s = strtr($s, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ة' => 'ه', 'ى' => 'ي', 'ؤ' => 'و', 'ئ' => 'ي']);
    $w = preg_split('/[^\p{L}\p{N}]+/u', $s, -1, PREG_SPLIT_NO_EMPTY);
    return implode(' ', array_map(fn($x) => mb_strlen($x) > 4 ? preg_replace('/^(وال|بال|كال|فال|لل|ال)/u', '', $x) : $x, $w));
}
/** The active topics that match the person's words, best first: a search form (or the title) found in the words counts by its length,
 *  a word shared with a sample question counts a little. [['id','name','explain','hint','score','terms'=>[]]] */
function wf_kb_topic_search(string $base, string $text, int $max = 5): array {
    $m = wi_db(); if (!$m || !wf_kb_topics_db()) return []; $q = ' ' . wf_kb_norm($text) . ' '; if (trim($q) === '') return [];
    $words = array_flip(array_filter(explode(' ', trim($q)), fn($w) => mb_strlen($w) >= 3));
    $st = $m->prepare("SELECT c.id, c.name, c.explain_text, c.answer_hint, t.term, t.norm, t.relation FROM wi_concepts c JOIN wi_concept_terms t ON t.concept_id = c.id WHERE c.base = ? AND c.status = 'active'");
    $st->bind_param('s', $base); $st->execute(); $r = $st->get_result(); $hit = [];
    while ($x = $r->fetch_assoc()) { $n = trim((string) $x['norm']); if ($n === '') continue; $sc = 0;
        if ($x['relation'] === 'question') { foreach (explode(' ', $n) as $w) if (mb_strlen($w) >= 4 && isset($words[$w])) $sc += 0.5; }
        elseif (str_contains($q, ' ' . $n . ' ')) $sc = 1 + substr_count($n, ' ') * 1.5;
        if ($sc <= 0) continue;
        $hit[$x['id']] ??= ['id' => (int) $x['id'], 'name' => (string) $x['name'], 'explain' => (string) $x['explain_text'], 'hint' => (string) $x['answer_hint'], 'score' => 0, 'terms' => []];
        $hit[$x['id']]['score'] += $sc; $hit[$x['id']]['terms'][] = ['term' => (string) $x['term'], 'relation' => $x['relation'] === 'question' ? 'related' : 'related']; }
    usort($hit, fn($a, $b) => $b['score'] <=> $a['score']);
    return array_slice($hit, 0, $max);
}
/** The lesson passages behind one topic that share the most words with the question (for the answer's sources). */
function wf_kb_topic_chunks(int $cid, string $text, int $n = 3): array {
    $m = wi_db(); if (!$m) return []; $words = array_filter(explode(' ', wf_kb_norm($text)), fn($w) => mb_strlen($w) >= 3); $out = [];
    $r = $m->query("SELECT k.id, k.text FROM wi_concept_chunks cc JOIN wi_learn_chunks k ON k.id = cc.chunk_id WHERE cc.concept_id = " . (int) $cid . " LIMIT 400");
    while ($r && ($x = $r->fetch_assoc())) { $nt = ' ' . wf_kb_norm((string) $x['text']) . ' '; $sc = 0; foreach ($words as $w) if (str_contains($nt, ' ' . $w)) $sc++; $out[] = [$sc, mb_strlen((string) $x['text']), (string) $x['text']]; }
    usort($out, fn($a, $b) => [$b[0], $b[1]] <=> [$a[0], $a[1]]);
    return array_map(fn($x) => ['kind' => 'file', 'topic' => '', 'text' => mb_substr($x[2], 0, 1200), 'agree' => true], array_slice($out, 0, $n));
}
/* ---------- Duplicate content by match percentage (Learning settings dup_pct, default 90, and sim_pct, default 75) ---------- */
/** A text's fingerprint: 48 MinHash values over its 3-word shingles (normalised Arabic), so the share of equal values estimates how much two texts match. */
function wf_kb_minhash(string $text): string {
    $w = explode(' ', wf_kb_norm($text)); $sh = []; for ($i = 0, $n = count($w); $i < max(1, $n - 2); $i++) $sh[implode(' ', array_slice($w, $i, 3))] = 1;
    $out = ''; for ($k = 0; $k < 48; $k++) { $min = PHP_INT_MAX; foreach ($sh as $x => $_) { $h = crc32($k . '|' . $x); if ($h < $min) $min = $h; } $out .= str_pad(dechex($min === PHP_INT_MAX ? 0 : $min), 8, '0', STR_PAD_LEFT); }
    return $out;
}
/** How much two fingerprints match, 0 to 100. */
function wf_kb_mh_pct(string $a, string $b): int { if (strlen($a) !== 384 || strlen($b) !== 384) return 0; $eq = 0; for ($i = 0; $i < 384; $i += 8) if (substr($a, $i, 8) === substr($b, $i, 8)) $eq++; return (int) round($eq * 100 / 48); }
/** The 12 band keys of a fingerprint: two texts that match well share at least one (the candidates to compare, instead of every pair). */
function wf_kb_mh_bands(string $mh): array { $b = []; for ($i = 0; $i < 12; $i++) $b[] = $i . ':' . substr($mh, $i * 32, 32); return $b; }
/** The fingerprint column on the passages (added once). */
function wf_kb_dedupe_db(): bool {
    $m = wi_db(); if (!$m || !wf_learn_install()) return false; static $ok = null; if ($ok !== null) return $ok;
    $r = $m->query("SHOW COLUMNS FROM wi_learn_chunks LIKE 'mh'"); if ($r && !$r->num_rows) $m->query("ALTER TABLE wi_learn_chunks ADD mh VARCHAR(384) CHARACTER SET ascii NULL");
    return $ok = true;
}
/** Topic choice per chat: the numbered list the bot sent, waiting for the person's number (expires after kb_pick_min minutes, default 30). */
function wf_kb_pick_get(string $base, string $jid): array { $p = (array) (wi_read('kb_pick.json', [])[$base][$jid] ?? []); return $p && (int) ($p['at'] ?? 0) > time() - 60 * max(5, (int) (wf_learn_cfg($base)['kb_pick_min'] ?? 30)) ? $p : []; }
function wf_kb_pick_set(string $base, string $jid, ?array $ids): void {
    wi_update('kb_pick.json', function ($P) use ($base, $jid, $ids) { $P = (array) $P; if ($ids === null) unset($P[$base][$jid]); else $P[$base][$jid] = ['ids' => array_values($ids), 'at' => time()];
        foreach ((array) ($P[$base] ?? []) as $j => $x) if ((int) ($x['at'] ?? 0) < time() - 86400) unset($P[$base][$j]); return $P; });
}
/** The person's answer to the list: a number (Western or Arabic-Indic digits) that is one of the listed topics, else 0. */
function wf_kb_pick_number(string $text, int $count): int {
    $t = strtr(trim($text), ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
    return preg_match('/^\D{0,6}(\d{1,2})\D{0,6}$/u', $t, $mm) && (int) $mm[1] >= 1 && (int) $mm[1] <= $count ? (int) $mm[1] : 0;
}
const WF_RELATIONS = ['related' => 'related to', 'breaks' => 'is one of the things that break', 'remedy' => 'is a solution for', 'symptom' => 'is a symptom of', 'example' => 'is an example of', 'same' => 'means the same as'];
function wf_learn_cfg(string $base): array { return (wi_read('learning.json', [])[$base] ?? []) + ['dup_pct' => 90, 'sim_pct' => 75, 'cache' => true, 'guide' => true, 'min_sim' => 0.9, 'ttl_days' => 30, 'tokens_per_answer' => 0, 'all_groups' => true]; }

/** Concepts whose linked words appear in the message (word-boundary match on normalised text). */
function wf_guide_match(string $base, string $text): array {
    $m = wi_db(); if (!$m) return [];
    $norm = ' ' . wf_norm($text) . ' ';
    $st = $m->prepare("SELECT c.id, c.name, c.explain_text, c.answer_hint, t.term, t.norm, t.relation FROM wi_concepts c JOIN wi_concept_terms t ON t.concept_id = c.id WHERE c.base = ? AND c.status = 'active' AND t.status = 'active'");
    $st->bind_param('s', $base); $st->execute(); $r = $st->get_result(); $hit = [];
    while ($x = $r->fetch_assoc()) {
        $n = trim((string) $x['norm']); if ($n === '') continue;
        // a word matches with or without the Arabic article / prefixes (ال، و، ب، ف، ل)
        if (!preg_match('/(^|\s)(?:و|ف|ب|ل|ال|وال|بال|فال|لل)?' . preg_quote($n, '/') . '(\s|$)/u', $norm)) continue;
        $hit[$x['id']] ??= ['id' => (int) $x['id'], 'name' => $x['name'], 'explain' => (string) $x['explain_text'], 'hint' => (string) $x['answer_hint'], 'terms' => []];
        $hit[$x['id']]['terms'][] = ['term' => $x['term'], 'relation' => $x['relation']];
    }
    if ($hit) $m->query("UPDATE wi_concepts SET hits = hits + 1 WHERE id IN (" . implode(',', array_map('intval', array_keys($hit))) . ")");
    return array_values($hit);
}
function wf_guide_prompt(array $concepts): string {
    if (!$concepts) return '';
    $out = "GUIDE — the customer's words match these concepts from the team's guide. Build the reply on the MEANING (it outranks the team examples and the sources): name the concept, explain the link in the meaning's own words, then what to do.\n";
    foreach (array_slice($concepts, 0, 3) as $c) {
        $rel = implode('; ', array_map(fn($t) => '"' . $t['term'] . '" ' . (WF_RELATIONS[$t['relation']] ?? 'related to') . ' "' . $c['name'] . '"', $c['terms']));
        $out .= "• " . $c['name'] . "\n  MEANING (primary — base the answer on this): " . mb_substr($c['explain'], 0, 700) . "\n  Link: " . $rel . "\n" . ($c['hint'] !== '' ? "  Extra guidance (secondary — only adds advice; never replaces or contradicts the meaning): " . mb_substr($c['hint'], 0, 500) . "\n" : '');
    }
    return $out . "\n";
}
/** Personal questions are never answered from the cache. */
function wf_cache_personal(string $text): bool {
    static $w = null; $w ??= wf_seed_alt('personal_words');
    return (bool) preg_match('/\d{6,}|' . $w . '/u', $text);
}
/** A saved answer for (nearly) the same general question, or null. */
function wf_cache_find(string $base, string $text): ?array {
    $L = wf_learn_cfg($base); if (empty($L['cache']) || wf_cache_personal($text)) return null;
    $m = wi_db(); if (!$m) return null; $q = wf_norm($text); if (mb_strlen($q) < 8) return null;
    $st = $m->prepare("SELECT id, qnorm, answer FROM wi_answer_cache WHERE base = ? AND status = 'active' AND (expires_at IS NULL OR expires_at > NOW())"); $st->bind_param('s', $base); $st->execute(); $r = $st->get_result();
    $best = null; $bs = 0;
    while ($x = $r->fetch_assoc()) { $s = wf_csim($q, $x['qnorm']); if ($s > $bs) { $bs = $s; $best = $x; } }
    if (!$best || $bs < (float) $L['min_sim']) return null;
    $saved = wf_tokens_per_answer($base);
    $m->query("UPDATE wi_answer_cache SET hits = hits + 1, tokens_saved = tokens_saved + " . (int) $saved . ", last_used = NOW() WHERE id = " . (int) $best['id']);
    return ['id' => (int) $best['id'], 'answer' => $best['answer'], 'similarity' => round($bs, 3), 'saved' => $saved];
}
function wf_cache_put(string $base, string $question, string $answer, string $source = 'ai'): void {
    $L = wf_learn_cfg($base); if (empty($L['cache']) || wf_cache_personal($question) || mb_strlen(wf_norm($question)) < 8) return;
    $m = wi_db(); if (!$m) return; $q = mb_substr(wf_norm($question), 0, 590);
    $st = $m->prepare("SELECT id FROM wi_answer_cache WHERE base = ? AND qnorm = ? LIMIT 1"); $st->bind_param('ss', $base, $q); $st->execute(); if ($st->get_result()->fetch_assoc()) return;
    $days = max(1, (int) $L['ttl_days']); $qq = mb_substr($question, 0, 1000);
    // An answer that quotes the Quran is never reused automatically: the AI can mistype a verse, so it waits (off) until someone checks it.
    $status = wf_has_quran($answer, $base) ? 'off' : 'active'; if ($status === 'off') $source = 'quran-check';
    $st = $m->prepare("INSERT INTO wi_answer_cache (base, qnorm, question, answer, source, status, created_at, expires_at) VALUES (?,?,?,?,?,?,NOW(),NOW() + INTERVAL $days DAY)");
    // a cache that cannot be written (database busy or read only) never breaks the reply itself
    if (!$st) return; $st->bind_param('ssssss', $base, $q, $qq, $answer, $source, $status); $st->execute();
}
/** Typical tokens one AI reply costs for this bot (median of recent bot calls) — what a cache hit saves. */
function wf_tokens_per_answer(string $base): int {
    static $memo = null; if ($memo !== null) return $memo;
    $d = wi_ai_tasks(); $v = [];
    foreach ($d as $x) if (str_starts_with((string) ($x['task'] ?? ''), 'Bot test') && in_array($x['state'] ?? '', ['done', 'ok'], true)) $v[] = (int) ($x['tokens_in'] ?? 0) + (int) ($x['tokens_out'] ?? 0);
    sort($v); return $memo = $v ? $v[intdiv(count($v), 2)] : 2500;
}

/** API calls of the Learning page. */
function wf_api_learning(string $api): void {
    switch ($api) {
    case 'learn_guide': {
        $base = wf_aid(); $m = wi_db(); if (!$m) wi_json(['ok' => false, 'error' => 'Database not available.']); wf_grow_install();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $in = wf_post(); $do = (string) ($in['do'] ?? '');
            // A concept id must belong to this number before anything linked to it is touched.
            $own = function (int $cid) use ($m, $base): bool { $st = $m->prepare("SELECT 1 FROM wi_concepts WHERE id = ? AND base = ?"); $st->bind_param('is', $cid, $base); $st->execute(); return (bool) $st->get_result()->fetch_row(); };
            if (in_array($do, ['delete', 'status', 'save'], true) && (int) ($in['id'] ?? 0) && !$own((int) $in['id'])) wi_json(['ok' => false, 'error' => 'Not found.'], 404);
            if ($do === 'delete') { $id = (int) ($in['id'] ?? 0); $m->query("DELETE FROM wi_concept_terms WHERE concept_id = $id"); $st = $m->prepare("DELETE FROM wi_concepts WHERE id = ? AND base = ?"); $st->bind_param('is', $id, $base); $st->execute(); wi_log('learn_concept_delete', ['id' => $id]); wi_json(['ok' => true]); }
            if ($do === 'status') { $id = (int) ($in['id'] ?? 0); $s = in_array($in['status'] ?? '', ['active', 'off', 'suggested'], true) ? $in['status'] : 'active'; $st = $m->prepare("UPDATE wi_concepts SET status = ? WHERE id = ? AND base = ?"); $st->bind_param('sis', $s, $id, $base); $st->execute(); wi_json(['ok' => true]); }
            if ($do === 'save') {
                $name = trim(mb_substr((string) ($in['name'] ?? ''), 0, 190)); if ($name === '') wi_json(['ok' => false, 'error' => 'Write the concept name.']);
                $ex = trim(mb_substr((string) ($in['explain'] ?? ''), 0, 3000)); $hint = trim(mb_substr((string) ($in['hint'] ?? ''), 0, 3000)); $id = (int) ($in['id'] ?? 0);
                if ($id) { $old = $m->query("SELECT explain_text, answer_hint FROM wi_concepts WHERE id = $id")->fetch_row(); if ($old && (trim((string) $old[0]) !== $ex || trim((string) $old[1]) !== $hint)) $m->query("UPDATE wi_answer_cache SET review = 1 WHERE concept_id = $id");
                    $st = $m->prepare("UPDATE wi_concepts SET name = ?, explain_text = ?, answer_hint = ? WHERE id = ? AND base = ?"); $st->bind_param('sssis', $name, $ex, $hint, $id, $base); $st->execute(); }
                else { $by = (string) $_SESSION['email']; $st = $m->prepare("INSERT INTO wi_concepts (base, name, explain_text, answer_hint, status, created_by, created_at) VALUES (?,?,?,?,'active',?,NOW())"); $st->bind_param('sssss', $base, $name, $ex, $hint, $by); $st->execute(); $id = (int) $m->insert_id; }
                $m->query("DELETE FROM wi_concept_terms WHERE concept_id = $id AND status = 'active'");
                $ins = $m->prepare("INSERT INTO wi_concept_terms (concept_id, term, norm, relation) VALUES (?,?,?,?)"); $n = 0;
                foreach ((array) ($in['terms'] ?? []) as $t) { $term = trim(mb_substr((string) ($t['term'] ?? ''), 0, 190)); $norm = wf_norm($term); if ($norm === '') continue;
                    $rel = isset(WF_RELATIONS[$t['relation'] ?? '']) ? $t['relation'] : 'related'; $ins->bind_param('isss', $id, $term, $norm, $rel); $ins->execute(); $n++; }
                wi_log('learn_concept_save', ['id' => $id, 'name' => $name, 'terms' => $n]); wi_json(['ok' => true, 'id' => $id]);
            }
            if ($do === 'test') { $c = wf_guide_match($base, (string) ($in['text'] ?? '')); wi_json(['ok' => true, 'concepts' => $c, 'prompt' => wf_guide_prompt($c)]); }
            wi_json(['ok' => false, 'error' => 'Unknown action.']);
        }
        $st = $m->prepare("SELECT * FROM wi_concepts WHERE base = ? ORDER BY status = 'suggested' DESC, name"); $st->bind_param('s', $base); $st->execute(); $r = $st->get_result(); $out = []; $ids = [];
        while ($x = $r->fetch_assoc()) { $out[$x['id']] = ['id' => (int) $x['id'], 'name' => $x['name'], 'explain' => (string) $x['explain_text'], 'hint' => (string) $x['answer_hint'], 'status' => $x['status'], 'hits' => (int) $x['hits'], 'by' => $x['created_by'], 'terms' => []]; }
        if ($out) { $r = $m->query("SELECT id, concept_id, term, relation, status, seen FROM wi_concept_terms WHERE concept_id IN (" . implode(',', array_keys($out)) . ") ORDER BY id"); while ($x = $r->fetch_assoc()) { if ($x['status'] === 'suggested') $out[$x['concept_id']]['pending'][] = ['id' => (int) $x['id'], 'term' => $x['term'], 'relation' => $x['relation'], 'seen' => (int) $x['seen']]; else $out[$x['concept_id']]['terms'][] = ['term' => $x['term'], 'relation' => $x['relation']]; } }
        wi_json(['ok' => true, 'concepts' => array_values($out), 'relations' => WF_RELATIONS]);
    }
    case 'learn_cache': {
        $base = wf_aid(); $m = wi_db(); if (!$m) wi_json(['ok' => false, 'error' => 'Database not available.']); wf_grow_install();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $in = wf_post(); $do = (string) ($in['do'] ?? ''); $id = (int) ($in['id'] ?? 0);
            if ($do === 'settings') { $all = wi_read('learning.json', []); $all[$base] = ['cache' => !empty($in['cache']), 'guide' => !empty($in['guide']), 'min_sim' => max(0.8, min(0.99, (float) ($in['min_sim'] ?? 0.9))), 'ttl_days' => max(1, min(365, (int) ($in['ttl_days'] ?? 30)))] + (array) ($all[$base] ?? []); wi_write('learning.json', $all); wi_json(['ok' => true]); }
            if ($do === 'off' || $do === 'on') { $s = $do === 'off' ? 'off' : 'active'; $st = $m->prepare("UPDATE wi_answer_cache SET status = ? WHERE id = ? AND base = ?"); $st->bind_param('sis', $s, $id, $base); $st->execute(); wi_json(['ok' => true]); }
            if ($do === 'edit') { $a = trim((string) ($in['answer'] ?? '')); if ($a === '') wi_json(['ok' => false, 'error' => 'Empty answer.']); $st = $m->prepare("UPDATE wi_answer_cache SET answer = ?, source = 'edited', review = 0 WHERE id = ? AND base = ?"); $st->bind_param('sis', $a, $id, $base); $st->execute(); wi_json(['ok' => true]); }
            if ($do === 'delete') { $st = $m->prepare("DELETE FROM wi_answer_cache WHERE id = ? AND base = ?"); $st->bind_param('is', $id, $base); $st->execute(); wi_json(['ok' => true]); }
            if ($do === 'clear_expired') { $st = $m->prepare("DELETE FROM wi_answer_cache WHERE base = ? AND expires_at < NOW()"); $st->bind_param('s', $base); $st->execute(); wi_json(['ok' => true, 'removed' => $m->affected_rows]); }
            wi_json(['ok' => false, 'error' => 'Unknown action.']);
        }
        $st = $m->prepare("SELECT id, question, answer, source, status, hits, tokens_saved, created_at, last_used, expires_at, review FROM wi_answer_cache WHERE base = ? ORDER BY hits DESC, id DESC LIMIT 1000"); $st->bind_param('s', $base); $st->execute(); $r = $st->get_result(); $rows = [];
        while ($x = $r->fetch_assoc()) { $x['id'] = (int) $x['id']; $x['hits'] = (int) $x['hits']; $x['tokens_saved'] = (int) $x['tokens_saved']; $rows[] = $x; }
        $sum = $m->query("SELECT COUNT(*) n, COALESCE(SUM(hits),0) hits, COALESCE(SUM(tokens_saved),0) saved FROM wi_answer_cache WHERE base = '" . $m->real_escape_string($base) . "'")->fetch_assoc();
        $L = wi_ai_tasks(); $today = date('Y-m-d'); $ai = 0; $tok = 0; foreach ($L as $x) if (str_starts_with((string) ($x['task'] ?? ''), 'Bot test') && substr((string) ($x['at'] ?? ''), 0, 10) === $today && ($x['account'] ?? $base) === $base) { $ai++; $tok += (int) ($x['tokens_in'] ?? 0) + (int) ($x['tokens_out'] ?? 0); }
        $log = wi_bot_log($base); $src = ['cache' => 0, 'guide' => 0, 'ai' => 0];
        foreach ($log as $x) if (substr((string) ($x['at'] ?? ''), 0, 10) === $today && !empty($x['reply'])) { $mdl = (string) ($x['model'] ?? ''); $src[$mdl === 'cache' ? 'cache' : (!empty($x['guide']) ? 'guide' : 'ai')]++; }
        wi_json(['ok' => true, 'rows' => $rows, 'settings' => wf_learn_cfg($base), 'per_answer' => wf_tokens_per_answer($base), 'total' => ['entries' => (int) $sum['n'], 'hits' => (int) $sum['hits'], 'saved' => (int) $sum['saved']], 'today' => ['ai_calls' => $ai, 'ai_tokens' => $tok, 'by_source' => $src]]);
    }
    case 'learn_suggest': {
        // AI proposes concepts + linked words from the team's knowledge base; they wait as "suggested" until approved.
        $in = wf_post(); $base = wf_aid(); @set_time_limit(200); $m = wi_db(); if (!$m) wi_json(['ok' => false, 'error' => 'Database not available.']);
        $kb = array_slice(array_values(array_filter(wf_kb($base), fn($x) => ($x['status'] ?? '') !== 'rejected')), 0, 120);
        if (!$kb) wi_json(['ok' => false, 'error' => 'Build the bot knowledge first (Bot: learning & test).']);
        $have = []; $r = $m->query("SELECT name FROM wi_concepts WHERE base = '" . $m->real_escape_string($base) . "'"); while ($x = $r->fetch_row()) $have[] = $x[0];
        $lines = ''; foreach ($kb as $x) $lines .= '- Q: ' . mb_substr((string) ($x['q'] ?? ''), 0, 160) . ' | A: ' . mb_substr((string) ($x['a'] ?? ''), 0, 260) . "\n";
        $prompt = wf_prompt('guide_suggest', $base, ['existing' => implode('، ', $have), 'relations' => implode(', ', array_keys(WF_RELATIONS)), 'knowledge' => $lines]);
        $ai = wi_ai('Learning guide suggestions', $prompt, 180, ['subject' => 'guide']);
        if (empty($ai['ok'])) wi_json(['ok' => false, 'error' => $ai['error'] ?? 'AI failed.']);
        $n = 0; $by = 'AI';
        foreach ((array) ($ai['data']['concepts'] ?? []) as $c) {
            $name = trim(mb_substr((string) ($c['name'] ?? ''), 0, 190)); if ($name === '' || in_array($name, $have, true)) continue;
            $ex = mb_substr((string) ($c['explain'] ?? ''), 0, 1000); $hint = mb_substr((string) ($c['hint'] ?? ''), 0, 1000);
            $st = $m->prepare("INSERT INTO wi_concepts (base, name, explain_text, answer_hint, status, created_by, created_at) VALUES (?,?,?,?,'suggested',?,NOW())"); $st->bind_param('sssss', $base, $name, $ex, $hint, $by); $st->execute(); $id = (int) $m->insert_id;
            $ins = $m->prepare("INSERT INTO wi_concept_terms (concept_id, term, norm, relation) VALUES (?,?,?,?)");
            foreach ((array) ($c['terms'] ?? []) as $t) { $term = trim((string) ($t['term'] ?? '')); $norm = wf_norm($term); if ($norm === '') continue; $rel = isset(WF_RELATIONS[$t['relation'] ?? '']) ? $t['relation'] : 'related'; $ins->bind_param('isss', $id, $term, $norm, $rel); $ins->execute(); }
            $n++;
        }
        wi_json(['ok' => true, 'added' => $n, 'task' => $ai['task'] ?? null]);
    }
    }
}

/** True when the text quotes the Quran (4 consecutive words found in the Uthmani text), or uses verse markers. */
function wf_has_quran(string $text, string $aid = ''): bool {
    if ($aid !== '' && function_exists('wf_pack') && empty(wf_pack($aid)['quran_check'])) return false; // only for business profiles that use the Quran check
    static $qm = null; $qm ??= wf_seed_alt('quran_markers');
    if (preg_match('/[﴿﴾]' . ($qm !== '' ? '|' . $qm : '') . '/u', $text)) return true;
    static $grams = null;
    if ($grams === null) {
        $grams = []; $f = dirname(__DIR__, 2) . '/wa-local/data/quran-uthmani.txt';
        if (is_readable($f)) foreach (file($f) as $line) { if (!preg_match('/^\d+\|\d+\|(.+)$/u', trim($line), $mm)) continue; $w = explode(' ', wf_norm($mm[1])); for ($i = 0; $i + 3 < count($w); $i++) $grams[$w[$i] . ' ' . $w[$i + 1] . ' ' . $w[$i + 2] . ' ' . $w[$i + 3]] = 1; }
    }
    if (!$grams) return false;
    $w = explode(' ', wf_norm($text)); $hits = 0;
    for ($i = 0; $i + 3 < count($w); $i++) if (isset($grams[$w[$i] . ' ' . $w[$i + 1] . ' ' . $w[$i + 2] . ' ' . $w[$i + 3]])) { if (++$hits >= 1) return true; }
    return false;
}

/* ---------- File library: uploaded files the bot learns from (text is split into short passages) ---------- */
function wf_docs_install(): bool {
    $m = wi_db(); if (!$m) return false;
    return (bool) ($m->query("CREATE TABLE IF NOT EXISTS wi_learn_docs (
        id INT AUTO_INCREMENT PRIMARY KEY, base VARCHAR(40) NOT NULL, name VARCHAR(255) NOT NULL, kind VARCHAR(10) NOT NULL, size INT NOT NULL DEFAULT 0, chars INT NOT NULL DEFAULT 0,
        chunks INT NOT NULL DEFAULT 0, status ENUM('active','off','unreadable') NOT NULL DEFAULT 'active', note VARCHAR(255) NOT NULL DEFAULT '', created_by VARCHAR(190) NOT NULL DEFAULT '', created_at DATETIME NULL, hits INT NOT NULL DEFAULT 0, KEY b (base)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci")
    && $m->query("CREATE TABLE IF NOT EXISTS wi_learn_chunks (
        id INT AUTO_INCREMENT PRIMARY KEY, doc_id INT NOT NULL, base VARCHAR(40) NOT NULL, idx INT NOT NULL, text TEXT NOT NULL, words TEXT NOT NULL, KEY d (doc_id), KEY b (base)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"));
}
/** Plain text from an uploaded file. Returns [text, note]. */
function wf_doc_text(string $file, string $ext): array {
    $ext = strtolower($ext);
    if (in_array($ext, ['txt', 'md', 'csv', 'json'], true)) return [(string) file_get_contents($file), ''];
    if (in_array($ext, ['html', 'htm'], true)) return [html_entity_decode(strip_tags(preg_replace('#<(script|style)\b.*?</\1>#is', ' ', (string) file_get_contents($file))), ENT_QUOTES, 'UTF-8'), ''];
    if ($ext === 'docx') {
        $z = new ZipArchive(); if ($z->open($file) !== true) return ['', 'The Word file could not be opened.'];
        $x = (string) $z->getFromName('word/document.xml'); $z->close();
        $x = preg_replace(['#</w:p>#', '#<w:tab/>#', '#<w:br/>#'], ["\n", "\t", "\n"], $x);
        return [html_entity_decode(strip_tags($x), ENT_QUOTES | ENT_XML1, 'UTF-8'), ''];
    }
    if ($ext === 'pdf') {
        // Best effort without a PDF tool on the server: readable only for simple PDFs (Arabic PDFs usually need a Word copy).
        $raw = (string) file_get_contents($file); $out = '';
        if (preg_match_all('#stream\r?\n(.*?)\r?\nendstream#s', $raw, $mm)) foreach ($mm[1] as $st) { $d = @gzuncompress($st); if ($d === false) $d = @gzinflate(substr($st, 2)); if ($d === false) continue;
            if (preg_match_all('#\((.*?)(?<!\\\\)\)\s*Tj|\[(.*?)\]\s*TJ#s', $d, $tt)) foreach ($tt[0] as $t) { preg_match_all('#\((.*?)(?<!\\\\)\)#s', $t, $p); $out .= implode('', $p[1]) . "\n"; } }
        $letters = preg_match_all('/[\p{Arabic}\p{Latin}]/u', $out); $len = mb_strlen($out);
        if ($len < 200 || $letters < $len * 0.4) return ['', 'This PDF\'s text could not be read on the server — save it as a Word file (.docx) and upload that.'];
        return [$out, 'PDF text read on a best-effort basis — check the passages.'];
    }
    return ['', 'This file type is not supported. Use Word (.docx), text (.txt / .md), .csv, .html or .json.'];
}
/** Splits text into passages of about 700 characters, on paragraph boundaries. */
function wf_doc_chunks(string $text): array {
    $text = trim(preg_replace("/[ \t]+/u", ' ', preg_replace("/\r\n?/", "\n", $text)));
    $paras = array_values(array_filter(array_map('trim', preg_split("/\n{2,}|\n(?=\s*(?:\d+[\.\-\)]|[•\-\*]\s))/u", $text))));
    $out = []; $cur = '';
    foreach ($paras as $p) {
        while (mb_strlen($p) > 1200) { $cut = mb_strrpos(mb_substr($p, 0, 900), ' ') ?: 900; $out[] = trim(($cur !== '' ? $cur . "\n" : '') . mb_substr($p, 0, $cut)); $cur = ''; $p = trim(mb_substr($p, $cut)); }
        if (mb_strlen($cur) + mb_strlen($p) > 800 && $cur !== '') { $out[] = $cur; $cur = ''; }
        $cur = $cur === '' ? $p : $cur . "\n" . $p;
    }
    if ($cur !== '') $out[] = $cur;
    return array_values(array_filter($out, fn($c) => mb_strlen($c) >= 20));
}
/** Passages from the file library that share the most words with the message. */
function wf_doc_retrieve(string $base, string $text, int $n = 3): array {
    $m = wi_db(); if (!$m) return []; $want = array_flip(wf_words($text)); if (!$want) return [];
    if (function_exists('wf_clean_install')) wf_clean_install();
    $st = $m->prepare("SELECT c.id, c.doc_id, c.text, c.words, c.verdict, d.name FROM wi_learn_chunks c JOIN wi_learn_docs d ON d.id = c.doc_id WHERE c.base = ? AND d.status = 'active' AND c.words <> '' AND c.verdict <> 'conflict'"); // what the team does differently is never used
    $st->bind_param('s', $base); $st->execute(); $r = $st->get_result(); $out = [];
    while ($x = $r->fetch_assoc()) { $w = array_flip(explode(' ', $x['words'])); $hit = count(array_intersect_key($w, $want)); if ($hit >= 2 || ($hit && count($want) <= 2)) $out[] = ['score' => $hit / max(3, count($want)) * 3 * (($x['verdict'] ?? '') === 'agree' ? 1.5 : 1), 'agree' => ($x['verdict'] ?? '') === 'agree', 'kind' => 'file', 'id' => 'f' . $x['id'], 'doc' => (int) $x['doc_id'], 'title' => $x['name'], 'topic' => $x['name'], 'text' => $x['text']]; }
    usort($out, fn($a, $b) => $b['score'] <=> $a['score']); $out = array_slice($out, 0, $n);
    if ($out) $m->query("UPDATE wi_learn_docs SET hits = hits + 1 WHERE id IN (" . implode(',', array_unique(array_map(fn($x) => (int) $x['doc'], $out))) . ")");
    return $out;
}

/* ---------- Owner rules as a list: edit, add, switch off, reorder (the bot reads the switched-on ones) ---------- */
function wf_rules_list(string $aid): array {
    $c = wi_read('bot.json', [])[$aid] ?? [];
    if (isset($c['rules_list']) && is_array($c['rules_list'])) return $c['rules_list'];
    $out = []; foreach (array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) ($c['rules'] ?? ''))))) as $l) $out[] = ['text' => $l, 'on' => true];
    return $out;
}
function wf_rules_save(string $aid, array $list): void {
    $clean = []; foreach ($list as $r) { $t = trim(mb_substr((string) ($r['text'] ?? ''), 0, 500)); if ($t !== '') $clean[] = ['text' => $t, 'on' => !empty($r['on'])]; }
    $all = wi_read('bot.json', []); $all[$aid]['rules_list'] = array_slice($clean, 0, 100);
    $all[$aid]['rules'] = implode("\n", array_map(fn($r) => $r['text'], array_filter($clean, fn($r) => $r['on']))); // what the bot reads
    wi_write('bot.json', $all);
}

/** Reads a file, splits it into passages and stores both (shared by uploads and Google Drive imports). */
function wf_doc_ingest(string $base, string $name, string $ext, string $path, int $size, string $by, bool $uploaded = false, string $source = '', string $driveId = '', string $driveMod = ''): array {
    $m = wi_db(); [$text, $note] = wf_doc_text($path, $ext);
    $chunks = $text !== '' ? wf_doc_chunks($text) : []; $status = $chunks ? 'active' : 'unreadable'; $chars = mb_strlen($text); $nc = count($chunks);
    if ($source !== '') $note = trim($note . ' ' . $source);
    $st = $m->prepare("INSERT INTO wi_learn_docs (base, name, kind, size, chars, chunks, status, note, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())");
    $st->bind_param('sssiiisss', $base, $name, $ext, $size, $chars, $nc, $status, $note, $by); $st->execute(); $id = (int) $m->insert_id;
    $dir = WI_DIR . '/learn_docs/' . preg_replace('/\W/', '', $base); if (!is_dir($dir)) { @mkdir($dir, 0700, true); @chmod($dir, 0700); @chmod(dirname($dir), 0700); }
    $dst = $dir . '/' . $id . '.' . (in_array($ext, ['txt', 'md', 'csv', 'json', 'html', 'htm', 'docx', 'pdf'], true) ? $ext : 'bin'); // never an executable extension // the original is kept (not served on the web)
    if ($uploaded) @move_uploaded_file($path, $dst); else @rename($path, $dst);
    // every passage is checked against what this number already has: a repeat (dup_pct and above) is kept out of answers and points to its original,
    // a close one (sim_pct to dup_pct) is marked similar for review; the file's note says how much repeated and from where
    $dup = wf_kb_dedupe_db(); $cfgL = wf_learn_cfg($base); $dPct = max(50, min(100, (int) $cfgL['dup_pct'])); $sPct = max(30, min($dPct, (int) $cfgL['sim_pct']));
    $bands = []; $mhs = []; $names = [];
    if ($dup) { $q = $m->query("SELECT k.id, k.mh, d.name FROM wi_learn_chunks k JOIN wi_learn_docs d ON d.id = k.doc_id WHERE k.base = '" . $m->real_escape_string($base) . "' AND k.dropped = 0 AND k.mh IS NOT NULL");
        while ($q && ($x = $q->fetch_assoc())) { $mhs[(int) $x['id']] = (string) $x['mh']; $names[(int) $x['id']] = (string) $x['name']; foreach (wf_kb_mh_bands((string) $x['mh']) as $bk) $bands[$bk][] = (int) $x['id']; } }
    $ins = $m->prepare("INSERT INTO wi_learn_chunks (doc_id, base, idx, text, words) VALUES (?,?,?,?,?)"); $nDup = 0; $nSim = 0; $from = [];
    foreach ($chunks as $i => $c) { $w = implode(' ', wf_words($c)); $ins->bind_param('isiss', $id, $base, $i, $c, $w); $ins->execute(); $cid = (int) $m->insert_id;
        if (!$dup || !$cid) continue; $mh = wf_kb_minhash($c); $best = 0; $bid = 0; $seen = [];
        foreach (wf_kb_mh_bands($mh) as $bk) foreach ($bands[$bk] ?? [] as $oid) { if (isset($seen[$oid])) continue; $seen[$oid] = 1; $p = wf_kb_mh_pct($mh, $mhs[$oid]); if ($p > $best) { $best = $p; $bid = $oid; } }
        if ($best >= $dPct) { $nDup++; $from[$names[$bid] ?? '?'] = ($from[$names[$bid] ?? '?'] ?? 0) + 1; $why = $best . '% = #' . $bid . ' ' . mb_substr($names[$bid] ?? '', 0, 80);
            $m->query("UPDATE wi_learn_chunks SET mh = '" . $mh . "', dropped = 1, verdict = 'duplicate', verdict_why = '" . $m->real_escape_string($why) . "' WHERE id = " . $cid); continue; }
        $sim = $best >= $sPct; if ($sim) $nSim++;
        $m->query("UPDATE wi_learn_chunks SET mh = '" . $mh . "'" . ($sim ? ", verdict = 'similar', verdict_why = '" . $m->real_escape_string($best . '% ~ #' . $bid . ' ' . mb_substr($names[$bid] ?? '', 0, 80)) . "'" : '') . " WHERE id = " . $cid);
        $mhs[$cid] = $mh; $names[$cid] = $name; foreach (wf_kb_mh_bands($mh) as $bk) $bands[$bk][] = $cid; }
    if ($nDup || $nSim) { arsort($from); $note = trim($note . ' ' . wi_t('Repeated') . ': ' . $nDup . '/' . $nc . ($nc ? ' (' . round($nDup * 100 / $nc) . '%)' : '') . ($from ? ' ' . wi_t('from') . ' «' . array_key_first($from) . '»' : '') . ($nSim ? ' · ' . wi_t('Similar') . ': ' . $nSim : ''));
        $note = mb_substr($note, 0, 250); $u = $m->prepare("UPDATE wi_learn_docs SET note = ? WHERE id = ?"); $u->bind_param('si', $note, $id); $u->execute(); }
    if ($driveId !== '') { $u = $m->prepare("UPDATE wi_learn_docs SET drive_id = ?, drive_modified = ? WHERE id = ?"); $u->bind_param('ssi', $driveId, $driveMod, $id); $u->execute(); }
    return ['id' => $id, 'chunks' => $nc, 'status' => $status, 'note' => $note, 'duplicates' => $nDup ?? 0, 'similar' => $nSim ?? 0];
}
/** Google Drive: what the connected account can read for learning (Google Docs are exported as text). */
const WF_DRIVE_LEARN_TYPES = [
    'application/vnd.google-apps.document' => ['txt', 'text/plain'],
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx', null],
    'text/plain' => ['txt', null], 'text/markdown' => ['md', null], 'text/csv' => ['csv', null], 'text/html' => ['html', null], 'application/json' => ['json', null], 'application/pdf' => ['pdf', null],
    'application/vnd.google-apps.spreadsheet' => ['csv', 'text/csv'],
];
function wf_api_learning_docs(string $api): void {
    switch ($api) {
    case 'learn_docs': {
        $base = wf_aid(); $m = wi_db(); if (!$m) wi_json(['ok' => false, 'error' => 'Database not available.']); wf_docs_install(); wf_clean_install();
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['file'])) {
            wi_check_csrf(); @set_time_limit(300);
            $f = $_FILES['file']; if (($f['error'] ?? 1) !== UPLOAD_ERR_OK) wi_json(['ok' => false, 'error' => 'Upload failed.']);
            if ($f['size'] > (int) wi_sys('max_learn_mb') * 1048576) wi_json(['ok' => false, 'error' => 'The file is larger than the allowed size (' . (int) wi_sys('max_learn_mb') . ' MB).']);
            $name = mb_substr(basename((string) $f['name']), 0, 200); $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $res = wf_doc_ingest($base, $name, $ext, $f['tmp_name'], (int) $f['size'], (string) $_SESSION['email'], true);
            wi_log('learn_doc_upload', ['name' => $name, 'chunks' => $res['chunks']]);
            if (!empty($res['chunks'])) wi_job_add('doc_verify', $base, ['base' => $base], (string) $_SESSION['email']); // checked against the groups in the background
            wi_json(['ok' => true] + $res);
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $in = wf_post(); $do = (string) ($in['do'] ?? ''); $id = (int) ($in['id'] ?? 0);
            if (in_array($do, ['delete', 'on', 'off', 'chunks'], true)) { $st = $m->prepare("SELECT 1 FROM wi_learn_docs WHERE id = ? AND base = ?"); $st->bind_param('is', $id, $base); $st->execute(); if (!$st->get_result()->fetch_row()) wi_json(['ok' => false, 'error' => 'Not found.'], 404); }
            if ($do === 'delete') { $m->query("DELETE FROM wi_learn_chunks WHERE doc_id = $id"); $st = $m->prepare("DELETE FROM wi_learn_docs WHERE id = ? AND base = ?"); $st->bind_param('is', $id, $base); $st->execute(); foreach (glob(WI_DIR . '/learn_docs/' . preg_replace('/\W/', '', $base) . '/' . $id . '.*') ?: [] as $x) @unlink($x); wi_log('learn_doc_delete', ['id' => $id]); wi_json(['ok' => true]); }
            if ($do === 'on' || $do === 'off') { $s = $do === 'on' ? 'active' : 'off'; $st = $m->prepare("UPDATE wi_learn_docs SET status = ? WHERE id = ? AND base = ? AND status <> 'unreadable'"); $st->bind_param('sis', $s, $id, $base); $st->execute(); wi_json(['ok' => true]); }
            if ($do === 'chunks') { wf_clean_install(); $st = $m->prepare("SELECT id, idx, text, orig, dropped, topic FROM wi_learn_chunks WHERE doc_id = ? AND base = ? ORDER BY idx LIMIT 400"); $st->bind_param('is', $id, $base); $st->execute(); wi_json(['ok' => true, 'chunks' => $st->get_result()->fetch_all(MYSQLI_ASSOC)]); }
            if ($do === 'verify') { wf_admin(); wi_job_add('doc_verify', $base, ['base' => $base], (string) $_SESSION['email']); wi_json(['ok' => true, 'queued' => true]); } // all files against the groups
            if ($do === 'search') { wi_json(['ok' => true, 'hits' => wf_doc_retrieve($base, (string) ($in['text'] ?? ''), 5)]); }
            wi_json(['ok' => false, 'error' => 'Unknown action.']);
        }
        wf_clean_install();
        $st = $m->prepare("SELECT d.id, d.name, d.kind, d.size, d.chars, d.chunks, d.status, d.note, d.created_by, d.created_at, d.hits, d.cleaned_at, d.clean_kept, d.clean_dropped,
            (SELECT COUNT(*) FROM wi_learn_chunks c WHERE c.doc_id = d.id AND c.verdict = 'agree') AS v_agree, (SELECT COUNT(*) FROM wi_learn_chunks c WHERE c.doc_id = d.id AND c.verdict = 'conflict') AS v_conflict,
            (SELECT COUNT(*) FROM wi_learn_chunks c WHERE c.doc_id = d.id AND c.verdict = '' AND c.dropped = 0) AS v_wait FROM wi_learn_docs d WHERE d.base = ? ORDER BY d.id DESC"); $st->bind_param('s', $base); $st->execute();
        wi_json(['ok' => true, 'docs' => $st->get_result()->fetch_all(MYSQLI_ASSOC), 'linked' => wi_read('learning.json', [])[$base]['drive_folder'] ?? null, 'import' => wi_read('learning.json', [])[$base]['import'] ?? null]);
    }
    case 'learn_drive': {
        // The connected Google account is the operator's; a client's panel uploads files instead.
        $base = wf_aid(); wf_docs_install(); @set_time_limit(600);
        $c = wf_drive_cfg(); if (empty($c['refresh_token'])) wi_json(['ok' => false, 'need' => 'connect', 'error' => wf_google_ws() !== '' ? 'Connect your Google account first (My account → Google account).' : 'Connect Google first (General settings → Google account).']);
        if (strpos((string) ($c['scopes'] ?? ''), 'drive.readonly') === false) wi_json(['ok' => false, 'need' => 'read', 'via' => wf_google_ws() === '' ? 'backup_cfg' : 'my_google', 'error' => 'Reading your Drive folders is an extra Google permission. Allow it once and the bot can learn from your Drive.']);
        $t = wf_drive_token(); if (!$t['ok']) wi_json($t);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $in = wf_post();
            if (($in['do'] ?? '') === 'link') { wf_admin(); $L = wi_read('learning.json', []); $L[$base]['drive_folder'] = ['id' => preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($in['id'] ?? '')), 'name' => mb_substr((string) ($in['name'] ?? ''), 0, 200), 'by' => $_SESSION['email'], 'at' => date('c')];
                wi_write('learning.json', $L); wi_log('learn_drive_link', ['folder' => $in['name'] ?? '']); wi_job_add('learn_sync', $base, ['base' => $base], (string) $_SESSION['email']); wi_json(['ok' => true]); }
            if (($in['do'] ?? '') === 'unlink') { wf_admin(); $L = wi_read('learning.json', []); unset($L[$base]['drive_folder']); wi_write('learning.json', $L); wi_json(['ok' => true]); }
            if (($in['do'] ?? '') === 'import_folder') {
                $fid = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($in['id'] ?? '')); if ($fid === '') wi_json(['ok' => false, 'error' => 'Choose a folder.']);
                $L = wi_read('learning.json', []); $L[$base]['import'] = ['folder' => (string) ($in['name'] ?? ''), 'running' => true, 'done' => 0, 'total' => 0, 'at' => date('c')]; wi_write('learning.json', $L);
                wi_job_add('learn_import', $base . ':' . $fid, ['base' => $base, 'id' => $fid, 'name' => mb_substr((string) ($in['name'] ?? ''), 0, 200), 'deep' => !empty($in['deep']), 'by' => (string) $_SESSION['email']], (string) $_SESSION['email']);
                wi_log('learn_drive_import_folder', ['folder' => $in['name'] ?? '']); wi_json(['ok' => true, 'queued' => true]); }
            if (($in['do'] ?? '') === 'sync') { wi_job_add('learn_sync', $base, ['base' => $base], (string) $_SESSION['email']); wi_json(['ok' => true, 'queued' => true]); }
            // import the chosen files
            $out = [];
            foreach (array_slice((array) ($in['files'] ?? []), 0, 30) as $f) {
                $id = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($f['id'] ?? '')); $mime = (string) ($f['mime'] ?? ''); $name = mb_substr((string) ($f['name'] ?? 'file'), 0, 200);
                if ($id === '') continue; $got = wf_drive_fetch($t['token'], $id, $mime, $name);
                if (!$got[0]) { $out[] = ['name' => $name, 'ok' => false, 'error' => $got[1]]; continue; }
                $r = wf_doc_ingest($base, $got[2], $got[1], $got[0], (int) filesize($got[0]), (string) $_SESSION['email'], false, '(Google Drive)', $id, (string) ($f['modified'] ?? ''));
                $name = $got[2];
                $out[] = ['name' => $name, 'ok' => true] + $r;
            }
            wi_log('learn_drive_import', ['files' => count($out)]);
            wi_json(['ok' => true, 'results' => $out]);
        }
        // browse / search
        $q = trim((string) ($_GET['q'] ?? '')); $folder = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($_GET['folder'] ?? ''));
        $mimes = array_merge(array_keys(WF_DRIVE_LEARN_TYPES), ['application/vnd.google-apps.folder']);
        $cond = "trashed = false and (" . implode(' or ', array_map(fn($x) => "mimeType = '" . $x . "'", $mimes)) . ")";
        if ($q !== '') $cond .= " and name contains '" . str_replace(["\\", "'"], ["\\\\", "\\'"], $q) . "'";
        elseif ($folder !== '') $cond .= " and '" . $folder . "' in parents";
        $r = wf_drive_req('GET', 'https://www.googleapis.com/drive/v3/files?' . http_build_query(['q' => $cond, 'fields' => 'files(id,name,mimeType,size,modifiedTime,iconLink)', 'orderBy' => 'folder,modifiedTime desc', 'pageSize' => 100, 'pageToken' => (string) ($_GET['page'] ?? '')]), $t['token']);
        if ($r['code'] !== 200) wi_json(['ok' => false, 'error' => 'Google Drive refused (HTTP ' . $r['code'] . ': ' . (is_array($r['body']) ? ($r['body']['error']['message'] ?? '') : '') . ')']);
        $files = array_map(fn($f) => ['id' => $f['id'], 'name' => $f['name'], 'mime' => $f['mimeType'], 'size' => (int) ($f['size'] ?? 0), 'modified' => $f['modifiedTime'] ?? '', 'folder' => $f['mimeType'] === 'application/vnd.google-apps.folder', 'gdoc' => str_starts_with($f['mimeType'], 'application/vnd.google-apps.') && $f['mimeType'] !== 'application/vnd.google-apps.folder'], $r['body']['files'] ?? []);
        wi_json(['ok' => true, 'files' => $files, 'email' => $c['email'] ?? '', 'linked' => wi_read('learning.json', [])[$base]['drive_folder'] ?? null, 'import' => wi_read('learning.json', [])[$base]['import'] ?? null]);
    }
    case 'learn_rules': {
        $aid = wf_aid();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') { $in = wf_post(); wf_admin();
            if (($in['do'] ?? '') === 'style') { $D = wf_style_defaults(); $x = (array) ($in['style'] ?? []);
                if (!empty($x['reset'])) $st = $D; else $st = ['prefix_on' => !empty($x['prefix_on']), 'prefix' => mb_substr(trim((string) ($x['prefix'] ?? '')), 0, 20), 'intro_on' => !empty($x['intro_on']), 'intro_groups' => !empty($x['intro_groups']),
                    'intro_text' => mb_substr(trim((string) ($x['intro_text'] ?? '')), 0, 1000), 'end_mode' => in_array($x['end_mode'] ?? '', ['fit', 'none', 'any'], true) ? $x['end_mode'] : 'fit',
                    'end_emojis' => array_slice(array_values(array_filter(array_map(fn($f) => ['e' => mb_substr(trim((string) ($f['e'] ?? '')), 0, 12), 'words' => mb_substr(trim((string) ($f['words'] ?? '')), 0, 1000)], (array) ($x['end_emojis'] ?? [])), fn($f) => $f['e'] !== '')), 0, 40),
                    'strip_romantic' => !empty($x['strip_romantic']), 'strip_affection' => !empty($x['strip_affection']), 'affection_words' => mb_substr((string) ($x['affection_words'] ?? ''), 0, 3000),
                    'prompt_rules' => array_slice(array_values(array_filter(array_map(fn($l) => mb_substr(trim((string) $l), 0, 2000), (array) ($x['prompt_rules'] ?? [])))), 0, 40)];
                $all = wi_read('bot.json', []); $all[$aid]['style'] = $st; wi_write('bot.json', $all); wi_log('bot_style_save', ['account' => $aid]); wi_json(['ok' => true, 'style' => wf_style_cfg($aid)]); }
            wf_rules_save($aid, (array) ($in['rules'] ?? [])); wi_log('bot_rules_save', ['count' => count((array) ($in['rules'] ?? []))]); }
        wi_json(['ok' => true, 'rules' => wf_rules_list($aid), 'style' => wf_style_cfg($aid)]);
    }
    }
}

/** A message that makes sense on its own (a full general question) — safe to answer from / save to the cache whatever came before it. */
function wf_cache_standalone(string $text): bool {
    $n = wf_norm($text); $w = count(array_filter(explode(' ', $n), fn($x) => mb_strlen($x) > 1));
    if ($w < 3 || $w > 60) return false;
    static $fw = null, $qw = null; $fw ??= wf_seed_alt('filler_words'); $qw ??= wf_seed_alt('question_words_cache');
    if (preg_match('/^(' . $fw . ')\b/u', $n)) return false;          // replies that lean on the previous message
    return (bool) preg_match('/[؟?]|(^|\s)(' . $qw . ')(\s|$)/u', $n . ' ' . $text);
}
/** Approved knowledge-base answers go into the cache automatically (answers with Quran wait for a check, as usual). */
function wf_cache_seed_kb(string $base): int {
    $n = 0; foreach (wf_kb($base) as $k) {
        if (($k['status'] ?? '') !== 'approved' || trim((string) ($k['q'] ?? '')) === '' || trim((string) ($k['a'] ?? '')) === '') continue;
        $before = wf_cache_count($base); wf_cache_put($base, (string) $k['q'], (string) $k['a'], 'kb'); if (wf_cache_count($base) > $before) $n++;
    }
    return $n;
}
function wf_cache_count(string $base): int { $m = wi_db(); if (!$m) return 0; $st = $m->prepare("SELECT COUNT(*) FROM wi_answer_cache WHERE base = ?"); $st->bind_param('s', $base); $st->execute(); return (int) $st->get_result()->fetch_row()[0]; }

/** Downloads one Drive file (Google Docs/Sheets exported) to a temp file. Returns [path, ext, name] or [null, error]. */
function wf_drive_fetch(string $token, string $id, string $mime, string $name): array {
    $type = WF_DRIVE_LEARN_TYPES[$mime] ?? null; if (!$type) return [null, 'Unsupported type'];
    [$ext, $export] = $type; if ($export && !str_ends_with(mb_strtolower($name), '.' . $ext)) $name .= '.' . $ext;
    $tmp = tempnam(sys_get_temp_dir(), 'wid'); $fh = fopen($tmp, 'wb');
    $url = $export ? 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($id) . '/export?mimeType=' . rawurlencode($export) : 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($id) . '?alt=media';
    $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_FILE => $fh, CURLOPT_TIMEOUT => 300, CURLOPT_FOLLOWLOCATION => true, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token]]);
    curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); fclose($fh);
    if ($code !== 200 || !filesize($tmp)) { @unlink($tmp); return [null, 'Google Drive refused (HTTP ' . $code . ')']; }
    if (filesize($tmp) > (int) wi_sys('max_learn_mb') * 1048576) { @unlink($tmp); return [null, 'The file is larger than the allowed size (' . (int) wi_sys('max_learn_mb') . ' MB).']; }
    return [$tmp, $ext, $name];
}
/** Linked Drive folder: new files are added, changed ones re-read, removed ones switched off. */
function wf_drive_folder_sync(string $base): array {
    $L = wi_read('learning.json', []); $fo = $L[$base]['drive_folder'] ?? null; if (!$fo || empty($fo['id'])) return ['ok' => false, 'error' => 'No folder linked.'];
    $t = wf_drive_token(); if (!$t['ok']) return $t; $m = wi_db(); wf_docs_install();
    $files = []; $page = '';
    do { $cond = "trashed = false and '" . preg_replace('/[^A-Za-z0-9_-]/', '', $fo['id']) . "' in parents and (" . implode(' or ', array_map(fn($x) => "mimeType = '" . $x . "'", array_keys(WF_DRIVE_LEARN_TYPES))) . ")";
        $r = wf_drive_req('GET', 'https://www.googleapis.com/drive/v3/files?' . http_build_query(['q' => $cond, 'fields' => 'nextPageToken,files(id,name,mimeType,modifiedTime)', 'pageSize' => 200, 'pageToken' => $page]), $t['token']);
        if ($r['code'] !== 200) return ['ok' => false, 'error' => 'Google Drive refused (HTTP ' . $r['code'] . ')'];
        foreach ($r['body']['files'] ?? [] as $f) $files[$f['id']] = $f; $page = (string) ($r['body']['nextPageToken'] ?? ''); } while ($page !== '');
    $have = []; $st = $m->prepare("SELECT id, drive_id, drive_modified, status FROM wi_learn_docs WHERE base = ? AND drive_id IS NOT NULL"); $st->bind_param('s', $base); $st->execute(); $rr = $st->get_result();
    while ($x = $rr->fetch_assoc()) $have[$x['drive_id']] = $x;
    $sum = ['added' => 0, 'updated' => 0, 'removed' => 0, 'errors' => []];
    foreach ($files as $fid => $f) {
        $old = $have[$fid] ?? null;
        if ($old && $old['drive_modified'] === $f['modifiedTime']) { if ($old['status'] === 'off') { /* switched off by a person: leave it */ } continue; }
        $got = wf_drive_fetch($t['token'], $fid, $f['mimeType'], $f['name']); if (!$got[0]) { $sum['errors'][] = $f['name'] . ': ' . $got[1]; continue; }
        if ($old) { $oid = (int) $old['id']; $m->query("DELETE FROM wi_learn_chunks WHERE doc_id = $oid"); $m->query("DELETE FROM wi_learn_docs WHERE id = $oid"); foreach (glob(WI_DIR . '/learn_docs/' . preg_replace('/\W/', '', $base) . '/' . $oid . '.*') ?: [] as $z) @unlink($z); }
        wf_doc_ingest($base, $got[2], $got[1], $got[0], (int) filesize($got[0]), 'Google Drive', false, '(Google Drive · ' . ($fo['name'] ?? '') . ')', $fid, (string) $f['modifiedTime']);
        $sum[$old ? 'updated' : 'added']++;
    }
    foreach ($have as $fid => $x) if (!isset($files[$fid]) && $x['status'] === 'active') { $oid = (int) $x['id']; $m->query("UPDATE wi_learn_docs SET status = 'off', note = 'Removed from the linked Drive folder' WHERE id = $oid"); $sum['removed']++; }
    $L = wi_read('learning.json', []); $L[$base]['drive_folder']['last'] = ['at' => date('c')] + $sum; wi_write('learning.json', $L);
    return ['ok' => true] + $sum;
}

/** Imports a whole Drive folder (and its subfolders, up to 3 levels, at most 300 files). Files already imported and unchanged are skipped. */
function wf_drive_import_folder(string $base, string $folder, string $fname, string $by, bool $deep = true): array {
    $t = wf_drive_token(); if (!$t['ok']) return $t; $m = wi_db(); wf_docs_install();
    $todo = [[$folder, $fname, 0]]; $files = [];
    while ($todo && count($files) < 300) {
        [$fid, $path, $lvl] = array_shift($todo); $page = '';
        do { $cond = "trashed = false and '" . preg_replace('/[^A-Za-z0-9_-]/', '', $fid) . "' in parents";
            $r = wf_drive_req('GET', 'https://www.googleapis.com/drive/v3/files?' . http_build_query(['q' => $cond, 'fields' => 'nextPageToken,files(id,name,mimeType,modifiedTime)', 'pageSize' => 200, 'pageToken' => $page]), $t['token']);
            if ($r['code'] !== 200) return ['ok' => false, 'error' => 'Google Drive refused (HTTP ' . $r['code'] . ')'];
            foreach ($r['body']['files'] ?? [] as $f) {
                if ($f['mimeType'] === 'application/vnd.google-apps.folder') { if ($deep && $lvl < 3) $todo[] = [$f['id'], $path . ' › ' . $f['name'], $lvl + 1]; continue; }
                if (isset(WF_DRIVE_LEARN_TYPES[$f['mimeType']])) $files[$f['id']] = $f + ['path' => $path];
            }
            $page = (string) ($r['body']['nextPageToken'] ?? ''); } while ($page !== '' && count($files) < 300);
    }
    $have = []; $st = $m->prepare("SELECT drive_id, drive_modified FROM wi_learn_docs WHERE base = ? AND drive_id IS NOT NULL"); $st->bind_param('s', $base); $st->execute(); $rr = $st->get_result();
    while ($x = $rr->fetch_assoc()) $have[$x['drive_id']] = $x['drive_modified'];
    $sum = ['found' => count($files), 'added' => 0, 'skipped' => 0, 'errors' => []]; $n = 0;
    foreach ($files as $fid => $f) {
        if (isset($have[$fid]) && $have[$fid] === $f['modifiedTime']) { $sum['skipped']++; continue; }
        $t = wf_drive_token(); $got = wf_drive_fetch($t['token'], $fid, $f['mimeType'], $f['name']);
        if (!$got[0]) { $sum['errors'][] = $f['name'] . ': ' . $got[1]; continue; }
        wf_doc_ingest($base, $got[2], $got[1], $got[0], (int) filesize($got[0]), $by, false, '(Google Drive · ' . $f['path'] . ')', $fid, (string) $f['modifiedTime']);
        $sum['added']++;
        if (++$n % 5 === 0) { $L = wi_read('learning.json', []); $L[$base]['import'] = ['folder' => $fname, 'running' => true, 'done' => $n, 'total' => count($files), 'at' => date('c')]; wi_write('learning.json', $L); }
    }
    $L = wi_read('learning.json', []); $L[$base]['import'] = ['folder' => $fname, 'running' => false, 'at' => date('c')] + $sum; wi_write('learning.json', $L);
    return ['ok' => true] + $sum;
}
