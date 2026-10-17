<?php
// WhatsApp Inbox API — library texts written by hand (add / edit / delete) and file upload to the library. Included by app/api.php inside the request.
    if ($api === 'lib_text_save' || $api === 'lib_text_delete' || $api === 'lib_upload') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') wi_json(['ok' => false, 'error' => 'POST only.'], 405);
        wi_check_csrf(); $acc = wi_current(); $aid = (string) ($acc['id'] ?? '');
        if (!$acc || !wi_can_manage($acc)) wi_json(['ok' => false, 'error' => 'Not allowed for this number.'], 403);
    }
    if ($api === 'lib_text_save') {
        $in = json_decode(wi_input(), true) ?: []; $id = preg_replace('/[^a-f0-9]/', '', (string) ($in['id'] ?? ''));
        $text = trim((string) ($in['text'] ?? '')); $title = trim(mb_substr((string) ($in['title'] ?? ''), 0, 120));
        if ($title === '' || $text === '') wi_json(['ok' => false, 'error' => 'Write a title and the text.'], 422);
        $code = wf_media_code_clean((string) ($in['code'] ?? ''));
        if ($id === '') $id = bin2hex(random_bytes(5));
        if ($code !== '' && ($why = wf_tag_taken($aid, $code, 'text:' . $id)) !== '') wi_json(['ok' => false, 'error' => $why], 422);
        $cover = (string) ($in['cover'] ?? ''); if ($cover !== '' && (!preg_match('#^inbox_uploads/[A-Za-z0-9_.-]{1,80}$#', $cover) || (wi_read('uploads.json', [])[substr($cover, 14)] ?? '') !== $aid)) $cover = '';
        $row = ['id' => $id, 'section' => mb_substr(trim((string) ($in['section'] ?? '')), 0, 60), 'topic' => mb_substr(trim((string) ($in['topic'] ?? '')), 0, 60), 'title' => $title, 'code' => $code,
            'keywords' => mb_substr(trim((string) ($in['keywords'] ?? '')), 0, 300), 'text' => mb_substr($text, 0, 8000), 'cover' => $cover, 'updated' => date('c'), 'by' => (string) ($_SESSION['email'] ?? '')];
        wi_update('lib_texts.json', function ($A) use ($aid, $row) { $L = (array) ($A[$aid] ?? []); $f = false; foreach ($L as $i => $x) if (($x['id'] ?? '') === $row['id']) { $L[$i] = $row + $x; $f = true; } if (!$f) $L[] = $row + ['created' => date('c')]; $A[$aid] = array_values($L); return $A; });
        wi_log('lib_text_save', ['account' => $aid, 'id' => $id]); wi_json(['ok' => true, 'id' => $id]);
    }
    if ($api === 'lib_text_delete') {
        $in = json_decode(wi_input(), true) ?: []; $id = preg_replace('/[^a-f0-9]/', '', (string) ($in['id'] ?? ''));
        wi_update('lib_texts.json', function ($A) use ($aid, $id) { $A[$aid] = array_values(array_filter((array) ($A[$aid] ?? []), fn($x) => ($x['id'] ?? '') !== $id)); return $A; });
        wi_log('lib_text_delete', ['account' => $aid, 'id' => $id]); wi_json(['ok' => true]);
    }
    if ($api === 'lib_upload') { // a file into the library (it shows in Media); also used for a text's cover
        $f = $_FILES['file'] ?? null;
        if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) wi_json(['ok' => false, 'error' => 'Upload failed.'], 422);
        if ($f['size'] > (int) wi_sys('max_send_mb') * 1048576) wi_json(['ok' => false, 'error' => 'The file is larger than the allowed size (' . (int) wi_sys('max_send_mb') . ' MB).'], 422);
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'audio/ogg' => 'ogg', 'audio/opus' => 'opus', 'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a', 'audio/x-m4a' => 'm4a',
            'audio/aac' => 'aac', 'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'application/pdf' => 'pdf', 'video/mp4' => 'mp4'];
        if (!isset($types[$mime])) wi_json(['ok' => false, 'error' => 'Only photos (JPG, PNG, WEBP), audio (OGG, MP3, M4A, WAV), PDF and MP4 video can be added.'], 422);
        $name = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $types[$mime]; $dir = dirname(__DIR__, 3) . '/storage/inbox_uploads';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) wi_json(['ok' => false, 'error' => 'Could not store the file.'], 500);
        @chmod($dir . '/' . $name, 0644);
        wi_update('uploads.json', function ($U) use ($name, $aid) { $U[$name] = $aid; return $U; });
        $nice = trim(preg_replace('/\.[A-Za-z0-9]{2,4}$/', '', mb_substr(basename((string) $f['name']), 0, 80)));
        if ($nice !== '' && !isset($_POST['cover'])) wi_update('media_meta.json', function ($M) use ($aid, $name, $nice) { $M[$aid]['inbox_uploads/' . $name]['title'] = $nice; return $M; }); // named after the file, renamable
        wi_log('lib_upload', ['account' => $aid, 'file' => $name]); wi_json(['ok' => true, 'key' => 'inbox_uploads/' . $name]);
    }
