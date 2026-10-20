<?php
// WhatsApp Inbox — Facebook Messenger channel. A Facebook page is an account like a number: its Messenger chats appear in the same
// inbox and the bot, library, tags, notes and team tools work on them through the same gateway calls (wf_fb_gateway answers them here).

/* ---------- accounts and settings ---------- */
function wf_fb_is(?array $acc): bool { return $acc && ($acc['channel'] ?? '') === 'fb'; }
function wf_fb_cfg(string $aid): array { return (array) (wi_read('fb.json', [])[$aid] ?? []) + ['page_id' => '', 'page_name' => '', 'app_id' => '', 'graph' => 'v26.0', 'verify' => '', 'ok_at' => '', 'error' => '', 'hook_at' => '', 'comment_bot' => 'off', 'comment_public' => 1, 'comment_private' => 0, 'app_scope' => '', 'via' => '', 'parent' => '', 'missing' => []]; }
function wf_fb_cfg_set(string $aid, array $set): void { wi_update('fb.json', function ($F) use ($aid, $set) { $F[$aid] = $set + (array) ($F[$aid] ?? []); return $F; }); }
function wf_fb_token(string $aid): string { return (string) wi_secret('fb_page_token:' . $aid, ''); }
function wf_fb_secret(string $aid): string { return (string) wi_secret('fb_app_secret:' . $aid, ''); }
/** The address Facebook calls; built from the address the page is opened on. */
function wf_fb_hook_url(string $aid): string {
    $host = (string) ($_SERVER['HTTP_HOST'] ?? ''); $dir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/');
    return ($host === '' ? '' : 'https://' . $host . $dir . '/') . 'index.php?fb_hook=' . rawurlencode($aid);
}
function wf_fb_account_for_page(string $pageId): ?array {
    foreach (wi_accounts() as $a) if (wf_fb_is($a) && wf_fb_cfg((string) $a['id'])['page_id'] === $pageId) return $a;
    return null;
}

/** The number a Facebook page is linked to (its business knowledge is used for the page's replies), or null. */
function wf_fb_parent(?array $acc): ?array { if (!wf_fb_is($acc)) return null; $p = (string) wf_fb_cfg((string) $acc['id'])['parent']; $all = wi_accounts(); return $p !== '' && isset($all[$p]) && !wf_fb_is($all[$p]) ? $all[$p] : null; }

/* ---------- Graph calls ---------- */
function wf_fb_graph(string $aid, string $method, string $path, array $params = [], ?array $file = null): array {
    if (PHP_SAPI === 'cli' && isset($GLOBALS['WI_FAKE_GRAPH']) && is_callable($GLOBALS['WI_FAKE_GRAPH'])) return ($GLOBALS['WI_FAKE_GRAPH'])($method, $path, $params); // tests: nothing leaves the server
    $c = wf_fb_cfg($aid); $tok = wf_fb_token($aid); if ($tok === '') return ['ok' => false, 'error' => 'The page token is missing.'];
    $url = 'https://graph.facebook.com/' . $c['graph'] . '/' . ltrim($path, '/');
    $ch = curl_init(); $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok]];
    if ($method === 'GET' || $method === 'DELETE') { $url .= ($params ? '?' . http_build_query($params) : ''); if ($method === 'DELETE') $opt[CURLOPT_CUSTOMREQUEST] = 'DELETE'; }
    else { $opt[CURLOPT_POST] = true; if ($file) { $f = $params; foreach ($f as $k => $v) if (is_array($v)) $f[$k] = json_encode($v, JSON_UNESCAPED_UNICODE); $f[$file['field'] ?? 'filedata'] = new CURLFile($file['path'], $file['mime'], $file['name']); $opt[CURLOPT_POSTFIELDS] = $f; }
        else { $opt[CURLOPT_POSTFIELDS] = json_encode($params, JSON_UNESCAPED_UNICODE); $opt[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json'; } }
    $opt[CURLOPT_URL] = $url; curl_setopt_array($ch, $opt); $raw = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    $r = json_decode((string) $raw, true);
    if (!is_array($r)) return ['ok' => false, 'error' => 'Facebook did not answer.'];
    if (isset($r['error']) && (int) ($r['error']['code'] ?? 0) === 190) wf_fb_cfg_set($aid, ['error' => 'The page token expired or was revoked. Connect the page again.']);
    if (isset($r['error'])) return ['ok' => false, 'error' => (string) ($r['error']['message'] ?? 'Facebook refused the request.'), 'code' => (int) ($r['error']['code'] ?? 0), 'sub' => (int) ($r['error']['error_subcode'] ?? 0)];
    return ['ok' => $code < 400] + $r;
}

/* ---------- archive (same rows as the number gateway) ---------- */
function wf_fb_dir(string $aid): string { $d = dirname(__DIR__, 2) . '/storage/fb/' . preg_replace('/[^A-Za-z0-9_-]/', '', $aid); if (!is_dir($d)) @mkdir($d, 0700, true); return $d; }
function wf_fb_append(string $aid, array $row): void {
    $row = ['time' => gmdate('c')] + $row; if (isset($row['ts'])) { $row['time'] = gmdate('c', (int) $row['ts']); unset($row['ts']); }
    $row['time'] = preg_replace('/\+00:00$/', '.000Z', $row['time']);
    if (!empty($row['id']) && in_array($row['dir'], ['in', 'out'], true)) { // Facebook repeats deliveries and echoes our own sends
        $seen = false;
        foreach (wf_fb_rows($aid, ['jid' => $row['jid'], 'limit' => 80]) as $o) if (($o['id'] ?? '') === $row['id'] && $o['dir'] === $row['dir']) { $seen = true; break; }
        if ($seen) return;
    }
    $fp = fopen(wf_fb_dir($aid) . '/' . substr($row['time'], 0, 7) . '.jsonl', 'ab'); if (!$fp) return; flock($fp, LOCK_EX); fwrite($fp, json_encode($row, JSON_UNESCAPED_UNICODE) . "\n"); flock($fp, LOCK_UN); fclose($fp);
    wf_fb_event($aid, $row);
}
/** Rows newest first. opts: jid, limit, months. */
function wf_fb_rows(string $aid, array $o = []): array {
    $files = glob(wf_fb_dir($aid) . '/*.jsonl') ?: []; rsort($files); $limit = max(1, (int) ($o['limit'] ?? 5000)); $months = (int) ($o['months'] ?? 36); $out = [];
    foreach (array_slice($files, 0, max(1, $months)) as $f) {
        $lines = @file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        for ($i = count($lines) - 1; $i >= 0; $i--) { $r = json_decode($lines[$i], true); if (!is_array($r)) continue;
            if (!empty($o['jid']) && ($r['jid'] ?? '') !== $o['jid']) continue; $out[] = $r; if (count($out) >= $limit) return $out; }
    }
    return $out;
}
function wf_fb_event(string $aid, array $row): void {
    if (!in_array($row['dir'] ?? '', ['in', 'out', 'deleted'], true)) return;
    wi_update('fb_events.json', function ($E) use ($aid, $row) { $e = (array) ($E[$aid] ?? ['seq' => 0, 'events' => []]); $e['seq']++; $e['events'][] = ['seq' => $e['seq'], 'type' => 'msg', 'row' => $row]; $e['events'] = array_slice($e['events'], -200); $E[$aid] = $e; return $E; });
}

/* ---------- people ---------- */
function wf_fb_person(string $aid, string $psid): array {
    $N = wi_read('fb_names.json', []); $p = $N[$aid][$psid] ?? null;
    if (is_array($p) && ($p['name'] ?? '') !== '') return $p;
    if (is_array($p) && (int) ($p['tried'] ?? 0) > time() - 3600) return $p; // not asked again for an hour
    $r = wf_fb_graph($aid, 'GET', $psid, ['fields' => 'name,first_name,last_name,profile_pic']); $name = trim((string) ($r['name'] ?? (($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''))));
    $p = ['name' => $name, 'pic' => (string) ($r['profile_pic'] ?? ''), 'tried' => time()];
    wi_update('fb_names.json', function ($N) use ($aid, $psid, $p) { $N[$aid][$psid] = $p; return $N; });
    return $p;
}

/* ---------- receiving (the webhook) ---------- */
function wf_fb_save_media(string $aid, string $url, string $mid, string $kind): string {
    if ($url === '' || !preg_match('#^https://#', $url)) return '';
    $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS]);
    $data = curl_exec($ch); $mime = strtolower(trim(explode(';', (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE))[0])); curl_close($ch);
    if (!is_string($data) || $data === '' || strlen($data) > 64 * 1024 * 1024) return '';
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'video/mp4' => 'mp4', 'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a', 'audio/aac' => 'aac', 'audio/ogg' => 'ogg', 'application/pdf' => 'pdf'][$mime] ?? ($kind === 'image' ? 'jpg' : ($kind === 'video' ? 'mp4' : ($kind === 'audio' ? 'm4a' : 'bin')));
    $dir = dirname(__DIR__, 2) . '/storage/inbox_media/' . $aid; if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $name = 'fb_' . substr(preg_replace('/[^A-Za-z0-9]/', '', $mid), -24) . '.' . $ext; if (@file_put_contents($dir . '/' . $name, $data) === false) return '';
    return 'inbox_media/' . $aid . '/' . $name;
}
function wf_fb_take(array $acc, array $ev, string $pageId): void {
    $aid = (string) $acc['id']; $echo = !empty($ev['message']['is_echo']); $sender = (string) ($ev['sender']['id'] ?? ''); $recip = (string) ($ev['recipient']['id'] ?? '');
    $psid = $echo ? $recip : $sender; if ($psid === '' || $psid === $pageId) return;
    $jid = $psid . '@fb'; $ts = isset($ev['timestamp']) ? (int) floor(((int) $ev['timestamp']) / 1000) : time();
    $name = ''; if (!$echo) $name = wf_fb_person($aid, $psid)['name'] ?? '';
    if (isset($ev['message'])) {
        $m = $ev['message']; $mid = (string) ($m['mid'] ?? ''); $text = trim((string) ($m['text'] ?? '')); $base = ['jid' => $jid, 'phone' => $psid, 'id' => $mid, 'ts' => $ts, 'dir' => $echo ? 'out' : 'in'];
        if ($echo) { $base['via'] = !empty($m['app_id']) ? 'app' : 'page'; } elseif ($name !== '') $base['name'] = $name;
        if (!empty($m['reply_to']['mid'])) $base['quote_id'] = (string) $m['reply_to']['mid'];
        $atts = (array) ($m['attachments'] ?? []); $first = true;
        if (!$atts) { if ($text !== '') wf_fb_append($aid, $base + ['kind' => 'text', 'text' => $text]); return; }
        foreach ($atts as $i => $a) { $t = (string) ($a['type'] ?? 'file'); $url = (string) ($a['payload']['url'] ?? '');
            $kind = ['image' => 'image', 'video' => 'video', 'audio' => 'voice', 'file' => 'document'][$t] ?? 'text';
            if ($t === 'location') { $lat = $a['payload']['coordinates']['lat'] ?? ''; $lng = $a['payload']['coordinates']['long'] ?? ''; wf_fb_append($aid, ['id' => $mid . '_' . $i] + $base + ['kind' => 'text', 'text' => '📍 https://maps.google.com/?q=' . $lat . ',' . $lng]); continue; }
            if ($t === 'fallback' || $kind === 'text') { $u = (string) ($a['URL'] ?? $url); wf_fb_append($aid, ['id' => $mid . '_' . $i] + $base + ['kind' => 'text', 'text' => trim(($first ? $text . "\n" : '') . (string) ($a['title'] ?? '') . ' ' . $u)]); $first = false; continue; }
            $path = wf_fb_save_media($aid, $url, $mid . $i, $t === 'audio' ? 'audio' : $t); $isSticker = !empty($a['payload']['sticker_id']); if ($isSticker) $kind = 'sticker';
            wf_fb_append($aid, ['id' => count($atts) > 1 ? $mid . '_' . $i : $mid] + $base + ['kind' => $kind, 'text' => $first ? $text : '', 'attachment' => $path, 'file_name' => basename((string) parse_url($url, PHP_URL_PATH))]); $first = false; }
        return;
    }
    if (isset($ev['postback'])) { $p = $ev['postback']; $t = trim((string) ($p['title'] ?? $p['payload'] ?? '')); if ($t !== '') wf_fb_append($aid, ['dir' => 'in', 'jid' => $jid, 'phone' => $psid, 'name' => $name, 'id' => (string) ($p['mid'] ?? 'pb' . $ts . $psid), 'ts' => $ts, 'kind' => 'text', 'text' => $t]); return; }
    if (isset($ev['reaction'])) { $rx = $ev['reaction']; wf_fb_append($aid, ['dir' => 'reaction', 'jid' => $jid, 'id' => (string) ($rx['mid'] ?? ''), 'emoji' => ($rx['action'] ?? '') === 'unreact' ? '' : (string) ($rx['emoji'] ?? $rx['reaction'] ?? ''), 'ts' => $ts, 'from' => $sender]); return; }
    if (isset($ev['read'])) { wi_update('fb_read.json', function ($R) use ($aid, $psid, $ts) { $R[$aid][$psid] = $ts; return $R; }); }
}
/** The public entry: Facebook verifies the address (GET) and then posts events (POST, signed). */
/** The events of one webhook entry (one page), handed to the right handlers. */
function wf_fb_entry(array $acc, array $en): void {
    $aid = (string) $acc['id']; $pid = (string) ($en['id'] ?? '');
    foreach ((array) ($en['changes'] ?? []) as $chg) { if (($chg['field'] ?? '') === 'feed') { try { wf_fb_feed_take($acc, (array) ($chg['value'] ?? []), $pid); } catch (Throwable $e) { wi_log('fb_hook_error', ['account' => $aid, 'error' => $e->getMessage()]); } } }
    $in = false; foreach ((array) ($en['messaging'] ?? []) as $ev) { try { wf_fb_take($acc, (array) $ev, $pid); if (isset($ev['message']) && empty($ev['message']['is_echo'])) $in = true; } catch (Throwable $e) { wi_log('fb_hook_error', ['account' => $aid, 'error' => $e->getMessage()]); } }
    if ($in && function_exists('wf_bot_tick') && wf_bot_run_cfg($aid)['mode'] !== 'off') { try { wf_bot_tick($acc, true); } catch (Throwable $e) {} } // the bot answers at once, like a number's instant trigger
}
function wf_fb_answer_ok(): void { http_response_code(200); header('Content-Length: 2'); echo 'ok'; flush(); if (function_exists('fastcgi_finish_request')) fastcgi_finish_request(); ignore_user_abort(true); @set_time_limit(120); }
/** The public entry: Facebook verifies the address (GET) and then posts events (POST, signed). "app-…" = the app's one address (every page of the app); otherwise one page's own address. */
function wf_fb_hook(string $aid): void {
    if (strpos($aid, 'app-') === 0) { wf_fb_hook_app(substr($aid, 4)); return; }
    $acc = wi_accounts()[$aid] ?? null; if (!wf_fb_is($acc)) { http_response_code(404); exit; }
    $c = wf_fb_cfg($aid);
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (($_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '') === 'subscribe' && $c['verify'] !== '' && hash_equals($c['verify'], (string) ($_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? ''))) { header('Content-Type: text/plain'); echo (string) ($_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? ''); exit; }
        http_response_code(403); exit;
    }
    $raw = wi_input(); $sec = wf_fb_secret($aid); $sig = (string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
    if ($sec === '' || !hash_equals('sha256=' . hash_hmac('sha256', $raw, $sec), $sig)) { http_response_code(403); exit; }
    wf_fb_answer_ok();
    $in = json_decode($raw, true); if (!is_array($in) || ($in['object'] ?? '') !== 'page') return;
    wf_fb_cfg_set($aid, ['hook_at' => gmdate('c')]);
    foreach ((array) ($in['entry'] ?? []) as $en) if ((string) ($en['id'] ?? '') === $c['page_id']) wf_fb_entry($acc, (array) $en);
}
function wf_fb_hook_app(string $key): void {
    $scope = wf_fb_scope_by_key($key); if ($scope === null) { http_response_code(404); exit; } $app = wf_fb_app($scope);
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (($_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '') === 'subscribe' && $app['verify'] !== '' && hash_equals($app['verify'], (string) ($_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? ''))) { header('Content-Type: text/plain'); echo (string) ($_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? ''); exit; }
        http_response_code(403); exit;
    }
    $raw = wi_input(); $sig = (string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
    if ($app['secret'] === '' || !hash_equals('sha256=' . hash_hmac('sha256', $raw, $app['secret']), $sig)) { http_response_code(403); exit; }
    wf_fb_answer_ok();
    $in = json_decode($raw, true); if (!is_array($in) || ($in['object'] ?? '') !== 'page') return;
    foreach ((array) ($in['entry'] ?? []) as $en) { $acc = wf_fb_account_for_page((string) ($en['id'] ?? '')); if (!$acc || wf_fb_cfg((string) $acc['id'])['app_scope'] !== $scope) continue; wf_fb_cfg_set((string) $acc['id'], ['hook_at' => gmdate('c')]); wf_fb_entry($acc, (array) $en); }
}

/* ---------- sending ---------- */
function wf_fb_last_in(string $aid, string $jid): int { foreach (wf_fb_rows($aid, ['jid' => $jid, 'limit' => 400]) as $r) if (($r['dir'] ?? '') === 'in') return strtotime((string) $r['time']) ?: 0; return 0; }
function wf_fb_send_one(string $aid, string $psid, array $message, int $lastIn, bool $bot): array {
    $age = time() - $lastIn; $body = ['recipient' => ['id' => $psid], 'message' => $message];
    if ($lastIn > 0 && $age <= 86400) $body['messaging_type'] = 'RESPONSE';
    elseif ($lastIn > 0 && $age <= 7 * 86400 && !$bot) { $body['messaging_type'] = 'MESSAGE_TAG'; $body['tag'] = 'HUMAN_AGENT'; } // a person may answer for 7 days
    else return ['ok' => false, 'error' => 'Facebook only lets a page answer within 24 hours of the person\'s last message (7 days for a person replying, not the bot).'];
    return wf_fb_graph($aid, 'POST', 'me/messages', $body);
}
function wf_fb_send(string $aid, array $p): array {
    $jid = preg_replace('/[^0-9A-Za-z@.:_-]/', '', (string) ($p['jid'] ?? '')); $psid = explode('@', $jid)[0]; if ($psid === '') return ['ok' => false, 'error' => 'Choose a conversation.'];
    $text = trim((string) ($p['text'] ?? '')); $bot = ($p['by'] ?? '') === 'bot' || ($p['via'] ?? '') === 'bot'; $lastIn = wf_fb_last_in($aid, $jid); $att = (string) ($p['attachment'] ?? '');
    $sent = []; $id = '';
    if ($att !== '') {
        $abs = dirname(__DIR__, 2) . '/storage/' . $att; if (strpos($att, '..') !== false || !is_file($abs)) return ['ok' => false, 'error' => 'File not found.'];
        $mime = mime_content_type($abs) ?: 'application/octet-stream'; $type = strpos($mime, 'image/') === 0 ? 'image' : (strpos($mime, 'video/') === 0 ? 'video' : (strpos($mime, 'audio/') === 0 ? 'audio' : 'file'));
        $age = time() - $lastIn; $params = ['recipient' => ['id' => $psid], 'message' => ['attachment' => ['type' => $type, 'payload' => ['is_reusable' => false]]]];
        if ($lastIn > 0 && $age <= 86400) $params['messaging_type'] = 'RESPONSE'; elseif ($lastIn > 0 && $age <= 7 * 86400 && !$bot) { $params['messaging_type'] = 'MESSAGE_TAG'; $params['tag'] = 'HUMAN_AGENT'; } else return ['ok' => false, 'error' => 'Facebook only lets a page answer within 24 hours of the person\'s last message (7 days for a person replying, not the bot).'];
        $r = wf_fb_graph($aid, 'POST', 'me/messages', $params, ['path' => $abs, 'mime' => $mime, 'name' => basename($abs)]); if (empty($r['ok'])) return $r;
        $id = (string) ($r['message_id'] ?? ''); wf_fb_append($aid, ['dir' => 'out', 'jid' => $jid, 'phone' => $psid, 'id' => $id ?: 'o' . uniqid(), 'kind' => $type === 'audio' ? 'voice' : ($type === 'file' ? 'document' : $type), 'text' => $text, 'attachment' => $att, 'file_name' => basename($abs), 'via' => (string) ($p['via'] ?? 'inbox'), 'by' => (string) ($p['by'] ?? '')]);
    }
    if ($text !== '' && ($att === '' ? true : false)) {
        foreach (preg_split('/(?<=.{1900})/us', $text) as $part) { $part = trim($part); if ($part === '') continue;
            $r = wf_fb_send_one($aid, $psid, ['text' => $part], $lastIn, $bot); if (empty($r['ok'])) return $r;
            $id = (string) ($r['message_id'] ?? ''); wf_fb_append($aid, ['dir' => 'out', 'jid' => $jid, 'phone' => $psid, 'id' => $id ?: 'o' . uniqid(), 'kind' => 'text', 'text' => $part, 'via' => (string) ($p['via'] ?? 'inbox'), 'by' => (string) ($p['by'] ?? '')] + (!empty($p['quote_id']) ? ['quote_id' => (string) $p['quote_id']] : [])); }
    }
    return ['ok' => true, 'id' => $id];
}

/* ---------- the gateway stand-in ---------- */
function wf_fb_gateway(string $path, ?array $post, array $acc): array {
    $aid = (string) $acc['id']; $u = parse_url($path); $route = (string) ($u['path'] ?? $path); parse_str((string) ($u['query'] ?? ''), $q); $post = $post ?? [];
    $cfg = wf_fb_cfg($aid);
    switch ($route) {
        case '/health': case '/status':
            $on = wf_fb_token($aid) !== '' && $cfg['page_id'] !== '' && $cfg['error'] === '';
            return ['ok' => true, 'connected' => $on, 'account' => $cfg['page_id'], 'channel' => 'fb', 'last_error' => $cfg['error'], 'logged_out' => false, 'events' => [], 'day' => ['disconnects' => 0, 'send_failed' => 0, 'logged_out' => false]];
        case '/events':
            $E = wi_read('fb_events.json', [])[$aid] ?? ['seq' => 0, 'events' => []];
            if (!isset($q['since']) || $q['since'] === '') return ['ok' => true, 'seq' => $E['seq'], 'events' => []];
            return ['ok' => true, 'seq' => $E['seq'], 'events' => array_values(array_filter($E['events'], fn($e) => $e['seq'] > (int) $q['since']))];
        case '/archive/chats':
            $chats = []; $rows = array_reverse(wf_fb_rows($aid, ['limit' => 300000, 'months' => (int) ($q['months'] ?? 36)]));
            foreach ($rows as $r) { $k = (string) ($r['jid'] ?? ''); if ($k === '') continue;
                $c = $chats[$k] ?? ['jid' => $k, 'phone' => (string) ($r['phone'] ?? ''), 'name' => '', 'group' => false, 'count' => 0, 'in' => 0, 'out' => 0, 'last' => null, 'channel' => 'fb'];
                if (!empty($r['name']) && ($r['dir'] ?? '') === 'in') $c['name'] = $r['name'];
                if (($r['dir'] ?? '') === 'in') $c['in']++; elseif (($r['dir'] ?? '') === 'out') $c['out']++; else { $chats[$k] = $c; continue; }
                $c['count']++; $c['last'] = ['time' => $r['time'], 'dir' => $r['dir'], 'text' => (string) ($r['text'] ?? ''), 'kind' => (string) ($r['kind'] ?? 'text')]; $chats[$k] = $c; }
            foreach ($chats as &$c) if ($c['name'] === '') $c['name'] = (string) (wf_fb_person($aid, explode('@', $c['jid'])[0])['name'] ?? ''); unset($c);
            usort($chats, fn($a, $b) => strcmp((string) ($b['last']['time'] ?? ''), (string) ($a['last']['time'] ?? '')));
            return ['ok' => true, 'chats' => array_values($chats)];
        case '/archive/thread':
            $jid = (string) ($q['jid'] ?? ''); $rows = array_reverse(wf_fb_rows($aid, ['jid' => $jid, 'limit' => min(60000, max(1, (int) ($q['limit'] ?? 5000))), 'months' => 24]));
            $react = []; foreach ($rows as $r) if (($r['dir'] ?? '') === 'reaction') $react[$r['id']] = $r['emoji'];
            $read = (int) (wi_read('fb_read.json', [])[$aid][explode('@', $jid)[0]] ?? 0);
            $out = []; foreach ($rows as $r) { if (!in_array($r['dir'] ?? '', ['in', 'out'], true)) continue; if (isset($react[$r['id'] ?? ''])) $r['reaction'] = $react[$r['id']];
                if (($r['dir'] ?? '') === 'out' && $read && strtotime((string) $r['time']) <= $read) $r['status'] = 'read'; $out[] = $r; }
            return ['ok' => true, 'rows' => $out, 'name' => (string) (wf_fb_person($aid, explode('@', $jid)[0])['name'] ?? '')];
        case '/archive/stats':
            $rows = wf_fb_rows($aid, ['limit' => 300000]); $n = ['in' => 0, 'out' => 0]; $top = [];
            foreach ($rows as $r) if (isset($n[$r['dir'] ?? ''])) { $n[$r['dir']]++; $top[$r['phone'] ?? ''] = ($top[$r['phone'] ?? ''] ?? 0) + 1; }
            arsort($top); $t = []; foreach (array_slice($top, 0, 10, true) as $ph => $c) $t[] = ['phone' => (string) $ph, 'count' => $c];
            return ['ok' => true, 'total' => $n['in'] + $n['out'], 'in' => $n['in'], 'out' => $n['out'], 'chats' => count($top), 'top' => $t];
        case '/archive/media-kinds': return ['ok' => true, 'items' => [], 'senders' => []];
        case '/contact': case '/contacts':
            $psid = explode('@', (string) ($q['jid'] ?? $q['phone'] ?? ''))[0]; $p = $psid !== '' ? wf_fb_person($aid, $psid) : []; return ['ok' => true, 'name' => (string) ($p['name'] ?? ''), 'phone' => $psid, 'picture' => (string) ($p['pic'] ?? ''), 'about' => '', 'channel' => 'fb', 'contacts' => []];
        case '/chat/send': return wf_fb_send($aid, $post);
        case '/chat/send-batch': $n = 0; foreach ((array) ($post['items'] ?? []) as $it) if (!empty(wf_fb_send($aid, (array) $it)['ok'])) $n++; return ['ok' => true, 'queued' => $n, 'waiting' => 0];
        case '/chat/read': case '/presence/subscribe': return ['ok' => true];
        case '/chat/react': return ['ok' => false, 'error' => 'Reactions are not available on Messenger replies from the inbox.'];
        case '/chat/delete': case '/chat/delete-msg':
            wf_fb_append($aid, ['dir' => 'deleted', 'jid' => (string) ($post['jid'] ?? ''), 'id' => (string) ($post['id'] ?? '')]); return ['ok' => true];
        case '/groups': case '/groups/members': case '/groups/events': return ['ok' => true, 'groups' => [], 'members' => [], 'events' => []];
        case '/library': return ['ok' => true, 'items' => []];
    }
    return ['ok' => false, 'error' => 'This is not available on a Facebook page.'];
}

/* ---------- connecting a page (settings) ---------- */
/** The permissions the page token really has (Facebook's token check, with the app's own key); [] when it cannot be asked. */
function wf_fb_token_scopes(string $aid): array {
    $c = wf_fb_cfg($aid); if (!function_exists('wf_fb_app') || $c['app_scope'] === null) return []; $app = wf_fb_app((string) $c['app_scope']); if ($app['app_id'] === '' || $app['secret'] === '') return [];
    $r = wf_fb_http_json('https://graph.facebook.com/' . $app['graph'] . '/debug_token?' . http_build_query(['input_token' => wf_fb_token($aid), 'access_token' => $app['app_id'] . '|' . $app['secret']]));
    return array_values(array_map('strval', (array) ($r['data']['scopes'] ?? [])));
}
/** Checks the page token and subscribes the page to the webhook. Messages need only messaging permissions: when Facebook did not give the
 *  reading / posting permissions, Messenger still works and the card lists what is missing (comments, posts and numbers need them). */
function wf_fb_connect(string $aid): array {
    $c = wf_fb_cfg($aid); $me = wf_fb_graph($aid, 'GET', 'me', ['fields' => 'id,name']);
    if (empty($me['ok'])) { if ($c['page_id'] === '' || wf_fb_token($aid) === '') { wf_fb_cfg_set($aid, ['error' => (string) $me['error']]); return $me; } $me = ['ok' => true, 'id' => $c['page_id'], 'name' => $c['page_name']]; } // the page is known from the sign-in
    if ($c['page_id'] !== '' && (string) $me['id'] !== $c['page_id']) { $e = 'The token belongs to another page.'; wf_fb_cfg_set($aid, ['error' => $e]); return ['ok' => false, 'error' => $e]; }
    $sub = wf_fb_graph($aid, 'POST', $me['id'] . '/subscribed_apps', ['subscribed_fields' => 'messages,messaging_postbacks,message_echoes,message_reads,message_reactions,feed']);
    if (empty($sub['ok'])) $sub = wf_fb_graph($aid, 'POST', $me['id'] . '/subscribed_apps', ['subscribed_fields' => 'messages,messaging_postbacks,message_echoes,message_reads,message_reactions']); // without the comments, when that permission is missing
    if (empty($sub['ok'])) { wf_fb_cfg_set($aid, ['page_id' => (string) $me['id'], 'page_name' => (string) ($me['name'] ?? ''), 'error' => (string) $sub['error']]); return $sub; }
    $have = function_exists('wf_fb_http_json') ? wf_fb_token_scopes($aid) : [];
    $need = ['pages_messaging', 'pages_manage_metadata', 'pages_read_engagement', 'pages_read_user_content', 'pages_manage_posts', 'pages_manage_engagement', 'read_insights'];
    $missing = $have ? array_values(array_diff($need, $have)) : [];
    wf_fb_cfg_set($aid, ['page_id' => (string) $me['id'], 'page_name' => (string) ($me['name'] ?? ''), 'error' => '', 'ok_at' => gmdate('c'), 'missing' => $missing]);
    return ['ok' => true, 'page' => (string) ($me['name'] ?? ''), 'missing' => $missing];
}
function wf_fb_add(string $name, array $in, ?string $owner): array {
    $tok = trim((string) ($in['token'] ?? '')); $sec = trim((string) ($in['secret'] ?? ''));
    if ($name === '' || mb_strlen($name) > 60) return ['ok' => false, 'error' => 'Give the page a name (up to 60 characters).'];
    if ($tok === '' || $sec === '') return ['ok' => false, 'error' => 'The page access token and the app secret are required.'];
    $accounts = wi_accounts(); $id = 'fb' . substr(bin2hex(random_bytes(4)), 0, 6);
    $accounts[$id] = ['id' => $id, 'name' => $name, 'channel' => 'fb', 'port' => 0, 'token' => bin2hex(random_bytes(24)), 'enabled' => true, 'payments' => false, 'autoreply' => false, 'created_at' => date('c'), 'created_by' => (string) ($_SESSION['email'] ?? ''), 'owner' => $owner];
    wi_save_accounts($accounts); wi_secret_set('fb_page_token:' . $id, $tok); wi_secret_set('fb_app_secret:' . $id, $sec);
    wf_fb_cfg_set($id, ['page_id' => preg_replace('/\D/', '', (string) ($in['page_id'] ?? '')), 'app_id' => preg_replace('/\D/', '', (string) ($in['app_id'] ?? '')), 'verify' => bin2hex(random_bytes(16))]);
    $r = wf_fb_connect($id); return ['ok' => !empty($r['ok']), 'id' => $id, 'error' => (string) ($r['error'] ?? '')];
}
