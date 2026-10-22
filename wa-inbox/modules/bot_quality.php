<?php
// WhatsApp Inbox — bot_quality: how good the bot's replies are.
// 1) The people's own rating, with no question asked: a reaction on a bot reply (👍 ❤️ / 👎) or the words right after it (thanks / that is wrong).
// 2) A weekly test: real questions answered by the team are asked to the bot again, and a model scores the bot's reply against the team's (1-5).
// A weekly report goes to the admins; the history shows the trend.

function wf_q_words(string $list): array { return array_values(array_filter(array_map(fn($w) => wf_intake_norm(trim($w)), preg_split('/[|,\x{060C}\n]+/u', $list)))); }
/** The people's rating of the bot's private replies in the last days: counts and the replies they did not like. */
function wf_bot_ratings(array $acc, int $days = 7): array {
    $aid = (string) $acc['id']; $P = wf_pack($aid); $since = time() - $days * 86400;
    $posE = preg_split('/\s+/u', trim((string) ($P['rating_good_emojis'] ?? ''))); $negE = preg_split('/\s+/u', trim((string) ($P['rating_bad_emojis'] ?? '')));
    $posW = wf_q_words((string) ($P['rating_good_words'] ?? '')); $negW = wf_q_words((string) ($P['rating_bad_words'] ?? ''));
    $jids = []; foreach (wi_bot_log($aid, 500) as $l) if (($l['result'] ?? '') === 'sent' && empty($l['group']) && strtotime((string) ($l['at'] ?? '')) >= $since) $jids[(string) $l['jid']] = 1;
    $good = 0; $bad = 0; $total = 0; $bads = [];
    foreach (array_slice(array_keys($jids), 0, 60) as $j) { $rows = array_values((array) (wi_gateway('/archive/thread?limit=200&jid=' . rawurlencode($j), null, $acc)['rows'] ?? []));
        foreach ($rows as $i => $m) { if (($m['via'] ?? '') !== 'bot' || strtotime((string) ($m['time'] ?? '')) < $since) continue; $total++; $v = 0;
            $re = trim((string) ($m['reaction'] ?? '')); if ($re !== '') { if (in_array($re, $posE, true)) $v = 1; elseif (in_array($re, $negE, true)) $v = -1; }
            if (!$v && ($n = $rows[$i + 1] ?? null) && ($n['dir'] ?? '') === 'in' && strtotime((string) $n['time']) - strtotime((string) $m['time']) < 3600) { $t = wf_intake_norm((string) ($n['text'] ?? ''));
                if (mb_strlen($t) <= 60) { foreach ($negW as $w) if ($w !== '' && mb_strpos($t, $w) !== false) { $v = -1; break; } if (!$v) foreach ($posW as $w) if ($w !== '' && mb_strpos($t, $w) !== false) { $v = 1; break; } } }
            if ($v > 0) $good++; elseif ($v < 0) { $bad++; $q = ''; for ($k = $i - 1; $k >= 0; $k--) if (($rows[$k]['dir'] ?? '') === 'in') { $q = (string) ($rows[$k]['text'] ?? ''); break; }
                $bads[] = ['jid' => $j, 'q' => mb_substr($q, 0, 200), 'reply' => mb_substr((string) ($m['text'] ?? ''), 0, 300), 'time' => (string) $m['time']]; } } }
    return ['total' => $total, 'good' => $good, 'bad' => $bad, 'rated' => $good + $bad, 'pct' => $good + $bad ? (int) round($good * 100 / ($good + $bad)) : null, 'bads' => array_slice($bads, -8)];
}
/** The weekly test: N real questions with the team's answer; the bot answers again and a model scores it 1-5 against the team. */
function wf_bot_quality_test(array $acc, int $n = 10): array {
    $aid = (string) $acc['id']; $pairs = array_values(array_filter(wf_pairs($aid), fn($p) => mb_strlen((string) ($p['a'] ?? '')) >= 20 && count(wf_words((string) ($p['q'] ?? ''))) >= 3));
    if (!$pairs) return ['ok' => false, 'error' => 'Collect the conversations first (step 1).'];
    shuffle($pairs); $items = []; $sum = 0;
    foreach (array_slice($pairs, 0, $n) as $p) { $r = wf_bot_answer($acc, (string) $p['q'], [], '', null, ['jid' => 'quality-test']); if (empty($r['ok'])) continue;
        $j = wi_ai('Bot quality test', wf_prompt('quality_judge', $aid, ['question' => mb_substr((string) $p['q'], 0, 600), 'team' => mb_substr((string) $p['a'], 0, 900), 'bot' => mb_substr((string) $r['reply'], 0, 900)]), 60, ['subject' => 'quality test', 'prefer' => wf_fast_model(true)]);
        $sc = max(1, min(5, (int) ($j['data']['score'] ?? 0))); if (empty($j['ok'])) continue; $sum += $sc;
        $items[] = ['q' => mb_substr((string) $p['q'], 0, 200), 'team' => mb_substr((string) $p['a'], 0, 300), 'bot' => mb_substr((string) $r['reply'], 0, 300), 'score' => $sc, 'why' => mb_substr((string) ($j['data']['why'] ?? ''), 0, 200), 'topic' => mb_substr((string) ($j['data']['topic'] ?? ''), 0, 60)]; }
    if (!$items) return ['ok' => false, 'error' => 'AI failed.'];
    $avg = round($sum / count($items), 2);
    return ['ok' => true, 'avg' => $avg, 'pct' => (int) round(($avg - 1) * 25), 'items' => $items, 'at' => date('c')];
}
/** The weekly run: ratings + test, kept in the history, and a short report to the admins. */
function wf_bot_quality_week(array $acc, ?callable $tell = null): array {
    $aid = (string) $acc['id']; $rt = wf_bot_ratings($acc, 7); $ts = wf_bot_quality_test($acc, (int) (wf_bot_run_cfg($aid)['quality_n'] ?? 10));
    $hist = (array) (wi_read('bot_quality.json', [])[$aid]['history'] ?? []); $prev = $hist ? (array) end($hist) : [];
    $row = ['at' => date('c'), 'ratings' => array_diff_key($rt, ['bads' => 1]), 'test' => $ts['ok'] ? ['avg' => $ts['avg'], 'pct' => $ts['pct']] : null];
    $weak = []; foreach ((array) ($ts['items'] ?? []) as $it) if ($it['score'] <= 2) $weak[] = '• ' . ($it['topic'] !== '' ? $it['topic'] : mb_substr($it['q'], 0, 50));
    wi_update('bot_quality.json', function ($Q) use ($aid, $row, $rt, $ts) { $Q[$aid]['history'][] = $row; $Q[$aid]['history'] = array_slice($Q[$aid]['history'], -26); $Q[$aid]['last'] = ['ratings' => $rt, 'test' => $ts]; return $Q; });
    if ($tell) $tell(wf_msg('bot_quality_report', ['name' => (string) ($acc['name'] ?? $aid), 'pct' => $ts['ok'] ? (string) $ts['pct'] : '—', 'trend' => $ts['ok'] && !empty($prev['test']['pct']) ? (($ts['pct'] - (int) $prev['test']['pct']) >= 0 ? '+' : '') . ($ts['pct'] - (int) $prev['test']['pct']) : '—',
        'rated' => (string) $rt['rated'], 'good' => (string) ($rt['pct'] ?? '—'), 'bad' => (string) $rt['bad'], 'weak' => $weak ? implode("\n", array_slice($weak, 0, 5)) : '—']));
    return $row;
}
function wf_api_bot_quality(string $api): void {
    if ($api !== 'bot_quality') return;
    $acc = wi_current(); if (!$acc) wi_json(['ok' => false, 'error' => 'No number chosen.'], 422); $aid = (string) $acc['id'];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') { wf_admin(); @set_time_limit(600); wf_bot_quality_week($acc, null); }
    $Q = (array) (wi_read('bot_quality.json', [])[$aid] ?? []);
    wi_json(['ok' => true, 'history' => (array) ($Q['history'] ?? []), 'last' => (array) ($Q['last'] ?? [])]);
}
