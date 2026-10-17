<?php
// WhatsApp Inbox — programs (split out of features.php in v3.8, code unchanged)

/* ---------- Programs (treatment programs sent day by day) ---------- */
function wf_programs(string $aid): array { return wi_read('programs.json', [])[$aid] ?? []; }
function wf_enrollments(string $aid): array { return wi_read('enrollments.json', [])[$aid] ?? []; }
/** Links a private chat to a program (its days are sent one by one from now). False when the chat already follows it or is a group. */
function wf_program_enroll(string $aid, string $jid, array $prog, string $by = 'tag'): bool {
    if ($jid === '' || str_ends_with($jid, '@g.us')) return false; $made = false;
    wi_update('enrollments.json', function ($A) use ($aid, $jid, $prog, $by, &$made) {
        foreach ((array) ($A[$aid] ?? []) as $e) if (($e['jid'] ?? '') === $jid && ($e['program_id'] ?? '') === ($prog['id'] ?? '') && in_array($e['status'] ?? '', ['active', 'paused'], true)) return $A;
        $nm = (string) (wi_customers()[preg_replace('/\D/', '', explode('@', $jid)[0])]['name'] ?? '');
        $A[$aid][] = ['id' => bin2hex(random_bytes(6)), 'jid' => $jid, 'name' => mb_substr($nm, 0, 80), 'program_id' => (string) $prog['id'], 'start' => date('Y-m-d'), 'hour' => max(7, min(22, (int) date('G') + 1)),
            'status' => 'active', 'sent' => [], 'replies' => 0, 'by' => $by, 'created' => date('c')]; $made = true; return $A; });
    if ($made) wi_log('program_enroll', ['account' => $aid, 'by' => $by]);
    return $made;
}


/** API calls of this area (moved unchanged from the old single switch). */
function wf_api_programs(string $api): void {
    switch ($api) {
    /* ----- 12) Treatment programs ----- */
    case 'programs': {
        $aid = wf_aid();
        $enr = wf_enrollments($aid); $c = wi_customers();
        wi_json(['ok' => true, 'programs' => wf_programs($aid), 'enrollments' => $enr]);
    }
    case 'program_save': {
        $in = wf_post(); wf_admin(); $aid = wf_aid(); $all = wi_read('programs.json', []); $list = $all[$aid] ?? [];
        if (($in['do'] ?? '') === 'delete') { $list = array_values(array_filter($list, fn($p) => $p['id'] !== ($in['id'] ?? ''))); }
        else {
            $days = [];
            foreach ((array) ($in['days'] ?? []) as $d) { $n = max(1, min(365, (int) ($d['day'] ?? 0))); $tx = trim((string) ($d['text'] ?? '')); if ($tx !== '') $days[] = ['day' => $n, 'text' => mb_substr($tx, 0, 4000)]; }
            usort($days, fn($a, $b) => $a['day'] <=> $b['day']);
            if (trim((string) ($in['name'] ?? '')) === '' || !$days) wi_json(['ok' => false, 'error' => 'Give the program a name and at least one day.']);
            $pid = preg_replace('/[^a-f0-9]/', '', (string) ($in['id'] ?? '')) ?: bin2hex(random_bytes(5)); $code = wf_media_code_clean((string) ($in['code'] ?? ''));
            if ($code !== '' && ($why = wf_tag_taken($aid, $code, 'prog:' . $pid)) !== '') wi_json(['ok' => false, 'error' => $why]);
            $p = ['id' => $pid, 'code' => $code, 'name' => mb_substr(trim((string) $in['name']), 0, 80), 'desc' => mb_substr(trim((string) ($in['desc'] ?? '')), 0, 500), 'days' => $days, 'updated' => date('c')];
            $found = false; foreach ($list as &$x) if ($x['id'] === $p['id']) { $x = $p; $found = true; } unset($x);
            if (!$found) $list[] = $p;
        }
        $all[$aid] = $list; wi_write('programs.json', $all); wi_json(['ok' => true, 'programs' => $list]);
    }
    case 'program_enroll': {
        $in = wf_post(); $aid = wf_aid(); $all = wi_read('enrollments.json', []); $list = $all[$aid] ?? [];
        if (!wi_can_reply()) wi_json(['ok' => false, 'error' => 'You do not have permission to message customers.'], 403);
        if (!empty($in['id'])) {
            foreach ($list as &$e) if ($e['id'] === $in['id'] && in_array($in['status'] ?? '', ['active', 'paused', 'stopped'], true)) { $e['status'] = $in['status']; $e['log'][] = ['status' => $in['status'], 'at' => date('c'), 'by' => $_SESSION['email']]; }
            unset($e);
        } else {
            $jid = wf_jid($in['jid'] ?? ''); $pid = (string) ($in['program_id'] ?? '');
            $prog = null; foreach (wf_programs($aid) as $p) if ($p['id'] === $pid) $prog = $p;
            if ($jid === '' || !$prog) wi_json(['ok' => false, 'error' => 'Choose a chat and a program.']);
            $start = strtotime((string) ($in['start'] ?? 'today')) ?: strtotime('today');
            $list[] = ['id' => bin2hex(random_bytes(6)), 'jid' => $jid, 'name' => mb_substr((string) ($in['name'] ?? ''), 0, 80), 'program_id' => $pid, 'start' => date('Y-m-d', $start), 'hour' => max(7, min(22, (int) ($in['hour'] ?? 10))),
                'status' => 'active', 'sent' => [], 'replies' => 0, 'by' => $_SESSION['email'], 'created' => date('c')];
        }
        $all[$aid] = $list; wi_write('enrollments.json', $all); wi_log('program_enroll', ['account' => $aid]);
        wi_json(['ok' => true, 'enrollments' => $list]);
    }

    }
}
