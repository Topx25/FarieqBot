<?php
// A client deletes their own account (My account): typed e-mail + a tick that all data will be lost, then a code sent to the e-mail (optional data file). The client, their team and their
// numbers stop at once; after WF_ACDEL_DAYS days everything is erased for good (the super admin can restore before that, or erase now).
const WF_ACDEL_DAYS = 14;

/** The card on My account (clients only, not while the super admin views a client's panel). */
function wf_acdel_card(): string {
    $me = wi_me(); if (!$me || wi_is_super() || !empty($_SESSION['imp_from']) || ($me['role'] ?? '') !== 'client') return '';
    $t = fn($s) => wi_e(wi_t($s)); $csrf = '<input type="hidden" name="_csrf" value="' . wi_e(wi_csrf()) . '">';
    $o = '<div class="card acdel"><div class="card-h"><h2>⚠️ ' . $t('Delete my account') . '</h2></div>'
        . '<p>' . $t('Deleting your account erases all its data for good: numbers, conversations, files, cases, library, bot settings and your team. This cannot be undone.') . '</p>'
        . '<p class="muted small">' . sprintf(wi_e(wi_t('Your account stops at once and the data is erased after %d days. Download a backup first if you need it.')), WF_ACDEL_DAYS) . '</p>';
    // step 2: a code was sent to the e-mail; deleting needs it
    $p = wf_acdel_pending((string) $me['email']);
    if ($p) return $o . '<form method="post" class="stack acdel-step2">' . $csrf . '<input type="hidden" name="do" value="acdel_confirm">'
        . '<p class="acdel-sent">📧 ' . wi_e(sprintf(wi_t('We sent a deletion code to %s. Write it here to delete the account for good.'), (string) $me['email'])) . '</p>'
        . '<label>' . $t('Deletion code') . '<input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" dir="ltr" required class="acdel-code"></label>'
        . (!empty($p['export']) ? '<p class="small">📦 ' . $t('Your data file will be prepared and its download link sent to your e-mail and WhatsApp.') . '</p>' : '')
        . '<div class="row wrap"><button class="btn danger" onclick="return confirm(this.dataset.q)" data-q="' . $t('Delete your account and all its data for good?') . '">🗑 ' . $t('Delete my account for good') . '</button>'
        . '<button class="btn ghost sm" name="act" value="cancel" formnovalidate>' . $t('Cancel, keep my account') . '</button></div></form></div>';
    // step 1: the e-mail typed again, the warning ticked, and the data file if wanted; then the code goes to the e-mail
    return $o . '<details><summary class="btn ghost sm acdel-open">' . $t('Delete my account') . '</summary><form method="post" class="stack" style="margin-top:10px">'
        . $csrf . '<input type="hidden" name="do" value="acdel_request">'
        . '<label>' . $t('Write your e-mail to confirm') . '<input name="confirm_email" dir="ltr" autocomplete="off" required></label>'
        . '<label class="check"><input type="checkbox" name="export" value="1" checked> 📦 ' . $t('Send me a file with all my data before deleting') . '</label>'
        . '<label class="acdel-ck"><input type="checkbox" name="understand" value="1" required> ' . $t('I understand that all my data will be lost and cannot be brought back.') . '</label>'
        . '<button class="btn danger">📧 ' . $t('Send me the deletion code') . '</button><p class="muted small">' . $t('A code is sent to your e-mail. Nothing is deleted until you write it here.') . '</p></form></details></div>';
}
/** The code waiting for this e-mail (15 minutes), or []. */
function wf_acdel_pending(string $email): array { $p = (array) (wi_read('acdel_codes.json', [])[strtolower($email)] ?? []); return $p && (int) ($p['exp'] ?? 0) > time() ? $p : []; }
/** The client's team (same tenant) and numbers they own. */
function wf_acdel_scope(string $email): array {
    $email = strtolower($email); $team = [];
    foreach (wi_users() as $u) if (strtolower((string) ($u['tenant'] ?? '')) === $email && in_array($u['role'] ?? '', WI_TEAM_ROLES, true)) $team[] = strtolower((string) $u['email']);
    $accs = array_keys(array_filter(wi_accounts(), fn($a) => strtolower((string) ($a['owner'] ?? '')) === $email && empty($a['main'])));
    return [$team, array_map('strval', $accs)];
}
/** Stop or start the client, the team and the numbers. */
function wf_acdel_switch(string $email, bool $on, array $accs): void {
    $email = strtolower($email); [$team] = wf_acdel_scope($email); $who = array_merge([$email], $team);
    $users = wi_users(); foreach ($users as &$u) if (in_array(strtolower((string) $u['email']), $who, true)) $u['active'] = $on; unset($u); wi_save_users($users);
    $A = wi_accounts(); foreach ($accs as $id) if (isset($A[$id])) $A[$id]['enabled'] = $on; wi_save_accounts($A);
}
/** POST do=acdel_request from the client: the e-mail and the tick checked, then a 6-digit code goes to the e-mail (WhatsApp too when the e-mail cannot be sent). */
function wf_acdel_request(): void {
    $me = wi_me(); if (!$me || wi_is_super() || !empty($_SESSION['imp_from']) || ($me['role'] ?? '') !== 'client') { header('Location: ./'); exit; }
    $email = strtolower((string) $me['email']);
    if (strtolower(trim((string) ($_POST['confirm_email'] ?? ''))) !== $email || empty($_POST['understand'])) { wi_flash('The e-mail does not match. Nothing was deleted.', 'bad'); header('Location: ?p=me#danger'); exit; }
    $code = (string) random_int(100000, 999999); $export = !empty($_POST['export']);
    wi_update('acdel_codes.json', function ($C) use ($email, $code, $export) { $C = (array) $C; $C[$email] = ['h' => password_hash($code, PASSWORD_DEFAULT), 'exp' => time() + 900, 'tries' => 0, 'export' => $export]; return $C; });
    $msg = wf_msg('acdel_code', ['code' => $code, 'days' => (string) WF_ACDEL_DAYS, 'email' => $email]);
    $sent = function_exists('wi_mail_on') && wi_mail_on() && wi_mail($email, wi_t('Account deletion code'), $msg);
    if (!$sent && !empty($me['phone'])) $sent = wi_send_code((string) $me['phone'], $msg, $code);
    wi_log('account_delete_code', ['email' => $email, 'sent' => $sent, 'export' => $export]);
    wi_flash($sent ? 'The deletion code was sent to your e-mail. Write it below to finish.' : 'The code could not be sent. Try again later or contact us.', $sent ? 'ok' : 'bad'); header('Location: ?p=me#danger'); exit;
}
/** POST do=acdel_confirm: the right code (5 tries) stops the account at once; with the data file, its link is sent after. act=cancel drops the request. */
function wf_acdel_confirm(): void {
    $me = wi_me(); if (!$me || wi_is_super() || !empty($_SESSION['imp_from']) || ($me['role'] ?? '') !== 'client') { header('Location: ./'); exit; }
    $email = strtolower((string) $me['email']); $p = wf_acdel_pending($email);
    if (($_POST['act'] ?? '') === 'cancel') { wi_update('acdel_codes.json', function ($C) use ($email) { $C = (array) $C; unset($C[$email]); return $C; }); wi_log('account_delete_cancel', ['email' => $email]); wi_flash('Deletion cancelled. Your account stays.', 'ok'); header('Location: ?p=me#danger'); exit; }
    if (!$p) { wi_flash('The code expired. Ask for a new one.', 'bad'); header('Location: ?p=me#danger'); exit; }
    $code = preg_replace('/\D/', '', strtr((string) ($_POST['code'] ?? ''), ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']));
    if (!password_verify($code, (string) $p['h'])) { $left = 0; wi_update('acdel_codes.json', function ($C) use ($email, &$left) { $C = (array) $C; $C[$email]['tries'] = (int) ($C[$email]['tries'] ?? 0) + 1; $left = 5 - $C[$email]['tries']; if ($left <= 0) unset($C[$email]); return $C; });
        wi_flash($left > 0 ? 'Wrong code. Nothing was deleted.' : 'Too many wrong codes. Ask for a new one.', 'bad'); header('Location: ?p=me#danger'); exit; }
    wi_update('acdel_codes.json', function ($C) use ($email) { $C = (array) $C; unset($C[$email]); return $C; });
    [$team, $accs] = wf_acdel_scope($email); $phone = (string) ($me['phone'] ?? '');
    wi_update('account_delete.json', function ($R) use ($email, $team, $accs, $p) { $R = (array) $R; $R[$email] = ['at' => date('c'), 'due' => date('c', time() + WF_ACDEL_DAYS * 86400), 'team' => $team, 'accounts' => $accs, 'ip' => wi_ip(), 'export' => !empty($p['export'])]; return $R; });
    wf_acdel_switch($email, false, $accs); wi_log('account_delete_request', ['email' => $email, 'accounts' => $accs, 'team' => count($team), 'confirmed_by' => 'email code']);
    $_SESSION = []; session_destroy(); session_start(); wi_flash('Your account was stopped and its data will be erased.', 'ok'); header('Location: ./');
    // the data file: made after the page has answered, kept until the erase date, its link sent by e-mail and WhatsApp
    if (!empty($p['export'])) { if (function_exists('fastcgi_finish_request')) fastcgi_finish_request(); ignore_user_abort(true); @set_time_limit(900); wf_acdel_export($email, $phone); }
    exit;
}
/** Builds the client's data file (chats, settings, cases, learning, team) and sends its download link (valid until the erase date). */
function wf_acdel_export(string $email, string $phone): bool {
    if (!function_exists('wf_client_backup_run')) return false; $r = wf_client_backup_run($email, true); if (empty($r['ok']) || empty($r['file'])) { wi_log('account_delete_export', ['email' => $email, 'ok' => false, 'error' => (string) ($r['error'] ?? '')]); return false; }
    $tok = bin2hex(random_bytes(16)); $dir = WI_DIR . '/acdel_exports'; if (!is_dir($dir)) @mkdir($dir, 0700, true); @rename((string) $r['file'], $dir . '/' . $tok . '.tar.gz');
    wi_update('account_delete.json', function ($R) use ($email, $tok) { $R = (array) $R; $R[$email]['export_token'] = $tok; return $R; });
    $link = rtrim((string) wi_sys('base_url'), '/') . '/?acdel_dl=' . $tok; $msg = wf_msg('acdel_export', ['link' => $link, 'days' => (string) WF_ACDEL_DAYS]);
    $ok = function_exists('wi_mail_on') && wi_mail_on() && wi_mail($email, wi_t('Your data file'), $msg); if ($phone !== '') $ok = wi_send_whatsapp($phone, $msg) || $ok;
    wi_log('account_delete_export', ['email' => $email, 'ok' => $ok, 'size' => (int) ($r['size'] ?? 0)]); return $ok;
}
/** ?acdel_dl=<token>: the data file of an account being deleted, until it is erased. */
function wf_acdel_download(string $tok): void {
    $tok = preg_replace('/[^a-f0-9]/', '', $tok); $f = WI_DIR . '/acdel_exports/' . $tok . '.tar.gz'; $ok = false;
    foreach ((array) wi_read('account_delete.json', []) as $r) if (($r['export_token'] ?? '') === $tok && $tok !== '' && empty($r['done'])) $ok = true;
    if (!$ok || !is_file($f)) { http_response_code(404); exit('Not found or expired.'); }
    header('Content-Type: application/gzip'); header('Content-Disposition: attachment; filename="my-data.tar.gz"'); header('Content-Length: ' . filesize($f)); readfile($f); exit;
}
/** Erase one client for good: users, numbers (list, settings, messages, media, folder) and their store rows. */
function wf_acdel_purge(string $email): array {
    $email = strtolower($email); $R = (array) wi_read('account_delete.json', []); $r = (array) ($R[$email] ?? []); if (!$r) return ['ok' => false];
    $accs = array_values(array_filter((array) ($r['accounts'] ?? []), fn($id) => $id !== 'main' && preg_match('/^[a-z0-9_-]+$/', $id))); $who = array_merge([$email], (array) ($r['team'] ?? []));
    $root = dirname(__DIR__, 2); $rm = function (string $p) use (&$rm) { if (is_link($p) || is_file($p)) return @unlink($p); if (!is_dir($p)) return false; foreach (array_diff((array) scandir($p), ['.', '..']) as $x) $rm($p . '/' . $x); return @rmdir($p); };
    $A = wi_accounts(); $folders = [];
    foreach ($accs as $id) { if (!empty($A[$id]['main'])) continue; if (function_exists('wi_client_folder') && ($f = wi_client_folder($id, $email))) $folders[$f] = 1;
        $rm($root . '/wa-local/accounts/' . $id); $rm($root . '/storage/inbox_media/' . $id); unset($A[$id]); }
    wi_save_accounts($A);
    if (function_exists('wf_cases_update')) foreach ($accs as $id) wf_cases_update($id, fn($C) => []); // the cases go through their own module
    foreach (['bot.json', 'case_intake.json', 'cmd_ai.json'] as $st) wi_update($st, function ($S) use ($accs) { $S = (array) $S; foreach ($accs as $id) unset($S[$id]); return $S; });
    foreach (array_keys($folders) as $f) if (str_starts_with((string) realpath($f), $root . '/clients/')) $rm($f);
    wi_save_users(array_filter(wi_users(), fn($u) => !in_array(strtolower((string) $u['email']), $who, true)));
    if (!empty($r['export_token'])) @unlink(WI_DIR . '/acdel_exports/' . preg_replace('/[^a-f0-9]/', '', (string) $r['export_token']) . '.tar.gz');
    wi_update('account_delete.json', function ($R) use ($email) { $R = (array) $R; $R[$email]['done'] = date('c'); return $R; });
    wi_log('account_purged', ['email' => $email, 'accounts' => $accs, 'users' => count($who)]); return ['ok' => true, 'accounts' => $accs];
}
/** Cron (daily): requests past their date are erased. */
function wf_acdel_due(): void { foreach ((array) wi_read('account_delete.json', []) as $em => $r) if (empty($r['done']) && strtotime((string) ($r['due'] ?? '')) < time()) wf_acdel_purge((string) $em); }
/** Super admin: the open requests (restore, or erase now), on the system overview. */
function wf_acdel_admin_card(): string {
    $open = array_filter((array) wi_read('account_delete.json', []), fn($r) => empty($r['done'])); if (!$open || !wi_is_super()) return '';
    $t = fn($s) => wi_e(wi_t($s)); $o = '<div class="card"><div class="card-h"><h2>🗑 ' . $t('Account deletion requests') . '</h2></div><table class="tbl">';
    foreach ($open as $em => $r) $o .= '<tr><td dir="ltr">' . wi_e((string) $em) . '</td><td class="small">' . $t('Erased on') . ' <span dir="ltr">' . wi_e(substr((string) $r['due'], 0, 10)) . '</span> · ' . count((array) $r['accounts']) . ' ' . $t('numbers') . '</td><td><form method="post" class="row" style="gap:6px"><input type="hidden" name="_csrf" value="' . wi_e(wi_csrf()) . '"><input type="hidden" name="do" value="acdel_admin"><input type="hidden" name="email" value="' . wi_e((string) $em) . '">'
        . '<button class="btn ghost sm" name="act" value="restore">↩ ' . $t('Restore') . '</button><button class="btn ghost sm" name="act" value="purge" onclick="return confirm(this.dataset.q)" data-q="' . $t('Erase this account and all its data now? This cannot be undone.') . '">🗑 ' . $t('Erase now') . '</button></form></td></tr>';
    return $o . '</table></div>';
}
function wf_acdel_admin(): void {
    if (!wi_is_super()) { http_response_code(403); exit; } $em = strtolower((string) ($_POST['email'] ?? '')); $r = (array) (wi_read('account_delete.json', [])[$em] ?? []);
    if ($r && empty($r['done'])) {
        if (($_POST['act'] ?? '') === 'restore') { wf_acdel_switch($em, true, (array) $r['accounts']); wi_update('account_delete.json', function ($R) use ($em) { $R = (array) $R; unset($R[$em]); return $R; }); wi_log('account_delete_restore', ['email' => $em]); wi_flash('Account restored.', 'ok'); }
        if (($_POST['act'] ?? '') === 'purge') { wf_acdel_purge($em); wi_flash('Account erased.', 'ok'); } }
    header('Location: ?p=saas'); exit;
}
