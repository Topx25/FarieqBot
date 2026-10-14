<?php
// WhatsApp Inbox — customer (split out of features.php in v3.8, code unchanged)

/** API calls of this area (moved unchanged from the old single switch). */
function wf_api_customer(string $api): void {
    switch ($api) {
    /* ----- 10) Customer profile ----- */
    case 'customer': {
        $acc = wi_current(); $aid = (string) $acc['id']; $jid = wf_jid($_GET['jid'] ?? '');
        $info = wi_chat_customer($jid); $ph = $info['phone'];
        $t = wi_gateway('/archive/thread?jid=' . rawurlencode($jid)); $rows = $t['rows'] ?? [];
        $in = count(array_filter($rows, fn($m) => $m['dir'] === 'in')); $out = count(array_filter($rows, fn($m) => $m['dir'] === 'out'));
        $kinds = array_count_values(array_map(fn($m) => $m['kind'] ?? 'text', array_filter($rows, fn($m) => in_array($m['dir'], ['in', 'out'], true))));
        $meta = wi_read('chat_meta.json', [])[$aid][$jid] ?? [];
        $sum = wi_read('summaries.json', [])[$aid][$jid] ?? null;
        $notes = wi_read('notes.json', [])[$aid][$jid] ?? [];
        $rem = array_values(array_filter(wi_read('reminders.json', []), fn($r) => ($r['acc'] ?? '') === $aid && ($r['jid'] ?? '') === $jid));
        $enr = array_values(array_filter(wf_enrollments($aid), fn($e) => $e['jid'] === $jid));
        $progs = []; foreach (wf_programs($aid) as $p) $progs[$p['id']] = $p['name'];
        foreach ($enr as &$e) $e['program_name'] = $progs[$e['program_id']] ?? '';
        unset($e);
        $sched = array_values(array_filter(wi_read('scheduled.json', []), fn($s) => ($s['acc'] ?? '') === $aid && $s['jid'] === $jid && $s['state'] === 'pending'));
        $case = $ph !== '' ? (wf_cases($aid)[$ph] ?? null) : null;
        $invoices = !empty($info['customer']['id']) ? wi_customer_invoices((int) $info['customer']['id']) : [];
        wi_json(['ok' => true, 'jid' => $jid, 'phone' => $ph, 'name' => $info['name'], 'customer' => $info['customer'], 'invoices' => $invoices,
            'stats' => ['in' => $in, 'out' => $out, 'first' => $rows[0]['time'] ?? null, 'last' => $rows ? end($rows)['time'] : null, 'kinds' => $kinds],
            'meta' => $meta, 'summary' => $sum, 'notes' => $notes, 'reminders' => $rem, 'enrollments' => $enr, 'scheduled' => $sched, 'case' => $case]);
    }

    }
}
