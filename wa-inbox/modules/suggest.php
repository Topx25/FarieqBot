<?php
// WhatsApp Inbox — suggest: one search box for everything (auto-complete / auto-suggest).
// Chats and numbers, cases, knowledge, library files, commands and message text, each with a match score (0-100), highest first.

/** How well the typed text matches a value (0-100): exact, starts with, a word starts with, contains, every word found, digits, or close spelling. */
function wf_sg_score(string $q, string $text): int {
    $n = fn(string $s) => trim(preg_replace('/\s+/u', ' ', wf_intake_norm(preg_replace('/[^\p{L}\p{N}#\s+]/u', ' ', $s))));
    $q0 = $n($q); $t = $n($text); if ($q0 === '' || $t === '') return 0;
    $qd = preg_replace('/\D/', '', $q); $td = preg_replace('/\D/', '', $text);
    if (strlen($qd) >= 3 && strlen($qd) >= mb_strlen(preg_replace('/\s+/u', '', $q)) - 1 && $td !== '') { // a number was typed
        $qd = ltrim($qd, '0'); if ($qd === '') return 0;
        if ($td === $qd || str_ends_with($td, $qd) && strlen($qd) >= 9) return 100;
        if (str_contains($td, $qd)) return 90 - min(20, strlen($td) - strlen($qd)); }
    if ($t === $q0) return 100;
    if (str_starts_with($t, $q0)) return 94;
    if (preg_match('/(^|\s)' . preg_quote($q0, '/') . '/u', $t)) return 86;
    if (mb_strpos($t, $q0) !== false) return 76;
    $words = array_filter(explode(' ', $q0), fn($w) => mb_strlen($w) >= 2);
    if (count($words) > 1) { $hit = 0; foreach ($words as $w) if (mb_strpos($t, $w) !== false) $hit++; if ($hit === count($words)) return 70; if ($hit) return (int) (40 + 25 * $hit / count($words)); }
    if (mb_strlen($q0) >= 3) { $best = 0; foreach (explode(' ', $t) as $w) { if (mb_strlen($w) < 3) continue; similar_text($q0, $w, $p); $best = max($best, $p); } // a small spelling slip
        if ($best >= 70) return (int) round($best * 0.65); }
    return 0;
}
/** The suggestions for a typed text: [{type, icon, label, sub, url, score}], highest score first. */
function wf_suggest(array $acc, string $q, int $max = 25): array {
    $aid = (string) $acc['id']; $out = []; $can = fn(string $p) => !function_exists('wi_page_perm') || wi_page_perm($p) !== '';
    $add = function (string $type, string $icon, string $label, string $sub, string $url, int $score) use (&$out) { if ($score >= 35) $out[] = compact('type', 'icon', 'label', 'sub', 'url', 'score'); };
    // the chat list is kept for 2 minutes: typing must not make the gateway read its whole archive at every key (that memory peak got it stopped by the host)
    $cf = WI_DIR . '/suggest_chats_' . preg_replace('/\W/', '', $aid) . '.json'; $chats = (is_file($cf) && filemtime($cf) > time() - 120) ? (array) json_decode((string) file_get_contents($cf), true) : null;
    if ($chats === null && $can('inbox')) { $chats = array_map(fn($c) => array_intersect_key($c, array_flip(['jid', 'name', 'phone', 'group'])), (array) (wi_gateway('/archive/chats?months=' . (int) wi_sys('months_recent'), null, $acc)['chats'] ?? [])); @file_put_contents($cf, json_encode($chats)); @chmod($cf, 0600); }
    if ($can('inbox')) foreach ((array) $chats as $c) {
        $nm = (string) ($c['name'] ?? ''); $ph = (string) ($c['phone'] ?? ''); $s = max(wf_sg_score($q, $nm), $ph !== '' ? wf_sg_score($q, $ph) : 0);
        $add(!empty($c['group']) ? 'group' : 'chat', !empty($c['group']) ? '👥' : '💬', $nm !== '' ? $nm : ($ph !== '' ? '+' . $ph : (string) $c['jid']), $ph !== '' ? '+' . $ph : '', '?p=inbox#' . rawurlencode((string) $c['jid']), $s); }
    if ($can('cases') && function_exists('wf_cases')) { $one = (string) (wf_case_cfg($aid)['one'] ?? '');
        foreach (wf_cases($aid) as $ph => $c) { if (!is_array($c)) continue; $nm = (string) ($c['name'] ?? ($c['data']['name'] ?? '')); $no = (string) ($c['no'] ?? '');
            $s = max(wf_sg_score($q, $nm), wf_sg_score($q, (string) $ph), $no !== '' && ltrim($q, '#') === $no ? 100 : 0);
            $add('case', '🗂️', $nm !== '' ? $nm : '+' . $ph, trim($one . ($no !== '' ? ' · ID ' . $no : '') . ' · +' . $ph), '?p=cases&q=' . rawurlencode((string) $ph), $s); } }
    if ($can('bot') && function_exists('wf_kb')) foreach (wf_kb($aid) as $k) { if (($k['status'] ?? '') === 'disabled') continue;
        $s = max(wf_sg_score($q, (string) ($k['q'] ?? '')), (int) (wf_sg_score($q, (string) ($k['a'] ?? '')) * 0.9));
        $add('knowledge', '🧠', mb_substr((string) ($k['q'] ?? ''), 0, 90), mb_substr(preg_replace('/\s+/u', ' ', (string) ($k['a'] ?? '')), 0, 110), '?p=bot&tab=kb', $s); }
    if ($can('library')) foreach ((array) (wi_read('media_meta.json', [])[$aid] ?? []) as $key => $m) { if (!empty($m['hidden'])) continue;
        $ti = (string) ($m['title'] ?? ''); $s = max(wf_sg_score($q, $ti), wf_sg_score($q, (string) ($m['code'] ?? '')), (int) (wf_sg_score($q, (string) ($m['caption'] ?? '')) * 0.85));
        $add('file', '📎', $ti !== '' ? $ti : (string) $key, (string) ($m['code'] ?? ''), '?p=media&q=' . rawurlencode($ti !== '' ? $ti : (string) $key), $s); }
    if ($can('bot') && function_exists('wf_cmds_inventory') && (mb_strpos($q, '#') !== false || mb_strlen($q) >= 3))
        foreach (wf_cmds_inventory($acc) as $r) $add('command', '#️⃣', (string) $r['tag'], wi_t((string) $r['what']) . ($r['arg'] !== '' ? ' ' . $r['arg'] : ''), '?p=bot&tab=cmd', wf_sg_score($q, (string) $r['tag']));
    if ($can('search') && mb_strlen(trim($q)) >= 4) { $r = wi_gateway('/archive?' . http_build_query(['q' => $q, 'limit' => 20, 'months' => (int) wi_sys('months_recent')]), null, $acc);
        foreach ((array) ($r['rows'] ?? []) as $i => $m) { $tx = (string) ($m['text'] ?? ''); $s = min(80, wf_sg_score($q, $tx)) - min(15, $i); // a message text: below a name that matches as well
            $add('message', '✉️', mb_substr(preg_replace('/\s+/u', ' ', $tx), 0, 100), trim((string) ($m['name'] ?? '') . ' · ' . substr((string) ($m['time'] ?? ''), 0, 10), ' ·'), '?p=inbox#' . rawurlencode((string) ($m['jid'] ?? '')), $s); } }
    usort($out, fn($a, $b) => $b['score'] <=> $a['score'] ?: strcmp($a['label'], $b['label']));
    $seen = []; $final = []; foreach ($out as $x) { $k = $x['type'] . '|' . $x['url'] . '|' . $x['label']; if (isset($seen[$k])) continue; $seen[$k] = 1; $final[] = $x; if (count($final) >= $max) break; }
    return $final;
}
function wf_api_suggest(string $api): void {
    if ($api !== 'suggest') return;
    $acc = wi_current(); $q = trim(mb_substr((string) ($_GET['q'] ?? ''), 0, 80));
    if (!$acc || mb_strlen($q) < 2) wi_json(['ok' => true, 'items' => []]);
    wi_json(['ok' => true, 'items' => wf_suggest($acc, $q)]);
}
