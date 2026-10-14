<?php
// WhatsApp Inbox — drive (split out of features.php in v3.8, code unchanged)

/* ---------- Google Drive (OAuth, drive.file scope) ---------- */
/** Whose Google account is used: '' = the operator's (Settings → Google); a client's email = that client's own (My account → Google).
 *  A client and their team always use the client's; background jobs set it from the number they work on (wf_google_use). */
function wf_google_ws(): string {
    if (array_key_exists('WI_GOOGLE_WS', $GLOBALS)) return (string) $GLOBALS['WI_GOOGLE_WS'];
    if (!wi_logged_in()) return ''; $t = wi_tenant(); if ($t) return strtolower((string) $t['email']);
    $u = wi_me(); if (!$u) return '-'; // signed in but unknown: no Google account at all, never the operator's
    if (wi_is_team($u)) return strtolower((string) ($u['tenant'] ?? '')) ?: '-'; // a team user of a client never falls back to the operator's Google
    return ($u['role'] ?? '') !== 'super' && wi_owned_accounts((string) $u['email']) ? strtolower((string) $u['email']) : ''; // an owner of a private number uses their own Google
}
function wf_google_use(?string $ws): void { if ($ws === null) unset($GLOBALS['WI_GOOGLE_WS']); else $GLOBALS['WI_GOOGLE_WS'] = $ws; }
const WF_GOOGLE_SCOPES = 'https://www.googleapis.com/auth/drive.file https://www.googleapis.com/auth/drive.readonly https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/meetings.space.created https://www.googleapis.com/auth/youtube.upload';
function wf_drive_cfg(): array {
    $ws = wf_google_ws();
    if ($ws !== '') { // a client's own Google account, signed in through the installation's Google app
        $c = (array) (wi_read('drive_ws.json', [])[$ws] ?? []); $op = wi_read('drive.json', []);
        $c['client_id'] = (string) ($op['client_id'] ?? ''); $c['client_secret'] = !empty($op['client_secret']) ? wi_secret('google_client_secret', (string) $op['client_secret']) : '';
        $c['refresh_token'] = !empty($c['refresh_token']) ? wi_secret('google_refresh_token:' . $ws) : '';
        return $c;
    }
    // The Google client secret and refresh token are read from the encrypted secrets table (the file keeps its old copy; empty in the file = not connected).
    $c = wi_read('drive.json', []);
    foreach (['client_secret' => 'google_client_secret', 'refresh_token' => 'google_refresh_token'] as $k => $name) if (!empty($c[$k])) $c[$k] = wi_secret($name, (string) $c[$k]);
    return $c;
}
/** Saves the Google settings of whoever's account is in use (the refresh token of a client goes to the encrypted secrets only). */
function wf_drive_save(array $c): void {
    $ws = wf_google_ws(); if ($ws === '') { wi_write('drive.json', $c); return; }
    unset($c['client_id'], $c['client_secret']);
    if (!empty($c['refresh_token'])) { wi_secret_set('google_refresh_token:' . $ws, (string) $c['refresh_token']); $c['refresh_token'] = 'saved'; }
    wi_update('drive_ws.json', function ($A) use ($ws, $c) { $keep = $A[$ws]['backup'] ?? null; unset($c['backup']); $A[$ws] = $c + ($keep !== null ? ['backup' => $keep] : []); return $A; }); // the backup settings are saved on their own
}
function wf_drive_redirect(): string { return wi_sys('base_url') . '?drive_cb=1'; }
function wf_drive_token(): array {
    $c = wf_drive_cfg();
    if (empty($c['refresh_token']) || empty($c['client_id'])) return ['ok' => false, 'error' => 'Google Drive is not connected.'];
    if (!empty($c['access_token']) && ($c['expires'] ?? 0) > time() + 60) return ['ok' => true, 'token' => $c['access_token']];
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_POSTFIELDS => http_build_query([
        'client_id' => $c['client_id'], 'client_secret' => $c['client_secret'], 'refresh_token' => $c['refresh_token'], 'grant_type' => 'refresh_token'])]);
    $r = json_decode((string) curl_exec($ch), true); curl_close($ch);
    if (empty($r['access_token'])) return ['ok' => false, 'error' => 'Google refused the saved connection: ' . ($r['error_description'] ?? $r['error'] ?? 'unknown') . '. Connect Google Drive again.'];
    $c['access_token'] = $r['access_token']; $c['expires'] = time() + (int) ($r['expires_in'] ?? 3000);
    wf_drive_save($c);
    return ['ok' => true, 'token' => $r['access_token']];
}
function wf_drive_req(string $method, string $url, string $token, ?string $body = null, array $headers = [], bool $wantHeaders = false): array {
    $ch = curl_init($url); $hdrs = [];
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 600,
        CURLOPT_HTTPHEADER => array_merge(['Authorization: Bearer ' . $token], $headers),
        CURLOPT_HEADERFUNCTION => function ($c, $l) use (&$hdrs) { $p = explode(':', $l, 2); if (count($p) === 2) $hdrs[strtolower(trim($p[0]))] = trim($p[1]); return strlen($l); }]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $res = (string) curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ['code' => $code, 'body' => json_decode($res, true) ?: $res, 'headers' => $hdrs];
}
/** Folder "WhatsApp Inbox Backups" in the connected Drive (created once). */
function wf_drive_folder(string $token): string {
    $c = wf_drive_cfg();
    if (!empty($c['folder_id'])) return (string) $c['folder_id'];
    $r = wf_drive_req('POST', 'https://www.googleapis.com/drive/v3/files', $token, json_encode(['name' => (string) wi_sys('backup_folder'), 'mimeType' => 'application/vnd.google-apps.folder']), ['Content-Type: application/json']);
    $id = (string) ($r['body']['id'] ?? '');
    if ($id !== '') { $c['folder_id'] = $id; wf_drive_save($c); }
    return $id;
}
/** Resumable upload in 8 MB pieces. */
function wf_drive_upload(string $file, string $name): array {
    $t = wf_drive_token(); if (!$t['ok']) return $t;
    $folder = wf_drive_folder($t['token']);
    if ($folder === '') return ['ok' => false, 'error' => 'Could not create the backup folder in Google Drive.'];
    $size = filesize($file);
    $init = wf_drive_req('POST', 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable', $t['token'], json_encode(['name' => $name, 'parents' => [$folder]]),
        ['Content-Type: application/json; charset=UTF-8', 'X-Upload-Content-Type: application/gzip', 'X-Upload-Content-Length: ' . $size]);
    $loc = $init['headers']['location'] ?? '';
    if ($loc === '') return ['ok' => false, 'error' => 'Drive did not accept the upload (HTTP ' . $init['code'] . ').'];
    $fh = fopen($file, 'rb'); $off = 0; $chunk = 8 * 1048576; $res = null;
    while ($off < $size) {
        $data = fread($fh, $chunk); $len = strlen($data);
        $res = wf_drive_req('PUT', $loc, $t['token'], $data, ['Content-Length: ' . $len, 'Content-Range: bytes ' . $off . '-' . ($off + $len - 1) . '/' . $size]);
        if (!in_array($res['code'], [200, 201, 308], true)) { fclose($fh); return ['ok' => false, 'error' => 'Upload stopped (HTTP ' . $res['code'] . ').']; }
        $off += $len;
    }
    fclose($fh);
    return ['ok' => true, 'id' => $res['body']['id'] ?? '', 'size' => $size];
}

/** API calls of this area (moved unchanged from the old single switch). */
function wf_api_drive(string $api): void {
    switch ($api) {
    /* ----- A client's own Google account (My account → Google): learning imports and Meet links of their panel ----- */
    case 'my_google': {
        $me = wi_me(); if (!$me || ($me['role'] ?? '') === 'super' || !wi_owned_accounts((string) $me['email']) || !empty($_SESSION['imp_from'])) wi_json(['ok' => false, 'error' => 'Only the panel owner connects its Google account.'], 403);
        wf_google_use(strtolower((string) $me['email'])); $c = wf_drive_cfg();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') { $in = wf_post();
            if (($in['do'] ?? '') === 'connect') {
                if (empty($c['client_id']) || empty($c['client_secret'])) wi_json(['ok' => false, 'error' => 'Google sign-in is not set up on this installation yet. Ask the administrator.']);
                $state = bin2hex(random_bytes(16)); $ws = wf_google_ws();
                wi_update('google_states.json', function ($A) use ($state, $ws) { $A[$state] = ['ws' => $ws, 'at' => time()]; return $A; });
                wi_json(['ok' => true, 'url' => 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query(['client_id' => $c['client_id'], 'redirect_uri' => wf_drive_redirect(), 'response_type' => 'code', 'scope' => WF_GOOGLE_SCOPES, 'access_type' => 'offline', 'prompt' => 'consent', 'state' => $state])]);
            }
            if (($in['do'] ?? '') === 'disconnect') { $ws = wf_google_ws(); wi_secret_set('google_refresh_token:' . $ws, ''); wi_update('drive_ws.json', function ($A) use ($ws) { unset($A[$ws]); return $A; }); wi_log('google_disconnect', []); wi_json(['ok' => true]); }
        }
        wi_json(['ok' => true, 'ready' => !empty($c['client_id']), 'connected' => !empty($c['refresh_token']), 'email' => (string) ($c['email'] ?? ''), 'missing' => $c['missing'] ?? [], 'at' => $c['connected_at'] ?? null]);
    }
    /* ----- 13) Google Drive backup ----- */
    case 'backup_cfg': {
        if (!wi_is_super()) wi_json(['ok' => false, 'error' => 'Super admin only.'], 403); // the backup copies every number's data
        $c = wf_drive_cfg();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $in = wf_post();
            if (($in['do'] ?? '') === 'connect') {
                $id = trim((string) ($in['client_id'] ?? '')); $sec = trim((string) ($in['client_secret'] ?? ''));
                if ($id !== '') $c['client_id'] = $id; if ($sec !== '') { $c['client_secret'] = $sec; wi_secret_set('google_client_secret', $sec); }
                if (empty($c['client_id']) || empty($c['client_secret'])) wi_json(['ok' => false, 'error' => 'Enter the OAuth Client ID and Client secret.']);
                $c['state'] = bin2hex(random_bytes(16)); $c['state_at'] = time(); wi_write('drive.json', $c);
                wi_json(['ok' => true, 'url' => 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query(['client_id' => $c['client_id'], 'redirect_uri' => wf_drive_redirect(), 'response_type' => 'code', 'scope' => 'https://www.googleapis.com/auth/drive.file https://www.googleapis.com/auth/drive.readonly https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/meetings.space.created https://www.googleapis.com/auth/youtube.upload', 'access_type' => 'offline', 'prompt' => 'consent', 'state' => $c['state']])]);
            }
            if (($in['do'] ?? '') === 'disconnect') { unset($c['refresh_token'], $c['access_token'], $c['expires'], $c['folder_id'], $c['email']); wi_write('drive.json', $c); }
            if (($in['do'] ?? '') === 'types') { $c['types'] = array_values(array_intersect(array_keys(WF_BACKUP_TYPES), (array) ($in['types'] ?? []))) ?: ['data']; wi_write('drive.json', $c); }
            if (($in['do'] ?? '') === 'restore_list') { $l = wf_restore_list((string) ($in['source'] ?? 'current')); $o = []; foreach ($l as $rel => $x) $o[] = ['path' => $rel, 'size' => $x['size'], 'exists' => ($lp = wf_backup_local_path($rel)) ? is_file($lp) : false]; wi_json(['ok' => true, 'files' => $o]); }
            if (($in['do'] ?? '') === 'restore') {
                // Super admin only: it changes data on the server.
                if (!wi_is_super()) wi_json(['ok' => false, 'error' => 'Only the super admin can restore.'], 403);
                $job = ['source' => (string) ($in['source'] ?? 'current'), 'types' => array_values(array_intersect(array_keys(WF_BACKUP_TYPES), (array) ($in['types'] ?? []))), 'files' => array_values(array_map('strval', (array) ($in['files'] ?? []))), 'mode' => ($in['mode'] ?? '') === 'overwrite' ? 'overwrite' : 'missing'];
                if (!$job['types'] && !$job['files']) wi_json(['ok' => false, 'error' => 'Choose what to restore.']);
                wi_job_add('restore', 'drive', $job, (string) $_SESSION['email']); wi_log('backup_restore', $job + ['files' => count($job['files'])]);
                wi_json(['ok' => true]);
            }
            if (($in['do'] ?? '') === 'package') {
                if (!wi_is_super()) wi_json(['ok' => false, 'error' => 'Only the super admin can make packages.'], 403);
                $k = (string) ($in['kind'] ?? ''); if (!isset(WF_SNAP_KINDS[$k])) wi_json(['ok' => false, 'error' => 'Unknown package.']);
                wi_job_add('package', $k, ['kind' => $k], (string) $_SESSION['email']); wi_log('backup_package', ['kind' => $k]); wi_json(['ok' => true]);
            }
            if (($in['do'] ?? '') === 'delete_package') {
                if (!wi_is_super()) wi_json(['ok' => false, 'error' => 'Only the super admin can delete packages.'], 403);
                $pid = (string) ($in['id'] ?? ''); $t = wf_drive_token(); if (!$t['ok']) wi_json($t);
                $r = wf_drive_req('PATCH', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($pid), $t['token'], json_encode(['trashed' => true]), ['Content-Type: application/json']);
                if (!in_array($r['code'], [200, 404], true)) wi_json(['ok' => false, 'error' => 'Google Drive refused (HTTP ' . $r['code'] . ').']);
                $c = wf_drive_cfg(); $c['packages'] = array_values(array_filter($c['packages'] ?? [], fn($x) => $x['id'] !== $pid)); wi_write('drive.json', $c); wi_log('backup_package_delete', ['id' => $pid]);
            }
            if (($in['do'] ?? '') === 'delete_archive') {
                // An archive goes to the Drive trash (Google keeps it there 30 days), then leaves the list.
                $aid = (string) ($in['id'] ?? ''); $t = wf_drive_token();
                if (!$t['ok']) wi_json($t);
                $r = wf_drive_req('PATCH', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($aid), $t['token'], json_encode(['trashed' => true]), ['Content-Type: application/json']);
                if (!in_array($r['code'], [200, 404], true)) wi_json(['ok' => false, 'error' => 'Google Drive refused (HTTP ' . $r['code'] . ').']);
                $c = wf_drive_cfg(); $c['archives'] = array_values(array_filter($c['archives'] ?? [], fn($x) => $x['id'] !== $aid)); wi_write('drive.json', $c); wi_log('backup_archive_delete', ['id' => $aid]);
            }
            if (($in['do'] ?? '') === 'settings') { $c['enabled'] = !empty($in['enabled']); $c['hour'] = max(0, min(23, (int) ($in['hour'] ?? 3))); $c['media'] = !empty($in['media']); wi_write('drive.json', $c); }
            if (($in['do'] ?? '') === 'run') { $types = array_values(array_intersect(array_keys(WF_BACKUP_TYPES), (array) ($in['types'] ?? ($c['types'] ?? ['data', 'messages'])))) ?: ['data'];
                $c['run_now'] = date('c'); wi_write('drive.json', $c); wi_job_add('backup', 'drive', ['types' => $types], (string) $_SESSION['email']); wi_log('backup_run', ['types' => $types]); }
        }
        $c = wf_drive_cfg(); $man = wf_backup_manifest(); $byType = [];
        foreach ($man['files'] ?? [] as $rel => $x) { $tp = explode('/', $rel)[0]; $byType[$tp]['files'] = ($byType[$tp]['files'] ?? 0) + 1; $byType[$tp]['bytes'] = ($byType[$tp]['bytes'] ?? 0) + (int) ($x['size'] ?? 0); }
        $jobs = array_merge(wi_jobs_recent('backup', 8), wi_jobs_recent('restore', 4), wi_jobs_recent('package', 6)); usort($jobs, fn($a, $b) => $b['id'] <=> $a['id']); $active = array_values(array_filter($jobs, fn($x) => in_array($x['status'], ['queued', 'running'], true)));
        wi_json(['types_all' => WF_BACKUP_TYPES, 'types' => $c['types'] ?? ['data', 'messages'], 'archives' => $c['archives'] ?? [], 'last_restore' => $c['last_restore'] ?? null, 'packages' => $c['packages'] ?? [], 'package_kinds' => WF_SNAP_KINDS, 'can_restore' => wi_is_super(), 'progress' => $c['progress'] ?? null, 'on_drive' => (object) $byType, 'jobs' => $jobs, 'busy' => (bool) $active, 'ok' => true, 'connected' => !empty($c['refresh_token']), 'email' => $c['email'] ?? '', 'client_id' => $c['client_id'] ?? '', 'has_secret' => !empty($c['client_secret']), 'redirect' => wf_drive_redirect(),
            'enabled' => !empty($c['enabled']), 'hour' => $c['hour'] ?? 3, 'media' => !empty($c['media']), 'run_now' => $c['run_now'] ?? null, 'running' => $c['running'] ?? null, 'last' => $c['last'] ?? null, 'history' => $c['history'] ?? []]);
    }
    }
}


/** OAuth return from Google (top-level redirect, so it is checked by a one-time state, not the session). */
function wf_drive_callback(): void {
    // A client's sign-in: the one-time state says whose account it is (the session cookie does not come back from Google).
    $st = (string) ($_GET['state'] ?? ''); $S = wi_read('google_states.json', []); $mine = $st !== '' && isset($S[$st]) && time() - (int) $S[$st]['at'] < 900;
    wf_google_use($mine ? (string) $S[$st]['ws'] : ''); // whose account: from the one-time state only, never from whoever is signed in
    if ($mine) { wi_update('google_states.json', function ($A) use ($st) { unset($A[$st]); foreach ($A as $k => $x) if (time() - (int) $x['at'] > 900) unset($A[$k]); return $A; }); }
    $c = wf_drive_cfg();
    $ok = $mine || (!empty($c['state']) && hash_equals((string) $c['state'], $st) && time() - (int) ($c['state_at'] ?? 0) < 900);
    $msg = 'Google Drive: connection failed.';
    if ($ok && !empty($_GET['code'])) {
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_POSTFIELDS => http_build_query([
            'code' => (string) $_GET['code'], 'client_id' => $c['client_id'], 'client_secret' => $c['client_secret'], 'redirect_uri' => wf_drive_redirect(), 'grant_type' => 'authorization_code'])]);
        $r = json_decode((string) curl_exec($ch), true); curl_close($ch);
        if (!empty($r['refresh_token'])) {
            $c['refresh_token'] = $r['refresh_token']; if (wf_google_ws() === '') wi_secret_set('google_refresh_token', (string) $r['refresh_token']); // a client's token is saved under its own name (wf_drive_save), never over the operator's $c['access_token'] = $r['access_token'] ?? ''; $c['expires'] = time() + (int) ($r['expires_in'] ?? 3000);
            $u = wf_drive_req('GET', 'https://www.googleapis.com/drive/v3/about?fields=user', (string) $c['access_token']);
            $c['email'] = (string) ($u['body']['user']['emailAddress'] ?? '');
            $c['scopes'] = (string) ($r['scope'] ?? '');
            // what was asked for but not granted (Google drops scopes that are not on the project's Data Access list, or that the person unticked)
            $want = ['drive.readonly' => 'Read Drive files (learning)', 'meetings.space.created' => 'Meet recording', 'youtube.upload' => 'YouTube upload', 'calendar.events' => 'Calendar (Meet links)', 'drive.file' => 'Google Drive'];
            $c['missing'] = array_values(array_filter(array_keys($want), fn($k) => strpos($c['scopes'], $k) === false)); $c['connected_at'] = date('c');
            $msg = 'Google Drive connected.';
        } else $msg = 'Google Drive: ' . ($r['error_description'] ?? $r['error'] ?? 'no refresh token returned');
    }
    unset($c['state'], $c['state_at']);
    wf_drive_save($c);
    if ($mine) { wi_log('google_connect', ['ws' => wf_google_ws(), 'ok' => !empty($c['refresh_token'])]); header('Location: ?p=me&drive=' . rawurlencode(str_replace('Google Drive', 'Google', $msg))); exit; }
    header('Location: ?p=settings&drive=' . rawurlencode($msg)); exit;
}

/* ---------- YouTube: upload a video of the media library to the channel of the connected Google account ---------- */
/** Resumable upload in 8 MB pieces. Returns ['ok', 'id'] or ['ok' => false, 'error']. */
function wf_youtube_upload(string $token, string $file, string $title, string $desc, array $tags, string $privacy): array {
    $meta = ['snippet' => ['title' => mb_substr($title !== '' ? $title : basename($file), 0, 100), 'description' => mb_substr($desc, 0, 4900), 'tags' => array_slice($tags, 0, 30), 'categoryId' => '22'],
        'status' => ['privacyStatus' => in_array($privacy, ['private', 'unlisted', 'public'], true) ? $privacy : 'private', 'selfDeclaredMadeForKids' => false]];
    $size = filesize($file);
    $init = wf_drive_req('POST', 'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status', $token, json_encode($meta, JSON_UNESCAPED_UNICODE),
        ['Content-Type: application/json; charset=UTF-8', 'X-Upload-Content-Length: ' . $size, 'X-Upload-Content-Type: video/*'], true);
    $loc = $init['headers']['location'] ?? '';
    if ($loc === '') { $b = $init['body']; $msg = is_array($b) ? (string) ($b['error']['errors'][0]['reason'] ?? $b['error']['message'] ?? '') : '';
        return ['ok' => false, 'error' => $msg === 'quotaExceeded' ? 'YouTube daily upload limit reached — try again tomorrow.' : ($msg === 'insufficientPermissions' || $init['code'] === 403 ? 'Google is connected without YouTube permission (or the YouTube Data API is not enabled). Reconnect Google.' : 'YouTube refused (HTTP ' . $init['code'] . ($msg ? ': ' . $msg : '') . ')')]; }
    $fh = fopen($file, 'rb'); $off = 0; $res = null;
    while ($off < $size) { $data = fread($fh, 8 * 1048576); $len = strlen($data);
        $res = wf_drive_req('PUT', $loc, $token, $data, ['Content-Length: ' . $len, 'Content-Range: bytes ' . $off . '-' . ($off + $len - 1) . '/' . $size]);
        if (!in_array($res['code'], [200, 201, 308], true)) { fclose($fh); return ['ok' => false, 'error' => 'Upload stopped (HTTP ' . $res['code'] . ')']; }
        $off += $len; }
    fclose($fh);
    $id = (string) ($res['body']['id'] ?? ''); return $id !== '' ? ['ok' => true, 'id' => $id, 'privacy' => (string) ($res['body']['status']['privacyStatus'] ?? $meta['status']['privacyStatus'])] : ['ok' => false, 'error' => 'YouTube gave no video id.'];
}
/** Background job: one media item → YouTube; the result is kept on the item (media_meta.json …['youtube']). */
function wf_youtube_job(array $p): array {
    $aid = (string) $p['aid']; $key = (string) $p['key']; $set = function (array $y) use ($aid, $key) { wi_update('media_meta.json', function ($M) use ($aid, $key, $y) { $M[$aid][$key]['youtube'] = $y + ['at' => date('c')]; return $M; }); };
    wf_google_use((string) ($p['ws'] ?? ''));
    try {
        $file = dirname(__DIR__, 2) . '/storage/' . $key; if (!is_file($file)) { $set(['state' => 'failed', 'error' => 'File not found.']); return ['ok' => false]; }
        $t = wf_drive_token(); if (empty($t['ok'])) { $set(['state' => 'failed', 'error' => (string) ($t['error'] ?? 'Google is not connected.')]); return ['ok' => false]; }
        $set(['state' => 'uploading']);
        $r = wf_youtube_upload($t['token'], $file, (string) ($p['title'] ?? ''), (string) ($p['desc'] ?? ''), (array) ($p['tags'] ?? []), (string) ($p['privacy'] ?? 'private'));
        $set($r['ok'] ? ['state' => 'done', 'id' => $r['id'], 'url' => 'https://youtu.be/' . $r['id'], 'privacy' => $r['privacy']] : ['state' => 'failed', 'error' => $r['error']]);
        wi_log('youtube_upload', ['account' => $aid, 'key' => $key, 'ok' => $r['ok']]); return $r;
    } finally { wf_google_use(null); }
}
