<?php
// WhatsApp Inbox API — library: AI tools per group. Included by app/api.php inside the request (same scope: $api, $apiPage…).
    /* ---------- Library AI tools per group: compare & pick best, format ---------- */
    if ($api === 'lib_ai_group' || $api === 'lib_ai_best') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') wi_json(['ok' => false, 'error' => 'POST only.'], 405);
        wi_check_csrf();
        @set_time_limit(240);
        $in = json_decode(wi_input(), true) ?: [];
        $aid = wi_current()['id'] ?? '';
        $all = wi_read('lib_ai.json', []); $done = $all[$aid] ?? [];
        $r = wi_gateway('/library?' . http_build_query(['type' => 'sections', 'group' => '1', 'min' => 0]));
        if (empty($r['ok'])) wi_json(['ok' => false, 'error' => $r['error'] ?? 'Gateway error.']);
        $byKey = []; foreach ($r['groups'] as $g) if (empty($g['audio'])) $byKey[$g['best']['key'] ?? ''] = $g;
        $compare = function (array $g) use (&$done) {
            $vers = array_slice(array_merge([$g['best']], $g['others'] ?? []), 0, 8);
            $txt = ''; foreach ($vers as $i => $v) $txt .= "### V" . ($i + 1) . " (shared " . (int) ($v['count'] ?? 1) . "x)\n" . mb_substr((string) $v['text'], 0, 1500) . "\n\n";
            $prompt = wf_prompt('library_compare', (string) (wi_current()['id'] ?? ''), ['versions' => $txt]);
            $ai = wi_ai('Library: compare versions', $prompt, 200, ['subject' => mb_substr((string) ($g['title'] ?? ''), 0, 80)]);
            if (empty($ai['ok']) || !is_array($ai['data'] ?? null)) return ['ok' => false, 'error' => $ai['error'] ?? 'AI failed.'];
            $b = max(1, min(count($vers), (int) ($ai['data']['best'] ?? 1)));
            $gk = $g['best']['key'];
            $done[$gk] = ($done[$gk] ?? []) + [];
            $done[$gk]['best_pick'] = $vers[$b - 1]['key'] ?? '';
            $done[$gk]['compare'] = ['reason' => mb_substr((string) ($ai['data']['reason'] ?? ''), 0, 400), 'differences' => mb_substr((string) ($ai['data']['differences'] ?? ''), 0, 600),
                'ranking' => array_values(array_filter(array_map('intval', (array) ($ai['data']['ranking'] ?? [])))), 'versions' => count($vers), 'at' => date('c'), 'model' => trim(($ai['provider'] ?? '') . ' ' . ($ai['model'] ?? ''))];
            unset($done[$gk]['skipped']);
            return ['ok' => true, 'best' => $b, 'compare' => $done[$gk]['compare']];
        };
        if ($api === 'lib_ai_best') {
            $todo = array_values(array_filter($byKey, fn($g) => count($g['others'] ?? []) >= 1 && empty($done[$g['best']['key']]['compare'])));
            usort($todo, fn($a, $b) => count($b['others']) <=> count($a['others']));
            $n = 0; $err = '';
            foreach (array_slice($todo, 0, 3) as $g) { $x = $compare($g); if ($x['ok']) $n++; else { $err = $x['error']; $done[$g['best']['key']]['compare'] = ['reason' => '', 'failed' => true, 'at' => date('c')]; } }
            $all[$aid] = $done; wi_write('lib_ai.json', $all);
            $total = count(array_filter($byKey, fn($g) => count($g['others'] ?? []) >= 1));
            wi_json(['ok' => $n > 0 || !$err, 'error' => $err, 'done' => $n, 'remaining' => max(0, count($todo) - 3), 'total' => $total]);
        }
        $key = (string) ($in['key'] ?? ''); $g = $byKey[$key] ?? null;
        if (!$g) wi_json(['ok' => false, 'error' => 'Group not found — reload the library.']);
        if (($in['do'] ?? '') === 'compare') {
            if (empty($g['others'])) wi_json(['ok' => false, 'error' => 'This group has only one version.']);
            $x = $compare($g);
        } else {
            $text = (string) $g['best']['text'];
            $prompt = wf_prompt('library_format', (string) (wi_current()['id'] ?? ''), ['text' => mb_substr($text, 0, 12000)]);
            $ai = wi_ai('Library: format text', $prompt, 200, ['subject' => mb_substr((string) ($g['title'] ?? ''), 0, 80)]);
            $f = trim((string) ($ai['data']['text'] ?? ''));
            // Safety: the formatted text must keep the same letters (only whitespace/symbols may change).
            $norm = fn($t) => preg_replace('/[^\p{Arabic}\p{L}\p{N}]+/u', '', $t);
            if (empty($ai['ok']) || $f === '') $x = ['ok' => false, 'error' => $ai['error'] ?? 'AI failed.'];
            elseif ($norm($f) !== $norm($text)) $x = ['ok' => false, 'error' => 'The AI changed some words, so its result was rejected. Try again.'];
            else { $done[$key]['formatted'] = mb_substr($f, 0, 20000); $x = ['ok' => true, 'formatted' => $f]; }
        }
        $all[$aid] = $done; wi_write('lib_ai.json', $all);
        wi_log('lib_ai_group', ['account' => $aid, 'do' => $in['do'] ?? '', 'ok' => $x['ok']]);
        wi_json($x);
    }
