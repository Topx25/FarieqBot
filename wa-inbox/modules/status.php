<?php
// WhatsApp Inbox — status (split out of features.php in v3.8, code unchanged)

/** API calls of this area (moved unchanged from the old single switch). */
function wf_api_status(string $api): void {
    switch ($api) {
    /* ----- 8) WhatsApp Status ----- */
    case 'status_upload': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') wi_json(['ok' => false, 'error' => 'POST only.'], 405);
        wi_check_csrf(); wf_admin();
        $f = $_FILES['file'] ?? null;
        if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) wi_json(['ok' => false, 'error' => 'Upload failed.'], 422);
        if ($f['size'] > (int) wi_sys('max_status_mb') * 1048576) wi_json(['ok' => false, 'error' => 'The file is larger than the allowed size (' . (int) wi_sys('max_status_mb') . ' MB).'], 422);
        $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'video/mp4' => 'mp4'];
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        if (!isset($types[$mime])) wi_json(['ok' => false, 'error' => 'Only photos (JPG, PNG, WEBP) and MP4 videos.'], 422);
        $name = 'status_' . date('Ymd_His') . '_' . bin2hex(random_bytes(5)) . '.' . $types[$mime];
        move_uploaded_file($f['tmp_name'], dirname(__DIR__, 2) . '/storage/inbox_uploads/' . $name); @chmod(dirname(__DIR__, 2) . '/storage/inbox_uploads/' . $name, 0644);
        wi_json(['ok' => true, 'attachment' => 'inbox_uploads/' . $name]);
    }
    case 'status_post': {
        $in = wf_post(); wf_admin(); @set_time_limit(240);
        $who = in_array($in['audience'] ?? '', ['customers', 'recent', 'all'], true) ? $in['audience'] : 'recent';
        $ch = wi_gateway('/archive/chats?months=' . ($who === 'all' ? 24 : 3)); $cust = wi_customers(); $aud = [];
        foreach ($ch['chats'] ?? [] as $c) {
            $p = (string) ($c['phone'] ?? ''); if ($p === '' || str_ends_with((string) $c['jid'], '@g.us')) continue;
            if ($who === 'customers' && !isset($cust[$p])) continue;
            $aud[$p] = true;
        }
        $att = (string) ($in['attachment'] ?? '');
        if ($att !== '' && !preg_match('#^inbox_uploads/status_[\w.-]+$#', $att)) $att = '';
        if (!empty($in['at']) && strtotime((string) $in['at']) > time() + 60) {
            $all = wi_read('scheduled.json', []);
            $all[] = ['id' => bin2hex(random_bytes(6)), 'acc' => wf_aid(), 'jid' => 'status@broadcast', 'name' => 'Status', 'text' => trim((string) ($in['text'] ?? '')), 'attachment' => $att, 'audience' => $who, 'at' => date('c', strtotime((string) $in['at'])), 'state' => 'pending', 'by' => (string) $_SESSION['email'], 'created' => date('c')];
            wi_write('scheduled.json', $all); wi_json(['ok' => true, 'scheduled' => true, 'audience' => count($aud)]);
        }
        $r = wi_gateway('/status/post', ['text' => trim((string) ($in['text'] ?? '')), 'attachment' => $att ?: null, 'audience' => array_slice(array_keys($aud), 0, 500), 'color' => (string) ($in['color'] ?? '#0f766e'), 'by' => (string) $_SESSION['email']]);
        wi_log('status_post', ['account' => wf_aid(), 'ok' => !empty($r['ok']), 'audience' => count($aud)]);
        wi_json($r);
    }

    }
}
