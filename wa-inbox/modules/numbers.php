<?php
// WhatsApp Inbox — numbers (split out of features.php in v3.8, code unchanged)

/** API calls of this area (moved unchanged from the old single switch). */
function wf_api_numbers(string $api): void {
    switch ($api) {
    /* ----- Numbers: from group members, private chats and numbers written in messages — one row per number, WhatsApp format ----- */
    case 'numbers': {
        $acc = wi_current(); $aid = (string) $acc['id']; @set_time_limit(300);
        $src = array_flip(array_filter(explode(',', (string) ($_GET['src'] ?? 'members,chats,written'))));
        $only = array_filter(explode(',', (string) ($_GET['groups'] ?? '')));
        $cacheF = WI_DIR . '/numbers_' . preg_replace('/\W/', '', $aid) . '.json';
        $cache = json_decode((string) @file_get_contents($cacheF), true) ?: [];
        $fresh = !empty($cache['at']) && time() - $cache['at'] < 1800 && empty($_GET['refresh']);
        if (!$fresh) {
            $g = wi_gateway('/groups' . (!empty($_GET['refresh']) ? '?refresh=1' : ''));
            if (empty($g['ok']) && !empty($cache['members'])) wi_json(['ok' => false, 'error' => 'WhatsApp is busy right now — the last complete list is kept. Try again in a minute.']);
            $prev = $cache; $failed = []; $known = [];
            $cache = ['at' => time(), 'groups' => [], 'members' => [], 'chats' => [], 'written' => []];
            foreach ($g['groups'] ?? [] as $x) {
                $cache['groups'][$x['jid']] = $x['name'];
                $m = wi_gateway('/groups/members?jid=' . rawurlencode($x['jid']));
                if (empty($m['ok'])) { usleep(800000); $m = wi_gateway('/groups/members?jid=' . rawurlencode($x['jid'])); } // one retry
                if (empty($m['ok'])) {
                    // Keep this group's members from the previous read instead of silently dropping them.
                    $failed[] = $x['name'];
                    foreach ($prev['members'] ?? [] as $pm) if ($pm['g'] === $x['jid']) $cache['members'][] = $pm;
                    continue;
                }
                foreach ($m['members'] ?? [] as $p) $cache['members'][] = ['p' => $p['phone'], 'n' => $p['name'] ?? '', 'g' => $x['jid'], 'lid' => !empty($p['is_lid']), 'a' => $p['admin'] ?? ''];
                $known[$x['jid']] = ['name' => $x['name'], 'at' => date('c'), 'members' => array_map(fn($p) => ['p' => $p['phone'], 'n' => $p['name'] ?? '', 'lid' => !empty($p['is_lid'])], $m['members'] ?? [])];
            }
            $kAll = wi_read('known_members.json', []); $kAll[$aid] = $known + ($kAll[$aid] ?? []); wi_write('known_members.json', $kAll); $known = $kAll[$aid];
            $cache['failed'] = $failed;
            // Groups we left: their members cannot be read any more, but everyone who wrote there is in the archive.
            $ac = wi_gateway('/archive/chats?months=' . (int) wi_sys('months_long') );
            foreach ($ac['chats'] ?? [] as $c) {
                if (empty($c['group']) || isset($cache['groups'][$c['jid']])) continue;
                $cache['groups'][$c['jid']] = ($c['name'] ?: explode('@', $c['jid'])[0]) . ' ⟲';
                $seen = [];
                $push = function (string $p, string $n, bool $lid) use (&$cache, &$seen, $c) { $p = wf_phone($p); if ($p === '' || isset($seen[$p])) return; $seen[$p] = true; $cache['members'][] = ['p' => $p, 'n' => $n, 'g' => $c['jid'], 'lid' => $lid, 'a' => '', 'past' => true]; };
                // 1) The member list saved while we were still in the group.
                foreach ($known[$c['jid']]['members'] ?? [] as $km) $push($km['p'], $km['n'] ?? '', !empty($km['lid']));
                // 2) Everyone who wrote, was @mentioned, or was replied to in the archive.
                $t = wi_gateway('/archive/thread?limit=60000&jid=' . rawurlencode($c['jid']));
                $looksLid = fn($p) => strlen($p) >= 14 && $p[0] === '1';
                foreach ($t['rows'] ?? [] as $x) {
                    if (($x['dir'] ?? '') === 'in' && !empty($x['from'])) $push((string) $x['from'], (string) ($x['from_name'] ?? ''), $looksLid(wf_phone($x['from'])) && empty($x['from_lid']));
                    foreach ((array) ($x['mentions'] ?? []) as $mn) $push((string) $mn, '', $looksLid(wf_phone($mn)));
                    if (!empty($x['quote_from'])) $push((string) $x['quote_from'], '', $looksLid(wf_phone($x['quote_from'])));
                }
            }
            $ch = wi_gateway('/archive/chats?months=' . (int) wi_sys('months_long') );
            foreach ($ch['chats'] ?? [] as $c) if (empty($c['group']) && !empty($c['phone'])) $cache['chats'][] = ['p' => $c['phone'], 'n' => $c['name'] ?? ''];
            $w = wi_gateway('/archive/numbers');
            foreach ($w['numbers'] ?? [] as $x) $cache['written'][] = ['p' => $x['phone'], 'c' => $x['count'], 'g' => array_keys($x['chats'])];
            file_put_contents($cacheF, json_encode($cache, JSON_UNESCAPED_UNICODE)); @chmod($cacheF, 0600);
        }
        $cust = wi_customers(); $rows = []; $lids = 0;
        $add = function (string $p, string $name, string $from, ?string $gj) use (&$rows, $cache, $cust) {
            $p = wf_phone($p);
            // WhatsApp format only: country code + number. Local numbers (starting with 0) cannot be used.
            if (strlen($p) < 10 || strlen($p) > 15 || $p[0] === '0') return;
            $r = $rows[$p] ?? ['phone' => $p, 'name' => '', 'groups' => [], 'sources' => [], 'customer' => $cust[$p]['name'] ?? ''];
            if ($r['name'] === '' && $name !== '' && !preg_match('/^[\d\s+]+$/', $name)) $r['name'] = $name;
            if ($gj && isset($cache['groups'][$gj])) $r['groups'][$gj] = $cache['groups'][$gj];
            $r['sources'][$from] = true; $rows[$p] = $r;
        };
        if (isset($src['members'])) foreach ($cache['members'] as $m) { if ($only && !in_array($m['g'], $only, true)) continue; if ($m['lid']) { $lids++; continue; } $add($m['p'], $m['n'], !empty($m['past']) ? 'left_group' : ($m['a'] ? 'admin' : 'member'), $m['g']); }
        if (isset($src['chats']) && !$only) foreach ($cache['chats'] as $c) $add($c['p'], $c['n'], 'chat', null);
        if (isset($src['written'])) foreach ($cache['written'] as $x) { $gs = array_values(array_filter($x['g'], fn($j) => !$only || in_array($j, $only, true))); if ($only && !$gs) continue; $add($x['p'], '', 'written', null); $k = wf_phone($x['p']); if (!isset($rows[$k])) continue; foreach ($gs as $gj) if (isset($cache['groups'][$gj])) $rows[$k]['groups'][$gj] = $cache['groups'][$gj]; }
        foreach ($rows as &$r) { if ($r['name'] === '' && $r['customer'] !== '') $r['name'] = $r['customer']; $r['groups'] = array_values($r['groups']); $r['sources'] = array_keys($r['sources']); }
        unset($r);
        $rows = array_values($rows);
        usort($rows, fn($a, $b) => [count($b['groups']), $a['phone']] <=> [count($a['groups']), $b['phone']]);
        $fmt = (string) ($_GET['export'] ?? '');
        if ($fmt !== '') {
            wi_log('numbers_export', ['account' => $aid, 'format' => $fmt, 'count' => count($rows)]);
            $name = 'whatsapp_numbers_' . date('Ymd_Hi');
            if ($fmt === 'vcf') {
                header('Content-Type: text/vcard; charset=utf-8'); header('Content-Disposition: attachment; filename="' . $name . '.vcf"');
                foreach ($rows as $r) echo "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:" . str_replace(["\r", "\n", ';', ','], ' ', $r['name'] !== '' ? $r['name'] : '+' . $r['phone']) . "\r\nTEL;TYPE=CELL:+" . $r['phone'] . "\r\nNOTE:" . str_replace(["\r", "\n", ';', ','], ' ', implode(' / ', $r['groups'])) . "\r\nEND:VCARD\r\n";
                exit;
            }
            if ($fmt === 'txt') { header('Content-Type: text/plain; charset=utf-8'); header('Content-Disposition: attachment; filename="' . $name . '.txt"'); echo implode("\r\n", array_column($rows, 'phone')); exit; }
            header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="' . $name . '.csv"');
            $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
            fputcsv($o, ['phone', 'name', 'groups', 'source', 'customer', 'wa_link']);
            foreach ($rows as $r) fputcsv($o, [$r['phone'], $r['name'], implode(' | ', $r['groups']), implode(',', $r['sources']), $r['customer'], 'https://wa.me/' . $r['phone']]);
            exit;
        }
        $bySrc = ['member' => 0, 'admin' => 0, 'chat' => 0, 'written' => 0, 'left_group' => 0];
        foreach ($rows as $r) foreach ($r['sources'] as $x) $bySrc[$x] = ($bySrc[$x] ?? 0) + 1;
        wi_json(['ok' => true, 'rows' => $rows, 'groups' => $cache['groups'], 'lids' => $lids, 'cached_at' => date('c', (int) $cache['at']), 'failed' => $cache['failed'] ?? [], 'by_source' => $bySrc]);
    }
    }
}

/** Numbers that are not linked (logged out, need the QR): stopped until their QR page is opened, warned daily, switched off at the end (System settings: idle_*). */
function wf_idle_numbers(callable $tellAdmins, int $now): void {
    $I = wi_read('idle_numbers.json', []); $A = wi_accounts(); $chg = false;
    foreach ($A as $id => $a) { if (!empty($a['main']) || empty($a['enabled']) || (string) ($a['channel'] ?? '') !== '' || (int) ($a['port'] ?? 0) < 1024) { unset($I[$id]); continue; } // WhatsApp numbers only (a Facebook page has no gateway)
        $inQr = (int) ($a['qr_until'] ?? 0) > $now; $h = (!empty($a['parked']) && !$inQr) ? [] : wi_gateway('/health', null, $a);
        if (!empty($h['connected'])) { unset($I[$id]); if (!empty($a['parked']) || isset($a['qr_until'])) { unset($A[$id]['parked'], $A[$id]['qr_until']); $chg = true; } continue; }
        // not linked = WhatsApp logged it out, or it answers but has stayed disconnected for 30 minutes (a stopped or crashed process is not counted: the watchdog restarts it)
        $idle = !empty($a['parked']) || !empty($h['logged_out']) || (!empty($h['ok']) && empty($h['connected']) && !empty($h['disconnected_since']) && strtotime((string) $h['disconnected_since']) < $now - 1800);
        if (!$idle) continue;
        $I[$id]['since'] ??= date('c'); $days = (int) floor(($now - strtotime((string) $I[$id]['since'])) / 86400);
        if (empty($a['parked']) && !$inQr) { $A[$id]['parked'] = true; $chg = true; }
        $warn = max(1, (int) wi_sys('idle_warn_days')); $stop = max($warn, (int) wi_sys('idle_stop_days'));
        if ($days >= $stop) { $A[$id]['enabled'] = false; unset($A[$id]['parked'], $A[$id]['qr_until']); $chg = true; unset($I[$id]);
            $tellAdmins(wf_msg('number_idle_stopped', ['name' => (string) ($a['name'] ?? $id), 'days' => (string) $days])); wi_log('number_idle_stopped', ['account' => $id]); continue; }
        if ($days >= $warn && ($I[$id]['warned'] ?? '') !== date('Y-m-d')) { $I[$id]['warned'] = date('Y-m-d');
            $tellAdmins(wf_msg('number_idle_warn', ['name' => (string) ($a['name'] ?? $id), 'days' => (string) $days, 'left' => (string) ($stop - $days)])); } }
    if ($chg) wi_save_accounts($A); wi_write('idle_numbers.json', $I);
}

