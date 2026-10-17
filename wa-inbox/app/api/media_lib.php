<?php
// WhatsApp Inbox API — media library: every photo, video, recording and file of the open number, with a title, caption, folder,
// tags and favourite, ready to be sent again in any chat (with its caption) or forwarded from a chat. Included by app/api.php.

/** A media key is the file's place in storage: inbox_media/<this number>/<file> or inbox_uploads/<file> sent from this number. */
$mlOk = function (string $key, string $aid): bool {
    if (preg_match('#^inbox_media/([a-z0-9_-]+)/[A-Za-z0-9_.-]{1,80}$#', $key, $m)) return $m[1] === $aid && is_file(dirname(__DIR__, 3) . '/storage/' . $key);
    if (preg_match('#^inbox_uploads/([A-Za-z0-9_.-]{1,80})$#', $key, $m)) return (string) (wi_read('uploads.json', [])[$m[1]] ?? '') === $aid && is_file(dirname(__DIR__, 3) . '/storage/' . $key);
    return false;
};
// kind by file type when the archive does not say (stickers are .webp, voice notes .ogg / .opus)
$mlKind = fn(string $f) => preg_match('/\.webp$/i', $f) ? 'sticker' : (preg_match('/\.(jpe?g|png)$/i', $f) ? 'image' : (preg_match('/\.(mp4|3gp|mov)$/i', $f) ? 'video'
    : (preg_match('/\.(ogg|opus)$/i', $f) ? 'voice' : (preg_match('/\.(mp3|m4a|aac|wav)$/i', $f) ? 'audio' : 'document'))));

if ($api === 'media_lib') { // the index of this number's media
    $acc = wi_current(); if (!$acc) wi_json(['ok' => false, 'error' => 'No number.']); $aid = (string) $acc['id']; $root = dirname(__DIR__, 3) . '/storage/';
    $meta = (array) (wi_read('media_meta.json', [])[$aid] ?? []); $items = [];
    $mk = wi_gateway('/archive/media-kinds', null, $acc); $real = (array) ($mk['kinds'] ?? []); $names = (array) ($mk['names'] ?? []); $fromChat = (array) ($mk['chats'] ?? []); $chatNames = []; foreach ((array) (wi_gateway('/archive/chats?months=' . (int) wi_sys('months_long'), null, $acc)['chats'] ?? []) as $ch) $chatNames[(string) $ch['jid']] = (string) ($ch['name'] ?? '') ?: (string) ($ch['phone'] ?? ''); // the real kind from WhatsApp (gif vs video, voice vs audio, sticker vs photo) and the file's own name
    foreach (glob($root . 'inbox_media/' . $aid . '/*') ?: [] as $p) { $f = basename($p); if (!preg_match('/^[A-Za-z0-9_.-]{1,80}$/', $f) || str_ends_with($f, '.part')) continue; $items['inbox_media/' . $aid . '/' . $f] = $p; }
    foreach ((array) wi_read('uploads.json', []) as $f => $a) if ($a === $aid && is_file($root . 'inbox_uploads/' . $f)) $items['inbox_uploads/' . $f] = $root . 'inbox_uploads/' . $f;
    $bin = !empty($_GET['bin']); // the deleted ones (they can be put back)
    $out = []; $other = ['count' => 0, 'size' => 0]; // the other side (in Deleted, or still in the library) is only counted
    foreach ($items as $key => $p) { $m = (array) ($meta[$key] ?? []); if (!empty($m['hidden']) !== $bin) { $other['count']++; $other['size'] += (int) filesize($p); continue; }
        $rk = (string) ($real[$key] ?? ''); $kind = in_array($rk, ['sticker', 'image', 'gif', 'video', 'voice', 'audio', 'document'], true) ? $rk : $mlKind($key);
        if ($kind === 'document' && $mlKind($key) !== 'document') $kind = $mlKind($key); // an audio / photo sent as a file is still shown by what it is
        $out[] = ['key' => $key, 'kind' => $kind, 'size' => (int) filesize($p), 'at' => date('c', (int) filemtime($p)), 'title' => (string) ($m['title'] ?? ''), 'caption' => (string) ($m['caption'] ?? ''), 'name' => (string) ($names[$key] ?? ''), 'code' => (string) ($m['code'] ?? ''),
            'folder' => (string) ($m['folder'] ?? ''), 'jid' => (string) ($fromChat[$key] ?? ''), 'chat' => (string) ($chatNames[(string) ($fromChat[$key] ?? '')] ?? ''), 'tags' => array_values((array) ($m['tags'] ?? [])), 'fav' => !empty($m['fav']), 'used' => (int) ($m['used'] ?? 0), 'youtube' => $m['youtube'] ?? null]; }
    usort($out, fn($a, $b) => [$b['fav'], $b['at']] <=> [$a['fav'], $a['at']]);
    wi_json(['ok' => true, 'items' => $out, 'bin' => $bin ? null : $other, 'folders' => array_values(array_unique(array_filter(array_column($out, 'folder'))))]);
}
if ($api === 'media_zip') { // the selected files in one .zip download
    $acc = wi_current(); if (!$acc || !class_exists('ZipArchive')) wi_json(['ok' => false, 'error' => 'Not available on this server.']); $aid = (string) $acc['id']; $root = dirname(__DIR__, 3) . '/storage/';
    $keys = array_slice(array_filter(explode(',', (string) ($_GET['keys'] ?? ''))), 0, 300); $tmp = tempnam(sys_get_temp_dir(), 'wiz'); $z = new ZipArchive(); $z->open($tmp, ZipArchive::OVERWRITE); $n = 0; $meta = (array) (wi_read('media_meta.json', [])[$aid] ?? []);
    foreach ($keys as $k) { if (!preg_match('#^(inbox_media/' . preg_quote($aid, '#') . '|inbox_uploads)/[A-Za-z0-9_.-]{1,80}$#', $k)) continue; $p = $root . $k; if (!is_file($p)) continue; // only this number's files
        $t = trim((string) ($meta[$k]['title'] ?? '')); $nm = $t !== '' ? preg_replace('/[\\\\\/:*?"<>|]+/u', '_', mb_substr($t, 0, 60)) . '.' . pathinfo($p, PATHINFO_EXTENSION) : basename($p); $z->addFile($p, (++$n) . '-' . $nm); }
    $z->close(); if (!$n) { @unlink($tmp); wi_json(['ok' => false, 'error' => 'Nothing to download.']); }
    header('Content-Type: application/zip'); header('Content-Disposition: attachment; filename="media-' . date('Y-m-d') . '.zip"'); header('Content-Length: ' . filesize($tmp)); readfile($tmp); @unlink($tmp); exit;
}
if ($api === 'media_inventory') { // the full list of the library, built now (always the latest); ?fmt=csv downloads it
    $acc = wi_current(); if (!$acc) wi_json(['ok' => false, 'error' => 'No number.']); $v = wf_lib_inventory($acc);
    if (($_GET['fmt'] ?? '') === 'csv') { header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="library-' . date('Y-m-d') . '.csv"');
        $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF"); fputcsv($o, ['shortcut', 'title', 'sent name', 'kind', 'folder', 'size KB', 'sent', 'date', 'caption', 'key']);
        foreach ($v['items'] as $x) fputcsv($o, [$x['code'], $x['title'], $x['name'], $x['kind'], $x['folder'], (string) round($x['size'] / 1024), $x['used'], $x['at'], $x['caption'], $x['key']]);
        fclose($o); exit; }
    wi_json(['ok' => true] + $v);
}
if ($api === 'media_meta') { // title / caption / folder / tags / favourite / hide of one item
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') wi_json(['ok' => false, 'error' => 'POST only.'], 405);
    wi_check_csrf(); $in = json_decode(wi_input(), true) ?: []; $acc = wi_current(); $aid = (string) ($acc['id'] ?? ''); $key = (string) ($in['key'] ?? '');
    if (!$mlOk($key, $aid)) wi_json(['ok' => false, 'error' => 'File not found.'], 404);
    $x = ['title' => mb_substr(trim((string) ($in['title'] ?? '')), 0, 80), 'caption' => mb_substr(trim((string) ($in['caption'] ?? '')), 0, 1000), 'folder' => mb_substr(trim((string) ($in['folder'] ?? '')), 0, 40),
        'tags' => array_slice(array_values(array_filter(array_map(fn($t) => mb_substr(trim($t), 0, 30), preg_split('/[,،\n]+/u', (string) ($in['tags'] ?? ''))))), 0, 12), 'fav' => !empty($in['fav']), 'hidden' => !empty($in['hidden']),
        'code' => wf_media_code_clean((string) ($in['code'] ?? ''))];
    $x = array_intersect_key($x, $in);
    if (($x['code'] ?? '') !== '' && ($why = wf_tag_taken($aid, $x['code'], $key)) !== '') wi_json(['ok' => false, 'error' => $why], 422); // only what was sent changes (a quick rename keeps the folder, tags, favourite…)
    wi_update('media_meta.json', function ($M) use ($aid, $key, $x) { $M[$aid][$key] = array_merge((array) ($M[$aid][$key] ?? []), $x); return $M; }); // keeps the send count, the YouTube status…
    wi_log('media_meta', ['account' => $aid, 'key' => $key]); wi_json(['ok' => true]);
}
if ($api === 'media_send') { // send a library item (or forward a chat's media) to one or more chats, with a caption
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') wi_json(['ok' => false, 'error' => 'POST only.'], 405);
    wi_check_csrf(); $in = json_decode(wi_input(), true) ?: []; $acc = wi_current(); $aid = (string) ($acc['id'] ?? ''); $key = (string) ($in['key'] ?? '');
    $keys = array_values(array_filter(array_slice((array) ($in['keys'] ?? [$key]), 0, 30), fn($k) => $mlOk((string) $k, $aid))); // one file or several
    if (!$keys) wi_json(['ok' => false, 'error' => 'File not found.'], 404);
    $jids = array_slice(array_values(array_unique(array_filter(array_map(fn($j) => preg_replace('/[^0-9A-Za-z@.:_-]/', '', (string) $j), (array) ($in['jids'] ?? [$in['jid'] ?? '']))))), 0, 10);
    if (!$jids) wi_json(['ok' => false, 'error' => 'Choose a conversation.'], 422);
    if (count($keys) * count($jids) > 30) wi_json(['ok' => false, 'error' => 'At most 30 messages at once (files × chats) — it protects the number.'], 422);
    foreach ($jids as $j) if (!wi_chat_visible($aid, $j)) wi_json(['ok' => false, 'error' => 'This conversation is handled by a colleague.'], 403);
    $email = (string) ($_SESSION['email'] ?? '');
    $kinds = (array) (wi_gateway('/archive/media-kinds', null, $acc)['kinds'] ?? []); $meta = (array) (wi_read('media_meta.json', [])[$aid] ?? []);
    $caption = array_key_exists('caption', $in) ? mb_substr(trim((string) $in['caption']), 0, 1000) : null; $items = []; $used = [];
    foreach ($jids as $j) foreach ($keys as $k) {
        $rk = (string) ($kinds[$k] ?? ''); $as = in_array($rk, ['sticker', 'gif'], true) ? $rk : (str_ends_with(strtolower($k), '.webp') ? 'sticker' : '');
        $cap = count($keys) > 1 || $caption === null ? (string) ($meta[$k]['caption'] ?? '') : $caption; // several files: each with its own caption
        $items[] = ['jid' => $j, 'text' => $cap, 'attachment' => $k, 'as' => $as, 'by' => $email]; $used[$k] = ($used[$k] ?? 0) + 1; }
    // the gateway keeps the queue and sends them one by one: the answer comes at once, whatever the user's connection does
    $r = wi_gateway('/chat/send-batch', ['items' => $items], $acc);
    if (!empty($r['ok'])) wi_update('media_meta.json', function ($M) use ($aid, $used) { foreach ($used as $k => $c) $M[$aid][$k]['used'] = (int) ($M[$aid][$k]['used'] ?? 0) + $c; return $M; });
    wi_log('media_send', ['account' => $aid, 'files' => count($keys), 'chats' => count($jids), 'queued' => !empty($r['ok'])]);
    wi_json(['ok' => !empty($r['ok']), 'queued' => true, 'of' => count($items), 'error' => (string) ($r['error'] ?? '')]);
}
/* ---------- folders (with sub-folders as "Parent/Child") and actions on many items at once ---------- */
$mlPath = fn($p) => implode('/', array_filter(array_map(fn($x) => mb_substr(trim(str_replace(['\\', '..'], '', $x)), 0, 40), explode('/', (string) $p)), fn($x) => $x !== ''));
if ($api === 'media_folders') { // create / rename / delete a folder; GET lists them
    $acc = wi_current(); $aid = (string) ($acc['id'] ?? ''); if ($aid === '') wi_json(['ok' => false, 'error' => 'No number.']);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') { wi_check_csrf(); $in = json_decode(wi_input(), true) ?: []; $do = (string) ($in['do'] ?? ''); $path = $mlPath($in['path'] ?? ''); $to = $mlPath($in['to'] ?? '');
        if ($path === '') wi_json(['ok' => false, 'error' => 'Name the folder.'], 422);
        if ($do === 'rename' && $to === '') wi_json(['ok' => false, 'error' => 'Name the folder.'], 422);
        wi_update('media_meta.json', function ($M) use ($aid, $do, $path, $to) {
            $F = (array) ($M[$aid . '|folders'] ?? []); $under = fn($f) => $f === $path || str_starts_with($f, $path . '/');
            $parent = str_contains($path, '/') ? substr($path, 0, strrpos($path, '/')) : '';
            if ($do === 'create') { $acc = ''; foreach (explode('/', $path) as $part) { $acc = $acc === '' ? $part : $acc . '/' . $part; $F[] = $acc; } } // parents too
            if ($do === 'rename') { $F = array_map(fn($f) => $under($f) ? $to . substr($f, strlen($path)) : $f, $F); $F[] = $to;
                foreach ((array) ($M[$aid] ?? []) as $k => $x) if ($under((string) ($x['folder'] ?? ''))) $M[$aid][$k]['folder'] = $to . substr((string) $x['folder'], strlen($path)); }
            if ($do === 'delete') { $F = array_filter($F, fn($f) => !$under($f)); // the files go up to the parent folder, never lost
                foreach ((array) ($M[$aid] ?? []) as $k => $x) if ($under((string) ($x['folder'] ?? ''))) $M[$aid][$k]['folder'] = $parent; }
            $F = array_values(array_unique(array_filter($F))); sort($F); $M[$aid . '|folders'] = $F; return $M; });
        wi_log('media_folder', ['account' => $aid, 'do' => $do, 'path' => $path]); }
    $M = wi_read('media_meta.json', []); $F = (array) ($M[$aid . '|folders'] ?? []);
    foreach ((array) ($M[$aid] ?? []) as $x) if (!empty($x['folder'])) { $acc2 = ''; foreach (explode('/', (string) $x['folder']) as $part) { $acc2 = $acc2 === '' ? $part : $acc2 . '/' . $part; $F[] = $acc2; } }
    $F = array_values(array_unique($F)); sort($F); wi_json(['ok' => true, 'folders' => $F]);
}
if ($api === 'media_bulk') { // move to a folder / favourite / remove from the library — for several items
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') wi_json(['ok' => false, 'error' => 'POST only.'], 405);
    wi_check_csrf(); $in = json_decode(wi_input(), true) ?: []; $acc = wi_current(); $aid = (string) ($acc['id'] ?? ''); $do = (string) ($in['do'] ?? '');
    $keys = array_values(array_filter(array_slice((array) ($in['keys'] ?? []), 0, 500), fn($k) => $mlOk((string) $k, $aid)));
    if (!$keys) wi_json(['ok' => false, 'error' => 'Choose files first.'], 422);
    if (!in_array($do, ['move', 'fav', 'unfav', 'delete', 'restore', 'tag', 'caption', 'rename'], true)) wi_json(['ok' => false, 'error' => 'Unknown action.'], 422);
    $folder = $mlPath($in['folder'] ?? ''); $cap = mb_substr(trim((string) ($in['caption'] ?? '')), 0, 1000);
    $tags = array_values(array_filter(array_map(fn($t) => mb_substr(trim($t), 0, 30), preg_split('/[,،\n]+/u', (string) ($in['tags'] ?? '')))));
    // rename several: "Offer" → "Offer 1", "Offer 2"… in the order chosen (one file keeps the name as written); the caption too when given
    $nm = mb_substr(trim((string) ($in['name'] ?? '')), 0, 70); if ($do === 'rename' && $nm === '') wi_json(['ok' => false, 'error' => 'Write a name.'], 422);
    $num = $do === 'rename' && count($keys) > 1; $withCap = $do === 'rename' && array_key_exists('caption', $in);
    wi_update('media_meta.json', function ($M) use ($aid, $keys, $do, $folder, $tags, $cap, $nm, $num, $withCap) { foreach ($keys as $i => $k) { $x = (array) ($M[$aid][$k] ?? []);
        if ($do === 'rename') { $x['title'] = $num ? $nm . ' ' . ($i + 1) : $nm; if ($withCap) $x['caption'] = $cap; $M[$aid][$k] = $x; continue; }
        if ($do === 'tag') $x['tags'] = array_slice(array_values(array_unique(array_merge((array) ($x['tags'] ?? []), $tags))), 0, 12);
        elseif ($do === 'caption') $x['caption'] = $cap;
        elseif ($do === 'move') $x['folder'] = $folder; elseif ($do === 'fav' || $do === 'unfav') $x['fav'] = $do === 'fav'; else $x['hidden'] = $do === 'delete';
        $M[$aid][$k] = $x; } return $M; });
    wi_log('media_bulk', ['account' => $aid, 'do' => $do, 'count' => count($keys)]); wi_json(['ok' => true, 'count' => count($keys)]);
}
if ($api === 'media_zip') { // download the chosen files as one .zip
    $acc = wi_current(); $aid = (string) ($acc['id'] ?? ''); $keys = array_values(array_filter(array_slice(explode(',', (string) ($_GET['keys'] ?? '')), 0, 200), fn($k) => $mlOk($k, $aid)));
    if (!$keys || !class_exists('ZipArchive')) { http_response_code(404); exit('No files.'); }
    $meta = (array) (wi_read('media_meta.json', [])[$aid] ?? []); $tmp = tempnam(sys_get_temp_dir(), 'wiz'); $z = new ZipArchive(); $z->open($tmp, ZipArchive::OVERWRITE); $names = [];
    foreach ($keys as $k) { $base = preg_replace('#[\\\\/:*?"<>|]+#', '_', (string) ($meta[$k]['title'] ?? '')) ?: pathinfo($k, PATHINFO_FILENAME); $ext = pathinfo($k, PATHINFO_EXTENSION); $nm = $base . '.' . $ext; $i = 2; while (isset($names[$nm])) $nm = $base . ' (' . $i++ . ').' . $ext; $names[$nm] = 1;
        $f = (string) ($meta[$k]['folder'] ?? ''); $z->addFile(dirname(__DIR__, 3) . '/storage/' . $k, ($f !== '' ? $f . '/' : '') . $nm); }
    $z->close(); wi_log('media_zip', ['account' => $aid, 'files' => count($keys)]);
    header('Content-Type: application/zip'); header('Content-Length: ' . filesize($tmp)); header('Content-Disposition: attachment; filename="media-' . date('Y-m-d') . '.zip"'); readfile($tmp); @unlink($tmp); exit;
}
if ($api === 'youtube_connect') { // the YouTube sign-in on its own (Google does not allow it together with Drive)
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') wi_json(['ok' => false, 'error' => 'POST only.'], 405);
    wi_check_csrf(); $c = wf_drive_cfg();
    if (empty($c['client_id']) || empty($c['client_secret'])) wi_json(['ok' => false, 'error' => 'Google sign-in is not set up on this installation yet. Ask the administrator.']);
    $state = bin2hex(random_bytes(16)); $ws = wf_google_ws();
    wi_update('google_states.json', function ($A) use ($state, $ws) { $A[$state] = ['ws' => $ws, 'at' => time(), 'kind' => 'yt', 'back' => '?p=media']; return $A; });
    wi_json(['ok' => true, 'url' => 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query(['client_id' => $c['client_id'], 'redirect_uri' => wf_drive_redirect(), 'response_type' => 'code', 'scope' => WF_YOUTUBE_SCOPE, 'access_type' => 'offline', 'prompt' => 'consent', 'state' => $state])]);
}
if ($api === 'media_youtube') { // queue videos for upload to YouTube (title / description / tags / privacy)
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') wi_json(['ok' => false, 'error' => 'POST only.'], 405);
    wi_check_csrf(); $in = json_decode(wi_input(), true) ?: []; $acc = wi_current(); $aid = (string) ($acc['id'] ?? '');
    if (!wf_yt_connected()) wi_json(['ok' => false, 'need_yt' => true, 'error' => 'Connect YouTube first (a separate sign-in from Google Drive).']);
    $kinds = (array) (wi_gateway('/archive/media-kinds', null, $acc)['kinds'] ?? []); $meta = (array) (wi_read('media_meta.json', [])[$aid] ?? []);
    $keys = array_values(array_filter(array_slice((array) ($in['keys'] ?? []), 0, 20), fn($k) => $mlOk((string) $k, $aid) && preg_match('/\.(mp4|3gp|mov)$/i', (string) $k) && ($kinds[$k] ?? 'video') !== 'gif'));
    if (!$keys) wi_json(['ok' => false, 'error' => 'Choose videos.'], 422);
    $privacy = in_array($in['privacy'] ?? '', ['private', 'unlisted', 'public'], true) ? $in['privacy'] : 'private';
    $tags = array_values(array_filter(array_map(fn($t) => mb_substr(trim($t), 0, 30), preg_split('/[,،\n]+/u', (string) ($in['tags'] ?? '')))));
    foreach ($keys as $k) { $one = count($keys) === 1; // one video: the form's title / description; several: each its own library title and caption
        $title = $one && trim((string) ($in['title'] ?? '')) !== '' ? (string) $in['title'] : (string) (($meta[$k]['title'] ?? '') ?: pathinfo($k, PATHINFO_FILENAME));
        $desc = $one && isset($in['desc']) ? (string) $in['desc'] : (string) ($meta[$k]['caption'] ?? '');
        wi_job_add('youtube_upload', $aid . '|' . $k, ['aid' => $aid, 'key' => $k, 'title' => $title, 'desc' => $desc, 'tags' => $tags ?: (array) ($meta[$k]['tags'] ?? []), 'privacy' => $privacy, 'ws' => wf_google_ws()], (string) $_SESSION['email']);
        wi_update('media_meta.json', function ($M) use ($aid, $k) { $M[$aid][$k]['youtube'] = ['state' => 'queued', 'at' => date('c')]; return $M; }); }
    wi_log('youtube_queue', ['account' => $aid, 'videos' => count($keys), 'privacy' => $privacy]);
    wi_json(['ok' => true, 'queued' => count($keys)]);
}
/** Duplicates: the same file saved more than once (same bytes). One copy is kept — a healthy one (opens, not empty), preferring a
 *  favourite, the most sent, one with a caption / title / folder, then the oldest — and the others go to the recycle bin (they can be put back).
 *  The kept copy takes over the others' tags, send count and caption, so nothing written on them is lost. */
if ($api === 'media_dupes') {
    $acc = wi_current(); if (!$acc) wi_json(['ok' => false, 'error' => 'No number.']); $aid = (string) $acc['id']; $root = dirname(__DIR__, 3) . '/storage/';
    $meta = (array) (wi_read('media_meta.json', [])[$aid] ?? []); $files = [];
    foreach (glob($root . 'inbox_media/' . $aid . '/*') ?: [] as $p) { $f = basename($p); if (preg_match('/^[A-Za-z0-9_.-]{1,80}$/', $f) && !str_ends_with($f, '.part')) $files['inbox_media/' . $aid . '/' . $f] = $p; }
    foreach ((array) wi_read('uploads.json', []) as $f => $a) if ($a === $aid && is_file($root . 'inbox_uploads/' . $f)) $files['inbox_uploads/' . $f] = $root . 'inbox_uploads/' . $f;
    $bySize = []; foreach ($files as $k => $p) { if (!empty($meta[$k]['hidden'])) continue; $s = (int) @filesize($p); if ($s > 0) $bySize[$s][] = $k; }
    // only files of the same size are read (hashes are remembered by size + date, so the next look is instant)
    $H = (array) (wi_read('media_hash.json', [])[$aid] ?? []); $newH = []; $groups = [];
    foreach ($bySize as $s => $ks) { if (count($ks) < 2) continue;
        foreach ($ks as $k) { $sig = $s . '|' . (int) @filemtime($files[$k]); $h = ($H[$k]['sig'] ?? '') === $sig ? (string) $H[$k]['h'] : (string) @md5_file($files[$k]);
            if ($h === '') continue; $newH[$k] = ['sig' => $sig, 'h' => $h]; $groups[$h][] = $k; } }
    if ($newH) wi_update('media_hash.json', function ($X) use ($aid, $newH) { $X[$aid] = $newH + (array) ($X[$aid] ?? []); return $X; });
    $healthy = function (string $k) use ($files): bool { $p = $files[$k]; if (!is_readable($p) || filesize($p) < 1) return false;
        if (preg_match('/\.(jpe?g|png|webp)$/i', $k)) return @getimagesize($p) !== false; $fh = @fopen($p, 'rb'); $ok = $fh && fread($fh, 16) !== ''; if ($fh) fclose($fh); return $ok; };
    $out = []; $waste = 0;
    foreach ($groups as $h => $ks) { if (count($ks) < 2) continue;
        $score = function ($k) use ($meta, $files, $healthy) { $m = (array) ($meta[$k] ?? []); return [$healthy($k) ? 1 : 0, !empty($m['fav']) ? 1 : 0, (int) ($m['used'] ?? 0),
            (trim((string) ($m['caption'] ?? '')) !== '' ? 1 : 0) + (trim((string) ($m['title'] ?? '')) !== '' ? 1 : 0) + (trim((string) ($m['folder'] ?? '')) !== '' ? 1 : 0), -(int) @filemtime($files[$k])]; };
        usort($ks, fn($a, $b) => $score($b) <=> $score($a));
        if (!$healthy($ks[0])) continue; // no copy opens: nothing is removed
        $size = (int) filesize($files[$ks[0]]); $waste += $size * (count($ks) - 1);
        $out[] = ['keep' => $ks[0], 'remove' => array_slice($ks, 1), 'size' => $size]; }
    usort($out, fn($a, $b) => $b['size'] * count($b['remove']) <=> $a['size'] * count($a['remove']));
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') wi_json(['ok' => true, 'groups' => $out, 'copies' => array_sum(array_map(fn($g) => count($g['remove']), $out)), 'waste' => $waste]);
    wi_check_csrf(); if ((wi_me()['role'] ?? '') === 'agent') wi_json(['ok' => false, 'error' => 'Not allowed.'], 403);
    wi_update('media_meta.json', function ($M) use ($aid, $out) { foreach ($out as $g) { $keep = (array) ($M[$aid][$g['keep']] ?? []);
        foreach ($g['remove'] as $k) { $x = (array) ($M[$aid][$k] ?? []);
            $keep['tags'] = array_slice(array_values(array_unique(array_merge((array) ($keep['tags'] ?? []), (array) ($x['tags'] ?? [])))), 0, 12);
            $keep['used'] = (int) ($keep['used'] ?? 0) + (int) ($x['used'] ?? 0); if (!empty($x['fav'])) $keep['fav'] = true;
            foreach (['caption', 'title', 'folder'] as $f) if (trim((string) ($keep[$f] ?? '')) === '' && trim((string) ($x[$f] ?? '')) !== '') $keep[$f] = $x[$f];
            $x['hidden'] = true; $x['dupe_of'] = $g['keep']; $M[$aid][$k] = $x; }
        if (empty($keep['tags'])) unset($keep['tags']); $M[$aid][$g['keep']] = $keep; } return $M; });
    $n = array_sum(array_map(fn($g) => count($g['remove']), $out));
    wi_log('media_dupes', ['account' => $aid, 'groups' => count($out), 'removed' => $n, 'bytes' => $waste]); wi_json(['ok' => true, 'removed' => $n, 'groups' => count($out), 'waste' => $waste]);
}
