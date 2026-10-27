<?php
// A narrow send endpoint for this server's own tools (for example the cPanel password panel's login codes):
// POST ?ext_send=<name> with "Authorization: Bearer <token>" and JSON {to, message}. The message goes out from the system's
// main number only, each tool has its own token (secret ext_send_token:<name>) and a limit per hour; off unless ext_send_on is set.

function wf_ext_send(string $name): void {
    header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
    $out = function (int $code, array $a): void { http_response_code($code); echo json_encode($a); exit; };
    $name = preg_replace('/[^a-z0-9_-]/', '', strtolower($name));
    if (!wi_sys('ext_send_on') || $name === '' || $_SERVER['REQUEST_METHOD'] !== 'POST') $out(404, ['ok' => false]);
    $tok = wi_secret('ext_send_token:' . $name); $hd = function_exists('getallheaders') ? array_change_key_case((array) getallheaders()) : []; $sent = (string) preg_replace('/^Bearer\s+/i', '', (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ($hd['authorization'] ?? ($hd['x-ext-token'] ?? ''))));
    if (strlen($tok) < 32 || !hash_equals($tok, $sent)) { wi_log('ext_send_denied', ['tool' => $name]); $out(401, ['ok' => false]); }
    $in = json_decode((string) file_get_contents('php://input', false, null, 0, 8000), true) ?: [];
    $to = wi_norm_phone((string) ($in['to'] ?? '')); $msg = trim(mb_substr((string) ($in['message'] ?? ''), 0, 600));
    if ($to === '' || $msg === '') $out(400, ['ok' => false, 'error' => 'to and message are needed']);
    $max = max(1, (int) (wi_sys('ext_send_hour') ?: 20)); $many = false;
    wi_update('ext_send.json', function ($S) use ($name, $max, &$many) { $t = array_values(array_filter((array) ($S[$name] ?? []), fn($x) => $x > time() - 3600)); if (count($t) >= $max) $many = true; else $t[] = time(); $S[$name] = $t; return $S; });
    if ($many) $out(429, ['ok' => false, 'error' => 'hourly limit']);
    $ok = wi_send_whatsapp($to, $msg);
    wi_log('ext_send', ['tool' => $name, 'ok' => $ok]);
    $out($ok ? 200 : 502, ['ok' => $ok]);
}
