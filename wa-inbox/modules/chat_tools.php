<?php
// WhatsApp Inbox — chat_tools (split out of features.php in v3.8, code unchanged)

/** API calls of this area (moved unchanged from the old single switch). */
function wf_api_chat_tools(string $api): void {
    switch ($api) {
    /* ----- 1) AI reply suggestion from the chat + the library ----- */
    case 'suggest_reply': {
        $in = wf_post(); @set_time_limit(200);
        $jid = wf_jid($in['jid'] ?? '');
        $t = wi_gateway('/archive/thread?jid=' . rawurlencode($jid));
        $rows = array_slice(array_values(array_filter($t['rows'] ?? [], fn($m) => in_array($m['dir'], ['in', 'out'], true) && trim((string) ($m['text'] ?? '')) !== '')), -30);
        if (!$rows) wi_json(['ok' => false, 'error' => 'No text messages in this chat yet.']);
        $conv = ''; $lastIn = '';
        foreach ($rows as $m) { $conv .= ($m['dir'] === 'out' ? 'Us' : 'Them') . ': ' . mb_substr(preg_replace('/\s+/u', ' ', (string) $m['text']), 0, 500) . "\n"; if ($m['dir'] === 'in') $lastIn .= ' ' . $m['text']; }
        // Library texts that share the most words with what they asked.
        $lib = wi_gateway('/library?' . http_build_query(['type' => 'sections', 'group' => '1', 'min' => 60]));
        $want = array_flip(array_slice(wf_words($lastIn), 0, 40)); $cands = [];
        foreach ($lib['groups'] ?? [] as $g) {
            if (!empty($g['audio'])) continue;
            $hit = count(array_intersect_key(array_flip(wf_words(($g['title'] ?? '') . ' ' . mb_substr((string) $g['best']['text'], 0, 1500))), $want));
            if ($hit) $cands[] = [$hit, $g];
        }
        usort($cands, fn($a, $b) => $b[0] <=> $a[0]);
        $ctx = '';
        foreach (array_slice($cands, 0, 4) as $i => $c) $ctx .= "### L" . ($i + 1) . ' [' . $c[1]['section'] . ' / ' . $c[1]['topic'] . "]\n" . mb_substr((string) $c[1]['best']['text'], 0, 1800) . "\n\n";
        $tone = in_array($in['tone'] ?? '', ['short', 'warm', 'formal'], true) ? $in['tone'] : 'warm';
        $prompt = wf_prompt('suggest_reply', wf_aid(), ['tone' => $tone, 'chat' => mb_substr($conv, -9000), 'library' => $ctx ?: '(none)']);
        $ai = wi_ai('Suggest a reply', $prompt, 190, ['subject' => $jid]);
        if (empty($ai['ok'])) wi_json(['ok' => false, 'error' => $ai['error'] ?? 'AI failed.']);
        wi_log('suggest_reply', ['account' => wf_aid(), 'jid' => $jid]);
        wi_json(['ok' => true, 'reply' => wf_clean_affection((string) ($ai['data']['reply'] ?? '')), 'note' => (string) ($ai['data']['note'] ?? ''), 'used' => count($ai['data']['used'] ?? []), 'model' => trim(($ai['provider'] ?? '') . ' ' . ($ai['model'] ?? ''))]);
    }

    /* ----- 4) Reaction ----- */
    case 'react': {
        $in = wf_post();
        if (!wi_can_reply()) wi_json(['ok' => false, 'error' => 'You do not have permission to reply.'], 403);
        $r = wi_gateway('/chat/react', ['jid' => wf_jid($in['jid'] ?? ''), 'id' => preg_replace('/[^0-9A-Za-z_-]/', '', (string) ($in['id'] ?? '')), 'emoji' => mb_substr((string) ($in['emoji'] ?? ''), 0, 4), 'by' => (string) $_SESSION['email']]);
        wi_json($r);
    }

    /* ----- 3) Scheduled messages ----- */
    case 'schedule': {
        $aid = wf_aid(); $all = wi_read('scheduled.json', []);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $list = array_values(array_filter($all, fn($s) => ($s['acc'] ?? '') === $aid && (empty($_GET['jid']) || $s['jid'] === wf_jid($_GET['jid']))));
            usort($list, fn($a, $b) => strcmp($a['at'], $b['at']));
            wi_json(['ok' => true, 'items' => array_slice(array_reverse($list), 0, 300)]);
        }
        $in = wf_post();
        if (!wi_can_reply()) wi_json(['ok' => false, 'error' => 'You do not have permission to reply.'], 403);
        if (($in['do'] ?? '') === 'cancel') {
            foreach ($all as &$s) if ($s['id'] === ($in['id'] ?? '') && $s['acc'] === $aid && $s['state'] === 'pending') { $s['state'] = 'cancelled'; $s['cancelled_by'] = $_SESSION['email']; }
            unset($s); wi_write('scheduled.json', $all); wi_json(['ok' => true]);
        }
        $jid = wf_jid($in['jid'] ?? ''); $text = trim((string) ($in['text'] ?? '')); $at = strtotime((string) ($in['at'] ?? ''));
        if ($jid === '' || $text === '') wi_json(['ok' => false, 'error' => 'Choose a chat and write the message.']);
        if (!$at || $at < time() + 30) wi_json(['ok' => false, 'error' => 'Choose a time in the future.']);
        if (mb_strlen($text) > 4000) wi_json(['ok' => false, 'error' => 'Message is too long.']);
        $all[] = ['id' => bin2hex(random_bytes(6)), 'acc' => $aid, 'jid' => $jid, 'name' => mb_substr((string) ($in['name'] ?? ''), 0, 80), 'text' => $text, 'at' => date('c', $at), 'state' => 'pending', 'by' => (string) $_SESSION['email'], 'created' => date('c')];
        wi_write('scheduled.json', $all); wi_log('schedule_add', ['account' => $aid, 'jid' => $jid, 'at' => date('c', $at)]);
        wi_json(['ok' => true]);
    }

    }
}
