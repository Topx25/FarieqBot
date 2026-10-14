<?php
// WhatsApp Inbox API — library: AI review in batches. Included by app/api.php inside the request (same scope: $api, $apiPage…).
    /* ---------- Library: AI review (sort, check, group) in batches ---------- */
    if ($api === 'lib_ai') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') wi_json(['ok' => false, 'error' => 'POST only.'], 405);
        wi_check_csrf();
        @set_time_limit(180);
        $in = json_decode(wi_input(), true) ?: [];
        $aid = wi_current()['id'] ?? '';
        $all = wi_read('lib_ai.json', []);
        if (!empty($in['reset'])) { unset($all[$aid]); wi_write('lib_ai.json', $all); wi_json(['ok' => true, 'reset' => true]); }
        $done = $all[$aid] ?? [];
        $r = wi_gateway('/library?' . http_build_query(['type' => 'sections', 'group' => '1', 'min' => 60]));
        if (empty($r['ok'])) wi_json(['ok' => false, 'error' => $r['error'] ?? 'Gateway error.']);
        $todo = array_values(array_filter($r['groups'], fn($g) => empty($g['audio']) && !isset($done[$g['best']['key'] ?? '']['quality']) && empty($done[$g['best']['key'] ?? '']['skipped'])));
        $total = count(array_filter($r['groups'], fn($g) => empty($g['audio'])));
        if (!$todo) wi_json(['ok' => true, 'reviewed' => 0, 'remaining' => 0, 'total' => $total]);
        $batch = array_slice($todo, 0, 25);
        // Context: titles of already-known groups so the AI can merge into them.
        $known = [];
        foreach ($r['groups'] as $g) if (empty($g['audio'])) $known[] = ($g['best']['key'] ?? '') . ' | ' . $g['section'] . ' | ' . mb_substr(preg_replace('/\s+/u', ' ', (string) $g['title']), 0, 70);
        $items = '';
        foreach ($batch as $g) $items .= "### id=" . $g['best']['key'] . " section=" . $g['section'] . " topic=" . $g['topic'] . "\n" . mb_substr((string) $g['best']['text'], 0, 900) . "\n\n";
        $prompt = wf_prompt('library_sort', (string) (wi_current()['id'] ?? ''), ['known' => mb_substr(implode("\n", $known), 0, 14000), 'items' => $items]);
        $ai = wi_ai('Library: sort & check', $prompt, 170, ['subject' => count($batch) . ' groups', 'progress' => ['done' => $total - count($todo), 'total' => $total]]);
        if (empty($ai['ok']) || !is_array($ai['data']['items'] ?? null)) wi_json(['ok' => false, 'error' => $ai['error'] ?? 'AI returned no result.']);
        $okSec = array_map('trim', explode(',', $sections)); $keys = array_flip(array_column(array_column($r['groups'], 'best'), 'key'));
        $n = 0;
        foreach ($ai['data']['items'] as $x) {
            $id = (string) ($x['id'] ?? ''); if (!isset($keys[$id])) continue;
            $m = (string) ($x['merge_into'] ?? '');
            $done[$id] = ($done[$id] ?? []) + []; $done[$id] = ['section' => in_array($x['section'] ?? '', $okSec, true) ? $x['section'] : '', 'topic' => mb_substr(trim((string) ($x['topic'] ?? '')), 0, 40), 'title' => mb_substr(trim((string) ($x['title'] ?? '')), 0, 90),
                'quality' => max(1, min(5, (int) ($x['quality'] ?? 3))), 'valid' => !isset($x['valid']) || !empty($x['valid']), 'note' => mb_substr(trim((string) ($x['note'] ?? '')), 0, 200),
                'merge_into' => $m !== $id && isset($keys[$m]) ? $m : '', 'at' => date('c'), 'model' => trim(($ai['provider'] ?? '') . ' ' . ($ai['model'] ?? ''))] + array_intersect_key($done[$id], array_flip(['best_pick', 'compare', 'formatted']));
            $n++;
        }
        // Items the AI skipped are marked so the loop moves on.
        foreach ($batch as $g) if (!isset($done[$g['best']['key']]['quality'])) $done[$g['best']['key']] = ['skipped' => true, 'at' => date('c')] + ($done[$g['best']['key']] ?? []);
        $all[$aid] = $done; wi_write('lib_ai.json', $all);
        wi_log('lib_ai', ['account' => $aid, 'reviewed' => $n]);
        wi_json(['ok' => true, 'reviewed' => $n, 'remaining' => max(0, count($todo) - count($batch)), 'total' => $total, 'model' => $done[array_key_last($done)]['model'] ?? '']);
    }
