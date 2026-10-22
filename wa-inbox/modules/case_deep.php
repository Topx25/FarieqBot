<?php
// WhatsApp Inbox — case_deep: "#تحليل" — a case looked at from each angle of the business (healing: spiritual, health, mental),
// how far the treatment got (stage by stage) and the trend over time. Every analysis is kept, so the follow-up page shows the change.

/** The angles of this business (business profile → analysis_axes, "|" between them); empty = the feature is off for this type. */
function wf_deep_axes(string $aid): array { return array_values(array_filter(array_map('trim', explode('|', (string) (wf_pack($aid)['analysis_axes'] ?? ''))))); }
/** Runs the analysis of one case and keeps it (the last 12). Returns the analysis, or ['error' => …]. */
function wf_case_deep(array $acc, string $ph, string $by = ''): array {
    $aid = (string) $acc['id']; $axes = wf_deep_axes($aid); if (!$axes) return ['error' => 'Analysis is not set for this business type.'];
    $case = wf_case_get($aid, $ph); if (!$case) return ['error' => 'Case not found.'];
    $conv = ''; foreach (wf_case_messages($acc, $ph) as $m) { $t = trim((string) ($m['text'] ?? '')); if ($t === '') continue;
        $conv .= '[' . substr((string) $m['time'], 0, 10) . '] ' . (($m['dir'] ?? '') === 'out' || !empty($m['by']) ? wf_pack($aid)['staff'] : wf_pack($aid)['client']) . ': ' . mb_substr(preg_replace('/\s+/u', ' ', $t), 0, 500) . "\n"; }
    if ($conv === '') return ['error' => 'No messages from this person yet.'];
    $st = (array) (wf_case_cfg($aid)['statuses'] ?? []); $stages = []; foreach ((array) ($case['status_log'] ?? []) as $l) $stages[] = substr((string) ($l['at'] ?? ''), 0, 10) . ' → ' . (string) ($st[$l['to'] ?? ''] ?? ($l['to'] ?? ''));
    $prog = []; foreach ((array) (wi_read('enrollments.json', [])[$aid] ?? []) as $e) if (preg_replace('/\D/', '', explode('@', (string) ($e['jid'] ?? ''))[0]) === $ph) $prog[] = (string) ($e['program_name'] ?? $e['program_id'] ?? '') . ' (' . (string) ($e['status'] ?? '') . ', day ' . (int) ($e['day'] ?? 0) . ')';
    $dl = (array) ($case['deep'] ?? []); $prev = $dl ? (array) end($dl) : [];
    $ai = wi_ai('Case analysis (deep)', wf_prompt('case_deep', $aid, ['axes' => implode(', ', $axes), 'stages' => implode("\n", $stages) ?: '-', 'programs' => implode("\n", $prog) ?: '-',
        'previous' => $prev ? json_encode(array_intersect_key($prev, array_flip(['at', 'progress', 'stage', 'axes'])), JSON_UNESCAPED_UNICODE) : '-', 'notes' => mb_substr(json_encode((array) ($case['data'] ?? []), JSON_UNESCAPED_UNICODE), 0, 2500), 'messages' => mb_substr($conv, -24000)]), 210, ['subject' => '+' . $ph]);
    $d = is_array($ai['data'] ?? null) ? $ai['data'] : null; if (empty($ai['ok']) || !$d) return ['error' => $ai['error'] ?? 'AI failed.'];
    $a = ['at' => date('c'), 'by' => $by, 'summary' => mb_substr((string) ($d['summary'] ?? ''), 0, 1200), 'axes' => [], 'stage' => mb_substr((string) ($d['stage'] ?? ''), 0, 120), 'progress' => max(0, min(100, (int) ($d['progress'] ?? 0))),
        'trend' => in_array($d['trend'] ?? '', ['better', 'same', 'worse'], true) ? $d['trend'] : 'unknown', 'stages' => [], 'next_step' => mb_substr((string) ($d['next_step'] ?? ''), 0, 400), 'red_flags' => mb_substr((string) ($d['red_flags'] ?? ''), 0, 400), 'model' => trim(($ai['provider'] ?? '') . ' ' . ($ai['model'] ?? ''))];
    foreach ((array) ($d['axes'] ?? []) as $x) if (is_array($x)) $a['axes'][] = ['name' => mb_substr((string) ($x['name'] ?? ''), 0, 40), 'score' => max(0, min(10, (int) ($x['score'] ?? 0))), 'state' => mb_substr((string) ($x['state'] ?? ''), 0, 300)];
    foreach ((array) ($d['stages'] ?? []) as $x) if (is_array($x)) $a['stages'][] = ['name' => mb_substr((string) ($x['name'] ?? ''), 0, 80), 'done' => !empty($x['done']), 'note' => mb_substr((string) ($x['note'] ?? ''), 0, 200)];
    wf_case_update($aid, $ph, function (?array $c) use ($a) { if ($c === null) return null; $c['deep'] = array_slice(array_merge((array) ($c['deep'] ?? []), [$a]), -12); return $c; });
    wi_log('case_deep', ['account' => $aid, 'phone' => $ph]);
    return $a;
}
/** The analysis as a WhatsApp message. */
function wf_case_deep_text(array $case, array $a): string {
    $tr = ['better' => '📈', 'same' => '➖', 'worse' => '📉', 'unknown' => '❔'][$a['trend']] ?? '';
    $ax = implode("\n", array_map(fn($x) => '• ' . $x['name'] . ': ' . str_repeat('●', (int) round($x['score'] / 2)) . str_repeat('○', 5 - (int) round($x['score'] / 2)) . ' ' . $x['score'] . '/10 — ' . $x['state'], $a['axes']));
    $sg = implode("\n", array_map(fn($x) => ($x['done'] ? '✅ ' : '⬜ ') . $x['name'] . ($x['note'] !== '' ? ' — ' . $x['note'] : ''), $a['stages']));
    return wf_msg('case_deep_msg', ['name' => (string) ($case['name'] ?? '') ?: ((string) ($case['data']['name'] ?? '') ?: '+' . (string) ($case['phone'] ?? '')), 'no' => (string) ($case['no'] ?? ''), 'summary' => $a['summary'], 'axes' => $ax, 'stage' => $a['stage'], 'progress' => (string) $a['progress'], 'trend' => $tr, 'stages' => $sg, 'next' => $a['next_step'], 'flags' => $a['red_flags'] !== '' ? "\n⚠️ " . $a['red_flags'] : '']);
}
/** "#تحليل" + ID, name or a tag: the analysis of that case. Returns the message to send, or '' when the text is not the command. */
/** Is this text the analysis command? */
function wf_case_deep_is(string $aid, string $text): bool { $w = trim((string) (wf_pack($aid)['analyze_cmd'] ?? '')); return $w !== '' && wf_deep_axes($aid) && wf_intake_has(mb_substr(trim($text), 0, mb_strlen($w) + 2), $w); }
function wf_case_deep_command(array $acc, string $text, array $mentions = [], string $by = '', string $chatJid = '', string $waitTo = '', array $waitMentions = []): string {
    $aid = (string) $acc['id']; if (!wf_case_deep_is($aid, $text)) return ''; $w = trim((string) wf_pack($aid)['analyze_cmd']); $t = trim($text);
    $grp = str_ends_with($waitTo, '@g.us'); // asked in a group: private-chat cases stay out of it
    $q = trim(mb_substr($t, mb_strlen($w))); $hit = ($q !== '' || $mentions) ? wf_intake_find($aid, $q, $mentions, $acc, $grp) : [];
    // the command alone in a person's private chat: that person
    if (!$hit && $q === '' && $chatJid !== '' && !str_ends_with($chatJid, '@g.us')) { $cp = wf_phone(explode('@', $chatJid)[0]); if (str_ends_with($chatJid, '@lid')) $cp = wf_phone((string) (wi_contact($chatJid, $acc)['phone'] ?? '')); if ($cp !== '') { $q = $cp; $hit = wf_intake_find($aid, $cp, [], $acc, $grp); } }
    // a number that has no case yet but has a chat: its case is opened now, then analysed
    $dg = wf_phone(preg_replace('/[^\d+]/', '', $q)); if (!$hit && !$grp && strlen($dg) >= 9 && !wf_case_get($aid, $dg) && wf_case_messages($acc, $dg)) { $nm = (string) (wi_contact($dg . '@s.whatsapp.net', $acc)['name'] ?? '');
        wf_case_reserve_no($aid, $dg, ['phone' => $dg, 'name' => $nm, 'status' => 'new', 'created' => date('c'), 'updated' => date('c'), 'data' => [], 'by' => 'analysis', 'scope' => 'private']); $hit = [$dg => wf_case_get($aid, $dg)]; }
    if (count($hit) !== 1) return wf_msg('case_deep_none', ['cmd' => $w]);
    $ph = (string) array_key_first($hit);
    if ($waitTo !== '') { $wc = wf_case_get($aid, $ph) ?? []; wi_gateway('/chat/send', ['jid' => $waitTo, 'text' => wf_msg('case_deep_wait', ['name' => (string) ($wc['name'] ?? '') ?: '+' . $ph, 'no' => (string) ($wc['no'] ?? '')]), 'mentions' => $waitMentions, 'via' => 'bot'], $acc); } // the case was found: "checking, the answer follows"
    $a = wf_case_deep($acc, $ph, $by);
    return !empty($a['error']) ? '⚠️ ' . wi_t((string) $a['error']) : wf_case_deep_text(wf_case_get($aid, $ph) ?? [], $a);
}

function wf_api_case_deep(string $api): void {
    if (!in_array($api, ['case_deep', 'case_follow'], true)) return;
    $acc = wi_current(); if (!$acc) wi_json(['ok' => false, 'error' => 'No number chosen.'], 422); $aid = (string) $acc['id'];
    if ($api === 'case_deep') { $in = wf_post(); @set_time_limit(240); $a = wf_case_deep($acc, wf_phone((string) ($in['phone'] ?? '')), (string) ($_SESSION['email'] ?? ''));
        wi_json(!empty($a['error']) ? ['ok' => false, 'error' => $a['error']] : ['ok' => true, 'deep' => $a]); }
    // the follow-up board: every case with its last analysis, stage, progress and trend
    $st = (array) (wf_case_cfg($aid)['statuses'] ?? []); $rows = [];
    foreach (wf_cases($aid) as $ph => $c) { if (!is_array($c) || in_array($c['status'] ?? '', ['archived', 'removed'], true) || (wf_case_is_private($c) && !wf_case_private_ok())) continue; $dl = (array) ($c['deep'] ?? []); $d = $dl ? (array) end($dl) : []; $h = array_map(fn($x) => (int) ($x['progress'] ?? 0), (array) ($c['deep'] ?? []));
        $rows[] = ['phone' => (string) $ph, 'name' => (string) ($c['name'] ?? ($c['data']['name'] ?? '')), 'no' => (string) ($c['no'] ?? ''), 'status' => (string) ($st[$c['status'] ?? 'new'] ?? ''), 'at' => (string) ($d['at'] ?? ''), 'progress' => $d ? (int) $d['progress'] : null,
            'trend' => (string) ($d['trend'] ?? ''), 'stage' => (string) ($d['stage'] ?? ''), 'axes' => (array) ($d['axes'] ?? []), 'history' => $h, 'flags' => (string) ($d['red_flags'] ?? ''), 'next' => (string) ($d['next_step'] ?? ''), 'summary' => (string) ($d['summary'] ?? ''), 'updated' => (string) ($c['updated'] ?? '')]; }
    usort($rows, fn($a, $b) => strcmp($b['at'] ?: $b['updated'], $a['at'] ?: $a['updated']));
    wi_json(['ok' => true, 'axes' => wf_deep_axes($aid), 'rows' => $rows, 'cmd' => (string) (wf_pack($aid)['analyze_cmd'] ?? '')]);
}
