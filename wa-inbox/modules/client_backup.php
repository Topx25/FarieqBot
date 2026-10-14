<?php
// WhatsApp Inbox — a client's own backup: the chats and data of the client's numbers, packed into one file and uploaded
// to the client's own Google Drive (My account → Google account). Nothing of other clients or of the operator goes in.

/** Stores kept per number ($X[$aid]) and flat lists with an "acc" field. Everything else is the operator's. */
const WF_CB_PER_NUMBER = ['bot.json', 'lists.json', 'programs.json', 'enrollments.json', 'team.json', 'case_groups.json', 'registry.json', 'registry_groups.json', 'cases.json',
    'summaries.json', 'lib_ai.json', 'lib_hidden.json', 'audio_meta.json', 'learning.json', 'learn_kb.json', 'learn_meta.json', 'bot_log.json', 'bot_drafts.json', 'bot_ratings.json',
    'bot_paused.json', 'bot_intro.json', 'notes.json', 'assign.json', 'chat_meta.json', 'case_cfg.json', 'known_members.json', 'health_alerts.json'];
const WF_CB_FLAT = ['scheduled.json' => 'acc', 'reminders.json' => 'acc', 'meetings.json' => 'acc'];

function wf_cb_cfg(string $ws): array { $c = (array) (wi_read('drive_ws.json', [])[$ws]['backup'] ?? []); return $c + ['enabled' => false, 'hour' => 3, 'media' => false, 'keep' => 7, 'files' => []]; }
function wf_cb_save(string $ws, array $b): void { wi_update('drive_ws.json', function ($A) use ($ws, $b) { $A[$ws]['backup'] = $b; return $A; }); }
/** Where a number's chat archive lives on the server. */
function wf_cb_archive_dir(string $aid): string { $wl = dirname(__DIR__, 2) . '/wa-local'; return $aid === 'main' ? $wl . '/archive' : $wl . '/accounts/' . $aid . '/archive'; }

/** Builds the package for one client and uploads it to their Drive. */
function wf_client_backup_run(string $ws, bool $dry = false): array { // $dry: build the package only (no Google), for checks
    $ws = strtolower($ws); $client = wi_find_user($ws);
    if (!$client || (!wi_can_self_backup($client) && !$dry)) return ['ok' => false, 'error' => 'Backup is not part of this plan.'];
    wf_google_use($ws);
    try {
        $t = $dry ? ['ok' => true] : wf_drive_token(); if (empty($t['ok'])) return ['ok' => false, 'error' => $t['error'] ?? 'Google is not connected.'];
        $accs = wi_owned_accounts($ws); if (!$accs) return ['ok' => false, 'error' => 'No WhatsApp number is linked yet.'];
        $B = wf_cb_cfg($ws); $tmp = sys_get_temp_dir() . '/wi-cb-' . bin2hex(random_bytes(5)); @mkdir($tmp . '/data', 0700, true); @mkdir($tmp . '/chats', 0700); @mkdir($tmp . '/media', 0700);
        $data = ['made_at' => date('c'), 'numbers' => []];
        foreach ($accs as $aid => $a) {
            $x = ['name' => $a['name'] ?? $aid];
            foreach (WF_CB_PER_NUMBER as $s) { $v = wi_read($s, [])[$aid] ?? null; if ($v !== null) $x[basename($s, '.json')] = $v; }
            foreach (WF_CB_FLAT as $s => $k) $x[basename($s, '.json')] = array_values(array_filter((array) wi_read($s, []), fn($r) => is_array($r) && ($r[$k] ?? '') === $aid));
            if ($m = wi_db()) { $st = $m->prepare("SELECT * FROM wi_learn_docs WHERE base = ?"); if ($st) { $st->bind_param('s', $aid); $st->execute(); $x['learn_docs'] = $st->get_result()->fetch_all(MYSQLI_ASSOC); } }
            $data['numbers'][$aid] = $x;
            if (is_dir($d = wf_cb_archive_dir($aid))) @symlink($d, $tmp . '/chats/' . $aid);
            if (!empty($B['media']) && is_dir($d = dirname(__DIR__, 2) . '/storage/inbox_media/' . $aid)) @symlink($d, $tmp . '/media/' . $aid);
        }
        // the panel's own lists and team (never passwords)
        $data['panel'] = ['quick_replies' => wi_read('replies_ws.json', [])[$ws] ?? [], 'labels' => (wi_read('labels.json', [])['ws'][$ws] ?? []), 'team_roles' => wi_read('team_roles.json', [])[$ws] ?? null,
            'team' => array_map(fn($u) => array_diff_key($u, ['password_hash' => 1]), wi_team_of($ws))];
        file_put_contents($tmp . '/data/panel.json', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE));
        file_put_contents($tmp . '/README.txt', "Backup of " . (string) wi_sys('brand_name') . " — " . date('Y-m-d H:i') . "\nchats/<number>/YYYY-MM.jsonl : every message, one JSON per line\ndata/panel.json : settings, cases, notes, bot, learning and team of your numbers\nmedia/ : photos and recordings (when included)\n");
        $file = $tmp . '.tar.gz'; $r = wf_snap_run_cmd(['tar', '-czhf', $file, '-C', $tmp, '.']);
        if (!$r['ok'] && (!is_file($file) || !filesize($file))) return ['ok' => false, 'error' => 'Packing failed: ' . $r['error']];
        if ($dry) { wf_snap_run_cmd(['rm', '-rf', $tmp]); return ['ok' => true, 'file' => $file, 'size' => (int) filesize($file)]; }
        $name = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) wi_sys('brand_name')) . '-backup-' . date('Y-m-d_Hi') . '.tar.gz';
        $u = wf_drive_put($t['token'], $file, $name, wf_drive_folder($t['token']), null); $size = (int) filesize($file);
        @unlink($file); wf_snap_run_cmd(['rm', '-rf', $tmp]);
        $B = wf_cb_cfg($ws); $B['last'] = ['at' => date('c'), 'ok' => !empty($u['ok']), 'size' => $size, 'name' => $name, 'error' => $u['error'] ?? ''];
        if (!empty($u['ok'])) { array_unshift($B['files'], ['id' => $u['id'], 'name' => $name, 'at' => date('c'), 'size' => $size]);
            // keep the last N on Drive
            while (count($B['files']) > max(1, (int) $B['keep'])) { $old = array_pop($B['files']); $t2 = wf_drive_token(); if (!empty($t2['ok'])) wf_drive_req('DELETE', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode((string) $old['id']), $t2['token']); } }
        wf_cb_save($ws, $B);
        return ['ok' => !empty($u['ok']), 'error' => $u['error'] ?? '', 'size' => $size, 'name' => $name];
    } finally { wf_google_use(null); }
}

function wf_api_client_backup(string $api): void {
    if ($api !== 'client_backup') return;
    $me = wi_me(); if (!wi_can_self_backup($me)) wi_json(['ok' => false, 'error' => 'Backup is not part of your plan.'], 403);
    $ws = strtolower((string) $me['email']); $B = wf_cb_cfg($ws);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') { $in = wf_post();
        if (($in['do'] ?? '') === 'settings') { $B['enabled'] = !empty($in['enabled']); $B['hour'] = max(0, min(23, (int) ($in['hour'] ?? 3))); $B['media'] = !empty($in['media']); $B['keep'] = max(1, min(30, (int) ($in['keep'] ?? 7))); wf_cb_save($ws, $B); wi_log('client_backup_cfg', []); }
        if (($in['do'] ?? '') === 'run') { wi_job_add('client_backup', $ws, ['ws' => $ws], (string) $_SESSION['email']); $B['queued'] = date('c'); wf_cb_save($ws, $B); wi_log('client_backup_run', []); }
    }
    $job = null; if ($m = wi_db()) { $st = $m->prepare("SELECT status FROM wi_jobs WHERE kind='client_backup' AND jkey=? AND status IN ('queued','running') LIMIT 1"); $st->bind_param('s', $ws); $st->execute(); $job = $st->get_result()->fetch_assoc()['status'] ?? null; }
    wi_json(['ok' => true, 'cfg' => array_diff_key($B, ['files' => 1]), 'files' => $B['files'], 'running' => $job]);
}
