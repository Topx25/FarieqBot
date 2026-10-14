<?php
// WhatsApp Inbox — form actions (POST do=…): sign-in, numbers, users, team, clients (moved out of index.php; runs in index.php's scope: $api, wi_* and wf_* are available).
/* ---------------- Actions ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    wi_check_csrf();
    $do = (string) ($_POST['do'] ?? '');
    if ($do === 'login') {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $u = wi_find_user($email);
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
        if (!wi_is_super() || !empty($_SESSION['imp_from'])) { http_response_code(403); exit('Super admins only.'); }
        if (!$t || ($t['role'] ?? '') === 'super' || strtolower($t['email']) === strtolower((string) $_SESSION['email'])) { wi_flash('This user cannot be opened.', 'bad'); header('Location: ?p=users'); exit; }
        // private numbers stay private: never open the panel of someone whose number you may not see
        foreach (wi_accounts() as $pa) if (wi_may_see_account($pa, $t) && !wi_may_see_account($pa)) { wi_flash('This user works on a private number — only its owner may see it.', 'bad'); header('Location: ?p=users'); exit; }
        wi_log('impersonate', ['target' => $t['email']]);
        session_regenerate_id(true); $_SESSION['imp_from'] = (string) $_SESSION['email']; $_SESSION['email'] = $t['email']; unset($_SESSION['acc']); $_SESSION['seen'] = time();
        header('Location: ./'); exit;
    }
    if ($do === 'switch_account') {
        $id = (string) ($_POST['acc'] ?? '');
        if (isset(wi_my_accounts()[$id])) $_SESSION['acc'] = $id;
        $back = (string) ($_POST['back'] ?? '');
        header('Location: ' . (preg_match('/^\?p=[a-z]+$/', $back) ? $back : './')); exit;
    }
    if (in_array($do, ['add_account', 'update_account', 'delete_account'], true)) {
        $accounts = wi_accounts();
        $target = $accounts[(string) ($_POST['acc'] ?? '')] ?? null;
        $owned = array_filter($accounts, fn($a) => !empty($a['owner']) && strtolower($a['owner']) === strtolower((string) $_SESSION['email']));
        $allowed = $do === 'add_account' ? ((wi_is_admin() && empty(wi_me()['own_number'])) || (wi_can_link_own() && !$owned)) : ($target && (wi_is_super() || (wi_can_manage($target) && empty($target['main']))));
        if (!$allowed) { wi_flash($do === 'add_account' ? 'You can link one number of your own. Ask an admin for more.' : 'Not allowed for this number.', 'bad'); header('Location: ?p=accounts'); exit; }
        if ($do === 'add_account') {
            $name = trim((string) ($_POST['name'] ?? ''));
            $used = array_map(fn($a) => (int) $a['port'], $accounts);
            $port = 0; for ($p = 3201; $p <= 3299; $p++) if (!in_array($p, $used, true)) { $port = $p; break; }
            if ($name === '' || mb_strlen($name) > 60) wi_flash('Give the account a name (up to 60 characters).', 'bad');
            elseif (!$port) wi_flash('No free port for another account.', 'bad');
            else {
                $id = 'wa' . substr(bin2hex(random_bytes(4)), 0, 6);
                $accounts[$id] = ['id' => $id, 'name' => $name, 'port' => $port, 'token' => bin2hex(random_bytes(24)), 'enabled' => true,
                    'payments' => wi_is_super() ? !empty($_POST['payments']) : false, // the payment bot sends receipts to the owner's invoice system: owner only 'autoreply' => false, 'created_at' => date('c'), 'created_by' => (string) $_SESSION['email'],
                    'owner' => wi_is_super() ? null : (string) $_SESSION['email']];
                wi_save_accounts($accounts); wi_log('account_add', ['account' => $id, 'name' => $name]);
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
                else { unset($accounts[$id]); wi_save_accounts($accounts); wi_log('account_delete', ['account' => $id]); wi_flash('Account removed. Its gateway stops within a minute; its archive is kept on the server.', 'ok'); }
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
    // Clients page (super admin): plan, end date, pause, seats and features of a client.
    if (in_array($do, ['client_save', 'client_extend', 'client_toggle'], true)) {
        if (!wi_is_super() || !empty($_SESSION['imp_from'])) { http_response_code(403); exit('Super admin only.'); }
        $email = strtolower(trim((string) ($_POST['email'] ?? ''))); $all = wi_users(); $hit = false;
        foreach ($all as &$u) { if (strtolower((string) $u['email']) !== $email || ($u['role'] ?? '') !== 'client') continue; $hit = true;
            if ($do === 'client_save') {
                $u['plan'] = mb_substr(trim((string) ($_POST['plan'] ?? '')), 0, 40);
                $ex = trim((string) ($_POST['expires'] ?? '')); $u['expires'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $ex) ? $ex : '';
                $u['max_team'] = max(0, min(500, (int) ($_POST['max_team'] ?? 0)));
                foreach (['can_bot', 'can_meetings', 'can_cases', 'can_backup'] as $fk) $u[$fk] = !empty($_POST[$fk]);
                $u['client_note'] = mb_substr(trim((string) ($_POST['client_note'] ?? '')), 0, 500);
                $u['allow_ruqyah'] = !empty($_POST['allow_ruqyah']); // the spiritual type is given by hand only
                $u['active'] = !empty($_POST['active']);
            } elseif ($do === 'client_extend') {
                $days = in_array((int) ($_POST['days'] ?? 0), [30, 90, 365], true) ? (int) $_POST['days'] : 30;
                $from = !empty($u['expires']) && strtotime((string) $u['expires']) > time() ? strtotime((string) $u['expires']) : time(); // renewing early adds to the current end
                $u['expires'] = date('Y-m-d', strtotime('+' . $days . ' days', $from)); $u['active'] = true;
            } else $u['active'] = empty($u['active']);
            wi_log($do, ['target' => $email, 'expires' => $u['expires'] ?? '', 'active' => !empty($u['active'])]); }
        unset($u);
        if ($hit) { wi_save_users($all); wi_flash($do === 'client_toggle' ? 'Updated.' : 'Saved.', 'ok'); } else wi_flash('Client not found.', 'bad');
        header('Location: ?p=clients' . (!empty($_POST['f']) ? '&f=' . rawurlencode((string) $_POST['f']) : '')); exit;
    }
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
                'accounts' => $accs, 'pages' => $pagesIn(), 'chat' => $chatIn($role), 'password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'active' => true, 'created_at' => date('c')];
            wi_save_users($users); wi_log('team_add', ['target' => $email, 'role' => $role]); $back('User added.');
        }
        if ($do === 'update_user') {
            foreach ($users as &$u) { if (strtolower((string) $u['email']) !== $email) continue;
                if (isset($_POST['name'])) $u['name'] = trim(mb_substr((string) $_POST['name'], 0, 80));
                if (($pw = (string) ($_POST['password'] ?? '')) !== '') { if (strlen($pw) < 10) $back('Password must be at least 10 characters.', 'bad'); $u['password_hash'] = password_hash($pw, PASSWORD_DEFAULT); }
                if (($ph = trim((string) ($_POST['phone'] ?? ''))) !== '') { $n = wi_norm_phone($ph); if ($n === '') $back('Invalid WhatsApp number.', 'bad'); $u['phone'] = $n; }
                $u['role'] = $role; $u['accounts'] = $accs; $u['pages'] = $pagesIn(); $u['chat'] = $chatIn($role); $u['active'] = !isset($_POST['active_sent']) || !empty($_POST['active']); }
            unset($u); wi_save_users($users); wi_log('team_update', ['target' => $email, 'role' => $role]); $back('Updated.');
        }
        wi_save_users(array_filter($users, fn($u) => strtolower((string) $u['email']) !== $email));
        // their chats go back to the open queue
        wi_update('assign.json', function ($A) use ($email) { foreach ($A as $aid => $rows) foreach ((array) $rows as $j => $a) if (strtolower((string) ($a['to'] ?? '')) === $email) unset($A[$aid][$j]); return $A; });
        wi_log('team_delete', ['target' => $email]); $back('User deleted.');
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
                    'password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'active' => true, 'created_at' => date('c')];
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
