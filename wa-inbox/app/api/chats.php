<?php
// WhatsApp Inbox API — chats, threads, sending, media, export, deleting, groups, contact, live events. Included by app/api.php inside the request (same scope: $api, $apiPage…).
    if ($api === 'stats') {
        $r = wi_gateway('/archive/stats');
        if (!empty($r['ok'])) {
            $c = wi_customers();
            foreach ($r['top'] as &$t) $t['customer'] = $c[$t['phone'] ?? ''] ?? null;
            unset($t);
            $customers = 0;
            $ch = wi_gateway('/archive/chats?months=' . (int) wi_sys('months_long') );
            foreach (($ch['chats'] ?? []) as $x) if (isset($c[$x['phone'] ?? ''])) $customers++;
            $r['customer_chats'] = $customers;
        }
        wi_json($r);
    }
    // Every number of this user in one list: each chat carries its number (acc) for a mark and to open it on that number.
    // The number's state for the chats page: connected or offline, and how many replies wait in its outbox.
    if ($api === 'acc_state') { $a = wi_current(); $h = $a ? wi_gateway('/health', null, $a) : [];
        wi_json(['ok' => true, 'connected' => !empty($h['connected']), 'waiting' => count((array) (wi_read('outbox.json', [])[(string) ($a['id'] ?? '')] ?? []))]); }
    if ($api === 'chats_all') {
        $out = []; $c = wi_customers();
        foreach (wi_my_accounts() as $a) { $r = wi_gateway('/archive/chats?months=' . (int) wi_sys('months_long'), null, $a); if (empty($r['ok'])) continue;
            $hide = wi_chats_taken_by_others((string) $a['id']);
            foreach ((array) $r['chats'] as $x) { if (isset($hide[$x['jid'] ?? ''])) continue; $x['customer'] = $c[$x['phone'] ?? ''] ?? null; $x['acc'] = (string) $a['id']; $x['acc_name'] = (string) ($a['name'] ?? $a['id']); $out[] = $x; } }
        usort($out, fn($p, $q) => strcmp((string) ($q['last']['time'] ?? ''), (string) ($p['last']['time'] ?? '')));
        wi_json(['ok' => true, 'chats' => array_slice($out, 0, 1500)]);
    }
    if ($api === 'chats') {
        $r = wi_gateway('/archive/chats?months=' . (int) wi_sys('months_long') );
        if (!empty($r['ok'])) {
            $c = wi_customers(); $notes = wi_read('notes.json', [])[wi_current()['id'] ?? ''] ?? [];
            foreach ($r['chats'] as &$x) { $x['customer'] = $c[$x['phone'] ?? ''] ?? null; $x['notes'] = count($notes[$x['jid']] ?? []); }
            unset($x);
            if ($hide = wi_chats_taken_by_others((string) (wi_current()['id'] ?? ''))) $r['chats'] = array_values(array_filter($r['chats'], fn($x) => !isset($hide[$x['jid'] ?? ''])));
        }
        wi_json($r);
    }
    if ($api === 'thread') {
        $jid = preg_replace('/[^0-9A-Za-z@.:_-]/', '', (string) ($_GET['jid'] ?? ''));
        // the newest messages only (300 by default; "older" asks for more) — a busy group has thousands
        $lim = max(50, min(5000, (int) ($_GET['limit'] ?? 300)));
        $r = wi_gateway('/archive/thread?jid=' . rawurlencode($jid) . '&limit=' . ($lim + 1));
        if (!empty($r['ok']) && is_array($r['rows'] ?? null)) { $r['more'] = count($r['rows']) > $lim; if ($r['more']) $r['rows'] = array_slice($r['rows'], -$lim); }
        wi_json($r);
    }
    if ($api === 'chat_media') { // this chat's photos, videos, recordings and files (newest first), for its own media panel
        $jid = preg_replace('/[^0-9A-Za-z@.:_-]/', '', (string) ($_GET['jid'] ?? '')); if ($jid === '') wi_json(['ok' => false, 'error' => 'Choose a conversation.'], 422);
        $r = wi_gateway('/archive/thread?jid=' . rawurlencode($jid) . '&limit=5000'); if (empty($r['ok'])) wi_json($r);
        $root = dirname(__DIR__, 3) . '/storage/'; $items = []; $seen = [];
        foreach (array_reverse((array) ($r['rows'] ?? [])) as $m) { $att = (string) ($m['attachment'] ?? '');
            if ($att === '' || isset($seen[$att]) || !preg_match('#^(inbox_media/[a-z0-9_-]+|inbox_uploads)/[A-Za-z0-9_.-]+$#', $att) || !is_file($root . $att)) continue; $seen[$att] = 1;
            $k = (string) ($m['kind'] ?? ''); $k = in_array($k, ['sticker', 'image', 'gif', 'video', 'voice', 'audio', 'document'], true) ? $k : 'document';
            $items[] = ['key' => $att, 'kind' => $k, 'name' => (string) ($m['file_name'] ?? ''), 'caption' => (string) ($m['text'] ?? ''), 'title' => '', 'tags' => [], 'size' => (int) filesize($root . $att), 'at' => (string) ($m['time'] ?? ''), 'dir' => (string) ($m['dir'] ?? '')]; }
        wi_json(['ok' => true, 'items' => $items]);
    }
    if ($api === 'qr') {
        if (!wi_can_manage(wi_current())) wi_json(['ok' => false, 'error' => 'Not allowed for this number.'], 403);
        $q = wi_gateway('/qr.json'); if (!empty($q['connected'])) $q['phone'] = preg_replace('/[:@].*$/', '', (string) (wi_gateway('/status')['account']['id'] ?? '')); // the linked mobile number
        wi_json($q);
    }
    if ($api === 'relink' || $api === 'restore') {
        if (!wi_can_manage(wi_current())) wi_json(['ok' => false, 'error' => 'Not allowed for this number.'], 403);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') wi_json(['ok' => false, 'error' => 'POST only.'], 405);
        wi_check_csrf();
        wi_log($api === 'relink' ? 'whatsapp_relink' : 'whatsapp_restore');
        wi_json(wi_gateway($api === 'relink' ? '/reset' : '/restore-auth', []));
    }
    if ($api === 'accounts' || $api === 'gateway') {
        $rows = [];
        foreach (wi_my_accounts() as $a) {
            if (!wi_can_manage($a)) continue;
            $st = wi_gateway('/status', null, $a);
            $stats = !empty($st['ok']) ? wi_gateway('/archive/stats', null, $a) : [];
            $rows[] = ['health' => $api === 'gateway' && !empty($st['ok']) ? array_intersect_key($st, array_flip(['pid', 'uptime_seconds', 'memory_mb', 'node', 'last_message_at', 'messages_since_start', 'last_error', 'last_disconnect_code', 'reconnect_attempts', 'down_seconds', 'needs_qr', 'started_at'])) : null,
                'restarts_24h' => wi_guard_restarts($a['id']),
                'id' => $a['id'], 'name' => $a['name'], 'main' => !empty($a['main']), 'enabled' => !empty($a['enabled']), 'payments' => !empty($a['payments']), 'autoreply' => !empty($a['autoreply']),
                'running' => !empty($st['ok']), 'starting' => empty($st['ok']) && !empty($a['enabled']) && strtotime((string) ($a['created_at'] ?? '')) > time() - 180, 'connected' => !empty($st['connected']), 'number' => isset($st['account']['id']) ? preg_replace('/[:@].*$/', '', (string) $st['account']['id']) : '',
                'wa_name' => (string) ($st['account']['name'] ?? ''), 'messages' => (int) ($stats['total'] ?? 0), 'chats' => (int) ($stats['chats'] ?? 0)];
        }
        wi_json(['ok' => true, 'accounts' => $rows, 'guard' => wi_guard_info()]);
    }
    if ($api === 'gw_action') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') wi_json(['ok' => false, 'error' => 'POST only.'], 405);
        wi_check_csrf();
        $in = json_decode(wi_input(), true) ?: [];
        $acc = wi_accounts()[(string) ($in['acc'] ?? '')] ?? null;
        if (!$acc || !wi_can_manage($acc)) wi_json(['ok' => false, 'error' => 'Not allowed for this number.'], 403);
        $action = (string) ($in['action'] ?? '');
        if (!in_array($action, ['reconnect', 'restart'], true)) wi_json(['ok' => false, 'error' => 'Unknown action.'], 422);
        wi_log('gateway_' . $action, ['account' => $acc['id']]);
        wi_json(wi_gateway($action === 'reconnect' ? '/restart' : '/shutdown', [], $acc));
    }
    if ($api === 'send_media') {
        @set_time_limit(300); @ignore_user_abort(true);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') wi_json(['ok' => false, 'error' => 'POST only.'], 405);
        wi_check_csrf();
        if (!wi_can_reply()) wi_json(['ok' => false, 'error' => 'You do not have permission to reply.'], 403);
        $f = $_FILES['file'] ?? null;
        if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) wi_json(['ok' => false, 'error' => 'Upload failed.'], 422);
        if ($f['size'] > (int) wi_sys('max_send_mb') * 1048576) wi_json(['ok' => false, 'error' => 'The file is larger than the allowed size (' . (int) wi_sys('max_send_mb') . ' MB).'], 422);
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'audio/ogg' => 'ogg', 'audio/opus' => 'opus', 'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a', 'audio/x-m4a' => 'm4a',
            'audio/aac' => 'aac', 'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'application/pdf' => 'pdf'];
        if (!isset($types[$mime])) wi_json(['ok' => false, 'error' => 'Only photos (JPG, PNG, WEBP), audio (OGG, MP3, M4A, WAV) and PDF can be sent.'], 422);
        $dir = dirname(__DIR__, 3) . '/storage/inbox_uploads';
        $name = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $types[$mime];
        if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) wi_json(['ok' => false, 'error' => 'Could not store the file.'], 500);
        @chmod($dir . '/' . $name, 0644);
        $jid = preg_replace('/[^0-9A-Za-z@.:_-]/', '', (string) ($_POST['jid'] ?? ''));
        $upAid = (string) (wi_current()['id'] ?? ''); wi_update('uploads.json', function ($U) use ($name, $upAid) { $U[$name] = $upAid; return $U; }); // which number sent it (only its people may open it)
        $r = wi_send_or_queue(['jid' => $jid, 'text' => trim((string) ($_POST['text'] ?? '')), 'attachment' => 'inbox_uploads/' . $name,
            'file_name' => mb_substr(basename((string) $f['name']), 0, 120), 'by' => (string) $_SESSION['email']]);
        wi_log('chat_send_media', ['account' => wi_current()['id'] ?? '', 'jid' => $jid, 'type' => $mime, 'ok' => !empty($r['ok'])]);
        wi_json($r);
    }
    if ($api === 'media') {
        // Files sent from the Inbox (inbox_uploads) and photos/voice kept by the archive (inbox_media/<account>).
        $f = basename((string) ($_GET['f'] ?? ''));
        $a = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) ($_GET['a'] ?? '')));
        if ($a !== '') {
            if (!isset(wi_my_accounts()[$a])) { http_response_code(403); exit; }
            $path = dirname(__DIR__, 3) . '/storage/inbox_media/' . $a . '/' . $f;
            $okName = preg_match('/^[A-Za-z0-9_-]{1,60}\.(jpg|png|webp|ogg|opus|mp3|m4a|wav|mp4|3gp|mov|pdf|docx?|xlsx?|pptx?|txt|csv|zip|rar|7z|bin)$/', $f);
        } else {
            $upA = (string) (wi_read('uploads.json', [])[$f] ?? ''); if ($upA !== '' && !isset(wi_my_accounts()[$upA])) { http_response_code(403); exit; } // a file sent from someone else's number
            $path = dirname(__DIR__, 3) . '/storage/inbox_uploads/' . $f;
            $okName = preg_match('/^\d{8}_\d{6}_[0-9a-f]{12}\.(jpg|png|webp|ogg|opus|mp3|m4a|aac|wav|pdf)$/', $f);
        }
        if (!$okName || !is_file($path)) { http_response_code(404); exit; }
        // The type comes from the extension, never from the bytes: a sender's "invoice.pdf" holding HTML must not run as a page here.
        $types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'ogg' => 'audio/ogg', 'opus' => 'audio/ogg', 'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'aac' => 'audio/aac', 'wav' => 'audio/wav',
            'mp4' => 'video/mp4', '3gp' => 'video/3gpp', 'mov' => 'video/quicktime', 'pdf' => 'application/pdf'];
        $ext0 = strtolower(pathinfo($f, PATHINFO_EXTENSION)); $real = (string) (new finfo(FILEINFO_MIME_TYPE))->file($path);
        $fits = isset($types[$ext0]) && (strtok($real, '/') === strtok($types[$ext0], '/') || ($ext0 === 'pdf' && $real === 'application/pdf') || $real === 'application/octet-stream');
        header('Content-Type: ' . ($fits ? $types[$ext0] : 'application/octet-stream'));
        header('X-Content-Type-Options: nosniff');
        if ($fits && in_array(strtok($types[$ext0], '/'), ['audio', 'video'], true)) header("Content-Security-Policy: default-src 'none'; media-src 'self'; style-src 'unsafe-inline'"); // audio / video opened on their own: a sandbox would give the player an opaque origin and block its own file
        elseif ($ext0 !== 'pdf') header("Content-Security-Policy: sandbox; default-src 'none'; img-src 'self' data:; media-src 'self'; style-src 'unsafe-inline'"); // the browser's PDF viewer does not open under a sandbox
        // Byte ranges: audio and video players seek (jump to any point) instead of playing from the start only
        $size = filesize($path); $from = 0; $to = $size - 1; header('Accept-Ranges: bytes');
        if (preg_match('/^bytes=(\d*)-(\d*)$/', (string) ($_SERVER['HTTP_RANGE'] ?? ''), $rg) && $size > 0) { if ($rg[1] === '') { $from = max(0, $size - (int) $rg[2]); } else { $from = (int) $rg[1]; if ($rg[2] !== '') $to = min($to, (int) $rg[2]); }
            if ($from > $to || $from >= $size) { http_response_code(416); header('Content-Range: bytes */' . $size); exit; }
            http_response_code(206); header('Content-Range: bytes ' . $from . '-' . $to . '/' . $size); }
        header('Content-Length: ' . ($to - $from + 1));
        header('Cache-Control: private, max-age=86400');
        $nice = trim(str_replace(['\\', '/', ':', '*', '?', '"', '<', '>', '|', "\r", "\n"], ' ', mb_substr((string) ($_GET['name'] ?? ''), 0, 120)));
        $ext = pathinfo($f, PATHINFO_EXTENSION);
        $dl = $nice !== '' ? $nice . '.' . $ext : $f;
        $inline = $fits; // anything else (or content that does not match its name) is only ever downloaded
        header("Content-Disposition: " . (!empty($_GET['dl']) || !$inline ? 'attachment' : 'inline') . "; filename=\"" . preg_replace('/[^\x20-\x7E]/', '_', $dl) . "\"; filename*=UTF-8''" . rawurlencode($dl));
        if ($from === 0 && $to === $size - 1) { readfile($path); exit; }
        $fh = fopen($path, 'rb'); fseek($fh, $from); $left = $to - $from + 1; while ($left > 0 && !feof($fh)) { $chunk = fread($fh, min(65536, $left)); echo $chunk; $left -= strlen($chunk); } fclose($fh); exit;
    }
    if ($api === 'export') {
        if ((wi_me()['role'] ?? '') === 'client' && empty(wi_me()['can_backup'])) wi_json(['ok' => false, 'error' => 'Backup (export) is not part of your plan.'], 403);
        $jid = preg_replace('/[^0-9A-Za-z@.:_-]/', '', (string) ($_GET['jid'] ?? ''));
        $fmt = in_array($_GET['fmt'] ?? '', ['csv', 'html'], true) ? $_GET['fmt'] : 'txt';
        $r = wi_gateway('/archive/thread?jid=' . rawurlencode($jid));
        if (empty($r['ok'])) { http_response_code(502); exit('Could not load the conversation.'); }
        $rows = $r['rows'] ?? [];
        $phone = ''; foreach ($rows as $m) if (!empty($m['phone'])) { $phone = $m['phone']; break; }
        $cust = wi_customers()[$phone] ?? null;
        $peer = $cust['name'] ?? ($phone !== '' ? '+' . $phone : explode('@', $jid)[0]);
        $acc = wi_current();
        wi_log('chat_export', ['account' => $acc['id'] ?? '', 'jid' => $jid, 'format' => $fmt, 'rows' => count($rows)]);
        $file = 'WhatsApp_' . preg_replace('/[^A-Za-z0-9_+-]+/', '_', $peer) . '_' . date('Y-m-d') . '.' . $fmt;
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Cache-Control: no-store');
        $label = function ($m) use ($peer, $acc) { return $m['dir'] === 'out' ? ($m['via'] === 'bot' ? 'Bot' : ($m['via'] === 'inbox' ? ($m['by'] ?? 'Inbox') : ($acc['name'] ?? 'Me'))) : ($m['group'] ?? false ? ('+' . ($m['from'] ?? '')) : $peer); };
        $body = function ($m) { $t = (string) ($m['text'] ?? ''); if (($m['kind'] ?? 'text') !== 'text') $t = '<' . $m['kind'] . (!empty($m['file_name']) ? ': ' . $m['file_name'] : '') . '>' . ($t !== '' ? ' ' . $t : ''); if (!empty($m['revoked_at'])) $t .= ' [deleted on WhatsApp]'; if (!empty($m['edits'])) $t .= ' [edited: ' . end($m['edits'])['text'] . ']'; return $t; };
        if ($fmt === 'html') {
            // Standalone page, WhatsApp style; photos/stickers kept by the archive are embedded (up to ~25 MB in total).
            header('Content-Type: text/html; charset=utf-8');
            $root = dirname(__DIR__, 3) . '/storage/';
            $budget = (int) wi_sys('max_export_mb') * 1048576;
            $ar = wi_lang() === 'ar';
            echo '<!doctype html><html lang="' . ($ar ? 'ar' : 'en') . '" dir="' . ($ar ? 'rtl' : 'ltr') . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . wi_e('WhatsApp — ' . $peer) . '</title><style>'
                . 'body{margin:0;background:#efeae2;font:15px/1.55 "Segoe UI",Tahoma,Arial,sans-serif;color:#111b21}header{position:sticky;top:0;background:#075e54;color:#fff;padding:14px 18px}header b{font-size:17px}header small{display:block;opacity:.8}'
                . 'main{max-width:860px;margin:0 auto;padding:16px;display:flex;flex-direction:column;gap:6px}.day{align-self:center;background:#e1f2fb;color:#54656f;font-size:12px;border-radius:8px;padding:3px 12px;margin:10px 0}'
                . '.b{max-width:72%;padding:7px 10px 20px;unicode-bidi:plaintext;border-radius:9px;position:relative;white-space:pre-wrap;word-break:break-word;box-shadow:0 1px .5px rgba(0,0,0,.13);min-width:80px}'
                . '.in{align-self:flex-start;background:#fff}.out{align-self:flex-end;background:#d9fdd3}.b small{position:absolute;bottom:3px;inset-inline-end:8px;font-size:11px;color:#667781}.via{display:block;font-size:12px;font-weight:700;color:#0f766e}'
                . '.b img{max-width:280px;border-radius:8px;display:block}.k{color:#54656f;font-weight:600}.note{font-size:12px;color:#b45309;display:block;margin-top:3px}@media print{header{position:static}}'
                . '</style></head><body><header><b>' . wi_e($peer) . '</b><small>' . wi_e(($acc['name'] ?? '') . ' · ' . count($rows) . ' ' . wi_t('messages') . ' · ' . wi_t('Export') . ' ' . date('d/m/Y H:i')) . '</small></header><main>';
            $day = '';
            foreach ($rows as $m) {
                $t = strtotime($m['time']);
                if (($kind = $m['kind'] ?? 'text') === 'text' && trim((string) ($m['text'] ?? '')) === '') continue;
                $d = date('d/m/Y', $t); if ($d !== $day) { echo '<div class="day">' . $d . '</div>'; $day = $d; }
                $media = '';
                $att = (string) ($m['attachment'] ?? '');
                if ($att !== '' && in_array($kind, ['image', 'sticker'], true) && preg_match('#^(inbox_media/[a-z0-9_-]+|inbox_uploads)/[A-Za-z0-9_.-]+$#', $att) && is_file($root . $att) && ($sz = filesize($root . $att)) <= $budget) {
                    $budget -= $sz;
                    $media = '<img src="data:' . (new finfo(FILEINFO_MIME_TYPE))->file($root . $att) . ';base64,' . base64_encode((string) file_get_contents($root . $att)) . '" alt="">';
                } elseif ($kind !== 'text') {
                    $media = '<span class="k">[' . wi_e($kind) . (!empty($m['file_name']) ? ': ' . wi_e($m['file_name']) : '') . ']</span>';
                }
                $text = (string) ($m['text'] ?? '');
                echo '<div class="b ' . ($m['dir'] === 'out' ? 'out' : 'in') . '"><span class="via">' . wi_e($label($m)) . '</span>' . $media . ($text !== '' ? '<div dir="auto" style="text-align:start">' . wi_e($text) . '</div>' : '')
                    . (!empty($m['edits']) ? '<span class="note">✏️ ' . wi_e(end($m['edits'])['text']) . '</span>' : '') . (!empty($m['revoked_at']) ? '<span class="note">🗑️ ' . wi_e(wi_t('Deleted on WhatsApp — kept here')) . '</span>' : '')
                    . '<small>' . date('H:i', $t) . '</small></div>';
            }
            echo '</main></body></html>';
            exit;
        }
        if ($fmt === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Date', 'Time', 'Direction', 'From', 'Type', 'Message']);
            foreach ($rows as $m) { $t = strtotime($m['time']); fputcsv($out, [date('Y-m-d', $t), date('H:i:s', $t), $m['dir'], $label($m), $m['kind'] ?? 'text', $body($m)]); }
            fclose($out);
        } else {
            header('Content-Type: text/plain; charset=utf-8');
            echo "\xEF\xBB\xBF" . 'WhatsApp chat with ' . $peer . ' — exported ' . date('d/m/Y H:i') . " from " . wi_sys('brand_name') . "\r\n\r\n";
            foreach ($rows as $m) echo '[' . date('d/m/Y, H:i', strtotime($m['time'])) . '] ' . $label($m) . ': ' . str_replace("\n", "\r\n    ", $body($m)) . "\r\n";
        }
        exit;
    }
    if ($api === 'send') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') wi_json(['ok' => false, 'error' => 'POST only.'], 405);
        wi_check_csrf();
        if (!wi_can_reply()) wi_json(['ok' => false, 'error' => 'You do not have permission to reply.'], 403);
        $in = json_decode(wi_input(), true) ?: [];
        $jid = preg_replace('/[^0-9A-Za-z@.:_-]/', '', (string) ($in['jid'] ?? ''));
        $text = trim((string) ($in['text'] ?? ''));
        if (preg_match('#^/(stop|start)$#i', $text, $cm)) { require_once dirname(__DIR__, 2) . '/features.php'; wf_bot_pause((string) (wi_current()['id'] ?? ''), $jid, strtolower($cm[1]) === 'stop', (string) $_SESSION['email']); wi_json(['ok' => true, 'bot' => strtolower($cm[1])]); }
        $r = wi_send_or_queue(['jid' => $jid, 'text' => $text, 'by' => (string) $_SESSION['email'], 'quote_id' => preg_replace('/[^0-9A-Za-z_-]/', '', (string) ($in['quote_id'] ?? '')) ?: null]);
        wi_log('chat_send', ['account' => wi_current()['id'] ?? '', 'jid' => $jid, 'ok' => !empty($r['ok'])]);
        wi_json($r);
    }
    // Add the person of this chat to a group this number administers (no need to save their number on the phone);
    // when their privacy does not allow it, an invite link can be sent in the chat instead. A daily limit protects the number.
    // Was this person removed from the group (by an admin)? Then WhatsApp refuses the public link; only an admin's add / personal invitation works.
    $grpRemoved = function (string $grp, string $jid): bool { $ids = array_values(array_filter([explode('@', $jid)[0], (string) (wi_gateway('/contact?jid=' . rawurlencode($jid))['phone'] ?? '')]));
        return wf_group_member_state(wi_current() ?: [], $grp, $ids) === 'removed'; }; // removed by an admin (not left by themselves)
    if ($api === 'group_removed') { $grp = preg_replace('/[^0-9A-Za-z@.:_-]/', '', (string) ($_GET['group'] ?? '')); wi_json(['ok' => true, 'removed' => $grpRemoved($grp, preg_replace('/[^0-9A-Za-z@.:_-]/', '', (string) ($_GET['jid'] ?? '')))]); }
    if ($api === 'group_admin_list') { $g = wi_gateway('/groups');
        wi_json(['ok' => !empty($g['ok']), 'error' => $g['error'] ?? '', 'groups' => array_values(array_map(fn($x) => ['jid' => $x['jid'], 'name' => $x['name'], 'members' => $x['members']], array_filter((array) ($g['groups'] ?? []), fn($x) => !empty($x['i_am_admin'])))), 'left' => max(0, (int) wi_sys('group_add_day') - (int) (wi_read('group_adds.json', [])[(string) (wi_current()['id'] ?? '')][date('Y-m-d')] ?? 0))]); }
    if ($api === 'group_add' || $api === 'group_invite_send') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') wi_json(['ok' => false, 'error' => 'POST only.'], 405);
        wi_check_csrf(); $in = json_decode(wi_input(), true) ?: []; $aid = (string) (wi_current()['id'] ?? '');
        $jid = preg_replace('/[^0-9A-Za-z@.:_-]/', '', (string) ($in['jid'] ?? '')); $grp = preg_replace('/[^0-9A-Za-z@.:_-]/', '', (string) ($in['group'] ?? ''));
        if ($jid === '' || str_ends_with($jid, '@g.us') || !str_ends_with($grp, '@g.us')) wi_json(['ok' => false, 'error' => 'Choose a group.'], 422);
        $gl = (array) (wi_gateway('/groups')['groups'] ?? []); $gx = null; foreach ($gl as $x) if (($x['jid'] ?? '') === $grp && !empty($x['i_am_admin'])) $gx = $x;
        if (!$gx) wi_json(['ok' => false, 'error' => 'This number is not an admin of that group.'], 422);
        if ($api === 'group_invite_send' && $grpRemoved($grp, $jid)) $api = 'group_add'; // removed earlier: the public link would be refused — add / personal invitation instead
        if ($api === 'group_invite_send') {
            if (!wi_chat_can('reply')) wi_json(['ok' => false, 'error' => 'You do not have permission to reply.'], 403);
            $lk = wi_gateway('/groups/invite?jid=' . rawurlencode($grp)); if (empty($lk['ok'])) wi_json(['ok' => false, 'error' => $lk['error'] ?? 'Could not get the invite link.']);
            $c = wi_gateway('/contact?jid=' . rawurlencode($jid)); $txt = wf_msg('group_invite', ['group' => (string) $gx['name'], 'link' => (string) $lk['link'], 'name' => (string) ($c['name'] ?? '')]);
            $r = wi_gateway('/chat/send', ['jid' => $jid, 'text' => $txt, 'by' => (string) $_SESSION['email']]); wi_log('group_invite_send', ['account' => $aid, 'jid' => $jid, 'group' => $grp, 'ok' => !empty($r['ok'])]); wi_json($r);
        }
        $n = (int) (wi_read('group_adds.json', [])[$aid][date('Y-m-d')] ?? 0);
        if ($n >= (int) wi_sys('group_add_day')) wi_json(['ok' => false, 'error' => 'Today\'s limit of people added to groups is reached (it protects the number). Send an invite link instead, or try tomorrow.']);
        $c = wi_gateway('/contact?jid=' . rawurlencode($jid)); $who = !empty($c['phone']) ? $c['phone'] . '@s.whatsapp.net' : $jid; // the phone number when known, else the hidden-number id
        $r = wi_gateway('/groups/add', ['group' => $grp, 'jid' => $who, 'private_invite' => true, 'text' => wf_msg('group_invite', ['group' => (string) $gx['name'], 'link' => '', 'name' => (string) ($c['name'] ?? '')])]);
        if (in_array($r['result'] ?? '', ['added', 'invited'], true)) wi_update('group_adds.json', function ($A) use ($aid) { $d = date('Y-m-d'); $A[$aid] = [$d => (int) ($A[$aid][$d] ?? 0) + 1]; return $A; });
        wi_log('group_add', ['account' => $aid, 'jid' => $jid, 'group' => $grp, 'result' => $r['result'] ?? ($r['error'] ?? '')]);
        wi_json($r + ['group_name' => (string) $gx['name']]);
    }
    // Deleting (owner, admins and supervisors allowed to; never an employee — checked above with the chat permissions).
    if ($api === 'delete_msg' || $api === 'delete_chat') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') wi_json(['ok' => false, 'error' => 'POST only.'], 405);
        wi_check_csrf();
        $in = json_decode(wi_input(), true) ?: [];
        $jid = preg_replace('/[^0-9A-Za-z@.:_-]/', '', (string) ($in['jid'] ?? '')); $aid = (string) (wi_current()['id'] ?? '');
        if ($jid === '') wi_json(['ok' => false, 'error' => 'Choose a conversation.'], 422);
        if ($api === 'delete_msg') {
            $id = preg_replace('/[^0-9A-Za-z_-]/', '', (string) ($in['id'] ?? ''));
            $r = wi_gateway('/chat/delete-msg', ['jid' => $jid, 'id' => $id, 'everyone' => ($in['everyone'] ?? true) !== false, 'by' => (string) $_SESSION['email']]);
            wi_log('msg_delete', ['account' => $aid, 'jid' => $jid, 'id' => $id, 'everyone' => !empty($r['everyone'])]);
        } else {
            $r = wi_gateway('/chat/delete', ['jid' => $jid, 'by' => (string) $_SESSION['email']]);
            if (!empty($r['ok'])) { // the chat's teamwork data goes with it
                $keep = ['assign' => null, 'labels' => null]; // kept with the chat in the recycle bin, given back on restore
                wi_update('assign.json', function ($A) use ($aid, $jid, &$keep) { $keep['assign'] = $A[$aid][$jid] ?? null; unset($A[$aid][$jid]); return $A; });
                wi_update('labels.json', function ($L) use ($aid, $jid, &$keep) { $keep['labels'] = $L['chats'][$aid][$jid] ?? null; unset($L['chats'][$aid][$jid]); return $L; });
                wi_update('chat_trash.json', function ($T) use ($aid, $jid, $keep) { $T[$aid][$jid] = $keep + ['at' => date('c')]; return $T; });
            }
            wi_log('chat_delete', ['account' => $aid, 'jid' => $jid]);
        }
        wi_json($r);
    }
    if ($api === 'forward_text') { // a message's text to other chats or groups, one by one like a person
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') wi_json(['ok' => false, 'error' => 'POST only.'], 405);
        wi_check_csrf(); $in = json_decode(wi_input(), true) ?: []; $aid = (string) (wi_current()['id'] ?? '');
        $text = trim(mb_substr((string) ($in['text'] ?? ''), 0, 4000)); if ($text === '') wi_json(['ok' => false, 'error' => 'Write a message.'], 422);
        $jids = array_slice(array_values(array_unique(array_filter(array_map(fn($j) => preg_replace('/[^0-9A-Za-z@.:_-]/', '', (string) $j), (array) ($in['jids'] ?? []))))), 0, 10);
        if (!$jids) wi_json(['ok' => false, 'error' => 'Choose a conversation.'], 422);
        foreach ($jids as $j) if (!wi_chat_visible($aid, $j)) wi_json(['ok' => false, 'error' => 'This conversation is handled by a colleague.'], 403);
        $email = (string) ($_SESSION['email'] ?? '');
        $r = wi_gateway('/chat/send-batch', ['items' => array_map(fn($j) => ['jid' => $j, 'text' => $text, 'by' => $email], $jids)]); // queued in the gateway, sent one by one
        wi_log('forward_text', ['account' => $aid, 'chats' => count($jids), 'queued' => !empty($r['ok'])]);
        wi_json(['ok' => !empty($r['ok']), 'queued' => true, 'of' => count($jids), 'error' => (string) ($r['error'] ?? '')]);
    }
    if ($api === 'trash' || $api === 'trash_restore') { // recycle bin of deleted chats and messages
        $canC = wi_chat_can('delete_chat'); $canM = wi_chat_can('delete_msg'); $aid = (string) (wi_current()['id'] ?? '');
        if (!$canC && !$canM) wi_json(['ok' => false, 'error' => 'You do not have permission for this in a conversation.'], 403);
        if ($api === 'trash') {
            $r = wi_gateway('/trash');
            if (empty($r['ok'])) wi_json($r);
            $hide = wi_chats_taken_by_others($aid); // a colleague's chats stay theirs
            $mine = fn(array $rows) => array_values(array_filter($rows, fn($x) => !isset($hide[(string) ($x['jid'] ?? '')])));
            wi_json(['ok' => true, 'chats' => $canC ? $mine((array) ($r['chats'] ?? [])) : [], 'messages' => $canM ? $mine((array) ($r['messages'] ?? [])) : []]);
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') wi_json(['ok' => false, 'error' => 'POST only.'], 405);
        wi_check_csrf();
        $in = json_decode(wi_input(), true) ?: [];
        $jid = preg_replace('/[^0-9A-Za-z@.:_-]/', '', (string) ($in['jid'] ?? '')); $id = preg_replace('/[^0-9A-Za-z_-]/', '', (string) ($in['id'] ?? ''));
        if ($jid === '' || ($id === '' ? !$canC : !$canM)) wi_json(['ok' => false, 'error' => 'Choose a conversation.'], 422);
        $r = wi_gateway('/trash/restore', ['jid' => $jid, 'id' => $id, 'by' => (string) $_SESSION['email']]);
        if (!empty($r['ok']) && $id === '') {
            $keep = null; wi_update('chat_trash.json', function ($T) use ($aid, $jid, &$keep) { $keep = $T[$aid][$jid] ?? null; unset($T[$aid][$jid]); return $T; });
            if (!empty($keep['assign'])) wi_update('assign.json', function ($A) use ($aid, $jid, $keep) { $A[$aid][$jid] ??= $keep['assign']; return $A; });
            if (!empty($keep['labels'])) wi_update('labels.json', function ($L) use ($aid, $jid, $keep) { $L['chats'][$aid][$jid] ??= $keep['labels']; return $L; });
        }
        wi_log($id === '' ? 'chat_restore' : 'msg_restore', ['account' => $aid, 'jid' => $jid, 'id' => $id]);
        wi_json($r);
    }
    if ($api === 'contact') {
        $jid = preg_replace('/[^0-9A-Za-z@.:_-]/', '', (string) ($_GET['jid'] ?? ''));
        $r = wi_gateway('/contact?jid=' . rawurlencode($jid));
        if (!empty($r['ok']) && !empty($r['phone'])) {
            $c = wi_customers()[$r['phone']] ?? null;
            if ($c) $r['customer'] = wi_customer_detail((int) $c['id']);
        }
        wi_json($r);
    }
    if ($api === 'events') {
        $r = wi_gateway('/events' . (isset($_GET['since']) && $_GET['since'] !== '' ? '?since=' . (int) $_GET['since'] : ''));
        if (!empty($r['events']) && ($hide = wi_chats_taken_by_others((string) (wi_current()['id'] ?? ''))))
            $r['events'] = array_values(array_filter($r['events'], fn($e) => !isset($hide[(string) ($e['jid'] ?? $e['row']['jid'] ?? '')])));
        wi_json($r);
    }
    if ($api === 'presence') {
        $jid = preg_replace('/[^0-9A-Za-z@.:_-]/', '', (string) ($_GET['jid'] ?? ''));
        wi_json(wi_gateway('/presence/subscribe', ['jid' => $jid]));
    }
