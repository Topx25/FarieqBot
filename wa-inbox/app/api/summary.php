<?php
// WhatsApp Inbox API — AI summary and category per chat. Included by app/api.php inside the request (same scope: $api, $apiPage…).
    /* ---------- AI summary + category per chat ---------- */
    if ($api === 'summarize') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') wi_json(['ok' => false, 'error' => 'POST only.'], 405);
        wi_check_csrf();
        @set_time_limit(180);
        $in = json_decode(wi_input(), true) ?: [];
        $acc = wi_current(); $aid = $acc['id'] ?? '';
        $jids = array_slice(array_filter(array_map(fn($j) => preg_replace('/[^0-9A-Za-z@.:_-]/', '', (string) $j), (array) ($in['jids'] ?? [$in['jid'] ?? '']))), 0, 4);
        $all = wi_read('summaries.json', []);
        $out = [];
        foreach ($jids as $jid) {
            $t = wi_gateway('/archive/thread?jid=' . rawurlencode($jid));
            $rows = array_slice(array_values(array_filter($t['rows'] ?? [], fn($m) => in_array($m['dir'], ['in', 'out'], true) && trim((string) ($m['text'] ?? '')) !== '')), -80);
            if (!$rows) { $out[$jid] = ['ok' => false, 'error' => 'No text messages to summarise.']; continue; }
            $conv = '';
            foreach ($rows as $m) $conv .= ($m['dir'] === 'out' ? 'Business' : 'Customer') . ' [' . substr((string) $m['time'], 0, 16) . ']: ' . mb_substr(preg_replace('/\s+/u', ' ', (string) $m['text']), 0, 400) . "\n";
            $prompt = wf_prompt('chat_summary', (string) (wi_current()['id'] ?? ''), ['conversation' => mb_substr($conv, -24000)]);
            $r = wi_ai('Chat summary', $prompt, 120, ['subject' => $jid]);
            if (empty($r['ok']) || !is_array($r['data'] ?? null)) { $out[$jid] = ['ok' => false, 'error' => $r['error'] ?? 'AI failed.']; continue; }
            $d = $r['data'];
            $rec = ['summary' => mb_substr((string) ($d['summary'] ?? ''), 0, 800), 'category' => preg_replace('/[^a-z_]/', '', strtolower((string) ($d['category'] ?? 'other'))) ?: 'other',
                'sentiment' => in_array($d['sentiment'] ?? '', ['positive', 'neutral', 'negative'], true) ? $d['sentiment'] : 'neutral', 'needs_reply' => !empty($d['needs_reply']),
                'next_action' => mb_substr((string) ($d['next_action'] ?? ''), 0, 300), 'at' => date('c'), 'last_msg' => end($rows)['time'], 'messages' => count($rows), 'model' => ($r['provider'] ?? '') . ' ' . ($r['model'] ?? '')];
            $all[$aid][$jid] = $rec; $out[$jid] = ['ok' => true] + $rec;
        }
        wi_write('summaries.json', $all);
        wi_log('summarize', ['account' => $aid, 'count' => count($jids)]);
        wi_json(['ok' => true, 'results' => $out]);
    }
