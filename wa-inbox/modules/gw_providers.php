<?php
// WhatsApp Inbox — gateway providers. Every number talks to WhatsApp through one provider (accounts.json "provider"):
//   local  — our own gateway (one process per number, or the hub): the default, every feature;
//   openwa — our OpenWA server (sessions, sends, webhooks);
//   cloud  — the official WhatsApp Business Cloud API of Meta (a phone number id, templates outside the 24-hour window).
// wi_gateway() hands a call of a non-local number to wf_gw_provider(). Messages that come in through a provider's webhook
// (index.php?gw_hook=<number>) are written in the same archive format as the gateway's, so the inbox, search and the bot read
// them the same way. A feature a provider does not have answers "not supported by this provider", never a silent failure.
// Keys and tokens live only in the secrets store (wi_secret): never in the code, never shown or logged.

function wf_gw_provider_of(array $acc): string { $p = (string) ($acc['provider'] ?? 'local'); return in_array($p, ['openwa', 'cloud'], true) ? $p : 'local'; }
/** What each provider can do (the gateway paths the dashboard uses). */
function wf_gw_features(string $p): array {
    $common = ['/status', '/health', '/chat/send', '/send', '/archive/chats', '/archive/thread', '/archive', '/archive/search'];
    return ['local' => ['*'], 'openwa' => array_merge($common, ['/groups', '/qr.json', '/chat/delete']), 'cloud' => $common][$p] ?? [];
}
function wf_gw_unsupported(string $path, string $p): array {
    return ['ok' => false, 'unsupported' => true, 'error' => 'Not supported by this provider (' . $p . '): ' . (string) parse_url($path, PHP_URL_PATH)];
}
/** HTTP for the providers; tests replace it with $GLOBALS['WI_FAKE_HTTP'] (nothing leaves the server). */
function wf_gw_http(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 40): array {
    if (PHP_SAPI === 'cli' && isset($GLOBALS['WI_FAKE_HTTP']) && is_callable($GLOBALS['WI_FAKE_HTTP'])) return ($GLOBALS['WI_FAKE_HTTP'])($method, $url, $headers, $body);
    $ch = curl_init($url); $h = []; foreach ($headers as $k => $v) $h[] = $k . ': ' . $v;
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $h] + ($body !== null ? [CURLOPT_POSTFIELDS => $body] : []));
    $raw = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $err = curl_error($ch); curl_close($ch);
    return ['code' => $code, 'body' => is_string($raw) ? $raw : '', 'json' => is_string($raw) ? (json_decode($raw, true) ?: null) : null, 'error' => $err];
}
/** The archive of a provider number: the same files and rows as the gateway's. */
function wf_gw_archive_dir(array $acc): string { $d = dirname(__DIR__, 2) . '/wa-local/accounts/' . preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $acc['id'])) . '/archive'; if (!is_dir($d)) @mkdir($d, 0700, true); return $d; }
function wf_gw_append(array $acc, array $row): bool {
    $row = ['time' => gmdate('Y-m-d\TH:i:s.000\Z')] + $row; $f = wf_gw_archive_dir($acc) . '/' . substr((string) $row['time'], 0, 7) . '.jsonl';
    if (!empty($row['id']) && is_file($f)) { $tail = (string) @file_get_contents($f, false, null, max(0, (int) filesize($f) - 262144)); if (strpos($tail, '"id":' . json_encode((string) $row['id'])) !== false) return false; } // delivered twice
    return @file_put_contents($f, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX) !== false;
}
function wf_gw_phone(string $jid): string { return preg_replace('/\D/', '', explode('@', $jid)[0]); }
/** When the person last wrote (for the 24-hour window of the Cloud API). */
function wf_gw_last_in(array $acc, string $jid): int { if (!function_exists('wf_hist_rows')) return 0; foreach (wf_hist_rows(['id' => $acc['id']] + $acc, ['jid' => $jid, 'limit' => 200]) as $r) if (($r['dir'] ?? '') === 'in') return strtotime((string) $r['time']) ?: 0; return 0; }

/** A gateway call of a provider number. */
function wf_gw_provider(string $path, ?array $post, array $acc): array {
    $p = wf_gw_provider_of($acc); $route = (string) parse_url($path, PHP_URL_PATH);
    if (!in_array($route, wf_gw_features($p), true)) return wf_gw_unsupported($path, $p);
    // reading the messages: from the number's archive files (written by the webhook and by our sends)
    if (str_starts_with($route, '/archive') && function_exists('wf_hist_gateway')) return wf_hist_gateway($path, null, $acc);
    try { return $p === 'openwa' ? wf_gw_openwa($route, $path, $post, $acc) : wf_gw_cloud($route, $path, $post, $acc); }
    catch (Throwable $e) { return ['ok' => false, 'error' => 'Provider error: ' . $e->getMessage()]; }
}

/* ---------- OpenWA ---------- */
function wf_gw_openwa_cfg(array $acc): array {
    $aid = (string) $acc['id']; $base = rtrim((string) (($acc['openwa_url'] ?? '') ?: wi_sys('openwa_url')), '/');
    return ['base' => $base, 'session' => (string) ($acc['openwa_session'] ?? ''), 'key' => (string) (wi_secret('openwa_key:' . $aid) ?: wi_secret('openwa_key'))];
}
function wf_gw_openwa_jid(string $jid): string { return str_ends_with($jid, '@g.us') ? $jid : wf_gw_phone($jid) . '@c.us'; }
function wf_gw_openwa(string $route, string $path, ?array $post, array $acc): array {
    $c = wf_gw_openwa_cfg($acc); if ($c['base'] === '' || $c['session'] === '' || $c['key'] === '') return ['ok' => false, 'error' => 'OpenWA is not set up for this number (address, session or key missing).'];
    $call = function (string $m, string $p, ?array $b = null) use ($c) { $r = wf_gw_http($m, $c['base'] . $p, ['X-API-Key' => $c['key'], 'Content-Type' => 'application/json', 'Accept' => 'application/json'], $b !== null ? json_encode($b, JSON_UNESCAPED_UNICODE) : null);
        if ($r['code'] === 0) return ['ok' => false, 'error' => 'OpenWA did not answer.']; if ($r['code'] >= 400) return ['ok' => false, 'error' => 'OpenWA: ' . mb_substr((string) ($r['json']['message'] ?? $r['body']), 0, 200), 'code' => $r['code']];
        return ['ok' => true] + (array) ($r['json'] ?? []); };
    $sid = rawurlencode($c['session']);
    if ($route === '/status' || $route === '/health') { $r = $call('GET', '/api/sessions/' . $sid); if (empty($r['ok'])) return $r + ['connected' => false];
        $st = (string) ($r['status'] ?? ''); return ['ok' => true, 'connected' => $st === 'ready', 'connecting' => in_array($st, ['initializing', 'authenticating'], true), 'needs_qr' => $st === 'qr_ready', 'provider' => 'openwa', 'account' => ['id' => (string) ($r['phone'] ?? $r['phoneNumber'] ?? '')], 'last_error' => $st === 'failed' ? 'OpenWA session failed' : '']; }
    if ($route === '/qr.json') { $r = $call('GET', '/api/sessions/' . $sid . '/qr'); return ['ok' => !empty($r['ok']), 'qr' => (string) ($r['qr'] ?? $r['qrCode'] ?? $r['data'] ?? '')]; }
    if ($route === '/groups') { $r = $call('GET', '/api/sessions/' . $sid . '/groups'); if (empty($r['ok'])) return $r; $list = isset($r[0]) ? $r : (array) ($r['data'] ?? $r['groups'] ?? []); unset($list['ok']);
        return ['ok' => true, 'groups' => array_values(array_map(fn($g) => ['id' => (string) ($g['id'] ?? ''), 'subject' => (string) ($g['name'] ?? $g['subject'] ?? '')], array_filter($list, 'is_array')))]; }
    if ($route === '/chat/delete') { $r = $call('POST', '/api/sessions/' . $sid . '/messages/delete', ['chatId' => wf_gw_openwa_jid((string) ($post['jid'] ?? '')), 'messageId' => (string) ($post['id'] ?? '')]); return ['ok' => !empty($r['ok'])] + $r; }
    // sending: text, or a file of our storage (sent as base64 with its type)
    $jid = (string) ($post['jid'] ?? ''); if ($jid === '' && !empty($post['to'])) $jid = wf_gw_phone((string) $post['to']) . '@s.whatsapp.net';
    $text = (string) ($post['text'] ?? $post['message'] ?? ''); $att = (string) ($post['attachment'] ?? '');
    if ($jid === '') return ['ok' => false, 'error' => 'No chat to send to.'];
    if ($att !== '') { $file = dirname(__DIR__, 2) . '/storage/' . ltrim($att, '/'); if (!is_file($file) || filesize($file) > 16 * 1048576) return ['ok' => false, 'error' => 'The file to send was not found.'];
        $mime = (string) (mime_content_type($file) ?: 'application/octet-stream'); $kind = str_starts_with($mime, 'image/') ? 'image' : (str_starts_with($mime, 'video/') ? 'video' : (str_starts_with($mime, 'audio/') ? 'audio' : 'document'));
        $r = $call('POST', '/api/sessions/' . $sid . '/messages/send-' . $kind, ['chatId' => wf_gw_openwa_jid($jid), 'base64' => base64_encode((string) file_get_contents($file)), 'mimetype' => $mime, 'filename' => basename($file), 'caption' => $text]); }
    else { if (trim($text) === '') return ['ok' => false, 'error' => 'Empty message.']; $r = $call('POST', '/api/sessions/' . $sid . '/messages/send-text', ['chatId' => wf_gw_openwa_jid($jid), 'text' => $text]); $kind = 'text'; }
    if (empty($r['ok'])) return $r;
    $id = (string) ($r['messageId'] ?? ''); wf_gw_append($acc, ['dir' => 'out', 'id' => $id, 'jid' => $jid, 'phone' => wf_gw_phone($jid), 'kind' => $kind, 'text' => $text, 'via' => (string) ($post['via'] ?? 'inbox')] + (str_ends_with($jid, '@g.us') ? ['group' => true] : []) + ($att !== '' ? ['attachment' => $att] : []));
    return ['ok' => true, 'id' => $id, 'result' => ['key' => ['id' => $id]]];
}

/* ---------- WhatsApp Business Cloud API (Meta) ---------- */
function wf_gw_cloud_cfg(array $acc): array {
    $aid = (string) $acc['id'];
    return ['phone_id' => preg_replace('/\D/', '', (string) ($acc['cloud_phone_id'] ?? '')), 'graph' => preg_match('/^v\d+\.\d+$/', (string) ($acc['cloud_graph'] ?? '')) ? (string) $acc['cloud_graph'] : 'v21.0', 'token' => (string) wi_secret('cloud_token:' . $aid)];
}
function wf_gw_cloud(string $route, string $path, ?array $post, array $acc): array {
    $c = wf_gw_cloud_cfg($acc); if ($c['phone_id'] === '' || $c['token'] === '') return ['ok' => false, 'error' => 'The Cloud API is not set up for this number (phone number id or token missing).'];
    $call = function (string $m, string $p, ?array $b = null) use ($c) { $r = wf_gw_http($m, 'https://graph.facebook.com/' . $c['graph'] . '/' . ltrim($p, '/'), ['Authorization' => 'Bearer ' . $c['token'], 'Content-Type' => 'application/json'], $b !== null ? json_encode($b, JSON_UNESCAPED_UNICODE) : null);
        if ($r['code'] === 0) return ['ok' => false, 'error' => 'The Cloud API did not answer.']; if ($r['code'] >= 400 || isset($r['json']['error'])) return ['ok' => false, 'error' => 'Cloud API: ' . mb_substr((string) ($r['json']['error']['message'] ?? $r['body']), 0, 200), 'code' => $r['code']];
        return ['ok' => true] + (array) ($r['json'] ?? []); };
    if ($route === '/status' || $route === '/health') { $r = $call('GET', $c['phone_id'] . '?fields=display_phone_number,verified_name,quality_rating');
        return empty($r['ok']) ? $r + ['connected' => false] : ['ok' => true, 'connected' => true, 'provider' => 'cloud', 'account' => ['id' => preg_replace('/\D/', '', (string) ($r['display_phone_number'] ?? '')), 'name' => (string) ($r['verified_name'] ?? '')], 'quality' => (string) ($r['quality_rating'] ?? '')]; }
    $jid = (string) ($post['jid'] ?? ''); if ($jid === '' && !empty($post['to'])) $jid = wf_gw_phone((string) $post['to']) . '@s.whatsapp.net';
    if ($jid === '' || str_ends_with($jid, '@g.us')) return ['ok' => false, 'unsupported' => str_ends_with($jid, '@g.us'), 'error' => str_ends_with($jid, '@g.us') ? 'Not supported by this provider (cloud): groups' : 'No chat to send to.'];
    $to = wf_gw_phone($jid); $text = (string) ($post['text'] ?? $post['message'] ?? '');
    // outside 24 hours after the person's last message only an approved template may be sent
    $open = time() - wf_gw_last_in($acc, $jid) < 86400;
    if (!$open && empty($post['template'])) return ['ok' => false, 'needs_template' => true, 'error' => 'Outside the 24-hour window: only an approved template message can be sent to this person.'];
    if (!empty($post['template'])) { $t = (array) $post['template']; $body = ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'template', 'template' => ['name' => (string) ($t['name'] ?? ''), 'language' => ['code' => (string) ($t['lang'] ?? 'ar')]] + (!empty($t['params']) ? ['components' => [['type' => 'body', 'parameters' => array_map(fn($x) => ['type' => 'text', 'text' => (string) $x], (array) $t['params'])]]] : [])]; $kind = 'text'; }
    elseif (!empty($post['attachment'])) { return wf_gw_unsupported('/chat/send (file)', 'cloud'); }
    else { if (trim($text) === '') return ['ok' => false, 'error' => 'Empty message.']; $body = ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'text', 'text' => ['body' => $text, 'preview_url' => true]]; $kind = 'text'; }
    $r = $call('POST', $c['phone_id'] . '/messages', $body); if (empty($r['ok'])) return $r;
    $id = (string) ($r['messages'][0]['id'] ?? ''); wf_gw_append($acc, ['dir' => 'out', 'id' => $id, 'jid' => $jid, 'phone' => $to, 'kind' => $kind, 'text' => $text !== '' ? $text : '[template ' . (string) ($post['template']['name'] ?? '') . ']', 'via' => (string) ($post['via'] ?? 'inbox')]);
    return ['ok' => true, 'id' => $id, 'result' => ['key' => ['id' => $id]]];
}

/* ---------- incoming messages (webhooks) ---------- */
/** index.php?gw_hook=<number>: OpenWA posts its events here (HMAC with the number's secret); Meta verifies (GET) and posts (signed). */
function wf_gw_hook(string $aid): void {
    $acc = wi_accounts()[$aid] ?? null; $p = $acc ? wf_gw_provider_of($acc) : 'local';
    if (!$acc || $p === 'local') { http_response_code(404); exit; }
    if ($p === 'cloud' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') { $vt = (string) wi_secret('cloud_verify:' . $aid);
        if ($vt !== '' && ($_GET['hub_mode'] ?? '') === 'subscribe' && hash_equals($vt, (string) ($_GET['hub_verify_token'] ?? ''))) { header('Content-Type: text/plain'); echo (string) ($_GET['hub_challenge'] ?? ''); exit; }
        http_response_code(403); exit; }
    $raw = wi_input(); if (!wf_gw_hook_signed($p, $aid, $raw)) { http_response_code(403); exit; }
    http_response_code(200); echo 'ok'; if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    $in = json_decode($raw, true); $n = is_array($in) ? wf_gw_take($acc, $p, $in) : 0;
    if ($n > 0 && function_exists('wf_bot_tick') && function_exists('wf_bot_run_cfg') && wf_bot_run_cfg($aid)['mode'] !== 'off') { try { wf_bot_tick($acc, true); } catch (Throwable $e) {} }
}
/** The webhook is ours: OpenWA signs with the number's secret, Meta with the app secret (sha256 HMAC of the body). */
function wf_gw_hook_signed(string $p, string $aid, string $raw): bool {
    $sec = (string) wi_secret(($p === 'cloud' ? 'cloud_app_secret:' : 'openwa_hook_secret:') . $aid); if ($sec === '') return false;
    $want = hash_hmac('sha256', $raw, $sec);
    foreach (['HTTP_X_HUB_SIGNATURE_256', 'HTTP_X_WEBHOOK_SIGNATURE', 'HTTP_X_OPENWA_SIGNATURE', 'HTTP_X_SIGNATURE'] as $h) { $got = (string) ($_SERVER[$h] ?? ''); if ($got !== '' && hash_equals($want, preg_replace('/^sha256=/', '', $got))) return true; }
    return false;
}
/** Rows from a webhook body (the gateway's archive format). Returns how many new incoming messages were written. */
function wf_gw_take(array $acc, string $p, array $in): int {
    $n = 0;
    if ($p === 'cloud') {
        foreach ((array) ($in['entry'] ?? []) as $en) foreach ((array) ($en['changes'] ?? []) as $ch) { $v = (array) ($ch['value'] ?? []); $names = [];
            foreach ((array) ($v['contacts'] ?? []) as $ct) $names[(string) ($ct['wa_id'] ?? '')] = (string) ($ct['profile']['name'] ?? '');
            foreach ((array) ($v['messages'] ?? []) as $m) { $ph = preg_replace('/\D/', '', (string) ($m['from'] ?? '')); if ($ph === '') continue; $t = (string) ($m['type'] ?? 'text');
                $text = (string) ($m['text']['body'] ?? $m['button']['text'] ?? $m['interactive']['button_reply']['title'] ?? $m['image']['caption'] ?? '');
                $row = ['time' => gmdate('Y-m-d\TH:i:s.000\Z', (int) ($m['timestamp'] ?? time())), 'dir' => 'in', 'id' => (string) ($m['id'] ?? ''), 'jid' => $ph . '@s.whatsapp.net', 'phone' => $ph, 'name' => $names[$ph] ?? '', 'kind' => ['image' => 'image', 'video' => 'video', 'audio' => 'voice', 'document' => 'document', 'sticker' => 'sticker'][$t] ?? 'text', 'text' => $text];
                if (wf_gw_append($acc, $row)) $n++; } }
        return $n;
    }
    // OpenWA: {event, data:{…}} (or a list of them); only received messages are written
    foreach (isset($in['event']) ? [$in] : (array) $in as $ev) { if (!is_array($ev) || !in_array((string) ($ev['event'] ?? ''), ['message.received', 'message'], true)) continue;
        $d = (array) ($ev['data'] ?? $ev['payload'] ?? []); if (!empty($d['fromMe'])) continue;
        $from = (string) ($d['from'] ?? $d['chatId'] ?? ''); $group = str_ends_with($from, '@g.us'); $ph = preg_replace('/\D/', '', explode('@', $group ? (string) ($d['author'] ?? $d['participant'] ?? '') : $from)[0]);
        if ($from === '') continue; $jid = $group ? $from : $ph . '@s.whatsapp.net'; $ts = (int) ($d['timestamp'] ?? time()); if ($ts > 20000000000) $ts = (int) floor($ts / 1000);
        $row = ['time' => gmdate('Y-m-d\TH:i:s.000\Z', $ts), 'dir' => 'in', 'id' => (string) ($d['id'] ?? $d['messageId'] ?? ''), 'jid' => $jid, 'phone' => $group ? '' : $ph, 'name' => (string) ($d['notifyName'] ?? $d['pushName'] ?? ''), 'kind' => in_array((string) ($d['type'] ?? 'chat'), ['image', 'video', 'document', 'sticker'], true) ? (string) $d['type'] : (in_array((string) ($d['type'] ?? ''), ['ptt', 'audio'], true) ? 'voice' : 'text'), 'text' => (string) ($d['body'] ?? $d['text'] ?? $d['caption'] ?? '')]
            + ($group ? ['group' => true, 'from' => $ph] : []);
        if (wf_gw_append($acc, $row)) $n++; }
    return $n;
}
