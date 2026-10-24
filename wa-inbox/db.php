<?php
/** Phase 1: users, page grants and the audit trail live in MySQL (the invoice system's database, wi_* tables).
 *  The JSON file is still written as a mirror so the old code path and rollbacks keep working. */
/** Database login. 'app' = this system's own tables: .data/db.php (returns ['host','user','pass','name']) written by the installer;
 *  without it, the invoice system's config.php is used (older installs). 'invoices' = the optional invoice add-on's database. */
function wi_db_creds(string $which = 'app'): ?array {
    if ($which === 'app' && is_file(WI_DIR . '/db.php')) { $c = include WI_DIR . '/db.php'; if (is_array($c) && !empty($c['name'])) return $c + ['host' => 'localhost', 'user' => '', 'pass' => '']; }
    $cfg = (string) (wi_sys('invoices_config') ?: dirname(__DIR__) . '/config.php');
    if (!defined('CONFIG_MYSQL_HOST')) { if (!is_file($cfg)) return null; if (!defined('BASEPATH')) define('BASEPATH', 'wa-inbox'); @include_once $cfg; }
    return defined('CONFIG_MYSQL_HOST') ? ['host' => CONFIG_MYSQL_HOST, 'user' => CONFIG_MYSQL_USER, 'pass' => CONFIG_MYSQL_PASS, 'name' => CONFIG_MYSQL_DB] : null;
}
/** The invoice add-on's database (null when the add-on is off or not set up). */
function wi_inv_db(): ?mysqli {
    static $m = false; if ($m !== false) return $m;
    if (!wi_sys('addon_invoices') || !($c = wi_db_creds('invoices'))) return $m = null;
    mysqli_report(MYSQLI_REPORT_OFF); $x = @new mysqli($c['host'], $c['user'], $c['pass'], $c['name']); if ($x->connect_errno) return $m = null;
    $x->set_charset('utf8mb4'); if (PHP_SAPI === 'cli' && isset($GLOBALS['WI_MEM_STORE'])) $x->query('SET SESSION TRANSACTION READ ONLY'); return $m = $x;
}
function wi_db(bool $reset = false): ?mysqli {
    static $db = false, $used = 0;
    // The server drops idle connections after 60 s: a long task (backup) reconnects instead of failing silently.
    if ($db && time() - $used > 30) { try { if (!@$db->query('SELECT 1')) $db = false; } catch (Throwable $e) { $db = false; } }
    if ($reset) $db = false;
    $used = time();
    if ($db !== false) return $db;
    $db = null;
    $c = wi_db_creds('app'); if (!$c) return null;
    mysqli_report(MYSQLI_REPORT_OFF);
    $m = @new mysqli($c['host'], $c['user'], $c['pass'], $c['name']);
    if ($m->connect_errno) return null;
    $m->set_charset('utf8mb4');
    // tests with the in-memory store: the database can be read but never written (a write fails instead of reaching live data)
    if (PHP_SAPI === 'cli' && isset($GLOBALS['WI_MEM_STORE'])) $m->query('SET SESSION TRANSACTION READ ONLY');
    return $db = $m;
}
/* ---------- Secrets (gateway tokens, bridge token, Google OAuth secret / refresh token): stored in MySQL, encrypted with
 * this installation's key (.data/app.key, AES-256-GCM). Old copies in the .data files are kept as they are (fallback). ---------- */
function wi_secret_key(): string {
    $f = WI_DIR . '/app.key';
    if (!is_file($f)) { file_put_contents($f, base64_encode(random_bytes(32))); @chmod($f, 0644); }
    return (string) base64_decode(trim((string) file_get_contents($f)));
}
function wi_secrets_install(): void {
    static $done = false; if ($done) return; $done = true; $m = wi_db(); if (!$m) return;
    $m->query("CREATE TABLE IF NOT EXISTS wi_secrets (name VARCHAR(120) NOT NULL PRIMARY KEY, value_enc TEXT NOT NULL, updated_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function wi_secret_set(string $name, string $value): bool {
    unset($GLOBALS['WI_SECRET_CACHE'][$name]); // the next read sees the new value (it was read once per request before)
    if (PHP_SAPI === 'cli' && isset($GLOBALS['WI_MEM_STORE'])) { $GLOBALS['WI_SECRET_CACHE'][$name] = $value; return true; } // tests: kept in memory only
    $m = wi_db(); if (!$m) return false; wi_secrets_install();
    if ($value === '') { $st = $m->prepare("DELETE FROM wi_secrets WHERE name = ?"); $st->bind_param('s', $name); return $st->execute(); } // empty = removed
    $iv = random_bytes(12); $tag = ''; $ct = openssl_encrypt($value, 'aes-256-gcm', wi_secret_key(), OPENSSL_RAW_DATA, $iv, $tag); if ($ct === false) return false;
    $enc = 'v1:' . base64_encode($iv . $tag . $ct);
    $st = $m->prepare("INSERT INTO wi_secrets (name, value_enc, updated_at) VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE value_enc = VALUES(value_enc), updated_at = NOW()");
    $st->bind_param('ss', $name, $enc); return $st->execute();
}
/** The secret from the database; when it is not there yet, the old value ($fallback) is saved there encrypted and returned. */
function wi_secret(string $name, string $fallback = ''): string {
    $cache = &$GLOBALS['WI_SECRET_CACHE']; $cache ??= [];
    if (isset($cache[$name])) return $cache[$name];
    $m = wi_db(); $val = '';
    if ($m) { wi_secrets_install(); $st = $m->prepare("SELECT value_enc FROM wi_secrets WHERE name = ?"); $st->bind_param('s', $name); $st->execute(); $row = $st->get_result()->fetch_row();
        if ($row && str_starts_with((string) $row[0], 'v1:')) { $raw = base64_decode(substr((string) $row[0], 3));
            $p = strlen($raw) > 28 ? openssl_decrypt(substr($raw, 28), 'aes-256-gcm', wi_secret_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16)) : false;
            if ($p !== false) $val = $p; } }
    if ($val === '' && $fallback !== '') { $val = $fallback; wi_secret_set($name, $fallback); }
    return $cache[$name] = $val;
}
function wi_db_install(): bool {
    $m = wi_db(); if (!$m) return false;
    $ok = $m->query("CREATE TABLE IF NOT EXISTS wi_users (
        email VARCHAR(190) NOT NULL PRIMARY KEY, name VARCHAR(190) NOT NULL DEFAULT '', phone VARCHAR(32) NOT NULL DEFAULT '',
        role ENUM('super','admin','manager','viewer','client','lead','agent') NOT NULL DEFAULT 'viewer', active TINYINT(1) NOT NULL DEFAULT 1,
        password_hash VARCHAR(255) NOT NULL DEFAULT '', data JSON NULL, created_at DATETIME NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci")
    && $m->query("CREATE TABLE IF NOT EXISTS wi_grants (
        id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(190) NOT NULL, page VARCHAR(40) NOT NULL,
        level ENUM('view','edit') NOT NULL, status ENUM('active','pending') NOT NULL DEFAULT 'active',
        granted_by VARCHAR(190) NOT NULL DEFAULT '', granted_by_name VARCHAR(190) NOT NULL DEFAULT '', granted_at DATETIME NULL, accepted_at DATETIME NULL,
        UNIQUE KEY one_grant (email, page, status), KEY by_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci")
    && $m->query("CREATE TABLE IF NOT EXISTS wi_audit (
        id BIGINT AUTO_INCREMENT PRIMARY KEY, at DATETIME NOT NULL, action VARCHAR(60) NOT NULL, user VARCHAR(190) NOT NULL DEFAULT '',
        acting_as VARCHAR(190) NOT NULL DEFAULT '', ip VARCHAR(64) NOT NULL DEFAULT '', extra JSON NULL, KEY by_at (at), KEY by_user (user)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Older installations: the role list grew (client, then the client's supervisor/employee).
    if ($ok && ($c = $m->query("SHOW COLUMNS FROM wi_users LIKE 'role'")) && !str_contains((string) ($c->fetch_assoc()['Type'] ?? ''), "'agent'"))
        $m->query("ALTER TABLE wi_users MODIFY role ENUM('super','admin','manager','viewer','client','lead','agent') NOT NULL DEFAULT 'viewer'");
    return (bool) $ok;
}
const WI_USER_COLS = ['email', 'name', 'phone', 'role', 'active', 'password_hash', 'created_at'];
/** All users, with their page grants (pages) and pending invitations (invites) rebuilt from wi_grants. */
function wi_db_users(): ?array {
    $m = wi_db(); if (!$m) return null;
    $r = $m->query("SELECT * FROM wi_users ORDER BY created_at, email"); if (!$r) return null;
    $out = []; $idx = [];
    while ($row = $r->fetch_assoc()) {
        $u = (json_decode((string) $row['data'], true) ?: []);
        foreach (WI_USER_COLS as $c) $u[$c] = $row[$c];
        $u['active'] = (bool) $row['active']; if ($u['created_at']) $u['created_at'] = date('c', strtotime($u['created_at']));
        unset($u['pages'], $u['invites']);
        $idx[strtolower($u['email'])] = count($out); $out[] = $u;
    }
    $g = $m->query("SELECT * FROM wi_grants");
    while ($g && ($x = $g->fetch_assoc())) {
        $i = $idx[strtolower($x['email'])] ?? null; if ($i === null) continue;
        if ($x['status'] === 'active') { $out[$i]['pages'][$x['page']] = $x['level']; if ($x['page'] === '*legacy') unset($out[$i]['pages']['*legacy']); }
        else $out[$i]['invites'][$x['page']] = ['level' => $x['level'], 'by' => $x['granted_by'], 'by_name' => $x['granted_by_name'], 'at' => $x['granted_at'] ? date('c', strtotime($x['granted_at'])) : null];
    }
    // A viewer saved with an explicit (possibly empty) page list carries a marker so "no pages" is not read as "the old default".
    foreach ($out as &$u) { if (!empty($u['pages_set']) && !isset($u['pages'])) $u['pages'] = []; unset($u['pages_set']); }
    unset($u);
    return $out;
}
function wi_db_save_users(array $users): bool {
    $m = wi_db(); if (!$m) return false;
    $m->begin_transaction();
    try {
        $keep = [];
        foreach ($users as $u) {
            $e = strtolower(trim((string) ($u['email'] ?? ''))); if ($e === '') continue; $keep[] = $e;
            $data = $u; foreach (WI_USER_COLS as $c) unset($data[$c]); unset($data['pages'], $data['invites']);
            if (array_key_exists('pages', $u)) $data['pages_set'] = true;
            $st = $m->prepare("INSERT INTO wi_users (email,name,phone,role,active,password_hash,data,created_at) VALUES (?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE name=VALUES(name), phone=VALUES(phone), role=VALUES(role), active=VALUES(active), password_hash=VALUES(password_hash), data=VALUES(data)");
            $name = (string) ($u['name'] ?? ''); $phone = (string) ($u['phone'] ?? ''); $role = in_array($u['role'] ?? '', ['super', 'admin', 'manager', 'viewer', 'client', 'lead', 'agent'], true) ? $u['role'] : 'viewer';
            $act = !empty($u['active']) ? 1 : 0; $pw = (string) ($u['password_hash'] ?? ''); $js = json_encode((object) $data, JSON_UNESCAPED_UNICODE);
            $ca = !empty($u['created_at']) ? date('Y-m-d H:i:s', strtotime((string) $u['created_at'])) : date('Y-m-d H:i:s');
            $st->bind_param('ssssisss', $e, $name, $phone, $role, $act, $pw, $js, $ca); $st->execute();
            // grants: replace this user's rows with what the record says
            $d = $m->prepare("DELETE FROM wi_grants WHERE email=?"); $d->bind_param('s', $e); $d->execute();
            $ins = $m->prepare("INSERT INTO wi_grants (email,page,level,status,granted_by,granted_by_name,granted_at,accepted_at) VALUES (?,?,?,?,?,?,?,?)");
            foreach ((array) ($u['pages'] ?? []) as $pg => $lvl) {
                if (!in_array($lvl, ['view', 'edit'], true)) continue;
                $s = 'active'; $by = (string) ($u['shared'][$pg]['by'] ?? ''); $bn = ''; $ga = !empty($u['shared'][$pg]['at']) ? date('Y-m-d H:i:s', strtotime($u['shared'][$pg]['at'])) : null;
                $aa = !empty($u['shared'][$pg]['accepted_at']) ? date('Y-m-d H:i:s', strtotime($u['shared'][$pg]['accepted_at'])) : null;
                $ins->bind_param('ssssssss', $e, $pg, $lvl, $s, $by, $bn, $ga, $aa); $ins->execute();
            }
            foreach ((array) ($u['invites'] ?? []) as $pg => $inv) {
                $lvl = ($inv['level'] ?? '') === 'edit' ? 'edit' : 'view'; $s = 'pending'; $by = (string) ($inv['by'] ?? ''); $bn = (string) ($inv['by_name'] ?? '');
                $ga = !empty($inv['at']) ? date('Y-m-d H:i:s', strtotime($inv['at'])) : date('Y-m-d H:i:s'); $aa = null;
                $ins->bind_param('ssssssss', $e, $pg, $lvl, $s, $by, $bn, $ga, $aa); $ins->execute();
            }
        }
        if ($keep) { $in = implode(',', array_map(fn($x) => "'" . $m->real_escape_string($x) . "'", $keep)); $m->query("DELETE FROM wi_users WHERE email NOT IN ($in)"); $m->query("DELETE FROM wi_grants WHERE email NOT IN ($in)"); }
        $m->commit(); return true;
    } catch (Throwable $e) { $m->rollback(); return false; }
}
function wi_db_audit(array $row): void {
    $m = wi_db(); if (!$m) return;
    $st = $m->prepare("INSERT INTO wi_audit (at,action,user,acting_as,ip,extra) VALUES (?,?,?,?,?,?)"); if (!$st) return;
    $at = date('Y-m-d H:i:s', strtotime((string) $row['time'])); $a = (string) $row['action']; $u = (string) ($row['user'] ?? ''); $as = (string) ($row['acting_as'] ?? ''); $ip = (string) ($row['ip'] ?? '');
    $x = $row; unset($x['time'], $x['action'], $x['user'], $x['acting_as'], $x['ip']); $js = $x ? json_encode($x, JSON_UNESCAPED_UNICODE) : null;
    $st->bind_param('ssssss', $at, $a, $u, $as, $ip, $js); @$st->execute();
}

/* ---------- Phase 2: the shared cases database (cases, reports, case events) ---------- */
function wi_db_install_cases(): bool {
    $m = wi_db(); if (!$m) return false;
    return (bool) ($m->query("CREATE TABLE IF NOT EXISTS wi_cases (
        base VARCHAR(40) NOT NULL, phone VARCHAR(32) NOT NULL, name VARCHAR(190) NOT NULL DEFAULT '', status VARCHAR(20) NOT NULL DEFAULT 'new',
        follower VARCHAR(190) NOT NULL DEFAULT '', treatment_group VARCHAR(80) NOT NULL DEFAULT '', created_by VARCHAR(190) NOT NULL DEFAULT '',
        created_at DATETIME NULL, last_at DATETIME NULL, data JSON NOT NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (base, phone), KEY by_status (base, status), KEY by_name (base, name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci")
    && $m->query("CREATE TABLE IF NOT EXISTS wi_case_reports (
        base VARCHAR(40) NOT NULL, id VARCHAR(64) NOT NULL, group_jid VARCHAR(80) NOT NULL DEFAULT '', poster VARCHAR(32) NOT NULL DEFAULT '',
        at DATETIME NULL, phones JSON NULL, name VARCHAR(190) NOT NULL DEFAULT '', manual TINYINT(1) NOT NULL DEFAULT 0, data JSON NOT NULL,
        pos INT NOT NULL DEFAULT 0, PRIMARY KEY (base, id), KEY by_at (base, at), KEY by_pos (base, pos)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci")
    && $m->query("CREATE TABLE IF NOT EXISTS wi_case_events (
        id BIGINT AUTO_INCREMENT PRIMARY KEY, base VARCHAR(40) NOT NULL, phone VARCHAR(32) NOT NULL, at DATETIME NULL, by_user VARCHAR(190) NOT NULL DEFAULT '',
        kind VARCHAR(20) NOT NULL DEFAULT 'note', text TEXT NULL, KEY by_case (base, phone, at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"));
}
/** One line of a case's history (wi_case_events): who changed what (kind: follower, status…). Tests keep it in memory. */
function wi_case_event_add(string $base, string $phone, string $by, string $kind, string $text): void {
    if (PHP_SAPI === 'cli' && isset($GLOBALS['WI_MEM_STORE'])) { $GLOBALS['WI_MEM_STORE']['_case_events'][] = compact('base', 'phone', 'by', 'kind', 'text'); return; }
    $m = wi_db(); if (!$m) return; $at = date('Y-m-d H:i:s');
    $st = $m->prepare("INSERT INTO wi_case_events (base,phone,at,by_user,kind,text) VALUES (?,?,?,?,?,?)"); if ($st) { $st->bind_param('ssssss', $base, $phone, $at, $by, $kind, $text); $st->execute(); }
}
function wi_dt($v): ?string { $t = $v ? strtotime((string) $v) : false; return $t ? date('Y-m-d H:i:s', $t) : null; }
/** cases.json shape: {base: {phone: case}} */
function wi_db_cases_read(): ?array {
    $m = wi_db(); if (!$m) return null; $r = $m->query("SELECT base, phone, data FROM wi_cases"); if (!$r) return null;
    $out = []; $seen = [];
    while ($x = $r->fetch_assoc()) { $out[$x['base']][$x['phone']] = json_decode((string) $x['data'], true) ?: []; $seen[$x['base']][$x['phone']] = md5(json_encode($out[$x['base']][$x['phone']], JSON_UNESCAPED_UNICODE)); }
    $GLOBALS['WI_CASES_SEEN'] = $seen; // what this request read: a save writes only the cases it changed
    return $out;
}
function wi_db_cases_write(array $all): bool {
    $seen = $GLOBALS['WI_CASES_SEEN'] ?? null; // null: nothing read first — every case is written (the old way)
    $m = wi_db(); if (!$m) return false; $m->begin_transaction();
    try {
        $st = $m->prepare("INSERT INTO wi_cases (base,phone,name,status,follower,treatment_group,created_by,created_at,last_at,data) VALUES (?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE name=VALUES(name), status=VALUES(status), follower=VALUES(follower), treatment_group=VALUES(treatment_group), last_at=VALUES(last_at), data=VALUES(data)");
        $ev = $m->prepare("INSERT INTO wi_case_events (base,phone,at,by_user,kind,text) VALUES (?,?,?,?,?,?)");
        foreach ($all as $base => $cases) {
            $base = (string) $base; $keep = [];
            foreach ((array) $cases as $ph => $c) {
                $ph = (string) $ph; $keep[] = $ph;
                $h = md5(json_encode($c, JSON_UNESCAPED_UNICODE)); if ($seen !== null && ($seen[$base][$ph] ?? '') === $h) continue; // unchanged: not rewritten (and a change another request made to it stays)
                $nm = (string) ($c['name'] ?? ''); $stt = (string) ($c['status'] ?? 'new'); $fo = (string) ($c['data']['follower'] ?? ''); $tg = (string) ($c['data']['tgroup'] ?? '');
                $cb = (string) ($c['by'] ?? ''); $ca = wi_dt($c['created'] ?? null); $la = wi_dt($c['last'] ?? ($c['report_at'] ?? null)); $js = json_encode($c, JSON_UNESCAPED_UNICODE);
                $st->bind_param('ssssssssss', $base, $ph, $nm, $stt, $fo, $tg, $cb, $ca, $la, $js); $st->execute();
                // case history: notes become event rows (rewritten for this case, so edits and removals stay in step)
                $d = $m->prepare("DELETE FROM wi_case_events WHERE base=? AND phone=? AND kind='note'"); $d->bind_param('ss', $base, $ph); $d->execute();
                foreach ((array) ($c['notes'] ?? []) as $n) { $at = wi_dt($n['at'] ?? null); $by = (string) ($n['by'] ?? ''); $k = 'note'; $tx = (string) ($n['text'] ?? ''); $ev->bind_param('ssssss', $base, $ph, $at, $by, $k, $tx); $ev->execute(); }
            }
            if ($seen !== null) { // removed: only cases this request read and then dropped (a case another request added meanwhile stays)
                $del = $m->prepare("DELETE FROM wi_cases WHERE base=? AND phone=?"); $dev = $m->prepare("DELETE FROM wi_case_events WHERE base=? AND phone=?");
                foreach (array_diff(array_map('strval', array_keys((array) ($seen[$base] ?? []))), $keep) as $gone) { $del->bind_param('ss', $base, $gone); $del->execute(); $dev->bind_param('ss', $base, $gone); $dev->execute(); }
            } elseif ($keep) { $in = implode(',', array_map(fn($x) => "'" . $m->real_escape_string($x) . "'", $keep)); $b = $m->real_escape_string($base); $m->query("DELETE FROM wi_cases WHERE base='$b' AND phone NOT IN ($in)"); }
            else { $b = $m->real_escape_string($base); $m->query("DELETE FROM wi_cases WHERE base='$b'"); }
        }
        $m->commit();
        $GLOBALS['WI_CASES_SEEN'] = []; foreach ($all as $b => $cs) foreach ((array) $cs as $ph => $c) $GLOBALS['WI_CASES_SEEN'][(string) $b][(string) $ph] = md5(json_encode($c, JSON_UNESCAPED_UNICODE)); // a second save in this request diffs against this one
        return true;
    } catch (Throwable $e) { $m->rollback(); return false; }
}
/** registry.json shape: {base: {id: report}} */
function wi_db_reports_read(): ?array {
    $m = wi_db(); if (!$m) return null; $r = $m->query("SELECT base, id, data FROM wi_case_reports ORDER BY base, pos"); if (!$r) return null;
    $out = []; while ($x = $r->fetch_assoc()) $out[$x['base']][$x['id']] = json_decode((string) $x['data'], true) ?: [];
    return $out;
}
function wi_db_reports_write(array $all): bool {
    $m = wi_db(); if (!$m) return false; $m->begin_transaction();
    try {
        $st = $m->prepare("INSERT INTO wi_case_reports (base,id,group_jid,poster,at,phones,name,manual,data,pos) VALUES (?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE pos=VALUES(pos), group_jid=VALUES(group_jid), poster=VALUES(poster), at=VALUES(at), phones=VALUES(phones), name=VALUES(name), manual=VALUES(manual), data=VALUES(data)");
        foreach ($all as $base => $reps) {
            $base = (string) $base; $keep = []; $pos = 0;
            foreach ((array) $reps as $id => $e) {
                $id = (string) $id; $keep[] = $id; $pos++;
                $g = (string) ($e['group'] ?? ''); $p = (string) ($e['poster'] ?? ''); $at = wi_dt($e['time'] ?? null); $ph = json_encode(array_values((array) ($e['phones'] ?? [])));
                $nm = (string) (($e['ai']['name'] ?? '') ?: ($e['name_guess'] ?? '')); $mn = !empty($e['manual']) ? 1 : 0; $js = json_encode($e, JSON_UNESCAPED_UNICODE);
                $st->bind_param('sssssssisi', $base, $id, $g, $p, $at, $ph, $nm, $mn, $js, $pos); $st->execute();
            }
            $b = $m->real_escape_string($base);
            if ($keep) { $in = implode(',', array_map(fn($x) => "'" . $m->real_escape_string($x) . "'", $keep)); $m->query("DELETE FROM wi_case_reports WHERE base='$b' AND id NOT IN ($in)"); }
            else $m->query("DELETE FROM wi_case_reports WHERE base='$b'");
        }
        $m->commit(); return true;
    } catch (Throwable $e) { $m->rollback(); return false; }
}

/* ---------- Phase 3: task queue (slow work runs in its own background worker, one task of a kind at a time) ---------- */
function wi_db_install_jobs(): bool {
    $m = wi_db(); if (!$m) return false;
    return (bool) $m->query("CREATE TABLE IF NOT EXISTS wi_jobs (
        id BIGINT AUTO_INCREMENT PRIMARY KEY, kind VARCHAR(40) NOT NULL, jkey VARCHAR(80) NOT NULL DEFAULT '', payload JSON NULL,
        status ENUM('queued','running','done','failed') NOT NULL DEFAULT 'queued', run_at DATETIME NOT NULL, started_at DATETIME NULL, finished_at DATETIME NULL,
        result JSON NULL, by_user VARCHAR(190) NOT NULL DEFAULT '', KEY due (status, run_at), KEY kind (kind, jkey, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
/** Adds a task unless the same kind+key is already waiting or running. */
function wi_job_add(string $kind, string $key = '', array $payload = [], string $by = ''): ?int {
    $m = wi_db(); if (!$m) return null;
    $st = $m->prepare("SELECT id FROM wi_jobs WHERE kind=? AND jkey=? AND status IN ('queued','running') LIMIT 1"); $st->bind_param('ss', $kind, $key); $st->execute();
    if ($x = $st->get_result()->fetch_assoc()) return (int) $x['id'];
    $js = json_encode((object) $payload, JSON_UNESCAPED_UNICODE);
    // run_at uses the database clock (NOW()), like every comparison in the queue — PHP's time zone (Cairo) differs from the server's.
    $st = $m->prepare("INSERT INTO wi_jobs (kind,jkey,payload,run_at,by_user) VALUES (?,?,?,NOW(),?)"); $st->bind_param('ssss', $kind, $key, $js, $by); $st->execute();
    return (int) $m->insert_id;
}
function wi_job_take(): ?array {
    $m = wi_db(); if (!$m) return null;
    $m->query("UPDATE wi_jobs SET status='failed', finished_at=NOW(), result=JSON_OBJECT('error','stopped (took over 2 hours)') WHERE status='running' AND started_at < NOW() - INTERVAL 2 HOUR");
    $m->query("DELETE FROM wi_jobs WHERE status IN ('done','failed') AND finished_at < NOW() - INTERVAL 14 DAY");
    $r = $m->query("SELECT * FROM wi_jobs WHERE status='queued' AND run_at <= NOW() AND kind NOT IN (SELECT kind FROM (SELECT kind FROM wi_jobs WHERE status='running') x) ORDER BY id LIMIT 1");
    $j = $r ? $r->fetch_assoc() : null; if (!$j) return null;
    $id = (int) $j['id']; $m->query("UPDATE wi_jobs SET status='running', started_at=NOW() WHERE id=$id AND status='queued'");
    if ($m->affected_rows !== 1) return null;
    $j['payload'] = json_decode((string) $j['payload'], true) ?: []; return $j;
}
function wi_job_finish(int $id, bool $ok, array $result): void {
    $m = wi_db(true); if (!$m) return; $s = $ok ? 'done' : 'failed'; $js = (string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    $st = $m->prepare("UPDATE wi_jobs SET status=?, finished_at=NOW(), result=? WHERE id=?"); if (!$st) return; $st->bind_param('ssi', $s, $js, $id); $st->execute();
}
function wi_jobs_recent(string $kind, int $n = 10): array {
    $m = wi_db(); if (!$m) return []; $st = $m->prepare("SELECT id,kind,jkey,status,run_at,started_at,finished_at,result,by_user FROM wi_jobs WHERE kind=? ORDER BY id DESC LIMIT ?");
    $st->bind_param('si', $kind, $n); $st->execute(); $r = $st->get_result(); $out = [];
    // Times leave as ISO 8601 with the database's own offset, so the page shows them in the viewer's local time correctly.
    $off = (string) ($m->query("SELECT TIME_FORMAT(TIMEDIFF(NOW(), UTC_TIMESTAMP()), '%H:%i')")->fetch_row()[0] ?? '00:00'); $off = ($off[0] === '-' ? '' : '+') . $off;
    while ($x = $r->fetch_assoc()) { $x['result'] = json_decode((string) $x['result'], true); foreach (['run_at', 'started_at', 'finished_at'] as $k) if (!empty($x[$k])) $x[$k] = str_replace(' ', 'T', $x[$k]) . $off; $out[] = $x; } return $out;
}

