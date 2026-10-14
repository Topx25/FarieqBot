<?php
// WhatsApp Inbox — backup packages (v3.11): one compressed file per package, uploaded to Drive "WhatsApp Inbox Backups / Packages",
// downloadable and movable to another server. Built by the background worker (it needs proc_open for tar and the database dump).

const WF_SNAP_KINDS = [
    'system' => ['Full system (portable)', 'Inbox code and data, gateways (code + archives), full database dump, server jobs and an install guide'],
    'db'     => ['Database only', 'Full dump of the database: invoices, users, permissions, cases, reports'],
    'chats'  => ['Conversations & numbers only', 'Every number\'s message archive, contact names, extracted numbers and group members'],
    'full'   => ['Full general', 'Everything on the site (invoice system, Inbox, gateways, all media) + database + server jobs'],
];
function wf_snap_home(): string { return dirname(wf_backup_root()); }            // /home/<user>
function wf_snap_run_cmd(array $cmd, ?string $cwd = null): array {
    $p = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    if (!$p) return ['ok' => false, 'error' => 'proc_open is not available'];
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    $code = proc_close($p);
    return ['ok' => $code === 0, 'code' => $code, 'error' => trim(mb_substr($err, -400))];
}
/** Full database dump (password kept out of the command line). */
function wf_snap_dump_db(string $file): array {
    $C = wi_db_creds('app'); if (!$C) return ['ok' => false, 'error' => 'Database not set up.'];
    $ini = $file . '.cnf'; file_put_contents($ini, "[client]\nuser=" . $C['user'] . "\npassword=\"" . addcslashes($C['pass'], '"\\') . "\"\nhost=" . $C['host'] . "\n"); @chmod($ini, 0600);
    $bin = is_file('/usr/bin/mariadb-dump') ? '/usr/bin/mariadb-dump' : 'mysqldump';
    $p = @proc_open([$bin, '--defaults-extra-file=' . $ini, '--single-transaction', '--quick', '--routines', '--default-character-set=utf8mb4', $C['name']], [1 => ['file', $file, 'w'], 2 => ['pipe', 'w']], $pipes);
    $err = $p ? stream_get_contents($pipes[2]) : 'proc_open is not available'; if ($p) { fclose($pipes[2]); $code = proc_close($p); } else $code = 1;
    @unlink($ini);
    return ['ok' => $code === 0 && filesize($file) > 0, 'error' => trim(mb_substr((string) $err, -300)), 'db' => $C['name']];
}
function wf_snap_readme(string $kind, string $db): string {
    $d = date('Y-m-d H:i');
    return wi_sys('brand_name') . " — backup package ($kind), $d\n\n"
        . "Contents\n- site/ … files, in the same layout as public_html\n- database.sql … full dump of the database \"$db\" (if included)\n- server/ … the every-minute job (run_minute.sh) and the crontab line\n\n"
        . "Move to a new server (cPanel or any Linux with PHP 8.2+, MariaDB/MySQL, Node 18+)\n"
        . "1. Copy site/ into the new account's public_html (keep the folders as they are).\n"
        . "2. Create a database and a user, then import: mysql -u USER -p NEWDB < database.sql\n"
        . "3. Edit wa-inbox/.data/db.php (return ['host'=>..,'user'=>..,'pass'=>..,'name'=>..]) for the new database.\n"
        . "4. Install the gateway packages: cd public_html/wa-local && npm install --omit=dev\n"
        . "5. Copy server/run_minute.sh to the home folder, fix the paths inside it, and add the crontab line from server/crontab.txt.\n"
        . "6. Point the domain to the new server, open /wa-inbox/, sign in, then WhatsApp → link each number again with its QR code\n"
        . "   (WhatsApp login keys are never included in a backup, for safety).\n"
        . "7. Settings → Google: connect Google Drive again (the redirect address changes with the domain).\n";
}
/** Builds and uploads one package. */
function wf_snapshot_run(string $kind): array {
    if (!isset(WF_SNAP_KINDS[$kind])) return ['ok' => false, 'error' => 'unknown package'];
    $root = wf_backup_root(); $home = wf_snap_home(); $tmp = $home . '/tmp_backup/pkg_' . date('Ymd_His'); @mkdir($tmp . '/server', 0700, true);
    $name = 'whatsapp-inbox-' . $kind . '-' . date('Y-m-d_H-i') . '.tar.gz';
    $prog = fn($phase, $cur = '', $done = 0, $total = 0) => wf_backup_progress(['running' => true, 'phase' => 'package', 'package' => $kind, 'step' => $phase, 'current' => $cur, 'done' => $done, 'total' => $total, 'bytes_done' => $done, 'bytes_total' => $total]);
    $sum = ['kind' => $kind, 'name' => $name];
    try {
        // 1) database
        if (in_array($kind, ['system', 'db', 'full'], true)) { $prog('database'); $d = wf_snap_dump_db($tmp . '/database.sql'); if (!$d['ok']) throw new RuntimeException('Database dump failed: ' . $d['error']); $sum['db_mb'] = (float) number_format(filesize($tmp . '/database.sql') / 1048576, 1, '.', ''); $db = $d['db']; }
        // 2) server jobs + guide
        if ($kind !== 'db') { @copy($home . '/run_minute.sh', $tmp . '/server/run_minute.sh'); file_put_contents($tmp . '/server/crontab.txt', "* * * * * /bin/bash $home/run_minute.sh\n"); }
        file_put_contents($tmp . '/README.txt', wf_snap_readme($kind, $db ?? ''));
        // 3) files → tar.gz (streamed by tar; WhatsApp login keys, caches, logs and dependencies are left out)
        $prog('packing');
        $exP = []; foreach (wi_backup_excluded() as $xid) foreach ([$xid === 'main' ? 'wa-local/archive' : 'wa-local/accounts/' . $xid, 'storage/inbox_media/' . $xid] as $xp) { $exP[] = '--exclude=' . $xp; $exP[] = '--exclude=./' . $xp; } // private numbers of other owners
        $ex = ['--exclude=*/node_modules', '--exclude=*/auth', '--exclude=*/auth-previous', '--exclude=*/auth-backup*', '--exclude=*.lock', '--exclude=*/tmp_backup', '--exclude=*/error_log', '--exclude=*.log', '--exclude=*/wa-local/backups', '--exclude=*/.data/cache_*', '--exclude=*/.data/db_snapshot'];
        $site = [];
        if ($kind === 'system') $site = ['wa-inbox', 'wa-local', 'config.php'];
        if ($kind === 'full') { $site = ['.']; }
        if ($kind === 'chats') {
            $site = ['wa-local/archive', 'wa-local/names.json', 'wa-local/accounts.json'];
            foreach (glob($root . '/wa-local/accounts/*', GLOB_ONLYDIR) ?: [] as $ad) { $id = basename($ad); foreach (['archive', 'names.json'] as $f) if (file_exists($ad . '/' . $f)) $site[] = 'wa-local/accounts/' . $id . '/' . $f; }
            foreach (glob($root . '/wa-inbox/.data/{numbers_*.json,known_members.json,customers.json,notes.json,labels.json}', GLOB_BRACE) ?: [] as $f) $site[] = 'wa-inbox/.data/' . basename($f);
        }
        $site = array_values(array_filter($site, fn($p) => file_exists($root . '/' . $p)));
        $out = $home . '/tmp_backup/' . $name;
        // files from public_html go under site/ inside the package
        $cmd = array_merge(['tar', '-czf', $out, '--warning=no-file-changed', '--ignore-failed-read'], $ex, $exP, ['-C', $tmp, 'README.txt', 'server'], file_exists($tmp . '/database.sql') ? ['database.sql'] : [],
            $site ? array_merge(['--transform=flags=r;s,^,site/,', '--transform=flags=r;s,^site/\\(README\\.txt\\|server\\|database\\.sql\\),\\1,', '-C', $root], $site) : []);
        $r = wf_snap_run_cmd($cmd);
        if (!is_file($out) || ($r['code'] ?? 1) > 1) throw new RuntimeException('Packing failed: ' . ($r['error'] ?? ''));
        $size = filesize($out); $sum['size'] = $size;
        // 4) upload
        $prog('upload', $name, 0, $size);
        $t = wf_drive_token(); if (!$t['ok']) throw new RuntimeException($t['error']);
        $rootId = wf_drive_folder($t['token']); $c = wf_drive_cfg();
        if (empty($c['packages_id'])) { $c['packages_id'] = wf_drive_child_folder($t['token'], $rootId, 'Packages'); wi_write('drive.json', $c); }
        $u = wf_drive_put($t['token'], $out, $name, (string) $c['packages_id'], null);
        if (getenv('WI_KEEP_PKG')) copy($out, getenv('WI_KEEP_PKG'));
        @unlink($out);
        if (!$u['ok']) throw new RuntimeException('Upload failed: ' . $u['error']);
        $c = wf_drive_cfg(); $c['packages'] = $c['packages'] ?? [];
        array_unshift($c['packages'], ['id' => $u['id'], 'kind' => $kind, 'name' => $name, 'size' => $size, 'at' => date('c'), 'db_mb' => $sum['db_mb'] ?? null]);
        $c['packages'] = array_slice($c['packages'], 0, 100); wi_write('drive.json', $c);
        $sum['ok'] = true;
    } catch (Throwable $e) { $sum['ok'] = false; $sum['error'] = $e->getMessage(); }
    // clean the work folder
    foreach (['database.sql', 'README.txt', 'server/run_minute.sh', 'server/crontab.txt'] as $f) @unlink($tmp . '/' . $f);
    @rmdir($tmp . '/server'); @rmdir($tmp);
    wf_backup_progress(['running' => false, 'phase' => 'done', 'current' => '', 'done' => 0, 'total' => 0, 'bytes_done' => 0, 'bytes_total' => 0]);
    return $sum;
}
