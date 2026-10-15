<?php
declare(strict_types=1);
// Every minute (CLI): scheduled messages & statuses, treatment programs, health alerts,
// case sync (every 10 min), daily report, Google Drive backup.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/lib.php';
require __DIR__ . '/features.php';
@date_default_timezone_set((string) wi_sys('timezone') ?: 'UTC');
$lock = fopen(WI_DIR . '/features.lock', 'ce');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit;
$accounts = array_filter(wi_accounts(), fn($a) => !wi_account_blocked($a)); // numbers of ended / paused client panels do no automatic work
$admins = array_values(array_filter(wi_users(), fn($u) => in_array($u['role'] ?? '', ['super', 'admin'], true) && !empty($u['active']) && !empty($u['phone'])));
// After a fresh install, the password-only first sign-in stops as soon as a number is connected.
$instF = WI_DIR . '/installed.json'; $inst = json_decode((string) @file_get_contents($instF), true);
if (is_array($inst) && empty($inst['first_number_at'])) { foreach (wi_accounts() as $ia) { $ih = wi_gateway('/health', null, $ia); if (!empty($ih['connected'])) { $inst['first_number_at'] = date('c'); file_put_contents($instF, json_encode($inst)); break; } } }
$tellAdmins = function (string $msg) use ($admins): void { foreach ($admins as $u) wi_send_whatsapp((string) $u['phone'], $msg); };

/* ---- 1) Scheduled messages / statuses: at most 3 per account per minute ---- */
$S = wi_read('scheduled.json', []); $changed = false; $perAcc = [];
foreach ($S as &$s) {
    if (($s['state'] ?? '') !== 'pending' || strtotime((string) $s['at']) > time()) continue;
    $acc = $accounts[$s['acc']] ?? null;
    if (!$acc || empty($acc['enabled'])) continue;
    if (($perAcc[$s['acc']] = ($perAcc[$s['acc']] ?? 0) + 1) > 3) continue;
    if (strtotime((string) $s['at']) < time() - 6 * 3600) { $s['state'] = 'expired'; $changed = true; continue; } // gateway was down too long
    if ($s['jid'] === 'status@broadcast') {
        $ch = wi_gateway('/archive/chats?months=' . (($s['audience'] ?? '') === 'all' ? 24 : 3), null, $acc); $cust = wi_customers(); $aud = [];
        foreach ($ch['chats'] ?? [] as $c) { $p = (string) ($c['phone'] ?? ''); if ($p === '' || !empty($c['group'])) continue; if (($s['audience'] ?? '') === 'customers' && !isset($cust[$p])) continue; $aud[] = $p; }
        $r = wi_gateway('/status/post', ['text' => $s['text'], 'attachment' => ($s['attachment'] ?? '') ?: null, 'audience' => array_slice(array_unique($aud), 0, 500), 'by' => $s['by']], $acc);
    } else {
        $r = wi_gateway('/chat/send', ['jid' => $s['jid'], 'text' => $s['text'], 'by' => $s['by'], 'via' => 'scheduled'], $acc);
    }
    $s['tries'] = ($s['tries'] ?? 0) + 1; $changed = true;
    if (!empty($r['ok'])) { $s['state'] = 'sent'; $s['sent_at'] = date('c'); }
    elseif ($s['tries'] >= 3) { $s['state'] = 'failed'; $s['error'] = (string) ($r['error'] ?? ''); }
}
unset($s);
if ($changed) wi_write('scheduled.json', $S);

/* ---- 2) Treatment programs: today's step, inside its hour, one per account per minute ---- */
$E = wi_read('enrollments.json', []); $P = wi_read('programs.json', []); $changedE = false;
foreach ($E as $aid => &$list) {
    $acc = $accounts[$aid] ?? null; if (!$acc || empty($acc['enabled'])) continue;
    $progs = []; foreach ($P[$aid] ?? [] as $p) $progs[$p['id']] = $p;
    $sentNow = 0;
    foreach ($list as &$e) {
        if (($e['status'] ?? '') !== 'active' || !isset($progs[$e['program_id']])) continue;
        $prog = $progs[$e['program_id']];
        $day = (int) floor((strtotime('today') - strtotime((string) $e['start'])) / 86400) + 1;
        $last = (int) end($prog['days'])['day'];
        if ($day > $last) { $e['status'] = 'done'; $e['done_at'] = date('c'); $changedE = true; continue; }
        if ((int) date('G') !== (int) $e['hour'] || $sentNow >= 1) continue;
        foreach ($prog['days'] as $d) {
            if ((int) $d['day'] !== $day || isset($e['sent'][(string) $day])) continue;
            $r = wi_gateway('/chat/send', ['jid' => $e['jid'], 'text' => $d['text'], 'by' => 'program:' . $prog['name'], 'via' => 'program'], $acc);
            if (!empty($r['ok'])) { $e['sent'][(string) $day] = date('c'); $sentNow++; } else $e['last_error'] = (string) ($r['error'] ?? '');
            $changedE = true;
        }
    }
    unset($e);
}
unset($list);
// Adherence: did they write back after a step was sent? (checked hourly)
if ((int) date('i') === 30) foreach ($E as $aid => &$list) {
    $acc = $accounts[$aid] ?? null; if (!$acc) continue;
    foreach ($list as &$e) {
        if (empty($e['sent']) || ($e['status'] ?? '') === 'stopped') continue;
        $t = wi_gateway('/archive/thread?jid=' . rawurlencode($e['jid']), null, $acc); $n = 0;
        foreach ($e['sent'] as $at) foreach ($t['rows'] ?? [] as $m) if ($m['dir'] === 'in' && ($mt = strtotime((string) $m['time'])) > strtotime($at) && $mt < strtotime($at) + 86400) { $n++; break; } // compare moments, not text in two time zones
        if ($n !== ($e['replies'] ?? 0)) { $e['replies'] = $n; $changedE = true; }
    }
    unset($e);
}
unset($list);
if ($changedE) wi_write('enrollments.json', $E);

/* ---- 2a) Keep the bot's library copy warm in server memory (every 5 minutes) ---- */
if ((int) date('i') % 5 === 0) foreach ($accounts as $acc) {
    if (wf_bot_run_cfg((string) $acc['id'])['mode'] === 'off') continue;
    $cf = WI_DIR . '/cache_lib_' . preg_replace('/\W/', '', (string) $acc['id']) . '.json';
    $lib = wi_gateway('/library?' . http_build_query(['type' => 'sections', 'group' => '1', 'min' => 60]), null, $acc);
    if (!empty($lib['ok'])) { file_put_contents($cf, json_encode(['groups' => array_values(array_filter($lib['groups'], fn($g) => empty($g['audio'])))], JSON_UNESCAPED_UNICODE)); @chmod($cf, 0600); }
}

/* ---- 2a2) Answer cache: filled automatically from approved knowledge, once an hour ---- */
if ((int) date('i') === 17) foreach ($accounts as $acc) { try { if (wf_bot_run_cfg((string) $acc['id'])['mode'] !== 'off' && wf_learn_cfg((string) $acc['id'])['cache']) wf_cache_seed_kb((string) $acc['id']); } catch (Throwable $e) {} }

/* ---- 2a3) Learning: linked Google Drive folders are pulled once an hour (in the background worker) ---- */
if ((int) date('i') === 41) foreach ($accounts as $acc) { try { $gb = (string) $acc['id']; $G = wf_grow_cfg($gb); $hasC = wi_db() && wi_db()->query("SELECT 1 FROM wi_concepts WHERE base = '" . wi_db()->real_escape_string($gb) . "' LIMIT 1")->fetch_row();
    if ($hasC && $G['auto'] && (int) ($cz = new DateTime('now', new DateTimeZone(date_default_timezone_get())))->format('G') === (int) $G['hour'] && (($G['last']['at'] ?? '') === '' || (new DateTime($G['last']['at']))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d') !== $cz->format('Y-m-d'))) wi_job_add('guide_grow', $gb, ['base' => $gb, 'by' => 'schedule'], 'schedule'); } catch (Throwable $e) {} }
if ((int) date('i') === 23) foreach (wi_read('learning.json', []) as $lb => $lc) if (!empty($lc['drive_folder']['id'])) wi_job_add('learn_sync', (string) $lb, ['base' => (string) $lb], 'schedule');

/* ---- 2b) Bot: answers new private messages (drafts or automatic, per number) ---- */
foreach ($accounts as $acc) { try { if (wf_bot_run_cfg((string) $acc['id'])['mode'] !== 'off') wf_bot_tick($acc); } catch (Throwable $e) {} }

/* ---- 2c) Server resources: memory, processes and threads of this account (read from /proc), against the plan's limits ---- */
try {
    $uid = getmyuid(); $mem = 0; $procs = 0; $threads = 0; $top = [];
    foreach (glob('/proc/[0-9]*/status') ?: [] as $sf) {
        $st = @file_get_contents($sf); if ($st === false || !preg_match('/^Uid:\s+(\d+)/m', $st, $u) || (int) $u[1] !== $uid) continue;
        preg_match('/^VmRSS:\s+(\d+)/m', $st, $r); preg_match('/^Threads:\s+(\d+)/m', $st, $t); preg_match('/^Name:\s+(\S+)/m', $st, $n);
        $kb = (int) ($r[1] ?? 0); $mem += $kb; $procs++; $threads += (int) ($t[1] ?? 1);
        $nm = $n[1] ?? '?'; $cl = (string) @file_get_contents(dirname($sf) . '/cmdline');
        $nm = str_contains($cl, 'server.js') ? 'WhatsApp gateways' : (preg_match('/^\d+\.\d+\.\d+/', $nm) ? 'Maintenance session' : ($nm === 'php' || str_contains($cl, 'php') ? 'PHP (dashboard and jobs)' : $nm));
        $top[$nm] = ($top[$nm] ?? 0) + $kb;
    }
    arsort($top);
    $lim = (wi_config()['limits'] ?? []) + ['pmem_mb' => 4096, 'nproc' => 250];
    $res = ['at' => date('c'), 'mem_mb' => round($mem / 1024), 'procs' => $procs, 'threads' => $threads, 'top' => array_map(fn($k) => round($k / 1024), array_slice($top, 0, 5, true)), 'limits' => $lim];
    $prev = wi_read('resources.json', []); $res['history'] = array_slice(array_merge($prev['history'] ?? [], [[date('H:i'), $res['mem_mb'], $res['threads']]]), -180);
    if ((int) date('i') === 0 || empty($prev['disk'])) { // folder sizes once an hour
        $sz = function ($d) { $b = 0; if (!is_dir($d)) return 0; foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY, RecursiveIteratorIterator::CATCH_GET_CHILD) as $f) if ($f->isFile()) $b += $f->getSize(); return round($b / 1048576); };
        $R = dirname(__DIR__); $res['disk'] = ['inbox_data' => $sz(WI_DIR), 'archives' => $sz($R . '/wa-local/archive') + $sz($R . '/wa-local/accounts'), 'media' => $sz($R . '/storage')];
    } else $res['disk'] = $prev['disk'];
    // Warn the super admin once when memory or threads pass 85% of the plan.
    $hot = $res['mem_mb'] > 0.85 * $lim['pmem_mb'] || $res['threads'] > 0.85 * $lim['nproc'];
    if ($hot && empty($prev['alerted'])) { $tellAdmins(wf_msg('alert_resources', ['mem' => $res['mem_mb'], 'mem_limit' => $lim['pmem_mb'], 'threads' => $res['threads'], 'threads_limit' => $lim['nproc']])); $res['alerted'] = date('c'); }
    elseif ($hot) $res['alerted'] = $prev['alerted'];
    wi_write('resources.json', $res);
} catch (Throwable $e) {}

/* ---- 3) Number health alerts (to admins' own WhatsApp, once per incident) ---- */
$HA = wi_read('health_alerts.json', []); $changedH = false;
foreach ($accounts as $id => $acc) {
    if (empty($acc['enabled'])) continue;
    $h = wi_gateway('/health', null, $acc);
    $down = empty($h['ok']) || empty($h['connected']);
    $since = !empty($h['disconnected_since']) ? strtotime($h['disconnected_since']) : (empty($h['ok']) ? ($HA[$id]['first_down'] ?? time()) : null);
    $st = $HA[$id] ?? ['state' => 'up'];
    if ($down) {
        if (empty($st['first_down'])) { $st['first_down'] = $since ?: time(); $changedH = true; }
        if ($st['state'] === 'up' && time() - (int) $st['first_down'] > 600) {
            $why = !empty($h['logged_out']) ? wf_msg('reason_logged_out') : (empty($h['ok']) ? wf_msg('reason_gateway_down') : wf_msg('reason_disconnected', ['error' => (string) ($h['last_error'] ?? '')]));
            if ($id !== 'main' || !empty($accounts['main']['enabled'])) wi_notify_account($acc, wf_msg('alert_down', ['number' => $acc['name'], 'reason' => $why, 'link' => wi_sys('base_url') . '?p=health']));
            $st['state'] = 'alerted'; $st['alerted_at'] = date('c'); $changedH = true;
        }
    } elseif ($st['state'] !== 'up' || !empty($st['first_down'])) {
        if ($st['state'] === 'alerted') wi_notify_account($acc, wf_msg('alert_back', ['number' => $acc['name']]));
        $st = ['state' => 'up', 'back_at' => date('c')]; $changedH = true;
    }
    if (!empty($h['ok']) && ($h['day']['send_failed'] ?? 0) >= 10 && empty($st['fail_alert']) ) { wi_notify_account($acc, wf_msg('alert_send_failed', ['number' => $acc['name'], 'count' => $h['day']['send_failed']])); $st['fail_alert'] = date('c'); $changedH = true; }
    if (!empty($st['fail_alert']) && strtotime($st['fail_alert']) < time() - 86400) { unset($st['fail_alert']); $changedH = true; }
    $HA[$id] = $st;
}
if ($changedH) wi_write('health_alerts.json', $HA);

/* ---- 4) Cases: sync monitored groups every 10 minutes ---- */
if ((int) date('i') % 10 === 5) foreach ($accounts as $acc) {
    if (empty($acc['enabled'])) continue;
    if (wf_registry_groups((string) $acc['id']) || wf_case_groups((string) $acc['id']) || wf_cases((string) $acc['id'])) wi_job_add('cases_sync', (string) $acc['id']); // runs in the background worker
}

/* ---- 4b) Clients' own backups to their own Google Drive (daily at the hour they chose) ---- */
foreach (wi_users() as $cu) if (!empty($cu['active']) && wi_can_self_backup($cu) && (($cu['role'] ?? '') !== 'client' || in_array(wi_client_state($cu)['state'], ['active', 'expiring'], true))) {
    $cws = strtolower((string) $cu['email']); $CB = wf_cb_cfg($cws);
    if (!empty($CB['enabled']) && (int) date('G') === (int) $CB['hour'] && ($CB['queued_date'] ?? '') !== date('Y-m-d')) { $CB['queued_date'] = date('Y-m-d'); wf_cb_save($cws, $CB); wi_job_add('client_backup', $cws, ['ws' => $cws], 'schedule'); }
}

/* ---- 4b2) Client numbers get their own gateway rules once (sales library, no healing tags) ---- */
foreach (wi_accounts() as $ra) try { wf_client_rules_ensure($ra); } catch (Throwable $e) {}

/* ---- 4c) Clients: usage counters (hourly) and reminders before a subscription ends ---- */
try { wf_intake_due($accounts); } catch (Throwable $e) {}
try { wf_gate_due($accounts); } catch (Throwable $e) {} // reception groups: reminder, last notice, removal
try { wf_cases_number_backfill(); } catch (Throwable $e) {} // older cases get their ID // cases from a group whose writer did not answer in time
try { if ((int) date('i') === 7 || !wi_read('usage.json', [])) wf_usage_refresh(); if ((int) date('i') === 30) wf_client_reminders($tellAdmins); } catch (Throwable $e) {}

/* ---- 5) Daily report ---- */
$R = wi_read('report.json', []);
if (!empty($R['enabled']) && (int) date('G') === (int) ($R['hour'] ?? 21) && ($R['last_date'] ?? '') !== date('Y-m-d')) {
    $R['last_date'] = date('Y-m-d'); wi_write('report.json', $R);
    // each person gets the report of the numbers they may see (owners: their own number only)
    foreach (wi_users() as $ru) { if (empty($ru['active']) || empty($ru['phone'])) continue; $mine = array_filter($accounts, fn($a) => !empty($a['enabled']) && wi_may_see_account($a, $ru));
        if (!$mine || (!in_array($ru['role'] ?? '', ['super', 'admin'], true) && !array_filter($mine, fn($a) => wi_private_owner($a) === strtolower((string) $ru['email'])))) continue;
        wi_send_whatsapp((string) $ru['phone'], wf_report_text($ru)); }
}

/* ---- 6) Google Drive backup: queued for the background worker (daily at its hour, or "Back up now") ---- */
$D = wf_drive_cfg();
if (!empty($D['refresh_token']) && !empty($D['enabled']) && (int) date('G') === (int) ($D['hour'] ?? 3) && ($D['queued_date'] ?? '') !== date('Y-m-d')) {
    $D['queued_date'] = date('Y-m-d'); wi_write('drive.json', $D);
    wi_job_add('backup', 'drive', ['types' => $D['types'] ?? ['data', 'messages']], 'schedule');
}
