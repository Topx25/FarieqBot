<?php
// WhatsApp Inbox — groups (split out of features.php in v3.8, code unchanged)

/** API calls of this area (moved unchanged from the old single switch). */
function wf_api_groups(string $api): void {
    switch ($api) {
    /* ----- 7) Groups ----- */
    case 'groups': {
        $r = wi_gateway('/groups' . (!empty($_GET['refresh']) ? '?refresh=1' : ''));
        if (empty($r['ok'])) wi_json($r);
        $mon = wf_case_groups(wf_aid());
        $ch = wi_gateway('/archive/chats?months=' . (int) wi_sys('months_short') ); $act = [];
        foreach ($ch['chats'] ?? [] as $c) $act[$c['jid']] = $c;
        foreach ($r['groups'] as &$g) { $g['monitored'] = isset($mon[$g['jid']]); $g['messages'] = $act[$g['jid']]['count'] ?? 0; $g['last'] = $act[$g['jid']]['last']['time'] ?? null; }
        unset($g);
        // Groups we left (or were removed from): still in the archive with all their messages.
        $have = array_flip(array_column($r['groups'], 'jid'));
        $all = wi_gateway('/archive/chats?months=' . (int) wi_sys('months_long') );
        foreach ($all['chats'] ?? [] as $c) {
            if (empty($c['group']) || isset($have[$c['jid']])) continue;
            $r['groups'][] = ['jid' => $c['jid'], 'name' => $c['name'] ?: explode('@', $c['jid'])[0], 'members' => 0, 'admins' => 0, 'i_am_admin' => false, 'left' => true,
                'monitored' => isset($mon[$c['jid']]), 'messages' => $c['count'] ?? 0, 'last' => $c['last']['time'] ?? null, 'desc' => '', 'announce' => false];
        }
        wi_json($r);
    }
    case 'group_detail': {
        $jid = wf_jid($_GET['jid'] ?? '');
        $m = wi_gateway('/groups/members?jid=' . rawurlencode($jid));
        $e = wi_gateway('/groups/events?jid=' . rawurlencode($jid));
        $t = wi_gateway('/archive/thread?limit=60000&jid=' . rawurlencode($jid));
        $by = []; $days = [];
        foreach ($t['rows'] ?? [] as $x) {
            if (($x['dir'] ?? '') !== 'in') continue;
            $p = wf_phone($x['from'] ?? ''); if ($p === '') continue;
            $by[$p] = ($by[$p] ?? 0) + 1;
            $d = substr((string) $x['time'], 0, 10); $days[$d] = ($days[$d] ?? 0) + 1;
        }
        arsort($by); ksort($days);
        if (!empty($m['members'])) { $c = wi_customers(); foreach ($m['members'] as &$x) { $x['messages'] = $by[$x['phone']] ?? 0; $x['customer'] = $c[$x['phone']] ?? null; } unset($x); usort($m['members'], fn($a, $b) => $b['messages'] <=> $a['messages']); }
        wi_json(['ok' => !empty($m['ok']), 'error' => $m['error'] ?? '', 'name' => $m['name'] ?? '', 'members' => $m['members'] ?? [], 'events' => array_slice($e['events'] ?? [], 0, 300), 'days' => array_slice($days, -60, null, true), 'silent' => count(array_filter($m['members'] ?? [], fn($x) => !$x['messages']))]);
    }
    case 'group_view': {
        $acc = wi_current(); $jid = wf_jid($_GET['jid'] ?? '');
        $m = wi_gateway('/groups/members?jid=' . rawurlencode($jid));
        $rows = wf_group_rows($acc, $jid);
        $cases = wf_cases((string) $acc['id']); $idMap = [];
        foreach ($cases as $ph => $c) foreach (wf_case_ids($c) as $id) $idMap[$id] = $ph;
        $by = []; $names = []; $lastAt = [];
        foreach ($rows as $x) {
            if (($x['dir'] ?? '') !== 'in') continue;
            $p = wf_phone($x['from'] ?? ''); if ($p === '') continue;
            $by[$p] = ($by[$p] ?? 0) + 1; $lastAt[$p] = $x['time'];
            if (!empty($x['from_name'])) $names[$p] = $x['from_name'];
        }
        $cust = wi_customers(); $members = [];
        foreach ($m['members'] ?? [] as $x) {
            $p = $x['phone'];
            $members[$p] = ['phone' => $p, 'name' => $cust[$p]['name'] ?? ($names[$p] ?? ($x['name'] ?? '')), 'admin' => $x['admin'] ?? '', 'messages' => $by[$p] ?? 0, 'last' => $lastAt[$p] ?? null, 'case' => $idMap[$p] ?? null, 'customer' => isset($cust[$p]), 'member' => true];
        }
        // People who wrote but are no longer members (or are known only by their LID).
        foreach ($by as $p => $n) if (!isset($members[$p])) $members[$p] = ['phone' => $p, 'name' => $names[$p] ?? '', 'admin' => '', 'messages' => $n, 'last' => $lastAt[$p] ?? null, 'case' => $idMap[$p] ?? null, 'customer' => isset($cust[$p]), 'member' => false];
        $members = array_values($members);
        usort($members, fn($a, $b) => [$b['messages'], (string) $b['last']] <=> [$a['messages'], (string) $a['last']]);
        $msgs = [];
        foreach (array_slice($rows, -400) as $x) {
            if (!in_array($x['dir'] ?? '', ['in', 'out'], true)) continue;
            $p = wf_phone($x['from'] ?? '');
            $msgs[] = ['id' => $x['id'] ?? '', 'dir' => $x['dir'], 'time' => $x['time'], 'kind' => $x['kind'] ?? 'text', 'text' => mb_substr((string) ($x['text'] ?? ''), 0, 1500), 'from' => $p, 'from_name' => $x['from_name'] ?? '', 'case' => $idMap[$p] ?? null,
                'quote' => $x['quote'] ?? null, 'quote_from' => wf_phone($x['quote_from'] ?? ''), 'by' => $x['by'] ?? ''];
        }
        $left = empty($m['ok']);
        if ($left && empty($m['name'])) { foreach ((wi_gateway('/archive/chats?months=' . (int) wi_sys('months_long') )['chats'] ?? []) as $c) if ($c['jid'] === $jid) $m['name'] = $c['name']; }
        wi_json(['ok' => true, 'left' => $left, 'jid' => $jid, 'name' => $m['name'] ?? '', 'members' => $members, 'messages' => $msgs, 'monitored' => isset(wf_case_groups((string) $acc['id'])[$jid]), 'registry' => isset(wf_registry_groups((string) $acc['id'])[$jid]), 'total' => count($rows)]);
    }
    case 'case_track': {
        $in = wf_post(); @set_time_limit(200);
        $acc = wi_current(); $aid = (string) $acc['id'];
        $ph = wf_phone($in['phone'] ?? ''); $gj = wf_jid($in['group'] ?? '');
        if ($ph === '' || !str_ends_with($gj, '@g.us')) wi_json(['ok' => false, 'error' => 'Choose a person in a group.']);
        $email = (string) $_SESSION['email'];
        wf_case_update($aid, $ph, function (?array $c) use ($in, $gj, $ph, $email) { // on the stored case, under the lock
            $c = $c ?? ['phone' => $ph, 'status' => 'new', 'created' => date('c'), 'notes' => [], 'data' => []];
            $c['groups'][$gj] = mb_substr((string) ($in['group_name'] ?? $gj), 0, 120);
            if (!empty($in['lid']) && wf_phone($in['lid']) !== $ph) $c['lids'] = array_values(array_unique(array_merge((array) ($c['lids'] ?? []), [wf_phone($in['lid'])])));
            if (trim((string) ($in['name'] ?? '')) !== '' && empty($c['name'])) $c['name'] = mb_substr(trim((string) $in['name']), 0, 80);
            $c['tracked'] = true;
            if (($c['status'] ?? 'new') === 'new') { $c['status_log'][] = ['from' => 'new', 'to' => 'follow', 'at' => date('c'), 'by' => $email]; $c['status'] = 'follow'; }
            $c['tracked_by'] = $email; $c['tracked_at'] = date('c');
            return $c; });
        wf_cases_sync($acc); // counts, names, IDs + full copy of their conversations
        wi_log('case_track', ['account' => $aid, 'phone' => $ph, 'group' => $gj]);
        wi_json(['ok' => true, 'phone' => $ph]);
    }
    }
}
