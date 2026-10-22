<?php
// WhatsApp Inbox — recommend: "💡 Suggestions" in a chat or a case — which written text, program or recording fits this person.
// Few tokens: the candidates are ranked with no AI (the person's words, what the bot learned, how often each one was sent),
// then a fast model only orders the best dozen and says why in one line.

/** How often each item was sent through the suggestions or its tag: [code => count]. */
function wf_rec_key(string $code): string { return str_replace(['-', ' ', '_'], '', wf_intake_norm(wf_media_code_clean($code))); }
function wf_rec_usage(string $aid): array { return (array) (wi_read('rec_usage.json', [])[$aid] ?? []); }
function wf_rec_used(string $aid, string $code): void { $code = wf_rec_key($code); if ($code === '') return; wi_update('rec_usage.json', function ($U) use ($aid, $code) { $U[$aid][$code] = (int) ($U[$aid][$code] ?? 0) + 1; return $U; }); }
/** Everything that can be suggested: written texts, programs and library files that have a tag. */
function wf_rec_items(string $aid): array {
    $L = [];
    foreach (wf_lib_texts($aid) as $t) if (trim((string) ($t['code'] ?? '')) !== '') $L[] = ['kind' => 'text', 'icon' => '📜', 'code' => (string) $t['code'], 'title' => (string) ($t['title'] ?? ''), 'about' => trim(($t['topic'] ?? '') . ' ' . str_replace('_', ' ', (string) ($t['keywords'] ?? '')) . ' ' . ($t['section'] ?? '')), 'body' => mb_substr((string) ($t['text'] ?? ''), 0, 400)];
    foreach (wf_programs($aid) as $p) if (trim((string) ($p['code'] ?? '')) !== '') $L[] = ['kind' => 'program', 'icon' => '🗓️', 'code' => (string) $p['code'], 'title' => (string) ($p['name'] ?? ''), 'about' => (string) ($p['desc'] ?? ''), 'body' => ''];
    foreach ((array) (wi_read('media_meta.json', [])[$aid] ?? []) as $m) if (trim((string) ($m['code'] ?? '')) !== '' && empty($m['hidden'])) $L[] = ['kind' => 'file', 'icon' => '🎧', 'code' => (string) $m['code'], 'title' => (string) ($m['title'] ?? ''), 'about' => trim((string) ($m['caption'] ?? '') . ' ' . implode(' ', (array) ($m['tags'] ?? []))), 'body' => ''];
    return $L;
}
/** The words that matter in a text (normalised, 3+ letters). */
function wf_rec_words(string $t): array { $o = []; foreach (preg_split('/\s+/u', wf_intake_norm(preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $t))) as $w) if (mb_strlen($w) >= 3) $o[$w] = 1; return $o; }
/** Suggestions for a chat (and its case): [{kind, icon, code, title, reason, score, uses}], best first. With $q empty and no messages: the most used. */
function wf_recommend(array $acc, string $jid, bool $ai = true, string $extra = ''): array {
    $aid = (string) $acc['id']; $items = wf_rec_items($aid); if (!$items) return [];
    $use = wf_rec_usage($aid); $ctx = $extra; // more of the person's words (e.g. their group messages)
    if ($jid !== '') { $rows = (array) (wi_gateway('/archive/thread?limit=60&jid=' . rawurlencode($jid), null, $acc)['rows'] ?? []);
        foreach (array_slice(array_values(array_filter($rows, fn($m) => ($m['dir'] ?? '') === 'in' && trim((string) ($m['text'] ?? '')) !== '')), -15) as $m) $ctx .= ' ' . $m['text'];
        $ph = preg_replace('/\D/', '', explode('@', $jid)[0]); if (function_exists('wf_case_get') && ($c = wf_case_get($aid, $ph))) foreach (['symptoms', 'complaint', 'story', 'diagnosis', 'notes'] as $k) $ctx .= ' ' . (string) ($c['data'][$k] ?? ''); }
    $cw = wf_rec_words($ctx);
    // what the bot learned: answers that name an item, for questions alike this person's words
    $kbHit = []; if ($cw) foreach (wf_kb($aid) as $k) { if (($k['status'] ?? '') === 'disabled') continue; $qw = wf_rec_words((string) ($k['q'] ?? '')); if (!$qw || count(array_intersect_key($qw, $cw)) < 2) continue;
        $a = wf_intake_norm((string) ($k['a'] ?? '')); foreach ($items as $i => $it) if (($t = wf_intake_norm($it['title'])) !== '' && mb_strpos($a, $t) !== false || mb_strpos($a, wf_intake_norm($it['code'])) !== false) $kbHit[$i] = ($kbHit[$i] ?? 0) + 1; }
    foreach ($items as $i => &$it) { $iw = wf_rec_words($it['title'] . ' ' . $it['about'] . ' ' . $it['body']); $hit = $cw ? count(array_intersect_key($iw, $cw)) : 0; $u = (int) ($use[wf_rec_key($it['code'])] ?? 0);
        $it['uses'] = $u; $it['score'] = min(99, ($hit ? 35 + min(40, 10 * $hit) : 0) + min(20, 8 * ($kbHit[$i] ?? 0)) + ($u >= 2 ? min(15, 3 * $u) : 0));
        $it['reason'] = $hit ? 'matches the person\'s words' : (($kbHit[$i] ?? 0) ? 'the team used it for alike cases' : ($u >= 2 ? 'often sent' : '')); }
    unset($it);
    $items = array_values(array_filter($items, fn($x) => $x['score'] > 0)); usort($items, fn($a, $b) => $b['score'] <=> $a['score']);
    $top = array_slice($items, 0, 12);
    if ($ai && $top && trim($ctx) !== '') { // a fast model orders the dozen and says why (in the business language)
        $list = ''; foreach ($top as $i => $x) $list .= $i . ') ' . $x['title'] . ' — ' . mb_substr($x['about'], 0, 120) . "\n";
        $r = wi_ai('Suggestions', wf_prompt('rec_pick', $aid, ['person' => mb_substr(trim($ctx), 0, 1500), 'items' => $list]), 60, ['subject' => 'suggestions', 'prefer' => function_exists('wf_fast_model') ? wf_fast_model(true) : '']);
        $pick = (array) ($r['data']['picks'] ?? []); if ($pick) { $out = [];
            foreach ($pick as $p) { $i = (int) ($p['n'] ?? -1); if (!isset($top[$i])) continue; $x = $top[$i]; $x['score'] = max(1, min(99, (int) ($p['confidence'] ?? 0))) ?: $x['score']; $x['reason'] = mb_substr(trim((string) ($p['why'] ?? '')), 0, 160) ?: $x['reason']; $out[$i] = $x; }
            foreach ($top as $i => $x) if (!isset($out[$i])) { $x['score'] = (int) round($x['score'] * 0.6); $out[$i] = $x; }
            $top = array_values($out); usort($top, fn($a, $b) => $b['score'] <=> $a['score']); } }
    return array_map(fn($x) => array_intersect_key($x, array_flip(['kind', 'icon', 'code', 'title', 'reason', 'score', 'uses'])), array_slice($top, 0, 8));
}
/** The most used items (sent 2 times or more), most used first. */
function wf_rec_popular(string $aid): array {
    $use = wf_rec_usage($aid); $out = [];
    foreach (wf_rec_items($aid) as $it) { $u = (int) ($use[wf_rec_key($it['code'])] ?? 0); if ($u >= 2) $out[] = ['kind' => $it['kind'], 'icon' => $it['icon'], 'code' => $it['code'], 'title' => $it['title'], 'reason' => 'often sent', 'score' => 0, 'uses' => $u]; }
    usort($out, fn($a, $b) => $b['uses'] <=> $a['uses']); return array_slice($out, 0, 10);
}
function wf_api_recommend(string $api): void {
    if (!in_array($api, ['recommend', 'recommend_send'], true)) return;
    $acc = wi_current(); if (!$acc) wi_json(['ok' => false, 'error' => 'No number chosen.'], 422); $aid = (string) $acc['id'];
    $jid = (string) ($_GET['jid'] ?? ''); if ($jid === '' && ($ph = preg_replace('/\D/', '', (string) ($_GET['phone'] ?? ''))) !== '') $jid = $ph . '@s.whatsapp.net';
    if ($api === 'recommend') { @set_time_limit(90); wi_json(['ok' => true, 'items' => $jid !== '' ? wf_recommend($acc, wf_jid($jid), empty($_GET['fast'])) : [], 'popular' => wf_rec_popular($aid)]); }
    $in = wf_post(); $jid = wf_jid((string) ($in['jid'] ?? '')); $code = trim((string) ($in['code'] ?? ''));
    if ($jid === '' || $code === '' || !wi_can_reply()) wi_json(['ok' => false, 'error' => 'Not allowed.'], 403);
    $ok = wf_media_code_send($acc, $jid, $code); wi_log('recommend_send', ['account' => $aid, 'jid' => $jid, 'code' => $code]);
    wi_json(['ok' => $ok]);
}
