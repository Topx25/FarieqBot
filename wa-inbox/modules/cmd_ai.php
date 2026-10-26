<?php
/** Group case commands the code could not read: the AI reads the intent (command list + cases), runs it when sure, asks "did you mean … reply 1" when not, learns from corrections, and a weekly report lists what was not understood. */

/** Store of this number: pending questions, misses and learned forms. */
function wf_cmdai_get(string $aid): array { return (array) (wi_read('cmd_ai.json', [])[$aid] ?? []) + ['pending' => [], 'misses' => [], 'learned' => []]; }
function wf_cmdai_put(string $aid, callable $fn): void { wi_update('cmd_ai.json', function ($A) use ($aid, $fn) { $A = (array) $A; $A[$aid] = $fn((array) ($A[$aid] ?? []) + ['pending' => [], 'misses' => [], 'learned' => []]); return $A; }); }

/** A "#…" message near a command word (a typo such as "#اتحسنتت"): worth asking the AI. Cheap: no lookups. */
function wf_cmdai_near(string $aid, string $text): bool {
    if (!preg_match('/#(\S+)/u', $text, $m)) return false; $t = mb_strtolower($m[1]);
    $words = array_merge(array_keys(wf_intake_cmds($aid)), [wf_intake_lookup_kw($aid)]);
    foreach ($words as $w) { similar_text($t, mb_strtolower(ltrim((string) $w, '#')), $pc); if ($pc >= 70) return true; }
    return false;
}

/** Asks the AI what the writer meant. Returns ['cmd' => command word, 'key' => case key, 'reason' => …, 'sure' => bool] or []. */
function wf_cmdai_read(array $acc, string $text): array {
    $aid = (string) $acc['id']; $kw = wf_intake_lookup_kw($aid); $cmds = wf_intake_cmds($aid); $st = wf_case_statuses($aid);
    $list = '- ' . $kw . ' = lookup (show the case)'; foreach ($cmds as $c => $k) $list .= "\n- " . $c . ' = ' . ($st[$k] ?? $k);
    $cases = []; foreach (wf_cases($aid) as $k => $c) if (is_array($c) && !wf_case_is_private($c)) $cases[(string) $k] = $c;
    uasort($cases, fn($a, $b) => strcmp((string) ($b['updated'] ?? ''), (string) ($a['updated'] ?? ''))); $cl = '';
    foreach (array_slice($cases, 0, 150, true) as $k => $c) $cl .= 'key ' . $k . ' | ID ' . ($c['no'] ?? '') . ' | ' . mb_substr((string) ($c['name'] ?? ''), 0, 40) . "\n";
    $ex = ''; foreach (array_slice((array) wf_cmdai_get($aid)['learned'], -15) as $l) $ex .= '«' . mb_substr((string) $l['wrong'], 0, 160) . '» → «' . mb_substr((string) $l['right'], 0, 160) . "»\n";
    $r = wi_ai('Read a group command', wf_prompt('cmd_understand', $aid, ['message' => mb_substr($text, 0, 1500), 'commands' => $list, 'cases' => $cl, 'examples' => $ex ?: '-']), 60, ['subject' => 'group command', 'prefer' => function_exists('wf_fast_model') ? wf_fast_model(true) : '']);
    $d = (array) ($r['data'] ?? []); $cmd = (string) ($d['command'] ?? ''); $key = preg_replace('/\D/', '', (string) ($d['case_key'] ?? ''));
    if ($cmd !== $kw && !isset($cmds[$cmd])) return []; if ($key === '' || !isset($cases[$key])) return [];
    return ['cmd' => $cmd, 'key' => $key, 'reason' => trim((string) ($d['reason'] ?? '')), 'sure' => (float) ($d['confidence'] ?? 0) >= 0.85, 'case' => $cases[$key]];
}

/** The command in the plain form the code reads. */
function wf_cmdai_form(array $x): string { return trim($x['cmd'] . ' +' . $x['key'] . ($x['reason'] !== '' ? "\n" . $x['reason'] : '')); }

/** Called when wf_intake_command could not use the message: true when the AI handled it (ran it, or asked). */
function wf_cmdai_try(array $acc, array $in, string $jid, string $from, string $text, string $id): bool {
    $aid = (string) $acc['id']; if (!empty($in['cmdai'])) return false; // a form the AI already rewrote: never twice
    $x = wf_cmdai_read($acc, $text);
    wf_cmdai_put($aid, function ($S) use ($jid, $from, $text, $x) { $S['misses'][] = ['at' => date('c'), 'jid' => $jid, 'from' => $from, 'text' => mb_substr($text, 0, 600), 'ai' => $x ? wf_cmdai_form($x) : '', 'done' => '']; $S['misses'] = array_slice($S['misses'], -300); return $S; });
    if (!$x) return false;
    [$tagT, $tagM] = wf_group_tag($from); $one = (string) wf_case_cfg($aid)['one'];
    if ($x['sure']) { wf_cmdai_mark($aid, $jid, $from, 'ai'); wi_log('cmd_ai_run', ['account' => $aid, 'group' => $jid, 'case' => $x['key'], 'cmd' => $x['cmd']]);
        return wf_intake_command($acc, ['cmdai' => 1] + $in, $jid, $from, wf_cmdai_form($x), $id); }
    wf_cmdai_put($aid, function ($S) use ($jid, $from, $x, $text) { $S['pending'][$jid . '|' . $from] = ['at' => time(), 'form' => wf_cmdai_form($x), 'text' => mb_substr($text, 0, 600)]; return $S; });
    wi_gateway('/chat/send', ['jid' => $jid, 'text' => $tagT . wf_msg('case_ai_ask', ['case' => $one, 'name' => (string) ($x['case']['name'] ?? '') ?: '+' . $x['key'], 'no' => (string) ($x['case']['no'] ?? ''), 'cmd' => $x['cmd'], 'reason' => mb_substr($x['reason'], 0, 200)]), 'quote_id' => $id, 'mentions' => $tagM, 'via' => 'bot'], $acc);
    wi_log('cmd_ai_ask', ['account' => $aid, 'group' => $jid, 'case' => $x['key']]); return true;
}

/** "1" from the same person in the same group within 30 minutes: the asked command runs. True when it was such a reply. */
function wf_cmdai_confirm(array $acc, array $in, string $jid, string $from, string $text, string $id): bool {
    $aid = (string) $acc['id']; if (!in_array(trim($text), ['1', '١'], true)) return false;
    $p = (array) (wf_cmdai_get($aid)['pending'][$jid . '|' . $from] ?? []); if (!$p || (int) $p['at'] < time() - 1800) return false;
    wf_cmdai_put($aid, function ($S) use ($jid, $from, $p) { unset($S['pending'][$jid . '|' . $from]); $S['learned'][] = ['wrong' => $p['text'], 'right' => $p['form'], 'at' => date('c')]; $S['learned'] = array_slice($S['learned'], -60); return $S; });
    wf_cmdai_mark($aid, $jid, $from, 'confirmed'); wi_log('cmd_ai_confirm', ['account' => $aid, 'group' => $jid]);
    wf_intake_command($acc, ['cmdai' => 1] + $in, $jid, $from, (string) $p['form'], $id); return true;
}

/** A command that worked: the writer's last miss in this group (10 minutes) is now solved, and its right form is learned. */
function wf_cmdai_learn(string $aid, string $jid, string $from, string $text): void {
    $S = wf_cmdai_get($aid); $hit = false; foreach (array_reverse((array) $S['misses'], true) as $m) if ($m['jid'] === $jid && $m['from'] === $from && $m['done'] === '' && strtotime($m['at']) > time() - 600 && $m['text'] !== $text) { $hit = true; break; }
    if (!$hit) return;
    wf_cmdai_put($aid, function ($S) use ($jid, $from, $text) { foreach (array_reverse(array_keys($S['misses'])) as $i) { $m = $S['misses'][$i];
            if ($m['jid'] === $jid && $m['from'] === $from && $m['done'] === '' && strtotime($m['at']) > time() - 600) { $S['misses'][$i]['done'] = 'corrected'; $S['learned'][] = ['wrong' => $m['text'], 'right' => mb_substr($text, 0, 600), 'at' => date('c')]; $S['learned'] = array_slice($S['learned'], -60); break; } }
        unset($S['pending'][$jid . '|' . $from]); return $S; });
}
function wf_cmdai_mark(string $aid, string $jid, string $from, string $how): void {
    wf_cmdai_put($aid, function ($S) use ($jid, $from, $how) { foreach (array_reverse(array_keys($S['misses'])) as $i) if ($S['misses'][$i]['jid'] === $jid && $S['misses'][$i]['from'] === $from && $S['misses'][$i]['done'] === '') { $S['misses'][$i]['done'] = $how; break; } return $S; });
}

/** Weekly: the commands the bot did not understand (last 7 days), to the watch group. */
function wf_cmdai_week(array $acc, callable $send): void {
    $aid = (string) $acc['id']; $since = time() - 7 * 86400; $M = array_filter((array) wf_cmdai_get($aid)['misses'], fn($m) => strtotime((string) $m['at']) >= $since);
    if (!$M) return; $open = array_filter($M, fn($m) => $m['done'] === ''); $lines = '';
    foreach (array_slice(array_values($open), -10) as $m) $lines .= '• «' . mb_substr(preg_replace('/\s+/u', ' ', (string) $m['text']), 0, 90) . "»\n";
    $send(wf_msg('cmd_ai_week', ['total' => (string) count($M), 'solved' => (string) (count($M) - count($open)), 'open' => (string) count($open), 'list' => $lines ?: '-']));
}
