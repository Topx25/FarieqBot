<?php
// WhatsApp Inbox — checkin: the bot asks a person under follow-up how they are (every few days, at a set hour),
// with a short message written for their case. Their answer comes back to the bot like any message, and the case file notes it.
// Safety for the number: only people who wrote in the last 30 days, nobody the team or the bot talked to in the last day, a daily cap.

function wf_checkin_run(array $acc, bool $dry = false): array {
    $aid = (string) $acc['id']; $c = wf_bot_run_cfg($aid); if (empty($c['checkin_on']) && !$dry) return ['off' => true];
    $every = max(1, (int) ($c['checkin_days'] ?? 2)); $cap = max(1, (int) ($c['checkin_max'] ?? 15)); $st = array_filter(array_map('trim', explode(',', (string) ($c['checkin_statuses'] ?? 'follow,improving'))));
    $now = time(); $done = []; $chats = [];
    foreach ((array) (wi_gateway('/archive/chats?months=1', null, $acc)['chats'] ?? []) as $ch) { if (!empty($ch['group'])) continue; $j = (string) $ch['jid'];
        $p = wf_phone((string) ($ch['phone'] ?? '')) ?: (str_ends_with($j, '@lid') ? wf_phone((string) (wi_contact($j, $acc)['phone'] ?? '')) : wf_phone(explode('@', $j)[0])); if ($p !== '') $chats[$p][] = $j; }
    foreach (wf_cases($aid) as $ph => $cs) { if (count($done) >= $cap) break; if (!is_array($cs) || !in_array((string) ($cs['status'] ?? ''), $st, true) || empty($chats[$ph])) continue;
        if (strtotime((string) ($cs['checkin_at'] ?? '')) > $now - $every * 86400 + 3600) continue;
        $jid = ''; $rows = []; $lastIn = 0; $lastOut = 0;
        foreach ($chats[$ph] as $j) { $r = (array) (wi_gateway('/archive/thread?limit=60&jid=' . rawurlencode($j), null, $acc)['rows'] ?? []); foreach ($r as $m) { $t = strtotime((string) ($m['time'] ?? '')); if (($m['dir'] ?? '') === 'in') { if ($t > $lastIn) { $lastIn = $t; $jid = $j; } } else $lastOut = max($lastOut, $t); } $rows = array_merge($rows, $r); }
        if (!$jid || $lastIn < $now - 30 * 86400 || $lastOut > $now - 86400 || $lastIn > $now - 86400) continue; // only people who wrote lately, and nobody talked with them today
        $paused = wf_bot_paused($aid)[$jid] ?? null; if ($paused && (empty($paused['until']) || strtotime((string) $paused['until']) > $now)) continue;
        usort($rows, fn($a, $b) => strcmp((string) $a['time'], (string) $b['time'])); $talk = '';
        foreach (array_slice(array_filter($rows, fn($m) => trim((string) ($m['text'] ?? '')) !== ''), -8) as $m) $talk .= (($m['dir'] ?? '') === 'in' ? 'Person: ' : 'Us: ') . mb_substr(preg_replace('/\s+/u', ' ', (string) $m['text']), 0, 300) . "\n";
        $dl = (array) ($cs['deep'] ?? []); $d = $dl ? (array) end($dl) : []; $stName = (string) (wf_case_cfg($aid)['statuses'][$cs['status']] ?? $cs['status']);
        $ai = wi_ai('Follow-up check-in', wf_prompt('checkin_msg', $aid, ['name' => (string) ($cs['name'] ?? ''), 'stage' => $stName . ((string) ($d['stage'] ?? '') !== '' ? ' — ' . $d['stage'] : ''), 'complaint' => mb_substr((string) ($cs['data']['complaint'] ?? ''), 0, 300), 'talk' => $talk]), 60, ['subject' => 'check-in', 'prefer' => wf_fast_model(true)]);
        $text = trim((string) ($ai['data']['text'] ?? '')); if ($text === '') continue;
        if ($dry) { $done[] = ['phone' => $ph, 'name' => (string) ($cs['name'] ?? ''), 'text' => $text]; continue; }
        $s = wi_gateway('/chat/send', ['jid' => $jid, 'text' => $text, 'via' => 'bot', 'typing_ms' => 2500], $acc);
        if (empty($s['ok'])) continue;
        wf_case_update($aid, $ph, function (?array $x) use ($text) { if ($x === null) return null; $x['checkin_at'] = date('c'); $x['notes'][] = ['at' => date('c'), 'by' => 'bot', 'text' => 'Check-in sent: ' . mb_substr($text, 0, 300)]; return $x; });
        wi_bot_log_add($aid, ['jid' => $jid, 'name' => (string) ($cs['name'] ?? ''), 'q' => '', 'reply' => $text, 'result' => 'sent', 'role' => 'checkin', 'at' => date('c')]);
        $done[] = ['phone' => $ph, 'name' => (string) ($cs['name'] ?? ''), 'text' => $text]; sleep(random_int(20, 60)); } // a pause between check-ins, like a person
    return ['sent' => $done];
}
function wf_api_checkin(string $api): void {
    if ($api !== 'checkin_preview') return;
    $acc = wi_current(); if (!$acc) wi_json(['ok' => false, 'error' => 'No number chosen.'], 422); wf_admin(); @set_time_limit(300);
    wi_json(['ok' => true] + wf_checkin_run($acc, true));
}
