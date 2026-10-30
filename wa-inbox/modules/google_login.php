<?php
// Sign in / sign up with a Google account, through the installation's own Google app (the one Drive and Meet use).
// Google gives the email and the name only: a new person still types their WhatsApp number and confirms it with a code,
// and a known person still gets the usual WhatsApp code, so Google never skips the second step.

/** The installation's Google app: [client id, client secret], or empty strings when it is not set up. */
/** The Google client for signing in: its own (Settings → System → Sign-up and Google sign-in) when both parts are set, else the Drive client (kept for older setups). */
function wf_glogin_app(): array {
    $id = trim((string) wi_sys('login_google_client_id')); $sec = wi_secret('login_google_client_secret'); if ($id !== '' && $sec !== '') return [$id, $sec];
    $op = wi_read('drive.json', []); return [(string) ($op['client_id'] ?? ''), !empty($op['client_secret']) ? wi_secret('google_client_secret', (string) $op['client_secret']) : ''];
}
function wf_glogin_on(): bool { [$id, $sec] = wf_glogin_app(); return $id !== '' && $sec !== '' && (bool) wi_sys('google_login_on'); }
function wf_glogin_redirect(): string { return wi_sys('base_url') . '?google_cb=1'; }
/** ?google_login=1: off to Google. */
function wf_glogin_start(): void {
    if (!wf_glogin_on()) { header('Location: ./?login'); exit; }
    [$id] = wf_glogin_app(); $st = bin2hex(random_bytes(16)); $_SESSION['glogin_state'] = ['s' => $st, 'at' => time(), 'signup' => !empty($_GET['signup'])];
    header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query(['client_id' => $id, 'redirect_uri' => wf_glogin_redirect(), 'response_type' => 'code', 'scope' => 'openid email profile', 'state' => $st, 'prompt' => 'select_account'])); exit;
}
/** Back to the sign-in page with a message. */
function wf_glogin_fail(string $m): never { wi_flash($m, 'bad'); header('Location: ./?login'); exit; }
/** ?google_cb=1: back from Google with a code. */
function wf_glogin_callback(): void {
    $S = (array) ($_SESSION['glogin_state'] ?? []); unset($_SESSION['glogin_state']);
    if (!wf_glogin_on() || !$S || !hash_equals((string) $S['s'], (string) ($_GET['state'] ?? '')) || time() - (int) $S['at'] > 600 || empty($_GET['code'])) wf_glogin_fail('Google sign-in did not finish. Try again.');
    [$id, $sec] = wf_glogin_app();
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['code' => (string) $_GET['code'], 'client_id' => $id, 'client_secret' => $sec, 'redirect_uri' => wf_glogin_redirect(), 'grant_type' => 'authorization_code'])]);
    $t = json_decode((string) curl_exec($ch), true) ?: []; curl_close($ch);
    $parts = explode('.', (string) ($t['id_token'] ?? '')); $p = count($parts) === 3 ? (json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true) ?: []) : [];
    $email = strtolower(trim((string) ($p['email'] ?? '')));
    if ($email === '' || empty($p['email_verified']) || (string) ($p['aud'] ?? '') !== $id) wf_glogin_fail('Google sign-in did not finish. Try again.');
    $u = wi_find_user($email); if ($u && !wi_host_allows($u)) $u = null;
    wi_log('google_login', ['email' => $email, 'known' => (bool) $u]);
    if (!$u) {
        if (!wi_sys('signup_on')) wf_glogin_fail('No account uses this Google email.');
        $_SESSION['gsignup'] = ['email' => $email, 'name' => mb_substr(trim((string) ($p['name'] ?? '')), 0, 80), 'at' => time()];
        header('Location: ./?signup'); exit;
    }
    if (($blk = wi_access_blocked($u)) !== '') wf_glogin_fail($blk);
    $code = (string) random_int(100000, 999999);
    $_SESSION['pending'] = ['email' => $email, 'hash' => password_hash($code, PASSWORD_DEFAULT), 'at' => time(), 'tries' => 0];
    if (!wi_send_whatsapp((string) $u['phone'], wf_msg('otp', ['code' => $code, 'minutes' => (int) wi_sys('otp_min')]))) { unset($_SESSION['pending']); wf_glogin_fail('Could not send the code on WhatsApp. Check that the gateway is connected.'); }
    wi_flash('A 6-digit code was sent to your WhatsApp.', 'ok'); header('Location: ./'); exit;
}
