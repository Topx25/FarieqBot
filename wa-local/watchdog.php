<?php
declare(strict_types=1);
// The gateway runs in its own container (WI_GATEWAY_EXTERNAL): Docker keeps it up, these watchdogs never start one.
if (getenv('WI_GATEWAY_EXTERNAL')) return;

// The main gateway's token and port live in the dashboard settings (wa-inbox/.data/config.json), never in the code.
$waConf = json_decode((string) @file_get_contents(dirname(__DIR__) . '/wa-inbox/.data/config.json'), true) ?: [];
$token = (string) ($waConf['gateway_token'] ?? '');
if (($waConf['main_gateway'] ?? '') === 'hub') exit; // the main number runs in the hub (hub_watchdog.php keeps it)
$nodePort = (int) ($waConf['main_port'] ?? 3188);
$gatewayDir = __DIR__;
$logFile = $gatewayDir . '/gateway.log';
$heartbeatFile = $gatewayDir . '/gateway-heartbeat.json';
$lockPath = $gatewayDir . '/watchdog.lock';

function wa_watchdog_disabled_function(string $name): bool
{
    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    return in_array($name, $disabled, true);
}

function wa_watchdog_find_node(): string
{
    foreach ([
        // NODE_BIN / gateway.json node_bin first, then Node installed in the account's home (Baileys 7 needs Node 20+), then system Node
        (string) (getenv('NODE_BIN') ?: (json_decode((string) @file_get_contents(__DIR__ . '/gateway.json'), true)['node_bin'] ?? '')), dirname(__DIR__, 2) . '/opt/node/bin/node',
        '/opt/cpanel/ea-nodejs22/bin/node',
        '/opt/cpanel/ea-nodejs20/bin/node',
        '/opt/cpanel/ea-nodejs18/bin/node',
        '/opt/cpanel/ea-nodejs16/bin/node',
        '/usr/local/bin/node',
        '/usr/bin/node',
    ] as $candidate) {
        if (is_file($candidate) && is_executable($candidate)) {
            return $candidate;
        }
    }
    return 'node';
}

function wa_watchdog_http(string $url, int $timeout = 6): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'PHP cURL is not available'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => $timeout,
    ]);
    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [
        'ok' => $body !== false && $status >= 200 && $status < 500,
        'status' => $status,
        'body' => $body !== false ? (string) $body : '',
        'error' => $error,
    ];
}

function wa_watchdog_start_gateway(string $gatewayDir, string $token, int $port, string $logFile): array
{
    if (!function_exists('proc_open') || wa_watchdog_disabled_function('proc_open')) {
        return ['ok' => false, 'error' => 'proc_open is disabled'];
    }
    $node = wa_watchdog_find_node();
    $env = array_merge($_ENV, [
        'GATEWAY_TOKEN' => $token,
        'PORT' => (string) $port,
        'STORAGE_ROOT' => dirname($gatewayDir) . '/storage',
        'HOME' => $gatewayDir,
    ]);
    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['file', $logFile, 'a'],
        2 => ['file', $logFile, 'a'],
    ];
    // Fewer threads: the hosting plan limits processes + threads per user.
    $env['UV_THREADPOOL_SIZE'] = '4';
    $cmd = escapeshellarg($node) . ' --v8-pool-size=2 ' . escapeshellarg($gatewayDir . '/server.js');
    $process = @proc_open($cmd, $descriptor, $pipes, $gatewayDir, $env);
    if (!is_resource($process)) {
        return ['ok' => false, 'error' => 'Unable to start Node.js gateway process'];
    }
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    return ['ok' => true, 'error' => ''];
}

$lock = @fopen($lockPath, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit;
}

$statusUrl = 'http://127.0.0.1:' . $nodePort . '/status?token=' . rawurlencode($token);
$status = wa_watchdog_http($statusUrl, 6);
$started = false;

if (!$status['ok'] || $status['status'] === 0) {
    // copies of the main gateway that stopped answering: all stopped first, so only one runs (two copies on one session fight for it)
    $copies = []; foreach ((array) glob('/proc/[0-9]*', GLOB_ONLYDIR) as $pd) { $cl = (string) @file_get_contents($pd . '/cmdline'); if (strpos($cl, 'wa-local/server.js') === false) continue;
        if (strpos((string) @file_get_contents($pd . '/environ'), "ACCOUNT_ID=") === false) $copies[(int) basename($pd)] = time() - (int) @filectime($pd); }
    if ($copies && min($copies) < 240) exit; // one is still starting up (it answers after a while): wait for it
    foreach (array_keys($copies) as $cp) if (function_exists('posix_kill')) @posix_kill($cp, 15);
    if ($copies) sleep(3);
    $start = wa_watchdog_start_gateway($gatewayDir, $token, $nodePort, $logFile);
    $started = (bool) $start['ok'];
    @file_put_contents($gatewayDir . '/guard.log', date('c') . " restarted main\n", FILE_APPEND);
    usleep(900000);
    $status = wa_watchdog_http($statusUrl, 6);
}

// ---- Down / recovery alerts to the admin (sent by the invoice app through the Telegram account) ----
$alertFile = $gatewayDir . '/gateway-alert.json';
$alertState = is_file($alertFile) ? (json_decode((string) file_get_contents($alertFile), true) ?: []) : [];
$statusBody = json_decode((string) $status['body'], true);
$isUp = is_array($statusBody) && !empty($statusBody['connected']);
$sendAlert = function (array $fields) use ($gatewayDir) {
    $secret = trim((string) @file_get_contents(dirname($gatewayDir) . '/application/cache/whatsapp_payment_webhook_token'));
    if ($secret === '' || !function_exists('curl_init')) {
        return false;
    }
    // Invoice add-on alert: address from wa-local/gateway.json (no add-on = no alert there; the dashboard sends its own alerts).
    $gw = json_decode((string) @file_get_contents(__DIR__ . '/gateway.json'), true) ?: [];
    $hook = (string) ($gw['invoice_webhook_url'] ?? ''); if ($hook === '') return false;
    $ch = curl_init(preg_replace('#/whatsapp_payment_webhook$#', '/gateway_alert', $hook) . '?token=' . rawurlencode($secret));
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields), CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => !empty($gw['invoice_webhook_host']) ? ['Host: ' . $gw['invoice_webhook_host']] : [], CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 90]);
    $out = curl_exec($ch);
    curl_close($ch);
    $decoded = json_decode((string) $out, true);
    return is_array($decoded) && !empty($decoded['ok']);
};
if (!$isUp) {
    $downSince = isset($alertState['down_since']) ? (int) $alertState['down_since'] : time();
    if (is_array($statusBody) && !empty($statusBody['down_seconds'])) {
        $downSince = min($downSince, time() - (int) $statusBody['down_seconds']);
    }
    $alertState['down_since'] = $downSince;
    $downMinutes = (int) floor((time() - $downSince) / 60);
    $lastAlert = isset($alertState['alerted_at']) ? (int) $alertState['alerted_at'] : 0;
    if ($downMinutes >= 5 && ($lastAlert === 0 || time() - $lastAlert >= 6 * 3600)) {
        $ok = $sendAlert([
            'event' => 'down',
            'down_minutes' => $downMinutes,
            'error' => is_array($statusBody) ? (string) ($statusBody['last_error'] ?? '') : ($status['error'] ?: 'Gateway process is not responding'),
            'needs_qr' => is_array($statusBody) && !empty($statusBody['needs_qr']) ? 1 : 0,
        ]);
        if ($ok) {
            $alertState['alerted_at'] = time();
        }
    }
} else {
    if (!empty($alertState['alerted_at'])) {
        $sendAlert(['event' => 'up', 'down_minutes' => (int) floor((time() - (int) ($alertState['down_since'] ?? time())) / 60)]);
    }
    $alertState = [];
}
@file_put_contents($alertFile, json_encode($alertState));

@file_put_contents($heartbeatFile, json_encode([
    'checked_at' => date('c'),
    'started' => $started,
    'status' => $status['status'],
    'body' => json_decode($status['body'], true) ?: $status['body'],
    'error' => $status['error'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

flock($lock, LOCK_UN);
fclose($lock);
