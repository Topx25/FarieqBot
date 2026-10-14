<?php
// WhatsApp Inbox — meetings (split out of features.php in v3.8, code unchanged)

/* ---------- Meetings: a gateway in front of Google Meet (fixed link, password, host opens, limit) ---------- */
/** A meeting this user may change: super, a meeting of one of their numbers, or one they created. */
/** Text of the public meeting pages, in the language of the system messages (Settings → System → msg_lang). */
function wf_mt(string $en): string { static $d = null; if (wi_sys('msg_lang') === 'en') return $en; $d ??= (array) require dirname(__DIR__) . '/i18n.php'; return (string) ($d[$en] ?? $en); }
/** A meeting of a private number is only for that number's owner and team (super admin included). */
/** A meeting belongs to its number: only users who work on that number (wi_my_accounts) reach it — never another client or the operator.
 *  A meeting whose number is gone: its creator only (an old one without a number: the operator's super admin too). */
function wf_meet_acc_ok(array $m): bool { $id = (string) ($m['acc'] ?? ''); $a = wi_accounts()[$id] ?? null;
    if ($a) return wi_may_see_account($a) && (wi_is_super() || isset(wi_my_accounts()[$id]));
    return ($id === '' && wi_is_super()) || (strtolower((string) ($m['by'] ?? '')) !== '' && strtolower((string) ($m['by'] ?? '')) === strtolower((string) ($_SESSION['email'] ?? ''))); }
function wf_meeting_mine(array $m): bool { if (!wf_meet_acc_ok($m)) return false; return wi_is_super() || isset(wi_my_accounts()[$m['acc'] ?? '']) || strtolower((string) ($m['by'] ?? '')) === strtolower((string) ($_SESSION['email'] ?? '')); }
function wf_meetings(): array { return wi_read('meetings.json', []); }
function wf_meet_public(array $m): array { return array_intersect_key($m, array_flip(['id', 'slug', 'title', 'desc', 'mode', 'limit', 'hosts', 'status', 'opened_at', 'opened_by', 'closed_at', 'created', 'by', 'ask_phone', 'record', 'transcript', 'space', 'rec_note', 'approve', 'auto_close'])) + ['has_password' => !empty($m['pass']), 'has_link' => !empty($m['url']), 'joins' => count($m['joins'] ?? []), 'pending' => count(array_filter($m['joins'] ?? [], fn($j) => ($j['state'] ?? '') === 'pending' && $j['at'] >= ($m['opened_at'] ?? ''))), 'joined_now' => count(array_filter($m['joins'] ?? [], fn($j) => !empty($m['opened_at']) && $j['at'] >= $m['opened_at']))]; }
function wf_meet_wa_hosts(array $m): array { return array_map(fn($h) => ['phone' => $h['phone'], 'name' => $h['name'] ?? ''], (array) ($m['wa_hosts'] ?? [])); }
/** A host from WhatsApp (no dashboard login) uses a personal link: ?m=slug&h=token */
function wf_meet_wa_host(array $m, string $tok): ?array { if ($tok === '') return null; foreach ((array) ($m['wa_hosts'] ?? []) as $h) if (hash_equals((string) $h['token'], $tok)) return $h; return null; }
function wf_meet_can_host(array $m): bool { if (!wf_meet_acc_ok($m)) return false; return wi_is_super() || ((wi_is_admin() || (wi_me()['role'] ?? '') === 'client') && (isset(wi_my_accounts()[$m['acc'] ?? '']) || strtolower((string) ($m['by'] ?? '')) === strtolower((string) ($_SESSION['email'] ?? '')))) || in_array(strtolower((string) ($_SESSION['email'] ?? '')), array_map('strtolower', (array) ($m['hosts'] ?? [])), true); }
/** Turns automatic recording / transcript on or off for a Meet space (Google Meet API). */
function wf_meet_space_cfg(string $space, string $url, bool $rec, bool $tr): array {
    $c = wf_drive_cfg();
    if (empty($c['refresh_token'])) return ['ok' => false, 'error' => 'Connect your Google account first.'];
    if (strpos((string) ($c['scopes'] ?? ''), 'meetings.space') === false) return ['ok' => false, 'error' => 'Reconnect Google (Disconnect → Connect) and allow “Google Meet” so recording can be switched on automatically.'];
    $t = wf_drive_token(); if (empty($t['ok'])) return $t;
    if ($space === '' && $url !== '') { // a link made elsewhere: find its space (works only for meetings this Google account created)
        $code = preg_replace('#^https://meet\.google\.com/#', '', strtok($url, '?'));
        $g = wf_drive_req('GET', 'https://meet.googleapis.com/v2/spaces/' . rawurlencode($code), $t['token']);
        $space = (string) ($g['body']['name'] ?? '');
        if ($space === '') return ['ok' => false, 'error' => 'This Meet link was not made by the connected Google account — press “Create automatically” to make one that can record.'];
    }
    $cfg = ['artifactConfig' => ['recordingConfig' => ['autoRecordingGeneration' => $rec ? 'ON' : 'OFF'], 'transcriptionConfig' => ['autoTranscriptionGeneration' => $tr ? 'ON' : 'OFF']]];
    $r = wf_drive_req('PATCH', 'https://meet.googleapis.com/v2/' . $space . '?updateMask=config.artifactConfig.recordingConfig.autoRecordingGeneration,config.artifactConfig.transcriptionConfig.autoTranscriptionGeneration', $t['token'], json_encode(['config' => $cfg]), ['Content-Type: application/json']);
    if ($r['code'] >= 200 && $r['code'] < 300) return ['ok' => true, 'space' => $space];
    $msg = is_array($r['body']) ? (string) ($r['body']['error']['message'] ?? '') : '';
    return ['ok' => false, 'error' => 'Google refused automatic recording (HTTP ' . $r['code'] . ($msg ? ': ' . $msg : '') . '). Recording needs a Google Workspace plan that includes Meet recording — a free Gmail account cannot record.'];
}
function wf_meet_enter(array $m, string $name): void {
    $e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
            $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
            if (!preg_match('/Android|iPhone|iPad|iPod|Mobile/i', $ua)) { header('Location: ' . $m['url']); exit; }
            // Phones: buttons for the Meet app (Android intent / iOS tap on the link) or the browser.
            $code = preg_replace('#^https://meet\.google\.com/#', '', (string) $m['url']);
            $android = 'intent://meet.google.com/' . $code . '#Intent;scheme=https;package=com.google.android.apps.meetings;S.browser_fallback_url=' . rawurlencode((string) $m['url']) . ';end';
            $app = preg_match('/Android/i', $ua) ? $android : (string) $m['url'];
            echo '<!doctype html><html lang="' . (wi_sys('msg_lang') === 'en' ? 'en" dir="ltr' : 'ar" dir="rtl') . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $e($m['title']) . '</title><style>body{margin:0;min-height:100vh;display:grid;place-items:center;font:16px/1.7 Cairo,Tahoma,system-ui,sans-serif;background:linear-gradient(135deg,#ecfdf5,#eef2ff);color:#0f172a}.c{width:min(420px,92vw);background:#fff;border-radius:20px;padding:28px;text-align:center;box-shadow:0 20px 50px -20px rgba(15,23,42,.35)}a{display:block;margin-top:14px;padding:14px;border-radius:14px;font-weight:800;text-decoration:none}.a{background:linear-gradient(135deg,#12b886,#0c8f68);color:#fff}.b{background:#f1f5f9;color:#0f172a}.m{color:#64748b;font-size:13.5px}</style></head><body><div class="c"><div style="font-size:44px">🎥</div><h2>' . $e($m['title']) . '</h2><p>' . wf_mt('Hello') . ' ' . $e($name) . ' ' . wf_mt('— you can join now') . '</p>'
                . '<a class="a" href="' . $e($app) . '">' . wf_mt('📱 Open in the Google Meet app') . '</a><a class="b" href="' . $e($m['url']) . '" target="_blank" rel="noopener">' . wf_mt('🌐 Open in the browser') . '</a><p class="m">' . wf_mt('If the app is not installed, it opens in the browser or asks you to install it.') . '</p></div></body></html>';
    exit;
}
function wf_meet_pending_html(array $m, callable $e): string {
    $p = array_values(array_filter($m['joins'] ?? [], fn($j) => ($j['state'] ?? '') === 'pending' && $j['at'] >= ($m['opened_at'] ?? '')));
    if (!$p) return '<p class="m">' . wf_mt('Nobody is waiting for approval right now.') . '</p>';
    $h = '<h3 style="margin:16px 0 4px">' . wf_mt('⏳ Waiting for your approval (')  . count($p) . ')</h3>';
    foreach ($p as $j) $h .= '<form method="post" style="display:flex;gap:6px;align-items:center;border-top:1px solid #eee;padding:6px 0;text-align:right"><span style="flex:1">' . $e($j['name']) . ($j['phone'] ? ' <small dir="ltr">+' . $e($j['phone']) . '</small>' : '') . '</span><input type="hidden" name="j" value="' . $e($j['id']) . '"><button style="width:auto;margin:0;padding:6px 12px" class="a" name="do" value="ok">✔</button><button style="width:auto;margin:0;padding:6px 12px" class="b" name="do" value="no">✖</button></form>';
    return $h . '<form method="post"><button class="a" name="do" value="okall">' . wf_mt('✔ Let everyone in') . '</button></form>';
}
/** Public page for one meeting (no login): waiting room, name/password form, then redirect to Meet. */
function wf_meet_page(string $slug): void {
    $all = wf_meetings(); $m = null; foreach ($all as $x) if (($x['slug'] ?? '') === $slug) $m = $x;
    header('Content-Type: text/html; charset=utf-8'); header('X-Robots-Tag: noindex'); header('Referrer-Policy: no-referrer');
    $e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
 $err = ''; $state = 'form';
    // A meeting closes by itself some hours after it was opened, so an old link never lets people in alone.
    if ($m && ($m['status'] ?? '') === 'open' && !empty($m['opened_at']) && strtotime($m['opened_at']) < time() - 3600 * max(1, (int) ($m['auto_close'] ?? 3))) {
        foreach ($all as &$x) if ($x['id'] === $m['id']) { $x['status'] = 'closed'; $x['closed_at'] = date('c'); $m = $x; } unset($x); wi_write('meetings.json', $all);
    }
    $host = $m ? wf_meet_wa_host($m, (string) ($_GET['h'] ?? '')) : null;
    if ($host) {
        // Personal host page: open/close the meeting, then go to Meet.
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            foreach ($all as &$x) if ($x['id'] === $m['id']) {
                if (in_array($_POST['do'] ?? '', ['ok', 'no', 'okall'], true)) foreach ($x['joins'] as &$j) if (($j['state'] ?? '') === 'pending' && ($_POST['do'] === 'okall' || ($j['id'] ?? '') === ($_POST['j'] ?? ''))) $j['state'] = $_POST['do'] === 'no' ? 'rejected' : 'approved';
                unset($j);
                if (($_POST['do'] ?? '') === 'open' && !empty($x['url'])) { $x['status'] = 'open'; $x['opened_at'] = date('c'); $x['opened_by'] = ($host['name'] ?: '+' . $host['phone']) . ' (WhatsApp)'; }
                elseif (($_POST['do'] ?? '') === 'close') { $x['status'] = 'closed'; $x['closed_at'] = date('c'); }
                $m = $x;
            }
            unset($x); wi_write('meetings.json', $all);
            if (($_POST['do'] ?? '') === 'open' && !empty($m['url'])) { header('Location: ' . $m['url']); exit; }
        }
        $open = ($m['status'] ?? '') === 'open';
        echo '<!doctype html><html lang="' . (wi_sys('msg_lang') === 'en' ? 'en" dir="ltr' : 'ar" dir="rtl') . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $e($m['title']) . '</title><style>body{margin:0;min-height:100vh;display:grid;place-items:center;font:16px/1.7 Cairo,Tahoma,system-ui,sans-serif;background:linear-gradient(135deg,#ecfdf5,#eef2ff);color:#0f172a}.c{width:min(420px,92vw);background:#fff;border-radius:20px;padding:28px;text-align:center;box-shadow:0 20px 50px -20px rgba(15,23,42,.35)}button,a{display:block;width:100%;box-sizing:border-box;margin-top:12px;padding:14px;border:0;border-radius:14px;font:inherit;font-weight:800;text-decoration:none;cursor:pointer}.a{background:linear-gradient(135deg,#12b886,#0c8f68);color:#fff}.b{background:#fee2e2;color:#b91c1c}.m{color:#64748b;font-size:13.5px}</style></head><body><div class="c"><div style="font-size:44px">🎙️</div><h2>' . $e($m['title']) . '</h2><p>' . wf_mt('Hello') . ' ' . $e($host['name'] ?: '') . ' ' . wf_mt('— you are one of the meeting hosts.') . '</p>'
            . '<p class="m">' . ($open ? '' . wf_mt('🟢 The meeting is open now') . '' : '' . wf_mt('⚪ The meeting is closed — people wait until you open it') ) . '</p>'
            . (empty($m['url']) ? '<p class="m">' . wf_mt('No Google Meet link has been added yet.') . '</p>' : ($open ? '<a class="a" href="' . $e($m['url']) . '">' . wf_mt('🎥 Join Google Meet') . '</a><form method="post"><button class="b" name="do" value="close">' . wf_mt('⏹ Close the meeting') . '</button></form>' : '<form method="post"><button class="a" name="do" value="open">' . wf_mt('▶️ Open the meeting and join') . '</button></form>'))
            . ($open && !empty($m['approve']) ? wf_meet_pending_html($m, $e) : '')
            . '<p class="m">' . wf_mt('This link is yours only — do not share it.') . '</p></div>' . ($open ? '<script>setTimeout(function(){location.reload()},10000)</script>' : '') . '</body></html>';
        exit;
    }
    if (!$m) { $state = 'missing'; }
    elseif (($m['status'] ?? 'closed') !== 'open') { $state = 'waiting'; }
    elseif (!empty($m['limit']) && count(array_filter($m['joins'] ?? [], fn($j) => $j['at'] >= $m['opened_at'])) >= (int) $m['limit']) { $state = 'full'; }
    if ($m && $state === 'form' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // Attempt limit: 8 wrong passwords per device per 15 minutes.
        $ipk = substr(hash('sha256', wi_ip() . '|' . $slug), 0, 20); $G = wi_read('meet_guard.json', []);
        $G[$ipk] = array_values(array_filter($G[$ipk] ?? [], fn($t) => $t > time() - 900));
        $name = trim(mb_substr((string) ($_POST['name'] ?? ''), 0, 60)); $phone = preg_replace('/\D/', '', (string) ($_POST['phone'] ?? ''));
        if (count($G[$ipk]) >= 8) $err = '' . wf_mt('Too many attempts — try again in 15 minutes.') . '';
        elseif ($name === '') $err = '' . wf_mt('Write your name.') . '';
        elseif (!empty($m['ask_phone']) && strlen($phone) < 8) $err = '' . wf_mt('Write your mobile number.') . '';
        elseif (($m['mode'] ?? 'auto') === 'password' && !password_verify((string) ($_POST['pass'] ?? ''), (string) ($m['pass'] ?? ''))) { $err = '' . wf_mt('Wrong password.') . ''; $G[$ipk][] = time(); }
        wi_write('meet_guard.json', $G);
        if ($err === '') {
            $jid = bin2hex(random_bytes(8)); $ap = !empty($m['approve']);
            foreach ($all as &$x) if ($x['id'] === $m['id']) { $x['joins'][] = ['id' => $jid, 'name' => $name, 'phone' => $phone, 'at' => date('c'), 'ip' => substr(hash('sha256', wi_ip()), 0, 12), 'state' => $ap ? 'pending' : 'approved']; $x['joins'] = array_slice($x['joins'], -2000); }
            unset($x); wi_write('meetings.json', $all);
            if ($ap) { setcookie('mj_' . $m['id'], $jid, ['expires' => time() + 6 * 3600, 'path' => (string) wi_sys('cookie_path'), 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']); header('Location: ?m=' . rawurlencode($slug)); exit; }
            wf_meet_enter($m, $name); }
    }
    // Guest already asked to join: wait for the host's decision.
    $mine = $m ? (string) ($_COOKIE['mj_' . $m['id']] ?? '') : '';
    if ($m && $mine !== '' && ($m['status'] ?? '') === 'open') foreach ($m['joins'] ?? [] as $j) if (($j['id'] ?? '') === $mine && $j['at'] >= $m['opened_at']) {
        if ($j['state'] === 'approved') wf_meet_enter($m, $j['name']);
        $state = $j['state'] === 'rejected' ? 'rejected' : 'pending';
    }
    $title = $m ? $m['title'] : wf_mt('Meeting');
    echo '<!doctype html><html lang="' . (wi_sys('msg_lang') === 'en' ? 'en" dir="ltr' : 'ar" dir="rtl') . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $e($title) . '</title>'
        . ($state === 'waiting' || $state === 'pending' ? '<meta http-equiv="refresh" content="' . ($state === 'pending' ? 5 : 15) . '">' : '')
        . '<style>body{margin:0;min-height:100vh;display:grid;place-items:center;font:15px/1.7 Cairo,Tahoma,system-ui,sans-serif;background:linear-gradient(135deg,#ecfdf5,#eef2ff);color:#0f172a}.c{width:min(420px,92vw);background:#fff;border-radius:20px;padding:28px;box-shadow:0 20px 50px -20px rgba(15,23,42,.35)}h1{font-size:21px;margin:0 0 6px}.m{color:#64748b;font-size:13.5px}label{display:block;margin:12px 0 4px;font-weight:700;font-size:13.5px}input{width:100%;box-sizing:border-box;padding:11px 12px;border:1px solid #e2e8f0;border-radius:12px;font:inherit}button{margin-top:16px;width:100%;padding:12px;border:0;border-radius:12px;background:linear-gradient(135deg,#12b886,#0c8f68);color:#fff;font:inherit;font-weight:800;cursor:pointer}.e{background:#fef2f2;color:#b91c1c;padding:8px 12px;border-radius:10px;margin-top:10px}.w{font-size:44px;text-align:center}</style></head><body><div class="c">'
        . '<div class="w">' . ['missing' => '❔', 'waiting' => '⏳', 'full' => '🚪', 'form' => '🎥', 'pending' => '🙋', 'rejected' => '🚫'][$state] . '</div><h1>' . $e($title) . '</h1>' . (!empty($m['desc']) ? '<p class="m">' . nl2br($e($m['desc'])) . '</p>' : '');
    if ($state === 'missing') echo '<p>' . wf_mt('This link is wrong or the meeting was cancelled.') . '</p>';
    elseif ($state === 'waiting') echo '<p>' . wf_mt('The meeting has not started yet — waiting for the host to open it.') . '</p><p class="m">' . wf_mt('This page refreshes by itself every 15 seconds.') . '</p>';
    elseif ($state === 'pending') echo '<p>' . wf_mt('Your request was sent ✅ — wait until the host lets you in.') . '</p><p class="m">' . wf_mt('This page refreshes by itself; you join as soon as the host agrees.') . '</p>';
    elseif ($state === 'rejected') echo '<p>' . wf_mt('The host did not let you into this meeting.') . '</p>';
    elseif ($state === 'full') echo '<p>' . wf_mt('The meeting is full.') . '</p>';
    else echo '<form method="post"><label>' . wf_mt('Name') . '</label><input name="name" required maxlength="60" value="' . $e($_POST['name'] ?? '') . '">'
        . (!empty($m['ask_phone']) ? '<label>' . wf_mt('Mobile number') . '</label><input name="phone" inputmode="tel" dir="ltr" required value="' . $e($_POST['phone'] ?? '') . '">' : '')
        . (($m['mode'] ?? 'auto') === 'password' ? '<label>' . wf_mt('Password') . '</label><input name="pass" type="password" required autocomplete="off">' : '')
        . ($err ? '<div class="e">' . $e($err) . '</div>' : '') . '<button>' . wf_mt('Join the meeting') . '</button></form>';
    echo '</div></body></html>'; exit;
}


/** API calls of this area (moved unchanged from the old single switch). */
function wf_api_meetings(string $api): void {
    switch ($api) {
    /* ----- Meetings ----- */
    case 'settings_overview': {
        // One page for the whole system: Google connection, numbers, users, AI, health, report, backup.
        // The operator's system page (its Google connection, AI, users, backup): super admin only, always the operator's own Google.
        wf_admin(); if (!wi_is_super()) wi_json(['ok' => false, 'error' => 'Super admin only.'], 403); wf_google_use(''); $c = wf_drive_cfg(); wf_google_use(null); $acc = [];
        foreach (wi_my_accounts() as $a) { $h = wi_gateway('/health', null, $a); $acc[] = ['id' => $a['id'], 'name' => $a['name'], 'connected' => !empty($h['connected']), 'payments' => !empty($a['payments']), 'bot' => wf_bot_run_cfg((string) $a['id'])['mode']]; }
        $ai = wi_ai_call(['action' => 'ai_list'], 25); $aiOn = count(array_filter($ai['providers'] ?? [], fn($p) => !empty($p['active'])));
        $rep = wi_read('report.json', []);
        wi_json(['ok' => true, 'google' => ['connected' => !empty($c['refresh_token']), 'email' => $c['email'] ?? '', 'calendar' => strpos((string) ($c['scopes'] ?? ''), 'calendar') !== false, 'drive' => strpos((string) ($c['scopes'] ?? ''), 'drive') !== false, 'drive_read' => strpos((string) ($c['scopes'] ?? ''), 'drive.readonly') !== false, 'missing' => $c['missing'] ?? array_values(array_filter(['drive.readonly', 'meetings.space.created'], fn($k) => strpos((string) ($c['scopes'] ?? ''), $k) === false)), 'connected_at' => $c['connected_at'] ?? null, 'meet' => strpos((string) ($c['scopes'] ?? ''), 'meetings.space') !== false, 'client_id' => $c['client_id'] ?? '', 'has_secret' => !empty($c['client_secret']), 'redirect' => wf_drive_redirect(), 'pending' => !empty($c['state'])],
            'accounts' => $acc, 'users' => count(wi_users()), 'ai' => ['active' => $aiOn, 'total' => count($ai['providers'] ?? []), 'mode' => $ai['mode'] ?? ''],
            'report' => ['enabled' => !empty($rep['enabled']), 'hour' => $rep['hour'] ?? 21], 'backup' => ['enabled' => !empty($c['enabled']), 'last' => $c['last'] ?? null],
            'version' => (((array) require dirname(__DIR__) . '/version.php')[0]['v'] ?? ''), 'ui_theme' => wi_config()['ui_theme'] ?? 'google', 'ui_colors' => wi_config()['ui_colors'] ?? null, 'palette' => WF_UI_PALETTE, 'canvases' => WF_UI_CANVAS]);
    }
    case 'acc_invoices': {
        // Invoices on/off for one number: payment-receipt bot, sending invoices from a chat, customer invoices, overdue reminders.
        $in = wf_post(); $id = (string) ($in['acc'] ?? ''); $acc = wi_accounts()[$id] ?? null;
        if (!$acc || !wi_is_admin() || !wi_can_manage($acc)) wi_json(['ok' => false, 'error' => 'Not allowed for this number.'], 403);
        $on = !empty($in['on']);
        if (!empty($acc['main'])) { $c = wi_config(); $c['main_payments'] = $on; wi_write('config.json', $c); }
        else { $all = wi_accounts(); $all[$id]['payments'] = $on; wi_save_accounts($all); }
        wi_log('invoices_' . ($on ? 'on' : 'off'), ['account' => $id]); wi_json(['ok' => true, 'on' => $on]);
    }
    case 'ui_colors': {
        // Studio theme: accent from the checked palette (each comes with its chart partner) + a background tone.
        $in = wf_post(); if (!wi_is_super()) wi_json(['ok' => false, 'error' => 'Only the super admin can change the colours.'], 403);
        $p = WF_UI_PALETTE[(int) ($in['accent'] ?? 0)] ?? WF_UI_PALETTE[0]; $cv = WF_UI_CANVAS[(int) ($in['canvas'] ?? 0)] ?? WF_UI_CANVAS[0];
        $c = wi_config(); $c['ui_colors'] = ['acc' => $p[1], 'acc2' => $p[2], 'canvas' => $cv[1], 'accent_i' => (int) ($in['accent'] ?? 0), 'canvas_i' => (int) ($in['canvas'] ?? 0)];
        if (!empty($in['apply'])) $c['ui_theme'] = 'studio';
        wi_write('config.json', $c); wi_log('ui_colors', $c['ui_colors']); wi_json(['ok' => true]);
    }
    case 'ui_theme': {
        // Super admin chooses the dashboard theme for everyone.
        $in = wf_post(); if (!wi_is_super()) wi_json(['ok' => false, 'error' => 'Only the super admin can change the theme.'], 403);
        $t = in_array($in['theme'] ?? '', ['google', 'classic', 'orchid', 'studio'], true) ? $in['theme'] : 'google';
        $c = wi_config(); $c['ui_theme'] = $t; wi_write('config.json', $c); wi_log('ui_theme', ['theme' => $t]);
        wi_json(['ok' => true]);
    }
    case 'meetings': {
        $all = wf_meetings(); $out = [];
        $myAcc = wi_my_accounts();
        foreach ($all as $m) if (wf_meet_acc_ok($m) && (wi_is_super() || isset($myAcc[$m['acc'] ?? '']) || strtolower((string) ($m['by'] ?? '')) === strtolower((string) $_SESSION['email']) || wf_meet_can_host($m))) $out[] = wf_meet_public($m) + ['wa_hosts' => wf_meet_wa_hosts($m), 'can_host' => wf_meet_can_host($m), 'url' => wf_meet_can_host($m) ? ($m['url'] ?? '') : ''];
        $users = ($ca = wi_current()) ? wi_account_people($ca) : []; // hosts to pick from: only people who work on this number
        wi_json(['ok' => true, 'meetings' => array_reverse($out), 'users' => $users, 'base' => wi_sys('base_url') . '?m=']);
    }
    case 'meeting_save': {
        $in = wf_post(); wf_admin(); $all = wf_meetings(); $id = (string) ($in['id'] ?? '');
        // Only meetings of this user's numbers (or their own) can be changed or deleted.
        foreach ($all as $x) if ($x['id'] === $id && !wf_meeting_mine($x)) wi_json(['ok' => false, 'error' => 'This meeting belongs to another number.'], 403);
        if (($in['do'] ?? '') === 'delete') { $all = array_values(array_filter($all, fn($x) => $x['id'] !== $id)); wi_write('meetings.json', $all); wi_json(['ok' => true]); }
        $url = trim((string) ($in['url'] ?? ''));
        if ($url !== '' && !preg_match('#^https://meet\.google\.com/[a-z0-9\-]+(\?[\w=&.%-]*)?$#i', $url)) wi_json(['ok' => false, 'error' => 'Paste a Google Meet link (https://meet.google.com/…).']);
        $slug = strtolower(trim(preg_replace('/[^a-z0-9-]+/i', '-', (string) ($in['slug'] ?? '')), '-'));
        if ($slug === '') $slug = substr(bin2hex(random_bytes(4)), 0, 8);
        foreach ($all as $x) if ($x['slug'] === $slug && $x['id'] !== $id) wi_json(['ok' => false, 'error' => 'This link name is already used.']);
        $idx = null; foreach ($all as $i => $x) if ($x['id'] === $id) $idx = $i;
        if ($idx !== null && !(wi_is_super() && wf_meet_acc_ok($all[$idx])) && !isset(wi_my_accounts()[$all[$idx]['acc'] ?? '']) && strtolower((string) ($all[$idx]['by'] ?? '')) !== strtolower((string) $_SESSION['email'])) wi_json(['ok' => false, 'error' => 'This meeting belongs to another number.'], 403);
        if ($idx === null && wf_aid() === '') wi_json(['ok' => false, 'error' => 'No WhatsApp account available for this user.'], 403); // a new meeting always belongs to a number
        if ($idx === null) { $all[] = ['id' => bin2hex(random_bytes(6)), 'created' => date('c'), 'by' => $_SESSION['email'], 'acc' => wf_aid(), 'status' => 'closed', 'joins' => []]; $idx = count($all) - 1; }
        $m = $all[$idx];
        $m['title'] = mb_substr(trim((string) ($in['title'] ?? '')), 0, 120) ?: 'Meeting'; $m['desc'] = mb_substr(trim((string) ($in['desc'] ?? '')), 0, 600);
        $m['slug'] = $slug; if ($url !== '') $m['url'] = $url;
        $m['mode'] = ($in['mode'] ?? '') === 'password' ? 'password' : 'auto';
        if (trim((string) ($in['pass'] ?? '')) !== '') $m['pass'] = password_hash((string) $in['pass'], PASSWORD_DEFAULT);
        if ($m['mode'] === 'password' && empty($m['pass'])) wi_json(['ok' => false, 'error' => 'Set a password for password mode.']);
        $m['limit'] = max(0, min(1000, (int) ($in['limit'] ?? 0))); $m['ask_phone'] = !empty($in['ask_phone']);
        if (!empty($in['space']) && $url !== '') $m['space'] = (string) $in['space']; elseif ($url !== '' && $url !== ($all[$idx]['url'] ?? '')) unset($m['space']);
        $rec = !empty($in['record']); $tr = !empty($in['transcript']); $m['rec_note'] = '';
        if (($rec || $tr || !empty($m['record']) || !empty($m['transcript'])) && ($rec !== !empty($m['record']) || $tr !== !empty($m['transcript']) || !empty($in['space']))) {
            // Automatic recording / transcript is a setting of the Meet space itself — it starts by itself when the call starts.
            $sp = wf_meet_space_cfg((string) ($m['space'] ?? ''), (string) ($m['url'] ?? ''), $rec, $tr);
            if (!empty($sp['ok'])) $m['space'] = $sp['space']; else $m['rec_note'] = $sp['error'];
        }
        $m['record'] = $rec; $m['transcript'] = $tr;
        $m['approve'] = !empty($in['approve']); $m['auto_close'] = max(1, min(24, (int) ($in['auto_close'] ?? 3)));
        $m['hosts'] = array_values(array_filter(array_map('strval', (array) ($in['hosts'] ?? []))));
        $old = []; foreach ((array) ($m['wa_hosts'] ?? []) as $h) $old[$h['phone']] = $h;
        $m['wa_hosts'] = [];
        foreach ((array) ($in['wa_hosts'] ?? []) as $h) { $ph = wf_phone((string) ($h['phone'] ?? '')); if ($ph === '' || isset($m['wa_hosts'][$ph])) continue;
            $m['wa_hosts'][$ph] = ['phone' => $ph, 'name' => mb_substr(trim((string) ($h['name'] ?? '')), 0, 60), 'token' => $old[$ph]['token'] ?? bin2hex(random_bytes(12))]; }
        $m['wa_hosts'] = array_values(array_slice($m['wa_hosts'], 0, 30));
        $all[$idx] = $m; wi_write('meetings.json', $all); wi_log('meeting_save', ['slug' => $slug]);
        wi_json(['ok' => true]);
    }
    case 'meeting_state': {
        $in = wf_post(); $all = wf_meetings(); $idx = null;
        foreach ($all as $i => $m) if ($m['id'] === ($in['id'] ?? '')) $idx = $i;
        if ($idx === null) wi_json(['ok' => false, 'error' => 'Not found.']);
        $m = $all[$idx];
        if (!wf_meet_can_host($m)) wi_json(['ok' => false, 'error' => 'Only the host(s) of this meeting can open or close it.'], 403);
        if (($in['do'] ?? '') === 'open') { if (empty($m['url'])) wi_json(['ok' => false, 'error' => 'Add the Google Meet link first.']); $m['status'] = 'open'; $m['opened_at'] = date('c'); $m['opened_by'] = $_SESSION['email']; }
        else { $m['status'] = 'closed'; $m['closed_at'] = date('c'); }
        $all[$idx] = $m; wi_write('meetings.json', $all); wi_log('meeting_' . ($in['do'] ?? ''), ['id' => $m['id']]);
        wi_json(['ok' => true, 'url' => ($in['do'] ?? '') === 'open' ? $m['url'] : '']);
    }
    case 'meeting_create_link': {
        // A new Google Meet link made through Google Calendar (needs Google connected with calendar access).
        $in = wf_post(); wf_admin();
        $c = wf_drive_cfg();
        if (empty($c['refresh_token'])) wi_json(['ok' => false, 'error' => wf_google_ws() !== '' ? 'Connect your Google account first (My account → Google account).' : 'Connect your Google account first (Health, report & backup → Google Drive backup).', 'need' => 'connect']);
        if (!empty($c['scopes']) && strpos((string) $c['scopes'], 'calendar') === false) wi_json(['ok' => false, 'error' => 'Your Google connection has no calendar access yet — press “Disconnect” then “Connect Google Drive” again and allow the calendar.', 'need' => 'reconnect']);
        $t = wf_drive_token(); if (empty($t['ok'])) wi_json($t);
        if (strpos((string) ($c['scopes'] ?? ''), 'meetings.space') !== false) {
            $r = wf_drive_req('POST', 'https://meet.googleapis.com/v2/spaces', $t['token'], json_encode(['config' => ['accessType' => 'TRUSTED']]), ['Content-Type: application/json']);
            if (!empty($r['body']['meetingUri'])) { wi_log('meeting_link_created', ['via' => 'meet']); wi_json(['ok' => true, 'url' => $r['body']['meetingUri'], 'space' => $r['body']['name'] ?? '']); }
        }
        $title = mb_substr(trim((string) ($in['title'] ?? 'Meeting')), 0, 120) ?: 'Meeting';
        $start = time() + 300;
        $ev = ['summary' => $title, 'description' => 'Created by ' . wi_sys('brand_name') . ' (meeting gateway).',
            'start' => ['dateTime' => gmdate('Y-m-d\TH:i:s\Z', $start)], 'end' => ['dateTime' => gmdate('Y-m-d\TH:i:s\Z', $start + 3600)],
            'conferenceData' => ['createRequest' => ['requestId' => bin2hex(random_bytes(8)), 'conferenceSolutionKey' => ['type' => 'hangoutsMeet']]]];
        $r = wf_drive_req('POST', 'https://www.googleapis.com/calendar/v3/calendars/primary/events?conferenceDataVersion=1&sendUpdates=none', $t['token'], json_encode($ev), ['Content-Type: application/json']);
        $link = (string) ($r['body']['hangoutLink'] ?? '');
        if ($link === '') wi_json(['ok' => false, 'error' => 'Google did not create a Meet link (HTTP ' . $r['code'] . ': ' . (is_array($r['body']) ? ($r['body']['error']['message'] ?? '') : '') . ').', 'need' => $r['code'] === 403 ? 'reconnect' : '']);
        wi_log('meeting_link_created', ['title' => $title]);
        wi_json(['ok' => true, 'url' => $link, 'event' => $r['body']['htmlLink'] ?? '']);
    }
    case 'meeting_approve': {
        $in = wf_post(); $all = wf_meetings();
        foreach ($all as &$x) if ($x['id'] === ($in['id'] ?? '')) { if (!wf_meet_can_host($x)) wi_json(['ok' => false, 'error' => 'Hosts only.'], 403);
            foreach ($x['joins'] as &$j) if (($j['state'] ?? '') === 'pending' && (($in['j'] ?? '') === '*' || ($j['id'] ?? '') === ($in['j'] ?? ''))) $j['state'] = ($in['do'] ?? '') === 'no' ? 'rejected' : 'approved';
            unset($j); wi_write('meetings.json', $all); wi_json(['ok' => true]); }
        wi_json(['ok' => false, 'error' => 'Not found.']);
    }
    case 'meeting_joins': {
        $id = (string) ($_GET['id'] ?? ''); foreach (wf_meetings() as $m) if ($m['id'] === $id) { if (!wf_meet_can_host($m)) wi_json(['ok' => false, 'error' => 'Hosts only.'], 403); wi_json(['ok' => true, 'joins' => array_reverse($m['joins'] ?? []), 'opened_at' => $m['opened_at'] ?? null]); }
        wi_json(['ok' => false, 'error' => 'Not found.']);
    }
    case 'meeting_send': {
        // Sends the gateway link (never the real Meet link) on WhatsApp to chats and/or groups.
        $in = wf_post(); if (!wi_can_reply()) wi_json(['ok' => false, 'error' => 'You do not have permission to reply.'], 403); $text = trim((string) ($in['text'] ?? '')); @set_time_limit(300);
        $jids = array_values(array_unique(array_filter(array_map(fn($j) => wf_jid((string) $j), (array) ($in['jids'] ?? [$in['jid'] ?? ''])))));
        if (!$jids || $text === '') wi_json(['ok' => false, 'error' => 'Choose a chat and write the message.']);
        $sent = 0; $fail = [];
        foreach (array_slice($jids, 0, 60) as $i => $j) { if ($i) usleep(1500000); // gentle pace so WhatsApp doesn't flag the number
            $r = wi_gateway('/chat/send', ['jid' => $j, 'text' => $text, 'by' => (string) $_SESSION['email']]); if (!empty($r['ok'])) $sent++; else $fail[] = $j; }
        wi_json(['ok' => $sent > 0, 'sent' => $sent, 'failed' => $fail, 'error' => $sent ? '' : 'Sending failed.']);
    }
    case 'meeting_host_links': {
        // Each WhatsApp host gets a personal link to open/close the meeting; optionally sent to them now.
        $in = wf_post(); wf_admin();
        foreach (wf_meetings() as $m) if ($m['id'] === ($in['id'] ?? '')) {
            if (!wf_meeting_mine($m)) wi_json(['ok' => false, 'error' => 'This meeting belongs to another number.'], 403);
            $base = wi_sys('base_url') . '?m=' . $m['slug'] . '&h='; $out = []; $sent = 0;
            foreach ((array) ($m['wa_hosts'] ?? []) as $h) {
                $out[] = ['phone' => $h['phone'], 'name' => $h['name'], 'link' => $base . $h['token']];
                if (!empty($in['send']) && (empty($in['phone']) || $in['phone'] === $h['phone'])) {
                    $r = wi_gateway('/chat/send', ['jid' => $h['phone'] . '@s.whatsapp.net', 'text' => wf_msg('meeting_host', ['title' => $m['title'], 'link' => $base . $h['token']]), 'by' => (string) $_SESSION['email']]);
                    if (!empty($r['ok'])) $sent++; usleep(1200000);
                }
            }
            wi_json(['ok' => true, 'hosts' => $out, 'sent' => $sent]);
        }
        wi_json(['ok' => false, 'error' => 'Not found.']);
    }
    case 'meeting_targets': {
        // Groups (and their members) to pick hosts from or to send the link to.
        if (!empty($_GET['group'])) { $mm = wi_gateway('/groups/members?jid=' . rawurlencode(wf_jid($_GET['group'])));
            $names = []; foreach (wi_read('known_members.json', [])[wf_aid()][$_GET['group']]['members'] ?? [] as $k) $names[$k['p']] = $k['n'];
            $out = []; foreach ($mm['members'] ?? [] as $x) { if (!empty($x['is_lid'])) continue; $ph = wf_phone((string) $x['phone']); if ($ph === '') continue; $out[] = ['phone' => $ph, 'name' => (string) ($x['name'] ?? '') ?: ($names[$ph] ?? ''), 'admin' => !empty($x['admin'])]; }
            usort($out, fn($a, $b) => $b['admin'] <=> $a['admin']);
            wi_json(['ok' => true, 'members' => $out]); }
        $r = wi_gateway('/groups'); if (empty($r['ok'])) wi_json($r);
        // Private chats (saved contacts and people we talked to), newest first.
        $ch = wi_gateway('/archive/chats?months=' . (int) wi_sys('months_long') ); $people = [];
        foreach ($ch['chats'] ?? [] as $c) { if (!empty($c['group']) || !preg_match('/@(s\.whatsapp\.net|lid)$/', (string) $c['jid'])) continue;
            $ph = wf_phone((string) ($c['phone'] ?? '')); if ($ph === '' && str_ends_with((string) $c['jid'], '@s.whatsapp.net')) $ph = explode('@', (string) $c['jid'])[0];
            $people[] = ['jid' => $ph !== '' ? $ph . '@s.whatsapp.net' : $c['jid'], 'name' => (string) ($c['name'] ?? ''), 'phone' => $ph, 'last' => $c['last']['time'] ?? ''];
            if (count($people) >= 3000) break; }
        $seen = array_flip(array_column($people, 'phone'));
        foreach ((wi_gateway('/contacts')['contacts'] ?? []) as $c) if (!isset($seen[$c['phone']])) { $seen[$c['phone']] = 1; $people[] = ['jid' => $c['phone'] . '@s.whatsapp.net', 'name' => $c['name'], 'phone' => $c['phone'], 'last' => '']; }
        wi_json(['ok' => true, 'people' => $people, 'groups' => array_values(array_map(fn($g) => ['jid' => $g['jid'], 'name' => $g['name'] ?? $g['subject'] ?? $g['jid'], 'size' => (int) ($g['members'] ?? 0)], array_filter($r['groups'], fn($g) => empty($g['left']))))]);
    }

    }
}
