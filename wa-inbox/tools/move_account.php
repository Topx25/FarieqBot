<?php
// Moves one number (and its panel owner, team, settings and database rows) from this installation to another one.
// Files (wa-local/accounts/<aid>, storage/inbox_media/<aid>) are copied apart, with tar or rsync.
//   php tools/move_account.php export <aid> <bundle.json>             on the old server
//   php tools/move_account.php import <bundle.json> [--apply] [--enable]  on the new server (dry run without --apply)
// The import stops before writing anything when a row id is already used by another number on the new server.
declare(strict_types=1);
chdir(dirname(__DIR__)); require 'lib.php'; require 'features.php';
if (PHP_SAPI !== 'cli') exit;

/** The stores that are keyed by the number id at the top, and the ones keyed by the panel owner's email. */
const MA_SKIP = ['accounts.json', 'users.json', 'config.json', 'backup_manifest.json', 'ai_tasks.json', 'js_errors.json', 'drive.json', 'google_watch.json', 'ai_reports.json'];
const MA_BY_OWNER = ['drive_ws.json', 'replies_ws.json'];

function ma_tables(mysqli $m): array {
    $o = []; $r = $m->query("SHOW TABLES LIKE 'wi\\_%'");
    while ($r && ($t = $r->fetch_row())) { $c = $m->query("SHOW COLUMNS FROM `{$t[0]}` LIKE 'base'"); if ($c && $c->num_rows) $o[] = $t[0]; }
    return $o;
}
function ma_rows(mysqli $m, string $sql): array { $r = $m->query($sql); $o = []; while ($r && ($x = $r->fetch_assoc())) $o[] = $x; return $o; }
function ma_in(mysqli $m, array $v): string { return implode(',', array_map(fn($x) => "'" . $m->real_escape_string((string) $x) . "'", $v ?: [''])); }

$cmd = $argv[1] ?? ''; $m = wi_db(); if (!$m) exit("No database.\n");

if ($cmd === 'export') {
    $aid = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) ($argv[2] ?? ''))); $out = (string) ($argv[3] ?? '');
    $acc = wi_accounts()[$aid] ?? null; if (!$acc || $out === '') exit("Usage: export <aid> <bundle.json> (unknown number?)\n");
    $owner = strtolower((string) ($acc['owner'] ?? ''));
    $users = array_values(array_filter(wi_users(true), fn($u) => $owner !== '' && (strtolower((string) $u['email']) === $owner || strtolower((string) ($u['tenant'] ?? '')) === $owner)));
    $emails = array_map(fn($u) => strtolower((string) $u['email']), $users);
    $kv = []; $names = array_map(fn($x) => $x['name'], ma_rows($m, "SELECT name FROM wi_kv"));
    foreach ($names as $n) { if (in_array($n, MA_SKIP, true)) continue; $d = wi_read($n, []);
        if (in_array($n, MA_BY_OWNER, true)) { if ($owner !== '' && isset($d[$owner])) $kv[$n] = ['owner' => $d[$owner]]; continue; }
        if (is_array($d) && array_key_exists($aid, $d)) $kv[$n] = ['aid' => $d[$aid]]; }
    $tables = []; foreach (ma_tables($m) as $t) $tables[$t] = ma_rows($m, "SELECT * FROM `$t` WHERE base = '" . $m->real_escape_string($aid) . "'");
    $cids = array_column($tables['wi_concepts'] ?? [], 'id');
    $terms = $cids ? ma_rows($m, "SELECT * FROM wi_concept_terms WHERE concept_id IN (" . ma_in($m, $cids) . ")") : [];
    $sec = ma_rows($m, "SELECT * FROM wi_secrets WHERE name LIKE '%:" . $m->real_escape_string($owner) . "' OR name LIKE '%" . $m->real_escape_string($aid) . "%'");
    $grants = $emails ? ma_rows($m, "SELECT * FROM wi_grants WHERE LOWER(email) IN (" . ma_in($m, $emails) . ")") : [];
    $B = ['aid' => $aid, 'owner' => $owner, 'at' => date('c'), 'account' => $acc, 'users' => $users, 'kv' => $kv, 'tables' => $tables, 'concept_terms' => $terms, 'secrets' => $sec, 'grants' => $grants];
    file_put_contents($out, json_encode($B, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)); chmod($out, 0600);
    echo "Exported $aid (owner $owner): ", count($users), ' users, ', count($kv), ' stores, ', implode(', ', array_map(fn($t, $r) => "$t " . count($r), array_keys($tables), $tables)), ', ', count($terms), ' concept terms, ', count($sec), ' secrets, ', count($grants), " grants.\n";
    exit;
}

if ($cmd === 'import') {
    $B = json_decode((string) @file_get_contents((string) ($argv[2] ?? '')), true); if (!is_array($B) || empty($B['aid'])) exit("Usage: import <bundle.json> [--apply] [--enable]\n");
    $apply = in_array('--apply', $argv, true); $enable = in_array('--enable', $argv, true); $aid = (string) $B['aid']; $owner = (string) $B['owner'];
    // row ids already used by another number here: stop
    $clash = [];
    foreach ($B['tables'] as $t => $rows) { $ids = array_column($rows, 'id'); if (!$ids || !is_numeric($ids[0])) continue;
        foreach (array_chunk($ids, 500) as $ch) foreach (ma_rows($m, "SELECT id FROM `$t` WHERE id IN (" . ma_in($m, $ch) . ") AND base <> '" . $m->real_escape_string($aid) . "'") as $x) $clash[$t][] = $x['id']; }
    $tids = array_column($B['concept_terms'], 'id'); $cids = array_column($B['tables']['wi_concepts'] ?? [], 'id');
    if ($tids) foreach (ma_rows($m, "SELECT id FROM wi_concept_terms WHERE id IN (" . ma_in($m, $tids) . ") AND concept_id NOT IN (" . ma_in($m, $cids) . ")") as $x) $clash['wi_concept_terms'][] = $x['id'];
    echo "Number $aid (owner $owner), bundle of {$B['at']}\n";
    echo '  users: ', implode(', ', array_column($B['users'], 'email')), "\n  stores: ", count($B['kv']), "\n";
    foreach ($B['tables'] as $t => $rows) echo "  $t: ", count($rows), " rows", isset($clash[$t]) ? ' — ' . count($clash[$t]) . ' ids used by another number' : '', "\n";
    echo '  concept terms ', count($B['concept_terms']), ', secrets ', count($B['secrets']), ', grants ', count($B['grants']), "\n";
    if ($clash) exit("STOP: row ids clash with another number on this server; nothing written.\n");
    if (!$apply) exit("Dry run: nothing written. Add --apply to import.\n");

    $m->begin_transaction();
    try {
        foreach ($B['tables'] as $t => $rows) { $m->query("DELETE FROM `$t` WHERE base = '" . $m->real_escape_string($aid) . "'");
            foreach ($rows as $r) { $cols = array_keys($r); $m->query("INSERT INTO `$t` (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', array_map(fn($v) => $v === null ? 'NULL' : "'" . $m->real_escape_string((string) $v) . "'", $r)) . ")") || throw new RuntimeException("$t: " . $m->error); } }
        if ($cids) $m->query("DELETE FROM wi_concept_terms WHERE concept_id IN (" . ma_in($m, $cids) . ")");
        foreach (['wi_concept_terms' => $B['concept_terms'], 'wi_secrets' => $B['secrets']] as $t => $rows) foreach ($rows as $r) { $cols = array_keys($r);
            $m->query("REPLACE INTO `$t` (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', array_map(fn($v) => $v === null ? 'NULL' : "'" . $m->real_escape_string((string) $v) . "'", $r)) . ")") || throw new RuntimeException("$t: " . $m->error); }
        $em = array_map(fn($u) => strtolower((string) $u['email']), $B['users']);
        if ($em) $m->query("DELETE FROM wi_grants WHERE LOWER(email) IN (" . ma_in($m, $em) . ")");
        foreach ($B['grants'] as $r) { unset($r['id']); $cols = array_keys($r); $m->query("INSERT INTO wi_grants (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', array_map(fn($v) => $v === null ? 'NULL' : "'" . $m->real_escape_string((string) $v) . "'", $r)) . ")"); }
        $m->commit();
    } catch (Throwable $e) { $m->rollback(); exit('STOP: ' . $e->getMessage() . " (database rolled back)\n"); }
    foreach ($B['kv'] as $n => $x) wi_update($n, function ($d) use ($x, $aid, $owner) { $d = is_array($d) ? $d : []; if (array_key_exists('aid', $x)) $d[$aid] = $x['aid']; else $d[$owner] = $x['owner']; return $d; });
    $all = wi_users(true); $byEm = []; foreach ($all as $i => $u) $byEm[strtolower((string) $u['email'])] = $i;
    foreach ($B['users'] as $u) { $k = strtolower((string) $u['email']); if (isset($byEm[$k])) $all[$byEm[$k]] = $u; else $all[] = $u; }
    wi_save_users(array_values($all));
    $A = wi_accounts(); $acc = $B['account']; $acc['enabled'] = $enable; unset($acc['vps_note']); $A[$aid] = $acc; wi_save_accounts($A);
    wi_log('account_moved_in', ['aid' => $aid, 'enabled' => $enable]);
    echo "Imported. The number is ", $enable ? 'ON' : 'OFF (run again with --enable, or switch it on in WhatsApp numbers)', ".\n";
    exit;
}
echo "Usage:\n  php tools/move_account.php export <aid> <bundle.json>\n  php tools/move_account.php import <bundle.json> [--apply] [--enable]\n";
