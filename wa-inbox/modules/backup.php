<?php
// WhatsApp Inbox — incremental Google Drive backup (v3.8).
// Drive layout: "WhatsApp Inbox Backups" / "Current" (one Drive file per local file, updated only when it changed)
//               "WhatsApp Inbox Backups" / "Archive <date>" (copies of files that were deleted on the server, moved out of Current)
// The local manifest (.data/backup_manifest.json) remembers each file's Drive id and checksum, so a run uploads only what changed
// and a stopped run continues where it left off.

const WF_BACKUP_TYPES = [
    'data'     => ['Data & settings', 'Inbox data, cases, users and permissions (MySQL tables), bot knowledge'],
    'messages' => ['Message archive', 'Every number\'s message history and contact names'],
    'media'    => ['Media files', 'Photos, voice notes, videos and documents (large)'],
];
function wf_backup_root(): string { return dirname(__DIR__, 2); }
/** Local files of the chosen types: rel path => absolute path. */
function wf_backup_files(array $types): array {
    $root = wf_backup_root(); $out = [];
    $walk = function (string $dir, string $prefix, ?callable $skip = null) use (&$out) {
        if (!is_dir($dir) || !is_readable($dir)) return;
        $it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            fn($f) => !$f->isDir() || is_readable($f->getPathname())), RecursiveIteratorIterator::LEAVES_ONLY, RecursiveIteratorIterator::CATCH_GET_CHILD);
        foreach ($it as $f) { if (!$f->isFile() || !$f->isReadable()) continue; $rel = substr($f->getPathname(), strlen($dir) + 1); if ($skip && $skip($rel)) continue; $out[$prefix . '/' . $rel] = $f->getPathname(); }
    };
    if (in_array('data', $types, true)) {
        $walk(WI_DIR, 'data', fn($r) => (bool) preg_match('#(^drive\.json$|^app\.key$|^ai\.key$|^db\.php$|\.lock$|\.tmp\.|^cache_|^backup_manifest|^js_errors|^sess_)#', $r));
        // MySQL tables as JSON snapshots (users, permissions, cases, reports, history, audit)
        $snap = WI_DIR . '/db_snapshot'; if (!is_dir($snap)) { @mkdir($snap, 0700, true); @chmod($snap, 0700); }
        // Data sets kept in the database (wi_kv): one JSON file each, so the backup always has the current data.
        if (wi_kv_on() && ($m = wi_db())) { $kd = $snap . '/kv'; if (!is_dir($kd)) { @mkdir($kd, 0700, true); @chmod($kd, 0700); }
            $xIds = array_flip(wi_backup_excluded());
            $r = $m->query("SELECT name, data FROM wi_kv"); while ($r && ($x = $r->fetch_assoc())) { $f = $kd . '/' . basename((string) $x['name']);
                if ($xIds && function_exists('wf_client_usage') && in_array((string) $x['name'], WF_CB_PER_NUMBER, true) && is_array($dj = json_decode((string) $x['data'], true))) $x['data'] = json_encode(array_diff_key($dj, $xIds), JSON_UNESCAPED_UNICODE); // their per-number data stays out
                if (!is_file($f) || md5_file($f) !== md5((string) $x['data'])) { file_put_contents($f, (string) $x['data']); @chmod($f, 0600); } } }
        if ($m = wi_db()) foreach (['wi_users', 'wi_grants', 'wi_cases', 'wi_case_reports', 'wi_case_events', 'wi_audit', 'wi_bot_log', 'wi_ai_tasks'] as $t) {
            $r = $m->query("SELECT * FROM $t"); if (!$r) continue; $rows = []; while ($x = $r->fetch_assoc()) $rows[] = $x;
            $f = $snap . '/' . $t . '.json'; $js = json_encode($rows, JSON_UNESCAPED_UNICODE);
            if (!is_file($f) || md5_file($f) !== md5($js)) { file_put_contents($f, $js); @chmod($f, 0600); }
        }
    }
    if (in_array('messages', $types, true)) {
        $wl = $root . '/wa-local';
        $walk($wl . '/archive', 'messages/main/archive');
        foreach (['names.json', 'broadcasts.json', 'media-hashes.json'] as $f) if (is_readable($wl . '/' . $f)) $out['messages/main/' . $f] = $wl . '/' . $f;
        foreach (glob($wl . '/accounts/*', GLOB_ONLYDIR) ?: [] as $ad) { $id = basename($ad);
            $walk($ad . '/archive', 'messages/' . $id . '/archive');
            foreach (['names.json', 'media-hashes.json'] as $f) if (is_readable($ad . '/' . $f)) $out['messages/' . $id . '/' . $f] = $ad . '/' . $f; }
        if (is_readable($wl . '/accounts.json')) $out['messages/accounts.json'] = $wl . '/accounts.json';
    }
    if (in_array('media', $types, true)) { $walk($root . '/storage/inbox_media', 'media/inbox'); $walk($root . '/wa-local/photos', 'media/photos'); }
    // private numbers of other owners never go into the operator's backup
    foreach (wi_backup_excluded() as $xid) foreach (array_keys($out) as $k) if (str_starts_with($k, 'messages/' . $xid . '/') || str_starts_with($k, 'media/inbox/' . $xid . '/')) unset($out[$k]);
    ksort($out);
    return $out;
}
function wf_backup_manifest(): array { return wi_read('backup_manifest.json', ['files' => []]); }
function wf_backup_progress(array $p): void { $c = wf_drive_cfg(); $c['progress'] = $p + ['at' => date('c')]; wi_write('drive.json', $c); }
function wf_drive_child_folder(string $token, string $parent, string $name): string {
    $r = wf_drive_req('POST', 'https://www.googleapis.com/drive/v3/files', $token, json_encode(['name' => $name, 'mimeType' => 'application/vnd.google-apps.folder', 'parents' => [$parent]]), ['Content-Type: application/json']);
    return (string) ($r['body']['id'] ?? '');
}
/** Uploads a new file (or new content for an existing Drive file) in 8 MB pieces. */
function wf_drive_put(string $token, string $file, string $name, string $parent, ?string $existingId): array {
    $size = filesize($file);
    $url = $existingId ? 'https://www.googleapis.com/upload/drive/v3/files/' . rawurlencode($existingId) . '?uploadType=resumable' : 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable';
    $meta = $existingId ? [] : ['name' => $name, 'parents' => [$parent]];
    $init = wf_drive_req($existingId ? 'PATCH' : 'POST', $url, $token, json_encode((object) $meta), ['Content-Type: application/json; charset=UTF-8', 'X-Upload-Content-Length: ' . $size]);
    if ($existingId && $init['code'] === 404) return wf_drive_put($token, $file, $name, $parent, null); // removed on Drive by hand: upload again
    $loc = $init['headers']['location'] ?? ''; if ($loc === '') return ['ok' => false, 'error' => 'HTTP ' . $init['code']];
    if ($size === 0) { $res = wf_drive_req('PUT', $loc, $token, '', ['Content-Length: 0']); return ['ok' => in_array($res['code'], [200, 201], true), 'id' => $res['body']['id'] ?? $existingId]; }
    $fh = fopen($file, 'rb'); $off = 0; $res = null;
    while ($off < $size) { $data = fread($fh, 8 * 1048576); $len = strlen($data);
        $res = wf_drive_req('PUT', $loc, $token, $data, ['Content-Length: ' . $len, 'Content-Range: bytes ' . $off . '-' . ($off + $len - 1) . '/' . $size]);
        if (!in_array($res['code'], [200, 201, 308], true)) { fclose($fh); return ['ok' => false, 'error' => 'HTTP ' . $res['code']]; }
        $off += $len; }
    fclose($fh);
    return ['ok' => true, 'id' => (string) ($res['body']['id'] ?? $existingId)];
}
/** One incremental run. Returns a summary; progress is written to drive.json as it goes. */
function wf_backup_incremental(array $types, int $maxSeconds = 2700): array {
    $t0 = time(); $t = wf_drive_token(); if (!$t['ok']) return $t; $tok = $t['token'];
    $root = wf_drive_folder($tok); if ($root === '') return ['ok' => false, 'error' => 'Could not create the backup folder in Google Drive.'];
    $c = wf_drive_cfg();
    // the Current folder was removed, or belongs to another Google account (reconnected): start a new one with a fresh list
    if (!empty($c['current_id'])) { $chk = wf_drive_req('GET', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode((string) $c['current_id']) . '?fields=id,trashed', $tok);
        if ($chk['code'] === 404 || !empty($chk['body']['trashed'])) { unset($c['current_id']); wi_write('drive.json', $c); wi_read('backup_manifest.json', []); wi_write('backup_manifest.json', ['files' => []]); wi_log('backup_new_current', []); } }
    if (empty($c['current_id'])) { $c['current_id'] = wf_drive_child_folder($tok, $root, 'Current'); wi_write('drive.json', $c); }
    $cur = (string) $c['current_id']; if ($cur === '') return ['ok' => false, 'error' => 'Could not create the Current folder in Google Drive.'];
    $files = wf_backup_files($types); $man = wf_backup_manifest(); $mf = &$man['files'];
    $todo = []; $bytes = 0;
    foreach ($files as $rel => $abs) { $sz = (int) @filesize($abs); $mt = (int) @filemtime($abs); $old = $mf[$rel] ?? null;
        if ($old && $old['size'] === $sz && $old['mtime'] === $mt) continue;            // unchanged (fast check)
        $todo[$rel] = [$abs, $sz, $mt]; $bytes += $sz; }
    // Files of the chosen types that are gone from the server → moved on Drive into a new archive folder (nothing is lost).
    $gone = [];
    foreach ($mf as $rel => $x) { $type = explode('/', $rel)[0]; if (in_array($type, $types, true) && !isset($files[$rel])) $gone[$rel] = $x; }
    $sum = ['types' => $types, 'uploaded' => 0, 'unchanged' => count($files) - count($todo), 'archived' => 0, 'bytes' => 0, 'errors' => []];
    $p = ['running' => true, 'phase' => 'upload', 'done' => 0, 'total' => count($todo), 'bytes_done' => 0, 'bytes_total' => $bytes, 'current' => '', 'started' => date('c'), 'types' => $types];
    wf_backup_progress($p);
    foreach ($todo as $rel => [$abs, $sz, $mt]) {
        if (time() - $t0 > $maxSeconds) { $sum['partial'] = true; break; }                    // continues on the next run
        // A file still being written (e.g. this month's message archive) is copied first, so its size cannot change mid-upload.
        $tmp = null; if (time() - $mt < 900) { $tmp = sys_get_temp_dir() . '/wibk_' . md5($rel); if (@copy($abs, $tmp)) { $abs = $tmp; $sz = filesize($tmp); } else $tmp = null; }
        $md5 = md5_file($abs); $old = $mf[$rel] ?? null;
        if ($old && ($old['md5'] ?? '') === $md5) { if ($tmp) @unlink($tmp); $mf[$rel]['size'] = $sz; $mf[$rel]['mtime'] = $mt; $p['done']++; $p['bytes_done'] += $sz; continue; }
        $p['current'] = $rel; if ($p['done'] % 5 === 0) wf_backup_progress($p);
        $u = wf_drive_put($tok, $abs, str_replace('/', ' › ', $rel), $cur, $old['id'] ?? null);
        if ($tmp) @unlink($tmp);
        if ($u['ok']) { $mf[$rel] = ['id' => $u['id'], 'md5' => $md5, 'size' => $sz, 'mtime' => $mt, 'at' => date('c')]; $sum['uploaded']++; $sum['bytes'] += $sz; }
        else { $sum['errors'][] = $rel . ': ' . $u['error']; if (count($sum['errors']) > 20) break; }
        $p['done']++; $p['bytes_done'] += $sz;
        if ($p['done'] % 10 === 0) wi_write('backup_manifest.json', $man);
        $t = wf_drive_token(); if ($t['ok']) $tok = $t['token']; // refreshed by Google every hour
    }
    if ($gone && empty($sum['partial'])) {
        $p['phase'] = 'archive'; $p['current'] = count($gone) . ' deleted files'; wf_backup_progress($p);
        $name = 'Archive ' . date('Y-m-d H.i'); $aid = wf_drive_child_folder($tok, $root, $name); $moved = 0; $size = 0;
        if ($aid !== '') foreach ($gone as $rel => $x) {
            $r = wf_drive_req('PATCH', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($x['id']) . '?addParents=' . rawurlencode($aid) . '&removeParents=' . rawurlencode($cur), $tok, '{}', ['Content-Type: application/json']);
            if ($r['code'] === 200 || $r['code'] === 404) { unset($mf[$rel]); if ($r['code'] === 200) { $moved++; $size += (int) ($x['size'] ?? 0); } }
        }
        if ($moved) { $c = wf_drive_cfg(); $c['archives'] = $c['archives'] ?? []; array_unshift($c['archives'], ['id' => $aid, 'name' => $name, 'at' => date('c'), 'files' => $moved, 'size' => $size, 'paths' => array_slice(array_keys($gone), 0, 50)]); $c['archives'] = array_slice($c['archives'] ?? [], 0, 200); wi_write('drive.json', $c); }
        elseif ($aid !== '') wf_drive_req('DELETE', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($aid), $tok);
        $sum['archived'] = $moved;
    }
    wi_write('backup_manifest.json', $man);
    $sum['ok'] = !$sum['errors']; $sum['files_on_drive'] = count($mf);
    $sum['drive_bytes'] = array_sum(array_map(fn($x) => (int) ($x['size'] ?? 0), $mf));
    wf_backup_progress(['running' => false, 'phase' => 'done', 'done' => $p['done'], 'total' => $p['total'], 'bytes_done' => $p['bytes_done'], 'bytes_total' => $p['bytes_total'], 'current' => '']);
    return $sum;
}

/* ---------- Restore from Google Drive ---------- */
/** Where a backed-up path lives on the server (the reverse of wf_backup_files). */
function wf_backup_local_path(string $rel): ?string {
    $root = wf_backup_root(); $wl = $root . '/wa-local'; $p = explode('/', $rel);
    if (in_array('..', $p, true) || in_array('', $p, true)) return null;
    $rest = fn($n) => implode('/', array_slice($p, $n));
    if ($p[0] === 'data' && count($p) > 1) return WI_DIR . '/' . $rest(1);
    if ($p[0] === 'media' && ($p[1] ?? '') === 'inbox' && count($p) > 2) return $root . '/storage/inbox_media/' . $rest(2);
    if ($p[0] === 'media' && ($p[1] ?? '') === 'photos' && count($p) > 2) return $wl . '/photos/' . $rest(2);
    if ($p[0] === 'messages') {
        if (($p[1] ?? '') === 'accounts.json' && count($p) === 2) return $wl . '/accounts.json';
        if (count($p) < 3) return null;
        $base = $p[1] === 'main' ? $wl : $wl . '/accounts/' . preg_replace('/[^A-Za-z0-9_-]/', '', $p[1]);
        return $base . '/' . $rest(2);
    }
    return null;
}
/** Files available to restore: from "Current" (the manifest) or from one archive folder (listed on Drive). */
function wf_restore_list(string $source): array {
    if ($source === 'current') { $out = []; foreach (wf_backup_manifest()['files'] ?? [] as $rel => $x) $out[$rel] = ['id' => $x['id'], 'size' => (int) ($x['size'] ?? 0), 'md5' => $x['md5'] ?? '']; return $out; }
    $t = wf_drive_token(); if (!$t['ok']) return [];
    $out = []; $page = '';
    do { $r = wf_drive_req('GET', 'https://www.googleapis.com/drive/v3/files?' . http_build_query(['q' => "'" . str_replace("'", '', $source) . "' in parents and trashed=false", 'fields' => 'nextPageToken,files(id,name,size,md5Checksum)', 'pageSize' => 1000, 'pageToken' => $page]), $t['token']);
        foreach ($r['body']['files'] ?? [] as $f) $out[str_replace(' › ', '/', $f['name'])] = ['id' => $f['id'], 'size' => (int) ($f['size'] ?? 0), 'md5' => $f['md5Checksum'] ?? ''];
        $page = (string) ($r['body']['nextPageToken'] ?? ''); } while ($page !== '');
    ksort($out); return $out;
}
/** Runs a restore. $p = {source, types[], files[], mode: missing|overwrite} */
function wf_restore_run(array $p, int $maxSeconds = 3000): array {
    $t0 = time(); $t = wf_drive_token(); if (!$t['ok']) return $t;
    $list = wf_restore_list((string) ($p['source'] ?? 'current'));
    $pick = array_flip((array) ($p['files'] ?? [])); $types = (array) ($p['types'] ?? []);
    $todo = [];
    foreach ($list as $rel => $x) { if ($pick ? !isset($pick[$rel]) : !in_array(explode('/', $rel)[0], $types, true)) continue; $to = wf_backup_local_path($rel); if ($to) $todo[$rel] = $x + ['to' => $to]; }
    $overwrite = ($p['mode'] ?? 'missing') === 'overwrite';
    $sum = ['restored' => 0, 'skipped' => 0, 'kept_copies' => 0, 'bytes' => 0, 'errors' => [], 'source' => $p['source'] ?? 'current'];
    $safe = dirname(wf_backup_root()) . '/backups/restore-safety/' . date('Y-m-d_His'); // the server's own copy of anything a restore replaces
    $pr = ['running' => true, 'phase' => 'restore', 'done' => 0, 'total' => count($todo), 'bytes_done' => 0, 'bytes_total' => array_sum(array_column($todo, 'size')), 'current' => '', 'started' => date('c')];
    wf_backup_progress($pr);
    foreach ($todo as $rel => $x) {
        if (time() - $t0 > $maxSeconds) { $sum['partial'] = true; break; }
        $to = $x['to']; $exists = is_file($to);
        if ($exists && !$overwrite) { $sum['skipped']++; $pr['done']++; continue; }
        if ($exists && $x['md5'] !== '' && md5_file($to) === $x['md5']) { $sum['skipped']++; $pr['done']++; continue; } // already the same
        $pr['current'] = $rel; if ($pr['done'] % 3 === 0) wf_backup_progress($pr);
        $t = wf_drive_token(); $tmp = $to . '.restore-' . bin2hex(random_bytes(3));
        if (!is_dir(dirname($to))) { @mkdir(dirname($to), 0700, true); }
        $fh = @fopen($tmp, 'wb'); if (!$fh) { $sum['errors'][] = $rel . ': cannot write'; $pr['done']++; continue; }
        $ch = curl_init('https://www.googleapis.com/drive/v3/files/' . rawurlencode($x['id']) . '?alt=media');
        curl_setopt_array($ch, [CURLOPT_FILE => $fh, CURLOPT_TIMEOUT => 1800, CURLOPT_FOLLOWLOCATION => true, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $t['token']]]);
        curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); fclose($fh);
        if ($code !== 200 || ($x['md5'] !== '' && md5_file($tmp) !== $x['md5'])) { @unlink($tmp); $sum['errors'][] = $rel . ': download failed (HTTP ' . $code . ')'; $pr['done']++; continue; }
        if ($exists) { $dst = $safe . '/' . $rel; @mkdir(dirname($dst), 0700, true); if (@copy($to, $dst)) $sum['kept_copies']++; }
        @chmod($tmp, 0600); rename($tmp, $to);
        $sum['restored']++; $sum['bytes'] += $x['size']; $pr['done']++; $pr['bytes_done'] += $x['size'];
        if (count($sum['errors']) > 20) break;
    }
    // Restored data sets go back into the database.
    if ($sum['restored'] && wi_kv_on()) foreach (array_keys($todo) as $rp) if (str_starts_with($rp, 'data/db_snapshot/kv/')) { $v = json_decode((string) @file_get_contents(WI_DIR . '/db_snapshot/kv/' . basename($rp)), true); if (is_array($v)) wi_kv_put(basename($rp), $v); }
    // Data that lives in MySQL is brought in line with the restored files.
    if ($sum['restored'] && (isset($todo['data/users.json']) || isset($todo['data/cases.json']) || isset($todo['data/registry.json']))) {
        $cfg = wi_read('config.json', []);
        if (!empty($cfg['users_db']) && isset($todo['data/users.json'])) wi_db_save_users(json_decode((string) file_get_contents(WI_DIR . '/users.json'), true) ?: []);
        if (!empty($cfg['cases_db']) && isset($todo['data/cases.json'])) wi_db_cases_write(json_decode((string) file_get_contents(WI_DIR . '/cases.json'), true) ?: []);
        if (!empty($cfg['cases_db']) && isset($todo['data/registry.json'])) wi_db_reports_write(json_decode((string) file_get_contents(WI_DIR . '/registry.json'), true) ?: []);
        $sum['database_updated'] = true;
    }
    if ($sum['kept_copies']) $sum['safety_folder'] = str_replace(dirname(wf_backup_root()) . '/', '', $safe);
    $sum['ok'] = !$sum['errors'];
    wf_backup_progress(['running' => false, 'phase' => 'done', 'done' => $pr['done'], 'total' => $pr['total'], 'bytes_done' => $pr['bytes_done'], 'bytes_total' => $pr['bytes_total'], 'current' => '']);
    return $sum;
}
