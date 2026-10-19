<?php
/* ---------- Facebook page: comments on the page's posts ----------
 * Facebook sends every comment (webhook field "feed"); they are kept here in one list with the post they belong to.
 * A person replies publicly, replies privately (one private message per comment, within 7 days), hides, deletes or likes.
 * The bot can answer comments too: off / suggest only (a draft waits for a person) / automatic, in public, privately, or both.
 * Settings are per page (Facebook page card → Comments). */

function wf_fb_comments(string $aid): array { return (array) (wi_read('fb_comments.json', [])[$aid] ?? []); }
function wf_fb_comment_save(string $aid, array $row): void {
    wi_update('fb_comments.json', function ($C) use ($aid, $row) { $c = (array) ($C[$aid] ?? []); $c[$row['id']] = $row + (array) ($c[$row['id']] ?? []);
        if (count($c) > 3000) { uasort($c, fn($a, $b) => strcmp((string) ($b['time'] ?? ''), (string) ($a['time'] ?? ''))); $c = array_slice($c, 0, 3000, true); } $C[$aid] = $c; return $C; });
}
/** A post of the page (text, picture, link), kept for six hours. */
function wf_fb_post(string $aid, string $postId): array {
    $P = (array) (wi_read('fb_posts.json', [])[$aid][$postId] ?? []);
    if ($P && (int) ($P['at'] ?? 0) > time() - 6 * 3600) return $P;
    $r = wf_fb_graph($aid, 'GET', $postId, ['fields' => 'message,story,permalink_url,created_time,full_picture']);
    $P = ['at' => time(), 'text' => mb_substr(trim((string) ($r['message'] ?? $r['story'] ?? '')), 0, 400), 'url' => (string) ($r['permalink_url'] ?? ''), 'pic' => (string) ($r['full_picture'] ?? ''), 'time' => (string) ($r['created_time'] ?? ''), 'ok' => !empty($r['ok'])];
    wi_update('fb_posts.json', function ($X) use ($aid, $postId, $P) { $X[$aid][$postId] = $P; if (count($X[$aid]) > 300) $X[$aid] = array_slice($X[$aid], -250, null, true); return $X; });
    return $P;
}

/** A "feed" change from the webhook. */
function wf_fb_feed_take(array $acc, array $v, string $pageId): void {
    $aid = (string) $acc['id']; if (($v['item'] ?? '') !== 'comment') return;
    $id = (string) ($v['comment_id'] ?? ''); if ($id === '') return; $verb = (string) ($v['verb'] ?? 'add'); $from = (array) ($v['from'] ?? []);
    $old = wf_fb_comments($aid)[$id] ?? null;
    if ($verb === 'remove') { if ($old) wf_fb_comment_save($aid, ['id' => $id, 'status' => 'deleted']); return; }
    if ($verb === 'hide' || $verb === 'unhide') { if ($old) wf_fb_comment_save($aid, ['id' => $id, 'status' => $verb === 'hide' ? 'hidden' : ($old['status'] === 'hidden' ? 'new' : $old['status'])]); return; }
    $ours = (string) ($from['id'] ?? '') === $pageId;
    $row = ['id' => $id, 'post_id' => (string) ($v['post_id'] ?? ''), 'parent_id' => (string) ($v['parent_id'] ?? ''), 'from_id' => (string) ($from['id'] ?? ''), 'from_name' => mb_substr((string) ($from['name'] ?? ''), 0, 120),
        'text' => mb_substr(trim((string) ($v['message'] ?? '')), 0, 2000), 'time' => gmdate('c', (int) ($v['created_time'] ?? time())), 'status' => $ours ? 'page' : ($old['status'] ?? 'new'), 'photo' => (string) ($v['photo'] ?? $v['link'] ?? '')];
    wf_fb_comment_save($aid, $row);
    if ($verb === 'add' && !$ours && !$old) wf_fb_comment_bot($acc, $row + ['status' => 'new']);
}

/** The bot answers a new comment according to the page's settings. */
function wf_fb_comment_bot(array $acc, array $row): void {
    $aid = (string) $acc['id']; $cfg = wf_fb_cfg($aid); $mode = (string) $cfg['comment_bot']; if (!in_array($mode, ['draft', 'auto'], true) || trim($row['text']) === '') return;
    $run = wf_bot_run_cfg($aid); if ($run['mode'] === 'off') return;
    $r = wf_bot_answer($acc, $row['text'], [], '', null, ['role' => 'bot', 'jid' => 'fbc|' . $row['from_id']]);
    $reply = trim((string) ($r['reply'] ?? '')); if (empty($r['ok']) || $reply === '' || !empty($r['handoff']) || (float) ($r['confidence'] ?? 0) < (float) $run['min_conf']) { wf_fb_comment_save($aid, ['id' => $row['id'], 'bot' => 'skipped']); return; }
    if ($mode === 'draft' || $run['mode'] !== 'auto') { wf_fb_comment_save($aid, ['id' => $row['id'], 'draft' => $reply, 'bot' => 'draft']); return; }
    $did = [];
    if (!empty($cfg['comment_public'])) { $x = wf_fb_comment_act($aid, $row['id'], 'reply', $reply, true); if (!empty($x['ok'])) $did[] = 'public'; }
    if (!empty($cfg['comment_private']) && $row['parent_id'] === $row['post_id']) { $x = wf_fb_comment_act($aid, $row['id'], 'private', $reply, true); if (!empty($x['ok'])) $did[] = 'private'; }
    wf_fb_comment_save($aid, ['id' => $row['id'], 'bot' => $did ? 'sent:' . implode('+', $did) : 'failed']);
}

/** One action on a comment: reply, private, hide, unhide, delete, like. */
function wf_fb_comment_act(string $aid, string $id, string $act, string $text = '', bool $bot = false): array {
    $row = wf_fb_comments($aid)[$id] ?? null; if (!$row) return ['ok' => false, 'error' => 'Comment not found.'];
    $text = trim($text);
    switch ($act) {
        case 'reply': if ($text === '') return ['ok' => false, 'error' => 'Write the reply.'];
            $r = wf_fb_graph($aid, 'POST', $id . '/comments', ['message' => mb_substr($text, 0, 7900)]);
            if (!empty($r['ok'])) wf_fb_comment_save($aid, ['id' => $id, 'status' => 'replied', 'reply' => $text, 'reply_by' => $bot ? 'bot' : (string) ($_SESSION['email'] ?? ''), 'reply_at' => gmdate('c')]); return $r;
        case 'private': if ($text === '') return ['ok' => false, 'error' => 'Write the message.'];
            if (strtotime((string) $row['time']) < time() - 7 * 86400) return ['ok' => false, 'error' => 'A private reply is possible within 7 days of the comment.'];
            if (!empty($row['private'])) return ['ok' => false, 'error' => 'A private reply was already sent for this comment.'];
            $r = wf_fb_graph($aid, 'POST', 'me/messages', ['recipient' => ['comment_id' => $id], 'message' => ['text' => mb_substr($text, 0, 1900)], 'messaging_type' => 'RESPONSE']);
            if (!empty($r['ok'])) { wf_fb_comment_save($aid, ['id' => $id, 'private' => $text, 'status' => $row['status'] === 'new' ? 'replied' : $row['status']]);
                if (!empty($r['recipient_id'])) wf_fb_append($aid, ['dir' => 'out', 'jid' => $r['recipient_id'] . '@fb', 'phone' => (string) $r['recipient_id'], 'id' => (string) ($r['message_id'] ?? 'pr' . $id), 'kind' => 'text', 'text' => $text, 'via' => $bot ? 'bot' : 'inbox']); } // it shows in the person's Messenger chat too
            return $r;
        case 'hide': case 'unhide': $r = wf_fb_graph($aid, 'POST', $id, ['is_hidden' => $act === 'hide']); if (!empty($r['ok'])) wf_fb_comment_save($aid, ['id' => $id, 'status' => $act === 'hide' ? 'hidden' : 'new']); return $r;
        case 'delete': $r = wf_fb_graph($aid, 'DELETE', $id); if (!empty($r['ok'])) wf_fb_comment_save($aid, ['id' => $id, 'status' => 'deleted']); return $r;
        case 'like': $r = wf_fb_graph($aid, 'POST', $id . '/likes'); if (!empty($r['ok'])) wf_fb_comment_save($aid, ['id' => $id, 'liked' => 1]); return $r;
    }
    return ['ok' => false, 'error' => 'Unknown action.'];
}

/** A story of the page. A photo: uploaded unpublished, then published as a story. A video: Facebook's three steps (start, upload, finish). */
function wf_fb_story(string $aid, string $abs): array {
    $mime = mime_content_type($abs) ?: ''; $pid = wf_fb_cfg($aid)['page_id'] ?: 'me';
    if (strpos($mime, 'image/') === 0) {
        $up = wf_fb_graph($aid, 'POST', $pid . '/photos', ['published' => 'false'], ['path' => $abs, 'mime' => $mime, 'name' => basename($abs), 'field' => 'source']); if (empty($up['ok']) || empty($up['id'])) return $up + ['ok' => false];
        return wf_fb_graph($aid, 'POST', $pid . '/photo_stories', ['photo_id' => (string) $up['id']]);
    }
    if ($mime !== 'video/mp4') return ['ok' => false, 'error' => 'Only photos and MP4 videos.'];
    $st = wf_fb_graph($aid, 'POST', $pid . '/video_stories', ['upload_phase' => 'start']); if (empty($st['ok']) || empty($st['video_id']) || empty($st['upload_url'])) return $st + ['ok' => false, 'error' => $st['error'] ?? 'Facebook did not start the upload.'];
    if (PHP_SAPI !== 'cli' || !isset($GLOBALS['WI_FAKE_GRAPH'])) { // the file itself goes to the upload address Facebook gave
        $ch = curl_init((string) $st['upload_url']); curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => file_get_contents($abs), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 300, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => ['Authorization: OAuth ' . wf_fb_token($aid), 'offset: 0', 'file_size: ' . filesize($abs)]]);
        $raw = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch); $j = json_decode((string) $raw, true);
        if ($code >= 400 || !is_array($j) || empty($j['success'])) return ['ok' => false, 'error' => (string) ($j['debug_info']['message'] ?? 'The video upload to Facebook failed.')];
    }
    return wf_fb_graph($aid, 'POST', $pid . '/video_stories', ['upload_phase' => 'finish', 'video_id' => (string) $st['video_id']]);
}

/* ---------- API ---------- */
function wf_api_fbfeed(string $api): void {
    if (!in_array($api, ['fb_comments', 'fb_comment_act', 'fb_cfg', 'fb_posts', 'fb_post_act', 'fb_upload', 'fb_insights'], true)) return;
    $acc = wi_current(); if (!wf_fb_is($acc)) wi_json(['ok' => false, 'error' => 'Open a Facebook page from the numbers list first.']);
    $aid = (string) $acc['id'];
    if ($api === 'fb_cfg') {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') { $in = wf_post(); wf_admin();
            wf_fb_cfg_set($aid, ['comment_bot' => in_array($in['comment_bot'] ?? '', ['off', 'draft', 'auto'], true) ? $in['comment_bot'] : 'off', 'comment_public' => !empty($in['comment_public']) ? 1 : 0, 'comment_private' => !empty($in['comment_private']) ? 1 : 0]); wi_log('fb_cfg', ['account' => $aid]); }
        $c = wf_fb_cfg($aid); wi_json(['ok' => true, 'cfg' => ['comment_bot' => $c['comment_bot'], 'comment_public' => (int) $c['comment_public'], 'comment_private' => (int) $c['comment_private']], 'connected' => $c['error'] === '' && $c['ok_at'] !== '']);
    }
    if ($api === 'fb_upload') { // a photo or video for a post
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') wi_json(['ok' => false, 'error' => 'POST only.'], 405); wi_check_csrf();
        $f = $_FILES['file'] ?? null; if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) wi_json(['ok' => false, 'error' => 'Upload failed.'], 422);
        if ($f['size'] > (int) wi_sys('max_send_mb') * 1048576) wi_json(['ok' => false, 'error' => 'The file is larger than the allowed size (' . (int) wi_sys('max_send_mb') . ' MB).'], 422);
        $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'video/mp4' => 'mp4']; $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        if (!isset($types[$mime])) wi_json(['ok' => false, 'error' => 'Only photos (JPG, PNG, WEBP, GIF) and MP4 videos.'], 422);
        $name = 'fbpost_' . date('Ymd_His') . '_' . bin2hex(random_bytes(5)) . '.' . $types[$mime]; $dir = dirname(__DIR__, 2) . '/storage/inbox_uploads/'; if (!is_dir($dir)) @mkdir($dir, 0755, true);
        move_uploaded_file($f['tmp_name'], $dir . $name); @chmod($dir . $name, 0644); wi_json(['ok' => true, 'attachment' => 'inbox_uploads/' . $name]);
    }
    if ($api === 'fb_posts') {
        $fields = 'id,message,created_time,permalink_url,full_picture,shares,reactions.summary(true).limit(0),comments.summary(true).limit(0)';
        $pub = wf_fb_graph($aid, 'GET', 'me/posts', ['fields' => $fields, 'limit' => 25]); if (empty($pub['ok'])) wi_json(['ok' => false, 'error' => (string) ($pub['error'] ?? 'Facebook refused the request.')]);
        $sch = wf_fb_graph($aid, 'GET', 'me/scheduled_posts', ['fields' => 'id,message,scheduled_publish_time,full_picture,permalink_url', 'limit' => 25]);
        $map = fn($x, $sched) => ['id' => (string) $x['id'], 'text' => (string) ($x['message'] ?? ''), 'time' => $sched ? gmdate('c', (int) ($x['scheduled_publish_time'] ?? 0)) : (string) ($x['created_time'] ?? ''), 'url' => (string) ($x['permalink_url'] ?? ''), 'pic' => (string) ($x['full_picture'] ?? ''),
            'reactions' => (int) ($x['reactions']['summary']['total_count'] ?? 0), 'comments' => (int) ($x['comments']['summary']['total_count'] ?? 0), 'shares' => (int) ($x['shares']['count'] ?? 0), 'scheduled' => $sched];
        wi_json(['ok' => true, 'scheduled' => array_map(fn($x) => $map($x, true), (array) ($sch['data'] ?? [])), 'posts' => array_map(fn($x) => $map($x, false), (array) ($pub['data'] ?? []))]);
    }
    if ($api === 'fb_post_act') { $in = wf_post(); $act = (string) ($in['act'] ?? ''); $id = preg_replace('/[^0-9A-Za-z_]/', '', (string) ($in['id'] ?? '')); $text = trim((string) ($in['text'] ?? ''));
        if ($act === 'story') { // a story of the page (photo or video), visible for 24 hours
            $att = (string) ($in['attachment'] ?? ''); $abs = dirname(__DIR__, 2) . '/storage/' . $att;
            if ($att === '' || strpos($att, '..') !== false || !preg_match('#^inbox_uploads/fbpost_[A-Za-z0-9_.]+$#', $att) || !is_file($abs)) wi_json(['ok' => false, 'error' => 'A story needs a photo or a video.']);
            $r = wf_fb_story($aid, $abs); if (empty($r['ok'])) wi_json(['ok' => false, 'error' => (string) ($r['error'] ?? 'Facebook refused the request.')]); wi_log('fb_story', ['account' => $aid]); wi_json(['ok' => true, 'id' => (string) ($r['post_id'] ?? '')]); }
        if ($act === 'create') { $att = (string) ($in['attachment'] ?? ''); $link = trim((string) ($in['link'] ?? '')); $at = (int) ($in['at'] ?? 0);
            if ($text === '' && $att === '' && $link === '') wi_json(['ok' => false, 'error' => 'Write the post or add a photo, video or link.']);
            if ($link !== '' && !preg_match('#^https?://#i', $link)) wi_json(['ok' => false, 'error' => 'The link must start with http:// or https://.']);
            $sched = $at > time() + 600 ? ['published' => false, 'scheduled_publish_time' => $at] : [];
            if ($at > 0 && !$sched) wi_json(['ok' => false, 'error' => 'A scheduled time must be at least 10 minutes from now.']);
            if ($att !== '') { $abs = dirname(__DIR__, 2) . '/storage/' . $att; if (strpos($att, '..') !== false || !preg_match('#^inbox_uploads/fbpost_[A-Za-z0-9_.]+$#', $att) || !is_file($abs)) wi_json(['ok' => false, 'error' => 'File not found.']);
                $mime = mime_content_type($abs) ?: ''; $vid = strpos($mime, 'video/') === 0;
                $r = wf_fb_graph($aid, 'POST', $vid ? 'me/videos' : 'me/photos', ($vid ? ['description' => $text] : ['caption' => $text]) + $sched, ['path' => $abs, 'mime' => $mime, 'name' => basename($abs), 'field' => 'source']); }
            else $r = wf_fb_graph($aid, 'POST', 'me/feed', ['message' => $text] + ($link !== '' ? ['link' => $link] : []) + $sched);
            if (empty($r['ok'])) wi_json(['ok' => false, 'error' => (string) ($r['error'] ?? 'Facebook refused the request.')]); wi_log('fb_post', ['account' => $aid, 'scheduled' => (bool) $sched]); wi_json(['ok' => true, 'id' => (string) ($r['post_id'] ?? $r['id'] ?? '')]); }
        if ($id === '') wi_json(['ok' => false, 'error' => 'Post not found.']);
        if ($act === 'edit') { if ($text === '') wi_json(['ok' => false, 'error' => 'Write the post.']); $r = wf_fb_graph($aid, 'POST', $id, ['message' => $text]); }
        elseif ($act === 'delete') $r = wf_fb_graph($aid, 'DELETE', $id);
        else wi_json(['ok' => false, 'error' => 'Unknown action.']);
        wi_json(!empty($r['ok']) ? ['ok' => true] : ['ok' => false, 'error' => (string) ($r['error'] ?? 'Facebook refused the request.')]);
    }
    if ($api === 'fb_insights') { // Meta retired the old reach / impressions metrics (2025-2026): only the current ones are asked, one by one, so a metric Meta no longer offers does not hide the others
        $me = wf_fb_graph($aid, 'GET', 'me', ['fields' => 'name,followers_count,fan_count,link']); $days = 28; $out = [];
        foreach (['page_media_view' => 'Media views', 'page_total_media_view_unique' => 'People who viewed', 'page_post_engagements' => 'Post engagements', 'page_views_total' => 'Page visits', 'page_daily_follows' => 'New followers'] as $m => $label) {
            $r = wf_fb_graph($aid, 'GET', 'me/insights', ['metric' => $m, 'period' => 'day', 'since' => time() - $days * 86400, 'until' => time()]);
            if (empty($r['ok'])) { $out[] = ['metric' => $m, 'label' => $label, 'ok' => false, 'error' => (string) ($r['error'] ?? '')]; continue; }
            $vals = array_map(fn($v) => (float) (is_array($v['value'] ?? 0) ? array_sum($v['value']) : ($v['value'] ?? 0)), (array) ($r['data'][0]['values'] ?? []));
            $out[] = ['metric' => $m, 'label' => $label, 'ok' => true, 'series' => $vals, 'total' => array_sum($vals), 'last7' => array_sum(array_slice($vals, -7))]; }
        wi_json(['ok' => true, 'page' => ['name' => (string) ($me['name'] ?? ''), 'followers' => (int) ($me['followers_count'] ?? 0), 'likes' => (int) ($me['fan_count'] ?? 0), 'url' => (string) ($me['link'] ?? '')], 'metrics' => $out, 'days' => $days]);
    }
    if ($api === 'fb_comment_act') { $in = wf_post(); $r = wf_fb_comment_act($aid, preg_replace('/[^0-9A-Za-z_]/', '', (string) ($in['id'] ?? '')), (string) ($in['act'] ?? ''), (string) ($in['text'] ?? ''));
        if (empty($r['ok'])) wi_json(['ok' => false, 'error' => (string) ($r['error'] ?? 'Facebook refused the request.')]); wi_json(['ok' => true, 'row' => wf_fb_comments($aid)[$in['id']] ?? null]); }
    $st = (string) ($_GET['status'] ?? ''); $q = mb_strtolower(trim((string) ($_GET['q'] ?? ''))); $rows = array_values(wf_fb_comments($aid)); $count = ['new' => 0, 'replied' => 0, 'hidden' => 0, 'draft' => 0];
    foreach ($rows as $r) { if (($r['status'] ?? '') === 'new') $count['new']++; elseif (($r['status'] ?? '') === 'replied') $count['replied']++; elseif (($r['status'] ?? '') === 'hidden') $count['hidden']++; if (!empty($r['draft']) && ($r['status'] ?? '') === 'new') $count['draft']++; }
    $rows = array_values(array_filter($rows, fn($r) => !in_array($r['status'] ?? '', ['deleted', 'page'], true) && ($st === '' || ($st === 'draft' ? (!empty($r['draft']) && ($r['status'] ?? '') === 'new') : ($r['status'] ?? '') === $st)) && ($q === '' || mb_strpos(mb_strtolower(($r['text'] ?? '') . ' ' . ($r['from_name'] ?? '')), $q) !== false)));
    usort($rows, fn($a, $b) => strcmp((string) ($b['time'] ?? ''), (string) ($a['time'] ?? ''))); $rows = array_slice($rows, 0, 200); $fetch = 0;
    foreach ($rows as &$r) { $P = (array) (wi_read('fb_posts.json', [])[$aid][$r['post_id'] ?? ''] ?? []); if (!$P && $fetch < 5 && !empty($r['post_id'])) { $P = wf_fb_post($aid, (string) $r['post_id']); $fetch++; } $r['post'] = ['text' => (string) ($P['text'] ?? ''), 'url' => (string) ($P['url'] ?? ''), 'pic' => (string) ($P['pic'] ?? '')]; } unset($r);
    wi_json(['ok' => true, 'rows' => $rows, 'count' => $count]);
}
