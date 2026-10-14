<?php
// Background worker (CLI, started every minute in the background by run_minute.sh): runs queued tasks one after another
// for up to ~55 minutes. Only one worker runs at a time; slow tasks (backup, case sync) never hold up the every-minute jobs.
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/lib.php';
require __DIR__ . '/features.php';
@date_default_timezone_set((string) wi_sys('timezone') ?: 'UTC');
$lock = fopen(WI_DIR . '/worker.lock', 'c'); @chmod(WI_DIR . '/worker.lock', 0600);
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit;
@set_time_limit(3500); $t0 = time();
while (time() - $t0 < 3300 && ($j = wi_job_take())) {
    $ok = false; $res = [];
    try {
        switch ($j['kind']) {
            case 'backup':
                $types = array_values(array_intersect(array_keys(WF_BACKUP_TYPES), (array) ($j['payload']['types'] ?? ['data', 'messages'])));
                $res = wf_backup_incremental($types, max(300, 3300 - (time() - $t0)));
                $ok = !empty($res['ok']);
                $c = wf_drive_cfg(); $c['last'] = ['at' => date('c'), 'ok' => $ok, 'uploaded' => $res['uploaded'] ?? 0, 'unchanged' => $res['unchanged'] ?? 0, 'archived' => $res['archived'] ?? 0, 'bytes' => $res['bytes'] ?? 0, 'types' => $types, 'partial' => !empty($res['partial']), 'error' => $res['error'] ?? implode(' · ', array_slice($res['errors'] ?? [], 0, 3))];
                unset($c['run_now']); wi_write('drive.json', $c);
                if (!empty($res['partial'])) wi_job_add('backup', 'drive', ['types' => $types]); // continue next run
                if (!$ok) { foreach (wi_users() as $u) if (($u['role'] ?? '') === 'super' && !empty($u['active']) && !empty($u['phone'])) wi_send_whatsapp((string) $u['phone'], wf_msg('backup_failed', ['error' => $c['last']['error'] ?: 'unknown'])); }
                break;
            case 'learn_import':
                $pl = $j['payload']; wf_google_use(wi_ws(wi_accounts()[(string) $pl['base']] ?? [])); $res = wf_drive_import_folder((string) $pl['base'], (string) $pl['id'], (string) ($pl['name'] ?? ''), (string) ($pl['by'] ?? ''), !empty($pl['deep'])); $ok = !empty($res['ok']); wf_google_use(null); break;
            case 'guide_grow':
                $res = wf_grow_run((string) $j['payload']['base'], (string) ($j['payload']['by'] ?? 'schedule')); $ok = !empty($res['ok']);
                wi_update('learning.json', function ($L) use ($j) { unset($L[(string) $j['payload']['base']]['grow']['running']); return $L; }); break;
            case 'learn_clean':
                $res = wf_clean_run((string) $j['payload']['base'], (array) ($j['payload']['ids'] ?? []), max(300, 3300 - (time() - $t0))); $ok = !empty($res['ok']);
                // Continue next run only when this run made progress (no endless re-queue).
                if (!empty($res['partial']) && (($res['kept'] ?? 0) + ($res['dropped'] ?? 0)) > 0) wi_job_add('learn_clean', (string) $j['payload']['base'] . '-' . time(), $j['payload']); break;
            case 'doc_verify': // uploaded files checked against the healers' answers in the groups
                $vb = (string) ($j['payload']['base'] ?? $j['jkey']); wi_ctx_set('ai_acc', wi_accounts()[$vb] ?? null);
                $res = wf_doc_verify_run($vb, max(300, 3000 - (time() - $t0))); wi_ctx_set('ai_acc', null); $ok = !empty($res['ok']);
                if ($ok && !empty($res['left']) && !empty($res['checked'])) wi_job_add('doc_verify', $vb . '-' . time(), ['base' => $vb]); break;
            case 'kb_learn': // Bot learning in the background: collect the exchanges (when asked), then distill them 20 by 20 until done or out of time
                $kb = (string) ($j['payload']['base'] ?? $j['jkey']); $ka = wi_accounts()[$kb] ?? null; if (!$ka) { $res = ['ok' => false, 'error' => 'No number.']; break; }
                wi_ctx_set('ai_acc', $ka); $res = ['collected' => null, 'batches' => 0, 'items' => 0]; $fails = 0;
                if (!empty($j['payload']['collect'])) { $c0 = wf_learn_collect($ka, !empty($j['payload']['full'])); $res['collected'] = $c0; if (empty($c0['ok'])) { $ok = false; break; } }
                while (time() - $t0 < 3000) { $d = wf_learn_distill($ka); if (empty($d['ok'])) { if (++$fails >= 6) break; sleep(60 * $fails); continue; } // a busy or limited AI: wait longer each time
                    $fails = 0; $res['batches']++; $res['items'] += (int) ($d['done'] ?? 0); $res['remaining'] = (int) ($d['remaining'] ?? 0); if (empty($d['remaining'])) break; }
                wi_ctx_set('ai_acc', null); $ok = $fails < 6; if (!$ok) $res['error'] = (string) ($d['error'] ?? '');
                $outOfTime = time() - $t0 >= 3000; // this worker run used its time (it may start late, after other jobs): the job carries on in the next run
                if (($res['remaining'] ?? 1) > 0 && ($res['batches'] > 0 || $outOfTime)) wi_job_add('kb_learn', $kb . '-' . time(), ['base' => $kb]); // the rest next run (also after an AI outage, when it made progress)
                break;
            case 'youtube_upload': // a video of the media library → YouTube (the Google account of whoever asked)
                $res = wf_youtube_job((array) $j['payload']); $ok = !empty($res['ok']); break;
            case 'client_backup': // a client's own backup to their own Google Drive
                $res = wf_client_backup_run((string) ($j['payload']['ws'] ?? $j['jkey'])); $ok = !empty($res['ok']); break;
            case 'learn_sync':
                $sb = (string) ($j['payload']['base'] ?? $j['jkey']); wf_google_use(wi_ws(wi_accounts()[$sb] ?? [])); $res = wf_drive_folder_sync($sb); wf_google_use(null); $ok = !empty($res['ok']); break;
            case 'package':
                $res = wf_snapshot_run((string) ($j['payload']['kind'] ?? '')); $ok = !empty($res['ok']);
                if (!$ok) { foreach (wi_users() as $u) if (($u['role'] ?? '') === 'super' && !empty($u['active']) && !empty($u['phone'])) wi_send_whatsapp((string) $u['phone'], wf_msg('package_failed', ['error' => $res['error'] ?? ''])); }
                break;
            case 'restore':
                $res = wf_restore_run($j['payload'], max(300, 3300 - (time() - $t0))); $ok = !empty($res['ok']);
                $c = wf_drive_cfg(); $c['last_restore'] = ['at' => date('c'), 'ok' => $ok] + $res; wi_write('drive.json', $c);
                wi_log('backup_restore_done', ['restored' => $res['restored'] ?? 0, 'source' => $res['source'] ?? '']);
                break;
            case 'cases_sync':
                $acc = wi_accounts()[$j['jkey']] ?? null;
                if ($acc) { if (wf_registry_groups((string) $acc['id'])) wf_registry_scan($acc); $res = wf_cases_sync($acc); }
                $ok = true; break;
            default: $res = ['error' => 'unknown task']; 
        }
    } catch (Throwable $e) { $res = ['error' => $e->getMessage()];
        // A learning job that crashed must not stay "running" in the page forever.
        $lb = (string) ($j['payload']['base'] ?? '');
        if ($lb !== '' && in_array($j['kind'], ['learn_clean', 'guide_grow'], true)) { $L = wi_read('learning.json', []);
            if ($j['kind'] === 'learn_clean') $L[$lb]['clean'] = ['running' => false, 'error' => mb_substr($e->getMessage(), 0, 200), 'at' => date('c')] + (array) ($L[$lb]['clean'] ?? []);
            else unset($L[$lb]['grow']['running']);
            wi_write('learning.json', $L); } }
    wi_job_finish((int) $j['id'], $ok, $res);
}
