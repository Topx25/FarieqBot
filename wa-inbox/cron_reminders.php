<?php
declare(strict_types=1);
// Follow-up reminders: at their time, each user gets a WhatsApp message (cron, every minute, CLI only).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/lib.php';
require __DIR__ . '/features.php';
@date_default_timezone_set((string) wi_sys('timezone') ?: 'UTC'); // the reminder hour is local time
$lock = fopen(WI_DIR . '/reminders.lock', 'ce');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit;
$R = wi_read('reminders.json', []);
$accounts = wi_accounts();
$changed = false;
foreach ($R as &$r) {
    if (!empty($r['done']) || !empty($r['notified']) || strtotime((string) $r['at']) > time()) continue;
    $u = null; foreach (wi_users() as $x) if (strtolower($x['email']) === strtolower((string) $r['by']) && !empty($x['active'])) $u = $x;
    $r['notified'] = date('c'); $changed = true;
    if (!$u || empty($u['phone'])) continue;
    $acc = $accounts[$r['acc']] ?? null;
    $msg = wf_msg('followup_reminder', ['name' => $r['name'] ?: explode('@', (string) $r['jid'])[0], 'number' => $acc && empty($acc['main']) ? ' · ' . $acc['name'] : '', 'note' => $r['note'] !== '' ? "\n" . $r['note'] : '', 'link' => wi_sys('base_url') . '?p=inbox#' . rawurlencode((string) $r['jid'])]);
    wi_gateway('/send', ['to' => $u['phone'], 'message' => $msg, 'via' => 'reminder'], 'main');
}
unset($r);
if ($changed) wi_write('reminders.json', $R);

/* ---------- Overdue invoice reminders, chosen per number (Inbox → chat → 🧾) ----------
 * Safety: only numbers with an existing chat, inside the chosen hour (09–21), at most one reminder
 * per run per WhatsApp account, each number at most every N days and a capped number of times. */
$IR = wi_read('inv_reminders.json', []);
$methods = '';
try { if (!defined('CONFIG_MYSQL_HOST')) @include_once dirname(__DIR__) . '/config.php'; } catch (Throwable $e) {}
$changedIR = false;
foreach ($IR as $aid => $list) {
    $acc = $accounts[$aid] ?? null;
    if (!$acc || empty($acc['enabled']) || empty($acc['payments'])) continue; // invoices switched off for this number
    foreach ($list as $jid => &$cfg) {
        if (empty($cfg['enabled']) || empty($cfg['biller_id'])) continue;
        if ((int) date('G') !== (int) $cfg['hour']) continue;
        if (!empty($cfg['last_sent']) && time() - strtotime($cfg['last_sent']) < $cfg['every_days'] * 86400 - 3600) continue;
        if ((int) ($cfg['sent_count'] ?? 0) >= (int) $cfg['max_times']) continue;
        $over = array_values(array_filter(wi_customer_invoices((int) $cfg['biller_id']), 'wi_is_overdue'));
        if (!$over) { $cfg['sent_count'] = 0; continue; } // paid up: start again next time
        $due = array_sum(array_map(fn($i) => (float) $i['total_due'], $over));
        $first = end($over);
        $msg = strtr((string) $cfg['message'], ['{name}' => (string) $cfg['name'], '{invoice}' => $first['reference'], '{due}' => number_format($due) . ' ' . ($first['currency'] ?: ((string) wi_sys('currency') ?: '')),
            '{count}' => (string) count($over), '{date}' => date('d/m/Y'), '{methods}' => wf_msg('payment_methods')]);
        $body = ['jid' => $jid, 'text' => $msg, 'by' => 'overdue-reminder'];
        if (!empty($cfg['with_image'])) { $img = wi_bridge(['action' => 'invoice_image', 'invoice_id' => (int) $first['id']], 90); if (!empty($img['ok'])) $body['attachment'] = $img['attachment']; }
        $r = wi_gateway('/chat/send', $body, $acc);
        $cfg['last_sent'] = date('c'); $cfg['sent_count'] = (int) ($cfg['sent_count'] ?? 0) + 1; $cfg['last_result'] = !empty($r['ok']) ? 'sent' : ('failed: ' . ($r['error'] ?? ''));
        $changedIR = true;
        @file_put_contents(WI_DIR . '/audit.jsonl', json_encode(['time' => date('c'), 'action' => 'overdue_reminder', 'account' => $aid, 'jid' => $jid, 'ok' => !empty($r['ok'])]) . "\n", FILE_APPEND);
        break; // one per account per run (anti-ban)
    }
    unset($cfg);
    $IR[$aid] = $list;
}
if ($changedIR) wi_write('inv_reminders.json', $IR);
