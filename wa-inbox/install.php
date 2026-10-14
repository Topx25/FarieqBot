<?php
declare(strict_types=1);
/*
 * First-run installer: checks the server, connects the database, creates every table, saves the system settings,
 * creates the first super admin and the main number's gateway token, then locks itself (.data/installed.json).
 */
require __DIR__ . '/lib.php';
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
$lock = WI_DIR . '/installed.json';
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
if (!is_file($lock) && (wi_users() || is_file(WI_DIR . '/db.php'))) { @file_put_contents($lock, json_encode(['at' => date('c'), 'by' => 'auto-lock', 'first_number_at' => date('c'), 'note' => 'users or database already present'])); }
if (is_file($lock)) { http_response_code(403); echo '<!doctype html><meta charset="utf-8"><title>Installed</title><p style="font:16px system-ui;padding:40px">This system is already installed. <a href="./">Open the dashboard</a>.</p>'; exit; }
if (!is_dir(WI_DIR)) @mkdir(WI_DIR, 0777, true);

$checks = [
    'PHP 8.1 or newer' => version_compare(PHP_VERSION, '8.1', '>='),
    'mysqli' => extension_loaded('mysqli'), 'curl' => extension_loaded('curl'), 'openssl' => extension_loaded('openssl'),
    'mbstring' => extension_loaded('mbstring'), 'zip' => class_exists('ZipArchive'), 'gd (AI image checks)' => extension_loaded('gd'),
    'Data folder writable (.data)' => is_dir(WI_DIR) && is_writable(WI_DIR),
    'Gateway folder (../wa-local)' => is_file(dirname(__DIR__) . '/wa-local/server.js'),
];
$allOk = !in_array(false, $checks, true);
$err = ''; $done = null;
$host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'); $dir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/install.php'))), '/');
$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
$f = $_POST + ['db_host' => 'localhost', 'db_name' => '', 'db_user' => '', 'brand' => 'WhatsApp Inbox', 'base_url' => ($https ? 'https://' : 'http://') . $host . $dir . '/',
    'timezone' => date_default_timezone_get() ?: 'UTC', 'country' => '', 'lang' => 'ar', 'preset' => 'general', 'name' => '', 'email' => '', 'phone' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $allOk) {
    try {
        $email = strtolower(trim((string) $f['email'])); $pw = (string) ($_POST['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Write a valid email for the admin.');
        if (strlen($pw) < 10) throw new RuntimeException('The admin password must be at least 10 characters.');
        if (!in_array((string) $f['timezone'], timezone_identifiers_list(), true)) throw new RuntimeException('Unknown time zone.');
        if (!preg_match('#^https?://#i', (string) $f['base_url'])) throw new RuntimeException('The dashboard address must start with http:// or https://');
        mysqli_report(MYSQLI_REPORT_OFF);
        $m = @new mysqli((string) $f['db_host'], (string) $f['db_user'], (string) ($_POST['db_pass'] ?? ''), (string) $f['db_name']);
        if ($m->connect_errno) throw new RuntimeException('Database connection failed: ' . $m->connect_error);
        $m->close();
        // 1) database login for this app only (PHP file: never served as text)
        $db = "<?php\n// Written by the installer. This app's own database login.\nreturn " . var_export(['host' => (string) $f['db_host'], 'user' => (string) $f['db_user'], 'pass' => (string) ($_POST['db_pass'] ?? ''), 'name' => (string) $f['db_name']], true) . ";\n";
        if (@file_put_contents(WI_DIR . '/db.php', $db) === false) throw new RuntimeException('Could not write .data/db.php');
        @chmod(WI_DIR . '/db.php', 0640);
        // 2) settings
        $cfg = wi_read('config.json', []);
        $cfg['system'] = ['brand_name' => trim((string) $f['brand']) ?: 'WhatsApp Inbox', 'base_url' => rtrim((string) $f['base_url'], '/') . '/', 'cookie_path' => ($dir ?: '') . '/',
            'timezone' => (string) $f['timezone'], 'country_code' => preg_replace('/\D/', '', (string) $f['country']), 'msg_lang' => $f['lang'] === 'en' ? 'en' : 'ar', 'ai_engine' => 'local', 'addon_invoices' => 0];
        $cfg['users_db'] = true; $cfg['cases_db'] = true;
        $cfg['gateway_token'] = $cfg['gateway_token'] ?? bin2hex(random_bytes(24)); $cfg['main_port'] = (int) ($cfg['main_port'] ?? 3188); $cfg['main_name'] = $cfg['main_name'] ?? 'Main number';
        wi_write('config.json', $cfg);
        // 3) tables
        require __DIR__ . '/features.php';
        foreach (['wi_db_install', 'wi_db_install_cases', 'wi_db_install_jobs', 'wf_learn_install', 'wf_docs_install', 'wf_clean_install', 'wf_grow_install'] as $fn) if (function_exists($fn)) $fn();
        // 4) business profile for the main number
        $presets = wf_pack_presets(); $bot = wi_read('bot.json', []); $bot['main']['pack'] = ['preset' => isset($presets[$f['preset']]) ? (string) $f['preset'] : 'general']; wi_write('bot.json', $bot);
        // 5) first super admin
        wi_save_users([['email' => $email, 'name' => trim((string) $f['name']) ?: $email, 'phone' => preg_replace('/\D/', '', (string) $f['phone']), 'role' => 'super',
            'password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'active' => true, 'created_by' => 'installer', 'created_at' => date('c')]]);
        // 6) gateway content rules start empty (a profile can fill them later in Settings → Gateway rules)
        $rules = dirname(__DIR__) . '/wa-local/rules.json'; if (!is_file($rules)) { @file_put_contents($rules, json_encode(['secret_patterns' => ['رمز دخول|رمز التحقق|كود التحقق|one-time password|verification code|\\bOTP\\b|password|كلمة المرور|كلمة السر'], 'strip_link_domains' => [], 'tags' => []], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)); @chmod($rules, 0666); }
        file_put_contents($lock, json_encode(['at' => date('c'), 'by' => $email, 'version' => (require __DIR__ . '/version.php')[0]['v'] ?? '']));
        $done = ['root' => dirname(__DIR__), 'php' => PHP_BINARY ?: '/usr/bin/php'];
    } catch (Throwable $e) { $err = $e->getMessage(); }
}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Install</title>
<style>
:root{--bg:#f6f7fb;--card:#fff;--ink:#16181d;--mut:#667085;--line:#e4e7ec;--acc:#2563eb;--bad:#d92d20;--ok:#079455}
@media (prefers-color-scheme:dark){:root{--bg:#0f1115;--card:#171a21;--ink:#e8eaf0;--mut:#98a2b3;--line:#2a2f3a;--acc:#5b8cff}}
body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.5 system-ui,-apple-system,"Segoe UI",Tahoma,sans-serif}
main{max-width:760px;margin:32px auto;padding:0 16px}.card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:20px;margin:14px 0}
h1{font-size:22px;margin:0 0 4px}h2{font-size:16px;margin:0 0 10px}.mut{color:var(--mut);font-size:13px}
.g{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}label{display:flex;flex-direction:column;gap:4px;font-size:13px;color:var(--mut)}
input,select{font:inherit;color:var(--ink);background:var(--bg);border:1px solid var(--line);border-radius:10px;padding:9px 11px}
button{font:inherit;background:var(--acc);color:#fff;border:0;border-radius:10px;padding:11px 20px;cursor:pointer}
.ok{color:var(--ok)}.bad{color:var(--bad)}pre{background:var(--bg);border:1px solid var(--line);border-radius:10px;padding:12px;overflow:auto;font-size:12.5px;direction:ltr}
ul{margin:0;padding-left:18px}
</style></head><body><main>
<h1>Install</h1><p class="mut">First run: database, settings and the first admin. This page locks itself when done.</p>
<?php if ($done): ?>
<div class="card"><h2 class="ok">✓ Installed</h2>
<p>1) Add this line to the server's cron (every minute) — it runs the gateways, the worker and the scheduled jobs:</p>
<pre>* * * * * <?= $h($done['php']) ?> <?= $h($done['root']) ?>/wa-local/watchdog.php; <?= $h($done['php']) ?> <?= $h($done['root']) ?>/wa-local/accounts_watchdog.php; <?= $h($done['php']) ?> <?= $h($done['root']) ?>/wa-inbox/worker.php; <?= $h($done['php']) ?> <?= $h($done['root']) ?>/wa-inbox/cron_reminders.php; <?= $h($done['php']) ?> <?= $h($done['root']) ?>/wa-inbox/cron_features.php</pre>
<p>2) Install the gateway's packages once (Node.js 20+): <code>cd <?= $h($done['root']) ?>/wa-local && npm install</code></p>
<p>3) <a href="./">Open the dashboard</a>, sign in, then link the WhatsApp number from <b>WhatsApp numbers</b> and add AI keys in <b>AI providers</b>.</p></div>
<?php else: ?>
<div class="card"><h2>Server check</h2><ul><?php foreach ($checks as $k => $ok): ?><li class="<?= $ok ? 'ok' : 'bad' ?>"><?= $ok ? '✓' : '✕' ?> <?= $h($k) ?></li><?php endforeach; ?></ul>
<?php if (!$allOk): ?><p class="bad">Fix the items marked ✕, then reload this page.</p><?php endif; ?></div>
<?php if ($err): ?><div class="card bad">⚠️ <?= $h($err) ?></div><?php endif; ?>
<?php if ($allOk): ?>
<form method="post">
<div class="card"><h2>Database (MySQL / MariaDB)</h2><div class="g">
<label>Host<input name="db_host" value="<?= $h($f['db_host']) ?>" required></label><label>Database name<input name="db_name" value="<?= $h($f['db_name']) ?>" required></label>
<label>User<input name="db_user" value="<?= $h($f['db_user']) ?>" required autocomplete="off"></label><label>Password<input type="password" name="db_pass" autocomplete="new-password"></label></div></div>
<div class="card"><h2>System</h2><div class="g">
<label>Product name<input name="brand" value="<?= $h($f['brand']) ?>"></label><label>Dashboard address<input name="base_url" value="<?= $h($f['base_url']) ?>"></label>
<label>Time zone<input name="timezone" list="tz" value="<?= $h($f['timezone']) ?>"></label><label>Country code (e.g. 20, 966, 1)<input name="country" value="<?= $h($f['country']) ?>"></label>
<label>Language of system messages<select name="lang"><option value="ar"<?= $f['lang'] === 'ar' ? ' selected' : '' ?>>العربية</option><option value="en"<?= $f['lang'] === 'en' ? ' selected' : '' ?>>English</option></select></label>
<label>Business profile<select name="preset"><option value="general">General business</option><option value="ruqyah"<?= $f['preset'] === 'ruqyah' ? ' selected' : '' ?>>Ruqyah / spiritual healing</option></select></label>
</div><datalist id="tz"><?php foreach (timezone_identifiers_list() as $z) echo '<option value="' . $h($z) . '">'; ?></datalist></div>
<div class="card"><h2>First admin</h2><div class="g">
<label>Name<input name="name" value="<?= $h($f['name']) ?>"></label><label>Email<input type="email" name="email" value="<?= $h($f['email']) ?>" required></label>
<label>WhatsApp number (for login codes)<input name="phone" value="<?= $h($f['phone']) ?>" placeholder="201xxxxxxxxx"></label><label>Password (10+ characters)<input type="password" name="password" minlength="10" required autocomplete="new-password"></label></div></div>
<button type="submit">Install</button>
</form>
<?php endif; endif; ?>
</main></body></html>
