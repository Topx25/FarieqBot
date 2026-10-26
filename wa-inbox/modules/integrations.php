<?php
// WhatsApp Inbox — Integrations: another system (a store, a CRM) uses one of the panel's WhatsApp numbers.
//   In:  every private message the number receives is sent to the system's webhook (POST JSON, with its token header), within a minute.
//   Out: the system sends messages through the number: POST ?int_api=send with the header X-Api-Key.
// Settings in integrations.json; the webhook token and the API key are kept encrypted / hashed (wi_secret), never shown again.

/** Every integration (id => settings). */
function wf_int_all(): array { return (array) wi_read('integrations.json', []); }
/** The integrations this user may see: all for the super admin, their own numbers' for a client. */
function wf_int_mine(): array {
    if (wi_is_super()) return wf_int_all(); $me = strtolower((string) (wi_me()['email'] ?? '')); $own = wi_owned_accounts($me);
    return array_filter(wf_int_all(), fn($x) => isset($own[(string) ($x['aid'] ?? '')]));
}
/** Numbers an integration may use for this user. */
function wf_int_numbers(): array {
    $all = wi_is_super() ? wi_accounts() : wi_owned_accounts((string) (wi_me()['email'] ?? ''));
    return array_filter($all, fn($a) => ($a['channel'] ?? '') !== 'fb');
}
/** A new API key: shown once, only its hash is kept. */
function wf_int_new_key(string $id): string { $k = 'fk_' . bin2hex(random_bytes(24)); wi_secret_set('int_key:' . $id, hash('sha256', $k)); return $k; }

/** Every minute (cron): new private messages of each active integration's number go to its webhook. A failed delivery is tried
 *  again next minute (the place is kept); after 60 failed minutes the waiting messages are skipped so new ones are not held back. */
function wf_int_tick(): void {
    $st = (array) wi_read('int_state.json', []); $changed = false;
    foreach (wf_int_all() as $id => $x) {
        if (empty($x['active']) || empty($x['url'])) continue; $acc = wi_accounts()[(string) ($x['aid'] ?? '')] ?? null; if (!$acc || empty($acc['enabled'])) continue;
        $cur = (string) ($st[$id]['cursor'] ?? ''); if ($cur === '') { $st[$id]['cursor'] = gmdate('Y-m-d\TH:i:s.v\Z'); $changed = true; continue; } // starts with what comes from now on
        $r = wi_gateway('/archive?limit=300&months=1', null, $acc); if (empty($r['ok'])) continue;
        $new = array_values(array_filter((array) ($r['rows'] ?? []), fn($m) => ($m['dir'] ?? '') === 'in' && !in_array($m['kind'] ?? '', ['reaction', 'deleted', 'edit'], true) && strcmp((string) ($m['time'] ?? ''), $cur) > 0 && !str_ends_with((string) ($m['jid'] ?? ''), '@g.us') && !str_contains((string) ($m['jid'] ?? ''), 'status@')));
        usort($new, fn($a, $b) => strcmp((string) $a['time'], (string) $b['time']));
        foreach (array_slice($new, 0, 50) as $m) {
            $res = wf_int_post($id, $x, wf_int_payload($x, $acc, $m));
            if (!$res['ok']) { $st[$id]['fail_since'] ??= time(); $st[$id]['last'] = ['at' => date('c'), 'ok' => false, 'code' => $res['code'], 'error' => $res['error']]; $changed = true;
                if (time() - (int) $st[$id]['fail_since'] > 3600) { $st[$id]['cursor'] = (string) end($new)['time']; $st[$id]['skipped'] = (int) ($st[$id]['skipped'] ?? 0) + count($new); unset($st[$id]['fail_since']); wi_log('int_skipped', ['id' => $id, 'count' => count($new)]); }
                break; }
            $st[$id]['cursor'] = (string) $m['time']; $st[$id]['sent'] = (int) ($st[$id]['sent'] ?? 0) + 1; $st[$id]['last'] = ['at' => date('c'), 'ok' => true, 'code' => $res['code']]; unset($st[$id]['fail_since']); $changed = true;
        }
    }
    if ($changed) wi_write('int_state.json', $st);
}
/** The JSON a webhook receives for one message. */
function wf_int_payload(array $x, array $acc, array $m): array {
    $from = preg_replace('/\D/', '', (string) (($m['phone'] ?? '') ?: strtok((string) ($m['jid'] ?? ''), '@')));
    return ['event' => 'message.received', 'integration' => (string) ($x['name'] ?? ''), 'number' => (string) ($acc['id'] ?? ''),
        'data' => ['id' => (string) ($m['id'] ?? ''), 'from' => $from, 'jid' => (string) ($m['jid'] ?? ''), 'name' => (string) ($m['name'] ?? ''), 'body' => (string) ($m['text'] ?? ''),
            'type' => (string) (($m['kind'] ?? '') ?: 'text'), 'has_media' => !empty($m['attachment']), 'time' => (string) ($m['time'] ?? '')]
            + (!empty($m['attachment']) ? ['media_url' => rtrim((string) wi_sys('base_url'), '/') . '/?int_api=media&f=' . rawurlencode((string) $m['attachment'])] : [])];
}
/** One POST to the integration's webhook. */
function wf_int_post(string $id, array $x, array $payload): array {
    $tok = wi_secret('int_hook:' . $id); $hdr = preg_replace('/[^A-Za-z0-9-]/', '', (string) (($x['header'] ?? '') ?: 'X-Webhook-Token'));
    $ch = curl_init((string) $x['url']); $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 45, CURLOPT_CONNECTTIMEOUT => 8, // a system may answer with AI before it replies
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json', 'User-Agent: Fariek-Integrations/1'], $tok !== '' ? [$hdr . ': ' . $tok] : [])]);
    $out = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    return ['ok' => $code >= 200 && $code < 300, 'code' => $code, 'error' => $err !== '' ? $err : ($code >= 300 ? mb_substr((string) $out, 0, 200) : '')];
}

/** ?int_api=send — another system sends a message through the number. Header X-Api-Key; JSON {"to": "2010…" or the chat id "…@lid", "text": "…"}.
 *  ?int_api=media&f=… — the file of a message (its media_url), only from the integration's own number. */
function wf_int_api(): void {
    header('X-Robots-Tag: noindex');
    $fail = function (int $c, string $e) { header('Content-Type: application/json; charset=utf-8'); http_response_code($c); echo json_encode(['ok' => false, 'error' => $e]); exit; };
    $what = (string) ($_GET['int_api'] ?? ''); if (!in_array($what, ['send', 'media'], true) || ($what === 'send' && $_SERVER['REQUEST_METHOD'] !== 'POST')) $fail(404, 'Use POST ?int_api=send or GET ?int_api=media');
    $key = (string) ($_SERVER['HTTP_X_API_KEY'] ?? ''); if (strlen($key) < 20) $fail(401, 'Missing X-Api-Key');
    $hash = hash('sha256', $key); $id = null; foreach (wf_int_all() as $iid => $x) if (hash_equals((string) wi_secret('int_key:' . $iid), $hash)) { $id = (string) $iid; break; }
    if ($id === null) $fail(401, 'Unknown API key'); $x = wf_int_all()[$id];
    if (empty($x['active'])) $fail(403, 'This integration is switched off');
    if ($what === 'media') { $f = (string) ($_GET['f'] ?? ''); $aid = preg_replace('/[^a-z0-9_-]/', '', (string) $x['aid']);
        if (str_contains($f, '..') || !preg_match('#^inbox_media/' . preg_quote($aid, '#') . '/[A-Za-z0-9_./-]+$#', $f)) $fail(403, 'Not a file of this number');
        $p = dirname(__DIR__, 2) . '/storage/' . $f; if (!is_file($p)) $fail(404, 'File not found');
        header('Content-Type: ' . (mime_content_type($p) ?: 'application/octet-stream')); header('Content-Length: ' . filesize($p)); header('Content-Disposition: inline; filename="' . basename($p) . '"');
        readfile($p); exit; }
    header('Content-Type: application/json; charset=utf-8');
    // at most 60 messages a minute per integration (protects the number)
    $rl = (array) wi_read('int_rate.json', []); $win = (int) floor(time() / 60); if ((int) ($rl[$id]['w'] ?? 0) !== $win) $rl[$id] = ['w' => $win, 'n' => 0];
    if (++$rl[$id]['n'] > 60) $fail(429, 'Too many messages this minute'); wi_write('int_rate.json', $rl);
    $in = json_decode((string) file_get_contents('php://input'), true) ?: $_POST;
    $raw = trim((string) ($in['to'] ?? ($in['jid'] ?? ''))); $text = trim((string) ($in['text'] ?? ($in['message'] ?? '')));
    // a chat id (…@lid for a hidden number, …@s.whatsapp.net): the reply goes into that existing conversation
    $jid = preg_match('/^[0-9A-Za-z._:-]+@(s\.whatsapp\.net|lid)$/', $raw) ? $raw : ''; $to = $jid === '' ? preg_replace('/\D/', '', $raw) : '';
    if (($jid === '' && strlen($to) < 8) || $text === '' || mb_strlen($text) > 4000) $fail(422, 'Give "to" (international number or the chat id from the webhook) and "text" (up to 4000 characters)');
    $acc = wi_accounts()[(string) ($x['aid'] ?? '')] ?? null; if (!$acc || empty($acc['enabled'])) $fail(503, 'The WhatsApp number is not available');
    $r = $jid !== '' ? wi_gateway('/chat/send', ['jid' => $jid, 'text' => $text], $acc) : wi_gateway('/send', ['to' => $to, 'message' => $text, 'via' => 'integration'], $acc);
    wi_update('int_state.json', function ($S) use ($id, $r) { $S[$id]['out'] = (int) ($S[$id]['out'] ?? 0) + (!empty($r['ok']) ? 1 : 0); $S[$id]['last_out'] = ['at' => date('c'), 'ok' => !empty($r['ok'])]; return $S; });
    if (empty($r['ok'])) $fail(502, (string) ($r['error'] ?? 'WhatsApp did not send'));
    echo json_encode(['ok' => true, 'id' => (string) ($r['id'] ?? ($r['messageId'] ?? ''))]); exit;
}

/** The Integrations page. */
function wf_int_page(): string {
    $t = fn($s) => wi_e(wi_t($s)); $base = rtrim((string) wi_sys('base_url'), '/') . '/'; $st = (array) wi_read('int_state.json', []); $nums = wf_int_numbers();
    $new = (array) ($_SESSION['int_new_key'] ?? []); unset($_SESSION['int_new_key']);
    $o = '<section class="hero"><div class="hero-t"><span class="hero-ic">🔌</span><div><h1>' . $t('Integrations') . '</h1><p class="muted">' . $t('Connect another system (a store, a CRM) to a WhatsApp number: messages that come in are sent to its webhook, and it sends messages through the number with its API key.') . '</p></div></div></section>';
    if ($new) $o .= '<div class="alert ok">🔑 ' . $t('The API key of') . ' <b>' . wi_e((string) $new['name']) . '</b>: <code dir="ltr" style="user-select:all">' . wi_e((string) $new['key']) . '</code><br>' . $t('Copy it now and put it in the other system. It is not shown again.') . '</div>';
    foreach (wf_int_mine() as $id => $x) { $s = (array) ($st[$id] ?? []); $l = (array) ($s['last'] ?? []); $acc = $nums[(string) ($x['aid'] ?? '')] ?? null;
        $o .= '<article class="card pad" style="margin:0 0 12px"><div class="row wrap" style="justify-content:space-between"><div><b dir="auto" style="font-size:16px">' . wi_e((string) $x['name']) . '</b> '
            . '<span class="sa-chip ' . (!empty($x['active']) ? 'ok">' . $t('On') : '">' . $t('Off')) . '</span></div><span class="muted small" dir="ltr">' . wi_e($acc ? wi_account_label($acc) : (string) ($x['aid'] ?? '')) . '</span></div>'
            . '<div class="cl-grid" style="margin-top:8px"><div><small>' . $t('Webhook (messages in)') . '</small><b dir="ltr" style="word-break:break-all">' . wi_e((string) $x['url']) . '</b><em class="muted" dir="ltr">' . wi_e((string) (($x['header'] ?? '') ?: 'X-Webhook-Token')) . ': ' . (wi_secret('int_hook:' . $id) !== '' ? '••••••' : $t('none')) . '</em></div>'
            . '<div><small>' . $t('Sent to the webhook') . '</small><b>' . (int) ($s['sent'] ?? 0) . '</b><em class="' . (isset($l['ok']) && !$l['ok'] ? 'bad-t' : 'muted') . '">' . (isset($l['at']) ? wi_e(substr((string) $l['at'], 0, 16)) . ' · ' . (!empty($l['ok']) ? 'HTTP ' . (int) $l['code'] : wi_e(wi_t('failed')) . ' HTTP ' . (int) ($l['code'] ?? 0) . ' ' . wi_e((string) ($l['error'] ?? ''))) : $t('nothing yet')) . '</em></div>'
            . '<div><small>' . $t('Sent through the API') . '</small><b>' . (int) ($s['out'] ?? 0) . '</b><em class="muted">' . (isset($s['last_out']['at']) ? wi_e(substr((string) $s['last_out']['at'], 0, 16)) : $t('nothing yet')) . '</em></div></div>'
            . '<form method="post" class="row wrap" style="margin-top:10px;gap:8px"><input type="hidden" name="_csrf" value="' . wi_e(wi_csrf()) . '"><input type="hidden" name="do" value="int_act"><input type="hidden" name="id" value="' . wi_e((string) $id) . '">'
            . '<button class="btn ghost sm" name="act" value="test">🧪 ' . $t('Send a test to the webhook') . '</button><button class="btn ghost sm" name="act" value="key" onclick="return confirm(\'' . $t('Make a new API key? The old one stops working.') . '\')">🔑 ' . $t('New API key') . '</button>'
            . '<button class="btn ghost sm" name="act" value="toggle">' . (!empty($x['active']) ? '⏸ ' . $t('Switch off') : '▶️ ' . $t('Switch on')) . '</button><button class="btn ghost sm" name="act" value="delete" onclick="return confirm(\'' . $t('Delete this integration?') . '\')">🗑 ' . $t('Delete') . '</button></form></article>'; }
    $o .= '<details class="card pad" style="margin:0 0 12px"' . (wf_int_mine() ? '' : ' open') . '><summary><b>➕ ' . $t('New integration') . '</b></summary><form method="post" class="stack" style="margin-top:10px;max-width:640px"><input type="hidden" name="_csrf" value="' . wi_e(wi_csrf()) . '"><input type="hidden" name="do" value="int_save">'
        . '<label>' . $t('Name') . '<input name="name" required maxlength="60" placeholder="iptvpro.org" dir="auto"></label>'
        . '<label>' . $t('WhatsApp number') . '<select name="aid" required>' . implode('', array_map(fn($a) => '<option value="' . wi_e((string) $a['id']) . '">' . wi_e(wi_account_label($a)) . '</option>', $nums)) . '</select></label>'
        . '<label>' . $t('Webhook address (messages in)') . '<input name="url" type="url" required placeholder="https://example.com/wa/webhook" dir="ltr"></label>'
        . '<div class="row wrap"><label style="flex:1">' . $t('Header name') . '<input name="header" value="X-Webhook-Token" dir="ltr"></label><label style="flex:2">' . $t('Webhook token (the other system gives it)') . '<input name="token" type="password" autocomplete="off" dir="ltr"></label></div>'
        . '<button class="btn primary">💾 ' . $t('Create and show the API key') . '</button></form></details>';
    $o .= '<div class="card pad"><h2 style="margin:0 0 8px">📘 ' . $t('For the developer of the other system') . '</h2>'
        . '<h3>1. ' . $t('Messages in (Fariek → your webhook)') . '</h3><p class="small muted">' . $t('Within a minute of each private message. Answer with HTTP 200; any other answer is tried again the next minute.') . '</p>'
        . '<pre dir="ltr" style="overflow-x:auto;font-size:12.5px">POST https://your-site/wa/webhook\nX-Webhook-Token: &lt;your token&gt;\nContent-Type: application/json\n\n{"event":"message.received","integration":"iptvpro.org","number":"main",\n "data":{"id":"3EB0…","from":"2010xxxxxxxx","jid":"2010xxxxxxxx@s.whatsapp.net","name":"Ahmed",\n         "body":"Hello","type":"image","has_media":true,"time":"2026-10-26T14:05:00.000Z",\n         "media_url":"' . wi_e($base) . '?int_api=media&amp;f=inbox_media/…"}}\n\nmedia_url: GET with the same X-Api-Key header → the file (photo, voice, document).</pre>'
        . '<h3>2. ' . $t('Send a message (your system → Fariek)') . '</h3><pre dir="ltr" style="overflow-x:auto;font-size:12.5px">POST ' . wi_e($base) . '?int_api=send\nX-Api-Key: &lt;the API key shown when the integration was made&gt;\nContent-Type: application/json\n\n{"to":"2010xxxxxxxx","text":"Your order is ready"}\nor reply in the same chat (needed for hidden numbers): {"to":"&lt;data.jid from the webhook&gt;","text":"…"}\n\n→ {"ok":true,"id":"3EB0…"}   errors: 401 key, 422 data, 429 more than 60 a minute, 502 WhatsApp</pre></div>';
    return $o;
}
