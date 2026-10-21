<?php
// WhatsApp Inbox — learn_lean: learning from the chosen groups with few tokens.
// 1) collect with no AI (the archive's question → team answer exchanges), 2) clean and group alike questions with no AI,
// 3) a free model shortens each exchange into one line (the archive), 4) a strong model turns the short lines into knowledge.

/** The settings of a number (Bot → Learning → Learn from groups, saving tokens). */
function wf_lean_cfg(string $aid): array {
    return (array) (wi_read('bot.json', [])[$aid]['lean'] ?? []) + ['on' => (string) (wf_pack($aid)['learn_group_words'] ?? '') !== '', 'words' => (string) (wf_pack($aid)['learn_group_words'] ?? ''),
        'skip' => [], 'free_model' => '', 'model' => '', 'calls_day' => 6, 'free_calls_day' => 48, 'batch' => 40, 'min_answer' => 15, 'same' => 0.6];
}
/** The groups learned from: names with one of the words (or marked "learn" in Bot → Groups), never one marked off or chosen to skip. */
function wf_lean_groups(array $acc): array {
    $aid = (string) $acc['id']; $L = wf_lean_cfg($aid); $rc = wf_bot_run_cfg($aid); $out = [];
    $words = array_values(array_filter(array_map(fn($w) => wf_intake_norm(trim($w)), preg_split('/[,\x{060C}|\n]+/u', (string) $L['words']))));
    foreach ((array) (wi_gateway('/archive/chats?months=' . (int) wi_sys('months_long'), null, $acc)['chats'] ?? []) as $ch) { if (empty($ch['group'])) continue;
        $j = (string) $ch['jid']; $gc = (array) ($rc['groups'][$j] ?? []); $nm = wf_intake_norm((string) ($ch['name'] ?? ''));
        if (in_array($j, (array) $L['skip'], true) || (array_key_exists('learn', $gc) && empty($gc['learn']) && isset($rc['groups'][$j]))) continue; // switched off for learning
        $hit = !empty($gc['learn']); foreach ($words as $w) if ($w !== '' && mb_strpos($nm, $w) !== false) $hit = true;
        if ($hit) $out[$j] = ['name' => (string) ($ch['name'] ?? '')]; }
    return $out;
}
/** A free model (a free plan without a small daily limit), '' when none is set up. */
function wf_free_model(string $aid = ''): string {
    if ($aid !== '' && ($m = trim((string) wf_lean_cfg($aid)['free_model'])) !== '') return $m;
    static $memo = null; if ($memo !== null) return $memo; $memo = '';
    try { require_once __DIR__ . '/lib/WiAiProviders.php'; $P = new WiAiProviders(wi_ai_scope());
        foreach ($P->active_providers() as $id => $p) { if (empty($p['health']['ok']) || ($p['plan'] ?? 'free') === 'paid' || !empty($p['free_daily_small'])) continue;
            $ch = $P->model_chain($p, false); if ($ch) { $memo = $id . ':' . $ch[0]; break; } } } catch (Throwable $e) {}
    return $memo;
}
/** Today's AI calls of this learning (the daily cap). */
function wf_lean_calls(string $aid, int $add = 0, string $kind = 'calls'): int {
    $n = 0; wi_update('learn_lean.json', function ($S) use ($aid, $add, $kind, &$n) { $d = date('Y-m-d'); if (($S[$aid]['day'] ?? '') !== $d) $S[$aid] = ['day' => $d, 'calls' => 0, 'free_calls' => 0] + (array) ($S[$aid] ?? []);
        $S[$aid][$kind] = (int) ($S[$aid][$kind] ?? 0) + $add; $n = $S[$aid][$kind]; return $S; });
    return $n;
}
/** Step 1 + 2, no AI: the groups' exchanges are collected, weak ones dropped, alike questions grouped (one kept, the others counted). */
function wf_lean_collect(array $acc): array {
    $aid = (string) $acc['id']; $L = wf_lean_cfg($aid); $lg = wf_lean_groups($acc); if (!$lg) return ['ok' => true, 'groups' => 0, 'new' => 0];
    $meta = (array) (wi_read('learn_lean.json', [])[$aid] ?? []); $since = (string) ($meta['to'] ?? '');
    $team = implode(',', array_keys(wf_team($acc, $lg)));
    $r = wi_gateway('/archive/pairs?months=' . (int) wi_sys('months_long') . ($since !== '' ? '&since=' . rawurlencode(gmdate('Y-m-d\TH:i:s', strtotime($since) - 6 * 3600)) : '')
        . '&groups=' . rawurlencode(implode(',', array_keys($lg))) . '&team=' . rawurlencode($team), null, $acc);
    if (empty($r['ok'])) return ['ok' => false, 'error' => $r['error'] ?? 'Gateway error.'];
    $pairs = wf_pairs($aid); $kbQ = array_map(fn($k) => (string) ($k['q'] ?? ''), wf_kb($aid)); $new = 0; $drop = 0; $to = $since;
    $todo = array_values(array_filter($pairs, fn($p) => empty($p['done']) && !empty($p['group'])));
    foreach ((array) ($r['pairs'] ?? []) as $p) { if (empty($p['group'])) continue; if ((string) $p['a_time'] > $to) $to = (string) $p['a_time'];
        $q = trim((string) $p['q']); $a = trim((string) $p['a']);
        $id = substr(md5(wf_norm($q) . '|' . wf_norm(mb_substr($a, 0, 300))), 0, 12); if (isset($pairs[$id])) continue;
        $row = ['id' => $id, 'q' => $q, 'a' => $a, 'time' => $p['a_time'], 'group' => $p['jid'], 'done' => false, 'role' => wf_learn_role($aid, $q), 'n' => 1];
        // weak: a greeting, a short or emoji answer, a tag or a link alone — dropped with no AI
        $plain = trim(preg_replace(['~https?://\S+~u', '/#\S+/u', '/[^\p{L}\p{N}\s]/u'], '', $a));
        if (count(wf_words($q)) < 2 || mb_strlen($plain) < (int) $L['min_answer']) { $row['done'] = 'weak'; $drop++; }
        // already known: alike a question the bot has learned
        if (!$row['done']) foreach ($kbQ as $kq) if (wf_text_sim($q, $kq) >= 0.75) { $row['done'] = 'known'; $drop++; break; }
        // alike a question waiting to be learned: counted on it (the longer answer is kept)
        if (!$row['done']) foreach ($todo as &$t) if (wf_text_sim($q, (string) $t['q']) >= (float) $L['same']) { $pairs[$t['id']]['n'] = (int) ($pairs[$t['id']]['n'] ?? 1) + 1;
            if (mb_strlen($a) > mb_strlen((string) $pairs[$t['id']]['a'])) { $pairs[$t['id']]['a'] = $a; unset($pairs[$t['id']]['c']); } $row['done'] = 'same'; $drop++; break; }
        unset($t);
        $pairs[$id] = $row; if (!$row['done']) { $todo[] = $row; $new++; } }
    wi_write(basename(wf_pairs_file($aid)), $pairs);
    wi_update('learn_lean.json', function ($S) use ($aid, $to) { $S[$aid]['to'] = $to ?: date('c'); $S[$aid]['collected'] = date('c'); return $S; });
    return ['ok' => true, 'groups' => count($lg), 'new' => $new, 'dropped' => $drop, 'waiting' => count($todo)];
}
/** Step 3, a free model: the waiting exchanges are shortened into one line each (kept in 'c'); with no free model, shortened by cutting. */
function wf_lean_archive(array $acc): array {
    $aid = (string) $acc['id']; $L = wf_lean_cfg($aid); $pairs = wf_pairs($aid);
    $todo = array_slice(array_values(array_filter($pairs, fn($p) => empty($p['done']) && !empty($p['group']) && !isset($p['c']))), 0, 30); if (!$todo) return ['ok' => true, 'done' => 0];
    $free = wf_free_model($aid); $lines = [];
    if ($free !== '' && wf_lean_calls($aid, 0, 'free_calls') < (int) $L['free_calls_day']) { // the free model has its own cap; only the strong model's calls cost
        $txt = ''; foreach ($todo as $p) $txt .= '### ' . $p['id'] . "\nQ: " . mb_substr(preg_replace('/\s+/u', ' ', (string) $p['q']), 0, 500) . "\nA: " . mb_substr(preg_replace('/\s+/u', ' ', (string) $p['a']), 0, 900) . "\n";
        $ai = wi_ai('Bot learning (archive)', wf_prompt('kb_compress', $aid, ['exchanges' => $txt]), 120, ['subject' => count($todo) . ' exchanges', 'prefer' => $free]); wf_lean_calls($aid, 1, 'free_calls');
        foreach ((array) ($ai['data']['items'] ?? []) as $x) if (is_array($x) && isset($x['id'])) $lines[(string) $x['id']] = trim((string) ($x['line'] ?? ''));
    }
    foreach ($todo as $p) { $l = $lines[$p['id']] ?? ''; if ($l === '-') { $pairs[$p['id']]['done'] = 'weak'; continue; } // the free model saw nothing worth keeping
        $pairs[$p['id']]['c'] = $l !== '' ? mb_substr($l, 0, 400) : mb_substr(preg_replace('/\s+/u', ' ', (string) $p['q']), 0, 160) . ' → ' . mb_substr(preg_replace('/\s+/u', ' ', (string) $p['a']), 0, 240); }
    wi_write(basename(wf_pairs_file($aid)), $pairs);
    return ['ok' => true, 'done' => count($todo), 'free' => $free !== '' && $lines !== []];
}
/** Step 4, a strong model on the short lines: knowledge items (pending review), most asked first. */
function wf_lean_analyse(array $acc): array {
    $aid = (string) $acc['id']; $L = wf_lean_cfg($aid); $pairs = wf_pairs($aid);
    $todo = array_values(array_filter($pairs, fn($p) => empty($p['done']) && !empty($p['group']) && isset($p['c'])));
    if (count($todo) < min(10, (int) $L['batch']) && array_filter($pairs, fn($p) => empty($p['done']) && !empty($p['group']) && !isset($p['c']))) return ['ok' => true, 'done' => 0, 'wait' => true]; // a fuller batch first
    if (!$todo) return ['ok' => true, 'done' => 0];
    if (wf_lean_calls($aid) >= (int) $L['calls_day']) return ['ok' => true, 'done' => 0, 'cap' => true];
    usort($todo, fn($a, $b) => ((int) ($b['n'] ?? 1)) <=> ((int) ($a['n'] ?? 1)));
    $batch = array_slice($todo, 0, max(5, min(80, (int) $L['batch']))); $txt = '';
    foreach ($batch as $p) $txt .= '### ' . $p['id'] . ((int) ($p['n'] ?? 1) > 1 ? ' (asked ' . (int) $p['n'] . ' times)' : '') . "\n" . $p['c'] . "\n";
    $kb = wf_kb($aid); $topics = implode(', ', array_slice(array_unique(array_column($kb, 'topic')), 0, 40));
    $ai = wi_ai('Bot learning', wf_prompt('kb_extract', $aid, ['topics' => $topics, 'exchanges' => $txt]), 220, ['subject' => count($batch) . ' short lines', 'prefer' => trim((string) $L['model']) ?: wf_fast_model(true)]); wf_lean_calls($aid, 1);
    $items = is_array($ai['data']['items'] ?? null) ? $ai['data']['items'] : (is_array($ai['data'] ?? null) && array_is_list($ai['data']) ? $ai['data'] : null);
    if (empty($ai['ok']) || $items === null) return ['ok' => false, 'error' => $ai['error'] ?? 'AI returned no result.'];
    $all = wi_read('learn_kb.json', []); $kbA = $all[$aid] ?? []; $n = 0;
    foreach ($items as $x) { if (!is_array($x)) continue; $q = trim((string) ($x['q'] ?? '')); $a = trim((string) ($x['a'] ?? '')); if ($q === '' || $a === '') continue;
        $id = substr(md5(wf_norm($q)), 0, 12); if (isset($kbA[$id])) { $kbA[$id]['sources'] = ($kbA[$id]['sources'] ?? 1) + 1; continue; }
        $kbA[$id] = ['id' => $id, 'topic' => mb_substr(trim((string) ($x['topic'] ?? '')), 0, 50), 'q' => mb_substr($q, 0, 500), 'a' => mb_substr($a, 0, 3000), 'type' => (string) ($x['type'] ?? 'faq'), 'sources' => max(1, count((array) ($x['from'] ?? []))), 'status' => 'pending', 'at' => date('c'), 'via' => 'groups'];
        $n++; }
    $all[$aid] = $kbA; wi_write('learn_kb.json', $all);
    foreach ($batch as $p) $pairs[$p['id']]['done'] = true; wi_write(basename(wf_pairs_file($aid)), $pairs);
    return ['ok' => true, 'done' => $n, 'read' => count($batch), 'task' => $ai['task'] ?? []];
}
/** One run (every hour from the minute job): collect, shorten one batch, analyse one batch — within the daily cap. */
function wf_lean_run(array $acc): array {
    $aid = (string) $acc['id']; if (empty(wf_lean_cfg($aid)['on'])) return ['ok' => true, 'off' => true];
    $c = wf_lean_collect($acc); if (empty($c['ok'])) return $c;
    $a = wf_lean_archive($acc); $z = wf_lean_analyse($acc);
    $out = ['ok' => true, 'collect' => $c, 'archive' => $a, 'analyse' => $z, 'at' => date('c'), 'calls_today' => wf_lean_calls($aid)];
    wi_update('learn_lean.json', function ($S) use ($aid, $out) { $S[$aid]['last'] = $out; return $S; });
    return $out;
}

function wf_api_learn_lean(string $api): void {
    if ($api !== 'learn_lean') return;
    $acc = wi_current(); if (!$acc) wi_json(['ok' => false, 'error' => 'No number chosen.'], 422); $aid = (string) $acc['id'];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') { $in = wf_post(); wf_admin();
        if (($in['do'] ?? '') === 'run') { @set_time_limit(600); wi_json(wf_lean_run($acc)); }
        $x = ['on' => !empty($in['on']), 'words' => mb_substr(trim((string) ($in['words'] ?? '')), 0, 500), 'skip' => array_values(array_filter((array) ($in['skip'] ?? []), fn($j) => preg_match('/^[0-9-]{6,40}@g\.us$/', (string) $j))),
            'free_model' => mb_substr(trim((string) ($in['free_model'] ?? '')), 0, 120), 'model' => mb_substr(trim((string) ($in['model'] ?? '')), 0, 120),
            'calls_day' => max(1, min(200, (int) ($in['calls_day'] ?? 6))), 'free_calls_day' => max(0, min(500, (int) ($in['free_calls_day'] ?? 48))), 'batch' => max(5, min(80, (int) ($in['batch'] ?? 40)))];
        wi_update('bot.json', function ($B) use ($aid, $x) { $B[$aid]['lean'] = $x + (array) ($B[$aid]['lean'] ?? []); return $B; }); wi_log('learn_lean_save', ['account' => $aid]); }
    $pairs = wf_pairs($aid); $g = array_filter($pairs, fn($p) => !empty($p['group']));
    wi_json(['ok' => true, 'cfg' => wf_lean_cfg($aid), 'groups' => wf_lean_groups($acc), 'free_now' => wf_free_model($aid), 'model_now' => trim((string) wf_lean_cfg($aid)['model']) ?: wf_fast_model(true),
        'state' => (array) (wi_read('learn_lean.json', [])[$aid] ?? []), 'counts' => ['all' => count($g), 'waiting' => count(array_filter($g, fn($p) => empty($p['done']))), 'learned' => count(array_filter($g, fn($p) => ($p['done'] ?? false) === true)), 'saved' => count(array_filter($g, fn($p) => is_string($p['done'] ?? null)))]]);
}
