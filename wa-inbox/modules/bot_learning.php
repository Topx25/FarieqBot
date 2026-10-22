<?php
// WhatsApp Inbox — bot_learning (split out of features.php in v3.8, code unchanged)

/* ---------- Bot learning: knowledge from past conversations + library; tested before the bot is switched on ---------- */
/** How alike two questions are (shared meaningful words / all words). */
function wf_sim(string $a, string $b): float {
    $x = array_flip(wf_words($a)); $y = array_flip(wf_words($b));
    if (!$x || !$y) return 0.0;
    return count(array_intersect_key($x, $y)) / max(1, count($x + $y));
}
/** Letter-level likeness (ignores spaces, so "ما غسلتهاش" = "ماغسلتهاش"): trigram Dice score 0..1. */
function wf_csim(string $a, string $b): float {
    $g = function ($t) { $t = str_replace(' ', '', wf_norm(mb_substr($t, 0, 600))); $o = []; $n = mb_strlen($t); for ($i = 0; $i < $n - 2; $i++) $o[mb_substr($t, $i, 3)] = 1; return $o; };
    $x = $g($a); $y = $g($b); if (!$x || !$y) return 0.0;
    return 2 * count(array_intersect_key($x, $y)) / (count($x) + count($y));
}
/** Merges near-identical knowledge items (same question in other words). Returns how many were merged. */
function wf_kb_merge(array &$kb, float $min = 0.55): int {
    $ids = array_keys($kb); $n = 0;
    usort($ids, fn($a, $b) => (($kb[$b]['status'] ?? '') === 'approved') <=> (($kb[$a]['status'] ?? '') === 'approved') ?: (($kb[$b]['sources'] ?? 0) <=> ($kb[$a]['sources'] ?? 0)));
    foreach ($ids as $i => $keep) {
        if (!isset($kb[$keep])) continue;
        foreach (array_slice($ids, $i + 1) as $dup) {
            if (!isset($kb[$dup]) || $dup === $keep) continue;
            $s = wf_sim($kb[$keep]['q'], $kb[$dup]['q']);
            $sameTopic = ($kb[$keep]['topic'] ?? '') !== '' && ($kb[$keep]['topic'] ?? '') === ($kb[$dup]['topic'] ?? '');
            $as = max(wf_sim($kb[$keep]['a'], $kb[$dup]['a']), wf_csim($kb[$keep]['a'], $kb[$dup]['a']));
            // Same question, or the same answer to a question asked in other words / another dialect.
            $s = max($s, wf_csim($kb[$keep]['q'], $kb[$dup]['q']) - 0.1);
            if (!($s >= $min || ($as >= 0.6 && $s >= 0.15) || ($sameTopic && $as >= 0.5 && $s >= 0.2))) continue;
            $kb[$keep]['sources'] = ($kb[$keep]['sources'] ?? 1) + ($kb[$dup]['sources'] ?? 1);
            if (empty($kb[$keep]['edited']) && ($kb[$keep]['status'] ?? '') !== 'approved' && mb_strlen($kb[$dup]['a']) > mb_strlen($kb[$keep]['a'])) { $kb[$keep]['alt'] = $kb[$keep]['a']; $kb[$keep]['a'] = $kb[$dup]['a']; }
            elseif (empty($kb[$keep]['alt']) && $kb[$dup]['a'] !== $kb[$keep]['a']) $kb[$keep]['alt'] = $kb[$dup]['a'];
            $kb[$keep]['merged'] = ($kb[$keep]['merged'] ?? 0) + 1 + ($kb[$dup]['merged'] ?? 0);
            unset($kb[$dup]); $n++;
        }
    }
    return $n;
}
/** Auto-approve: answers the team gave at least N times (0 = off). */
function wf_kb_auto(array &$kb, int $min): int {
    if ($min < 1) return 0; $n = 0;
    foreach ($kb as &$k) if (($k['status'] ?? 'pending') === 'pending' && ($k['sources'] ?? 1) >= $min) { $k['status'] = 'approved'; $k['auto'] = date('c'); $n++; }
    unset($k); return $n;
}
function wf_kb(string $aid): array { return wi_read('learn_kb.json', [])[$aid] ?? []; }
function wf_pairs_file(string $aid): string { return WI_DIR . '/learn_pairs_' . preg_replace('/\W/', '', $aid) . '.json'; }
function wf_pairs(string $aid): array { return wi_read(basename(wf_pairs_file($aid)), []); }
/** Best knowledge items and library texts for a message (word overlap; approved items rank higher). */
function wf_retrieve(array $acc, string $text, int $n = 6): array {
    $want = array_flip(wf_words($text)); if (!$want) return [];
    $out = [];
    foreach (wf_kb((string) $acc['id']) as $k) {
        if (($k['status'] ?? '') !== 'approved') continue; // only what was approved (by the team, or repeated by several healers) speaks for the team
        $hit = count(array_intersect_key(array_flip(wf_words($k['q'] . ' ' . ($k['topic'] ?? ''))), $want));
        if ($hit) $out[] = ['score' => $hit * (($k['status'] ?? '') === 'approved' ? 1.5 : 1) + min(3, ($k['sources'] ?? 1) / 5), 'kind' => 'knowledge', 'id' => $k['id'], 'title' => $k['q'], 'text' => $k['a'], 'topic' => $k['topic'] ?? '', 'status' => $k['status'] ?? 'pending'];
    }
    $cf = WI_DIR . '/cache_lib_' . preg_replace('/\W/', '', (string) $acc['id']) . '.json';
    $lib = (is_file($cf) && filemtime($cf) > time() - 600) ? (json_decode((string) file_get_contents($cf), true) ?: []) : [];
    if (!$lib) { $lib = wi_gateway('/library?' . http_build_query(['type' => 'sections', 'group' => '1', 'min' => 60]), null, $acc); if (!empty($lib['ok'])) { file_put_contents($cf, json_encode(['groups' => array_values(array_filter((array) ($lib['groups'] ?? []), fn($g) => empty($g['audio'])))], JSON_UNESCAPED_UNICODE)); @chmod($cf, 0600); } } // library cached 10 min
    foreach ($lib['groups'] ?? [] as $g) {
        if (!empty($g['audio'])) continue;
        $hit = count(array_intersect_key(array_flip(wf_words(($g['title'] ?? '') . ' ' . mb_substr((string) $g['best']['text'], 0, 1200))), $want));
        if ($hit >= 2) $out[] = ['score' => $hit * 0.8, 'kind' => 'library', 'id' => $g['best']['key'] ?? '', 'title' => $g['title'] ?? '', 'text' => mb_substr((string) $g['best']['text'], 0, 1800), 'topic' => ($g['section'] ?? '') . ' / ' . ($g['topic'] ?? '')];
    }
    usort($out, fn($a, $b) => $b['score'] <=> $a['score']);
    return array_slice($out, 0, $n);
}
/** Who this WhatsApp number is (each number has its own business, audience and style). */
function wf_profile(string $aid): string {
    $c = wi_read('bot.json', [])[$aid] ?? [];
    $p = trim((string) ($c['about'] ?? '')) !== '' ? trim((string) $c['about']) : trim((string) ($c['profile'] ?? ''));
    $acc = wi_accounts()[$aid] ?? null;
    return $p !== '' ? $p : 'the business that owns the WhatsApp number "' . ($acc['name'] ?? $aid) . '" (its exact service is not described yet — stay general and hand over anything specific)';
}
function T_(string $s): string { return ['Audience' => 'الجمهور', 'Language' => 'اللغة', 'Tone' => 'الأسلوب', 'Common requests' => 'الطلبات الشائعة', 'Leave to a human' => 'يُترك لموظف'][$s] ?? $s; }
/** The owner's rules box as a numbered block that comes before every other instruction. */
function wf_owner_rules(array $cfg): string {
    $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) ($cfg['rules'] ?? '')))));
    if (!$lines) return '';
    $out = "OWNER RULES — these come first and override every other instruction below, including the team examples:\n";
    foreach ($lines as $i => $l) $out .= ($i + 1) . '. ' . $l . "\n";
    return $out . "\n";
}
function wf_bot_cfg(string $aid): array { return (wi_read('bot.json', [])[$aid] ?? []) + ['model' => '', 'tone' => 'warm', 'name' => '', 'about' => '', 'rules' => '', 'fast' => true]; }
/** Real past exchanges closest to the message — examples of how the team talks. */
function wf_style_examples(string $aid, string $text, int $n = 4, string $role = ''): array {
    $want = array_flip(wf_words($text)); $out = [];
    foreach (wf_pairs($aid) as $p) {
        if (mb_strlen($p['a']) < 25 || mb_strlen($p['a']) > 900) continue;
        $hit = count(array_intersect_key(array_flip(wf_words($p['q'])), $want));
        if ($hit && $role !== '' && ($p['role'] ?? '') === $role) $hit += 2; // the healers' answers in this role teach its style first
        if ($hit) $out[] = [$hit, $p];
    }
    usort($out, fn($a, $b) => $b[0] <=> $a[0]);
    return array_map(fn($x) => $x[1], array_slice($out, 0, $n));
}
/** What the bot knows about the person: new or existing, groups, programs, case, customer. */
function wf_customer_card(array $acc, string $jid, array $rows = []): array {
    $aid = (string) $acc['id'];
    if (!$rows) { $t = wi_gateway('/archive/thread?jid=' . rawurlencode($jid), null, $acc); $rows = $t['rows'] ?? []; }
    $msgs = array_values(array_filter($rows, fn($m) => in_array($m['dir'] ?? '', ['in', 'out'], true)));
    $first = $msgs[0]['time'] ?? null;
    $c = wi_contact($jid, $acc); $phone = (string) ($c['phone'] ?? '');
    $groups = [];
    foreach (wi_read('known_members.json', [])[$aid] ?? [] as $gj => $g) foreach ($g['members'] ?? [] as $m) if ($phone !== '' && ($m['p'] ?? '') === $phone) $groups[] = $g['name'];
    $progs = []; $pn = []; foreach (wf_programs($aid) as $p) $pn[$p['id']] = $p['name'];
    foreach (wf_enrollments($aid) as $e) if ($e['jid'] === $jid && in_array($e['status'], ['active', 'paused'], true)) $progs[] = ($pn[$e['program_id']] ?? '') . ' (day ' . (count($e['sent'] ?? []) ) . ')';
    $case = $phone !== '' ? (wf_cases($aid)[$phone] ?? null) : null;
    $cust = $phone !== '' ? (wi_customers()[$phone] ?? null) : null;
    $earlier = count(array_filter($msgs, fn($m) => strtotime((string) $m['time']) < time() - 86400));
    return ['new' => $earlier === 0 && !$groups && !$progs && !$case, 'first' => $first, 'messages' => count($msgs), 'groups' => array_slice(array_unique($groups), 0, 5), 'programs' => $progs,
        'case' => $case ? (($case['status'] ?? 'new') . ($case['data']['complaint'] ?? '' ? ': ' . $case['data']['complaint'] : '')) : '', 'customer' => $cust['name'] ?? '', 'name' => (string) ($c['name'] ?? '')];
}
function wf_card_text(?array $k): string {
    if (!$k) return "UNKNOWN person — treat as NEW.";
    if ($k['new']) return 'NEW person — first contact' . ($k['first'] ? ' (' . substr((string) $k['first'], 0, 10) . ')' : '') . ', not in any group, no program, no case yet' . ($k['name'] ? ', WhatsApp name: ' . $k['name'] : '') . '.';
    return 'EXISTING person — talking with us since ' . substr((string) $k['first'], 0, 10) . ', ' . $k['messages'] . ' messages'
        . ($k['groups'] ? '; in groups: ' . implode(', ', $k['groups']) : '; not in our groups') . ($k['programs'] ? '; programs: ' . implode(', ', $k['programs']) : '; no program')
        . ($k['case'] ? '; case: ' . $k['case'] : '') . ($k['customer'] ? '; registered customer: ' . $k['customer'] : '') . '.';
}
/** The real names of this number's groups (the bot's groups, the case registry and every group of the number), cached 10 minutes. */
function wf_our_groups(array $acc): array {
    $aid = (string) $acc['id']; static $mem = []; if (isset($mem[$aid])) return $mem[$aid];
    $f = WI_DIR . '/cache_ourgroups_' . preg_replace('/\W/', '', $aid) . '.json';
    if (is_file($f) && filemtime($f) > time() - 600) return $mem[$aid] = (array) json_decode((string) file_get_contents($f), true);
    $n = []; foreach ((array) (wf_bot_run_cfg($aid)['groups'] ?? []) as $g) $n[] = (string) ($g['name'] ?? '');
    if (function_exists('wf_registry_groups')) foreach (wf_registry_groups($aid) as $g) $n[] = (string) ($g['name'] ?? '');
    foreach ((array) (wi_gateway('/archive/chats?months=3', null, $acc)['chats'] ?? []) as $c) if (!empty($c['group'])) $n[] = (string) ($c['name'] ?? '');
    $n = array_slice(array_values(array_unique(array_filter(array_map(fn($x) => trim(preg_replace('/\s+/u', ' ', $x)), $n)))), 0, 25);
    @file_put_contents($f, json_encode($n, JSON_UNESCAPED_UNICODE)); @chmod($f, 0600);
    return $mem[$aid] = $n;
}
/** ⚡ Fast replies: the first provider that is working now (in the AI providers order), with its quickest model that is not resting
 *  (Gemini: a Flash-Lite model). "provider:model", or '' to let the normal chain decide. Cached for the request. */
function wf_fast_model(bool $bulk = false): string {
    // $bulk: background work (learning, file checks) — it leaves out a provider on a free plan with small daily limits (Gemini free: 20–500 a day), so the bot keeps that quota
    static $memo = []; if (isset($memo[(int) $bulk])) return $memo[(int) $bulk]; $m = &$memo[(int) $bulk]; $m = '';
    try { require_once __DIR__ . '/lib/WiAiProviders.php'; $P = new WiAiProviders(wi_ai_scope());
        foreach ($P->active_providers() as $id => $p) { if (empty($p['health']['ok'])) continue;
            if ($bulk && !empty($p['free_daily_small']) && ($p['plan'] ?? 'free') !== 'paid') continue;
            $chain = $P->model_chain($p, false); if (!$chain) continue;
            // the provider's models that can answer now (not resting), quickest kinds first (measured: deepseek flash ~5 s, glm flash ~10 s, big models 20–30 s)
            $st = $P->get_state($id); $all = array_column((array) json_decode((string) ($st['models_json'] ?? ''), true), 'id'); $lim = json_decode((string) ($st['limits_json'] ?? ''), true);
            $cool = (array) ($lim['model_cooldown'] ?? []); $ok = array_values(array_filter(array_unique(array_merge($chain, $all)), fn($x) => (empty($cool[$x]) || $cool[$x] <= time()) && $P->model_allowed($p, $x) && ($p['plan'] === 'paid' || in_array($x, $chain, true) || !preg_match('/pro|max|opus|ultra/i', $x))));
            $pick = $chain[0];
            // measured on 14 real questions: deepseek flash and gemini flash are equal, the lite models are the weakest
            foreach (['/deepseek[\w.-]*flash/i', '/gemini-[\d.]+-flash(?!-lite)/i', '/glm[\w.-]*flash/i', '/-flash\b|flash$/i', '/flash-lite/i', '/mini|nano|lightning/i'] as $re) { $hit = array_values(array_filter($ok, fn($x) => preg_match($re, $x))); if ($hit) { $pick = $hit[0]; break; } }
            return $m = $id . ':' . $pick; }
    } catch (Throwable $e) {}
    return $m;
}
/** What the bot WOULD answer (nothing is sent). $prefer = "provider:model" to force a model (compare). */
function wf_bot_answer(array $acc, string $text, array $history = [], string $prefer = '', ?array $card = null, array $opt = []): array {
    if (function_exists('wf_fb_parent') && ($pa = wf_fb_parent($acc))) $acc = $pa; // a Facebook page linked to a number answers with that number's knowledge (profile, library, learning, rules)
    $aid = (string) $acc['id']; $cfg = wf_bot_cfg($aid); $avoid = array_values(array_filter((array) ($opt['avoid'] ?? [])));
    // 1) Answer cache: the same general question answered well before → reused, no AI call, no tokens.
    if (!$prefer && !$avoid && ($opt['role'] ?? 'bot') === 'bot' && mb_strlen(trim($text)) >= 12 && (count($history) <= 2 || wf_cache_standalone($text)) && ($hit = wf_cache_find($aid, $text))) {
        return ['ok' => true, 'reply' => $hit['answer'], 'confidence' => 0.95, 'handoff' => false, 'clarify' => false, 'reason' => 'من الكاش (' . round($hit['similarity'] * 100) . '%)',
            'symptoms' => false, 'complaint' => '', 'sources' => [], 'used' => [], 'cached' => $hit['id'], 'task' => ['model' => 'cache', 'tokens_in' => 0, 'tokens_out' => 0]];
    }
    // 2) Guide: concepts whose words appear in the message go in as short cards — and fewer library chunks are needed.
    $guide = wf_learn_cfg($aid)['guide'] ? wf_guide_match($aid, $text) : [];
    $src = wf_retrieve($acc, $text . ' ' . implode(' ', array_slice(array_column($history, 'text'), -2)), $guide ? 3 : 6);
    // passages from uploaded files (Learning → File library)
    try { $src = array_merge(wf_doc_retrieve($aid, $text, 2), $src); } catch (Throwable $e) {}
    $ctx = ''; foreach ($src as $i => $x) $ctx .= '### S' . ($i + 1) . ' [' . ($x['kind'] === 'library' ? 'library text' : ($x['kind'] === 'file' ? (!empty($x['agree']) ? 'uploaded file, matches the team' : 'uploaded file') : 'team answer')) . ($x['topic'] ? ' · ' . $x['topic'] : '') . "]\nAbout: " . $x['title'] . "\n" . $x['text'] . "\n\n";
    $ex = ''; foreach (wf_style_examples($aid, $text, 4, in_array($opt['role'] ?? '', ['', 'bot', 'teacher', 'crisis'], true) ? '' : (string) $opt['role']) as $p) $ex .= "Customer: " . mb_substr(preg_replace('/\s+/u', ' ', $p['q']), 0, 300) . "\nTeam: " . mb_substr($p['a'], 0, 600) . "\n---\n";
    $hist = ''; foreach (array_slice($history, -8) as $h) $hist .= ($h['from'] === 'bot' ? 'You' : 'Customer') . ': ' . mb_substr((string) $h['text'], 0, 500) . "\n";
    $tone = ['warm' => 'kind, calm and respectful — professional care, never affectionate', 'short' => 'very short and direct (1–3 lines)', 'formal' => 'polite and formal'][$cfg['tone']] ?? 'warm';
    $rp = isset($opt['role']) && function_exists('wf_role_prompt') ? wf_role_prompt($aid, (string) ($opt['jid'] ?? ''), (string) $opt['role']) : ['rules' => '', 'prefer' => ''];
    // The WhatsApp name of this number is the team's number (the bot itself), not a sheikh or a person to send people to.
    $ours = wf_our_groups($acc); $groupsRule = $ours ? "- OUR GROUPS (the only groups and the only center you may name or send people to): " . implode(' · ', $ours) . ". A center or group name found in the sources or examples that is not in this list belongs to someone else: never mention it.\n" : '';
    $self = trim((string) ($acc['name'] ?? '')); $selfRule = (!empty($opt['team']) ? wf_prompt('bot_team_person', $aid) : '') . (!empty($opt['ask']) ? wf_prompt('bot_ask', $aid) : '') . (!empty($opt['fun']) ? wf_prompt('bot_fun', $aid) : '') . $groupsRule . ($self !== '' ? "- \"" . $self . "\" is the name of THIS WhatsApp number (you, the team's number), not a person: never call it a sheikh and never tell people to follow up with it; say \"the sheikh\" / \"the team\" without a name unless the sources give the healer's name.\n- Use the person's name only if they wrote it or the customer card gives it; never take a name from another person in the conversation.\n\n" : '');
    // Healing numbers with roles: one prompt where the model picks the mode itself (keyword routing sent questions to the wrong role)
    $rc = function_exists('wf_roles_cfg') ? wf_roles_cfg($aid) : ['on' => 0, 'roles' => []];
    if (!empty($rc['on']) && $rc['roles'] && (!empty($cfg['brain']) || !empty($opt['brain'])) && empty($opt['classic'])) { // on once the bot test shows it is better
        $modes = ''; foreach ($rc['roles'] as $k => $r) if (!empty($r['on'])) $modes .= '- ' . $k . ' (' . ($r['label'] ?? $k) . '): ' . str_replace("\n", ' ', trim((string) ($r['rules'] ?? ''))) . "\n";
        $modes .= "- bot (the team's assistant): greetings, questions about the team, groups, times, joining, anything else.\n" . trim((string) $rc['common']);
        $hint = ($opt['role'] ?? 'bot') !== 'bot' ? "(The customer's words suggest the mode: " . $opt['role'] . ")\n" : '';
        $prompt = wf_prompt('bot_brain', $aid, ['owner_rules' => $selfRule . wf_owner_rules($cfg), 'tone' => $tone,
            'name_line' => ($cfg['name'] ?: 'the assistant') . ($cfg['name'] ? ' (your name is ' . $cfg['name'] . ')' : ''), 'who' => wf_card_text($card), 'modes' => $hint . $modes,
            'avoid' => $avoid ? "YOUR EARLIER REPLIES HERE — never repeat or rephrase them:\n- " . implode("\n- ", array_map(fn($a) => mb_substr(preg_replace('/\s+/u', ' ', (string) $a), 0, 300), $avoid)) . "\n\n" : '',
            'examples' => $ex ?: '(none)', 'sources' => $ctx ?: '(none)', 'conversation' => $hist . 'Customer: ' . mb_substr($text, 0, 1500)]);
    } else
    $prompt = wf_prompt('bot_reply', $aid, ['owner_rules' => $rp['rules'] . $selfRule . wf_owner_rules($cfg), 'guide' => wf_guide_prompt($guide), 'tone' => $tone,
        'name_line' => ($cfg['name'] ?: 'the assistant') . ($cfg['name'] ? ' (your name is ' . $cfg['name'] . '; never make up another name)' : ' (never make up a personal name for yourself)'),
        'style_rules' => wf_style_prompt($aid), 'who' => wf_card_text($card), 'owner_check' => $cfg['rules'] ? "- Before answering, check your reply against EVERY numbered OWNER RULE at the top; rewrite it if it breaks any.\n" : '',
        'avoid' => $avoid ? "YOUR EARLIER REPLIES IN THIS CHAT — the new reply must NOT repeat or rephrase them; answer what is new, move the conversation forward, or set handoff=true:\n- " . implode("\n- ", array_map(fn($a) => mb_substr(preg_replace('/\s+/u', ' ', (string) $a), 0, 300), $avoid)) . "\n\n" : '',
        'examples' => $ex ?: '(none)', 'sources' => $ctx ?: '(none)', 'conversation' => $hist . 'Customer: ' . mb_substr($text, 0, 1500)]);
    // Joining groups (Bot → Joining groups): the model only flags the request; the runner adds the person or sends the personal invitation.
    if (function_exists('wf_bot_join_cfg') && !empty(wf_bot_join_cfg($aid)['on'])) $prompt = str_replace("Set clarify=true", "Set \"join_group\":true when the person asks to join or rejoin one of our groups, or says they cannot get in (the link does not work, they were removed). Then the reply only says you are taking care of it now — never send or promise a link.\nSet clarify=true", $prompt);
    // ⚡ Fast replies: when no model is chosen, a light fast model answers first (others take over if it fails).
    $pref = $prefer ?: ($rp['prefer'] ?: ($cfg['model'] ?: (!empty($cfg['fast']) && empty($opt['no_fast']) ? wf_fast_model() : ''))); // a role that needs care uses a strong model
    $ai = wi_ai('Bot test', $prompt, 180, ['subject' => mb_substr($text, 0, 60), 'prefer' => $pref]);
    if (empty($ai['ok']) && preg_match('/blocked|PROHIBITED|SAFETY/i', (string) ($ai['error'] ?? ''))) {
        // A content filter refused the material: retry with the closest two sources only and no examples.
        $ctx2 = ''; foreach (array_slice($src, 0, 2) as $i => $x) $ctx2 .= '### S' . ($i + 1) . "\n" . mb_substr($x['text'], 0, 700) . "\n\n";
        $prompt2 = preg_replace('/TEAM EXAMPLES.*?SOURCES:\n.*?\nCONVERSATION:/su', "SOURCES:\n" . ($ctx2 ?: '(none)') . "\nCONVERSATION:", $prompt);
        $ai = wi_ai('Bot test (short context)', $prompt2, 180, ['subject' => mb_substr($text, 0, 60), 'prefer' => $pref]);
    }
    if (empty($ai['ok'])) return ['ok' => false, 'error' => $ai['error'] ?? 'AI failed.', 'sources' => $src];
    // Guard: a count or a duration (11 مرة، 3 أيام…) that is in no team example and no source was made up — rewrite once without it
    if (!empty($ai['ok']) && isset($ai['data']['reply'])) { $digits = fn($t) => strtr((string) $t, array_combine(array_map(fn($i) => mb_chr(0x660 + $i), range(0, 9)), array_map('strval', range(0, 9)))); // Arabic-Indic digits too
        $known = $digits($ex . ' ' . $ctx . ' ' . $text); $bad = [];
        if (preg_match_all('/(\d+)\s*(?:' . (string) (wf_pack($aid)['count_units'] ?? 'times|days|hours|weeks|months|minutes') . ')/u', $digits($ai['data']['reply']), $mm)) foreach (array_unique($mm[1]) as $nb) if (!preg_match('/(?<!\d)' . $nb . '(?!\d)/', $known)) $bad[] = $nb;
        $sac = wf_sacred_unknown($aid, (string) $ai['data']['reply'], $known); // a verse, surah or dhikr that no example or source has
        if ($bad || $sac) { $why = trim(($bad ? 'counts or durations (' . implode(', ', $bad) . ') ' : '') . ($sac ? 'verses, surahs or adhkar (' . implode(' / ', $sac) . ') ' : ''));
            $fix = wi_ai('Bot reply (fix)', $prompt . wf_prompt('bot_fix', $aid, ['draft' => (string) $ai['data']['reply'], 'why' => $why]), 120, ['prefer' => $pref]);
            if (!empty($fix['ok']) && (wf_sacred_unknown($aid, (string) ($fix['data']['reply'] ?? ''), $known))) $fix['data']['confidence'] = min((float) ($fix['data']['confidence'] ?? 0), 0.4); // still made up: not sent
            if (!empty($fix['ok']) && trim((string) ($fix['data']['reply'] ?? '')) !== '') $ai = $fix + ['task' => $ai['task']]; else $ai['data']['confidence'] = min((float) ($ai['data']['confidence'] ?? 0), 0.4); } }
    // The number's own name after "the sheikh" (a mix-up the model keeps making): the title stays, the name goes.
    $self = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', trim((string) ($acc['name'] ?? '')));
    if ($self !== '' && isset($ai['data']['reply']) && preg_match_all('/\p{L}/u', $self, $ch)) { $re = implode('[\x{064B}-\x{065F}\x{0670}\x{0640}\s]*', array_map(fn($c) => preg_quote($c, '/'), $ch[0]));
        $ai['data']['reply'] = preg_replace('/(\x{0627}\x{0644}\x{0634}\x{064A}\x{062E}|\x{0634}\x{064A}\x{062E})[\x{064B}-\x{065F}\s]+' . $re . '/u', '$1', (string) $ai['data']['reply']); }
    $SS = wf_style_cfg($aid); $ai['data']['reply'] = wf_clean_affection((string) ($ai['data']['reply'] ?? ''), $SS['strip_affection'] ? wf_style_words((string) $SS['affection_words'] . '،' . (string) (wf_pack($aid)['nicknames'] ?? '')) : [], !empty($SS['strip_romantic']));
    $used = [];
    foreach ((array) ($ai['data']['used'] ?? []) as $u) { $i = (int) preg_replace('/\D/', '', (string) $u) - 1; if (isset($src[$i])) $used[] = $i; }
    // 3) A confident general answer is kept for next time.
    $rep = (string) ($ai['data']['reply'] ?? ''); $conf = (float) ($ai['data']['confidence'] ?? 0);
    if (!$prefer && !$avoid && ($opt['role'] ?? 'bot') === 'bot' && $rep !== '' && $conf >= 0.85 && empty($ai['data']['handoff']) && empty($ai['data']['clarify']) && (count($history) <= 2 || wf_cache_standalone($text))) wf_cache_put($aid, $text, $rep, $guide ? 'guide' : 'ai');
    return ['ok' => true, 'reply' => $rep, 'mode' => preg_replace('/[^a-z]/', '', (string) ($ai['data']['mode'] ?? '')), 'guide' => array_column($guide, 'name'), 'confidence' => max(0, min(1, (float) ($ai['data']['confidence'] ?? 0))), 'handoff' => !empty($ai['data']['handoff']),
        'join_group' => !empty($ai['data']['join_group']), 'symptoms' => !empty($ai['data']['symptoms']), 'complaint' => mb_substr((string) ($ai['data']['complaint'] ?? ''), 0, 300),
        'reason' => (string) ($ai['data']['reason'] ?? ''), 'clarify' => !empty($ai['data']['clarify']), 'sources' => $src, 'used' => $used, 'task' => $ai['task'] ?? null];
}

/** Verses, surah names and adhkar in a reply that appear in no team example and no source (each a short quote). Markers come from the business profile. */
function wf_sacred_unknown(string $aid, string $reply, string $known): array {
    $mk = (string) (wf_pack($aid)['sacred_markers'] ?? ''); if ($mk === '' || trim($reply) === '') return [];
    $n = fn(string $t) => trim(preg_replace('/\s+/u', ' ', preg_replace('/[^\p{L}\p{N}\s]/u', ' ', wf_intake_norm(preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}]/u', '', $t)))));
    $K = ' ' . $n($known) . ' '; $out = [];
    foreach (preg_split('/[\n.\x{060C}\x{061B}!\x{061F}?:]+/u', $reply) as $seg) {
        if (!preg_match('/(' . $mk . ')/u', $seg, $m, PREG_OFFSET_CAPTURE)) continue;
        $w = array_values(array_filter(explode(' ', $n(substr($seg, $m[0][1])))));
        if (count($w) < 2) continue;
        $q = implode(' ', array_slice($w, 0, min(5, count($w)))); // the marker and the words after it
        if (mb_strpos($K, ' ' . $q . ' ') === false && mb_strpos($K, ' ' . implode(' ', array_slice($w, 0, preg_match('/^\x{0633}\x{0648}\x{0631}/u', $w[0]) ? 2 : 3)) . ' ') === false) $out[] = mb_substr(trim($seg), 0, 60);
    }
    return array_slice(array_unique($out), 0, 4);
}

/** Message style: editable per number in Learning → Rules ("Message style"). These are only the starting values. */
function wf_style_defaults(): array {
    return ['prefix_on' => true, 'prefix' => '🤖', 'intro_on' => true, 'intro_groups' => false, 'intro_text' => 'أنا المساعد الرقمي بالذكاء الاصطناعي، وسأبدأ معك الحوار.',
        'end_mode' => 'fit', // fit = keep one ending emoji only when it suits the words · none = never · any = leave as written
        'end_emojis' => [['e' => '🤲', 'words' => 'يشفي، شفا، اللهم، ربنا، بإذن الله، باذن الله، يعافي، دعاء، ادعو، أدعو'], ['e' => '🙏', 'words' => 'شكر، جزاك، بارك'], ['e' => '✅', 'words' => 'تم، خلاص، اتسجل، جاهز'], ['e' => '📖', 'words' => 'سورة، آية، اقرأ، اقرا، قراءة'], ['e' => '⏰', 'words' => 'الساعة، ميعاد، موعد'], ['e' => '📌', 'words' => 'ملاحظة، مهم']],
        'strip_romantic' => true, 'strip_affection' => true,
        'affection_words' => 'حبيبي، حبيبتي، حبايبي، يا قلبي، يا روحي، يا عمري، يا غالي، يا غالية، يا قمر، يا عسل، يا حلوة، يا جميلة، يا سكر، يا دلوعة، يا أميرة، يا اميرة، يا برنسيسة، يا ست البنات، يا ست الكل، منورة، منوره، نورتي، أحبك، بحبك، وحشتني، dear، honey، sweetheart، darling',
        'prompt_rules' => ['Call the person only by the name shown under WHO YOU TALK TO (or the name they gave in this chat). Never guess, invent or reuse another name — if no name is known, use none.',
            'Greetings and welcomes stay neutral and respectful: no compliments, flattery or emotional words (e.g. منورة، نورتي، يا قمر، يا أميرة), never pair a person\'s first name with praise, and be extra formal with women. A plain greeting and getting to the point is enough.',
            'Never say that something happened or did not happen (a message was deleted, a payment arrived, a booking or file was sent, someone saw it) unless the conversation or the sources show it. If you are not sure, say you will check or that the team will confirm. Never swear (والله، أقسم) to make a claim.',
            'Never deny being an automated assistant; if asked, say you are the digital assistant and a team member can join.', 'Never mention a symptom, illness, family member, place, treatment step or past event about the person unless it is written in WHO YOU TALK TO or in the recent messages. A greeting, thanks or a short comment gets a short, natural reply — do not bring up their health or an earlier complaint, and do not give a new instruction nobody asked for. If you are not sure what they mean, ask one short question.', 'Never repeat a sentence or a question you already sent in this conversation; if the customer did not answer it, take a different step or hand over to the team.', 'Keep it WhatsApp-sized: 1–5 short lines. Emojis only when they really help (e.g. ✅ for a step, ⏰ for a time) — usually none; end with an emoji only if it fits what you just said (e.g. 🤲 after a dua), at most one, never decorative; never hearts, flowers, kisses or anything romantic.', 'STRICTLY RESPECTFUL: never use words of love or endearment (حبيبي، حبيبتي، يا قلبي، يا روحي، يا عمري، يا غالي، يا غالية، يا قمر، يا عسل، أحبك، وحشتني، love, dear, honey…), never comment on looks or voice, no flirting or anything that could be read as flirting or harassment; emojis are fine EXCEPT hearts of any colour, flowers/roses, kisses, love faces or anything romantic — even if the team examples or the person do. Address people with neutral respect, by name when known, and vary the wording; do NOT open with «يا أخي الكريم» / «أخي الكريم» / «أختي الكريمة» — prefer the person\'s name or no address at all.']];
}
function wf_style_cfg(string $aid): array {
    $d = wf_style_defaults();
    // the starting ending-emoji words follow the type of business: the faith ones (🤲 / 📖) only for the spiritual type
    if ($aid !== '' && function_exists('wf_pack') && (wf_pack($aid)['preset'] ?? '') !== 'ruqyah') $d['end_emojis'] = array_values(array_filter($d['end_emojis'], fn($x) => !in_array($x['e'], ['🤲', '📖'], true)));
    return (array) (wi_read('bot.json', [])[$aid]['style'] ?? []) + $d;
}
function wf_style_prompt(string $aid): string { $o = ''; foreach ((array) wf_style_cfg($aid)['prompt_rules'] as $l) if (trim((string) $l) !== '') $o .= '- ' . trim((string) $l) . "\n"; return $o; }
function wf_style_words(string $list): array { return array_values(array_filter(array_map('trim', preg_split('/[\n,،]+/u', $list)))); }
/** Applied to every bot message before it is sent — all of it follows the number's "Message style" settings. */
function wf_bot_style(string $t, string $aid = '', string $jid = '', bool $group = false): string {
    $S = $aid !== '' ? wf_style_cfg($aid) : wf_style_defaults(); $pre = trim((string) $S['prefix']);
    $t = trim($t); if ($pre !== '') $t = preg_replace('/^\s*' . preg_quote($pre, '/') . '\s*/u', '', $t);
    if ($S['strip_affection']) $t = wf_clean_affection($t, wf_style_words((string) $S['affection_words']), !empty($S['strip_romantic']));
    elseif ($S['strip_romantic']) $t = wf_clean_affection($t, [], true);
    if ($S['end_mode'] !== 'any' && preg_match('/((?:[\s\x{FE0F}\x{200D}]|\p{Extended_Pictographic}|[\x{1F3FB}-\x{1F3FF}])+)$/u', $t, $em)) {
        $body = rtrim(mb_substr($t, 0, mb_strlen($t) - mb_strlen($em[1]))); $keep = '';
        if ($S['end_mode'] === 'fit') { $tail = wf_norm(mb_substr($body, -150));
            foreach ((array) $S['end_emojis'] as $f) { $e = trim((string) ($f['e'] ?? '')); if ($e === '' || mb_strpos($em[1], $e) === false) continue;
                foreach (wf_style_words((string) ($f['words'] ?? '')) as $w) if (($n = wf_norm($w)) !== '' && mb_strpos($tail, $n) !== false) { $keep = ' ' . $e; break 2; } } }
        $t = $body . $keep; }
    if (!preg_match('/[\p{L}\p{N}]/u', $t)) return ''; // nothing left to say after the clean-up: nothing is sent
    // First bot message in a chat: introduce itself once.
    if ($aid !== '' && $jid !== '' && $S['intro_on'] && trim((string) $S['intro_text']) !== '' && (!$group || $S['intro_groups'])) { $I = wi_read('bot_intro.json', []);
        if (empty($I[$aid][$jid])) { $GLOBALS['WF_INTRO_PENDING'] = [$aid, $jid]; $t = trim((string) $S['intro_text']) . "\n\n" . $t; } } // marked as sent only after the send succeeds (wf_bot_intro_done)
    return ($S['prefix_on'] && $pre !== '' ? $pre . ' ' : '') . $t;
}
/** The introduction prepared by wf_bot_style() was really delivered: never prepend it again in this chat. */
function wf_bot_intro_done(bool $sent): void {
    $p = $GLOBALS['WF_INTRO_PENDING'] ?? null; unset($GLOBALS['WF_INTRO_PENDING']); if (!$p || !$sent) return;
    wi_update('bot_intro.json', function ($I) use ($p) { $I[$p[0]][$p[1]] = date('c'); return $I; });
}
/** Last safety net: removes words of endearment the model may still have written. */
function wf_clean_affection(string $t, ?array $words = null, bool $romantic = true): string {
    $words ??= wf_style_words((string) wf_style_defaults()['affection_words']);
    if ($words) { $alt = implode('|', array_map(fn($w) => preg_replace(['/\\ /u', '/[يى]/u', '/[ةه]$/u', '/[أإا]/u'], ['\\s*', '[يى]', '[ةه]', '[أإا]'], preg_quote($w, '/')), $words));
        // A short phrase built around such a word ("منورة الجروب يا فلانة") goes as a whole, so no broken sentence is left; in a long sentence only the word goes.
        $rx = '/(?<![\p{L}])(?:يا\s+)?(?:' . $alt . ')(?![\p{L}])/iu';
        $cut = preg_replace_callback('/[^،,.!؟?\n]+[،,.!؟?]?/u', function ($m) use ($rx) { return preg_match($rx, $m[0]) && count(preg_split('/\s+/u', trim($m[0]))) <= 6 ? '' : $m[0]; }, $t);
        if (count(preg_split('/\s+/u', trim(preg_replace('/[^\p{L}\p{N}\s]+/u', '', $cut)))) >= 3) $t = $cut; // never leave (almost) nothing: then only the words go
        $t = preg_replace('/\s*[،,]?\s*(?<![\p{L}])(?:يا\s+)?(?:' . $alt . ')(?![\p{L}])\s*[،,!]?/iu', ' ', $t); }
    if ($romantic) $t = preg_replace('/(?:[❤♥❣💌💋💍💏💑💒💓💔💕💖💗💘💙💚💛💜💝💞💟🖤🤍🤎🧡🩵🩶🩷😍😘😚😙😗🥰😻😽💐🌸🌹🌷🌺🌼🌻🥀🏵💮🌹]|👩‍❤️‍👨|👩‍❤️‍👩|👨‍❤️‍👨|🫶|🫰|💃|🕺)\x{FE0F}?(?:\x{200D}[\x{1F525}\x{1FA79}])?/u', '', $t);
    return trim(preg_replace('/[ \t]{2,}/u', ' ', preg_replace('/^\s*[،,]\s*/mu', '', $t)));
}

/** Which bot role a question belongs to (healer, support, dreams…) from the role words; '' = general. */
function wf_learn_role(string $aid, string $q): string {
    if (!function_exists('wf_roles_cfg')) return ''; static $R = [];
    $R[$aid] ??= array_filter(wf_roles_cfg($aid)['roles'], fn($r) => trim((string) ($r['words'] ?? '')) !== '');
    foreach ($R[$aid] as $k => $r) if (wf_roles_hit($q, (string) $r['words'])) return (string) $k;
    return '';
}
/* ---------- Learning from the team's real answers (collect the exchanges, then distill them into knowledge) ---------- */
/** Step 1: the question → team answer exchanges from the chats and the groups the team answers in (each tagged with its role). */
function wf_learn_collect(array $acc, bool $fullAsked = false): array {
    $aid = (string) $acc['id'];
        // Incremental: only exchanges newer than the last collect are read; everything collected/learned is kept.
        $old = wf_pairs($aid); $meta = wi_read('learn_meta.json', [])[$aid] ?? [];
        // Groups to learn from: the bot's groups marked "learn", and — when "learn from all groups" is on (default) — every group of this number,
        // so the healers' answers in the treatment groups teach the bot too.
        $rc = wf_bot_run_cfg($aid); $lg = [];
        $lean = function_exists('wf_lean_cfg') && !empty(wf_lean_cfg($aid)['on']); // groups are learned the token-saving way (learn_lean): here only the private chats
        if (!$lean) foreach ((array) ($rc['groups'] ?? []) as $gj => $gc) if (!empty($gc['learn'])) $lg[$gj] = ['name' => $gc['name'] ?? ''];
        if (!$lean && !empty(wf_learn_cfg($aid)['all_groups'])) foreach ((array) (wi_gateway('/archive/chats?months=' . (int) wi_sys('months_long'), null, $acc)['chats'] ?? []) as $ch) if (!empty($ch['group']) && !isset($lg[$ch['jid']])) $lg[(string) $ch['jid']] = ['name' => (string) ($ch['name'] ?? '')];
        $lgKey = md5(json_encode(array_keys($lg)));
        $full = $fullAsked || empty($meta['collected_to']) || ($meta['learn_groups'] ?? '') !== $lgKey;
        $since = $full ? '' : date('c', strtotime((string) $meta['collected_to']) - 6 * 3600); // overlap: a reply can come hours later
        // The members' questions in those groups and the answers of the team / the group admins (the healers).
        $teamNums = $lg ? implode(',', array_keys(array_filter(wf_team($acc, $lg), fn($w) => $w !== 'user' || true))) : '';
        $r = wi_gateway('/archive/pairs?months=' . (int) wi_sys('months_long')  . ($since !== '' ? '&since=' . rawurlencode(gmdate('Y-m-d\TH:i:s', strtotime($since))) : '')
            . ($lg ? '&groups=' . rawurlencode(implode(',', array_keys($lg))) . '&team=' . rawurlencode($teamNums) : ''), null, $acc);
        if (empty($r['ok'])) return ['ok' => false, 'error' => $r['error'] ?? 'Gateway error.'];
        $out = $full ? ($lean ? array_filter($old, fn($p) => !empty($p['group'])) : []) : $old; $new = 0; $latest = (string) ($meta['collected_to'] ?? '');
        foreach ($r['pairs'] as $p) {
            if ((string) $p['a_time'] > $latest) $latest = (string) $p['a_time'];
            if (count(wf_words($p['q'])) < 2 || mb_strlen(trim($p['a'])) < 8) continue; // greetings / "ok"
            $id = substr(md5(wf_norm($p['q']) . '|' . wf_norm(mb_substr($p['a'], 0, 300))), 0, 12);
            if (isset($out[$id])) continue;
            $out[$id] = ['id' => $id, 'q' => $p['q'], 'a' => $p['a'], 'time' => $p['a_time'], 'done' => !empty($old[$id]['done']), 'role' => wf_learn_role($aid, (string) $p['q'])] + (!empty($p['group']) ? ['group' => $p['jid']] : []);
            if (!isset($old[$id])) $new++;
        }
        wi_write(basename(wf_pairs_file($aid)), $out);
        $all = wi_read('learn_meta.json', []); $all[$aid] = ['collected_at' => date('c'), 'collected_to' => $latest ?: date('c'), 'learn_groups' => $lgKey]; wi_write('learn_meta.json', $all);
        return ['ok' => true, 'pairs' => count($out), 'new' => $new, 'full' => $full, 'remaining' => count(array_filter($out, fn($p) => empty($p['done'])))];
}
/** Step 2: the next 20 exchanges become general knowledge items (AI); items confirmed by enough team answers are approved. */
function wf_learn_distill(array $acc): array {
    $aid = (string) $acc['id'];
        // distill: 20 pairs per call into general knowledge items
        $pairs = wf_pairs($aid); $lean = function_exists('wf_lean_cfg') && !empty(wf_lean_cfg($aid)['on']); $todo = array_values(array_filter($pairs, fn($p) => empty($p['done']) && !($lean && !empty($p['group'])))); // groups: learn_lean
        // Most useful first: substantial replies to real questions, newer first.
        $qscore = fn($p) => min(400, mb_strlen($p['a'])) / 40 + min(8, count(wf_words($p['q']))) + (preg_match('/[؟?]/u', $p['q']) ? 3 : 0) + (strtotime((string) $p['time']) > time() - 180 * 86400 ? 2 : 0);
        usort($todo, fn($a, $b) => $qscore($b) <=> $qscore($a));
        $total = count($pairs);
        if (!$todo) return ['ok' => true, 'done' => 0, 'remaining' => 0, 'total' => $total];
        $batch = array_slice($todo, 0, 20); $txt = '';
        foreach ($batch as $p) $txt .= '### ' . $p['id'] . "\nCUSTOMER: " . mb_substr(preg_replace('/\s+/u', ' ', $p['q']), 0, 600) . "\nTEAM REPLY: " . mb_substr($p['a'], 0, 900) . "\n\n";
        $kb = wf_kb($aid); $topicsKnown = implode('، ', array_slice(array_unique(array_column($kb, 'topic')), 0, 40));
        $prompt = wf_prompt('kb_extract', $aid, ['topics' => $topicsKnown, 'exchanges' => $txt]);
        $ai = wi_ai('Bot learning', $prompt, 220, ['subject' => count($batch) . ' exchanges', 'progress' => ['done' => $total - count($todo), 'total' => $total], 'prefer' => wf_fast_model(true)]); // not on Gemini's free daily quota: the bot needs it
        $items = is_array($ai['data']['items'] ?? null) ? $ai['data']['items'] : (is_array($ai['data'] ?? null) && array_is_list($ai['data']) ? $ai['data'] : null); // some models return the list itself
        if (empty($ai['ok']) || $items === null) return ['ok' => false, 'error' => $ai['error'] ?? 'AI returned no result.'];
        $all = wi_read('learn_kb.json', []); $kbA = $all[$aid] ?? []; $n = 0;
        foreach ($items as $x) { if (!is_array($x)) continue;
            $q = trim((string) ($x['q'] ?? '')); $a = trim((string) ($x['a'] ?? '')); if ($q === '' || $a === '') continue;
            $id = substr(md5(wf_norm($q)), 0, 12);
            if (isset($kbA[$id])) { $kbA[$id]['sources'] = ($kbA[$id]['sources'] ?? 1) + count((array) ($x['from'] ?? [1])); if (empty($kbA[$id]['edited']) && mb_strlen($a) > mb_strlen($kbA[$id]['a'])) $kbA[$id]['alt'] = $a; continue; }
            $kbA[$id] = ['id' => $id, 'topic' => mb_substr(trim((string) ($x['topic'] ?? '')), 0, 50), 'q' => mb_substr($q, 0, 500), 'a' => mb_substr($a, 0, 3000), 'type' => (string) ($x['type'] ?? 'faq'), 'sources' => max(1, count((array) ($x['from'] ?? []))), 'status' => 'pending', 'at' => date('c')];
            $n++;
        }
        $merged = wf_kb_merge($kbA); $auto = wf_kb_auto($kbA, (int) (wf_bot_cfg($aid)['auto_approve'] ?? 0));
        $all[$aid] = $kbA; wi_write('learn_kb.json', $all);
        foreach ($batch as $p) $pairs[$p['id']]['done'] = true;
        wi_write(basename(wf_pairs_file($aid)), $pairs);
        return ['ok' => true, 'done' => $n, 'remaining' => max(0, count($todo) - count($batch)), 'total' => $total, 'task' => $ai['task']];
}

/** API calls of this area (moved unchanged from the old single switch). */
function wf_api_bot_learning(string $api): void {
    switch ($api) {
    /* ----- Bot learning & testing ----- */
    case 'learn_state': {
        $aid = wf_aid(); $kb = wf_kb($aid); $pairs = wf_pairs($aid);
        $topics = []; $st = ['pending' => 0, 'approved' => 0, 'disabled' => 0];
        foreach ($kb as $k) { $topics[$k['topic'] ?? '—'] = ($topics[$k['topic'] ?? '—'] ?? 0) + 1; $st[$k['status'] ?? 'pending']++; }
        arsort($topics);
        $rat = wi_read('bot_ratings.json', [])[$aid] ?? [];
        $good = count(array_filter($rat, fn($r) => $r['rating'] === 'good'));
        $accN = wi_accounts()[$aid]['name'] ?? $aid; $bc = wi_read('bot.json', [])[$aid] ?? [];
        $lm = wi_read('learn_meta.json', [])[$aid] ?? [];
        wi_json(['ok' => true, 'mode' => wf_bot_run_cfg($aid)['mode'], 'auto_approve' => (int) (wf_bot_cfg($aid)['auto_approve'] ?? 0), 'collected_at' => $lm['collected_at'] ?? null, 'account' => $accN, 'has_profile' => trim((string) (($bc['about'] ?? '') ?: ($bc['profile'] ?? ''))) !== '', 'pairs' => count($pairs), 'distilled' => count(array_filter($pairs, fn($p) => !empty($p['done']))), 'kb' => array_values($kb), 'topics' => $topics, 'status' => $st,
            'ratings' => ['total' => count($rat), 'good' => $good], 'bot_on' => !empty((wi_current() ?? [])['autoreply'])]);
    }
    case 'learn_build': {
        $in = wf_post(); @set_time_limit(240); $acc = wi_current();
        if (($in['step'] ?? '') === 'collect') wi_json(wf_learn_collect($acc, !empty($in['full'])));
        if (($in['step'] ?? '') === 'background') { wf_admin(); wi_job_add('kb_learn', (string) $acc['id'], ['base' => (string) $acc['id'], 'collect' => !empty($in['collect']), 'full' => !empty($in['full'])], (string) $_SESSION['email']); wi_json(['ok' => true, 'queued' => true]); } // everything, in the background
        wi_json(wf_learn_distill($acc));
    }
    case 'kb_table': {
        // Each knowledge question with the different real answers the team gave to it (or to the same question in other words).
        $aid = wf_aid(); $kb = wf_kb($aid); $pairs = wf_pairs($aid);
        $pw = []; foreach ($pairs as $p) $pw[] = [array_flip(wf_words($p['q'])), $p];
        $rows = [];
        foreach ($kb as $k) {
            $kw = array_flip(wf_words($k['q'])); $vars = [];
            $add = function (string $txt, string $from, ?string $time = null) use (&$vars) { $n = wf_norm(mb_substr($txt, 0, 400)); if ($n === '') return; $key = substr(md5($n), 0, 10); if (!isset($vars[$key])) $vars[$key] = ['text' => $txt, 'count' => 0, 'from' => $from, 'last' => $time]; $vars[$key]['count']++; if ($time && $time > (string) $vars[$key]['last']) $vars[$key]['last'] = $time; };
            $add($k['a'], 'bot'); if (!empty($k['alt'])) $add($k['alt'], 'learned');
            $aw = array_flip(wf_words($k['a']));
            if ($kw) foreach ($pw as [$w, $p]) {
                if (mb_strlen(trim($p['a'])) < 25) continue; // fragments like "لحد ما تفكي"
                $inter = count(array_intersect_key($kw, $w)); if (!$inter) continue;
                $qs = $inter / max(1, count($kw + $w));
                $as = $aw ? count(array_intersect_key($aw, array_flip(wf_words($p['a'])))) / max(1, count($aw + array_flip(wf_words($p['a'])))) : 0;
                if ($qs >= 0.34 || ($qs >= 0.18 && $as >= 0.3)) $add($p['a'], 'team', $p['time']);
            }
            $cur = substr(md5(wf_norm(mb_substr($k['a'], 0, 400))), 0, 10);
            $list = array_values(array_map(fn($key, $v) => $v + ['key' => $key, 'current' => $key === $cur], array_keys($vars), $vars));
            // Match % with the bot (AI) answer, agreement with the other team answers, frequency → score.
            $maxCount = max(1, max(array_column($list, 'count')));
            foreach ($list as $i => &$v) {
                $sim = fn($a, $b) => max(wf_sim($a, $b), wf_csim($a, $b));
                $v['match'] = $v['current'] ? 100 : (int) round($sim($v['text'], $k['a']) * 100);
                $others = array_filter($list, fn($o, $j) => $j !== $i && $o['from'] !== 'bot', ARRAY_FILTER_USE_BOTH);
                $v['agree'] = $others ? (int) round(array_sum(array_map(fn($o) => $sim($v['text'], $o['text']), $others)) / count($others) * 100) : 0;
                $v['score'] = (int) round(0.5 * $v['match'] + 0.3 * ($others ? $v['agree'] : $v['match']) + 0.2 * ($v['count'] / $maxCount * 100));
            }
            unset($v);
            // Recommended: when a real team answer is close to the bot's (≥ 60%), prefer the team's own wording — the best-scoring one.
            $best = null;
            foreach ($list as $i => $v) if (!$v['current'] && $v['from'] !== 'bot' && $v['match'] >= 60 && ($best === null || $v['score'] > $list[$best]['score'])) $best = $i;
            if ($best !== null) $list[$best]['recommended'] = true;
            foreach ($list as &$v) if ($v['current']) { $v['match'] = null; $v['score'] = null; } // the bot answer is the reference, not a candidate
            unset($v);
            usort($list, fn($a, $b) => [$b['current'], !empty($b['recommended']), $b['score']] <=> [$a['current'], !empty($a['recommended']), $a['score']]);
            $rec = null; foreach ($list as $v) if (!empty($v['recommended'])) $rec = $v;
            $rows[] = ['id' => $k['id'], 'topic' => $k['topic'] ?? '', 'q' => $k['q'], 'status' => $k['status'] ?? 'pending', 'sources' => $k['sources'] ?? 1, 'answers' => array_slice($list, 0, 8), 'variants' => count($list),
                'best_match' => max(array_map(fn($v) => $v['current'] ? 0 : $v['match'], $list) ?: [0]), 'recommended' => $rec ? ['key' => $rec['key'], 'score' => $rec['score'], 'match' => $rec['match']] : null];
        }
        usort($rows, fn($a, $b) => [$b['variants'], $b['sources']] <=> [$a['variants'], $a['sources']]);
        wi_json(['ok' => true, 'rows' => $rows]);
    }
    case 'kb_bulk': {
        $in = wf_post(); $aid = wf_aid(); $all = wi_read('learn_kb.json', []); $kb = $all[$aid] ?? []; $n = 0; $extra = [];
        $do = (string) ($in['do'] ?? '');
        if ($do === 'merge') { $extra['merged'] = wf_kb_merge($kb, (float) ($in['min'] ?? 0.55)); }
        elseif ($do === 'auto') { $c = wi_read('bot.json', []); $c[$aid]['auto_approve'] = max(0, min(50, (int) ($in['min'] ?? 0))); wi_write('bot.json', $c); $extra['approved'] = wf_kb_auto($kb, (int) $c[$aid]['auto_approve']); }
        else foreach (array_slice((array) ($in['ids'] ?? []), 0, 5000) as $id) {
            if (!isset($kb[$id])) continue; $n++;
            if ($do === 'delete') { unset($kb[$id]); continue; }
            if (in_array($do, ['approved', 'pending', 'disabled'], true)) $kb[$id]['status'] = $do;
            if ($do === 'topic') $kb[$id]['topic'] = mb_substr(trim((string) ($in['topic'] ?? '')), 0, 50);
            $kb[$id]['by'] = $_SESSION['email'];
        }
        $all[$aid] = $kb; wi_write('learn_kb.json', $all);
        wi_json(['ok' => true, 'changed' => $n, 'left' => count($kb)] + $extra);
    }
    case 'kb_set': {
        $in = wf_post(); $aid = wf_aid(); $all = wi_read('learn_kb.json', []); $id = (string) ($in['id'] ?? '');
        if (($in['do'] ?? '') === 'add') { $id = substr(md5(wf_norm((string) $in['q'])), 0, 12); $all[$aid][$id] = ['id' => $id, 'topic' => '', 'q' => '', 'a' => '', 'type' => 'faq', 'sources' => 0, 'status' => 'approved', 'at' => date('c')]; }
        if (!isset($all[$aid][$id])) wi_json(['ok' => false, 'error' => 'Not found.']);
        $k = &$all[$aid][$id];
        foreach (['q', 'a', 'topic'] as $f) if (isset($in[$f])) { $k[$f] = mb_substr(trim((string) $in[$f]), 0, $f === 'a' ? 3000 : 500); $k['edited'] = date('c'); }
        if (isset($in['status']) && in_array($in['status'], ['pending', 'approved', 'disabled'], true)) $k['status'] = $in['status'];
        if (!empty($in['use_alt']) && !empty($k['alt'])) { $k['a'] = $k['alt']; unset($k['alt']); }
        $k['by'] = $_SESSION['email']; unset($k);
        if (($in['do'] ?? '') === 'delete') unset($all[$aid][$id]);
        wi_write('learn_kb.json', $all); wi_json(['ok' => true]);
    }
    case 'bot_test': {
        $in = wf_post(); @set_time_limit(200);
        $text = trim((string) ($in['text'] ?? '')); if ($text === '') wi_json(['ok' => false, 'error' => 'Write a message.']);
        $prefer = preg_replace('/[^A-Za-z0-9_.:\/@-]+/', '', (string) ($in['prefer'] ?? ''));
        $who = (string) ($in['who'] ?? 'new');
        $card = $who === 'existing' ? ['new' => false, 'first' => date('c', time() - 30 * 86400), 'messages' => 40, 'groups' => ['(treatment group)'], 'programs' => ['(daily protection program)'], 'case' => '', 'customer' => '', 'name' => ''] : ['new' => true, 'first' => date('c'), 'messages' => 1, 'groups' => [], 'programs' => [], 'case' => '', 'customer' => '', 'name' => ''];
        wi_json(wf_bot_answer(wi_current(), $text, (array) ($in['history'] ?? []), $prefer, $card) + ['who' => $who]);
    }
    case 'bot_cfg': {
        $aid = wf_aid(); $all = wi_read('bot.json', []);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') { $in = wf_post(); wf_admin();
            $all[$aid] = ['model' => preg_replace('/[^A-Za-z0-9_.:\/@-]+/', '', (string) ($in['model'] ?? '')), 'tone' => in_array($in['tone'] ?? '', ['warm', 'short', 'formal'], true) ? $in['tone'] : 'warm',
                'name' => mb_substr(trim((string) ($in['name'] ?? '')), 0, 60), 'about' => mb_substr(trim((string) ($in['about'] ?? '')), 0, 500), 'rules' => mb_substr(trim((string) ($in['rules'] ?? '')), 0, 1500), 'fast' => !empty($in['fast'])]
                + array_intersect_key($all[$aid] ?? [], array_flip(['run', 'profile', 'profile_at', 'auto_approve']));
            // keep the Learning → Rules list in step: the box's lines are the switched-on rules, switched-off ones are kept
            $old = wi_read('bot.json', [])[$aid]['rules_list'] ?? null;
            if (is_array($old)) { $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $all[$aid]['rules'])))); $list = array_map(fn($l) => ['text' => $l, 'on' => true], $lines);
                foreach ($old as $o) if (empty($o['on']) && !in_array($o['text'], $lines, true)) $list[] = $o; $all[$aid]['rules_list'] = $list; }
            wi_write('bot.json', $all); }
        $list = wi_ai_call(['action' => 'ai_list'], 30); $models = [];
        foreach ($list['providers'] ?? [] as $p) {
            if (empty($p['enabled']) || empty($p['has_key'])) continue;
            foreach (array_slice($p['models'] ?? [], 0, 60) as $m) if ($p['plan'] === 'paid' || !empty($m['free'])) $models[] = ['id' => $p['id'] . ':' . $m['id'], 'label' => $p['name'] . ' · ' . $m['id'], 'meta' => (bool) preg_match('/llama|meta/i', $m['id'])];
        }
        $acc = wi_accounts()[$aid] ?? [];
        wi_json(['ok' => true, 'cfg' => wf_bot_cfg($aid), 'models' => $models, 'account' => $acc['name'] ?? $aid, 'profile' => (wi_read('bot.json', [])[$aid]['profile'] ?? ''), 'profile_at' => (wi_read('bot.json', [])[$aid]['profile_at'] ?? null)]);
    }
    case 'bot_profile': {
        // Detect who this number is from ITS OWN conversations (business, audience, language, tone).
        $in = wf_post(); @set_time_limit(200); $acc = wi_current(); $aid = (string) $acc['id'];
        $pairs = array_values(wf_pairs($aid)); shuffle($pairs); $ex = '';
        foreach (array_slice($pairs, 0, 40) as $p) $ex .= 'Customer: ' . mb_substr(preg_replace('/\s+/u', ' ', $p['q']), 0, 200) . "\nTeam: " . mb_substr(preg_replace('/\s+/u', ' ', $p['a']), 0, 300) . "\n---\n";
        if (count($pairs) < 15) {
            // Few learned exchanges: read the latest messages of its chats instead.
            $ch = wi_gateway('/archive/chats?months=' . (int) wi_sys('months_short') , null, $acc); $n = 0;
            foreach ($ch['chats'] ?? [] as $c) { if (!empty($c['group']) || $n >= 40) continue; $t = trim((string) ($c['last']['text'] ?? '')); if ($t === '') continue; $ex .= (($c['last']['dir'] ?? '') === 'out' ? 'Team' : 'Customer') . ': ' . mb_substr(preg_replace('/\s+/u', ' ', $t), 0, 250) . "\n"; $n++; }
            foreach (array_slice(array_filter($ch['chats'] ?? [], fn($c) => !empty($c['group'])), 0, 15) as $g) $ex .= 'Group: ' . ($g['name'] ?? '') . "\n";
        }
        if (trim($ex) === '') wi_json(['ok' => false, 'error' => 'No conversations on this number yet.']);
        $prompt = wf_prompt('profile_detect', (string) $acc['id'], ['name' => (string) ($acc['name'] ?? ''), 'messages' => mb_substr($ex, 0, 14000)]);
        $ai = wi_ai('Bot: detect this number’s business', $prompt, 180, ['subject' => $acc['name'] ?? $aid]);
        if (empty($ai['ok'])) wi_json(['ok' => false, 'error' => $ai['error'] ?? 'AI failed.']);
        $d = $ai['data'];
        $txt = trim((string) ($d['profile'] ?? '')) . "\n" . T_('Audience') . ': ' . ($d['audience'] ?? '') . "\n" . T_('Language') . ': ' . ($d['language'] ?? '') . "\n" . T_('Tone') . ': ' . ($d['tone'] ?? '')
            . (!empty($d['common']) ? "\n" . T_('Common requests') . ': ' . implode('، ', (array) $d['common']) : '') . (!empty($d['handoff']) ? "\n" . T_('Leave to a human') . ': ' . implode('، ', (array) $d['handoff']) : '');
        $all = wi_read('bot.json', []); $all[$aid]['profile'] = mb_substr($txt, 0, 2000); $all[$aid]['profile_at'] = date('c'); wi_write('bot.json', $all);
        wi_json(['ok' => true, 'profile' => $all[$aid]['profile'], 'task' => $ai['task']]);
    }
    case 'bot_run': {
        $aid = wf_aid(); $all = wi_read('bot.json', []);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') { $in = wf_post(); wf_admin();
            $mode = in_array($in['mode'] ?? '', ['off', 'draft', 'auto'], true) ? $in['mode'] : 'off';
            $old = $all[$aid]['run'] ?? [];
            $all[$aid]['run'] = ['mode' => $mode, 'min_conf' => max(0.5, min(0.99, (float) ($in['min_conf'] ?? 0.8))), 'from' => max(0, min(23, (int) ($in['from'] ?? 9))), 'to' => max(0, min(24, (int) ($in['to'] ?? 23))),
                'per_hour' => max(0, min(100000, (int) ($in['per_hour'] ?? 20))), 'human_pause' => max(0, min(72, (int) ($in['human_pause'] ?? 6))), 'human_wait' => max(0, min(240, (int) ($in['human_wait'] ?? ($old['human_wait'] ?? 10)))), 'dream_wait' => max(0, min(600, (int) ($in['dream_wait'] ?? ($old['dream_wait'] ?? 120)))), 'case_private' => array_key_exists('case_private', $in) ? !empty($in['case_private']) : ($old['case_private'] ?? true), 'case_private_new' => array_key_exists('case_private_new', $in) ? !empty($in['case_private_new']) : ($old['case_private_new'] ?? false),
                // protection against repeating and against WhatsApp blocks (each falls back to the saved value)
                'gather_secs' => max(0, min(600, (int) ($in['gather_secs'] ?? ($old['gather_secs'] ?? 45)))), 'quiet_secs' => max(3, min(120, (int) ($in['quiet_secs'] ?? ($old['quiet_secs'] ?? 6)))), 'chat_per_hour' => max(0, min(1000, (int) ($in['chat_per_hour'] ?? ($old['chat_per_hour'] ?? 12)))), 'group_person_hour' => max(0, min(1000, (int) ($in['group_person_hour'] ?? ($old['group_person_hour'] ?? 0)))),
                'repeat_sim' => max(0.4, min(1, (float) ($in['repeat_sim'] ?? ($old['repeat_sim'] ?? 0.7)))), 'repeat_retry' => array_key_exists('repeat_retry', $in) ? !empty($in['repeat_retry']) : ($old['repeat_retry'] ?? true),
                'max_clarify' => max(1, min(5, (int) ($in['max_clarify'] ?? ($old['max_clarify'] ?? 1)))), 'group_gap' => max(0, min(3600, (int) ($in['group_gap'] ?? ($old['group_gap'] ?? 120)))), 'stop_words' => mb_substr((string) ($in['stop_words'] ?? ($old['stop_words'] ?? 'وقف، توقف، كفاية، بس كده، stop')), 0, 1000),
                'typing_min' => max(0.5, min(20, (float) ($in['typing_min'] ?? ($old['typing_min'] ?? 2)))), 'typing_max' => max(1, min(30, (float) ($in['typing_max'] ?? ($old['typing_max'] ?? 8)))),
                'private_24h' => array_key_exists('private_24h', $in) ? !empty($in['private_24h']) : !empty($old['private_24h']), 'heal_24h' => array_key_exists('heal_24h', $in) ? !empty($in['heal_24h']) : ($old['heal_24h'] ?? null), 'crisis_pause_h' => max(0, min(168, (int) ($in['crisis_pause_h'] ?? ($old['crisis_pause_h'] ?? 12)))), 'warmup_until' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($in['warmup_until'] ?? '')) ? $in['warmup_until'] : (array_key_exists('warmup_until', $in) ? '' : ($old['warmup_until'] ?? '')), 'warmup_per_hour' => max(1, min(60, (int) ($in['warmup_per_hour'] ?? ($old['warmup_per_hour'] ?? 5)))),
                'test' => array_values(array_filter(array_map('wf_phone', preg_split('/[\s,،]+/u', (string) ($in['test'] ?? ''))), fn($p) => strlen($p) >= 10)),
                'scope' => in_array($in['scope'] ?? '', ['private', 'both', 'groups'], true) ? $in['scope'] : ($old['scope'] ?? 'private'),
                'groups_mode' => isset($in['groups_mode']) ? (($in['groups_mode'] ?? '') === 'all' ? 'all' : 'selected') : ($old['groups_mode'] ?? 'selected'),
                'group_default' => isset($in['group_default']) ? wf_clean_gcfg((array) $in['group_default']) : ($old['group_default'] ?? wf_clean_gcfg([])),
                'groups' => isset($in['groups']) ? array_map('wf_clean_gcfg', array_filter((array) $in['groups'], fn($v, $k) => str_ends_with((string) $k, '@g.us'), ARRAY_FILTER_USE_BOTH)) : ($old['groups'] ?? []),
                // Only messages that arrive after switching on are answered (never the backlog).
                'since' => ($old['mode'] ?? 'off') === 'off' && $mode !== 'off' ? date('c') : ($old['since'] ?? date('c')), 'by' => $_SESSION['email'], 'at' => date('c')];
            wi_write('bot.json', $all); wi_log('bot_run', ['account' => $aid, 'mode' => $mode]);
            $acc = wi_accounts()[$aid] ?? null; if ($acc) { $acc['autoreply'] = $mode === 'auto'; }
        }
        $L = wi_bot_log($aid);
        $dl = []; foreach (wf_bot_drafts($aid) as $dj => $dx) $dl[] = ['jid' => $dj, 'name' => $dx['name'] ?? '', 'q' => $dx['q'] ?? '', 'reply' => $dx['reply'] ?? '', 'why' => $dx['why'] ?? '', 'at' => $dx['at'] ?? '', 'group' => !empty($dx['group']), 'confidence' => $dx['confidence'] ?? null];
        usort($dl, fn($a, $b) => strcmp((string) $b['at'], (string) $a['at']));
        // Names, numbers and group names for the paused chats (WhatsApp often gives only an internal ID).
        $who = []; foreach ((wi_gateway('/archive/chats?months=' . (int) wi_sys('months_long') )['chats'] ?? []) as $ch) $who[$ch['jid']] = ['name' => (string) ($ch['name'] ?? ''), 'phone' => (string) ($ch['phone'] ?? ''), 'group' => !empty($ch['group'])];
        $pl = []; foreach (wf_bot_paused($aid) as $pj => $px) {
            $w = $who[$pj] ?? null;
            if (!$w || ($w['name'] === '' && $w['phone'] === '')) { $cc = wi_contact($pj); $w = ['name' => (string) ($cc['name'] ?? ($w['name'] ?? '')), 'phone' => (string) ($cc['phone'] ?? ''), 'group' => str_ends_with($pj, '@g.us')]; }
            $pl[] = ['jid' => $pj, 'name' => $w['name'], 'phone' => $w['phone'], 'group' => $w['group'], 'at' => $px['at'] ?? '', 'by' => $px['by'] ?? '', 'until' => $px['until'] ?? null];
        }
        foreach ($dl as &$d0) if (($d0['name'] ?? '') === '' && isset($who[$d0['jid']])) $d0['name'] = $who[$d0['jid']]['name']; unset($d0);
        wi_json(['drafts_list' => $dl, 'paused_list' => $pl, 'ok' => true, 'run' => wf_bot_run_cfg($aid), 'log' => array_slice(array_reverse($L), 0, 500), 'drafts' => count(wf_bot_drafts($aid)), 'account' => wi_accounts()[$aid]['name'] ?? $aid]);
    }
    case 'bot_chats': {
        // Every private chat (last 3 months) with the bot on/off for it.
        $aid = wf_aid(); $paused = wf_bot_paused($aid); $cfg = wf_bot_run_cfg($aid); $out = [];
        $team = []; try { $team = wf_team(wi_current(), wf_case_groups($aid)); } catch (Throwable $e) {}
        foreach ((wi_gateway('/archive/chats?months=' . (int) wi_sys('months_short') )['chats'] ?? []) as $ch) {
            if (str_ends_with((string) $ch['jid'], '@newsletter') || str_starts_with((string) $ch['jid'], '0@')) continue; // channels and WhatsApp's own account are not chats
            if (!empty($ch['group'])) { // groups: "on" = the bot works in this group (Groups settings)
                $out[] = ['jid' => $ch['jid'], 'name' => (string) ($ch['name'] ?? ''), 'phone' => '', 'group' => true, 'last' => $ch['last']['time'] ?? '', 'last_text' => mb_substr((string) ($ch['last']['text'] ?? ''), 0, 90),
                    'last_dir' => $ch['last']['dir'] ?? '', 'count' => (int) ($ch['count'] ?? 0), 'on' => wf_bot_group_cfg($cfg, (string) $ch['jid']) !== null && in_array($cfg['scope'] ?? 'private', ['both', 'groups'], true), 'paused_by' => '', 'team' => false, 'test' => false];
                continue; }
            $p = $paused[$ch['jid']] ?? null; $off = $p && (empty($p['until']) || strtotime((string) $p['until']) > time());
            $out[] = ['jid' => $ch['jid'], 'name' => (string) ($ch['name'] ?? ''), 'phone' => (string) ($ch['phone'] ?? ''), 'last' => $ch['last']['time'] ?? '', 'last_text' => mb_substr((string) ($ch['last']['text'] ?? ''), 0, 90),
                'last_dir' => $ch['last']['dir'] ?? '', 'count' => (int) ($ch['count'] ?? 0), 'on' => !$off, 'paused_by' => $off ? ($p['by'] ?? '') : '', 'team' => isset($team[(string) ($ch['phone'] ?? '')]), 'test' => in_array((string) ($ch['phone'] ?? ''), (array) $cfg['test'], true)];
        }
        // One row per person: WhatsApp can keep the same number as two chats (phone ID and internal ID) — merge them by number.
        $by = [];
        foreach ($out as $x) {
            $k = !empty($x['group']) ? 'g:' . $x['jid'] : ($x['phone'] !== '' ? 'p:' . $x['phone'] : 'j:' . $x['jid']);
            if (!isset($by[$k])) { $by[$k] = $x + ['jids' => [$x['jid']]]; continue; }
            $g = &$by[$k]; $g['jids'][] = $x['jid']; $g['count'] += $x['count']; $g['on'] = $g['on'] && $x['on']; if ($g['paused_by'] === '') $g['paused_by'] = $x['paused_by'];
            if ($g['name'] === '') $g['name'] = $x['name'];
            if (strcmp((string) $x['last'], (string) $g['last']) > 0) { foreach (['last', 'last_text', 'last_dir'] as $f) $g[$f] = $x[$f]; if ($x['name'] !== '') $g['name'] = $x['name']; $g['jid'] = $x['jid']; }
            unset($g);
        }
        wi_json(['ok' => true, 'chats' => array_values($by), 'mode' => $cfg['mode']]);
    }
    case 'bot_pause': {
        $aid = wf_aid();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') { $in = wf_post();
            // Several chats in ONE request (bulk actions): avoids dozens of parallel requests racing on the same file.
            if (!empty($in['jids']) && is_array($in['jids'])) { $n = 0; foreach (array_slice($in['jids'], 0, 2000) as $j) { $j = wf_jid((string) $j); if ($j === '') continue; wf_bot_pause($aid, $j, !empty($in['pause']), (string) $_SESSION['email']); $n++; } wi_json(['ok' => true, 'changed' => $n]); }
            wf_bot_pause($aid, wf_jid($in['jid'] ?? ''), !empty($in['pause']), (string) $_SESSION['email']); }
        $jid = wf_jid($_GET['jid'] ?? ($in['jid'] ?? ''));
        wi_json(['ok' => true, 'paused' => isset(wf_bot_paused($aid)[$jid]), 'mode' => wf_bot_run_cfg($aid)['mode']]);
    }
    case 'bot_team': {
        // Who the bot treats as the team: group admins (by name), our numbers, Inbox users, and people added by hand.
        $acc = wi_current(); $aid = (string) $acc['id'];
        if ($_SERVER['REQUEST_METHOD'] === 'POST') { $in = wf_post(); wf_admin(); $T = wi_read('team.json', []); $ph = wf_phone($in['phone'] ?? '');
            if ($ph !== '') { if (!empty($in['remove'])) unset($T[$aid][$ph]); else $T[$aid][$ph] = ['at' => date('c'), 'by' => $_SESSION['email'], 'name' => mb_substr(trim((string) ($in['name'] ?? '')), 0, 80)]; wi_write('team.json', $T); } }
        $c = wf_bot_run_cfg($aid); $groups = [];
        foreach ((array) $c['groups'] as $gj => $g) {
            $m = wi_gateway('/groups/members?jid=' . rawurlencode($gj), null, $acc); $adm = [];
            foreach ($m['members'] ?? [] as $x) if (!empty($x['admin'])) $adm[] = ['phone' => $x['phone'], 'name' => $x['name'] ?? '', 'role' => $x['admin'], 'hidden' => !empty($x['is_lid'])];
            $groups[] = ['jid' => $gj, 'name' => $g['name'] ?? ($m['name'] ?? $gj), 'admins' => $adm, 'error' => empty($m['ok']) ? ($m['error'] ?? '') : ''];
        }
        $own = []; foreach (wi_my_accounts() as $a) { $h = wi_gateway('/health', null, $a); $n = wf_phone(explode(':', (string) ($h['account'] ?? ''))[0]); if ($n !== '') $own[] = ['phone' => $n, 'name' => $a['name']]; }
        $users = []; foreach (wi_account_people(wi_current() ?: [], true) as $u) if (!empty($u['phone'])) $users[] = ['phone' => wf_phone($u['phone']), 'name' => $u['name']]; // only people of this number
        $manual = []; foreach ((array) (wi_read('team.json', [])[$aid] ?? []) as $ph => $x) $manual[] = ['phone' => (string) $ph, 'name' => $x['name'] ?? '', 'by' => $x['by'] ?? ''];
        wi_json(['ok' => true, 'groups' => $groups, 'own' => $own, 'users' => $users, 'manual' => $manual]);
    }
    case 'bot_group_set': {
        // One group's bot settings (from the group's own page or the groups table).
        $in = wf_post(); wf_admin(); $aid = wf_aid(); $jid = wf_jid($in['jid'] ?? '');
        if (!str_ends_with($jid, '@g.us')) wi_json(['ok' => false, 'error' => 'Not a group.']);
        $all = wi_read('bot.json', []); $run = $all[$aid]['run'] ?? [];
        if (empty($in['on'])) unset($run['groups'][$jid]);
        else $run['groups'][$jid] = wf_clean_gcfg(((array) ($in['cfg'] ?? [])) + ($run['groups'][$jid] ?? []) + (array) ($run['group_default'] ?? []) + ['name' => (string) ($in['name'] ?? '')]);
        if (!empty($in['on']) && ($run['scope'] ?? 'private') === 'private') $run['scope'] = 'both'; // a group switched on: groups must be part of the scope
        $all[$aid]['run'] = $run; wi_write('bot.json', $all);
        wi_log('bot_group_set', ['account' => $aid, 'jid' => $jid, 'on' => !empty($in['on'])]);
        wi_json(['ok' => true, 'group' => $run['groups'][$jid] ?? null, 'scope' => $run['scope'] ?? 'private']);
    }
    case 'bot_group': {
        // Everything for one group's page: settings, stats, its bot log.
        $acc = wi_current(); $aid = (string) $acc['id']; $jid = wf_jid($_GET['jid'] ?? '');
        $c = wf_bot_run_cfg($aid); $g = $c['groups'][$jid] ?? null;
        $m = wi_gateway('/groups/members?jid=' . rawurlencode($jid), null, $acc);
        $L = array_values(array_filter(wi_bot_log($aid), fn($x) => ($x['jid'] ?? '') === $jid));
        $sent = count(array_filter($L, fn($x) => in_array($x['result'] ?? '', ['sent', 'handoff'], true)));
        wi_json(['ok' => true, 'jid' => $jid, 'name' => $m['name'] ?? ($g['name'] ?? $jid), 'on' => (bool) $g, 'cfg' => $g ?: (array) $c['group_default'], 'scope' => $c['scope'], 'mode' => $c['mode'], 'groups_mode' => $c['groups_mode'],
            'members' => count($m['members'] ?? []), 'admins' => array_values(array_map(fn($x) => ['name' => $x['name'] ?? '', 'phone' => $x['phone']], array_filter($m['members'] ?? [], fn($x) => !empty($x['admin'])))),
            'log' => array_reverse(array_slice($L, -300)), 'stats' => ['answers' => $sent, 'drafts' => count(array_filter($L, fn($x) => ($x['result'] ?? '') === 'draft')), 'total' => count($L)]]);
    }
    case 'bot_draft': {
        $aid = wf_aid(); $jid = wf_jid($_GET['jid'] ?? ($_POST['jid'] ?? ''));
        if ($_SERVER['REQUEST_METHOD'] === 'POST') { $in = wf_post(); wi_update('bot_drafts.json', function ($d) use ($aid, $in) { foreach ((array) ($in['jids'] ?? []) as $j) unset($d[$aid][wf_jid((string) $j)]); unset($d[$aid][wf_jid($in['jid'] ?? '')]); return $d; }); wi_json(['ok' => true]); }
        wi_json(['ok' => true, 'draft' => wf_bot_drafts($aid)[$jid] ?? null]);
    }
    case 'bot_eval': {
        // One real past customer message: what the bot would say next to what the team actually said.
        $in = wf_post(); @set_time_limit(200); $acc = wi_current(); $aid = (string) $acc['id'];
        $pairs = array_values(wf_pairs($aid)); if (!$pairs) wi_json(['ok' => false, 'error' => 'Collect the conversations first (step 1).']);
        $p = $pairs[random_int(0, count($pairs) - 1)];
        $r = wf_bot_answer($acc, $p['q']);
        wi_json($r + ['question' => $p['q'], 'actual' => $p['a'], 'pair' => $p['id'], 'time' => $p['time']]);
    }
    case 'bot_rate': {
        $in = wf_post(); $aid = wf_aid(); $all = wi_read('bot_ratings.json', []);
        $all[$aid][] = ['at' => date('c'), 'by' => $_SESSION['email'], 'rating' => ($in['rating'] ?? '') === 'good' ? 'good' : 'bad', 'q' => mb_substr((string) ($in['q'] ?? ''), 0, 1500), 'bot' => mb_substr((string) ($in['bot'] ?? ''), 0, 3000), 'fix' => mb_substr((string) ($in['fix'] ?? ''), 0, 3000)];
        // A corrected answer becomes approved knowledge right away.
        if (trim((string) ($in['fix'] ?? '')) !== '' && trim((string) ($in['q'] ?? '')) !== '') { $kb = wi_read('learn_kb.json', []); $id = substr(md5(wf_norm((string) $in['q'])), 0, 12); $kb[$aid][$id] = ['id' => $id, 'topic' => (string) ($in['topic'] ?? ''), 'q' => mb_substr((string) $in['q'], 0, 500), 'a' => mb_substr((string) $in['fix'], 0, 3000), 'type' => 'faq', 'sources' => 1, 'status' => 'approved', 'edited' => date('c'), 'by' => $_SESSION['email'], 'at' => date('c')]; wi_write('learn_kb.json', $kb); }
        wi_write('bot_ratings.json', $all); wi_json(['ok' => true]);
    }
    case 'registry_group': {
        $in = wf_post(); wf_admin(); $aid = wf_aid(); $all = wi_read('registry_groups.json', []); $jid = wf_jid($in['jid'] ?? '');
        if (!str_ends_with($jid, '@g.us')) wi_json(['ok' => false, 'error' => 'Not a group.']);
        if (!empty($in['on'])) $all[$aid][$jid] = ['name' => mb_substr((string) ($in['name'] ?? ''), 0, 120), 'since' => date('c'), 'by' => $_SESSION['email']]; else unset($all[$aid][$jid]);
        wi_write('registry_groups.json', $all); wi_log('registry_group', ['account' => $aid, 'jid' => $jid, 'on' => !empty($in['on'])]);
        if (!empty($in['on'])) { @set_time_limit(200); wf_registry_scan(wi_current()); }
        wi_json(['ok' => true]);
    }
    case 'registry': {
        $acc = wi_current(); $aid = (string) $acc['id'];
        if (!empty($_GET['scan'])) { @set_time_limit(200); wf_registry_scan($acc); }
        $list = array_values(wi_read('registry.json', [])[$aid] ?? []);
        $cases = wf_cases($aid); $labels = [];
        foreach ($list as &$e) { foreach ($e['labels'] as $l) $labels[$l] = ($labels[$l] ?? 0) + 1; $ph = $e['phones'][0] ?? ''; $e['case'] = isset($cases[$ph]) ? $ph : null; $e['case_status'] = $cases[$ph]['status'] ?? null; }
        unset($e); arsort($labels);
        wi_json(['ok' => true, 'entries' => $list, 'labels' => $labels, 'groups' => wf_registry_groups($aid), 'pending_ai' => count(array_filter($list, fn($e) => empty($e['ai'])))]);
    }
    case 'registry_set': {
        $in = wf_post(); $acc = wi_current(); $aid = (string) $acc['id'];
        $all = wi_read('registry.json', []); $ids = array_slice(array_map('strval', (array) ($in['ids'] ?? [])), 0, 2000); $n = 0; $followed = [];
        $add = trim(mb_substr((string) ($in['add_label'] ?? ''), 0, 40)); $rm = (string) ($in['remove_label'] ?? '');
        foreach ($ids as $id) {
            if (!isset($all[$aid][$id])) continue;
            $e = &$all[$aid][$id];
            if ($add !== '') $e['labels'] = array_values(array_unique(array_merge($e['labels'] ?? [], [$add])));
            if ($rm !== '') $e['labels'] = array_values(array_diff($e['labels'] ?? [], [$rm]));
            if (isset($in['note'])) $e['note'] = mb_substr((string) $in['note'], 0, 2000);
            if (!empty($in['follow'])) { $e['followed'] = date('c'); $followed[] = $id; }
            $n++; unset($e);
        }
        wi_write('registry.json', $all);
        $cases = [];
        foreach ($followed as $id) { $ph = wf_registry_follow($acc, $all[$aid][$id]); if ($ph) $cases[] = $ph; }
        if ($cases) { @set_time_limit(300); foreach (array_slice($cases, 0, 20) as $ph) wf_case_snapshot($acc, $ph); }
        // Labels of followed entries also go on the case.
        if ($add !== '' || $rm !== '') { $lab = []; foreach ($ids as $id) { $ph = $all[$aid][$id]['phones'][0] ?? ''; if ($ph !== '') $lab[$ph] = $all[$aid][$id]['labels']; } // the reports' labels go to their cases
            wf_cases_update($aid, function (array $C) use ($lab) { foreach ($lab as $ph => $l) if (isset($C[$ph])) $C[$ph]['labels'] = $l; return $C; }); }
        wi_log('registry_set', ['account' => $aid, 'count' => $n, 'followed' => count($cases)]);
        wi_json(['ok' => true, 'changed' => $n, 'cases' => $cases]);
    }
    case 'registry_ai': {
        $in = wf_post(); @set_time_limit(240); $acc = wi_current(); $aid = (string) $acc['id'];
        $all = wi_read('registry.json', []); $list = $all[$aid] ?? [];
        $want = array_map('strval', (array) ($in['ids'] ?? []));
        $todo = array_values(array_filter($list, fn($e) => $want ? in_array($e['id'], $want, true) && empty($e['ai']['at_force']) : empty($e['ai'])));
        $total = count(array_filter($list, fn($e) => !empty($e['ai']))) + count($todo);
        if (!$todo) wi_json(['ok' => true, 'done' => 0, 'remaining' => 0, 'total' => $total]);
        $batch = array_slice($todo, 0, 8); $txt = '';
        foreach ($batch as $e) $txt .= '### id=' . $e['id'] . ' phones=' . implode(',', $e['phones']) . ' voice_notes=' . $e['voice'] . "\n" . mb_substr($e['text'], 0, 1500) . "\n\n";
        $prompt = wf_prompt('registry_extract', (string) $acc['id'], ['reports' => $txt]);
        $ai = wi_ai('Registry: extract cases', $prompt, 220, ['subject' => count($batch) . ' entries', 'progress' => ['done' => $total - count($todo), 'total' => $total]]);
        if (empty($ai['ok']) || !is_array($ai['data']['items'] ?? null)) wi_json(['ok' => false, 'error' => $ai['error'] ?? 'AI returned no result.']);
        $n = 0;
        foreach ($ai['data']['items'] as $x) {
            $id = (string) ($x['id'] ?? ''); if (!isset($all[$aid][$id])) continue;
            $all[$aid][$id]['ai'] = array_intersect_key($x, array_flip(['name', 'phone', 'gender', 'age', 'country', 'complaint', 'symptoms', 'diagnosis', 'summary', 'labels'])) + ['at' => date('c'), 'model' => $ai['task']['model'] ?? ''];
            foreach ((array) ($x['labels'] ?? []) as $l) { $l = trim(mb_substr((string) $l, 0, 30)); if ($l !== '') $all[$aid][$id]['labels'][] = $l; }
            $all[$aid][$id]['labels'] = array_values(array_unique($all[$aid][$id]['labels'] ?? []));
            $n++;
        }
        foreach ($batch as $e) if (empty($all[$aid][$e['id']]['ai'])) $all[$aid][$e['id']]['ai'] = ['skipped' => true, 'at' => date('c')];
        wi_write('registry.json', $all);
        wi_json(['ok' => true, 'done' => $n, 'remaining' => max(0, count($todo) - count($batch)), 'total' => $total, 'task' => $ai['task']]);
    }
    case 'group_monitor': {
        $in = wf_post(); wf_admin();
        $aid = wf_aid(); $all = wi_read('case_groups.json', []); $jid = wf_jid($in['jid'] ?? '');
        if (!str_ends_with($jid, '@g.us')) wi_json(['ok' => false, 'error' => 'Not a group.']);
        if (!empty($in['on'])) $all[$aid][$jid] = ['name' => mb_substr((string) ($in['name'] ?? ''), 0, 120), 'since' => date('c'), 'by' => $_SESSION['email']];
        else unset($all[$aid][$jid]);
        wi_write('case_groups.json', $all); wi_log('group_monitor', ['account' => $aid, 'jid' => $jid, 'on' => !empty($in['on'])]);
        if (!empty($in['on'])) { @set_time_limit(200); wf_cases_sync(wi_current()); }
        wi_json(['ok' => true]);
    }

    }
}
