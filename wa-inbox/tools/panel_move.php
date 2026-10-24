<?php
// Moves numbers into a client panel (CLI): the client becomes their owner, and what was kept per owner (quick replies, labels,
// team roles) is copied to the new owner, never over what the new owner already has.
// php tools/panel_move.php --client=<email> --numbers=<id,id> [--name=<panel name>] [--create] [--ai-system] [--allow=<type,type>] [--apply]
//   --create     make the client account when it does not exist (no password: the owner sets it in Users)
//   --ai-system  the panel runs on the system's AI keys
//   --allow      business types kept for some panels only that this client may use
// Without --apply it only prints what it would change. With --apply it saves a copy of every store it writes first.
// A super admin is never changed.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/lib.php';
$o = getopt('', ['client:', 'numbers:', 'name:', 'create', 'ai-system', 'allow:', 'apply']);
$email = strtolower(trim((string) ($o['client'] ?? ''))); $apply = isset($o['apply']);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) exit("--client=<email> is required\n");
$nums = array_values(array_filter(array_map('trim', explode(',', (string) ($o['numbers'] ?? '')))));
$allow = array_values(array_filter(array_map(fn($x) => preg_replace('/[^a-z0-9_]/', '', strtolower(trim($x))), explode(',', (string) ($o['allow'] ?? '')))));

$users = wi_users(true); $accounts = wi_read('accounts.json', []); $config = wi_read('config.json', []); $all = wi_accounts(); $log = []; $moves = [];
foreach ($nums as $n) if (!isset($all[$n])) exit("number $n not found — nothing changed\n");
$i = null; foreach ($users as $j => $u) if (strtolower((string) $u['email']) === $email) $i = $j;
if ($i === null) {
    if (!isset($o['create'])) exit("$email not found — add --create to make the client\n");
    $users[] = ['email' => $email, 'name' => (string) ($o['name'] ?? ''), 'role' => 'client', 'active' => true, 'pass' => '', 'created' => date('c'), 'created_by' => 'panel_move'];
    $i = array_key_last($users); $log[] = 'create client ' . $email;
} elseif (($users[$i]['role'] ?? '') === 'super') exit("$email is a super admin — refusing to change it\n");
$old = (string) ($users[$i]['role'] ?? '');
$set = ['role' => 'client', 'active' => true] + (isset($o['name']) ? ['panel_name' => mb_substr((string) $o['name'], 0, 60)] : []) + (isset($o['ai-system']) ? ['ai_system' => true] : []);
foreach ($allow as $t) $set['allow_' . $t] = true;
$users[$i] = $set + $users[$i]; $log[] = $email . ': role ' . ($old ?: '(new)') . ' → client' . (isset($o['name']) ? ', panel «' . $o['name'] . '»' : '') . ($allow ? ', allowed: ' . implode(', ', $allow) : '');
foreach ($nums as $n) {
    if ($n === 'main') { $from = strtolower((string) ($config['main_owner'] ?? '')); $log[] = 'main: owner ' . ($from ?: '(operator)') . ' → ' . $email; $moves[$from] = $email; $config['main_owner'] = $email; continue; }
    foreach ($accounts as $j => $a) if (($a['id'] ?? '') === $n) { $from = strtolower((string) ($a['owner'] ?? '')); $log[] = $n . ': owner ' . ($from ?: '(operator)') . ' → ' . $email; $moves[$from] = $email; $accounts[$j]['owner'] = $email; }
}
// stores kept per owner (wi_ws = the owner's email; '' = the operator's own lists)
$R = (array) wi_read('replies_ws.json', []); $L = (array) wi_read('labels.json', ['defs' => [], 'chats' => []]); $T = (array) wi_read('team_roles.json', []);
foreach ($moves as $from => $to) { if ($from === $to) continue;
    $rep = $from === '' ? (array) wi_read('replies.json', []) : (array) ($R[$from] ?? []); if ($rep && empty($R[$to])) { $R[$to] = $rep; $log[] = 'quick replies ' . ($from ?: 'operator') . ' → ' . $to . ' (' . count($rep) . ')'; }
    $lab = $from === '' ? (array) ($L['defs'] ?? []) : (array) ($L['ws'][$from] ?? []); if ($lab && empty($L['ws'][$to])) { $L['ws'][$to] = $lab; $log[] = 'labels ' . ($from ?: 'operator') . ' → ' . $to . ' (' . count($lab) . ')'; }
    if ($from !== '' && !empty($T[$from]) && empty($T[$to])) { $T[$to] = $T[$from]; $log[] = 'team roles ' . $from . ' → ' . $to; } }
echo implode("\n", $log), "\n";
if (!$apply) exit("dry run — add --apply to save\n");
$bk = WI_DIR . '/backup_panel_' . date('Ymd_His'); @mkdir($bk, 0700, true);
file_put_contents($bk . '/users.json', json_encode(wi_users(true), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
foreach (['accounts.json', 'config.json', 'replies_ws.json', 'labels.json', 'team_roles.json'] as $sf) file_put_contents($bk . '/' . $sf, json_encode(wi_read($sf, []), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
wi_save_users($users); wi_write('accounts.json', $accounts); wi_write('config.json', $config);
wi_write('replies_ws.json', $R); wi_write('labels.json', $L); wi_write('team_roles.json', $T);
wi_log('panel_move', ['client' => $email, 'numbers' => $nums]);
echo "saved; copy of the old files in $bk\n";
