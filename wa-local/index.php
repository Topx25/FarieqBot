<?php
declare(strict_types=1);

// The main gateway's token and port live in the dashboard settings (wa-inbox/.data/config.json), never in the code.
$waConf = json_decode((string) @file_get_contents(dirname(__DIR__) . '/wa-inbox/.data/config.json'), true) ?: [];
$token = (string) ($waConf['gateway_token'] ?? '');
$nodePort = (int) ($waConf['main_port'] ?? 3188);
$nodeUrl = 'http://127.0.0.1:' . $nodePort;
$gatewayDir = __DIR__;
$logFile = $gatewayDir . '/gateway.log';

function wa_disabled_function(string $name): bool
{
    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    return in_array($name, $disabled, true);
}

function wa_find_node(): string
{
    $candidates = [
        (string) (getenv('NODE_BIN') ?: (json_decode((string) @file_get_contents(__DIR__ . '/gateway.json'), true)['node_bin'] ?? '')), dirname(__DIR__, 2) . '/opt/node/bin/node', 
        '/opt/cpanel/ea-nodejs22/bin/node',
        '/opt/cpanel/ea-nodejs20/bin/node',
        '/opt/cpanel/ea-nodejs18/bin/node',
        '/opt/cpanel/ea-nodejs16/bin/node',
        '/usr/local/bin/node',
        '/usr/bin/node',
    ];
    foreach ($candidates as $candidate) {
        if (is_file($candidate) && is_executable($candidate)) {
            return $candidate;
        }
    }
    return 'node';
}

function wa_try_start_gateway(string $gatewayDir, string $token, int $port, string $logFile): array
{
    if (!function_exists('proc_open') || wa_disabled_function('proc_open')) {
        return ['ok' => false, 'error' => 'proc_open is disabled on this hosting account'];
    }
    $lockPath = $gatewayDir . '/gateway-start.lock';
    $lock = @fopen($lockPath, 'c');
    if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        usleep(1200000);
        return ['ok' => true, 'error' => 'Gateway start is already in progress'];
    }
    $node = wa_find_node();
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
    $cmd = escapeshellarg($node) . ' ' . escapeshellarg($gatewayDir . '/server.js');
    $process = @proc_open($cmd, $descriptor, $pipes, $gatewayDir, $env);
    if (!is_resource($process)) {
        if ($lock) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return ['ok' => false, 'error' => 'Unable to start Node.js gateway process'];
    }
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    if ($lock) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    return ['ok' => true, 'error' => ''];
}

function wa_gateway_http(string $url, string $method = 'GET', array $headers = [], string $body = '', int $timeout = 5): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'status' => 0, 'headers' => '', 'body' => '', 'error' => 'PHP cURL is not available'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $body !== '' ? $body : null,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HEADER => true,
    ]);
    $raw = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return [
        'ok' => $raw !== false,
        'status' => $status,
        'headers' => $raw !== false ? substr($raw, 0, $headerSize) : '',
        'body' => $raw !== false ? substr($raw, $headerSize) : '',
        'error' => $error,
    ];
}

$requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/wa-local/');
$path = isset($_GET['route']) ? (string) $_GET['route'] : (parse_url($requestUri, PHP_URL_PATH) ?: '/');
$query = (string) ($_SERVER['QUERY_STRING'] ?? '');
$prefix = '/wa-local';
if ($path === $prefix || $path === $prefix . '/') {
    $path = '/qr';
} elseif (str_starts_with($path, $prefix . '/index.php')) {
    $path = substr($path, strlen($prefix . '/index.php')) ?: '/qr';
} elseif (str_starts_with($path, $prefix . '/')) {
    $path = substr($path, strlen($prefix));
}
if ($path === '' || $path === '/') {
    $path = '/qr';
}

$queryParts = [];
if ($query !== '') {
    parse_str($query, $queryParts);
}
// Security: the caller must present the gateway token itself (query ?token= or Bearer header).
// It used to be added automatically, which let anyone send messages, read the QR or reset the session.
$givenToken = isset($queryParts['token']) ? (string) $queryParts['token'] : '';
if ($givenToken === '' && preg_match('/^Bearer\s+(.+)$/i', (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''), $bearer)) {
    $givenToken = trim($bearer[1]);
}
if ($givenToken === '' || !hash_equals($token, $givenToken)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}
$queryParts['token'] = $token;

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($path === '/send' && $method === 'GET') {
    $statusUrl = $nodeUrl . '/status?token=' . rawurlencode($token);
    $statusResponse = wa_gateway_http($statusUrl, 'GET', ['Accept: application/json'], '', 5);
    $connected = false;
    if ($statusResponse['ok'] && $statusResponse['status'] === 200) {
        $decodedStatus = json_decode($statusResponse['body'], true);
        $connected = is_array($decodedStatus) && !empty($decodedStatus['connected']);
    }
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>WhatsApp Send Gateway</title><style>body{font-family:Arial,sans-serif;background:#f6f7fb;color:#111;display:grid;place-items:center;min-height:100vh;margin:0}.box{max-width:560px;background:#fff;border:1px solid #ddd;padding:26px;line-height:1.55}.ok{color:#087f5b;font-weight:bold}.bad{color:#b91c1c;font-weight:bold}code{background:#f1f5f9;padding:2px 5px}</style></head><body><div class="box"><h1>WhatsApp Send Gateway</h1><p class="'.($connected ? 'ok' : 'bad').'">'.($connected ? 'WhatsApp is linked and ready.' : 'WhatsApp is not linked yet.').'</p><p>This endpoint is used by the invoice system with <code>POST</code>. Opening it directly in the browser does not send a message.</p></div></body></html>';
    exit;
}


$url = $nodeUrl . $path . '?' . http_build_query($queryParts);
$headers = ['Accept: ' . ($_SERVER['HTTP_ACCEPT'] ?? '*/*')];
if (!empty($_SERVER['CONTENT_TYPE'])) {
    $headers[] = 'Content-Type: ' . $_SERVER['CONTENT_TYPE'];
}
$headers[] = 'Authorization: Bearer ' . $token;
$body = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) ? (string) file_get_contents('php://input') : '';
$response = wa_gateway_http($url, $method, $headers, $body, 30);

if (!$response['ok'] || $response['status'] === 0) {
    $start = wa_try_start_gateway($gatewayDir, $token, $nodePort, $logFile);
    if ($start['ok']) {
        usleep(900000);
        $response = wa_gateway_http($url, $method, $headers, $body, 30);
    } elseif ($response['error'] === '') {
        $response['error'] = $start['error'];
    }
}

if ($response['ok'] && $response['status'] > 0) {
    http_response_code($response['status']);
    foreach (explode("\r\n", $response['headers']) as $line) {
        if (stripos($line, 'Content-Type:') === 0 || stripos($line, 'Cache-Control:') === 0) {
            header($line, true);
        }
    }
    echo $response['body'];
    exit;
}

$wantsJson = stripos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false || $path === '/send' || $path === '/status';
if ($wantsJson) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'error' => 'WhatsApp Web gateway is not running. Enable cPanel Node.js App / Passenger for public_html/wa-local.',
        'details' => $response['error'],
    ]);
    exit;
}

http_response_code(503);
header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>WhatsApp Gateway</title><style>body{font-family:Arial,sans-serif;background:#f6f7fb;color:#111;display:grid;place-items:center;min-height:100vh;margin:0}.box{max-width:560px;background:#fff;border:1px solid #ddd;padding:26px;line-height:1.55}.bad{color:#b91c1c;font-weight:bold}code{background:#f1f5f9;padding:2px 5px}</style></head><body><div class="box"><h1>WhatsApp Web Gateway</h1><p class="bad">Gateway service is not running.</p><p>The system tried to start the local gateway automatically. If this message stays visible, activate cPanel Node.js App / Passenger for <code>public_html/wa-local</code>, startup file <code>server.js</code>.</p><p><small>'.htmlspecialchars($response['error'], ENT_QUOTES, 'UTF-8').'</small></p></div></body></html>';
