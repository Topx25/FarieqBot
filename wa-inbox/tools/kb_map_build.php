<?php
// Builds a number's knowledge map of TOPICS from all its lessons (wi_learn_chunks), with the cheap model order of the AI providers page.
// Usage: php tools/kb_map_build.php <account id> [--apply] [--step=label|group|write|all] [--limit=N]
//   1) label: every kept chunk gets a short topic title (batches of 25 chunks)
//   2) group: the titles (and the map's existing concepts) are grouped into broad topics (at most ~120)
//   3) write: each topic gets an explanation, answer guidance, search forms and sample questions from its own chunks
// Without --apply nothing is written to the map: the progress file keeps every result, and the summary shows a sample.
// With --apply the topics are saved as DRAFTS (wi_concepts status 'draft' + wi_concept_chunks); the bot keeps the old map until they are switched on.
chdir(dirname(__DIR__)); require 'lib.php'; require 'features.php';
$aid = (string) ($argv[1] ?? ''); if ($aid === '' || !isset(wi_accounts()[$aid])) { fwrite(STDERR, "account id?\n"); exit(1); }
$apply = in_array('--apply', $argv, true); $step = 'all'; $limit = 0;
foreach ($argv as $a) { if (str_starts_with($a, '--step=')) $step = substr($a, 7); if (str_starts_with($a, '--limit=')) $limit = (int) substr($a, 8); }
$m = wi_db(); if (!$m) { fwrite(STDERR, "no database\n"); exit(1); }
wi_ctx_set('ai_acc', wi_accounts()[$aid]);
$pf = WI_DIR . '/kb_build_' . preg_replace('/\W/', '', $aid) . '.json';
$S = is_file($pf) ? (json_decode((string) file_get_contents($pf), true) ?: []) : []; $S += ['labels' => [], 'groups' => [], 'final' => [], 'topics' => [], 'log' => []];
$save = function () use (&$S, $pf) { file_put_contents($pf, json_encode($S, JSON_UNESCAPED_UNICODE)); };
$say = function (string $t) use (&$S, $save) { $S['log'][] = date('H:i:s') . ' ' . $t; $S['log'] = array_slice($S['log'], -60); $save(); echo $t, "\n"; };
$ai = function (string $task, string $prompt, int $timeout = 120): array { for ($i = 0; $i < 3; $i++) { $r = wi_ai($task, $prompt, $timeout, ['subject' => 'knowledge map']); if (!empty($r['ok']) && is_array($r['data'] ?? null)) return $r['data']; sleep(3); } return []; };

// the chunks to read: kept (not dropped, not judged unrelated), one copy of a file uploaded twice
$docs = []; $seenName = []; $q = $m->query("SELECT id, name FROM wi_learn_docs WHERE base='" . $m->real_escape_string($aid) . "' AND status='active' ORDER BY id");
while ($d = $q->fetch_assoc()) { $k = mb_strtolower(trim((string) $d['name'])); if (isset($seenName[$k])) continue; $seenName[$k] = 1; $docs[] = (int) $d['id']; }
$chunks = []; if ($docs) { $q = $m->query("SELECT id, text, topic FROM wi_learn_chunks WHERE doc_id IN (" . implode(',', $docs) . ") AND dropped=0 AND (verdict IS NULL OR verdict<>'unrelated') ORDER BY doc_id, idx");
    while ($c = $q->fetch_assoc()) if (mb_strlen(trim((string) $c['text'])) >= 60) $chunks[(int) $c['id']] = ['t' => (string) $c['text'], 'topic' => trim((string) $c['topic'])]; }
$say('chunks to read: ' . count($chunks) . ' from ' . count($docs) . ' files');

// ---- 1) a short title for every chunk (the old per-chunk topic is kept when it has one)
if (in_array($step, ['label', 'all'], true)) {
    foreach ($chunks as $id => $c) if ($c['topic'] !== '' && !isset($S['labels'][$id])) $S['labels'][$id] = $c['topic'];
    $todo = array_values(array_filter(array_keys($chunks), fn($id) => !isset($S['labels'][$id]))); if ($limit) $todo = array_slice($todo, 0, $limit);
    $say('label: ' . count($todo) . ' chunks need a title');
    foreach (array_chunk($todo, 25) as $n => $batch) {
        $list = ''; foreach ($batch as $i => $id) $list .= '[' . $i . '] ' . preg_replace('/\s+/u', ' ', mb_substr($chunks[$id]['t'], 0, 450)) . "\n";
        $r = $ai('Knowledge map: titles', "You label lesson excerpts for a knowledge base. For each numbered excerpt give a short topic title of 3 to 7 words, in the excerpt's own language, naming its subject (not a summary, not a question). Use \"-\" for chat noise with no teaching content.\nReturn JSON only: {\"items\":[{\"i\":0,\"t\":\"title\"}]}\n\n" . $list);
        foreach ((array) ($r['items'] ?? []) as $it) { $i = (int) ($it['i'] ?? -1); if (isset($batch[$i])) $S['labels'][$batch[$i]] = mb_substr(trim((string) ($it['t'] ?? '-')), 0, 120); }
        if ($n % 4 === 0) $say('label: batch ' . ($n + 1) . ' of ' . ceil(count($todo) / 25) . ', titles ' . count($S['labels']));
        else $save();
    }
}

// ---- 2) the titles grouped into broad topics, then the groups of every batch (and the old concepts) merged into one list
if (in_array($step, ['group', 'all'], true)) {
    $titles = []; foreach ($S['labels'] as $id => $t) { $t = trim((string) $t); if ($t === '' || $t === '-') continue; $titles[$t][] = (int) $id; }
    $names = array_keys($titles); $say('group: ' . count($names) . ' distinct titles');
    $S['groups'] = [];
    foreach (array_chunk($names, 350) as $n => $batch) {
        $list = ''; foreach ($batch as $i => $t) $list .= $i . '. ' . $t . "\n";
        $r = $ai('Knowledge map: grouping', "Group these lesson titles into broad topics a student would ask about (about 15 to 30 groups). Titles about the same subject go together even when worded differently. Name each group in the titles' language, 2 to 6 words.\nReturn JSON only: {\"groups\":[{\"name\":\"topic\",\"members\":[0,3,7]}]}\n\n" . $list, 180);
        foreach ((array) ($r['groups'] ?? []) as $g) { $ids = []; foreach ((array) ($g['members'] ?? []) as $i) if (isset($batch[(int) $i])) $ids = array_merge($ids, $titles[$batch[(int) $i]]); if ($ids) $S['groups'][] = ['name' => trim((string) $g['name']), 'chunks' => array_values(array_unique($ids))]; }
        $say('group: batch ' . ($n + 1) . ', groups so far ' . count($S['groups']));
    }
    // the map's existing concepts join the merge, so none of them is lost
    $old = []; $q = $m->query("SELECT id, name, explain_text, answer_hint, status FROM wi_concepts WHERE base='" . $m->real_escape_string($aid) . "' AND status IN ('active','suggested')"); while ($o = $q->fetch_assoc()) $old[] = $o;
    $list = ''; foreach ($S['groups'] as $i => $g) $list .= 'G' . $i . '. ' . $g['name'] . ' (' . count($g['chunks']) . ")\n"; foreach ($old as $i => $o) $list .= 'C' . $i . '. ' . $o['name'] . " (existing concept)\n";
    $r = $ai('Knowledge map: merge', "These are topic groups from different parts of the lessons (G…) and the existing concepts of the map (C…). Merge only the ones that are clearly about the SAME subject into final topics: aim for 60 to 120 final topics (never fewer than 50). Every group (G…) and every existing concept (C…) must end up in exactly one final topic. Keep distinct subjects apart: a person asking about one should not get the other. Name each final topic clearly, in the groups' language.\nReturn JSON only: {\"topics\":[{\"name\":\"final topic\",\"g\":[0,5],\"c\":[2]}]}\n\n" . $list, 240);
    $S['final'] = [];
    foreach ((array) ($r['topics'] ?? []) as $tp) { $ids = []; foreach ((array) ($tp['g'] ?? []) as $gi) if (isset($S['groups'][(int) $gi])) $ids = array_merge($ids, $S['groups'][(int) $gi]['chunks']);
        $cs = []; foreach ((array) ($tp['c'] ?? []) as $ci) if (isset($old[(int) $ci])) $cs[] = $old[(int) $ci];
        if ($ids || $cs) $S['final'][] = ['name' => trim((string) $tp['name']), 'chunks' => array_values(array_unique($ids)), 'concepts' => $cs]; }
    // too few topics means the merge lumped subjects together: then every group stays a topic of its own (same names joined)
    if (count($S['final']) < 40) { $by = []; foreach ($S['groups'] as $g) { $k = wf_kb_norm($g['name']); $by[$k] ??= ['name' => $g['name'], 'chunks' => [], 'concepts' => []]; $by[$k]['chunks'] = array_values(array_unique(array_merge($by[$k]['chunks'], $g['chunks']))); }
        foreach ($old as $o) { $by['c' . $o['id']] = ['name' => (string) $o['name'], 'chunks' => [], 'concepts' => [$o]]; } $S['final'] = array_values($by); }
    $S['topics'] = []; $say('group: final topics ' . count($S['final']));
}

// ---- 3) each topic written from its own chunks: explanation, answer guidance, search forms, sample questions
if (in_array($step, ['write', 'all'], true)) {
    foreach ($S['final'] as $n => $tp) { if (isset($S['topics'][$n])) continue; if ($limit && $n >= $limit) break;
        $txt = ''; $use = $tp['chunks']; usort($use, fn($a, $b) => mb_strlen($chunks[$b]['t'] ?? '') <=> mb_strlen($chunks[$a]['t'] ?? ''));
        foreach ($use as $id) { if (!isset($chunks[$id])) continue; $txt .= '- ' . preg_replace('/\s+/u', ' ', mb_substr($chunks[$id]['t'], 0, 900)) . "\n"; if (mb_strlen($txt) > 7000) break; }
        foreach ($tp['concepts'] as $o) $txt .= '- (existing concept) ' . $o['name'] . ': ' . $o['explain_text'] . ' / ' . $o['answer_hint'] . "\n";
        $r = $ai('Knowledge map: topic', "Write one topic of a knowledge base from these lesson excerpts, in the excerpts' language.\nTopic: " . $tp['name'] . "\n- title: a clear title\n- explain: a full explanation of the topic as the lessons teach it, 5 to 12 short lines, faithful to the excerpts, nothing invented\n- answer_hint: how to answer someone who asks about it (what to tell them, what to ask, when to send them to a person)\n- forms: 6 to 15 words or short phrases people may use to ask about it, with spelling forms (singular, plural, with and without the article, common misspellings)\n- questions: 3 short sample questions\nReturn JSON only: {\"title\":\"\",\"explain\":\"\",\"answer_hint\":\"\",\"forms\":[],\"questions\":[]}\n\nExcerpts:\n" . $txt, 180);
        if (!empty($r['title'])) $S['topics'][$n] = ['title' => mb_substr(trim((string) $r['title']), 0, 180), 'explain' => trim((string) ($r['explain'] ?? '')), 'hint' => trim((string) ($r['answer_hint'] ?? '')), 'forms' => array_values(array_filter(array_map('trim', (array) ($r['forms'] ?? [])))), 'questions' => array_values((array) ($r['questions'] ?? [])), 'chunks' => $tp['chunks']];
        if ($n % 5 === 0) $say('write: topic ' . ($n + 1) . ' of ' . count($S['final'])); else $save();
    }
    $say('write: topics written ' . count($S['topics']));
}

// ---- 4) saved as drafts (only with --apply)
if ($apply) {
    wf_kb_topics_db();
    $e = fn($v) => $m->real_escape_string((string) $v); $m->query("DELETE c, t FROM wi_concepts c LEFT JOIN wi_concept_terms t ON t.concept_id=c.id WHERE c.base='" . $e($aid) . "' AND c.status='draft'");
    $m->query("DELETE FROM wi_concept_chunks WHERE base='" . $e($aid) . "' AND concept_id NOT IN (SELECT id FROM wi_concepts)");
    // a topic whose explanation repeats an earlier one (dup_pct and above) joins it: its search forms and passages are added there, no second topic
    $dPct = (int) wf_learn_cfg($aid)['dup_pct']; $keep = []; foreach ($S['topics'] as $tp) { $mh = wf_kb_minhash($tp['title'] . ' ' . $tp['explain']); $hit = null;
        foreach ($keep as $k => $o) if (wf_kb_mh_pct($mh, $o['mh']) >= $dPct) { $hit = $k; break; }
        if ($hit === null) { $keep[] = $tp + ['mh' => $mh]; continue; }
        $keep[$hit]['forms'] = array_values(array_unique(array_merge($keep[$hit]['forms'], [$tp['title']], $tp['forms']))); $keep[$hit]['chunks'] = array_values(array_unique(array_merge($keep[$hit]['chunks'], $tp['chunks']))); }
    $say('apply: ' . (count($S['topics']) - count($keep)) . ' repeated topics joined their original');
    $saved = 0; foreach ($keep as $tp) {
        $m->query("INSERT INTO wi_concepts (base, name, explain_text, answer_hint, status, created_by, created_at) VALUES ('" . $e($aid) . "','" . $e($tp['title']) . "','" . $e($tp['explain']) . "','" . $e($tp['hint']) . "','draft','kb_map_build',NOW())"); $cid = (int) $m->insert_id; if (!$cid) continue; $saved++;
        foreach (array_unique(array_merge([$tp['title']], $tp['forms'])) as $f) { $f = mb_substr(trim((string) $f), 0, 180); if ($f === '') continue; $m->query("INSERT INTO wi_concept_terms (concept_id, term, norm, relation) VALUES ($cid,'" . $e($f) . "','" . $e(wf_kb_norm($f)) . "','form')"); }
        foreach ((array) $tp['questions'] as $qq) { $qq = mb_substr(trim((string) $qq), 0, 180); if ($qq !== '') $m->query("INSERT INTO wi_concept_terms (concept_id, term, norm, relation) VALUES ($cid,'" . $e($qq) . "','" . $e(wf_kb_norm($qq)) . "','question')"); }
        foreach (array_slice($tp['chunks'], 0, 400) as $ch) $m->query("INSERT IGNORE INTO wi_concept_chunks (base, concept_id, chunk_id) VALUES ('" . $e($aid) . "',$cid," . (int) $ch . ")");
    }
    $say('apply: ' . $saved . ' draft topics saved');
}
// a short sample for the owner
echo "\n== sample ==\n"; foreach (array_slice($S['topics'], 0, 5, true) as $tp) echo '• ', $tp['title'], ' (', count($tp['chunks']), " excerpts)\n  ", mb_substr(str_replace("\n", ' ', $tp['explain']), 0, 220), "\n  forms: ", implode(', ', array_slice($tp['forms'], 0, 8)), "\n";
