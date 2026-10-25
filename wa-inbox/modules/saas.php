<?php
declare(strict_types=1);
/*
 * SaaS — Topx1.Host
 * Plans with real limits (numbers, team seats, bot, meetings, cases, backups, AI calls on the operator's keys),
 * self sign-up with a trial, and the public pricing page. Plans are edited by the super admin (Clients page);
 * these are only the starting values.
 */

/** The plans: the saved ones (Clients → Plans), or the starting set. */
function wi_plans(): array {
    $saved = wi_read('plans.json', []);
    if (is_array($saved) && $saved) return $saved;
    return [
        'trial' => ['name' => 'Trial', 'price' => 0, 'days' => 14, 'numbers' => 1, 'team' => 2, 'bot' => true, 'meetings' => true, 'cases' => true, 'backup' => false, 'ai_calls' => 300, 'media_gb' => 0, 'public' => true, 'trial' => true],
        'starter' => ['name' => 'Starter', 'price' => 7, 'days' => 30, 'numbers' => 1, 'team' => 2, 'bot' => true, 'meetings' => false, 'cases' => true, 'backup' => false, 'ai_calls' => 500, 'media_gb' => 0, 'public' => true, 'trial' => false],
        'basic' => ['name' => 'Basic', 'price' => 15, 'days' => 30, 'numbers' => 1, 'team' => 5, 'bot' => true, 'meetings' => false, 'cases' => true, 'backup' => true, 'ai_calls' => 2000, 'media_gb' => 0, 'public' => true, 'trial' => false],
        'pro' => ['name' => 'Pro', 'price' => 35, 'days' => 30, 'numbers' => 3, 'team' => 15, 'bot' => true, 'meetings' => true, 'cases' => true, 'backup' => true, 'ai_calls' => 6000, 'media_gb' => 0, 'public' => true, 'trial' => false],
    ];
}
/** One plan, cleaned. */
function wi_plan_clean(array $p): array {
    return ['name' => mb_substr(trim((string) ($p['name'] ?? '')), 0, 40), 'price' => max(0, round((float) ($p['price'] ?? 0), 2)), 'days' => max(1, min(3650, (int) ($p['days'] ?? 30))),
        'numbers' => max(0, min(100, (int) ($p['numbers'] ?? 1))), 'team' => max(0, min(500, (int) ($p['team'] ?? 0))), 'ai_calls' => max(0, min(1000000, (int) ($p['ai_calls'] ?? 0))), 'media_gb' => max(0, min(100000, (int) ($p['media_gb'] ?? 0))),
        'bot' => !empty($p['bot']), 'meetings' => !empty($p['meetings']), 'cases' => !empty($p['cases']), 'backup' => !empty($p['backup']), 'public' => !empty($p['public']), 'trial' => !empty($p['trial'])];
}
/** The plan a client is on (by its id), or null for a hand-made client. */
function wi_client_plan(array $client): ?array { $id = (string) ($client['plan_id'] ?? ''); $p = wi_plans()[$id] ?? null; return is_array($p) ? $p : null; }
/** Gives a client the limits and features of a plan (the super admin can still change a field by hand afterwards). */
function wi_plan_apply(array $client, string $planId, bool $setEnd): array {
    $p = wi_plans()[$planId] ?? null; if (!$p) return $client;
    $client['plan_id'] = $planId; $client['plan'] = (string) $p['name'];
    $client['max_numbers'] = (int) $p['numbers']; $client['max_team'] = (int) $p['team']; $client['ai_calls'] = (int) $p['ai_calls']; $client['media_gb'] = (int) ($p['media_gb'] ?? 0);
    $client['can_bot'] = !empty($p['bot']); $client['can_meetings'] = !empty($p['meetings']); $client['can_cases'] = !empty($p['cases']); $client['can_backup'] = !empty($p['backup']);
    if ($setEnd) $client['expires'] = date('Y-m-d', strtotime('+' . (int) $p['days'] . ' days'));
    return $client;
}
/* ---------- Media allowance (plan field media_gb, 0 = unlimited) and the plan usage section ---------- */
/** A client's media allowance in MB; 0 = unlimited (no client, the operator's own panels, or media_gb 0). */
function wi_media_allow_mb(?array $client): int {
    if (!$client || ($client['role'] ?? '') !== 'client' || !empty($client['ai_system'])) return 0;
    return max(0, (int) ($client['media_gb'] ?? 0)) * 1024;
}
/** The client who owns a number, or null (the operator's own number). */
function wi_account_client(array $acc): ?array {
    $o = strtolower((string) ($acc['owner'] ?? '')); if ($o === '') return null;
    foreach (wi_users() as $u) if (strtolower((string) ($u['email'] ?? '')) === $o && ($u['role'] ?? '') === 'client') return $u;
    return null;
}
/** MB kept per number, counted at most every 10 minutes (store media_usage.json); each number's gateway gets its owner's allowance and use (media_allow.json). */
function wi_media_usage_refresh(bool $force = false): array {
    $U = wi_read('media_usage.json', []); if (!$force && (int) ($U['at'] ?? 0) > time() - 600) return $U;
    $root = dirname(__DIR__, 2); $mb = [];
    foreach (wi_accounts() as $aid => $a) { $bytes = 0; $d = $root . '/storage/inbox_media/' . $aid;
        if (is_dir($d)) foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS)) as $fi) $bytes += (int) $fi->getSize();
        $mb[(string) $aid] = (int) round($bytes / 1048576); }
    $U = ['at' => time(), 'acc' => $mb]; wi_write('media_usage.json', $U);
    foreach (wi_accounts() as $aid => $a) { $c = wi_account_client($a); $cap = wi_media_allow_mb($c);
        $used = $c ? array_sum(array_map(fn($x) => (int) ($mb[(string) $x] ?? 0), array_keys(wi_owned_accounts((string) $c['email'])))) : (int) ($mb[(string) $aid] ?? 0);
        $f = $aid === 'main' ? $root . '/wa-local/media_allow.main.json' : $root . '/wa-local/accounts/' . preg_replace('/[^a-z0-9_-]/', '', (string) $aid) . '/media_allow.json';
        if (is_dir(dirname($f))) @file_put_contents($f, json_encode(['cap_mb' => $cap, 'used_mb' => $used, 'at' => date('c')])); }
    return $U;
}
/** True when a number's owner has used the whole media allowance of the plan (new files are not downloaded). */
function wi_media_full(array $acc): bool {
    $c = wi_account_client($acc); $cap = wi_media_allow_mb($c); if ($cap <= 0) return false;
    $mb = (array) (wi_read('media_usage.json', [])['acc'] ?? []);
    return array_sum(array_map(fn($x) => (int) ($mb[(string) $x] ?? 0), array_keys(wi_owned_accounts((string) $c['email'])))) >= $cap;
}
/** A client's plan: each allowance with its use (numbers, team, AI replies, media, messages) and the days left. */
function wi_plan_usage(string $email): array {
    $e = strtolower(trim($email)); $c = null; foreach (wi_users() as $u) if (strtolower((string) ($u['email'] ?? '')) === $e) { $c = $u; break; }
    if (!$c) return ['ok' => false, 'rows' => []];
    $row = function (string $key, string $label, $used, $max, string $unit = '') { $max = max(0, $max); $pct = $max > 0 ? (int) min(999, round($used * 100 / $max)) : 0;
        return ['key' => $key, 'label' => $label, 'used' => $used, 'max' => $max, 'unit' => $unit, 'pct' => $pct, 'level' => $max <= 0 ? 'ok' : ($pct >= 100 ? 'full' : ($pct >= 80 ? 'warn' : 'ok'))]; };
    $nums = array_keys(wi_owned_accounts($e)); $mb = (array) (wi_read('media_usage.json', [])['acc'] ?? []);
    $mediaMb = array_sum(array_map(fn($x) => (int) ($mb[(string) $x] ?? 0), $nums));
    $msgs = 0; $Us = wi_read('usage.json', []); foreach ($nums as $aid) { $x = (array) ($Us[$aid][date('Y-m')] ?? []); $msgs += (int) ($x['in'] ?? 0) + (int) ($x['out'] ?? 0); }
    $rows = [$row('numbers', 'WhatsApp numbers', count($nums), wi_client_max_numbers($c)), $row('team', 'Team members', count(wi_team_of($e)), wi_team_seats($c)),
        $row('ai', 'AI replies this month', (int) (wi_read('ai_allowance.json', [])[$e][date('Y-m')] ?? 0), (int) ($c['ai_calls'] ?? 0)),
        $row('media', 'Media', round($mediaMb / 1024, 2), (int) ($c['media_gb'] ?? 0), 'GB')];
    if ($msgs > 0) $rows[] = $row('messages', 'Messages this month', $msgs, 0);
    $days = !empty($c['expires']) ? (int) floor((strtotime((string) $c['expires'] . ' 23:59:59') - time()) / 86400) : null;
    return ['ok' => true, 'plan' => (string) ($c['plan'] ?? ''), 'days_left' => $days, 'rows' => $rows, 'unlimited_media' => wi_media_allow_mb($c) <= 0];
}
/** The plan usage section (client home and, compact, each client on the Clients page): a row per allowance with a bar, coloured at 80% and 100%. */
function wi_plan_usage_html(array $pu, bool $compact = false): string {
    if (empty($pu['ok'])) return '';
    $fmt = fn($v) => is_float($v) ? number_format($v, 2) : number_format((int) $v);
    $h = '<section class="card pu' . ($compact ? ' pu-c' : '') . '">' . ($compact ? '' : '<div class="card-h"><h2>📦 ' . wi_e(wi_t('Plan limits and usage')) . '</h2><a class="btn primary sm" href="pricing.php">' . wi_e(wi_t('Upgrade the plan')) . '</a></div>') . '<div class="pu-rows">';
    foreach ($pu['rows'] as $r) { $lim = $r['max'] > 0 ? $fmt($r['max']) . ($r['unit'] !== '' ? ' ' . $r['unit'] : '') : wi_t('Unlimited');
        $h .= '<div class="pu-r pu-' . wi_e($r['level']) . '"><div class="pu-t"><span>' . wi_e(wi_t($r['label'])) . '</span><b dir="ltr">' . $fmt($r['used']) . ($r['unit'] !== '' ? ' ' . wi_e($r['unit']) : '') . ' / ' . wi_e($lim) . '</b></div>'
            . '<div class="pu-bar"><span style="width:' . ($r['max'] > 0 ? min(100, (int) $r['pct']) : 0) . '%"></span></div></div>'; }
    if ($pu['days_left'] !== null) $h .= '<div class="pu-r pu-' . ($pu['days_left'] < 0 ? 'full' : ($pu['days_left'] <= 7 ? 'warn' : 'ok')) . '"><div class="pu-t"><span>' . wi_e(wi_t('Days left in the plan')) . '</span><b dir="ltr">' . (int) $pu['days_left'] . '</b></div></div>';
    return $h . '</div></section>';
}
/** API plan_usage: the signed-in client's own plan (the super admin may ask for any client with ?email=). */
function wf_api_plan_usage(string $api): void {
    if ($api !== 'plan_usage') return;
    $me = wi_me(); $email = wi_is_super() && !empty($_GET['email']) ? (string) $_GET['email'] : (string) (($me['role'] ?? '') === 'client' ? $me['email'] : (wi_tenant($me)['email'] ?? ''));
    if ($email === '') wi_json(['ok' => false, 'error' => 'Not allowed.'], 403);
    wi_json(wi_plan_usage($email));
}
/** How many numbers a client may link: their plan's, or 1 for a hand-made client. */
function wi_client_max_numbers(array $client): int { return max(0, (int) ($client['max_numbers'] ?? 1)); }

/* ---------- AI on the operator's keys: a monthly allowance per client ---------- */
/** True when the client has AI keys of their own that are switched on. */
function wi_client_has_ai(string $email): bool {
    $s = (array) wi_read('ai_providers_u' . substr(md5(strtolower($email)), 0, 10) . '.json', []);
    foreach ((array) ($s['providers'] ?? []) as $p) if (!empty($p['enabled']) && (!empty($p['api_key']) || !empty($p['extra_keys']))) return true;
    return false;
}
/** Calls left this month on the operator's keys for a client (0 = none). */
function wi_ai_allowance_left(array $client): int {
    $quota = (int) ($client['ai_calls'] ?? 0); if ($quota <= 0) return 0;
    $used = (int) (wi_read('ai_allowance.json', [])[strtolower((string) $client['email'])][date('Y-m')] ?? 0);
    return max(0, $quota - $used);
}
function wi_ai_allowance_use(string $email): void {
    $e = strtolower($email); $m = date('Y-m');
    wi_update('ai_allowance.json', function ($A) use ($e, $m) { $A[$e][$m] = (int) ($A[$e][$m] ?? 0) + 1; foreach ((array) $A[$e] as $k => $_) if ($k < date('Y-m', strtotime('-3 months'))) unset($A[$e][$k]); return $A; });
}

/* ---------- Self sign-up ---------- */
/** Creates a client on the trial plan. Returns '' or the reason it was refused. */
function wi_signup(array $in): string {
    if (!wi_sys('signup_on')) return 'Sign-up is closed. Contact us.';
    $name = trim(mb_substr((string) ($in['name'] ?? ''), 0, 80)); $biz = trim(mb_substr((string) ($in['business'] ?? ''), 0, 120));
    $email = strtolower(trim((string) ($in['email'] ?? ''))); $phone = wi_norm_phone((string) ($in['phone'] ?? ''));
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $phone === '') return 'Fill in your name, a valid email and your WhatsApp number.';
    $pw = (string) ($in['password'] ?? ''); if (strlen($pw) < 10) return 'Password must be at least 10 characters.';
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? ''); $limited = false;
    wi_update('signup_ips.json', function ($S) use ($ip, &$limited) { $S = array_filter((array) $S, fn($t) => max((array) $t) > time() - 86400);
        $S[$ip] = array_values(array_filter((array) ($S[$ip] ?? []), fn($t) => $t > time() - 86400)); if (count($S[$ip]) >= 3) $limited = true; else $S[$ip][] = time(); return $S; });
    if ($limited) return 'Too many sign-ups from this connection today. Try again tomorrow.';
    foreach (wi_users() as $u) { if (strtolower((string) $u['email']) === $email) return 'This email already has an account. Sign in instead.'; if ((string) ($u['phone'] ?? '') === $phone) return 'This WhatsApp number already has an account. Sign in instead.'; }
    $planId = ''; foreach (wi_plans() as $id => $p) if (!empty($p['trial'])) { $planId = (string) $id; break; }
    if ($planId === '') return 'Sign-up is closed. Contact us.';
    $u = ['email' => $email, 'name' => $name, 'business' => $biz, 'phone' => $phone, 'role' => 'client', 'accounts' => [], 'can_reply' => true, 'can_link' => true, 'own_number' => true,
        'password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'active' => true, 'created_at' => date('c'), 'signup' => true];
    $u = wi_plan_apply($u, $planId, true);
    $all = wi_users(); $all[] = $u; wi_save_users($all);
    wi_log('signup', ['target' => $email, 'plan' => $planId]);
    return '';
}

/* ---------- The client's own address: a subdomain or their own domain shows their panel with their name and logo ---------- */
/** Host of this request, lower case, without the port. */
function wi_req_host(): string { return strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? ''))); }
/** The client whose address this request came to (a domain linked to them, or <slug>.<SaaS domain>), else null. */
function wi_host_client(): ?array {
    static $hit = false, $val = null; if ($hit) return $val; $hit = true;
    $h = wi_req_host(); if ($h === '') return null;
    $base = strtolower(trim((string) wi_sys('saas_domain')));
    foreach (wi_users() as $u) {
        if (($u['role'] ?? '') !== 'client') continue;
        if (in_array($h, array_map('strtolower', (array) ($u['domains'] ?? [])), true)) return $val = $u;
        if ($base !== '' && ($u['slug'] ?? '') !== '' && $h === strtolower((string) $u['slug']) . '.' . $base) return $val = $u;
    }
    return null;
}
/** Brand shown on the page: the client's own (on their address) or the system's. */
function wi_brand(string $k): string {
    $c = wi_host_client(); $v = $c ? trim((string) ($c[$k === 'brand_logo' ? 'brand_logo' : 'brand_name'] ?? '')) : '';
    return $v !== '' ? $v : (string) wi_sys($k);
}
/** On a client's address only that client, their team and the super admin may sign in. */
function wi_host_allows(array $u): bool {
    $c = wi_host_client(); if (!$c || ($u['role'] ?? '') === 'super') return true;
    $o = wi_client_of($u); return $o && strtolower((string) $o['email']) === strtolower((string) $c['email']);
}
/** Cleans the address fields of a client: a short name for <slug>.<SaaS domain> and their own domains. */
function wi_client_addr(array $u, string $slug, string $domains): array {
    $slug = strtolower(trim($slug)); $u['slug'] = preg_match('/^[a-z0-9]([a-z0-9-]{1,28}[a-z0-9])$/', $slug) && !in_array($slug, ['www', 'mail', 'admin', 'api', 'app', 'cpanel', 'webmail'], true) ? $slug : '';
    $d = []; foreach (preg_split('/[\s,]+/', strtolower($domains)) as $x) if (preg_match('/^([a-z0-9-]+\.)+[a-z]{2,}$/', $x)) $d[] = $x;
    $u['domains'] = array_values(array_unique(array_slice($d, 0, 5))); return $u;
}

/* ---------- The public landing page (the product's marketing page) ---------- */
/** Renders landing.html with the brand, the public plans and the links (Settings: landing_cta_url / landing_cta_label / plan_currency). */
function wi_landing(): void {
    $e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); $t = fn($en) => function_exists('wf_mt') ? wf_mt($en) : $en;
    $cur = (string) (wi_sys('plan_currency') ?: '$'); $cards = ''; $plans = array_filter(wi_plans(), fn($p) => !empty($p['public'])); $mid = (int) floor(count($plans) / 2); $i = 0;
    foreach ($plans as $p) { $p = wi_plan_clean($p); $hot = $i++ === $mid && count($plans) > 1;
        $li = '<li>' . $p['numbers'] . ' ' . $e($t('WhatsApp numbers')) . '</li><li>' . ($p['team'] ?: '∞') . ' ' . $e($t('team members')) . '</li>'
            . ($p['bot'] ? '<li>' . $e($t('Smart bot that learns')) . ($p['ai_calls'] ? ' (' . number_format($p['ai_calls']) . ' ' . $e($t('AI replies')) . ')' : '') . '</li>' : '')
            . ($p['cases'] ? '<li>' . $e($t('Customers & tickets follow-up')) . '</li>' : '') . ($p['meetings'] ? '<li>' . $e($t('Meetings')) . '</li>' : '') . ($p['backup'] ? '<li>' . $e($t('Backups')) . '</li>' : '');
        $cards .= '<div class="plan' . ($hot ? ' hot' : '') . '">' . ($hot ? '<span class="eyebrow">' . $e($t('Most popular')) . '</span>' : '') . '<h3>' . $e($t($p['name'])) . '</h3><div class="price">' . ($p['price'] > 0 ? $e($cur) . $e(rtrim(rtrim(number_format($p['price'], 2), '0'), '.')) . ' <small>/ ' . $e($p['trial'] ? $p['days'] . ' ' . $t('days') : $t('month')) . '</small>' : $e($t('Free')) . ' <small>' . $p['days'] . ' ' . $e($t('days')) . '</small>') . '</div><ul>' . $li . '</ul><a class="btn ' . ($hot ? 'p' : 'g') . '" href="{{cta_url}}">{{cta_label}}</a></div>'; }
    $dz = (int) ($_GET['d'] ?? 0); $sw = ''; // ?d=1..3 previews a design with a switcher; otherwise the chosen one (General settings)
    if ($dz >= 1 && $dz <= 3) { foreach ([1 => $t('Bright'), 2 => $t('Night'), 3 => $t('Calm')] as $n => $nm) $sw .= '<a href="?d=' . $n . '"' . ($n === $dz ? ' class="on"' : '') . '>' . $e($nm) . '</a>'; $sw = '<nav class="dsw">' . $sw . '</nav>'; }
    else $dz = max(1, min(3, (int) wi_sys('landing_design')));
    $vars = ['brand' => $e(wi_sys('brand_name')), 'plans' => $cards, 'cta_url' => $e(wi_sys('landing_cta_url') ?: (wi_sys('login_url') ?: './?login')), 'cta_label' => $e(wi_sys('landing_cta_label') ?: $t('Start now')), 'login_url' => $e(wi_sys('login_url') ?: './?login'),
        'video_url' => './?intro_video=1', 'design' => $dz, 'design_switch' => $sw, 'privacy_url' => 'privacy', 'terms_url' => 'terms', 'year' => date('Y')];
    $html = (string) @file_get_contents(dirname(__DIR__) . (($_GET['v'] ?? '') === '2' ? '/landing2.html' : (($_GET['v'] ?? '') === '3' ? '/landing3.html' : (($_GET['v'] ?? '') === '1' ? '/landing.html' : (($_GET['v'] ?? '') === '4' ? '/landing4.html' : (($_GET['v'] ?? '') === '6' ? '/landing6.html' : '/landing5.html'))))));
    for ($k = 0; $k < 2; $k++) $html = preg_replace_callback('/\{\{(\w+)\}\}/', fn($m) => $vars[$m[1]] ?? '', $html); // twice: the plan cards carry the button's link
    header('Content-Type: text/html; charset=utf-8'); echo $html; exit;
}

