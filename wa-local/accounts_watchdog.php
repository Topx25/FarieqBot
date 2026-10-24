<?php
declare(strict_types=1);

/*
 * Keeps the extra WhatsApp accounts running (cron, every minute, as the account user).
 * The registry is written by WhatsApp Inbox (wa-inbox/.data/accounts.json). Each extra account
 * runs wa-local/server.js with its own DATA_DIR (wa-local/accounts/<id>: session + archive),
 * PORT and GATEWAY_TOKEN. The main account (port 3188) is handled by watchdog.php as before.
 */

define('WA_REGISTRY', dirname(__DIR__) . '/wa-inbox/.data/accounts.json'); // the dashboard next to this folder
const WA_DIR = __DIR__;

$lock = @fopen(WA_DIR . '/accounts_watchdog.lock', 'ce'); // 'e': not inherited by started gateways
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit;

function wa_node(): string {
    foreach ([(string) (getenv('NODE_BIN') ?: (json_decode((string) @file_get_contents(__DIR__ . '/gateway.json'), true)['node_bin'] ?? '')), dirname(__DIR__, 2) . '/opt/node/bin/node', '/opt/cpanel/ea-nodejs22/bin/node', '/usr/local/bin/node', '/usr/bin/node'] as $n) if ($n !== '' && is_file($n) && is_executable($n)) return $n;
    return 'node';
}
function wa_http(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 6]);
    $body = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    return ['up' => $body !== false && $code > 0, 'json' => is_string($body) ? json_decode($body, true) : null];
}
function wa_stop(string $dir): void {
    $pid = (int) @file_get_contents($dir . '/gateway.pid');
    if ($pid > 1 && function_exists('posix_kill') && @posix_kill($pid, 0)) {
        $cmd = @file_get_contents('/proc/' . $pid . '/cmdline');
        if (is_string($cmd) && strpos($cmd, 'server.js') !== false) posix_kill($pid, 15);
    }
}

$registry = is_file(WA_REGISTRY) ? json_decode((string) file_get_contents(WA_REGISTRY), true) : [];
if (!is_array($registry)) $registry = [];
$wanted = [];
$guard = ['checked_at' => date('c'), 'accounts' => []];
foreach ($registry as $a) {
    $id = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) ($a['id'] ?? '')));
    $port = (int) ($a['port'] ?? 0);
    $token = (string) ($a['token'] ?? '');
    if ($id === '' || $id === 'main' || $port < 1024 || $port > 65535 || strlen($token) < 32) continue;
    $dir = WA_DIR . '/accounts/' . $id;
    if (empty($a['enabled'])) { wa_stop($dir); continue; }
    if (($a['gateway'] ?? '') === 'hub') continue; // run by the hub (hub_watchdog.php below stops this number's own process)
    if (in_array($a['provider'] ?? '', ['openwa', 'cloud'], true)) { wa_stop($dir); continue; } // on another provider: no gateway of ours
    if (!empty($a['parked']) && (int) ($a['qr_until'] ?? 0) < time()) { wa_stop($dir); continue; } // not linked: no process until someone opens its QR page
    $wanted[$id] = true;
    if (!is_dir($dir)) { @mkdir($dir . '/archive', 0755, true); @mkdir($dir . '/auth', 0700, true); }
    $st = wa_http('http://127.0.0.1:' . $port . '/status?token=' . rawurlencode($token));
    $guard['accounts'][$id] = ['up' => $st['up'], 'connected' => !empty($st['json']['connected']), 'restarted' => !$st['up']];
    if ($st['up']) continue;
    // Busy but alive (e.g. processing a big history sync): never start a second copy on the same session.
    $pid = (int) @file_get_contents($dir . '/gateway.pid');
    $cmdline = $pid > 1 ? (string) @file_get_contents('/proc/' . $pid . '/cmdline') : '';
    if ($cmdline !== '' && strpos($cmdline, 'server.js') !== false) { $guard['accounts'][$id]['restarted'] = false; $guard['accounts'][$id]['busy'] = true; continue; }
    // any other copy of this number's gateway still alive (its pid file was overwritten): never start one more
    $alive = false; foreach ((array) glob('/proc/[0-9]*', GLOB_ONLYDIR) as $pd) { $cl = (string) @file_get_contents($pd . '/cmdline'); if (strpos($cl, 'server.js') === false) continue; if (strpos((string) @file_get_contents($pd . '/environ'), "ACCOUNT_ID={$id}\0") !== false) { $alive = true; break; } }
    if ($alive) { $guard['accounts'][$id]['restarted'] = false; $guard['accounts'][$id]['busy'] = true; continue; }
    $env = array_merge($_ENV, ['GATEWAY_TOKEN' => $token, 'PORT' => (string) $port, 'DATA_DIR' => $dir, 'ACCOUNT_ID' => $id,
        'STORAGE_ROOT' => dirname(WA_DIR) . '/storage', 'HOME' => WA_DIR, 'UV_THREADPOOL_SIZE' => '2']);
    $log = $dir . '/gateway.log';
    // Fully detached (own session, no pipes), so this run ends and the next run can manage the account.
    $cmd = 'setsid ' . escapeshellarg(wa_node()) . ' --v8-pool-size=1 --expose-gc --max-old-space-size=384 ' . escapeshellarg(WA_DIR . '/server.js') . ' >> ' . escapeshellarg($log) . ' 2>&1 < /dev/null &';
    $p = @proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, WA_DIR, $env);
    if (is_resource($p)) proc_close($p);
    @file_put_contents($log, date('c') . " watchdog started account {$id} on port {$port}\n", FILE_APPEND);
}
// Guard report for the Inbox, plus a log of every restart it made.
foreach ($guard['accounts'] as $id => $g) if ($g['restarted']) @file_put_contents(WA_DIR . '/accounts/guard.log', date('c') . " restarted {$id}\n", FILE_APPEND);
@file_put_contents(WA_DIR . '/accounts/guard.json', json_encode($guard));
// Accounts removed from the registry: stop their gateways (their archive stays on disk).
foreach (glob(WA_DIR . '/accounts/*', GLOB_ONLYDIR) ?: [] as $dir) {
    if (!isset($wanted[basename($dir)])) wa_stop($dir);
}
// the numbers set to the hub mode run in one process (hub.js); nothing happens here while none is
require __DIR__ . '/hub_watchdog.php';
