<?php
declare(strict_types=1);

/*
 * Keeps the gateway hub running (hub.js: every number set to the hub mode in ONE Node process). Called every minute at the
 * end of accounts_watchdog.php. Does nothing while no number is set to the hub mode (accounts.json "gateway": "hub",
 * config.json "main_gateway": "hub"). Never starts a second hub: the hub's pid file and health port are its lock.
 * Per-number gateways of hub numbers are stopped here, so the hub can take their ports (it waits until a port is free).
 * Rollback: take the "gateway" setting off a number; the hub stops it within 15 s and the per-number watchdog starts it again.
 */

if (!defined('WA_HUB_DIR')) define('WA_HUB_DIR', __DIR__);
$hubLock = @fopen(WA_HUB_DIR . '/hub_watchdog.lock', 'ce');
if (!$hubLock || !flock($hubLock, LOCK_EX | LOCK_NB)) return;

$hubConf = json_decode((string) @file_get_contents(dirname(WA_HUB_DIR) . '/wa-inbox/.data/config.json'), true) ?: [];
$hubReg = json_decode((string) @file_get_contents(dirname(WA_HUB_DIR) . '/wa-inbox/.data/accounts.json'), true) ?: [];
$hubIds = []; foreach ($hubReg as $a) if (($a['gateway'] ?? '') === 'hub' && !empty($a['enabled']) && empty($a['archived'])) $hubIds[] = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) ($a['id'] ?? '')));
$mainHub = ($hubConf['main_gateway'] ?? '') === 'hub';
$hubPort = (int) ($hubConf['hub_port'] ?? 3190); $hubToken = (string) (($hubConf['hub_token'] ?? '') ?: ($hubConf['gateway_token'] ?? ''));
$hubPidFile = WA_HUB_DIR . '/hub.pid'; $hubPid = (int) @file_get_contents($hubPidFile);
$hubAlive = $hubPid > 1 && function_exists('posix_kill') && @posix_kill($hubPid, 0) && strpos((string) @file_get_contents('/proc/' . $hubPid . '/cmdline'), 'hub.js') !== false;

/** Stops a per-number gateway of a hub number (by its pid file, or for the main number every server.js copy without ACCOUNT_ID). */
function wa_hub_stop_single(string $id): void {
    if (!function_exists('posix_kill')) return;
    if ($id === 'main') { foreach ((array) glob('/proc/[0-9]*', GLOB_ONLYDIR) as $pd) { if (strpos((string) @file_get_contents($pd . '/cmdline'), 'wa-local/server.js') === false) continue;
        if (strpos((string) @file_get_contents($pd . '/environ'), 'ACCOUNT_ID=') === false) @posix_kill((int) basename($pd), 15); } return; }
    $pid = (int) @file_get_contents(WA_HUB_DIR . '/accounts/' . $id . '/gateway.pid');
    if ($pid > 1 && @posix_kill($pid, 0) && strpos((string) @file_get_contents('/proc/' . $pid . '/cmdline'), 'server.js') !== false) @posix_kill($pid, 15);
}

if (!$hubIds && !$mainHub) { if ($hubAlive) @posix_kill($hubPid, 15); return; } // nobody in the hub mode: no hub
foreach ($hubIds as $id) wa_hub_stop_single($id);
if ($mainHub) wa_hub_stop_single('main');

// the hub answers its health address: nothing to do
$ch = curl_init('http://127.0.0.1:' . $hubPort . '/health'); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 6, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $hubToken]]);
$hb = json_decode((string) curl_exec($ch), true); curl_close($ch);
@file_put_contents(WA_HUB_DIR . '/hub-guard.json', json_encode(['checked_at' => date('c'), 'up' => !empty($hb['ok']), 'sessions' => count((array) ($hb['sessions'] ?? [])), 'rss_mb' => $hb['memory_mb']['rss'] ?? null]));
if (!empty($hb['ok'])) return;
// busy but alive (a big start): never start a second one; a hub that stopped answering for 4 minutes is replaced
if ($hubAlive && time() - (int) @filectime('/proc/' . $hubPid) < 240) return;
if ($hubAlive) { @posix_kill($hubPid, 15); sleep(3); }
if (!function_exists('proc_open')) return;
$node = (string) (json_decode((string) @file_get_contents(WA_HUB_DIR . '/gateway.json'), true)['node_bin'] ?? ''); if ($node === '' || !is_executable($node)) $node = dirname(WA_HUB_DIR, 2) . '/opt/node/bin/node';
if (!is_executable($node)) $node = 'node';
$heap = max(256, min(4096, (int) ($hubConf['hub_heap_mb'] ?? 768)));
$env = array_merge($_ENV, ['HOME' => WA_HUB_DIR, 'UV_THREADPOOL_SIZE' => '4']);
$log = WA_HUB_DIR . '/hub.log';
// started in its own session (setsid), like the per-number gateways, so it outlives this minute job
$p = @proc_open('setsid ' . escapeshellarg($node) . ' --v8-pool-size=1 --expose-gc --max-old-space-size=' . $heap . ' ' . escapeshellarg(WA_HUB_DIR . '/hub.js') . ' >> ' . escapeshellarg($log) . ' 2>&1 < /dev/null &', [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, WA_HUB_DIR, $env);
if (is_resource($p)) proc_close($p);
@file_put_contents(WA_HUB_DIR . '/guard.log', date('c') . ' ' . (is_resource($p) ? 'started' : 'could not start') . " hub\n", FILE_APPEND);
