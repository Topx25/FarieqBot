<?php
declare(strict_types=1);

/*
 * WhatsApp Inbox — a separate app for the WhatsApp message archive.
 * Reads the archive from the local gateway (wa-local, 127.0.0.1:3188). Own users, own login
 * (password + a code sent on WhatsApp), own session. Nothing here touches the invoice system,
 * except a read-only lookup of customer names.
 */

const WI_DIR = __DIR__ . '/.data';
/** API calls posted without the page's CSRF token (none today: add a name only for a caller that cannot send it, such as a server-to-server hook). */
const WI_API_NO_CSRF = [];
/** System settings (Settings → System): everything that differs per installation. Values here are only the starting values. */
function wi_sys_defaults(): array {
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'); $dir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/');
    return ['signup_on' => 0, 'saas_domain' => '', 'api_base' => '', 'privacy_text' => '', 'terms_text' => '', 'legal_owner' => '', 'legal_email' => '', 'brand_name' => 'WhatsApp Inbox', 'brand_name_en' => '', 'brand_logo' => '💬', 'device_name' => 'WhatsGuard', 'base_url' => 'https://' . $host . $dir . '/',
        'timezone' => date_default_timezone_get() ?: 'UTC', 'country_code' => '', 'trunk_prefix' => '0', 'idle_min' => 30, 'otp_min' => 5, 'max_fails' => 5, 'lock_min' => 15,
        'cookie_path' => ($dir ?: '') . '/', 'landing_home' => 1, 'addon_instagram' => 0, 'landing_design' => 1, 'backup_folder' => 'WhatsApp Inbox Backups', 'archive_months' => 3, 'refresh_pct' => 100, 'changelog' => 'product', 'max_send_mb' => 70, 'addon_facebook' => 1, 'landing_cta_url' => '', 'landing_cta_label' => '', 'plan_currency' => '$', 'idle_warn_days' => 3, 'idle_stop_days' => 5, 'idle_qr_min' => 15, 'group_add_day' => 20, 'notify_clients' => 1, 'keyword_per_hour' => 30, 'keyword_cooldown_min' => 10, 'max_learn_mb' => 20, 'max_status_mb' => 60, 'max_export_mb' => 25, 'months_recent' => 1, 'months_short' => 3, 'months_long' => 36, 'msg_lang' => 'ar', 'ai_engine' => 'bridge', 'addon_invoices' => 0, 'currency' => '', 'ai_safety' => 'BLOCK_ONLY_HIGH', 'number_locale' => 'ar', 'trusted_proxies' => '173.245.48.0/20 103.21.244.0/22 103.22.200.0/22 103.31.4.0/22 141.101.64.0/18 108.162.192.0/18 190.93.240.0/20 188.114.96.0/20 197.234.240.0/22 198.41.128.0/17 162.158.0.0/15 104.16.0.0/13 104.24.0.0/14 172.64.0.0/13 131.0.72.0/22 2400:cb00::/32 2606:4700::/32 2803:f800::/32 2405:b500::/32 2405:8100::/32 2a06:98c0::/29 2c0f:f248::/32', 'invoices_config' => '', 'bridge_url' => '', 'bridge_host' => ''];
}
function wi_sys(?string $k = null) {
    static $S = null;
    if ($S === null) { $c = json_decode((string) @file_get_contents(WI_DIR . '/config.json'), true); $S = (array) ($c['system'] ?? []) + wi_sys_defaults(); $S['edition'] = (string) (getenv('WI_EDITION') ?: ($c['edition'] ?? (is_file(__DIR__ . '/edition.php') ? (require __DIR__ . '/edition.php') : 'saas'))); if ($S['edition'] === 'standalone') { $S['signup_on'] = 0; $S['landing_home'] = 0; $S['saas_domain'] = ''; } }
    return $k === null ? $S : ($S[$k] ?? null);
}
/** Single-customer (self-hosted) edition: no clients, plans, sign-up or product page. Set by WI_EDITION=standalone or config.json "edition". */
function wi_standalone(): bool { return wi_sys('edition') === 'standalone'; }
define('WI_IDLE', max(5, (int) wi_sys('idle_min')) * 60);   // inactivity before sign-out
define('WI_OTP_TTL', max(1, (int) wi_sys('otp_min')) * 60); // login code lifetime
define('WI_MAX_FAILS', max(3, (int) wi_sys('max_fails')));  // failed attempts before a lock

/** The host the gateway listens on: the WI_GATEWAY_HOST variable (a separate container), else this server. */
function wi_gw_host(): string { $h = (string) getenv('WI_GATEWAY_HOST'); return preg_match('/^[A-Za-z0-9.-]+$/', $h) ? $h : '127.0.0.1'; }
function wi_boot(): void {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    @date_default_timezone_set((string) wi_sys('timezone') ?: 'UTC');
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_name('wainbox_sid');
        $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' || ($_SERVER['HTTP_CF_VISITOR'] ?? '') !== '';
        session_set_cookie_params(['lifetime' => 0, 'path' => (string) wi_sys('cookie_path'), 'secure' => $secure, 'httponly' => true, 'samesite' => 'Strict']);
        session_start();
    }
    if (!empty($_SESSION['email'])) {
        $expired = time() - (int) ($_SESSION['seen'] ?? 0) > WI_IDLE;
        $moved = !hash_equals((string) ($_SESSION['fp'] ?? ''), wi_fingerprint());
        $fu = wi_find_user((string) $_SESSION['email']); $blocked = $fu && empty($_SESSION['imp_from']) ? wi_access_blocked($fu) : ''; // the super admin may still look inside an ended panel
        if ($expired || $moved || !$fu || $blocked !== '') {
            $_SESSION = [];
            session_regenerate_id(true);
            wi_flash($blocked !== '' ? $blocked : ($expired ? 'Your session expired. Please sign in again.' : 'Please sign in again.'), 'bad');
        } else {
            $_SESSION['seen'] = time();
        }
    }
}

/* ---------- language (English / Arabic) ---------- */
function wi_lang(): string {
    static $lang = null;
    if ($lang !== null) return $lang;
    $q = (string) ($_GET['lang'] ?? '');
    if (in_array($q, ['en', 'ar'], true)) {
        setcookie('wi_lang', $q, ['expires' => time() + 31536000, 'path' => (string) wi_sys('cookie_path'), 'secure' => true, 'httponly' => false, 'samesite' => 'Lax']);
        return $lang = $q;
    }
    $c = (string) ($_COOKIE['wi_lang'] ?? '');
    return $lang = in_array($c, ['en', 'ar'], true) ? $c : 'en';
}
/* ---------- add-ons: optional folders in addons/ (each with its addon.php), loaded by features.php ----------
 * The core calls wi_filter() where an add-on may add to or change a value; without add-ons every filter returns the value unchanged. */
function wi_hook_add(string $name, callable $fn): void { $GLOBALS['WI_HOOKS'][$name][] = $fn; }
function wi_filter(string $name, $value, ...$args) { foreach ((array) ($GLOBALS['WI_HOOKS'][$name] ?? []) as $fn) $value = $fn($value, ...$args); return $value; }
function wi_addon_on(string $name): bool { return isset($GLOBALS['WI_ADDONS'][$name]); }
function wi_dict(): array {
    static $base = null, $full = null;
    if ($full !== null) return $full;
    $base ??= wi_lang() === 'ar' ? (array) require __DIR__ . '/i18n.php' : [];
    if (!function_exists('wf_pack')) return $base; // the business profile is not loaded yet: plain dictionary for now
    // Wording of the business profile ("ui_terms": "Interface text => your word" per line) wins over the dictionary, in both languages.
    $over = []; $acc = wi_current();
    foreach (preg_split('/\R/u', (string) (wf_pack((string) ($acc['id'] ?? ''))['ui_terms'] ?? '')) as $l) { $x = array_map('trim', explode('=>', $l, 2)); if (count($x) === 2 && $x[0] !== '' && $x[1] !== '') $over[$x[0]] = $x[1]; }
    return $full = $over + wi_filter('i18n', $base, wi_lang());
}
function wi_t(string $s): string { $d = wi_dict(); return $d[$s] ?? $s; }
/** Arabic pages: translate interface text on output (text nodes, placeholders, titles, confirm dialogs). */
function wi_translate_html(string $html): string {
    $d = wi_dict();
    if (!$d) return $html;
    if (wi_lang() === 'ar') $html = str_replace('<html lang="en" dir="ltr">', '<html lang="ar" dir="rtl">', $html);
    foreach ($d as $en => $ar) if (strpos($en, '<') !== false) $html = str_replace($en, $ar, $html);
    $html = preg_replace_callback('/>(\s*)([^<>]+?)(\s*)</u', function ($m) use ($d) { return '>' . $m[1] . ($d[$m[2]] ?? $d[html_entity_decode($m[2], ENT_QUOTES, 'UTF-8')] ?? $m[2]) . $m[3] . '<'; }, $html); // "A &amp; B" in the page is the key "A & B"
    $html = preg_replace_callback('/\b(placeholder|title)="([^"]+)"/u', function ($m) use ($d) { return $m[1] . '="' . ($d[$m[2]] ?? $m[2]) . '"'; }, $html);
    $html = preg_replace_callback("/confirm\('([^']+)'\)/u", function ($m) use ($d) { return "confirm('" . ($d[$m[1]] ?? $m[1]) . "')"; }, $html);
    return $html;
}

/** The request body (JSON APIs). The test harness (tests/, command line only) hands it over directly. */
function wi_input(): string { return PHP_SAPI === 'cli' && isset($GLOBALS['WI_TEST_BODY']) ? (string) $GLOBALS['WI_TEST_BODY'] : (string) file_get_contents('php://input'); }
function wi_e(?string $v): string { return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function wi_fingerprint(): string { return hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')); }
/** The visitor's IP. A proxy header (Cloudflare) is believed only when the request really comes from a trusted proxy (Settings → System). */
function wi_ip(): string {
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $cf = (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '');
    if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP) && wi_ip_in($remote, (string) wi_sys('trusted_proxies'))) return $cf;
    return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '';
}
/** The visitor's country (two letters, e.g. EG), from Cloudflare's header and only through a trusted proxy; '' when unknown. */
function wi_ip_country(): string {
    $cc = strtoupper((string) ($_SERVER['HTTP_CF_IPCOUNTRY'] ?? ''));
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? ''); // the web server may already have put the visitor's IP here from Cloudflare's header (remote IP module)
    $via = wi_ip_in($remote, (string) wi_sys('trusted_proxies')) || ($remote !== '' && $remote === (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '') && !empty($_SERVER['HTTP_CF_RAY']));
    return preg_match('/^[A-Z]{2}$/', $cc) && $cc !== 'XX' && $cc !== 'T1' && $via ? $cc : ''; // a label for the meeting log only, never used to allow anything
}
function wi_ip_in(string $ip, string $cidrs): bool {
    $bin = @inet_pton($ip); if ($bin === false) return false;
    foreach (preg_split('/[\s,]+/', $cidrs, -1, PREG_SPLIT_NO_EMPTY) as $c) { [$net, $bits] = array_pad(explode('/', $c, 2), 2, null); $nb = @inet_pton((string) $net);
        if ($nb === false || strlen($nb) !== strlen($bin)) continue; $bits = $bits === null ? strlen($nb) * 8 : (int) $bits;
        $bytes = intdiv($bits, 8); $rem = $bits % 8;
        if (substr($bin, 0, $bytes) !== substr($nb, 0, $bytes)) continue;
        if ($rem && ((ord($bin[$bytes]) ^ ord($nb[$bytes])) & (0xFF << (8 - $rem)) & 0xFF)) continue;
        return true; }
    return false;
}

/* ---------- storage ---------- */
/** Data sets stored in MySQL once their switch is on (the JSON file stays as a mirror and fallback). */
function wi_db_store(string $name): ?array {
    static $map = ['cases.json' => ['cases_db', 'wi_db_cases_read', 'wi_db_cases_write'], 'registry.json' => ['cases_db', 'wi_db_reports_read', 'wi_db_reports_write']];
    if (!isset($map[$name]) || !function_exists($map[$name][1])) return null;
    $f = WI_DIR . '/config.json'; $c = is_file($f) ? (json_decode((string) @file_get_contents($f), true) ?: []) : [];
    return !empty($c[$map[$name][0]]) ? $map[$name] : null;
}
/* All dynamic data lives in MySQL (table wi_kv, one row per data set; cases/reports in their own tables).
 * A few sets are also written to .data as a mirror because other programs read them as files: the gateway (bot.json),
 * the gateway watchdogs (config.json, accounts.json). Before the switch "kv_db" is on (fresh copy, no database), files are used. */
const WI_FILE_MIRROR = ['config.json', 'accounts.json', 'bot.json'];
function wi_kv_on(): bool {
    static $on = null; if ($on !== null) return $on;
    $c = json_decode((string) @file_get_contents(WI_DIR . '/config.json'), true) ?: [];
    return $on = !empty($c['kv_db']) && function_exists('wi_db') && wi_db() !== null;
}
function wi_kv_install(): void {
    static $done = false; if ($done) return; $done = true; $m = wi_db(); if (!$m) return;
    $m->query("CREATE TABLE IF NOT EXISTS wi_kv (name VARCHAR(160) NOT NULL PRIMARY KEY, data LONGTEXT NOT NULL, updated_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function wi_kv_get(string $name): ?array {
    $m = wi_db(); if (!$m) return null; wi_kv_install();
    $st = $m->prepare("SELECT data FROM wi_kv WHERE name = ?"); if (!$st) return null; $st->bind_param('s', $name); $st->execute(); $r = $st->get_result()->fetch_row();
    if (!$r) return null; $v = json_decode((string) $r[0], true); return is_array($v) ? $v : [];
}
function wi_kv_put(string $name, array $data, ?mysqli $m = null): bool {
    $m ??= wi_db(); if (!$m) return false; wi_kv_install();
    $js = (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    $st = $m->prepare("INSERT INTO wi_kv (name, data, updated_at) VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE data = VALUES(data), updated_at = NOW()"); if (!$st) return false;
    $st->bind_param('ss', $name, $js); return $st->execute();
}
function wi_file_write(string $name, array $data): void {
    $f = WI_DIR . '/' . $name; $t = $f . '.tmp.' . bin2hex(random_bytes(4));
    file_put_contents($t, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    @chmod($t, 0600); rename($t, $f);
}
function wi_read(string $name, $default) {
    // tests: an in-memory store, nothing is read from or written to the real data
    if (PHP_SAPI === 'cli' && isset($GLOBALS['WI_MEM_STORE'])) return array_key_exists($name, $GLOBALS['WI_MEM_STORE']) ? $GLOBALS['WI_MEM_STORE'][$name] : $default;
    // the same store read again within 2 seconds of this request comes from memory (a loop no longer re-reads it); wi_write / wi_update forget it, and wi_update always reads fresh
    $c = $GLOBALS['WI_READ_CACHE'][$name] ?? null;
    if ($c !== null && empty($GLOBALS['WI_READ_FRESH']) && $c[0] > microtime(true) - 2) return $c[1] ?? $default;
    $v = wi_read_store($name);
    $GLOBALS['WI_READ_CACHE'][$name] = [microtime(true), $v];
    return $v ?? $default;
}
/** A store as saved (null when it has nothing), without the request's memory. */
function wi_read_store(string $name): ?array {
    if ($s = wi_db_store($name)) { $v = ($s[1])(); if ($v !== null) return $v ?: null; }
    if (wi_kv_on()) { $v = wi_kv_get($name); if ($v !== null) { $GLOBALS['WI_KV_BASE'][$name] = $v; return $v; } } // remembered: what this request saw
    $f = WI_DIR . '/' . $name;
    $v = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
    return is_array($v) ? $v : null;
}
function wi_write(string $name, array $data): void {
    if (PHP_SAPI === 'cli' && isset($GLOBALS['WI_MEM_STORE'])) { $GLOBALS['WI_MEM_STORE'][$name] = $data; return; }
    unset($GLOBALS['WI_READ_CACHE'][$name]);
    if ($name === 'cases.json') wi_case_numbers($data); // every case has its ID, whoever writes
    if ($s = wi_db_store($name)) ($s[2])($data);
    if (wi_kv_on() && wi_kv_put_merged($name, $data) && !in_array($name, WI_FILE_MIRROR, true)) return;
    wi_file_write($name, $data);
}
/** Every case gets an ID: 1, 2, 3… per WhatsApp number, in the order the cases were opened, never given twice. */
function wi_case_numbers(array &$A, bool $save = true): void {
    $need = []; foreach ($A as $aid => $cases) if (is_array($cases)) foreach ($cases as $k => $c) if (is_array($c) && empty($c['no'])) $need[$aid][] = $k;
    if (!$need) return;
    $m = wi_db(); if ($m) $m->query("SELECT GET_LOCK('wi_case_seq', 10)"); // two writers never hand out the same ID
    try {
    $seq = wi_read('case_seq.json', []);
    foreach ($need as $aid => $keys) {
        $max = (int) ($seq[$aid] ?? 0); foreach ($A[$aid] as $c) $max = max($max, (int) ($c['no'] ?? 0));
        usort($keys, fn($a, $b) => strcmp((string) ($A[$aid][$a]['created'] ?? ''), (string) ($A[$aid][$b]['created'] ?? '')));
        foreach ($keys as $k) $A[$aid][$k]['no'] = ++$max;
        $seq[$aid] = $max;
    }
    if ($save) wi_write('case_seq.json', $seq);
    } finally { if ($m) $m->query("SELECT RELEASE_LOCK('wi_case_seq')"); }
}
/* No lost changes: if another request changed the same data set after this one read it, both changes are kept.
 * Inside a row lock the stored value is compared with what this request read; when it moved on, a three-way merge keeps
 * every part this request did not touch as the other request left it. (wi_update() locks for the whole change and needs no merge.) */
function wi_kv_put_merged(string $name, array &$data): bool {
    $base = $GLOBALS['WI_KV_BASE'][$name] ?? null; $m = wi_db(); if (!$m) return false;
    if ($base === null) { $ok = wi_kv_put($name, $data); $GLOBALS['WI_KV_BASE'][$name] = $data; return $ok; } // never read in this request: plain write
    wi_kv_install(); $m->begin_transaction();
    try {
        $st = $m->prepare("SELECT data FROM wi_kv WHERE name = ? FOR UPDATE"); $st->bind_param('s', $name); $st->execute(); $r = $st->get_result()->fetch_row();
        $cur = $r ? (json_decode((string) $r[0], true) ?: []) : [];
        if ($r && $cur !== $base) $data = wi_merge3($base, $data, $cur);
        $ok = wi_kv_put($name, $data, $m); $m->commit();
    } catch (Throwable $e) { $m->rollback(); throw $e; }
    $GLOBALS['WI_KV_BASE'][$name] = $data; return $ok;
}
/** Three-way merge of JSON-like arrays: $base = what was read, $ours = what this request writes, $theirs = what is stored now. */
function wi_merge3($base, $ours, $theirs) {
    if ($ours === $base) return $theirs; if ($theirs === $base || $ours === $theirs) return $ours;
    if (!is_array($ours) || !is_array($theirs) || !is_array($base)) return $ours; // both changed a single value: this request wins
    if (array_is_list($ours) && array_is_list($theirs)) { // lists (logs, notes…): their version plus what this request added
        $out = $theirs; foreach ($ours as $x) if (!in_array($x, $base, true) && !in_array($x, $out, true)) $out[] = $x;
        return array_values(array_filter($out, fn($x) => in_array($x, $ours, true) || !in_array($x, $base, true))); // and without what this request removed
    }
    $out = [];
    foreach (array_unique(array_merge(array_keys($base), array_keys($ours), array_keys($theirs))) as $k) {
        $inB = array_key_exists($k, $base); $inO = array_key_exists($k, $ours); $inT = array_key_exists($k, $theirs);
        if ($inB && !$inO) { if ($inT && $theirs[$k] !== $base[$k]) $out[$k] = $theirs[$k]; continue; } // removed here (kept if the other side changed it)
        if ($inB && !$inT) { if ($inO && $ours[$k] !== $base[$k]) $out[$k] = $ours[$k]; continue; }  // removed there
        if (!$inB) { $out[$k] = $inO && $inT ? wi_merge3([], $ours[$k], $theirs[$k]) : ($inO ? $ours[$k] : $theirs[$k]); continue; }
        $out[$k] = wi_merge3($base[$k], $ours[$k], $theirs[$k]);
    }
    return $out;
}
/** Read-change-write in one database transaction (row lock): two runs changing the same data set at once no longer overwrite each other. */
function wi_update(string $name, callable $fn, $default = []) {
    if (PHP_SAPI === 'cli' && isset($GLOBALS['WI_MEM_STORE'])) return $GLOBALS['WI_MEM_STORE'][$name] = $fn($GLOBALS['WI_MEM_STORE'][$name] ?? $default);
    $GLOBALS['WI_READ_FRESH'] = ($GLOBALS['WI_READ_FRESH'] ?? 0) + 1; unset($GLOBALS['WI_READ_CACHE'][$name]);
    try { return wi_update_store($name, $fn, $default); } finally { $GLOBALS['WI_READ_FRESH']--; unset($GLOBALS['WI_READ_CACHE'][$name]); }
}
function wi_update_store(string $name, callable $fn, $default) {
    if (!wi_kv_on() || wi_db_store($name)) { // stores kept in their own tables (cases…): a named database lock keeps two writers apart
        $m = wi_db(); $lk = 'wi_upd_' . substr(md5($name), 0, 16);
        if ($m && (int) ($m->query("SELECT GET_LOCK('$lk', 30)")->fetch_row()[0] ?? 0) !== 1) throw new RuntimeException('Busy: ' . $name . ' is being changed, try again.'); // never write without the lock
        try { $v = $fn(wi_read($name, $default)); wi_write($name, $v); } finally { if ($m) $m->query("SELECT RELEASE_LOCK('$lk')"); }
        return $v; }
    $m = wi_db(); wi_kv_install(); $m->begin_transaction();
    try {
        $st = $m->prepare("SELECT data FROM wi_kv WHERE name = ? FOR UPDATE"); $st->bind_param('s', $name); $st->execute(); $r = $st->get_result()->fetch_row();
        $cur = $r ? (json_decode((string) $r[0], true) ?: []) : wi_read($name, $default);
        $v = $fn(is_array($cur) ? $cur : $default); if ($name === 'cases.json') wi_case_numbers($v); wi_kv_put($name, $v, $m); $m->commit(); $GLOBALS['WI_KV_BASE'][$name] = $v;
    } catch (Throwable $e) { $m->rollback(); throw $e; }
    if (in_array($name, WI_FILE_MIRROR, true)) wi_file_write($name, $v);
    return $v;
}
/** Answers the browser now and keeps working on the server (sending files one by one): the user's connection is no longer needed. */
function wi_json_then(array $x): void {
    @set_time_limit(900); ignore_user_abort(true); if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    while (ob_get_level() > 0) ob_end_clean();
    $b = json_encode($x, JSON_UNESCAPED_UNICODE); header('Content-Type: application/json'); header('Connection: close'); header('Content-Length: ' . strlen($b)); echo $b; flush();
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
}
/** A scheduled job that failed: kept in job_errors.json (the Error log page reads it), at most once an hour per job and message, so a failure is never silent. */
function wi_catch(Throwable $e, string $where): void {
    try { $msg = mb_substr($e->getMessage(), 0, 300) . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(); $k = md5($where . '|' . $msg);
        wi_update('job_errors.json', function ($A) use ($where, $msg, $k) { $A = array_filter((array) $A, fn($r) => is_array($r) && ($r['at'] ?? 0) > time() - 7 * 86400);
            if (($A[$k]['at'] ?? 0) > time() - 3600) { $A[$k]['count'] = (int) ($A[$k]['count'] ?? 1) + 1; return $A; }
            $A[$k] = ['at' => time(), 'where' => $where, 'what' => $msg, 'count' => (int) ($A[$k]['count'] ?? 0) + 1]; return $A; });
    } catch (Throwable $e2) { error_log('wi_catch ' . $where . ': ' . $e->getMessage()); }
}
function wi_log(string $action, array $extra = []): void {
    if (PHP_SAPI === 'cli' && isset($GLOBALS['WI_MEM_STORE'])) { $GLOBALS['WI_MEM_STORE']['_audit'][] = ['action' => $action] + $extra; return; }
    $row = ['time' => date('c'), 'action' => $action, 'user' => (string) ($_SESSION['imp_from'] ?? $_SESSION['email'] ?? ''), 'ip' => wi_ip()] + (!empty($_SESSION['imp_from']) ? ['acting_as' => (string) $_SESSION['email']] : []) + $extra;
    @file_put_contents(WI_DIR . '/audit.jsonl', json_encode($row, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    if (wi_read('config.json', [])['users_db'] ?? false) wi_db_audit($row);
    @chmod(WI_DIR . '/audit.jsonl', 0600);
}
function wi_config(): array { return wi_read('config.json', []); }

/* ---------- flash / csrf ---------- */
function wi_flash(?string $msg = null, string $type = 'ok'): ?array {
    if ($msg !== null) { $_SESSION['flash'] = ['m' => $msg, 't' => $type]; return null; }
    $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f;
}
function wi_csrf(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function wi_check_csrf(): void {
    $sent = (string) ($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF'] ?? '');
    if ($sent === '' || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $sent)) { http_response_code(403); exit('Invalid request.'); }
}

/* ---------- users ---------- */
function wi_norm_phone(string $raw): string {
    $d = preg_replace('/\D+/', '', $raw);
    if (strpos($d, '00') === 0) $d = substr($d, 2);
    // local numbers get this installation's country code (Settings → System: country_code, trunk_prefix), e.g. 01xxxxxxxxx → 201xxxxxxxxx
    $cc = preg_replace('/\D/', '', (string) wi_sys('country_code')); $tp = preg_replace('/\D/', '', (string) wi_sys('trunk_prefix'));
    if ($cc !== '' && $tp !== '' && str_starts_with($d, $tp) && !str_starts_with($d, $cc) && strlen($d) >= 9 && strlen($d) <= 11) $d = $cc . substr($d, strlen($tp));
    if ($cc !== '' && $tp !== '' && str_starts_with($d, $cc . $tp) && strlen($d) - strlen($cc . $tp) >= 8) $d = $cc . substr($d, strlen($cc . $tp)); // country code + local prefix typed together
    return strlen($d) >= 10 && strlen($d) <= 15 ? $d : '';
}
require_once __DIR__ . '/db.php';
/** Users come from MySQL (wi_users + wi_grants); users.json is kept as a mirror and a fallback if the database is down. */
function wi_users(bool $fresh = false): array {
    static $cache = null;
    if ($fresh) $cache = null;
    if ($cache !== null) return $cache;
    $db = (wi_read('config.json', [])['users_db'] ?? false) ? wi_db_users() : null;
    return $cache = ($db !== null && $db ? $db : wi_read('users.json', []));
}
function wi_save_users(array $u): void {
    $u = array_values($u);
    if (wi_read('config.json', [])['users_db'] ?? false) wi_db_save_users($u);
    wi_write('users.json', $u);
    wi_users(true);
}
function wi_find_user(string $email): ?array {
    foreach (wi_users() as $u) if (strtolower((string) $u['email']) === strtolower(trim($email)) && !empty($u['active'])) return $u;
    return null;
}
function wi_logged_in(): bool { return !empty($_SESSION['email']); }
function wi_me(): ?array { return wi_logged_in() ? wi_find_user((string) $_SESSION['email']) : null; }
/** May link / restart / manage this account: admins all; a user only the numbers they own (linked themselves). */
function wi_can_manage(?array $acc): bool {
    if (!$acc) return false;
    if (wi_is_super()) return true; // the super admin runs the infrastructure of every number (gateway, provider, link); its chats stay with its owner (wi_may_see_account)
    if (!wi_may_see_account($acc)) return false; // a private number is managed by its owner only
    if (wi_is_admin() && isset(wi_my_accounts()[$acc['id'] ?? ''])) return true;
    $me = wi_me();
    return $me && !empty($acc['owner']) && strtolower((string) $acc['owner']) === strtolower((string) $me['email']);
}
function wi_can_link_own(): bool { $u = wi_me(); return $u && (wi_is_admin() || !empty($u['can_link'])); }
function wi_can_reply(): bool { $u = wi_me(); if (wi_is_team($u)) return wi_page_perm('inbox') === 'edit' && !empty(((array) ($u['chat'] ?? wi_team_roles((string) ($u['tenant'] ?? ''))[$u['role']]['chat']))['reply']);
    return $u && (wi_is_admin() || wi_page_perm('inbox') === 'edit' || !empty($u['can_reply'])); }
/** Super admin: sees and manages everything (all numbers, all users, system settings).
 *  Admin: full control of their own number(s) and of the users on them. Manager: like admin, without users. */
function wi_is_super(): bool { $u = wi_me(); return $u && ($u['role'] ?? '') === 'super'; }
/** Who may open whose panel ("view as"; no password asked, none changed). '' = allowed, otherwise the reason.
 *  Only a super admin, never from inside a borrowed view. A client panel always opens (owner's rule, 2026-10-23);
 *  anyone else only when none of their numbers is private to them. */
function wi_may_impersonate(?array $me, ?array $t, bool $inside, array $accounts): string {
    if (!$me || ($me['role'] ?? '') !== 'super' || $inside) return 'Super admins only.';
    if (!$t || ($t['role'] ?? '') === 'super' || strtolower((string) $t['email']) === strtolower((string) $me['email'])) return 'This user cannot be opened.';
    if (($t['role'] ?? '') === 'client') return '';
    foreach ($accounts as $pa) if (wi_may_see_account($pa, $t) && !wi_may_see_account($pa, $me)) return 'This user works on a private number — only its owner may see it.';
    return '';
}
function wi_is_admin(): bool { $u = wi_me(); return $u && in_array($u['role'] ?? '', ['super', 'admin', 'manager'], true); }
function wi_is_owner(): bool { return wi_is_super(); }
/** Works on the cases of a number without seeing the number itself (its chats, selector, stats). */
function wi_cases_only(): bool { $u = wi_me(); return $u && ($u['role'] ?? '') === 'viewer' && !empty($u['cases_only']); }
function wi_can_users(): bool { $u = wi_me(); return $u && (in_array($u['role'] ?? '', ['super', 'admin'], true) || (($u['role'] ?? '') === 'client' && wi_team_seats($u) > 0)); }
/** Users an admin may see/edit: viewers and managers working only on that admin's numbers. */
function wi_user_in_scope(array $t): bool {
    if (wi_is_super()) return true;
    $me = wi_me();
    if (($me['role'] ?? '') === 'client') return wi_is_team($t) && strtolower((string) ($t['tenant'] ?? '')) === strtolower((string) $me['email']); // a client sees only their own team
    if (!wi_can_users() || !in_array($t['role'] ?? 'viewer', ['viewer', 'manager'], true)) return false;
    $mine = array_keys(wi_my_accounts()); $theirs = (array) ($t['accounts'] ?? []);
    return ($theirs && !array_diff($theirs, $mine)) || strtolower((string) ($t['created_by'] ?? '')) === strtolower((string) ($_SESSION['email'] ?? ''));
}
/** The dashboard themes (id => name). The super admin picks the default for everyone; each user may pick their own (My account). */
const WI_THEMES = ['fq-meta' => 'Fariek theme', 'fq-violet' => 'Farieq violet', 'fq-night' => 'Farieq night and gold', 'fq-ink' => 'Farieq ink and green', 'fq-dawn' => 'Farieq dawn', 'aurora' => 'Aurora', 'google' => 'Google (Material)', 'classic' => 'Classic', 'orchid' => 'Orchid', 'studio' => 'Studio'];
const WI_SUPER_PAGES = ['ai', 'settings', 'backup', 'report', 'clients', 'errors', 'saas', 'plans'];
/* ---------- Client teams (SaaS): the client owns their panel; under them supervisors (lead) and employees (agent) ----------
 * A team user belongs to one client (tenant = the client's email), works only on that client's numbers, never sees more
 * than the client has, and has page permissions (the menu) plus chat permissions (what they may do inside a conversation). */
const WI_TEAM_ROLES = ['lead', 'agent'];
/** Chat permissions, in display order. delete_msg / delete_chat are never given to an employee. */
const WI_CHAT_PERMS = ['reply', 'media', 'notes', 'labels', 'assign', 'groups', 'quick', 'export', 'invoices', 'delete_msg', 'delete_chat'];
const WI_AGENT_NEVER = ['delete_msg', 'delete_chat'];
/** Pages a team user never gets: their client's users, numbers (linking), system settings, AI keys and backups stay with the client. */
const WI_TEAM_NEVER = ['users', 'accounts', 'settings', 'ai', 'backup', 'report', 'business'];
function wi_is_team(?array $u = null): bool { $u ??= wi_me(); return $u && in_array($u['role'] ?? '', WI_TEAM_ROLES, true); }
/** The client whose panel this user works in: the client themself, or a team user's client (only while that client is active). */
function wi_tenant(?array $u = null): ?array {
    $u ??= wi_me(); if (!$u) return null;
    if (($u['role'] ?? '') === 'client') return $u;
    if (!wi_is_team($u)) return null;
    $o = wi_find_user((string) ($u['tenant'] ?? '')); return $o && in_array($o['role'] ?? '', ['client', 'super'], true) ? $o : null; // a team belongs to a client, or to the super admin for the numbers they own
}
/** Turns a viewer into a member of an owner's team (role lead / agent), keeping their numbers, pages and phone. Returns an error text, or '' when done (the list is changed, not saved). */
function wi_viewer_to_team(array &$users, string $email, string $owner, string $role): string {
    $email = strtolower(trim($email)); $owner = strtolower(trim($owner)); $t = null; $ow = null;
    foreach ($users as $i => $u) { if (strtolower((string) $u['email']) === $email) $t = $i; if (strtolower((string) $u['email']) === $owner) $ow = $u; }
    if ($t === null || ($users[$t]['role'] ?? '') !== 'viewer') return 'Only a viewer can be moved to a team.';
    if (!$ow || !in_array($ow['role'] ?? '', ['client', 'super'], true)) return 'The owner must be a client or the super admin.';
    if (!in_array($role, WI_TEAM_ROLES, true)) return 'Choose supervisor or employee.';
    $own = array_keys(wi_owned_accounts($owner)); if (!$own) return 'This owner has no linked number.';
    $accs = array_values(array_intersect((array) ($users[$t]['accounts'] ?? []), $own)) ?: $own; $d = wi_team_roles($owner)[$role];
    $pages = array_filter((array) ($users[$t]['pages'] ?? []), fn($v, $k) => in_array($v, ['view', 'edit'], true) && !in_array($k, WI_TEAM_NEVER, true), ARRAY_FILTER_USE_BOTH) ?: $d['pages'];
    $u = $users[$t]; unset($u['cases_only'], $u['can_bot'], $u['can_meetings'], $u['can_cases'], $u['can_backup'], $u['can_share'], $u['can_link'], $u['can_reply'], $u['own_number'], $u['max_team'], $u['shared']);
    $users[$t] = ['role' => $role, 'tenant' => $owner, 'accounts' => $accs, 'pages' => $pages, 'chat' => $d['chat'], 'moved_from' => 'viewer', 'moved_at' => date('c')] + $u;
    return '';
}
/** Numbers a client owns (linked themselves). */
function wi_owned_accounts(string $email): array { $e = strtolower($email); return array_filter(wi_accounts(), fn($a) => $e !== '' && strtolower((string) ($a['owner'] ?? '')) === $e); }
/** Starting values of the two team roles. A client changes them for their own panel (Users → Role defaults); these are only the first values. */
function wi_team_role_defaults(): array {
    return [
        'lead' => ['pages' => ['inbox' => 'edit', 'search' => 'edit', 'customer' => 'edit', 'numbers' => 'edit', 'groups' => 'edit', 'broadcast' => 'edit', 'status' => 'edit', 'schedule' => 'edit', 'programs' => 'edit',
            'meetings' => 'edit', 'library' => 'edit', 'audio' => 'edit', 'files' => 'edit', 'analytics' => 'view', 'cases' => 'edit', 'botrun' => 'edit', 'botgroup' => 'edit', 'bot' => 'view', 'learning' => 'view', 'health' => 'view', 'facebook' => 'edit'],
            'chat' => array_fill_keys(WI_CHAT_PERMS, true)],
        'agent' => ['pages' => ['inbox' => 'edit', 'search' => 'view', 'customer' => 'view', 'library' => 'view', 'audio' => 'view', 'files' => 'view', 'cases' => 'edit', 'meetings' => 'view'],
            'chat' => ['reply' => true, 'media' => true, 'notes' => true, 'labels' => true, 'assign' => false, 'groups' => false, 'quick' => false, 'export' => false, 'invoices' => false, 'delete_msg' => false, 'delete_chat' => false]],
    ];
}
/** The role defaults of one client's panel (their saved changes over the starting values). */
function wi_team_roles(string $tenant): array {
    $d = wi_team_role_defaults(); $own = (array) (wi_read('team_roles.json', [])[strtolower($tenant)] ?? []);
    foreach ($d as $r => &$x) { if (isset($own[$r]['pages'])) $x['pages'] = (array) $own[$r]['pages']; if (isset($own[$r]['chat'])) $x['chat'] = array_merge($x['chat'], array_map('boolval', (array) $own[$r]['chat'])); }
    unset($x); foreach (WI_AGENT_NEVER as $k) $d['agent']['chat'][$k] = false;
    return $d;
}
/* ---------- Subscriptions (Clients page): end date, pause, and what stops when a panel ends ---------- */
/** State of a client's subscription: active | expiring (a week or less left) | expired | suspended. days = days left (null = no end date). */
function wi_client_state(array $c): array {
    if (empty($c['active'])) return ['state' => 'suspended', 'days' => null];
    $e = (string) ($c['expires'] ?? ''); if ($e === '' || !strtotime($e)) return ['state' => 'active', 'days' => null];
    $d = (int) floor((strtotime($e . ' 23:59:59') - time()) / 86400);
    return ['state' => $d < 0 ? 'expired' : ($d <= 7 ? 'expiring' : 'active'), 'days' => $d];
}
/** The client record behind a user (the client themself, or a team user's client), active or not. */
function wi_client_of(array $u): ?array {
    if (($u['role'] ?? '') === 'client') return $u; if (!in_array($u['role'] ?? '', WI_TEAM_ROLES, true)) return null;
    $e = strtolower((string) ($u['tenant'] ?? '')); foreach (wi_users() as $x) if (strtolower((string) $x['email']) === $e && in_array($x['role'] ?? '', ['client', 'super'], true)) return $x; return null; // the owner of a team: a client, or the super admin
}
/** Why this user may not use the panel now ('' = allowed). A client's team stops with its client. */
function wi_access_blocked(array $u): string {
    if (($u['role'] ?? '') !== 'client' && !in_array($u['role'] ?? '', WI_TEAM_ROLES, true)) return '';
    $c = wi_client_of($u); if (!$c) return 'This panel was closed. Contact the administrator.';
    if (($c['role'] ?? '') === 'super') return ''; // the super admin's own team has no subscription to end
    $s = wi_client_state($c)['state'];
    return $s === 'expired' ? 'The subscription of this panel has ended. Contact the administrator to renew it.' : ($s === 'suspended' ? 'This panel is paused. Contact the administrator.' : '');
}
/** A number of an ended or paused client panel: its bot, scheduled messages and other automatic work stop (the archive keeps recording). */
function wi_account_blocked(array $acc): bool {
    $o = strtolower((string) ($acc['owner'] ?? '')); if ($o === '') return false;
    foreach (wi_users() as $x) if (strtolower((string) $x['email']) === $o && ($x['role'] ?? '') === 'client') return in_array(wi_client_state($x)['state'], ['expired', 'suspended'], true);
    return false;
}
/** Team seats: how many supervisors + employees a client may have (set by the super admin; 0 = no team). */
function wi_team_seats(array $client): int { return max(0, (int) ($client['max_team'] ?? 0)); }
function wi_team_of(string $tenant): array { $e = strtolower($tenant); return array_values(array_filter(wi_users(), fn($u) => wi_is_team($u) && strtolower((string) ($u['tenant'] ?? '')) === $e)); }
/** What a client's plan allows on a page (the ceiling for the whole panel). */
function wi_client_page_perm(array $u, string $page): string {
    if (($u['role'] ?? '') === 'super') return in_array($page, ['settings', 'backup', 'report', 'users', 'ai'], true) ? '' : 'edit'; // the super admin as the owner of a team: everything a team may see
    // Features the super admin turned on for this client: bot (+ learning + their own AI keys), meetings, cases, backup (exports).
    if (in_array($page, ['settings', 'backup', 'report'], true)) return '';
    if ($page === 'users') return wi_team_seats($u) > 0 ? 'edit' : '';
    if (in_array($page, ['bot', 'botrun', 'botgroup', 'learning', 'ai'], true)) return !empty($u['can_bot']) ? 'edit' : '';
    if ($page === 'meetings') return !empty($u['can_meetings']) ? 'edit' : '';
    if ($page === 'cases') return !empty($u['can_cases']) ? 'edit' : '';
    return 'edit';
}
/** May the signed-in user do this inside a conversation? Owners and admins: everything; team users: their chat permissions;
 *  older viewer accounts keep their old rules (reply flag, no deleting). */
function wi_chat_can(string $perm): bool {
    $u = wi_me(); if (!$u || !in_array($perm, WI_CHAT_PERMS, true)) return false;
    $t = wi_tenant($u);
    if ($perm === 'export' && $t && empty($t['can_backup'])) return false; // exporting is part of the client's backup plan
    if ($perm === 'invoices' && (empty(wi_sys('addon_invoices')) || $t)) return false; // the invoice system belongs to the operator, never to a client's panel
    if (in_array($u['role'] ?? '', ['super', 'admin', 'manager', 'client'], true)) return true;
    if (wi_is_team($u)) {
        if (!$t || wi_page_perm('inbox') !== 'edit' && !in_array($perm, ['export'], true)) return false;
        if (($u['role'] ?? '') === 'agent' && in_array($perm, WI_AGENT_NEVER, true)) return false;
        $c = isset($u['chat']) ? (array) $u['chat'] : (wi_team_roles((string) $t['email'])[$u['role']]['chat'] ?? []);
        return !empty($c[$perm]);
    }
    if (in_array($perm, ['reply', 'media'], true)) return wi_can_reply();
    if (in_array($perm, WI_AGENT_NEVER, true)) return false;
    return $perm === 'export' || wi_page_perm('inbox') === 'edit';
}
/** Which conversations this user sees: 'all', or 'mine_open' (an employee: chats given to them plus chats nobody has taken yet). */
function wi_chat_scope(): string { return (wi_me()['role'] ?? '') === 'agent' ? 'mine_open' : 'all'; }
/** Conversations of this number given to someone else (hidden from an employee). jid => email */
function wi_chats_taken_by_others(string $aid): array {
    if (wi_chat_scope() === 'all') return [];
    $me = strtolower((string) ($_SESSION['email'] ?? '')); $o = [];
    foreach ((array) (wi_read('assign.json', [])[$aid] ?? []) as $jid => $a) { $to = strtolower((string) ($a['to'] ?? '')); if ($to !== '' && $to !== $me) $o[$jid] = $to; }
    return $o;
}
function wi_chat_visible(string $aid, string $jid): bool { return !isset(wi_chats_taken_by_others($aid)[$jid]); }
/** Activity of a client's panel (the client and their team), newest first — for the client's "Team activity" view. */
function wi_team_activity(string $tenant, int $limit = 150, string $who = ''): array {
    $emails = array_map(fn($u) => strtolower((string) $u['email']), wi_team_of($tenant)); $emails[] = strtolower($tenant);
    if ($who !== '') $emails = array_values(array_intersect($emails, [strtolower($who)]));
    $m = wi_db(); if (!$m || !$emails) return [];
    $in = implode(',', array_map(fn($e) => "'" . $m->real_escape_string($e) . "'", $emails));
    $r = $m->query("SELECT at, action, user, extra FROM wi_audit WHERE LOWER(user) IN ($in) AND acting_as = '' ORDER BY id DESC LIMIT " . max(1, min(500, $limit)));
    $o = []; while ($r && ($x = $r->fetch_assoc())) { $e = json_decode((string) $x['extra'], true) ?: [];
        $o[] = ['at' => $x['at'], 'action' => $x['action'], 'user' => $x['user'], 'jid' => (string) ($e['jid'] ?? ''), 'target' => (string) ($e['target'] ?? ''), 'account' => (string) ($e['account'] ?? '')]; }
    return $o;
}
/** Working inside a client's panel: a client or their team, or anyone looking at a client's number. Operator-only things
 *  (the invoice system, the operator's Google account, the shared cases number, the operator's staff) are never shown there. */
function wi_in_client_panel(): bool { if (wi_tenant() !== null) return true; $o = wi_private_owner(wi_current() ?: []); if ($o === '') return false; $u = wi_find_user($o); return $u && ($u['role'] ?? '') === 'client'; }
/** Whose shared lists (quick replies, label names) the open number uses: a client's numbers have their client's own lists; '' = the operator's. */
function wi_ws(?array $acc = null): string {
    $acc ??= wi_current(); $o = strtolower((string) ($acc['owner'] ?? '')); return $o; // every owned number has its own lists
}
/** Quick replies of a given number (its client's list, or the operator's). */
function wi_replies_for(array $acc): array { $w = wi_ws($acc); return $w === '' ? (array) wi_read('replies.json', []) : (array) (wi_read('replies_ws.json', [])[$w] ?? []); }
/** The one quick reply a typed shortcut points to ("#keyword-name", "/name"): the exact name, or whole words of exactly one name or trigger. null = none or several. */
function wi_reply_match(array $replies, string $typed): ?array {
    $q = wf_norm($typed); if (mb_strlen($q) < 3) return null; $wq = ' ' . $q . ' ';
    $exact = array_values(array_filter($replies, fn($r) => wf_norm((string) $r['title']) === $q));
    $whole = array_values(array_filter($replies, function ($r) use ($wq) { foreach (array_merge([(string) $r['title']], (array) ($r['triggers'] ?? [])) as $w) if (str_contains(' ' . wf_norm((string) $w) . ' ', $wq)) return true; return false; }));
    if (count($exact) === 1 && count($whole) === 1) return $exact[0];
    if (!$exact && count($whole) === 1 && mb_strlen($q) >= 4) return $whole[0];
    return null;
}
/** Fills a quick reply for one chat: {name} {phone} {due} {invoice} {date}. Invoice details only on the operator's numbers. */
function wi_reply_fill(string $text, string $jid, array $acc): string {
    $c = str_ends_with($jid, '@g.us') ? [] : wi_contact($jid, $acc); // in a group there is no one person to name
    $phone = (string) ($c['phone'] ?? ''); $cust = null;
    if (wi_ws($acc) === '' && $phone !== '' && isset(wi_customers()[$phone])) $cust = wi_customer_detail((int) wi_customers()[$phone]['id']);
    $openInv = null; foreach ((array) ($cust['invoices'] ?? []) as $i) if ((float) $i['total_due'] > 0 && in_array($i['status'], ['unpaid', 'partial', 'overdue'], true)) { $openInv = $i; break; }
    $map = ['{name}' => $cust['person'] ?? ($c['name'] ?? ''), '{phone}' => $phone !== '' ? '+' . $phone : '', '{due}' => $cust ? number_format((float) $cust['due']) . ' ' . (string) wi_sys('currency') : '',
        '{invoice}' => $openInv['reference'] ?? '', '{date}' => date('d/m/Y')];
    return trim(preg_replace('/[ \t]+\n/', "\n", strtr($text, $map)));
}
function wi_replies(): array { if (!wi_current()) return []; $w = wi_ws(); return $w === '' ? (array) wi_read('replies.json', []) : (array) (wi_read('replies_ws.json', [])[$w] ?? []); }
function wi_replies_save(array $rs): void { $w = wi_ws(); if ($w === '') { wi_write('replies.json', $rs); return; } wi_update('replies_ws.json', function ($A) use ($w, $rs) { $A[$w] = $rs; return $A; }); }
/** People who work on a number (for assigning chats): never users of other clients, and the operator's staff never on a client's number. */
function wi_account_people(array $acc, bool $phone = false): array {
    $aid = (string) ($acc['id'] ?? ''); $ws = wi_ws($acc); $o = [];
    foreach (wi_users() as $u) { if (empty($u['active'])) continue; $r = $u['role'] ?? 'viewer'; $e = strtolower((string) $u['email']);
        if ($ws !== '') $in = $e === $ws || (in_array($r, WI_TEAM_ROLES, true) && strtolower((string) ($u['tenant'] ?? '')) === $ws && (empty($u['accounts']) || in_array($aid, (array) $u['accounts'], true)));
        else $in = $r === 'super' || (!in_array($r, array_merge(WI_TEAM_ROLES, ['client']), true) && (in_array($aid, (array) ($u['accounts'] ?? []), true) || strtolower((string) ($acc['owner'] ?? '')) === $e));
        if ($in) $o[] = ['email' => $u['email'], 'name' => ($u['name'] ?? '') ?: $u['email']] + ($phone ? ['phone' => (string) ($u['phone'] ?? '')] : []); }
    return $o;
}

/** Technical areas a manager never gets. */
const WI_TECH_PAGES = ['ai', 'settings', 'backup', 'report', 'health', 'users'];
/** Pages that can be given to a user, with their name. */
/** The menu: sections and their pages. Labels, icons and hidden items can be changed per installation (Settings → Menu, config.json "menu").
 *  who: all | manager (managers and above) | users (may manage users) | admin | super. 'pages' = pages that highlight this item. */
function wi_menu_registry(): array {
    return [
        ['id' => 'saas', 'title' => 'System', 'items' => [
            ['key' => 'saas', 'icon' => '🏢', 'label' => 'System overview', 'who' => wi_standalone() ? 'none' : 'super'],
            ['key' => 'clients', 'icon' => '🏪', 'label' => 'Clients', 'who' => wi_standalone() ? 'none' : 'super'],
            ['key' => 'plans', 'icon' => '📦', 'label' => 'Plans and subscriptions', 'who' => wi_standalone() ? 'none' : 'super'],
            ['key' => 'errors', 'icon' => '🧯', 'label' => 'Error log', 'who' => 'super'],
            ['key' => 'settings', 'icon' => '⚙️', 'label' => 'General settings', 'who' => 'super']]],
        ['id' => 'today', 'title' => 'Today', 'items' => [['key' => 'dashboard', 'href' => './', 'icon' => '🏠', 'label' => 'Dashboard', 'who' => 'all']]],
        ['id' => 'comm', 'title' => 'Communication', 'items' => [
            ['key' => 'inbox', 'icon' => '💬', 'label' => 'Conversations', 'who' => 'all', 'pages' => ['inbox', 'search'], 'q' => ['ch' => '']],
            ['key' => 'groups', 'icon' => '👥', 'label' => 'Groups', 'who' => 'all'],
            ['key' => 'meetings', 'icon' => '🎥', 'label' => 'Meetings', 'who' => 'all'],
            ['key' => 'broadcast', 'icon' => '📣', 'label' => 'Broadcast', 'who' => 'manager']]],
        ['id' => 'facebook', 'title' => 'Facebook', 'items' => [ // shown when the user has a Facebook page (Connect pages: to anyone who may link one)
            ['key' => 'fb_messenger', 'icon' => '💬', 'label' => 'Messenger', 'who' => 'all', 'href' => '?p=inbox&ch=fb', 'pages' => ['inbox'], 'q' => ['ch' => 'fb']],
            ['key' => 'facebook', 'icon' => '💭', 'label' => 'Page comments', 'who' => 'all', 'href' => '?p=facebook', 'pages' => ['facebook'], 'q' => ['tab' => '']],
            ['key' => 'fb_posts', 'icon' => '📰', 'label' => 'Page posts', 'who' => 'all', 'href' => '?p=facebook&tab=p', 'pages' => ['facebook'], 'q' => ['tab' => 'p']],
            ['key' => 'fb_numbers', 'icon' => '📈', 'label' => 'Page numbers', 'who' => 'all', 'href' => '?p=facebook&tab=i', 'pages' => ['facebook'], 'q' => ['tab' => 'i']],
            ['key' => 'fb_connect', 'icon' => '🔗', 'label' => 'Connect pages', 'who' => 'manager', 'href' => '?p=accounts#fb-hub', 'pages' => ['accounts'], 'q' => ['fbx' => '1']]]],
        ['id' => 'cases', 'title' => 'Cases & follow-up', 'items' => [
            ['key' => 'cases', 'icon' => '🩺', 'label' => 'Cases', 'who' => 'all'],
            ['key' => 'schedule', 'icon' => '⏰', 'label' => 'Scheduling & programs', 'who' => 'all', 'pages' => ['schedule', 'programs', 'status']]]],
        ['id' => 'lib', 'title' => 'Library', 'items' => [
            ['key' => 'library', 'icon' => '📚', 'label' => 'Library', 'who' => 'all', 'pages' => ['library', 'audio', 'files', 'media']]]],
        ['id' => 'auto', 'title' => 'Automation & content', 'items' => [
            ['key' => 'botrun', 'icon' => '🤖', 'label' => 'Bot', 'who' => 'all', 'pages' => ['botrun', 'botgroup']],
            ['key' => 'learning', 'icon' => '🎓', 'label' => 'Learning', 'who' => 'all'],
            ['key' => 'bot', 'icon' => '🧠', 'label' => 'Bot: learning & test', 'who' => 'all'],
            ['key' => 'analytics', 'icon' => '📈', 'label' => 'Analytics & data', 'who' => 'all'],
            ['key' => 'numbers', 'icon' => '📇', 'label' => 'Numbers', 'who' => 'all'],
            ['key' => 'catalog', 'icon' => '🛍️', 'label' => 'Product catalog', 'who' => 'all']]],
        ['id' => 'admin', 'title' => 'Administration', 'items' => [
            ['key' => 'accounts', 'icon' => '📱', 'label' => 'WhatsApp', 'who' => 'manager', 'pages' => ['accounts', 'link', 'gateway']],
            ['key' => 'business', 'icon' => '🏢', 'label' => 'Business settings', 'who' => 'manager'],
            ['key' => 'users', 'icon' => '🔐', 'label' => 'Users', 'who' => 'users'],
            ['key' => 'health', 'icon' => '☁️', 'label' => 'Health, report & backup', 'who' => 'admin', 'pages' => ['health', 'report', 'backup']],
            ['key' => 'ai', 'icon' => '✨', 'label' => 'AI providers', 'who' => 'ai'],
            ['key' => 'integrations', 'icon' => '🔌', 'label' => 'Integrations', 'who' => 'integrations']]],
    ];
}
/** The registry with this installation's changes applied (labels, icons, hidden items, section titles). */
function wi_menu(): array {
    $own = (array) (wi_read('config.json', [])['menu'] ?? []); $out = [];
    foreach (wi_menu_registry() as $sec) {
        if ($sec['id'] === 'facebook' && !wi_sys('addon_facebook')) continue; // Facebook is an add-on, like the invoices (General settings)
        $sec['default_title'] = $sec['title']; $sec['title'] = (string) ($own['sections'][$sec['id']] ?? $sec['title']);
        foreach ($sec['items'] as &$it) { $o = (array) ($own['items'][$it['key']] ?? []); $it['default_label'] = $it['label']; $it['default_icon'] = $it['icon'];
            if (trim((string) ($o['label'] ?? '')) !== '') $it['label'] = (string) $o['label']; if (trim((string) ($o['icon'] ?? '')) !== '') $it['icon'] = (string) $o['icon'];
            // the records item follows the open number's own names (Business settings / Case settings), over the installation's menu
            if ($it['key'] === 'cases' && function_exists('wf_case_cfg') && ($ccA = wi_current())) { $cc = wf_case_cfg((string) $ccA['id']); $it['label'] = (string) $cc['many']; $it['icon'] = (string) $cc['icon']; } $it['hidden'] = !empty($o['hidden']); $it['pages'] ??= [$it['key']]; }
        unset($it); $out[] = $sec;
    }
    return $out;
}
function wi_menu_can(string $who, bool $manager): bool {
    return match ($who) { 'none' => false, 'manager' => $manager, 'users' => wi_can_users(), 'admin' => wi_is_admin() || (wi_me()['role'] ?? '') === 'client', 'super' => wi_is_super(), 'integrations' => (($ime = wi_me()) && ($ime['role'] ?? '') === 'client' && wi_client_can($ime, 'integrations')), 'ai' => wi_is_super() || (($w = wi_me()) && ($w['role'] ?? '') === 'client' && !empty($w['can_bot'])), default => true };
}
function wi_page_names(): array {
    return ['inbox' => 'Inbox', 'search' => 'Search', 'library' => 'Library', 'audio' => 'Audio', 'files' => 'Files', 'analytics' => 'Analytics', 'numbers' => 'Numbers', 'catalog' => 'Product catalog',
        'cases' => 'Cases', 'groups' => 'Groups', 'customer' => 'Customer profile', 'schedule' => 'Scheduling', 'programs' => 'Programs', 'status' => 'Status posts', 'facebook' => 'Facebook',
        'meetings' => 'Meetings', 'learning' => 'Learning', 'bot' => 'Bot learning', 'botrun' => 'Bot', 'botgroup' => 'Group bot', 'broadcast' => 'Broadcast',
        'health' => 'Number health', 'business' => 'Business settings', 'report' => 'Daily report', 'backup' => 'Backup', 'ai' => 'AI providers', 'settings' => 'Settings', 'accounts' => 'WhatsApp numbers'];
}
/** Pages that only admins see unless given explicitly. */
const WI_ADMIN_PAGES = ['ai', 'report', 'backup', 'status', 'settings', 'accounts', 'broadcast', 'health'];
/** '' (no access), 'view' or 'edit' for the signed-in user on a page. */
/** The signed-in user linked a number of their own (they are its owner). */
function wi_owns_number(): bool { $e = strtolower((string) ($_SESSION['email'] ?? '')); if ($e === '') return false; foreach (wi_accounts() as $a) if (strtolower((string) ($a['owner'] ?? '')) === $e) return true; return false; }
/** The number currently open is the user's own number. */
function wi_current_is_own(): bool { $c = wi_current(); return $c && strtolower((string) ($c['owner'] ?? '')) === strtolower((string) ($_SESSION['email'] ?? '')); }
function wi_page_perm(string $page): string {
    $u = wi_me(); if (!$u) return '';
    if ($page === 'media') $page = 'library'; // the media library goes with the library permission
    if (wi_is_super()) return 'edit';
    // Client: like a super admin for their own number only — every page of the number; never users, permissions or system settings;
    // the bot pages only when the super admin allowed the bot (can_bot).
    if (($u['role'] ?? '') === 'client') return wi_client_page_perm($u, $page);
    // Supervisor / employee: what their client gave them, never more than the client's own plan.
    if (wi_is_team($u)) {
        if (in_array($page, WI_TEAM_NEVER, true)) return '';
        if ($page === 'dashboard') return 'view';
        $t = wi_tenant($u); if (!$t || empty($t['active']) || wi_client_page_perm($t, $page) === '') return '';
        $pg = isset($u['pages']) ? (array) $u['pages'] : (wi_team_roles((string) $t['email'])[$u['role']]['pages'] ?? []);
        $p = (string) ($pg[$page] ?? ''); if ($p === '' && in_array($page, ['botrun', 'botgroup'], true)) $p = (string) ($pg['bot'] ?? '');
        if ($page === 'customer' && $p === '') $p = (string) ($pg['inbox'] ?? '') !== '' ? 'view' : '';
        return in_array($p, ['view', 'edit'], true) ? $p : '';
    }
    // Someone who linked their own number runs everything of that number: its cases and its health too.
    if (in_array($u['role'] ?? '', ['manager', 'admin'], true) && in_array($page, ['cases', 'health'], true) && wi_owns_number()) return 'edit';
    if (($u['role'] ?? '') === 'manager') {
        // Manager: everything except the technical admin areas; the shared cases database only after accepting a share.
        if (in_array($page, WI_TECH_PAGES, true)) return '';
        if ($page === 'cases') { $p = (string) ($u['pages']['cases'] ?? ''); return in_array($p, ['view', 'edit'], true) ? $p : ''; }
        return 'edit';
    }
    if (wi_is_admin()) return in_array($page, WI_SUPER_PAGES, true) ? '' : 'edit';
    if ($page === 'users') return '';
    if ($page === 'dashboard') return 'view';
    if ($page === 'accounts' && !empty($u['can_link'])) return 'edit'; // may link (and manage) their own number; each action still checks ownership
    if (!isset($u['pages'])) return in_array($page, WI_ADMIN_PAGES, true) ? '' : 'edit'; // older users: as before
    $p = (string) ($u['pages'][$page] ?? '');
    if ($p === '' && in_array($page, ['botrun', 'botgroup'], true)) $p = (string) ($u['pages']['bot'] ?? '');
    return in_array($p, ['view', 'edit'], true) ? $p : '';
}
function wi_pages_from_post(): array { $o = []; foreach ((array) ($_POST['pages'] ?? []) as $k => $v) if (isset(wi_page_names()[$k]) && in_array($v, ['view', 'edit'], true)
    && (wi_is_super() || (!in_array($k, WI_SUPER_PAGES, true) && wi_page_perm((string) $k) === 'edit'))) $o[$k] = $v; return $o; } // never more than the granter has
/** May share this page with another user: the owner, or a user allowed to share who can edit the page. */
function wi_can_share(string $page): bool { $u = wi_me(); if (wi_tenant($u)) return false; /* a client manages their team on the Users page; sharing reaches the operator's staff */ return $u && isset(wi_page_names()[$page]) && wi_page_perm($page) === 'edit' && (wi_can_users() || !empty($u['can_share'])); }
/** Which page an API call belongs to (for view/edit checks). '' = shared by every page. */
function wi_api_page(string $api): string {
    static $map = null;
    if ($map === null) { $map = [];
        foreach (['inbox' => 'catalog_send chats thread chat_media send send_media media_send forward_text delete_msg delete_chat trash trash_restore export group_add group_invite_send group_admin_list group_removed group_members send_invoice chat_invoices notes meta_set summarize reply_fill inv_reminder suggest_reply react fetch_media meta contact events photo presence', 'search' => 'search', 'analytics' => 'stats analyze export',
            'library' => 'library media_lib media_inventory media_meta media_folders media_bulk media_dupes media_zip media_youtube lib_ai lib_ai_best lib_ai_group lib_hide media_queue media_vault media_vault_set media_vault_unlock media_vault_lock media_vault_move media_vault_otp media_vault_file lib_text_save lib_text_delete lib_upload youtube_connect', 'audio' => 'audio_lib audio_meta', 'files' => 'files', 'ai' => 'ai_providers',
            'broadcast' => 'broadcast broadcast_control broadcast_status lists', 'accounts' => 'accounts gateway gw_action qr relink',
            'cases' => 'case_cfg case_intake_cfg cases case case_new case_team case_set case_analyze case_export registry registry_set registry_ai registry_group group_monitor', 'groups' => 'groups group_detail group_view case_track',
            'numbers' => 'numbers', 'catalog' => 'catalog catalog_save catalog_del catalog_img market market_ai', 'facebook' => 'fb_comments fb_comment_act fb_cfg fb_posts fb_post_act fb_upload fb_insights', 'schedule' => 'schedule', 'programs' => 'programs program_save program_enroll', 'status' => 'status_upload status_post', 'health' => 'health',
            'report' => 'report_cfg', 'backup' => 'backup_cfg', 'business' => 'business_cfg', 'customer' => 'customer', 'settings' => 'settings_overview',
            'learning' => 'learn_guide learn_cache learn_suggest learn_docs learn_rules learn_drive learn_clean learn_grow ai_prompts', 'bot' => 'learn_state learn_build kb_table kb_bulk kb_set bot_test bot_eval bot_rate bot_profile bot_cfg', 'botrun' => 'bot_run bot_chats bot_pause bot_team bot_draft bot_health bot_retry bot_join_cfg bot_roles', 'botgroup' => 'bot_group bot_group_set'] as $pg => $list)
            foreach (explode(' ', $list) as $a) $map[$a] = $pg;
        // Calls that belong to a page but were missing (they skipped the page check), and calls any signed-in user may make ('*': the handler checks who may use it)
        foreach (['inbox' => 'chats_all media acc_state recommend recommend_send suggest', 'audio' => 'audio_cats', 'accounts' => 'restore', 'cases' => 'case_files case_trail case_deep case_follow case_delete',
            'botrun' => 'bot_quality bot_commands team_watch checkin_preview', 'botgroup' => 'visitors', 'learning' => 'learn_lean',
            '*' => 'plan_usage home sysguide jslog hide_for qr_mine my_google perm_share perm_list perm_invite client_backup acc_invoices ui_colors ui_theme ai_tasks gw_rules menu_cfg sys_settings msg_templates ig_state push_key push_sub push_unsub push_test push_fcm'] as $pg => $list)
            foreach (explode(' ', $list) as $a) $map[$a] ??= $pg; }
    if ($api === 'meeting_targets') return ($_GET['ctx'] ?? '') === 'cases' ? 'cases' : 'meetings'; // the case form uses the same group/member lists
    if (str_starts_with($api, 'meeting')) return 'meetings';
    return $map[$api] ?? '';
}

/* ---------- brute-force guard ---------- */
function wi_guard_key(string $email): string { return hash('sha256', strtolower(trim($email)) . '|' . wi_ip()); }
function wi_locked(string $email): bool {
    $g = wi_read('guard.json', []); $k = wi_guard_key($email);
    return isset($g[$k]) && $g[$k]['n'] >= WI_MAX_FAILS && time() - $g[$k]['t'] < max(1, (int) wi_sys('lock_min')) * 60;
}
function wi_fail(string $email): void {
    $g = wi_read('guard.json', []); $k = wi_guard_key($email);
    $n = isset($g[$k]) && time() - $g[$k]['t'] < 900 ? $g[$k]['n'] + 1 : 1;
    $g[$k] = ['n' => $n, 't' => time()];
    foreach ($g as $key => $v) if (time() - $v['t'] > 86400) unset($g[$key]);
    wi_write('guard.json', $g);
}
function wi_clear_fails(string $email): void { $g = wi_read('guard.json', []); unset($g[wi_guard_key($email)]); wi_write('guard.json', $g); }

/* ---------- Request context: what this request (or cron run) works on — set in one place, read through these two functions ----------
 * acc      : the number served instead of the session's one (the shared cases number on the Cases page)
 * ai_acc   : the number whose AI keys are used (the bot / background jobs work per number)
 * api_page : the page an API call belongs to (its permission decides "edit" rights) */
function wi_ctx(string $key) { return $GLOBALS['WI_CTX'][$key] ?? null; }
function wi_ctx_set(string $key, $value): void { if ($value === null) unset($GLOBALS['WI_CTX'][$key]); else $GLOBALS['WI_CTX'][$key] = $value; }

/* ---------- gateway ---------- */
/* ---------- WhatsApp accounts ---------- */
/** Main account (the original gateway on 3188) + extra accounts from .data/accounts.json. */
function wi_accounts(): array {
    $main = ['id' => 'main', 'name' => (string) (wi_config()['main_name'] ?? 'Main number'), 'port' => (int) (wi_config()['main_port'] ?? 3188), 'token' => wi_secret('gateway_token:main', (string) (wi_config()['gateway_token'] ?? '')),
        'enabled' => true, 'main' => true, 'payments' => (wi_config()['main_payments'] ?? true) !== false, 'autoreply' => false, 'owner' => (string) (wi_config()['main_owner'] ?? '')];
    $out = ['main' => $main];
    foreach (wi_read('accounts.json', []) as $a) if (!empty($a['id']) && $a['id'] !== 'main') { $a['token'] = wi_secret('gateway_token:' . $a['id'], (string) ($a['token'] ?? '')); $out[$a['id']] = $a + ['main' => false]; }
    return $out;
}
function wi_save_accounts(array $accounts): void {
    $rows = [];
    foreach ($accounts as $a) if (empty($a['main'])) { unset($a['main']); if (!empty($a['token'])) wi_secret_set('gateway_token:' . $a['id'], (string) $a['token']); $rows[] = $a; } // file copy kept for the gateway watchdog
    wi_write('accounts.json', $rows);
}
/** Accounts this user may see: admins all, viewers only the ones assigned to them. */
/* PRODUCTION RULE (fixed): a number that has an owner is private — only its owner and the owner's own team ever see it
 * (its chats, files, cases, settings, any word of it). Not the super admin, not other admins. Numbers without an owner follow the roles. */
function wi_private_owner(array $acc): string { return strtolower(trim((string) ($acc['owner'] ?? ''))); }
function wi_may_see_account(array $acc, ?array $u = null): bool {
    $u ??= wi_me(); if (!$u) return false; $o = wi_private_owner($acc); if ($o === '') return true; // not private: the role rules below decide
    $e = strtolower((string) $u['email']); return $e === $o || (in_array($u['role'] ?? '', WI_TEAM_ROLES, true) && strtolower((string) ($u['tenant'] ?? '')) === $o);
}
/** Who hears about a number (alerts, reports): its owner for a private number, otherwise the super admins and admins. */
function wi_account_watchers(array $acc): array {
    $o = wi_private_owner($acc);
    return array_values(array_filter(wi_users(), fn($u) => !empty($u['active']) && !empty($u['phone'])
        && ($o !== '' ? strtolower((string) $u['email']) === $o : in_array($u['role'] ?? '', ['super', 'admin'], true))));
}
/** Numbers left out of the operator's backups: private numbers whose owner is not a super admin (their owner backs them up to their own Drive). */
function wi_backup_excluded(): array {
    $sup = array_map(fn($u) => strtolower((string) $u['email']), array_filter(wi_users(), fn($u) => ($u['role'] ?? '') === 'super'));
    return array_keys(array_filter(wi_accounts(), fn($a) => ($o = wi_private_owner($a)) !== '' && !in_array($o, $sup, true)));
}
/** May back up their own numbers to their own Google Drive: a client with the Backup feature, or any other owner of a private number (not the super admin, whose numbers are in the system backup). */
function wi_can_self_backup(?array $u = null): bool {
    $u ??= wi_me(); if (!$u || ($u['role'] ?? '') === 'super' || !wi_owned_accounts((string) $u['email'])) return false;
    return ($u['role'] ?? '') !== 'client' || !empty($u['can_backup']);
}
function wi_notify_account(array $acc, string $msg): void { foreach (wi_account_watchers($acc) as $u) wi_send_whatsapp((string) $u['phone'], $msg); }
function wi_my_accounts(): array {
    $all = array_filter(wi_accounts(), fn($a) => wi_may_see_account($a));
    if (wi_is_super()) return $all;
    $me = wi_me();
    // A team user: the numbers their client gave them, and only while those still belong to that client.
    if (wi_is_team($me)) { $t = wi_tenant($me); if (!$t || empty($t['active'])) return []; $own = wi_owned_accounts((string) $t['email']);
        $pick = (array) ($me['accounts'] ?? []); return $pick ? array_intersect_key($own, array_flip($pick)) : $own; }
    $allowed = array_flip((array) ($me['accounts'] ?? []));
    foreach ($all as $id => $a) if (!empty($a['owner']) && strtolower((string) $a['owner']) === strtolower((string) ($me['email'] ?? ''))) $allowed[$id] = 1;
    return array_intersect_key($all, $allowed);
}
/** The phone number a WhatsApp account is signed in with (digits only; empty while it is not linked), and its display label "name · +number". */
function wi_account_phone(array $acc): string {
    if (($acc['channel'] ?? '') === 'fb' || !empty($acc['archived'])) return '';
    $id = (string) (wi_gateway('/status', null, $acc)['account']['id'] ?? '');
    return preg_replace('/\D/', '', explode('@', explode(':', $id)[0])[0]);
}
function wi_account_label(array $acc): string { $ph = wi_account_phone($acc); return (string) ($acc['name'] ?? $acc['id']) . ($ph !== '' ? ' · +' . $ph : ''); }
function wi_current(): ?array {
    if (wi_ctx('acc')) return wi_ctx('acc'); // shared cases: served from the cases number for anyone allowed on the Cases page
    $mine = wi_my_accounts();
    $id = (string) ($_SESSION['acc'] ?? '');
    if (!isset($mine[$id])) { $id = (string) array_key_first($mine); $_SESSION['acc'] = $id; }
    return $id !== '' ? $mine[$id] : null;
}

/* ---------- gateway ---------- */
/** Calls the gateway of the current account ($acc overrides; 'main' for login codes). */
/** WhatsApp's contact data of a chat (name, number…): kept for 6 hours (10 minutes when WhatsApp did not answer) — asking for it on every click was the slowest call of the chat screen. */
function wi_contact(string $jid, $acc = null): array {
    if (is_string($acc)) $acc = wi_accounts()[$acc] ?? null; $acc ??= wi_current(); $aid = preg_replace('/\W/', '', (string) ($acc['id'] ?? 'x')); $f = WI_DIR . '/cache_contact_' . $aid . '.json';
    $all = is_file($f) ? (array) json_decode((string) @file_get_contents($f), true) : []; $e = $all[$jid] ?? null;
    if ($e && time() - (int) $e['t'] < (!empty($e['d']['ok']) ? 21600 : 600)) return (array) $e['d'];
    $d = wi_gateway('/contact?jid=' . rawurlencode($jid), null, $acc); $all[$jid] = ['t' => time(), 'd' => $d];
    if (count($all) > 600) { uasort($all, fn($x, $y) => $y['t'] <=> $x['t']); $all = array_slice($all, 0, 400, true); }
    @file_put_contents($f, json_encode($all, JSON_UNESCAPED_UNICODE), LOCK_EX); @chmod($f, 0600); return $d;
}
function wi_gateway(string $path, ?array $post = null, $acc = null): array {
    if (PHP_SAPI === 'cli' && isset($GLOBALS['WI_FAKE_GATEWAY']) && is_callable($GLOBALS['WI_FAKE_GATEWAY'])) return ($GLOBALS['WI_FAKE_GATEWAY'])($path, $post, $acc); // tests: a stand-in gateway, nothing leaves the server
    if (is_string($acc)) { $all = wi_accounts(); $acc = $all[$acc] ?? null; }
    if ($acc === null) $acc = wi_current();
    if (!$acc) return ['ok' => false, 'error' => 'No WhatsApp account available for this user.'];
    // an archived number (removed, or the old number of an account): its history is read from its files, nothing is sent
    if (!empty($acc['archived']) && function_exists('wf_hist_gateway')) return wf_hist_gateway($path, $post, $acc);
    // a number on another provider (OpenWA, the Cloud API of Meta): its calls go to that provider
    if (in_array($acc['provider'] ?? '', ['openwa', 'cloud'], true)) { if (!function_exists('wf_gw_provider')) require_once __DIR__ . '/features.php'; return wf_gw_provider($path, $post, $acc); }
    if (($acc['channel'] ?? '') === 'fb' && function_exists('wf_fb_gateway')) return wf_fb_gateway($path, $post, $acc); // a Facebook page: answered by the Messenger channel, no phone gateway
    if ($path === '/chat/send' && is_array($post) && !empty($post['mentions']) && str_ends_with((string) ($post['jid'] ?? ''), '@g.us')) $post = wi_mentions_present($post, $acc); // nobody is tagged who is not in the group now
    $token = (string) ($acc['token'] ?? '');
    if ($token === '') return ['ok' => false, 'error' => 'Gateway token missing.'];
    $ch = curl_init('http://' . wi_gw_host() . ':' . (int) $acc['port'] . $path); // the token travels in the Authorization header, never in the address
    $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => strpos($path, '/chat/send') === 0 ? 240 : (preg_match('#^/(status|qr\.json|events|media/queue)#', $path) ? 8 : 30), CURLOPT_CONNECTTIMEOUT => 3];
    $opt[CURLOPT_HTTPHEADER] = ['Authorization: Bearer ' . $token];
    if ($post !== null) { $opt[CURLOPT_POST] = true; $opt[CURLOPT_POSTFIELDS] = json_encode($post); $opt[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json'; }
    curl_setopt_array($ch, $opt);
    $t0 = microtime(true); $r = json_decode((string) curl_exec($ch), true);
    if (getenv('WI_PROF')) @file_put_contents('/tmp/wi_prof.log', sprintf("%6.0f ms  %s\n", (microtime(true) - $t0) * 1000, $path), FILE_APPEND); // speed check: WI_PROF=1 lists every gateway call and its time
    curl_close($ch);
    return is_array($r) ? $r : ['ok' => false, 'error' => 'This account\'s gateway is not running yet (it starts within a minute of being added).'];
}
/** A code message, then the code alone in its own message so it is copied with one press. */
function wi_send_code(string $to, string $message, string $code): bool {
    if (!wi_send_whatsapp($to, $message)) return false;
    wi_send_whatsapp($to, $code); return true;
}
function wi_send_whatsapp(string $to, string $message): bool {
    $r = wi_gateway('/send', ['to' => $to, 'message' => $message, 'via' => 'inbox'], 'main');
    return !empty($r['ok']);
}

/* ---------- customers (read-only lookup from the invoice system) ---------- */
function wi_customers(): array {
    static $map = null;
    if (wi_in_client_panel()) return []; // the invoice system's customers belong to the operator
    if ($map !== null) return $map;
    $map = [];
    try {
        $db = wi_inv_db(); if (!$db) return $map; // invoices add-on off or not set up
        $prefix = defined('CONFIG_MYSQL_PREFIX') ? CONFIG_MYSQL_PREFIX : '';
        $res = $db->query("SELECT id, fullname, company, phone, whatsapp_phone FROM `{$prefix}biller`");
        if ($res) while ($b = $res->fetch_assoc()) {
            $name = trim((string) $b['company']) !== '' ? trim($b['company']) : trim((string) $b['fullname']);
            foreach ([$b['whatsapp_phone'], $b['phone']] as $p) { $n = wi_norm_phone((string) $p); if ($n !== '') $map[$n] = ['id' => (int) $b['id'], 'name' => $name]; }
        }
    } catch (Throwable $e) { /* names are optional */ }
    return $map;
}

/* ---------- A new number for the same business: the old number's chats, files, notes and cases come along ---------- */
/** The folder where a number's gateway keeps its archive. */
function wi_archive_dir(string $aid): string { $base = dirname(__DIR__) . '/wa-local'; return $aid === 'main' ? $base . '/archive' : $base . '/accounts/' . preg_replace('/[^a-z0-9]/', '', $aid) . '/archive'; }
/** Copies the history of number $from into number $to (the old number keeps its own copy). Runs once per pair. Returns counts. */
function wi_merge_number(string $from, string $to): array {
    if ($from === $to) throw new RuntimeException('Choose two different numbers.');
    $src = wi_archive_dir($from); $dst = wi_archive_dir($to); if (!is_dir($src)) throw new RuntimeException('The old number has no saved chats.');
    if (!is_dir($dst)) @mkdir($dst, 0700, true);
    $mark = $dst . '/merged_' . preg_replace('/[^a-z0-9]/', '', $from) . '.json'; if (is_file($mark)) throw new RuntimeException('These chats were already added to this number.');
    $lines = 0; $files = 0;
    foreach (glob($src . '/[0-9][0-9][0-9][0-9]-[0-9][0-9].jsonl') ?: [] as $p) { // each month: the old rows go after the new number's rows of that month, files pointing to their new place
        $t = str_replace(['inbox_media/' . $from . '/', 'inbox_media\\/' . $from . '\\/'], ['inbox_media/' . $to . '/', 'inbox_media\\/' . $to . '\\/'], (string) file_get_contents($p)); // both spellings (JSON writes "\/")
        if (trim($t) === '') continue; file_put_contents($dst . '/' . basename($p), rtrim($t, "\n") . "\n", FILE_APPEND | LOCK_EX); $lines += substr_count($t, "\n");
    }
    $root = dirname(__DIR__) . '/storage/inbox_media/'; @mkdir($root . $to, 0700, true);
    foreach (glob($root . $from . '/*') ?: [] as $p) { $q = $root . $to . '/' . basename($p); if (!is_file($q) && @copy($p, $q)) $files++; }
    // what the team wrote about these chats (the new number's own entries win)
    $mv = fn(array $x) => json_decode(str_replace('inbox_media\/' . $from . '\/', 'inbox_media\/' . $to . '\/', json_encode($x)), true) ?: $x;
    foreach (['media_meta.json', 'notes.json', 'assign.json', 'summaries.json', 'chat_meta.json', 'enrollments.json', 'known_members.json'] as $store)
        wi_update($store, function ($S) use ($from, $to, $mv) { if (!empty($S[$from]) && is_array($S[$from])) $S[$to] = (array) ($S[$to] ?? []) + $mv($S[$from]); return $S; });
    if (function_exists('wf_cases_update')) { $old = function_exists('wf_cases') ? wf_cases($from) : []; if ($old) wf_cases_update($to, fn(array $C) => $C + $old); }
    file_put_contents($mark, json_encode(['from' => $from, 'at' => date('c'), 'lines' => $lines, 'files' => $files]));
    wi_log('number_merge', ['from' => $from, 'to' => $to, 'lines' => $lines, 'files' => $files]);
    return ['lines' => $lines, 'files' => $files];
}
/* ---------- Working while the number is offline: a reply is kept and sent when WhatsApp is back ---------- */
/** Sends now, or keeps the message in this number's outbox when WhatsApp is disconnected (sent every minute once it is back). */
function wi_send_or_queue(array $body, ?array $acc = null): array {
    $acc ??= wi_current(); $r = wi_gateway('/chat/send', $body, $acc);
    if (!empty($r['ok']) || !$acc) return $r;
    $err = strtolower((string) ($r['error'] ?? ''));
    if ($err !== '' && !preg_match('/not connected|not linked|scan|gateway|timed out|connect|503|refused|no response/', $err)) return $r; // a real refusal (bad number, too big…): shown as it is
    $aid = (string) $acc['id']; $n = 0;
    wi_update('outbox.json', function ($Q) use ($aid, $body, &$n) { $Q[$aid][] = $body + ['queued_at' => date('c')]; $Q[$aid] = array_slice((array) $Q[$aid], -500); $n = count($Q[$aid]); return $Q; });
    wi_log('outbox_queued', ['account' => $aid, 'jid' => (string) ($body['jid'] ?? ''), 'waiting' => $n]);
    return ['ok' => true, 'queued' => true, 'waiting' => $n, 'note' => 'WhatsApp is offline: the message waits and is sent as soon as the number is back.'];
}
/** Every minute: the waiting messages of the numbers that are connected again, oldest first. */
function wi_outbox_flush(): void {
    $Q = (array) wi_read('outbox.json', []); if (!$Q) return;
    foreach ($Q as $aid => $list) { $acc = wi_accounts()[$aid] ?? null; if (!$acc || !$list) continue;
        if (empty(wi_gateway('/health', null, $acc)['connected'])) continue;
        $sent = 0; foreach (array_slice((array) $list, 0, 20) as $body) { unset($body['queued_at']); $r = wi_gateway('/chat/send', $body, $acc); if (empty($r['ok'])) break; $sent++; }
        if ($sent) { wi_update('outbox.json', function ($X) use ($aid, $sent) { $X[$aid] = array_slice((array) ($X[$aid] ?? []), $sent); if (!$X[$aid]) unset($X[$aid]); return $X; }); wi_log('outbox_sent', ['account' => $aid, 'sent' => $sent]); } }
}
/** Chats and groups hidden from the signed-in user by their client or admin: jid => true (owners and the super admin: none). */
function wi_hidden_jids(): array {
    static $h = null; if ($h !== null) return $h; $u = wi_me();
    return $h = $u && !wi_is_super() ? array_fill_keys(array_map('strval', (array) ($u['hide_jids'] ?? [])), true) : [];
}
/** Drops every item that belongs to a hidden chat or group from an API answer (lists of chats, messages, search results, groups…). */
function wi_scrub_hidden($x, array $H) {
    if (!is_array($x)) return $x; $list = array_is_list($x); $out = [];
    foreach ($x as $k => $v) { if (is_array($v) && ((isset($v['jid']) && is_string($v['jid']) && isset($H[$v['jid']])) || (isset($v['group']) && is_string($v['group']) && isset($H[$v['group']])))) continue; $out[$k] = wi_scrub_hidden($v, $H); }
    return $list ? array_values($out) : $out;
}
function wi_json(array $data, int $code = 200): void {
    if (($H = wi_hidden_jids()) && isset($_GET['api'])) $data = wi_scrub_hidden($data, $H); // STRICT: a hidden chat never reaches this user, whatever the page
    if (isset($data['error']) && is_string($data['error']) && $data['error'] !== '') $data['error'] = wi_t($data['error']); // every error in the user's language, from one place
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* ---------- Gateway Guard info (watchdog reports) ---------- */
function wi_guard_info(): array {
    $wl = dirname(__DIR__) . '/wa-local';
    $main = json_decode((string) @file_get_contents($wl . '/gateway-heartbeat.json'), true);
    $acc = json_decode((string) @file_get_contents($wl . '/accounts/guard.json'), true);
    return ['main_checked_at' => $main['checked_at'] ?? null, 'accounts_checked_at' => $acc['checked_at'] ?? null];
}
function wi_guard_restarts(string $id): int {
    $wl = dirname(__DIR__) . '/wa-local';
    $file = $id === 'main' ? $wl . '/guard.log' : $wl . '/accounts/guard.log';
    $n = 0; $since = time() - 86400;
    foreach (@file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (strpos($line, 'restarted ' . $id) === false) continue;
        $t = strtotime(substr($line, 0, 25)); if ($t && $t >= $since) $n++;
    }
    return $n;
}

/** Invoice-system details for one customer (read-only): open invoices and totals. */
function wi_customer_detail(int $id): ?array {
    if (wi_in_client_panel()) return null;
    try {
        $db = wi_inv_db(); if (!$db) return null;
        $st = $db->prepare('SELECT id, fullname, company, email, phone, whatsapp_phone FROM biller WHERE id = ?'); $st->bind_param('i', $id); $st->execute();
        $b = $st->get_result()->fetch_assoc(); if (!$b) return null;
        $st = $db->prepare("SELECT reference, total, total_due, status, date, currency FROM invoices WHERE bill_to_id = ? AND status <> 'archived' ORDER BY date DESC LIMIT 6"); $st->bind_param('i', $id); $st->execute();
        $inv = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st = $db->prepare("SELECT COUNT(*) n, COALESCE(SUM(total),0) total, COALESCE(SUM(CASE WHEN total_due > 0 AND status IN ('unpaid','partial','overdue') THEN total_due ELSE 0 END),0) due FROM invoices WHERE bill_to_id = ? AND status <> 'archived'"); $st->bind_param('i', $id); $st->execute();
        $sum = $st->get_result()->fetch_assoc();
        
        return ['id' => (int) $b['id'], 'name' => trim((string) $b['company']) !== '' ? trim($b['company']) : trim((string) $b['fullname']), 'person' => trim((string) $b['fullname']),
            'email' => (string) $b['email'], 'invoices' => $inv, 'count' => (int) $sum['n'], 'total' => (float) $sum['total'], 'due' => (float) $sum['due']];
    } catch (Throwable $e) { return null; }
}

/* ---------- AI: this installation's own engine ("local", keys in .data, Settings → System) or the invoice system's (bridge) ---------- */
/** Whose AI keys a call uses: a client's own store (their number's AI calls, their AI providers page), otherwise the system store (''). */
function wi_ai_scope(): string {
    $acc = wi_ctx('ai_acc'); $email = '';
    if ($acc) $email = (string) ($acc['owner'] ?? '');
    elseif (($me = wi_me()) && ($me['role'] ?? '') === 'client') $email = (string) $me['email'];
    elseif (PHP_SAPI !== 'cli' && ($c = wi_current()) && !wi_is_super()) $email = (string) ($c['owner'] ?? '');
    if ($email === '') return '';
    foreach (wi_users() as $u) if (strtolower((string) $u['email']) === strtolower($email)) {
        if (($u['role'] ?? '') !== 'client' || !empty($u['ai_system'])) return ''; // ai_system: the operator's own panels run on the system keys
        // No AI keys of their own: the plan's monthly allowance runs on the operator's keys
        if (!wi_client_has_ai($email) && wi_ai_allowance_left($u) > 0) { $GLOBALS['WI_AI_PAYER'] = strtolower($email); return ''; }
        return 'u' . substr(md5(strtolower($email)), 0, 10);
    }
    return '';
}
function wi_ai_call(array $body, int $timeout = 90): array {
    if (PHP_SAPI === 'cli' && isset($GLOBALS['WI_FAKE_AI']) && is_callable($GLOBALS['WI_FAKE_AI'])) return ($GLOBALS['WI_FAKE_AI'])($body); // tests: a stand-in AI, no provider is called
    $GLOBALS['WI_AI_PAYER'] = ''; if (wi_ai_scope() === '' && $GLOBALS['WI_AI_PAYER'] !== '' && ($body['action'] ?? '') === 'ai_json') wi_ai_allowance_use($GLOBALS['WI_AI_PAYER']); // a client on the operator's keys: counted against their plan
    if (wi_sys('ai_engine') !== 'local' && wi_ai_scope() === '') return wi_bridge($body, $timeout);
    require_once __DIR__ . '/modules/lib/WiAiProviders.php';
    $eng = new WiAiProviders(wi_ai_scope()); $cat = WiAiProviders::catalog(); $act = (string) ($body['action'] ?? '');
    if ($act === 'ai_json') {
        $prompt = mb_substr((string) ($body['prompt'] ?? ''), 0, 60000); if ($prompt === '') return ['ok' => false, 'error' => 'Empty prompt.'];
        $t0 = microtime(true); $eng->prefer = preg_replace('/[^A-Za-z0-9_.:\/@-]+/', '', (string) ($body['prefer'] ?? ''));
        @set_time_limit(max(60, $timeout + 30)); $r = $eng->vision_json($prompt, null, null);
        $u = $eng->usage + ['ms' => (int) round((microtime(true) - $t0) * 1000), 'mode' => $eng->mode()];
        return !empty($r['ok']) ? ['ok' => true, 'data' => $r['data'], 'provider' => $r['provider'], 'model' => $r['model'], 'usage' => $u] : ['ok' => false, 'error' => $r['error'] ?? 'AI failed.', 'usage' => $u];
    }
    if (!in_array($act, ['ai_list', 'ai_save', 'ai_refresh', 'ai_models', 'ai_mode'], true)) return wi_bridge($body, $timeout); // invoice actions stay with the invoice system
    $id = strtolower(trim((string) ($body['id'] ?? ''))); $config = $eng->stored_config();
    if ($act === 'ai_mode') { $config['mode'] = ($body['mode'] ?? '') === 'parallel' ? 'parallel' : 'sequential'; $eng->save_config($config); }
    elseif ($act !== 'ai_list' && !isset($cat[$id])) return ['ok' => false, 'error' => 'Unknown provider.'];
    if ($act === 'ai_save') {
        $old = $config['providers'][$id] ?? []; $key = trim((string) ($body['api_key'] ?? ''));
        $model = preg_replace('/[^A-Za-z0-9_.:\/@-]+/', '', trim((string) ($body['model'] ?? ($old['model'] ?? 'auto'))));
        $config['providers'][$id] = ['enabled' => !empty($body['enabled']) ? 1 : 0, 'api_key' => !empty($body['clear_key']) ? '' : ($key !== '' ? $eng->encrypt($key) : ($old['api_key'] ?? '')),
            'model' => $model === '' ? 'auto' : $model, 'plan' => in_array($body['plan'] ?? '', ['free', 'paid'], true) ? $body['plan'] : 'auto', 'daily_limit' => max(0, (int) ($body['daily_limit'] ?? 0))];
        if (!empty($cat[$id]['custom'])) { $url = rtrim(trim((string) ($body['base_url'] ?? ($old['base_url'] ?? ''))), '/');
            if ($url !== '' && !preg_match('#^https://[a-z0-9.-]+(:\d+)?(/[\w./-]*)?$#i', $url)) return ['ok' => false, 'error' => 'Base URL must start with https:// (e.g. https://api.example.com/v1).'];
            if ($url !== '' && !WiAiProviders::public_ips((string) parse_url($url, PHP_URL_HOST))) return ['ok' => false, 'error' => 'The provider address is not a public https address.'];
            $config['providers'][$id]['base_url'] = $url; $config['providers'][$id]['name'] = mb_substr(trim((string) ($body['name'] ?? ($old['name'] ?? ''))), 0, 40); }
        if (!empty($cat[$id]['base_editable'])) { $url = trim((string) ($body['base_url'] ?? ($old['base_url'] ?? ''))); // Anthropic: the address, with ?workspace=wrkspc_… for an organisation's key
            if ($url !== '' && !preg_match('#^https://api\.anthropic\.com/?(\?workspace=[A-Za-z0-9_]+)?$#', $url)) return ['ok' => false, 'error' => 'The address must be https://api.anthropic.com or https://api.anthropic.com?workspace=wrkspc_…'];
            $config['providers'][$id]['base_url'] = $url; }
        if (isset($body['order']) && is_array($body['order'])) $config['order'] = $eng->normalize_order($body['order']);
        // Extra keys of the same provider: each one is its own — a key, a model and a plan like the main key. "keys" is the whole list (a key missing from it is removed);
        // an entry with an id and no key text keeps its saved key. key_mode: failover (the next key when one reaches its limit) or together (the keys share the requests).
        $oldKeys = (array) ($old['keys'] ?? []);
        if (!$oldKeys && !empty($old['extra_keys'])) foreach ((array) $old['extra_keys'] as $e) { $x = $eng->decrypt((string) $e); $pl = 'auto'; if (preg_match('/^(free|paid):(.+)$/s', $x, $m0)) { $pl = $m0[1]; $x = $m0[2]; } if ($x !== '') $oldKeys[] = ['id' => substr(md5($x), 0, 8), 'key' => $e, 'plan' => $pl, 'model' => 'auto']; }
        $byId = []; foreach ($oldKeys as $ok) $byId[(string) ($ok['id'] ?? '')] = $ok;
        if (!empty($body['clear_extra'])) { $newKeys = []; }
        elseif (isset($body['keys']) && is_array($body['keys'])) {
            $newKeys = []; $seen = [$eng->decrypt((string) $config['providers'][$id]['api_key']) => 1];
            foreach (array_slice($body['keys'], 0, 10) as $kk) { if (!is_array($kk)) continue; $kid = preg_replace('/[^a-f0-9]/', '', (string) ($kk['id'] ?? '')); $txt = trim((string) ($kk['api_key'] ?? '')); $txt = (string) preg_replace('/^(free|paid):/', '', $txt);
                $prev = $kid !== '' ? ($byId[$kid] ?? null) : null;
                if ($txt !== '') { if (strlen($txt) > 300 || isset($seen[$txt])) continue; $enc = $eng->encrypt($txt); $plainKey = $txt; }
                elseif ($prev) { $enc = (string) $prev['key']; $plainKey = $eng->decrypt($enc); if (preg_match('/^(free|paid):(.+)$/s', $plainKey, $m1)) $plainKey = $m1[2]; }
                else continue;
                if (isset($seen[$plainKey])) continue; $seen[$plainKey] = 1;
                $kmodel = preg_replace('/[^A-Za-z0-9_.:\/@-]+/', '', trim((string) ($kk['model'] ?? 'auto')));
                $newKeys[] = ['id' => $kid !== '' && $prev ? $kid : substr(bin2hex(random_bytes(4)), 0, 8), 'key' => $enc, 'model' => $kmodel === '' ? 'auto' : $kmodel, 'plan' => in_array($kk['plan'] ?? '', ['free', 'paid'], true) ? $kk['plan'] : 'auto'];
            }
        } else { $newKeys = $oldKeys; }
        // the old way: a text of keys, one per line ("paid:KEY" / "free:KEY") — still accepted
        foreach (preg_split('/[\s,]+/', (string) ($body['extra_keys'] ?? '')) as $k) { $k = trim($k); $pl = 'auto'; if (preg_match('/^(free|paid):(.+)$/s', $k, $m2)) { $pl = $m2[1]; $k = $m2[2]; }
            $dup = false; foreach ($newKeys as $nk) { $pn = $eng->decrypt((string) $nk['key']); if (preg_match('/^(free|paid):(.+)$/s', $pn, $m3)) $pn = $m3[2]; if ($pn === $k) $dup = true; }
            if ($k !== '' && strlen($k) <= 300 && !$dup && $k !== $eng->decrypt((string) $config['providers'][$id]['api_key'])) $newKeys[] = ['id' => substr(bin2hex(random_bytes(4)), 0, 8), 'key' => $eng->encrypt($k), 'model' => 'auto', 'plan' => $pl]; }
        $newKeys = array_slice($newKeys, 0, 10);
        if ($newKeys) $config['providers'][$id]['keys'] = $newKeys; else unset($config['providers'][$id]['keys']);
        unset($config['providers'][$id]['extra_keys']); // everything is in "keys" now
        $config['providers'][$id]['key_mode'] = in_array($body['key_mode'] ?? ($old['key_mode'] ?? ''), ['failover', 'together'], true) ? ($body['key_mode'] ?? $old['key_mode']) : 'failover';
        $eng->save_config($config);
        if ($key !== '') { @set_time_limit(120); $eng->refresh($id); }
    }
    if ($act === 'ai_refresh') { @set_time_limit(120); $eng->refresh($id); }
    if ($act === 'ai_models') { @set_time_limit(120); $eng->refresh_models_only($id); } // only the list of models, quick
    $list = []; foreach ($eng->stored_config()['order'] as $pid) { $st = $eng->public_state($pid); if ($st) $list[] = $st + ['tagline' => $cat[$pid]['tagline'], 'color' => $cat[$pid]['color'], 'signup_url' => $cat[$pid]['signup_url'], 'key_hint' => $cat[$pid]['key_hint']]; }
    return ['ok' => true, 'providers' => $list, 'mode' => $eng->mode(), 'engine' => 'local'];
}

/* ---------- Bridge to the invoice system (AI + invoice images) ---------- */
function wi_bridge(array $body, int $timeout = 90): array {
    $f = WI_DIR . '/bridge_token';
    if (!is_file($f)) { file_put_contents($f, bin2hex(random_bytes(32))); @chmod($f, 0644); }
    if ((string) wi_sys('bridge_url') === '') return ['ok' => false, 'error' => 'The AI / invoice bridge is not set up (Settings → System).'];
    $ch = curl_init((string) wi_sys('bridge_url'));
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => array_merge((string) wi_sys('bridge_host') !== '' ? ['Host: ' . wi_sys('bridge_host')] : [], ['Content-Type: application/json', 'X-Bridge-Token: ' . wi_secret('bridge_token', trim((string) file_get_contents($f)))])]);
    $r = json_decode((string) curl_exec($ch), true); curl_close($ch);
    return is_array($r) ? $r : ['ok' => false, 'error' => 'The invoice system did not respond.'];
}
/** Phone + customer of a chat (via the gateway's contact lookup). */
function wi_chat_customer(string $jid): array {
    $c = wi_contact($jid);
    $phone = (string) ($c['phone'] ?? '');
    $cust = $phone !== '' ? (wi_customers()[$phone] ?? null) : null;
    return ['phone' => $phone, 'name' => (string) ($c['name'] ?? ''), 'customer' => $cust ? wi_customer_detail((int) $cust['id']) : null];
}
/** Invoices of one customer (all, newest first) with due date. */
function wi_customer_invoices(int $billerId): array {
    try {
        $db = wi_inv_db(); if (!$db) return [];
        $st = $db->prepare("SELECT id, reference, total, total_due, status, date, date_due, currency FROM invoices WHERE bill_to_id = ? AND status <> 'archived' ORDER BY date DESC LIMIT 30");
        $st->bind_param('i', $billerId); $st->execute(); $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        return $rows;
    } catch (Throwable $e) { return []; }
}
function wi_is_overdue(array $i): bool {
    return (float) $i['total_due'] > 0 && in_array($i['status'], ['unpaid', 'partial', 'overdue'], true) && (($i['status'] === 'overdue') || (!empty($i['date_due']) && $i['date_due'] !== '0000-00-00' && strtotime($i['date_due']) < strtotime('today')));
}

/* ---------- AI tasks: every AI call is logged with its task, model, tokens, time and progress ---------- */
/* ---------- Bot log: one row per entry in its own table (appending no longer rewrites a 400 KB set); the old set is the fallback ---------- */
function wi_bot_log_db(): ?mysqli {
    static $ready = null; $m = wi_db(); if (!$m) return null;
    if ($ready === null) { $ready = (bool) $m->query("CREATE TABLE IF NOT EXISTS wi_bot_log (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, base VARCHAR(40) NOT NULL, at VARCHAR(32) NOT NULL, data MEDIUMTEXT NOT NULL, KEY base_id (base, id)) DEFAULT CHARSET=utf8mb4");
        if ($ready && !(int) ($m->query("SELECT COUNT(*) FROM wi_bot_log")->fetch_row()[0] ?? 0)) { // first use: the entries kept so far move over once
            $st = $m->prepare("INSERT INTO wi_bot_log (base, at, data) VALUES (?,?,?)");
            foreach ((array) wi_read('bot_log.json', []) as $b => $rows) foreach ((array) $rows as $r) { $b = (string) $b; $at = (string) ($r['at'] ?? ''); $js = json_encode($r, JSON_UNESCAPED_UNICODE); $st->bind_param('sss', $b, $at, $js); $st->execute(); } } }
    return $ready ? $m : null;
}
/** Adds one entry to a number's bot log (the newest 500 are kept). */
function wi_bot_log_add(string $aid, array $row): void {
    if (PHP_SAPI === 'cli' && isset($GLOBALS['WI_MEM_STORE'])) { $GLOBALS['WI_MEM_STORE']['_bot_log'][] = ['aid' => $aid] + $row; return; }
    $row += ['at' => date('c')]; $m = wi_bot_log_db();
    if (!$m) { wi_update('bot_log.json', function ($L) use ($aid, $row) { $L[$aid][] = $row; $L[$aid] = array_slice($L[$aid], -500); return $L; }); return; }
    $st = $m->prepare("INSERT INTO wi_bot_log (base, at, data) VALUES (?,?,?)"); $at = (string) $row['at']; $js = json_encode($row, JSON_UNESCAPED_UNICODE); $st->bind_param('sss', $aid, $at, $js); $st->execute();
    if (random_int(1, 50) === 1) { $b = $m->real_escape_string($aid); $cut = $m->query("SELECT id FROM wi_bot_log WHERE base='$b' ORDER BY id DESC LIMIT 1 OFFSET 500"); if ($cut && ($x = $cut->fetch_row())) $m->query("DELETE FROM wi_bot_log WHERE base='$b' AND id <= " . (int) $x[0]); }
}
/** A number's bot log, oldest first (at most the newest $limit). */
function wi_bot_log(string $aid, int $limit = 500): array {
    if (PHP_SAPI === 'cli' && isset($GLOBALS['WI_MEM_STORE'])) return array_slice(array_values(array_filter((array) ($GLOBALS['WI_MEM_STORE']['_bot_log'] ?? []), fn($r) => ($r['aid'] ?? '') === $aid)), -$limit);
    $m = wi_bot_log_db(); if (!$m) return array_slice((array) (wi_read('bot_log.json', [])[$aid] ?? []), -$limit);
    $st = $m->prepare("SELECT data FROM wi_bot_log WHERE base=? ORDER BY id DESC LIMIT ?"); $st->bind_param('si', $aid, $limit); $st->execute(); $r = $st->get_result(); $out = [];
    while ($x = $r->fetch_row()) $out[] = json_decode((string) $x[0], true) ?: [];
    return array_reverse($out);
}
/* ---------- AI activity: one row per AI call in its own table (each call no longer rewrites the whole list twice); the old set is the fallback ---------- */
function wi_ai_db(): ?mysqli {
    static $ready = null; $m = wi_db(); if (!$m) return null;
    if ($ready === null) { $ready = (bool) $m->query("CREATE TABLE IF NOT EXISTS wi_ai_tasks (seq BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, id VARCHAR(16) NOT NULL, data MEDIUMTEXT NOT NULL, UNIQUE KEY id (id)) DEFAULT CHARSET=utf8mb4");
        if ($ready && !(int) ($m->query("SELECT COUNT(*) FROM wi_ai_tasks")->fetch_row()[0] ?? 0)) { $st = $m->prepare("INSERT IGNORE INTO wi_ai_tasks (id, data) VALUES (?,?)");
            foreach ((array) wi_read('ai_tasks.json', []) as $r) { $i = (string) ($r['id'] ?? bin2hex(random_bytes(6))); $js = json_encode($r, JSON_UNESCAPED_UNICODE); $st->bind_param('ss', $i, $js); $st->execute(); } } } // first use: the list kept so far moves over once
    return $ready ? $m : null;
}
/** The latest AI calls (oldest first, at most 400). */
function wi_ai_tasks(): array {
    $m = wi_ai_db(); if (!$m) return (array) wi_read('ai_tasks.json', []);
    $r = $m->query("SELECT data FROM wi_ai_tasks ORDER BY seq DESC LIMIT 400"); $out = []; while ($r && ($x = $r->fetch_row())) $out[] = json_decode((string) $x[0], true) ?: [];
    return array_reverse($out);
}
/** Saves one AI call (new, or its result merged in by id). */
function wi_ai_task_put(array $row): void {
    if (PHP_SAPI === 'cli' && isset($GLOBALS['WI_MEM_STORE'])) { $GLOBALS['WI_MEM_STORE']['_ai_tasks'][$row['id']] = $row + ($GLOBALS['WI_MEM_STORE']['_ai_tasks'][$row['id']] ?? []); return; }
    try { $m = wi_ai_db();
        if (!$m) { wi_update('ai_tasks.json', function ($d) use ($row) { $f = false; foreach ($d as &$x) if (($x['id'] ?? '') === $row['id']) { $x = $row + $x; $f = true; } unset($x); if (!$f) $d[] = $row; return array_slice($d, -400); }); return; }
        $cur = $m->prepare("SELECT data FROM wi_ai_tasks WHERE id=?"); $cur->bind_param('s', $row['id']); $cur->execute(); $old = $cur->get_result()->fetch_row();
        if ($old) $row += (json_decode((string) $old[0], true) ?: []);
        $js = json_encode($row, JSON_UNESCAPED_UNICODE); $st = $m->prepare("INSERT INTO wi_ai_tasks (id, data) VALUES (?,?) ON DUPLICATE KEY UPDATE data=VALUES(data)"); $st->bind_param('ss', $row['id'], $js); $st->execute();
        if (random_int(1, 50) === 1) { $c = $m->query("SELECT seq FROM wi_ai_tasks ORDER BY seq DESC LIMIT 1 OFFSET 400"); if ($c && ($x = $c->fetch_row())) $m->query("DELETE FROM wi_ai_tasks WHERE seq <= " . (int) $x[0]); }
    } catch (Throwable $e) { /* the log never breaks an AI call */ }
}
/** Runs one AI task through the invoice system's providers and records it. $meta: what it works on, batch progress. */
function wi_ai(string $task, string $prompt, int $timeout = 180, array $meta = []): array {
    $id = bin2hex(random_bytes(6));
    $row = ['id' => $id, 'task' => $task, 'state' => 'running', 'at' => date('c'), 'user' => (string) ($_SESSION['email'] ?? 'system'), 'account' => (string) ((wi_ctx('ai_acc')['id'] ?? '') ?: ((function_exists('wi_current') && PHP_SAPI !== 'cli') ? (wi_current()['id'] ?? '') : '')), /* the bot's number (set by the tick) or the open number */
        'subject' => mb_substr((string) ($meta['subject'] ?? ''), 0, 120), 'progress' => $meta['progress'] ?? null, 'prompt_chars' => mb_strlen($prompt)];
    wi_ai_task_put($row);
    $t0 = microtime(true);
    $r = wi_ai_call(['action' => 'ai_json', 'prompt' => $prompt] + (!empty($meta['prefer']) ? ['prefer' => (string) $meta['prefer']] : []), $timeout);
    $u = (array) ($r['usage'] ?? []);
    $done = ['state' => !empty($r['ok']) ? 'done' : 'failed', 'ms' => (int) round((microtime(true) - $t0) * 1000), 'model' => trim(($r['provider'] ?? '') . ' ' . ($r['model'] ?? '')),
        'tokens_in' => (int) ($u['tokens_in'] ?? 0), 'tokens_out' => (int) ($u['tokens_out'] ?? 0), 'requests' => (int) ($u['requests'] ?? 0), 'mode' => (string) ($u['mode'] ?? ''), 'error' => empty($r['ok']) ? mb_substr((string) ($r['error'] ?? ''), 0, 300) : ''];
    wi_ai_task_put(['id' => $id] + $done);
    $r['task'] = ['id' => $id, 'task' => $task] + $done;
    return $r;
}
require_once __DIR__ . '/modules/saas.php'; // plans, sign-up, AI allowance

/** A group message's tags kept only for people who are in the group right now (members read once a few minutes); a tag of someone gone is dropped from the text too. */
function wi_mentions_present(array $post, array $acc): array {
    static $mem = [];
    $g = (string) $post['jid']; $k = (string) ($acc['id'] ?? '') . '|' . $g;
    if (!isset($mem[$k])) { $mem[$k] = null; $c = wi_read('group_members_cache.json', [])[$k] ?? null;
        if (is_array($c) && ($c['at'] ?? 0) > time() - 300) $mem[$k] = $c['m'];
        else { $r = wi_gateway('/groups/members?jid=' . rawurlencode($g), null, $acc); if (!empty($r['members'])) { $m = [];
            foreach ((array) $r['members'] as $x) { foreach ([(string) ($x['phone'] ?? ''), (string) ($x['jid'] ?? ''), (string) ($x['id'] ?? ''), (string) ($x['lid'] ?? '')] as $v) { $d = preg_replace('/\D/', '', explode('@', $v)[0]); if ($d !== '') $m[$d] = 1; } }
            $mem[$k] = $m; wi_update('group_members_cache.json', function ($C) use ($k, $m) { $C[$k] = ['at' => time(), 'm' => $m]; foreach ($C as $kk => $v) if (($v['at'] ?? 0) < time() - 86400) unset($C[$kk]); return $C; }); } } }
    $m = $mem[$k]; if (!$m) return $post; // members could not be read: sent as it is
    $keep = []; $gone = [];
    foreach ((array) $post['mentions'] as $j) { $d = preg_replace('/\D/', '', explode('@', (string) $j)[0]); if (isset($m[$d])) $keep[] = $j; else $gone[] = $d; }
    $post['mentions'] = $keep;
    if ($gone) { $post['text'] = trim(preg_replace('/@(' . implode('|', array_map('preg_quote', array_filter($gone))) . ')\b\s?/u', '', (string) ($post['text'] ?? ''))); if (function_exists('wi_log')) wi_log('mention_dropped', ['group' => $g, 'who' => $gone]); }
    return $post;
}
/** Sign-in with an email or a WhatsApp number: a number on one account gives its email; on several accounts the person must use the email. */
function wi_login_id(string $in): array {
    $in = strtolower(trim($in)); if ($in === '' || str_contains($in, '@')) return ['email' => $in, 'many' => false];
    $p = wi_norm_phone($in); if ($p === '') return ['email' => $in, 'many' => false];
    $hit = array_values(array_unique(array_map(fn($u) => strtolower((string) $u['email']), array_filter(wi_users(), fn($u) => !empty($u['active']) && wi_norm_phone((string) ($u['phone'] ?? '')) === $p))));
    return ['email' => count($hit) === 1 ? $hit[0] : $in, 'many' => count($hit) > 1];
}
/** Email by SMTP (Plans page: host, port, user, sender; the password is kept as a secret). Port 465 = TLS from the start, other ports use STARTTLS. */
function wi_mail_on(): bool { return trim((string) wi_sys('smtp_host')) !== '' && trim((string) wi_sys('smtp_user')) !== '' && wi_secret('smtp_pass') !== ''; }
function wi_mail(string $to, string $subject, string $text): bool {
    if (!wi_mail_on() || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $host = trim((string) wi_sys('smtp_host')); $port = (int) (wi_sys('smtp_port') ?: 587); $user = trim((string) wi_sys('smtp_user')); $from = trim((string) (wi_sys('smtp_from') ?: $user));
    $s = @stream_socket_client(($port === 465 ? 'ssl://' : 'tcp://') . $host . ':' . $port, $en, $es, 15); if (!$s) { wi_log('mail_failed', ['why' => 'connect']); return false; }
    stream_set_timeout($s, 15);
    $rd = function () use ($s) { $r = ''; while (($l = fgets($s, 1000)) !== false) { $r .= $l; if (strlen($l) < 4 || $l[3] === ' ') break; } return $r; };
    $cmd = function ($c, $ok) use ($s, $rd) { if ($c !== null) fwrite($s, $c . "\r\n"); $r = $rd(); return in_array(substr($r, 0, 3), (array) $ok, true); };
    $me = parse_url((string) wi_sys('base_url'), PHP_URL_HOST) ?: 'localhost';
    $good = $cmd(null, '220') && $cmd('EHLO ' . $me, '250');
    if ($good && $port !== 465) $good = $cmd('STARTTLS', '220') && stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) && $cmd('EHLO ' . $me, '250');
    $good = $good && $cmd('AUTH LOGIN', '334') && $cmd(base64_encode($user), '334') && $cmd(base64_encode(wi_secret('smtp_pass')), '235')
        && $cmd('MAIL FROM:<' . $from . '>', '250') && $cmd('RCPT TO:<' . $to . '>', ['250', '251']) && $cmd('DATA', '354');
    if ($good) { $name = (string) (wi_sys('brand_name') ?: $me);
        $h = 'From: =?UTF-8?B?' . base64_encode($name) . '?= <' . $from . ">\r\nTo: <" . $to . ">\r\nSubject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\nDate: " . date('r') . "\r\nMessage-ID: <" . bin2hex(random_bytes(8)) . '@' . $me . ">\r\n";
        $good = $cmd($h . "\r\n" . chunk_split(base64_encode($text)) . '.', '250'); }
    @fwrite($s, "QUIT\r\n"); fclose($s);
    if (!$good) wi_log('mail_failed', ['to' => $to]);
    return $good;
}
/** Encrypted message archive (see gateway_session.js): the installation key (made once, a copy kept as a secret) and the switch per number. */
function wi_archive_key(): string {
    $f = dirname(__DIR__) . '/wa-local/.archive_key'; $k = is_file($f) ? trim((string) @file_get_contents($f)) : '';
    if (strlen((string) base64_decode($k, true)) === 32) return $k;
    $k = function_exists('wi_secret') ? wi_secret('archive_key') : ''; if (strlen((string) base64_decode($k, true)) !== 32) $k = base64_encode(random_bytes(32));
    @file_put_contents($f, $k, LOCK_EX); @chmod($f, 0600); if (function_exists('wi_secret_set') && wi_secret('archive_key') !== $k) wi_secret_set('archive_key', $k); return $k;
}
function wi_archive_encrypt(string $aid, bool $on = true): bool {
    $dir = dirname(__DIR__) . '/wa-local' . ($aid === 'main' ? '' : '/accounts/' . preg_replace('/[^a-z0-9_-]/', '', $aid)); if (!is_dir($dir) && !@mkdir($dir, 0700, true)) return false;
    if ($on) { wi_archive_key(); return @file_put_contents($dir . '/encrypt.on', date('c')) !== false; } return !is_file($dir . '/encrypt.on') || @unlink($dir . '/encrypt.on');
}
function wi_archive_dec(array $r): array {
    static $k = null; $k ??= (string) base64_decode(wi_archive_key(), true);
    foreach (['text', 'caption', 'quote_text'] as $f) if (is_string($r[$f] ?? null) && str_starts_with($r[$f], '§e1:')) { $b = (string) base64_decode(substr($r[$f], strlen('§e1:')), true);
        $v = strlen($b) > 28 ? openssl_decrypt(substr($b, 28), 'aes-256-gcm', $k, OPENSSL_RAW_DATA, substr($b, 0, 12), substr($b, 12, 16)) : false; $r[$f] = $v === false ? '' : $v; }
    return $r;
}
/** Sends the archive key to the super admins (their WhatsApp from the main number, and email when SMTP is set). The words "archive password" keep the gateway from storing the message. */
function wi_archive_key_backup(): array {
    if (!function_exists('wf_msg')) require_once __DIR__ . '/features.php'; $txt = wf_msg('archive_key_backup', ['key' => wi_archive_key()]); $out = ['wa' => 0, 'mail' => 0];
    foreach (wi_users() as $u) { if (($u['role'] ?? '') !== 'super' || empty($u['active'])) continue;
        if (($ph = wi_norm_phone((string) ($u['phone'] ?? ''))) !== '' && wi_send_whatsapp($ph, $txt)) $out['wa']++;
        if (wi_mail_on() && wi_mail((string) $u['email'], wi_t('Message archive key (backup)'), $txt)) $out['mail']++; }
    wi_log('archive_key_backup', $out); return $out;
}
/** The work that must be right (group cases, commands, roles) goes to the paid models first: the first provider of this order that is on, with its chosen model. Free ones stay the fallback. */
function wi_ai_paid(string $order = 'anthropic,opencode_go'): string {
    static $m = []; if (isset($m[$order])) return $m[$order]; if (!function_exists('wf_role_strong_model')) @include_once __DIR__ . '/features.php';
    return $m[$order] = function_exists('wf_role_strong_model') ? wf_role_strong_model('', $order) : '';
}
