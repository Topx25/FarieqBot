<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
if (!is_file(WI_DIR . '/installed.json') && !wi_users()) { header('Location: install.php'); exit; } // fresh copy: run the installer first
wi_boot();
if (wi_logged_in() && ($hu = wi_me()) && !wi_host_allows($hu)) { session_destroy(); header('Location: ./'); exit; } // another client's address
// The website (PHP-FPM) and the background jobs run as the account user: the data stays private to it (0700 / 0600).

if (isset($_GET['m']) && !isset($_GET['api'])) { require __DIR__ . '/features.php'; wf_meet_page(preg_replace('/[^a-z0-9-]/', '', strtolower((string) $_GET['m']))); } // public meeting gateway (no login)
if (isset($_GET['bot_hook'])) {
    // Instant bot trigger from the WhatsApp gateway (server-to-server, checked by the bridge token).
    require __DIR__ . '/features.php';
    $tok = trim((string) @file_get_contents(WI_DIR . '/bridge_token'));
    if (strlen($tok) < 32 || !hash_equals($tok, (string) ($_SERVER['HTTP_X_BOT_TOKEN'] ?? ''))) { http_response_code(403); exit; }
    $in = json_decode(wi_input(), true) ?: [];
    $acc = wi_accounts()[(string) ($in['account'] ?? '')] ?? null;
    if ($acc && ($in['cmd'] ?? '') === 'join') { http_response_code(202); header('Content-Length: 0'); flush(); if (function_exists('fastcgi_finish_request')) fastcgi_finish_request(); ignore_user_abort(true); @set_time_limit(120);
        // Members who join close together get ONE welcome: each join waits; the last one (no newer join for 30 s) sends it for all.
        $gj = preg_replace('/[^0-9A-Za-z@.:_-]/', '', (string) ($in['jid'] ?? '')); $k = (string) $acc['id'] . '|' . $gj; $mine = uniqid("", true);
        if (function_exists('wf_gate_joined')) wf_gate_joined($acc, $gj, array_map('strval', (array) ($in['members'] ?? []))); // reception group: the follow-up starts
        if (function_exists('wf_gate_moved')) wf_gate_moved($acc, $gj, array_map('strval', (array) ($in['members'] ?? [])), (string) ($in['by'] ?? '')); // moved from the reception group: the healer opens the case
        wi_update('welcome_queue.json', function ($Q) use ($k, $in, $mine) { $Q[$k]['m'] = array_values(array_unique(array_merge((array) ($Q[$k]['m'] ?? []), array_map('strval', (array) ($in['members'] ?? []))))); $Q[$k]['t'] = $mine; return $Q; });
        sleep(30); $take = [];
        wi_update('welcome_queue.json', function ($Q) use ($k, $mine, &$take) { if ((string) ($Q[$k]['t'] ?? '') === $mine) { $take = (array) ($Q[$k]['m'] ?? []); unset($Q[$k]); } return $Q; });
        if ($take) wf_bot_welcome($acc, $gj, $take); exit; }
    // A shortcut typed on the phone ("#تحصين-المكان"): the gateway edits that message into the full quick reply.
    // A member wrote a keyword (#تحصين) in a group or a private chat: the quick reply with that keyword, within the limits that protect the number.
    // "#حالة" + details in a group (or the writer answering the bot's questions): a case is opened — see modules/case_intake.php
    if ($acc && ($in['cmd'] ?? '') === 'case_intake') { wf_intake_hook($acc, $in); exit; }
    if ($acc && ($in['cmd'] ?? '') === 'keyword') { header('Content-Type: application/json'); $out = ['ok' => false];
        $jid = preg_replace('/[^0-9A-Za-z@.:_-]/', '', (string) ($in['jid'] ?? '')); $kw = wf_norm(preg_replace('/^\s*[#\/]/u', '', (string) ($in['text'] ?? '')));
        $hit = $kw === '' || wi_account_blocked($acc) ? [] : array_values(array_filter(wi_replies_for($acc), fn($r) => ($k = wf_norm((string) ($r['keyword'] ?? ''))) !== '' && $k === $kw));
        if (count($hit) === 1) { $aid = (string) $acc['id']; $now = time(); $ck = $jid . '|' . $hit[0]['id'] . '|' . preg_replace('/\D/', '', (string) ($in['from'] ?? '')); $why = ''; // per person: each member gets the reply once per cooldown
            wi_update('keyword_sent.json', function ($K) use ($aid, $now, $ck, &$why) { $K[$aid]['h'] = array_values(array_filter((array) ($K[$aid]['h'] ?? []), fn($x) => $x > $now - 3600));
                if ((int) ($K[$aid]['c'][$ck] ?? 0) > $now - 60 * (int) wi_sys('keyword_cooldown_min')) $why = 'cooldown';
                elseif (count($K[$aid]['h']) >= (int) wi_sys('keyword_per_hour')) $why = 'hourly limit';
                else { $K[$aid]['h'][] = $now; $K[$aid]['c'][$ck] = $now; foreach ((array) $K[$aid]['c'] as $k => $x) if ($x < $now - 86400) unset($K[$aid]['c'][$k]); }
                return $K; });
            if ($why === '') { $txt = str_replace('{name}', trim((string) ($in['name'] ?? '')), (string) $hit[0]['text']); $out = ['ok' => true, 'text' => wi_reply_fill($txt, $jid, $acc)]; }
            wi_log('keyword_reply', ['account' => $aid, 'jid' => $jid, 'keyword' => $hit[0]['keyword'], 'sent' => $why === '', 'why' => $why]); }
        echo json_encode($out, JSON_UNESCAPED_UNICODE); exit; }
    if ($acc && ($in['cmd'] ?? '') === 'expand') { $jid = preg_replace('/[^0-9A-Za-z@.:_-]/', '', (string) ($in['jid'] ?? ''));
        if (function_exists('wf_media_code_send') && wf_media_code_send($acc, preg_replace('/[^0-9A-Za-z@.:_-]/', '', (string) ($in['jid'] ?? '')), (string) ($in['text'] ?? ''))) { // a file's shortcut typed on the phone: the file goes to that chat
            header('Content-Type: application/json'); echo json_encode(['ok' => false]); exit; }
        if (function_exists('wf_intake_list_text') && mb_strpos((string) ($in['text'] ?? ''), '#') !== false) { $Lw = (array) (wf_case_cfg((string) $acc['id'])['words'] ?? []) + ['list' => '', 'shortcuts' => '', 'programs' => ''];
            if ($Lw['list'] !== '' && wf_intake_has((string) ($in['text'] ?? ''), (string) $Lw['list']) && !wf_is_menu((string) $acc['id'], (string) ($in['text'] ?? '')) && ($lt = wf_intake_list_text($acc, (string) ($in['text'] ?? ''), $Lw)) !== null) { // "#قائمة #المكتبة" typed on the phone becomes the list
                header('Content-Type: application/json'); echo json_encode(['ok' => true, 'text' => $lt, 'title' => 'list'], JSON_UNESCAPED_UNICODE); exit; } }
        if (function_exists('wf_is_menu') && wf_is_menu((string) $acc['id'], (string) ($in['text'] ?? ''))) { // "#menu" typed on the phone becomes the list
            header('Content-Type: application/json'); echo json_encode(['ok' => true, 'text' => wf_cmd_menu((string) $acc['id']), 'title' => 'menu'], JSON_UNESCAPED_UNICODE); exit; }
        $typed = preg_replace('/^\s*[#\/]/u', '', (string) ($in['text'] ?? '')); $kwHit = array_values(array_filter(wi_replies_for($acc), fn($x) => ($k = wf_norm((string) ($x['keyword'] ?? ''))) !== '' && $k === wf_norm($typed)));
        $r = count($kwHit) === 1 ? $kwHit[0] : wi_reply_match(wi_replies_for($acc), $typed); // the members' keyword works for you too
        header('Content-Type: application/json'); echo json_encode($r ? ['ok' => true, 'text' => wi_reply_fill((string) $r['text'], $jid, $acc), 'title' => $r['title']] : ['ok' => false], JSON_UNESCAPED_UNICODE);
        if ($r) wi_log('reply_from_phone', ['account' => $acc['id'], 'jid' => $jid, 'title' => $r['title']]); exit; }
    if ($acc && in_array($in['cmd'] ?? '', ['stop', 'start'], true)) { wf_bot_pause((string) $acc['id'], preg_replace('/[^0-9A-Za-z@.:_-]/', '', (string) ($in['jid'] ?? '')), $in['cmd'] === 'stop', 'WhatsApp (' . (($in['by'] ?? '') === 'team' ? 'team' : 'customer') . ')'); http_response_code(204); exit; }
    if (!$acc || wf_bot_run_cfg((string) $acc['id'])['mode'] === 'off') { http_response_code(204); exit; }
    ignore_user_abort(true); @set_time_limit(240);
    http_response_code(202); header('Content-Length: 0'); header('Connection: close'); flush();
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    wf_bot_tick($acc, true);
    exit;
}
if (isset($_GET['drive_cb'])) { require __DIR__ . '/features.php'; wf_drive_callback(); }
require __DIR__ . '/app/api.php';     // JSON API (?api=…)

require __DIR__ . '/app/actions.php'; // form actions (POST)

$flash = wi_flash();
ob_start('wi_translate_html');
$page = (string) ($_GET['p'] ?? 'dashboard');
// A user who is to link their own number and has none yet starts on the "link a number" page.
if (!wi_my_accounts() && wi_can_link_own() && !wi_is_super() && !in_array($page, ['accounts', 'link', 'changes', 'users', 'me'], true)) {
    if (empty($_SESSION['own_hint'])) { $_SESSION['own_hint'] = 1; wi_flash('Welcome! Link your WhatsApp number to start: add it here, then scan the QR code with WhatsApp on your phone.', 'ok'); }
    header('Location: ?p=accounts'); exit;
}
if (wi_logged_in() && isset($_GET['acc']) && isset(wi_my_accounts()[(string) $_GET['acc']])) $_SESSION['acc'] = (string) $_GET['acc']; // links that open a page for a given number
if ($page === 'clients' && !wi_is_super()) $page = 'dashboard'; // subscriptions are the super admin's
if (!in_array($page, ['business', 'clients', 'me', 'dashboard', 'inbox', 'search', 'users', 'link', 'accounts', 'gateway', 'broadcast', 'analytics', 'library', 'audio', 'changes', 'files', 'media', 'ai', 'cases', 'groups', 'schedule', 'programs', 'status', 'health', 'report', 'backup', 'customer', 'numbers', 'bot', 'botrun', 'botgroup', 'meetings', 'settings', 'learning'], true)) $page = 'dashboard';
$fxPages = ['business', 'learning', 'cases', 'groups', 'schedule', 'programs', 'status', 'health', 'report', 'backup', 'customer', 'numbers', 'bot', 'botrun', 'botgroup', 'meetings', 'settings'];
$autoPages = ['schedule', 'programs', 'status'];
$sysPages = ['health', 'report', 'backup'];
$versions = (array) require __DIR__ . '/version.php';
if (wi_logged_in() && $page !== 'users' && wi_page_perm($page === 'link' || $page === 'gateway' ? 'accounts' : $page) === '' && !in_array($page, ['link', 'gateway', 'changes', 'me'], true)) $page = 'dashboard';
if ($page === 'users' && !wi_can_users()) $page = 'dashboard';
$manager = wi_is_admin() || wi_can_link_own() || (bool) array_filter(wi_my_accounts(), 'wi_can_manage');
if (in_array($page, ['link', 'accounts', 'gateway', 'broadcast'], true) && !$manager && !($page === 'broadcast' && wi_is_team() && wi_page_perm('broadcast') !== '')) $page = 'dashboard';
$cur = wi_current();
$mine = wi_my_accounts();
$me = wi_me();
$v = preg_replace('/[^0-9.]/', '', (string) (((array) require __DIR__ . '/version.php')[0]['v'] ?? '')) . '.' . ((int) @filemtime(__DIR__ . '/app.js') + (int) @filemtime(__DIR__ . '/app.css') + (int) @filemtime(__DIR__ . '/features.js') + (int) @filemtime(__DIR__ . '/theme.css'));
require_once __DIR__ . '/features.php'; // page parts (menu labels, library categories, examples) read the business profile
require __DIR__ . '/app/page.php';    // the page
