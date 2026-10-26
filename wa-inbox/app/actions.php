<?php
// WhatsApp Inbox — form actions (POST do=…): sign-in, numbers, users, team, clients (moved out of index.php; runs in index.php's scope: $api, wi_* and wf_* are available).
/* ---------------- Actions ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    wi_check_csrf();
    $do = (string) ($_POST['do'] ?? '');
    if (in_array($do, ['signup', 'signup_code', 'forgot', 'forgot_code'], true)) require_once dirname(__DIR__) . '/features.php'; // the message templates and the sign-up helpers
    if ($do === 'signup') {
        if (!wi_turnstile_ok()) { wi_flash('Confirm that you are not a robot, then try again.', 'bad'); header('Location: ./?signup'); exit; }
        $g = (array) ($_SESSION['gsignup'] ?? []); if ($g && time() - (int) $g['at'] > 1800) { unset($_SESSION['gsignup']); $g = []; }
        $in = array_intersect_key($_POST, array_flip(['name', 'business', 'email', 'phone'])); if ($g) $in['email'] = $g['email'];
        $ph = $g ? password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT) : password_hash((string) ($_POST['password'] ?? ''), PASSWORD_DEFAULT);
        $err = wi_signup($g ? $in + ['password_hash' => $ph] : $in + ['password' => (string) ($_POST['password'] ?? '')], false);
        if ($err !== '') { wi_flash($err, 'bad'); header('Location: ./?signup'); exit; }
        $code = (string) random_int(100000, 999999);
        $_SESSION['signup_pending'] = ['in' => $in + ['password_hash' => $ph] + ($g ? ['google' => true] : []), 'code' => password_hash($code, PASSWORD_DEFAULT), 'until' => time() + 600, 'tries' => 0];
        if (!wi_send_whatsapp(wi_norm_phone((string) $in['phone']), wf_msg('signup_code', ['code' => $code, 'minutes' => 10]))) { unset($_SESSION['signup_pending']); wi_flash('Could not send the code on WhatsApp. Check the number and try again.', 'bad'); header('Location: ./?signup'); exit; }
        wi_log('signup_code', ['target' => strtolower((string) $in['email'])]); header('Location: ./?signup'); exit;
    }
    if ($do === 'signup_code') {
        $P = (array) ($_SESSION['signup_pending'] ?? []);
        if (!$P || time() > (int) $P['until'] || (int) $P['tries'] >= 5) { unset($_SESSION['signup_pending']); wi_flash('The code expired. Sign up again.', 'bad'); header('Location: ./?signup'); exit; }
        if (!password_verify(trim((string) ($_POST['code'] ?? '')), (string) $P['code'])) { $_SESSION['signup_pending']['tries'] = (int) $P['tries'] + 1; wi_flash('Wrong code.', 'bad'); header('Location: ./?signup'); exit; }
        unset($_SESSION['signup_pending'], $_SESSION['gsignup']); $err = wi_signup((array) $P['in'], true);
        if ($err !== '') { wi_flash($err, 'bad'); header('Location: ./?signup'); exit; }
        wi_flash('Your account is ready. Sign in to start your trial.'); header('Location: ./?login'); exit;
    }
    if ($do === 'forgot') {
        $email = strtolower(trim((string) ($_POST['email'] ?? ''))); $u = wi_find_user($email); if ($u && !wi_host_allows($u)) $u = null;
        $many = false; wi_update('forgot_ips.json', function ($S) use (&$many) { $ip = hash('sha256', wi_ip()); $S = array_filter((array) $S, fn($t) => max((array) $t) > time() - 3600); $t = array_values(array_filter((array) ($S[$ip] ?? []), fn($x) => $x > time() - 3600)); if (count($t) >= 5) $many = true; else $t[] = time(); $S[$ip] = $t; return $S; });
        if ($many || wi_locked($email)) { wi_flash('Too many failed attempts. Try again in 15 minutes.', 'bad'); header('Location: ./?forgot'); exit; }
        $code = (string) random_int(100000, 999999);
        $_SESSION['reset_pending'] = ['email' => $u && !empty($u['active']) && !empty($u['phone']) ? $email : '', 'code' => password_hash($code, PASSWORD_DEFAULT), 'until' => time() + 600, 'tries' => 0];
        if ($_SESSION['reset_pending']['email'] !== '') { wi_send_whatsapp((string) $u['phone'], wf_msg('reset_code', ['code' => $code, 'minutes' => 10])); wi_log('password_reset_code', ['target' => $email]); }
        wi_flash('If this email has an account, a code was sent to its WhatsApp.'); header('Location: ./?forgot'); exit;
    }
    if ($do === 'forgot_code') {
        $P = (array) ($_SESSION['reset_pending'] ?? []); $pw = (string) ($_POST['password'] ?? '');
        if (!$P || time() > (int) $P['until'] || (int) $P['tries'] >= 5) { unset($_SESSION['reset_pending']); wi_flash('The code expired. Start again.', 'bad'); header('Location: ./?forgot'); exit; }
        if (strlen($pw) < 10) { wi_flash('Password must be at least 10 characters.', 'bad'); header('Location: ./?forgot'); exit; }
        if ($P['email'] === '' || !password_verify(trim((string) ($_POST['code'] ?? '')), (string) $P['code'])) { $_SESSION['reset_pending']['tries'] = (int) $P['tries'] + 1; wi_flash('Wrong code.', 'bad'); header('Location: ./?forgot'); exit; }
        unset($_SESSION['reset_pending']);
        $all = wi_users(); foreach ($all as &$x) if (strtolower((string) $x['email']) === $P['email']) $x['password_hash'] = password_hash($pw, PASSWORD_DEFAULT); unset($x); wi_save_users($all);
        wi_log('password_reset', ['target' => $P['email']]); wi_flash('Password changed. Sign in with the new one.'); header('Location: ./?login'); exit;
    }
    if ($do === 'forgot_cancel') { unset($_SESSION['reset_pending']); header('Location: ./?login'); exit; }
    if ($do === 'signup_cancel') { unset($_SESSION['signup_pending'], $_SESSION['gsignup']); header('Location: ./?signup'); exit; }
    if ($do === 'login') {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $u = wi_find_user($email);
        if ($u && !wi_host_allows($u)) $u = null; // another client's address: treated as unknown
        if (wi_locked($email)) {
            wi_flash('Too many failed attempts. Try again in 15 minutes.', 'bad');
        } elseif (($raw = $u ?: (array_values(array_filter(wi_users(), fn($x) => strtolower((string) $x['email']) === $email && ($x['role'] ?? '') === 'client'))[0] ?? null))
            && ($blk = wi_access_blocked($raw)) !== '' && password_verify((string) ($_POST['password'] ?? ''), (string) $raw['password_hash'])) { // a paused client is told why, not "wrong password"
            wi_flash($blk, 'bad'); wi_log('login_blocked', ['email' => $email]);
        } elseif ($u && password_verify((string) ($_POST['password'] ?? ''), (string) $u['password_hash'])) {
            $p = $_SESSION['pending'] ?? null;
            if ($p && $p['email'] === $email && time() - $p['at'] < 60) {
                wi_flash('A code was already sent. Use the latest message.', 'ok');
            } else {
                $code = (string) random_int(100000, 999999);
                $_SESSION['pending'] = ['email' => $email, 'hash' => password_hash($code, PASSWORD_DEFAULT), 'at' => time(), 'tries' => 0];
                require_once dirname(__DIR__) . '/features.php';
                if (wi_send_whatsapp((string) $u['phone'], wf_msg('otp', ['code' => $code, 'minutes' => (int) wi_sys('otp_min')]))) {
                    wi_flash('A 6-digit code was sent to your WhatsApp.', 'ok');
                } elseif (($inst = json_decode((string) @file_get_contents(WI_DIR . '/installed.json'), true)) && empty($inst['first_number_at']) && strtolower((string) ($inst['by'] ?? '')) === $email) {
                    // Fresh install: no WhatsApp number is linked yet, so the installer's admin signs in with the password alone (until a number connects).
                    unset($_SESSION['pending']); session_regenerate_id(true);
                    $_SESSION['email'] = $email; $_SESSION['seen'] = time(); $_SESSION['fp'] = wi_fingerprint();
                    wi_clear_fails($email); wi_log('login', ['first_setup' => true]);
                    wi_flash('Signed in without a WhatsApp code because no number is linked yet. Link the number now from WhatsApp numbers.', 'ok');
                } else {
                    unset($_SESSION['pending']);
                    wi_flash('Could not send the code on WhatsApp. Check that the gateway is connected.', 'bad');
                }
            }
        } else {
            wi_fail($email);
            wi_log('login_failed', ['email' => $email]);
            wi_flash('Email or password is incorrect.', 'bad');
        }
        header('Location: ./'); exit;
    }
    if ($do === 'otp') {
        $p = $_SESSION['pending'] ?? null;
        $code = preg_replace('/\D+/', '', (string) ($_POST['code'] ?? ''));
        if (!$p || time() - $p['at'] > WI_OTP_TTL || $p['tries'] >= 5) {
            unset($_SESSION['pending']); wi_flash('The code expired. Please sign in again.', 'bad');
        } elseif (password_verify($code, $p['hash'])) {
            unset($_SESSION['pending']);
            session_regenerate_id(true);
            $_SESSION['email'] = $p['email']; $_SESSION['seen'] = time(); $_SESSION['fp'] = wi_fingerprint();
            wi_clear_fails($p['email']);
            wi_log('login');
        } else {
            $_SESSION['pending']['tries']++; wi_fail($p['email']); wi_flash('Wrong code.', 'bad');
        }
        header('Location: ./'); exit;
    }
    if ($do === 'cancel') { unset($_SESSION['pending']); header('Location: ./'); exit; }
    if ($do === 'logout' && !empty($_SESSION['imp_from'])) $do = 'impersonate_end'; // signing out of a borrowed view returns to your own account
    if ($do === 'impersonate_end' && !empty($_SESSION['imp_from'])) {
        $back = (string) $_SESSION['imp_from']; wi_log('impersonate_end');
        session_regenerate_id(true); $_SESSION['email'] = $back; unset($_SESSION['imp_from'], $_SESSION['acc']); $_SESSION['seen'] = time();
        header('Location: ?p=users'); exit;
    }
    if ($do === 'logout') { wi_log('logout'); $_SESSION = []; session_destroy(); header('Location: ./'); exit; }
    // My account: every user can change their own name, WhatsApp number and password (the current password is required).
    // Any user: their own dashboard theme ('' = the system's).
    if ($do === 'my_theme' && !empty($_SESSION['email'])) {
        $th = (string) ($_POST['theme'] ?? ''); if ($th !== '' && !isset(WI_THEMES[$th])) $th = '';
        $users = wi_users(); $email = strtolower((string) $_SESSION['email']);
        foreach ($users as &$u) if (strtolower((string) $u['email']) === $email) { if ($th === '') unset($u['ui_theme']); else $u['ui_theme'] = $th; } unset($u);
        wi_save_users($users); wi_log('my_theme', ['theme' => $th]); wi_flash('Saved.', 'ok'); header('Location: ?p=me'); exit;
    }
    if ($do === 'my_account' && !empty($_SESSION['email']) && empty($_SESSION['imp_from'])) {
        $users = wi_users(); $email = strtolower((string) $_SESSION['email']); $ok = false;
        foreach ($users as &$u) { if (strtolower((string) $u['email']) !== $email) continue;
            if (!password_verify((string) ($_POST['current'] ?? ''), (string) $u['password_hash'])) { wi_flash('The current password is incorrect.', 'bad'); break; }
            $name = trim(mb_substr((string) ($_POST['name'] ?? ''), 0, 80)); $phone = wi_norm_phone((string) ($_POST['phone'] ?? '')); $pw = (string) ($_POST['password'] ?? '');
            if ($phone === '') { wi_flash('Invalid WhatsApp number.', 'bad'); break; }
            if ($pw !== '' && strlen($pw) < 10) { wi_flash('Password must be at least 10 characters.', 'bad'); break; }
            $u['name'] = $name; $u['phone'] = $phone; if ($pw !== '') $u['password_hash'] = password_hash($pw, PASSWORD_DEFAULT); $ok = true; }
        unset($u);
        if ($ok) { wi_save_users($users); wi_log('my_account_save', []); wi_flash('Your account was saved.', 'ok'); }
        header('Location: ?p=me'); exit;
    }

    if (!wi_logged_in()) { header('Location: ./'); exit; }
    if ($do === 'impersonate') {
        // Super admin only: see the dashboard exactly as another user sees it. Logged in the audit trail.
        $t = wi_find_user((string) ($_POST['email'] ?? ''));
        $why = wi_may_impersonate(wi_me(), $t, !empty($_SESSION['imp_from']), wi_accounts());
        if ($why === 'Super admins only.') { http_response_code(403); exit($why); }
        if ($why !== '') { wi_flash($why, 'bad'); header('Location: ?p=users'); exit; }
        wi_log('impersonate', ['target' => $t['email']]);
        session_regenerate_id(true); $_SESSION['imp_from'] = (string) $_SESSION['email']; $_SESSION['email'] = $t['email']; unset($_SESSION['acc']); $_SESSION['seen'] = time();
        header('Location: ./'); exit;
    }
    if ($do === 'history_choice' && function_exists('wf_hist_choose')) {
        $ha = wi_accounts()[(string) ($_POST['acc'] ?? '')] ?? null;
        if (!$ha || !wi_can_manage($ha)) { http_response_code(403); exit; }
        $err = wf_hist_choose((string) $ha['id'], (string) ($_POST['mode'] ?? ''));
        wi_flash($err !== '' ? $err : (($_POST['mode'] ?? '') === 'merge' ? 'The old chats are merged with the new number.' : 'The old number\'s chats are kept as a separate read-only history.'), $err !== '' ? 'bad' : 'ok');
        header('Location: ./'); exit;
    }
    if ($do === 'switch_account') {
        $id = (string) ($_POST['acc'] ?? '');
        if (isset(wi_my_accounts()[$id])) $_SESSION['acc'] = $id;
        $back = (string) ($_POST['back'] ?? '');
        header('Location: ' . (preg_match('/^\?p=[a-z]+$/', $back) ? $back : './')); exit;
    }
    // A new number for the same business: the old number's history is added to it (the gateway of the new number restarts to read it).
    if ($do === 'merge_numbers') {
        $all = wi_accounts(); $from = (string) ($_POST['from'] ?? ''); $to = (string) ($_POST['to'] ?? '');
        $own = fn($id) => isset($all[$id]) && (wi_is_super() || (!empty($all[$id]['owner']) && strtolower((string) $all[$id]['owner']) === strtolower((string) $_SESSION['email'])));
        if (!$own($from) || !$own($to)) { wi_flash('Not allowed for this number.', 'bad'); header('Location: ?p=accounts'); exit; }
        try { require_once dirname(__DIR__) . '/features.php'; $r = wi_merge_number($from, $to); $pid = (int) (wi_gateway('/status', null, $all[$to])['pid'] ?? 0); if ($pid > 1 && function_exists('posix_kill')) @posix_kill($pid, 15); // the watchdog starts it again within a minute, reading the added history
            wi_flash(sprintf(wi_t('Done: %s messages and %s files were added. The number reconnects in a moment to show them.'), number_format($r['lines']), number_format($r['files'])), 'ok'); }
        catch (Throwable $e) { wi_flash($e->getMessage(), 'bad'); }
        header('Location: ?p=accounts'); exit;
    }
    if (($do === 'fb_app_save' || $do === 'fb_pick_add') && (require_once dirname(__DIR__) . '/features.php') && function_exists('wf_fb_is')) { // the Facebook functions live in the modules // the Facebook app, and the pages picked after "Connect with Facebook"
        $may = (wi_is_admin() && empty(wi_me()['own_number'])) || wi_can_link_own();
        if (!$may) { wi_flash('Not allowed for this number.', 'bad'); header('Location: ?p=accounts'); exit; }
        if ($do === 'fb_app_save') { $r = wf_fb_app_save(wi_is_super() ? '' : strtolower((string) $_SESSION['email']), (string) ($_POST['fb_appid'] ?? ''), (string) ($_POST['fb_appsecret'] ?? ''), (string) ($_POST['fb_config'] ?? '')); wi_flash(!empty($r['ok']) ? 'Facebook app saved. Now connect your pages.' : (string) $r['error'], !empty($r['ok']) ? 'ok' : 'bad'); }
        else { $r = wf_fb_pick_add((string) ($_POST['pk'] ?? ''), array_map('strval', (array) ($_POST['pages'] ?? [])));
            wi_flash(!empty($r['ok']) ? 'Connected: ' . implode(', ', $r['done']) . ($r['errors'] ? ' — ' . implode(' · ', $r['errors']) : '') : (string) $r['error'], !empty($r['ok']) && !$r['errors'] ? 'ok' : 'bad'); }
        header('Location: ?p=accounts'); exit;
    }
    if (($do === 'add_fb' || $do === 'fb_connect') && (require_once dirname(__DIR__) . '/features.php') && function_exists('wf_fb_is')) { // the Facebook functions live in the modules // Facebook page (Messenger): same permission as adding a number
        $accs = wi_accounts(); $owned = array_filter($accs, fn($a) => !empty($a['owner']) && strtolower($a['owner']) === strtolower((string) $_SESSION['email']));
        if ($do === 'add_fb') {
            if (!((wi_is_admin() && empty(wi_me()['own_number'])) || (wi_can_link_own() && count($owned) < ((wi_me()['role'] ?? '') === 'client' ? wi_client_max_numbers(wi_me()) : 1)))) { wi_flash('You reached the numbers of your plan. Upgrade your plan or ask us for more.', 'bad'); header('Location: ?p=accounts'); exit; }
            $r = wf_fb_add(trim((string) ($_POST['name'] ?? '')), ['token' => $_POST['token'] ?? '', 'secret' => $_POST['secret'] ?? '', 'page_id' => $_POST['fb_pageid'] ?? '', 'app_id' => ''], wi_is_super() ? null : (string) $_SESSION['email']);
            if (!empty($r['id']) && !wi_is_super()) { $users = wi_users(); foreach ($users as &$uu) if (strtolower($uu['email']) === strtolower((string) $_SESSION['email'])) $uu['accounts'] = array_values(array_unique(array_merge((array) ($uu['accounts'] ?? []), [$r['id']]))); unset($uu); wi_save_users($users); }
            if (!empty($r['id'])) { $_SESSION['acc'] = $r['id']; wi_log('account_add', ['account' => $r['id'], 'channel' => 'fb']); }
            wi_flash(!empty($r['ok']) ? 'Facebook page connected. Add the address and the code below to the app\'s webhook so messages arrive.' : (!empty($r['id']) ? 'The page was saved but is not connected yet: ' . $r['error'] : (string) $r['error']), !empty($r['ok']) ? 'ok' : 'bad');
        } else {
            $t = $accs[(string) ($_POST['acc'] ?? '')] ?? null;
            if (!$t || !wf_fb_is($t) || !(wi_is_super() || wi_can_manage($t))) wi_flash('Not allowed for this number.', 'bad');
            else {
                if (trim((string) ($_POST['token'] ?? '')) !== '') wi_secret_set('fb_page_token:' . $t['id'], trim((string) $_POST['token']));
                if (trim((string) ($_POST['secret'] ?? '')) !== '') wi_secret_set('fb_app_secret:' . $t['id'], trim((string) $_POST['secret']));
                $r = wf_fb_connect((string) $t['id']); wi_flash(!empty($r['ok']) ? 'Connected to the page: ' . $r['page'] : (string) $r['error'], !empty($r['ok']) ? 'ok' : 'bad');
            }
        }
        header('Location: ?p=accounts'); exit;
    }
    // a number's gateway: our own (its own process or the shared hub), OpenWA or the Cloud API; secrets are only ever written, never shown
    if ($do === 'account_provider') {
        if (!wi_is_super()) { http_response_code(403); exit; }
        $accounts = wi_accounts(); $id = (string) ($_POST['acc'] ?? ''); $a = $accounts[$id] ?? null;
        if (!$a) { wi_flash('Account not found.', 'bad'); header('Location: ?p=accounts'); exit; }
        $pv = in_array($_POST['provider'] ?? '', ['openwa', 'cloud'], true) ? (string) $_POST['provider'] : 'local'; $hub = !empty($_POST['hub']) && $pv === 'local';
        if (!empty($a['main'])) { $cfg = wi_read('config.json', []); if ($hub) $cfg['main_gateway'] = 'hub'; else unset($cfg['main_gateway']); wi_write('config.json', $cfg); }
        else {
            $accounts[$id] = ['provider' => $pv, 'openwa_url' => mb_substr(trim((string) ($_POST['openwa_url'] ?? '')), 0, 200), 'openwa_session' => preg_replace('/[^A-Za-z0-9_.:-]/', '', (string) ($_POST['openwa_session'] ?? '')),
                'cloud_phone_id' => preg_replace('/\D/', '', (string) ($_POST['cloud_phone_id'] ?? '')), 'cloud_graph' => preg_match('/^v\d+\.\d+$/', (string) ($_POST['cloud_graph'] ?? '')) ? (string) $_POST['cloud_graph'] : 'v21.0'] + $accounts[$id];
            if ($hub) $accounts[$id]['gateway'] = 'hub'; else unset($accounts[$id]['gateway']);
            wi_save_accounts($accounts);
            foreach (['openwa_key', 'openwa_hook_secret', 'cloud_token', 'cloud_app_secret', 'cloud_verify'] as $sk) { $sv = trim((string) ($_POST[$sk] ?? '')); if ($sv !== '') wi_secret_set($sk . ':' . $id, $sv); }
        }
        wi_log('account_provider', ['account' => $id, 'provider' => $pv, 'hub' => $hub]);
        wi_flash('Gateway settings saved. A change of process takes effect within a minute.', 'ok'); header('Location: ?p=accounts'); exit;
    }
    if (in_array($do, ['add_account', 'update_account', 'delete_account'], true)) {
        $accounts = wi_accounts();
        $target = $accounts[(string) ($_POST['acc'] ?? '')] ?? null;
        $owned = array_filter($accounts, fn($a) => !empty($a['owner']) && strtolower($a['owner']) === strtolower((string) $_SESSION['email']));
        $allowed = $do === 'add_account' ? ((wi_is_admin() && empty(wi_me()['own_number'])) || (wi_can_link_own() && count($owned) < ((wi_me()['role'] ?? '') === 'client' ? wi_client_max_numbers(wi_me()) : 1))) : ($target && (wi_is_super() || (wi_can_manage($target) && empty($target['main']))));
        if (!$allowed) { wi_flash($do === 'add_account' ? 'You reached the numbers of your plan. Upgrade your plan or ask us for more.' : 'Not allowed for this number.', 'bad'); header('Location: ?p=accounts'); exit; }
        if ($do === 'add_account') {
            $name = trim((string) ($_POST['name'] ?? ''));
            $used = array_map(fn($a) => (int) $a['port'], $accounts);
            $port = 0; for ($p = 3201; $p <= 3299; $p++) if (!in_array($p, $used, true)) { $port = $p; break; }
            if ($name === '' || mb_strlen($name) > 60) wi_flash('Give the account a name (up to 60 characters).', 'bad');
            elseif (!$port) wi_flash('No free port for another account.', 'bad');
            else {
                $id = 'wa' . substr(bin2hex(random_bytes(4)), 0, 6);
                $accounts[$id] = ['id' => $id, 'name' => $name, 'port' => $port, 'token' => bin2hex(random_bytes(24)), 'enabled' => true,
                    'payments' => wi_is_super() ? !empty($_POST['payments']) : false, 'autoreply' => false, 'created_at' => date('c'), 'created_by' => (string) $_SESSION['email'], // payments: the payment bot sends receipts to the owner's invoice system (owner only)
                    'owner' => wi_is_super() ? null : (string) $_SESSION['email']];
                wi_save_accounts($accounts); wi_log('account_add', ['account' => $id, 'name' => $name]);
                if (!wi_is_super()) wi_client_folder($id, (string) $_SESSION['email']); // a client's files go to a folder of its own
                if (!wi_is_super()) { // a user's own number is visible to that user
                    $users = wi_users();
                    foreach ($users as &$uu) if (strtolower($uu['email']) === strtolower((string) $_SESSION['email'])) $uu['accounts'] = array_values(array_unique(array_merge((array) ($uu['accounts'] ?? []), [$id])));
                    unset($uu); wi_save_users($users);
                }
                $_SESSION['acc'] = $id;
                wi_flash('Account added. Its gateway starts within a minute — then scan the QR below.', 'ok');
                header('Location: ?p=link'); exit;
            }
        } else {
            $id = (string) ($_POST['acc'] ?? '');
            if (!isset($accounts[$id])) wi_flash('Account not found.', 'bad');
            elseif ($do === 'delete_account') {
                if (!empty($accounts[$id]['main'])) wi_flash('The main account cannot be deleted.', 'bad');
                elseif (!wi_is_super() && strtolower((string) ($accounts[$id]['owner'] ?? '')) === strtolower((string) $_SESSION['email'])) wi_flash('Your own number cannot be deleted — use Re-link to connect it again.', 'bad');
                // nothing of a number is deleted: it becomes an archived account (read only, no gateway) with all its chats, media, cases and library
                else { $accounts[$id] = ['enabled' => false, 'archived' => true, 'archived_at' => date('c')] + $accounts[$id]; wi_save_accounts($accounts); wi_log('account_delete', ['account' => $id, 'archived' => true]); wi_flash('Number removed. Its gateway stops within a minute; all its history stays readable under 🗄️ in the numbers list.', 'ok'); }
            } else {
                if (($n = trim((string) ($_POST['name'] ?? ''))) !== '') {
                    if (!empty($accounts[$id]['main'])) { $c = wi_config(); $c['main_name'] = mb_substr($n, 0, 60); wi_write('config.json', $c); }
                    else $accounts[$id]['name'] = mb_substr($n, 0, 60);
                }
                if (!empty($accounts[$id]['main']) && wi_is_super() && isset($_POST['payments_form'])) { $c = wi_config(); $c['main_payments'] = !empty($_POST['payments']); wi_write('config.json', $c); }
                if (empty($accounts[$id]['main'])) {
                    $accounts[$id]['enabled'] = !empty($_POST['enabled']);
                    if (wi_is_super()) $accounts[$id]['payments'] = !empty($_POST['payments']);
                    $accounts[$id]['autoreply'] = !empty($_POST['autoreply']);
                }
                wi_save_accounts($accounts); wi_log('account_update', ['account' => $id]); wi_flash('Account updated.', 'ok');
            }
        }
        header('Location: ?p=accounts'); exit;
    }
    // Clients page (super admin): the plans themselves, and whether visitors may sign up.
    if ($do === 'plans_save') {
        if (!wi_is_super() || !empty($_SESSION['imp_from'])) { http_response_code(403); exit('Super admin only.'); }
        $out = []; foreach ((array) ($_POST['plans'] ?? []) as $id => $p) { $id = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) ($p['id'] ?? $id))); if ($id === '' || trim((string) ($p['name'] ?? '')) === '' || !empty($p['del'])) continue; $out[$id] = wi_plan_clean((array) $p); }
        if ($out) wi_write('plans.json', $out);
        $cfg = wi_config(); $cfg['system']['signup_on'] = !empty($_POST['signup_on']) ? 1 : 0; $cfg['system']['google_login_on'] = !empty($_POST['google_login_on']) ? 1 : 0; $ts = trim((string) ($_POST['turnstile_site'] ?? '')); $cfg['system']['turnstile_site'] = preg_match('/^[A-Za-z0-9_-]{10,100}$/', $ts) ? $ts : ''; if (trim((string) ($_POST['turnstile_secret'] ?? '')) !== '') wi_secret_set('turnstile_secret', trim((string) $_POST['turnstile_secret'])); $sd = strtolower(trim((string) ($_POST['saas_domain'] ?? ''))); $cfg['system']['saas_domain'] = preg_match('/^([a-z0-9-]+\.)+[a-z]{2,}$/', $sd) ? $sd : ''; wi_write('config.json', $cfg);
        wi_log('plans_save', ['plans' => array_keys($out)]); wi_flash('Saved.', 'ok'); header('Location: ?p=plans'); exit;
    }
    // System overview (super admin): the price per million tokens of each AI model, for the cost estimate.
    if ($do === 'ai_prices') {
        if (!wi_is_super() || !empty($_SESSION['imp_from'])) { http_response_code(403); exit('Super admin only.'); }
        $pr = []; foreach ((array) ($_POST['price'] ?? []) as $md => $x) { $md = mb_substr(trim((string) $md), 0, 80); if ($md === '') continue; $pr[$md] = ['in' => max(0, round((float) ($x['in'] ?? 0), 4)), 'out' => max(0, round((float) ($x['out'] ?? 0), 4))]; }
        $cfg = wi_config(); $cfg['ai_prices'] = $pr; wi_write('config.json', $cfg); wi_log('ai_prices', ['models' => array_keys($pr)]); wi_flash('Saved.', 'ok'); header('Location: ?p=saas#ai-cost'); exit;
    }
    // Clients page (super admin): plan, end date, pause, seats and features of a client.
    if (in_array($do, ['client_save', 'client_extend', 'client_toggle'], true)) {
        if (!wi_is_super() || !empty($_SESSION['imp_from'])) { http_response_code(403); exit('Super admin only.'); }
        $email = strtolower(trim((string) ($_POST['email'] ?? ''))); $all = wi_users(); $hit = false;
        foreach ($all as &$u) { if (strtolower((string) $u['email']) !== $email || ($u['role'] ?? '') !== 'client') continue; $hit = true;
            if ($do === 'client_save') {
                $pid = (string) ($_POST['plan_id'] ?? ''); if ($pid !== '' && isset(wi_plans()[$pid]) && $pid !== (string) ($u['plan_id'] ?? '')) $u = wi_plan_apply($u, $pid, false); // a new plan: its limits and features
                elseif ($pid === '') $u['plan_id'] = '';
                $u['plan'] = mb_substr(trim((string) ($_POST['plan'] ?? ($u['plan'] ?? ''))), 0, 40) ?: (string) ($u['plan'] ?? '');
                if (isset($_POST['slug'])) { $u = wi_client_addr($u, (string) $_POST['slug'], (string) ($_POST['domains'] ?? ''));
                    foreach ($all as $x) if (strtolower((string) $x['email']) !== $email && (($u['slug'] !== '' && ($x['slug'] ?? '') === $u['slug']) || array_intersect($u['domains'], (array) ($x['domains'] ?? [])))) { wi_flash('This address is already used by another client.', 'bad'); header('Location: ?p=clients'); exit; } }
                if (isset($_POST['brand_name'])) { $u['brand_name'] = mb_substr(trim((string) $_POST['brand_name']), 0, 60); $u['brand_logo'] = mb_substr(trim((string) ($_POST['brand_logo'] ?? '')), 0, 8); }
                $u['max_numbers'] = max(0, min(100, (int) ($_POST['max_numbers'] ?? ($u['max_numbers'] ?? 1))));
                $u['ai_calls'] = max(0, min(1000000, (int) ($_POST['ai_calls'] ?? ($u['ai_calls'] ?? 0)))); $u['ai_tokens'] = max(0, min(10000000000, (int) ($_POST['ai_tokens'] ?? ($u['ai_tokens'] ?? 0))));
                $ex = trim((string) ($_POST['expires'] ?? '')); $u['expires'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $ex) ? $ex : '';
                if (isset($_POST['pay_method'])) { $pm = (string) $_POST['pay_method']; $u['pay_method'] = isset(wi_pay_methods()[$pm]) ? $pm : ''; }
                $ps = trim((string) ($_POST['plan_start'] ?? '')); if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $ps)) $u['plan_start'] = $ps;
                $u['max_team'] = max(0, min(500, (int) ($_POST['max_team'] ?? 0)));
                $u['media_gb'] = max(0, min(100000, (int) ($_POST['media_gb'] ?? ($u['media_gb'] ?? 0))));
                foreach (['can_bot', 'can_meetings', 'can_cases', 'can_backup'] as $fk) $u[$fk] = !empty($_POST[$fk]);
                $u['client_note'] = mb_substr(trim((string) ($_POST['client_note'] ?? '')), 0, 500);
                $u['panel_name'] = mb_substr(trim((string) ($_POST['panel_name'] ?? '')), 0, 60);
                // a restricted business type is given by hand only
                require_once dirname(__DIR__) . '/features.php'; foreach (array_keys(wf_restricted_types()) as $rk) $u['allow_' . $rk] = !empty($_POST['allow_' . $rk]);
                $u['active'] = !empty($_POST['active']);
            } elseif ($do === 'client_extend') {
                $days = in_array((int) ($_POST['days'] ?? 0), [30, 90, 365], true) ? (int) $_POST['days'] : 30;
                $from = !empty($u['expires']) && strtotime((string) $u['expires']) > time() ? strtotime((string) $u['expires']) : time(); // renewing early adds to the current end
                $u['expires'] = date('Y-m-d', strtotime('+' . $days . ' days', $from)); $u['active'] = true; if (empty($u['plan_start'])) $u['plan_start'] = date('Y-m-d', $from);
            } else $u['active'] = empty($u['active']);
            wi_log($do, ['target' => $email, 'expires' => $u['expires'] ?? '', 'active' => !empty($u['active'])]); }
        unset($u);
        if ($hit) { wi_save_users($all); wi_flash($do === 'client_toggle' ? 'Updated.' : 'Saved.', 'ok'); } else wi_flash('Client not found.', 'bad');
        header('Location: ?p=clients' . (!empty($_POST['f']) ? '&f=' . rawurlencode((string) $_POST['f']) : '')); exit;
    }
    $chatId = function (array $u): array { if (!isset($_POST['chat_sent'])) return $u; $u['chat_name'] = trim(mb_substr((string) ($_POST['chat_name'] ?? ''), 0, 40)); $u['chat_prefix'] = !empty($_POST['chat_prefix']); $u['chat_welcome'] = !empty($_POST['chat_welcome']); return $u; }; // name in chats, "add my name", welcome
    // A client manages their own team only: supervisors and employees on their own numbers, within their seats and their own plan.
    if (in_array($do, ['add_user', 'update_user', 'delete_user', 'team_roles'], true) && (wi_me()['role'] ?? '') === 'client' && empty($_SESSION['imp_from'])) {
        $me = wi_me(); $mail = strtolower((string) $me['email']); $users = wi_users(); $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $pagesIn = function () use ($me): array { $o = []; foreach ((array) ($_POST['pages'] ?? []) as $k => $v) if (isset(wi_page_names()[$k]) && in_array($v, ['view', 'edit'], true) && !in_array($k, WI_TEAM_NEVER, true) && wi_client_page_perm($me, (string) $k) !== '') $o[$k] = $v; return $o; };
        $chatIn = function (string $role): array { $o = []; foreach (WI_CHAT_PERMS as $k) $o[$k] = !empty($_POST['chat'][$k]) && !($role === 'agent' && in_array($k, WI_AGENT_NEVER, true)); return $o; };
        $role = in_array($_POST['role'] ?? '', WI_TEAM_ROLES, true) ? (string) $_POST['role'] : 'agent';
        $accs = array_values(array_intersect(array_keys(wi_owned_accounts($mail)), (array) ($_POST['accounts'] ?? [])));
        $back = function (string $msg, string $kind = 'ok') { wi_flash($msg, $kind); header('Location: ?p=users'); exit; };
        if ($do === 'team_roles') {
            wi_update('team_roles.json', function ($T) use ($mail, $role, $pagesIn, $chatIn) { $T[$mail][$role] = ['pages' => $pagesIn(), 'chat' => $chatIn($role)]; return $T; });
            wi_log('team_roles', ['role' => $role]); $back('Saved as the default for this role.');
        }
        $t = null; foreach ($users as $x) if (strtolower((string) $x['email']) === $email) $t = $x;
        if ($do !== 'add_user' && (!$t || !wi_user_in_scope($t))) $back('You can only manage your own team.', 'bad');
        if ($do === 'add_user') {
            $phone = wi_norm_phone((string) ($_POST['phone'] ?? '')); $pw = (string) ($_POST['password'] ?? '');
            if (count(wi_team_of($mail)) >= wi_team_seats($me)) $back('Your plan is full. Ask the administrator for more users.', 'bad');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $back('Invalid email.', 'bad');
            if (wi_find_user($email) || $t) $back('This email already exists.', 'bad');
            if (strlen($pw) < 10) $back('Password must be at least 10 characters.', 'bad');
            if ($phone === '') $back('Invalid WhatsApp number.', 'bad');
            $users[] = ['email' => $email, 'name' => trim(mb_substr((string) ($_POST['name'] ?? ''), 0, 80)), 'phone' => $phone, 'role' => $role, 'tenant' => $mail, 'created_by' => $mail,
                'accounts' => $accs, 'pages' => $pagesIn(), 'chat' => $chatIn($role), 'password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'active' => true, 'created_at' => date('c')]; $users[count($users) - 1] = $chatId($users[count($users) - 1]);
            wi_save_users($users); wi_log('team_add', ['target' => $email, 'role' => $role]); $back('User added.');
        }
        if ($do === 'update_user') {
            foreach ($users as &$u) { if (strtolower((string) $u['email']) !== $email) continue;
                if (isset($_POST['name'])) $u['name'] = trim(mb_substr((string) $_POST['name'], 0, 80));
                $u = $chatId($u);
                if (($pw = (string) ($_POST['password'] ?? '')) !== '') { if (strlen($pw) < 10) $back('Password must be at least 10 characters.', 'bad'); $u['password_hash'] = password_hash($pw, PASSWORD_DEFAULT); }
                if (($ph = trim((string) ($_POST['phone'] ?? ''))) !== '') { $n = wi_norm_phone($ph); if ($n === '') $back('Invalid WhatsApp number.', 'bad'); $u['phone'] = $n; }
                $u['role'] = $role; $u['accounts'] = $accs; $u['pages'] = $pagesIn(); $u['chat'] = $chatIn($role); $u['active'] = !isset($_POST['active_sent']) || !empty($_POST['active']); $u = $chatId($u); }
            unset($u); wi_save_users($users); wi_log('team_update', ['target' => $email, 'role' => $role]); $back('Updated.');
        }
        wi_save_users(array_filter($users, fn($u) => strtolower((string) $u['email']) !== $email));
        // their chats go back to the open queue
        wi_update('assign.json', function ($A) use ($email) { foreach ($A as $aid => $rows) foreach ((array) $rows as $j => $a) if (strtolower((string) ($a['to'] ?? '')) === $email) unset($A[$aid][$j]); return $A; });
        wi_log('team_delete', ['target' => $email]); $back('User deleted.');
    }
    if ($do === 'viewer_to_team' && wi_can_users() && empty($_SESSION['imp_from']) && in_array(wi_me()['role'] ?? '', ['super', 'admin'], true)) { // the page that moves a viewer into the team of the number's owner
        $users = wi_users(); $err = wi_viewer_to_team($users, (string) ($_POST['email'] ?? ''), (string) ($_POST['owner'] ?? ''), (string) ($_POST['role'] ?? 'agent'));
        if ($err === '') { wi_save_users($users); wi_log('viewer_to_team', ['target' => strtolower((string) ($_POST['email'] ?? '')), 'owner' => strtolower((string) ($_POST['owner'] ?? '')), 'role' => (string) ($_POST['role'] ?? '')]); wi_flash('Moved to the team. They now see the number of their owner.', 'ok'); }
        else wi_flash($err, 'bad');
        header('Location: ?p=users'); exit;
    }
    if (in_array($do, ['add_user', 'update_user', 'delete_user'], true)) {
        if (!wi_can_users() || (wi_me()['role'] ?? '') === 'client') { http_response_code(403); exit('Admins only.'); }
        $users = wi_users();
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        // An admin (not super) manages only viewers/managers on their own numbers, and cannot give higher roles or other numbers.
        if (!wi_is_super()) {
            $myAcc = array_keys(wi_my_accounts());
            $_POST['accounts'] = array_values(array_intersect((array) ($_POST['accounts'] ?? []), $myAcc));
            if (!in_array($_POST['role'] ?? 'viewer', ['viewer', 'manager'], true)) $_POST['role'] = 'viewer';
            if ($do !== 'add_user') { $t = null; foreach ($users as $x) if (strtolower($x['email']) === $email) $t = $x;
                if (!$t || !wi_user_in_scope($t)) { wi_flash('You can only manage users on your own numbers.', 'bad'); header('Location: ?p=users'); exit; }
                if ($do === 'update_user') foreach ($users as &$x) if (strtolower($x['email']) === $email) { $keep = array_diff((array) ($x['accounts'] ?? []), $myAcc); $_POST['accounts'] = array_values(array_merge($keep, $_POST['accounts'])); } unset($x); }
        }
        if ($do === 'add_user') {
            $phone = wi_norm_phone((string) ($_POST['phone'] ?? ''));
            $pw = (string) ($_POST['password'] ?? '');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) wi_flash('Invalid email.', 'bad');
            elseif (wi_find_user($email)) wi_flash('This email already exists.', 'bad');
            elseif (strlen($pw) < 10) wi_flash('Password must be at least 10 characters.', 'bad');
            elseif ($phone === '') wi_flash('Invalid WhatsApp number.', 'bad');
            else {
                $users[] = ['email' => $email, 'name' => trim((string) ($_POST['name'] ?? '')), 'phone' => $phone, 'role' => in_array($_POST['role'] ?? '', ['super', 'admin', 'manager', 'client'], true) ? $_POST['role'] : 'viewer', 'can_bot' => wi_is_super() && !empty($_POST['can_bot']), 'can_meetings' => wi_is_super() && !empty($_POST['can_meetings']), 'can_cases' => wi_is_super() && !empty($_POST['can_cases']), 'can_backup' => wi_is_super() && !empty($_POST['can_backup']), 'max_team' => wi_is_super() ? max(0, min(500, (int) ($_POST['max_team'] ?? 0))) : 0, 'created_by' => (string) $_SESSION['email'], 'can_share' => !empty($_POST['can_share']), 'cases_only' => !empty($_POST['cases_only']), 'pages' => wi_pages_from_post(),
                    'accounts' => (($_POST['num_src'] ?? '') === 'own' || ($_POST['role'] ?? '') === 'client') ? [] : array_values(array_intersect(array_keys(wi_my_accounts()), (array) ($_POST['accounts'] ?? []))),
                    'can_reply' => !empty($_POST['can_reply']) || ($_POST['num_src'] ?? '') === 'own' || ($_POST['role'] ?? '') === 'client',
                    'can_link' => !empty($_POST['can_link']) || ($_POST['num_src'] ?? '') === 'own' || ($_POST['role'] ?? '') === 'client',
                    'own_number' => ($_POST['num_src'] ?? '') === 'own' || ($_POST['role'] ?? '') === 'client', // links exactly one number of their own
                    'password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'active' => true, 'created_at' => date('c')]; $users[count($users) - 1] = $chatId($users[count($users) - 1]);
                wi_save_users($users); wi_log('user_add', ['target' => $email]); wi_flash('User added.', 'ok');
            }
        } elseif ($do === 'update_user') {
            foreach ($users as &$u) {
                if (strtolower($u['email']) !== $email) continue;
                if (isset($_POST['name'])) $u['name'] = trim(mb_substr((string) $_POST['name'], 0, 80));
                if (($_POST['role'] ?? '') === 'client' && wi_is_super()) { $_POST['can_link'] = 1; $_POST['can_reply'] = 1; $u['own_number'] = true; } // a client always links and runs their own number
                if (($pw = (string) ($_POST['password'] ?? '')) !== '') { if (strlen($pw) < 10) { wi_flash('Password must be at least 10 characters.', 'bad'); header('Location: ?p=users'); exit; } $u['password_hash'] = password_hash($pw, PASSWORD_DEFAULT); }
                if (($ph = trim((string) ($_POST['phone'] ?? ''))) !== '') { $n = wi_norm_phone($ph); if ($n === '') { wi_flash('Invalid WhatsApp number.', 'bad'); header('Location: ?p=users'); exit; } $u['phone'] = $n; }
                if (isset($_POST['accounts_sent'])) { $u['accounts'] = array_values(array_intersect(array_keys(wi_accounts()), (array) ($_POST['accounts'] ?? []))); $u['can_reply'] = !empty($_POST['can_reply']); $u['can_link'] = !empty($_POST['can_link']); $u['can_share'] = !empty($_POST['can_share']); $u['cases_only'] = !empty($_POST['cases_only']); $keep = array_intersect_key((array) ($u['pages'] ?? []), ($_POST['role'] ?? $u['role'] ?? '') === 'manager' ? ['cases' => 1] : []); $u['pages'] = wi_pages_from_post() + $keep; } // a manager keeps the cases share he accepted
                if (wi_is_super() && isset($_POST['accounts_sent'])) { foreach (['can_bot', 'can_meetings', 'can_cases', 'can_backup'] as $fk) $u[$fk] = !empty($_POST[$fk]); if (isset($_POST['max_team'])) $u['max_team'] = max(0, min(500, (int) $_POST['max_team'])); }
                if (in_array($_POST['role'] ?? '', ['super', 'admin', 'manager', 'viewer', 'client'], true) && (wi_is_super() || ($_POST['role'] ?? '') !== 'client')) {
                    if ($_POST['role'] !== 'super' && wi_is_super() && $email === strtolower((string) $_SESSION['email'])) { wi_flash('You cannot remove your own admin role.', 'bad'); header('Location: ?p=users'); exit; }
                    if (($u['role'] ?? '') !== $_POST['role']) wi_log('role_change', ['target' => $email, 'from' => (string) ($u['role'] ?? ''), 'to' => (string) $_POST['role']]); // who turned a client into a super admin (or back) is in the audit
                    $u['role'] = $_POST['role'];
                }
            }
            unset($u);
            // "Cases only" is a viewer mode, and every non-super user needs at least one number to see anything.
            $warn = '';
            foreach ($users as &$u) if (strtolower($u['email']) === $email) {
                if (($u['role'] ?? '') !== 'super' && empty($u['accounts']) && empty($u['cases_only'])) $warn .= ' No WhatsApp number is ticked — this user will see no cases or chats.';
            }
            unset($u);
            wi_save_users($users); wi_log('user_update', ['target' => $email]); wi_flash('Updated.' . $warn, $warn ? 'bad' : 'ok');
        } else {
            if ($email === strtolower((string) $_SESSION['email'])) wi_flash('You cannot delete yourself.', 'bad');
            else { // a client's team goes with the client
                $gone = array_filter($users, fn($u) => strtolower($u['email']) === $email || (in_array($u['role'] ?? '', WI_TEAM_ROLES, true) && strtolower((string) ($u['tenant'] ?? '')) === $email));
                wi_save_users(array_filter($users, fn($u) => !in_array($u, $gone, true))); wi_log('user_delete', ['target' => $email, 'team' => count($gone) - 1]); wi_flash('User deleted.' . (count($gone) > 1 ? ' Their team was deleted too.' : ''), 'ok'); }
        }
        header('Location: ?p=users'); exit;
    }
    header('Location: ./'); exit;
}
