<?php
/* ---------- Bot roles: one bot on the number, several hats ----------
 * The regular bot answers by default. A message that fits a role (defined by the business type)
 * is answered with that role's instructions, and the chat keeps the role while the talk stays on it.
 * Safety first: words that show danger (self-harm, suicide, violence) stop the bot in that chat, send a fixed
 * support message and alert the team. Roles, their words, instructions and models come from the business profile
 * (preset "roles") and are changed per number in Bot → Roles. */

function wf_roles_cfg(string $aid): array {
    $p = wf_pack($aid); $own = (array) (wi_read('bot_roles.json', [])[$aid] ?? []); $roles = [];
    foreach ((array) ($p['roles'] ?? []) as $k => $r) $roles[$k] = ((array) ($own['roles'][$k] ?? [])) + (array) $r;
    $crisis = ((array) ($own['crisis'] ?? [])) + (array) ($p['crisis'] ?? []) + ['on' => 1, 'words' => '', 'alert' => ''];
    return ['common' => (string) ($own['common'] ?? ($p['roles_common'] ?? '')), 'group_role' => (string) ($own['group_role'] ?? ($p['group_role'] ?? '')), 'on' => (int) ($own['on'] ?? (!empty($roles) ? 1 : 0)), 'sticky_h' => (int) ($own['sticky_h'] ?? 6), 'strong' => (string) ($own['strong'] ?? 'anthropic,opencode_go,gemini'), 'roles' => $roles, 'crisis' => $crisis];
}
function wf_roles_words(string $list): array { return array_values(array_filter(array_map(fn($w) => trim(wf_intake_norm($w)), preg_split('/[\n,\x{060C}]+/u', $list)))); }
/** A short word (3 letters or less, like "مس" or "عين") must stand alone; a longer one may be part of a word. */
function wf_roles_hit(string $text, string $list): bool { $t = ' ' . preg_replace('/[^\p{L}\p{N}]+/u', ' ', wf_intake_norm($text)) . ' ';
    foreach (wf_roles_words($list) as $w) if (mb_strpos($t, mb_strlen($w) <= 3 ? ' ' . $w . ' ' : $w) !== false) return true; return false; }

/** Which hat answers this message: 'crisis', a role key, or 'bot'. The chat keeps its role for a few hours. */
function wf_role_pick(string $aid, string $jid, string $text, string $fallback = 'bot'): string {
    $c = wf_roles_cfg($aid); if (empty($c['on'])) return 'bot';
    if (!empty($c['crisis']['on']) && wf_roles_hit($text, (string) $c['crisis']['words'])) return 'crisis';
    $st = (array) (wi_read('bot_roles_state.json', [])[$aid][$jid] ?? []); $pick = '';
    foreach ($c['roles'] as $k => $r) if (!empty($r['on']) && trim((string) ($r['words'] ?? '')) !== '' && wf_roles_hit($text, (string) ($r['words'] ?? ''))) { $pick = $k; break; }
    if ($pick === '' && !empty($st['role']) && isset($c['roles'][$st['role']]) && !empty($c['roles'][$st['role']]['on']) && strtotime((string) $st['at']) > time() - $c['sticky_h'] * 3600) $pick = (string) $st['role'];
    if ($pick === '') $pick = $fallback !== 'bot' && !empty($c['roles'][$fallback]['on']) ? $fallback : '';
    if ($pick === '') return 'bot';
    $first = ($st['role'] ?? '') !== $pick || empty($st['intro']);
    wi_update('bot_roles_state.json', function ($S) use ($aid, $jid, $pick, $first) { $S[$aid][$jid] = ['role' => $pick, 'at' => date('c'), 'intro' => $first ? 0 : 1]; return $S; });
    return $pick;
}
/** The role's instructions for the reply prompt (put before the owner's rules), and the model to prefer. */
function wf_role_prompt(string $aid, string $jid, string $role): array {
    $c = wf_roles_cfg($aid); $r = (array) ($c['roles'][$role] ?? []);
    if (!$r) return ['rules' => !empty($c['on']) && trim((string) $c['common']) !== '' ? "RULES:\n" . trim((string) $c['common']) . "\n\n" : '', 'prefer' => '']; // the regular bot keeps the same no-invention / no-repeat rules
    $st = (array) (wi_read('bot_roles_state.json', [])[$aid][$jid] ?? []);
    $rules = "YOUR ROLE IN THIS CHAT: " . ($r['label'] ?? $role) . ".\n" . trim((string) ($r['rules'] ?? '')) . "\n"
        . (empty($st['intro']) && trim((string) ($r['intro'] ?? '')) !== '' ? "- This is your first reply in this role: start it with this note, in the customer's dialect: " . trim((string) $r['intro']) . "\n" : '')
        . "- If the matter is beyond this role or the person needs a human, set handoff=true.\n" . trim((string) $c['common']) . "\n\n";
    if (empty($st['intro']) && $jid !== '') wi_update('bot_roles_state.json', function ($S) use ($aid, $jid) { if (isset($S[$aid][$jid])) $S[$aid][$jid]['intro'] = 1; return $S; });
    $fast = !empty(wf_bot_cfg($aid)['fast']) && function_exists('wf_fast_model'); // ⚡ fast replies on: speed first, the quick model of the strong provider
    return ['rules' => $rules, 'prefer' => !empty($r['strong']) ? ($fast ? wf_fast_model() : wf_role_strong_model($aid, (string) $c['strong'])) : ''];
}
/** The first strong provider that is on and has a key (OpenCode Go, then Google Gemini by default), as "provider:model". */
function wf_role_strong_model(string $aid, string $order): string {
    try { require_once __DIR__ . '/lib/WiAiProviders.php'; $P = (new WiAiProviders(wi_ai_scope()))->providers(); } catch (Throwable $e) { return ''; }
    foreach (array_filter(array_map('trim', explode(',', $order))) as $id) { $p = $P[$id] ?? null;
        if ($p && !empty($p['enabled']) && ($p['api_key'] !== '' || !empty($p['key_optional'])) && (string) $p['model'] !== '') return $id . ':' . $p['model']; }
    return '';
}
/** Danger in the message: the bot stops in this chat, sends the fixed support message and alerts the team at once. */
function wf_role_crisis(array $acc, string $jid, string $text, string $name, string $quote = '', string $who = ''): void {
    $aid = (string) $acc['id']; $c = wf_roles_cfg($aid)['crisis'];
    wi_gateway('/chat/send', ['jid' => $jid, 'text' => wf_msg('crisis_reply', ['name' => $name]), 'by' => 'bot', 'via' => 'bot'] + ($quote !== '' ? ['quote_id' => $quote] : []), $acc);
    $phone = $who !== '' ? $who : explode('@', $jid)[0];
    $to = (string) ($c['alert'] ?? '') ?: (function_exists('wf_intake_diag') ? wf_intake_diag($aid) : '');
    if ($to !== '') wi_gateway('/chat/send', ['jid' => $to, 'text' => wf_msg('crisis_alert', ['name' => $name ?: '+' . $phone, 'phone' => '+' . $phone, 'text' => mb_substr($text, 0, 300)]), 'via' => 'bot'], $acc);
    if (!str_ends_with($jid, '@g.us')) wf_bot_pause($aid, $jid, true, 'crisis — a person must answer', (int) (wf_bot_run_cfg($aid)['crisis_pause_h'] ?? 12)); // the team has these hours; 0 = until someone writes /start // a group keeps its bot; the person is followed up privately
    wi_log('bot_crisis', ['account' => $aid, 'jid' => $jid, 'alerted' => $to !== '']);
}

/** A role conversation goes into the person's case file (when they have one): the dream and its interpretation, the support talk… */
function wf_role_note(string $aid, string $phone, string $role, string $q, string $reply, string $where): void {
    $phone = preg_replace('/\D/', '', $phone); if ($phone === '' || in_array($role, ['bot', 'teacher', 'crisis'], true)) return;
    $r = (array) (wf_roles_cfg($aid)['roles'][$role] ?? []); if (!$r) return;
    wf_case_update($aid, $phone, function (?array $c) use ($r, $q, $reply, $where) { if ($c === null) return null;
        $c['notes'][] = ['at' => date('c'), 'by' => 'bot', 'text' => trim(($r['icon'] ?? '') . ' ' . wi_t((string) ($r['label'] ?? '')) . ($where !== '' ? ' · ' . $where : '')) . "\n❓ " . mb_substr($q, 0, 600) . "\n💬 " . mb_substr($reply, 0, 900)];
        return $c; });
}
/** A healing talk fills the person's case file: the AI reads what they wrote (name, age, country, symptoms, story…) into the empty fields only.
 *  Runs when the person has a case and wrote something substantial; never overwrites what the team entered. */
function wf_role_fill_case(string $aid, string $phone, array $texts): array {
    $phone = preg_replace('/\D/', '', $phone); if ($phone === '' || !function_exists('wf_intake_extract')) return [];
    $case = wf_case_get($aid, $phone); if (!$case) return [];
    $all = trim(implode("\n", array_slice(array_filter(array_map('trim', $texts)), -10))); if (mb_strlen($all) < 40) return [];
    $x = wf_intake_extract($aid, $all); $got = array_diff_key((array) ($x['fields'] ?? []), ['phone' => 1]); if (!$got) return [];
    $added = [];
    wf_case_update($aid, $phone, function (?array $c) use ($got, &$added) { if ($c === null) return null;
        foreach ($got as $k => $v) { if ($k === 'name') { if (trim((string) ($c['name'] ?? '')) === '') { $c['name'] = $v; $added[] = $k; } continue; }
            if (trim(is_array($c['data'][$k] ?? '') ? implode(' ', $c['data'][$k]) : (string) ($c['data'][$k] ?? '')) === '') { $c['data'][$k] = $v; $added[] = $k; } }
        if ($added) $c['notes'][] = ['at' => date('c'), 'by' => 'bot', 'text' => '🤖 ' . implode("\u{060C} ", $added)];
        return $c; });
    return $added;
}
/** Replies by role over the last days, from the bot log, and the danger alerts. */
function wf_role_stats(string $aid, int $days = 7): array {
    $since = date('c', time() - $days * 86400); $out = [];
    foreach (wi_bot_log($aid) as $l) { if ((string) ($l['at'] ?? '') < $since) continue; $k = (string) ($l['role'] ?? 'bot');
        $out[$k] = ($out[$k] ?? ['replies' => 0, 'sent' => 0, 'handoff' => 0]); $out[$k]['replies']++; if (in_array($l['result'] ?? '', ['sent', 'handoff'], true)) $out[$k]['sent']++; if (!empty($l['handoff'])) $out[$k]['handoff']++; }
    return $out;
}
function wf_api_bot_roles(string $api): void {
    if ($api !== 'bot_roles') return;
    $aid = wf_aid(); if ($aid === '') wi_json(['ok' => false, 'error' => 'No number.']);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') { $in = wf_post(); wf_admin(); $base = (array) (wf_pack($aid)['roles'] ?? []); $x = ['on' => !empty($in['on']) ? 1 : 0, 'sticky_h' => max(1, min(72, (int) ($in['sticky_h'] ?? 6))),
            'strong' => preg_replace('/[^a-z0-9_,]/', '', (string) ($in['strong'] ?? 'opencode_go,gemini')), 'group_role' => preg_replace('/[^a-z0-9_]/', '', (string) ($in['group_role'] ?? '')), 'common' => mb_substr((string) ($in['common'] ?? ''), 0, 3000), 'roles' => [], 'crisis' => ['on' => !empty($in['crisis']['on']) ? 1 : 0, 'words' => mb_substr((string) ($in['crisis']['words'] ?? ''), 0, 3000), 'alert' => str_ends_with($a = wf_jid($in['crisis']['alert'] ?? ''), '@g.us') || str_ends_with($a, '@s.whatsapp.net') ? $a : '']];
        foreach ($base as $k => $r) { $v = (array) ($in['roles'][$k] ?? []); $x['roles'][$k] = ['on' => !empty($v['on']) ? 1 : 0, 'strong' => !empty($v['strong']) ? 1 : 0,
            'words' => mb_substr((string) ($v['words'] ?? ''), 0, 2000), 'rules' => mb_substr((string) ($v['rules'] ?? ''), 0, 4000), 'intro' => mb_substr((string) ($v['intro'] ?? ''), 0, 500)]; }
        wi_update('bot_roles.json', function ($R) use ($aid, $x) { $R[$aid] = $x; return $R; }); wi_log('bot_roles', ['account' => $aid, 'on' => $x['on']]);
        wi_json(['ok' => true, 'cfg' => wf_roles_cfg($aid)]); }
    wi_json(['ok' => true, 'cfg' => wf_roles_cfg($aid), 'strong_now' => wf_role_strong_model($aid, wf_roles_cfg($aid)['strong']), 'stats' => wf_role_stats($aid)]);
}
