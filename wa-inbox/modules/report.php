<?php
// WhatsApp Inbox — report (split out of features.php in v3.8, code unchanged)

/* ---------- Daily report ---------- */
function wf_report_text(?array $viewer = null): string {
    $lines = [wf_msg('report_title')];
    foreach (wi_accounts() as $acc) {
        if (empty($acc['enabled']) || !wi_may_see_account($acc, $viewer ?? wi_me())) continue; // only the numbers this person may see
        $h = wi_gateway('/health', null, $acc);
        $ch = wi_gateway('/archive/chats?months=' . (int) wi_sys('months_short') , null, $acc);
        $day = time() - 86400; $in = 0; $chats = 0; $wait = 0;
        foreach ($ch['chats'] ?? [] as $c) {
            $lt = strtotime((string) ($c['last']['time'] ?? ''));
            if (!$lt || $lt < $day) continue;
            $chats++;
            if (($c['last']['dir'] ?? '') === 'in' && $lt < time() - 1800 && empty($c['group'])) $wait++;
        }
        $cases = wf_cases((string) $acc['id']);
        $newC = count(array_filter($cases, fn($c) => strtotime((string) ($c['created'] ?? '')) > $day));
        $fol = count(array_filter($cases, fn($c) => in_array($c['status'] ?? '', ['follow', 'improving'], true)));
        $sent = 0; foreach (wf_enrollments((string) $acc['id']) as $e) foreach ($e['sent'] ?? [] as $t) if (strtotime($t) > $day) $sent++;
        $lines[] = wf_msg('report_number', ['number' => $acc['name'], 'state' => wf_msg(!empty($h['connected']) ? 'report_online' : 'report_offline')]);
        $lines[] = wf_msg('report_chats', ['chats' => $chats, 'waiting' => $wait]);
        if ($cases) $lines[] = wf_msg('report_cases', ['new' => $newC, 'follow' => $fol]);
        if ($sent) $lines[] = wf_msg('report_programs', ['sent' => $sent]);
        if (!empty($h['day']['disconnects'])) $lines[] = wf_msg('report_disconnects', ['count' => $h['day']['disconnects']]);
    }
    // Overdue invoices (invoice system, read-only).
    $over = 0; $due = 0.0;
    $doneIds = [];
    foreach (wi_customers() as $ph => $c) { if (isset($doneIds[$c['id']])) continue; $doneIds[$c['id']] = 1; foreach (array_filter(wi_customer_invoices((int) $c['id']), 'wi_is_overdue') as $i) { $over++; $due += (float) $i['total_due']; } }
    if ($over) $lines[] = wf_msg('report_invoices', ['count' => $over, 'due' => number_format($due, 2)]);
    $lines[] = "\n" . wi_sys('base_url');
    return implode("\n", $lines);
}


/** API calls of this area (moved unchanged from the old single switch). */
function wf_api_report(string $api): void {
    switch ($api) {
    /* ----- 11) Daily report settings ----- */
    case 'report_cfg': {
        $c = wi_read('report.json', ['enabled' => false, 'hour' => 21]);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $in = wf_post(); wf_admin();
            if (!empty($in['preview'])) wi_json(['ok' => true, 'text' => wf_report_text()]);
            if (!empty($in['send_now'])) { $txt = wf_report_text(wi_me()); $n = 0; foreach (wi_users() as $u) if (($u['role'] ?? '') === 'super' && !empty($u['active']) && !empty($u['phone'])) { if (wi_send_whatsapp((string) $u['phone'], $txt)) $n++; } wi_json(['ok' => $n > 0, 'sent' => $n, 'error' => $n ? '' : 'No admin has a WhatsApp number in Users.']); }
            $c = ['enabled' => !empty($in['enabled']), 'hour' => max(0, min(23, (int) ($in['hour'] ?? 21))), 'last_date' => $c['last_date'] ?? null];
            wi_write('report.json', $c);
        }
        $to = array_values(array_map(fn($u) => $u['name'] ?? $u['email'], array_filter(wi_users(), fn($u) => ($u['role'] ?? '') === 'super' && !empty($u['active']) && !empty($u['phone']))));
        wi_json(['ok' => true, 'cfg' => $c, 'recipients' => $to]);
    }

    }
}
