<?php
/* ---------- Linking Facebook pages: one Facebook app, "Connect with Facebook", pick the pages ----------
 * The owner of the system saves the Facebook app (App ID + App secret) once; a client may save an app of their own. "Connect with Facebook"
 * opens Facebook's sign-in, the person allows the permissions, and the pages they manage are listed to pick from. Each picked page becomes
 * an account (same as a number): its token is stored encrypted and the page is subscribed to the webhook. The app has ONE webhook address,
 * which routes every event to the right page by its ID. */

const WF_FB_SCOPES = 'pages_show_list,pages_messaging,pages_manage_metadata,pages_read_engagement,pages_read_user_content,pages_manage_posts,pages_manage_engagement,read_insights';

/* ---------- the app ---------- */
/** Whose app: a client's own when they saved one, otherwise the system owner's (''). */
function wf_fb_scope_for(?string $email): string { $e = strtolower(trim((string) $email)); $A = (array) wi_read('fb_app.json', []); return $e !== '' && !empty($A[$e]['app_id']) ? $e : ''; }
function wf_fb_app(string $scope): array {
    $A = (array) (wi_read('fb_app.json', [])[$scope] ?? []);
    return ['scope' => $scope, 'app_id' => (string) ($A['app_id'] ?? ''), 'secret' => (string) wi_secret('fb_app_secret_app:' . $scope, ''), 'verify' => (string) ($A['verify'] ?? ''), 'key' => (string) ($A['key'] ?? ''), 'graph' => (string) ($A['graph'] ?? 'v26.0')];
}
function wf_fb_app_save(string $scope, string $appId, string $secret): array {
    $appId = preg_replace('/\D/', '', $appId); $secret = trim($secret);
    if ($appId === '') return ['ok' => false, 'error' => 'Write the App ID.'];
    $cur = wf_fb_app($scope); if ($secret === '' && $cur['secret'] === '') return ['ok' => false, 'error' => 'Write the App secret.'];
    if ($secret !== '') wi_secret_set('fb_app_secret_app:' . $scope, $secret);
    wi_update('fb_app.json', function ($A) use ($scope, $appId) { $o = (array) ($A[$scope] ?? []); $A[$scope] = ['app_id' => $appId, 'verify' => $o['verify'] ?? bin2hex(random_bytes(12)), 'key' => $o['key'] ?? substr(bin2hex(random_bytes(6)), 0, 10), 'graph' => $o['graph'] ?? 'v26.0'] + $o; return $A; });
    return ['ok' => true];
}
function wf_fb_base_url(): string { $host = (string) ($_SERVER['HTTP_HOST'] ?? ''); $dir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/'); return $host === '' ? '' : 'https://' . $host . $dir . '/'; }
function wf_fb_redirect_uri(): string { return wf_fb_base_url() . 'index.php?fb_cb=1'; }
function wf_fb_app_hook_url(array $app): string { return wf_fb_base_url() . 'index.php?fb_hook=app-' . $app['key']; }
function wf_fb_scope_by_key(string $key): ?string { foreach ((array) wi_read('fb_app.json', []) as $s => $a) if (($a['key'] ?? '') !== '' && hash_equals((string) $a['key'], $key)) return (string) $s; return null; }

/* ---------- sign-in with Facebook ---------- */
function wf_fb_oauth_start(): void {
    if (!wi_logged_in()) { header('Location: ./'); exit; }
    $scope = wf_fb_scope_for((string) ($_SESSION['email'] ?? '')); $app = wf_fb_app($scope);
    if ($app['app_id'] === '' || $app['secret'] === '') { wi_flash('Save the Facebook app (App ID and App secret) first.', 'bad'); header('Location: ?p=accounts'); exit; }
    $state = bin2hex(random_bytes(16));
    wi_update('fb_states.json', function ($S) use ($state, $scope) { foreach ($S as $k => $x) if (time() - (int) $x['at'] > 900) unset($S[$k]); $S[$state] = ['email' => strtolower((string) $_SESSION['email']), 'scope' => $scope, 'at' => time(), 'acc' => (string) ($_GET['acc'] ?? '')]; return $S; });
    header('Location: https://www.facebook.com/' . $app['graph'] . '/dialog/oauth?' . http_build_query(['client_id' => $app['app_id'], 'redirect_uri' => wf_fb_redirect_uri(), 'state' => $state, 'scope' => WF_FB_SCOPES, 'response_type' => 'code'])); exit;
}
function wf_fb_http_json(string $url): array { if (PHP_SAPI === 'cli' && isset($GLOBALS['WI_FAKE_HTTP']) && is_callable($GLOBALS['WI_FAKE_HTTP'])) return ($GLOBALS['WI_FAKE_HTTP'])($url); // tests
    $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 8]); $r = json_decode((string) curl_exec($ch), true); curl_close($ch); return is_array($r) ? $r : ['error' => ['message' => 'Facebook did not answer.']]; }
/** Facebook sends the person back here (a top-level redirect, so the one-time state says who it is, not the session). */
function wf_fb_oauth_callback(): void {
    $st = (string) ($_GET['state'] ?? ''); $S = (array) wi_read('fb_states.json', []); $mine = $st !== '' && isset($S[$st]) && time() - (int) $S[$st]['at'] < 900 ? $S[$st] : null;
    $fail = function (string $m) { wi_flash($m, 'bad'); header('Location: ?p=accounts'); exit; };
    if (!$mine) $fail('The Facebook sign-in expired. Try again.');
    wi_update('fb_states.json', function ($A) use ($st) { unset($A[$st]); return $A; });
    if (isset($_GET['error']) || empty($_GET['code'])) $fail('Facebook did not give access: ' . mb_substr((string) ($_GET['error_description'] ?? $_GET['error'] ?? ''), 0, 160));
    $app = wf_fb_app((string) $mine['scope']); $g = 'https://graph.facebook.com/' . $app['graph'] . '/';
    $t = wf_fb_http_json($g . 'oauth/access_token?' . http_build_query(['client_id' => $app['app_id'], 'client_secret' => $app['secret'], 'redirect_uri' => wf_fb_redirect_uri(), 'code' => (string) $_GET['code']]));
    if (empty($t['access_token'])) $fail('Facebook refused the sign-in: ' . mb_substr((string) ($t['error']['message'] ?? ''), 0, 160));
    $l = wf_fb_http_json($g . 'oauth/access_token?' . http_build_query(['grant_type' => 'fb_exchange_token', 'client_id' => $app['app_id'], 'client_secret' => $app['secret'], 'fb_exchange_token' => $t['access_token']])); // long-lived: the page tokens made from it do not expire
    $tok = (string) ($l['access_token'] ?? $t['access_token']);
    $p = wf_fb_http_json($g . 'me/accounts?' . http_build_query(['fields' => 'id,name,access_token,tasks', 'limit' => 100, 'access_token' => $tok]));
    $pages = array_values(array_filter((array) ($p['data'] ?? []), fn($x) => !empty($x['id']) && !empty($x['access_token'])));
    if (!$pages) $fail(isset($p['error']) ? 'Facebook: ' . mb_substr((string) $p['error']['message'], 0, 160) : 'No page found. Choose the pages to allow in Facebook\'s sign-in window.');
    $pk = bin2hex(random_bytes(12)); $meta = [];
    foreach ($pages as $x) { wi_secret_set('fb_pend:' . $pk . ':' . $x['id'], (string) $x['access_token']); $meta[] = ['id' => (string) $x['id'], 'name' => mb_substr((string) $x['name'], 0, 80)]; }
    wi_update('fb_pending.json', function ($P) use ($pk, $meta, $mine) { foreach ($P as $k => $x) if (time() - (int) $x['at'] > 900) unset($P[$k]); $P[$pk] = ['email' => $mine['email'], 'scope' => $mine['scope'], 'pages' => $meta, 'at' => time()]; return $P; });
    header('Location: ?p=accounts&fb_pick=' . $pk . '#fb-pick'); exit;
}
function wf_fb_pending(string $pk): ?array { $P = (array) (wi_read('fb_pending.json', [])[$pk] ?? []); return $P && time() - (int) $P['at'] < 900 && strtolower((string) ($_SESSION['email'] ?? '')) === (string) $P['email'] ? $P : null; }
/** The picked pages become accounts (a page already linked just gets its new token). */
function wf_fb_pick_add(string $pk, array $ids): array {
    $P = wf_fb_pending($pk); if (!$P) return ['ok' => false, 'error' => 'The Facebook sign-in expired. Try again.'];
    $done = []; $errs = [];
    foreach ($ids as $pid) { $pid = preg_replace('/\D/', '', (string) $pid); $row = null; foreach ($P['pages'] as $x) if ($x['id'] === $pid) $row = $x; if (!$row) continue;
        $tok = (string) wi_secret('fb_pend:' . $pk . ':' . $pid, ''); if ($tok === '') continue;
        $acc = wf_fb_account_for_page($pid); $aid = $acc ? (string) $acc['id'] : '';
        if (!$acc) { $accounts = wi_accounts(); $aid = 'fb' . substr(bin2hex(random_bytes(4)), 0, 6);
            $accounts[$aid] = ['id' => $aid, 'name' => $row['name'], 'channel' => 'fb', 'port' => 0, 'token' => bin2hex(random_bytes(24)), 'enabled' => true, 'payments' => false, 'autoreply' => false, 'created_at' => date('c'), 'created_by' => (string) ($_SESSION['email'] ?? ''), 'owner' => wi_is_super() ? null : (string) $_SESSION['email']];
            wi_save_accounts($accounts);
            if (!wi_is_super()) { $users = wi_users(); foreach ($users as &$uu) if (strtolower($uu['email']) === strtolower((string) $_SESSION['email'])) $uu['accounts'] = array_values(array_unique(array_merge((array) ($uu['accounts'] ?? []), [$aid]))); unset($uu); wi_save_users($users); }
            wi_log('account_add', ['account' => $aid, 'channel' => 'fb']); }
        wi_secret_set('fb_page_token:' . $aid, $tok); $app = wf_fb_app((string) $P['scope']); wi_secret_set('fb_app_secret:' . $aid, $app['secret']);
        wf_fb_cfg_set($aid, ['page_id' => $pid, 'page_name' => $row['name'], 'app_id' => $app['app_id'], 'app_scope' => (string) $P['scope'], 'via' => 'oauth', 'error' => '', 'verify' => $app['verify']]);
        $r = wf_fb_connect($aid); if (empty($r['ok'])) $errs[] = $row['name'] . ': ' . (string) $r['error']; else $done[] = $row['name']; $_SESSION['acc'] = $aid; }
    wi_update('fb_pending.json', function ($X) use ($pk) { unset($X[$pk]); return $X; });
    foreach ($P['pages'] as $x) wi_secret_set('fb_pend:' . $pk . ':' . $x['id'], '');
    return ['ok' => (bool) $done, 'done' => $done, 'errors' => $errs, 'error' => $errs ? implode(' · ', $errs) : ($done ? '' : 'Choose at least one page.')];
}

/* ---------- Facebook's deauthorize / data deletion calls (required by the app settings) ---------- */
function wf_fb_signed_request(string $sr): ?array {
    [$sig, $pay] = array_pad(explode('.', $sr, 2), 2, ''); if ($sig === '' || $pay === '') return null; $b = fn($s) => base64_decode(strtr($s, '-_', '+/'));
    $data = json_decode($b($pay), true); if (!is_array($data)) return null;
    foreach ((array) wi_read('fb_app.json', []) as $scope => $a) { $app = wf_fb_app((string) $scope); if ($app['secret'] !== '' && hash_equals(hash_hmac('sha256', $pay, $app['secret'], true), $b($sig))) return $data + ['_scope' => (string) $scope]; }
    return null;
}
function wf_fb_user_callback(string $kind): void {
    $d = wf_fb_signed_request((string) ($_POST['signed_request'] ?? '')); if (!$d) { http_response_code(403); exit; }
    $uid = (string) ($d['user_id'] ?? ''); $code = bin2hex(random_bytes(8));
    wi_log('fb_' . $kind, ['user' => $uid, 'code' => $code]);
    if ($kind === 'deauth') { foreach (wi_accounts() as $a) if (wf_fb_is($a) && wf_fb_cfg((string) $a['id'])['app_scope'] === $d['_scope']) { /* the person removed the app: its pages stop working until they connect again */ wf_fb_cfg_set((string) $a['id'], ['error' => 'The app was removed from Facebook. Connect the page again.']); } http_response_code(200); echo 'ok'; exit; }
    header('Content-Type: application/json'); echo json_encode(['url' => wf_fb_base_url() . 'index.php?fb_deleted=' . $code, 'confirmation_code' => $code]); exit;
}
