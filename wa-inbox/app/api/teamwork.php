<?php
// WhatsApp Inbox API — teamwork: quick replies, labels, assignment, reminders, viewing; analytics; library. Included by app/api.php inside the request (same scope: $api, $apiPage…).
    /* ---------- Teamwork: quick replies, labels, assignment, follow-up reminders, who is viewing ---------- */
    if ($api === 'meta') {
        $acc = wi_current(); $aid = $acc['id'] ?? '';
        $L = wi_read('labels.json', ['defs' => [], 'chats' => []]);
        $A = wi_read('assign.json', []);
        $R = array_values(array_filter(wi_read('reminders.json', []), fn($r) => ($r['acc'] ?? '') === $aid && empty($r['done'])));
        $V = wi_read('viewing.json', []);
        $viewing = [];
        foreach ($V[$aid] ?? [] as $jid => $who) foreach ($who as $email => $t) if (time() - $t < 40 && strtolower($email) !== strtolower((string) $_SESSION['email'])) $viewing[$jid][] = $email;
        $users = $acc ? wi_account_people($acc) : [];
        $ws = wi_ws($acc); $defs = $ws === '' ? ($L['defs'] ?? []) : (array) ($L['ws'][$ws] ?? []);
        $sums = wi_read('summaries.json', [])[$aid] ?? [];
        $cats = []; foreach ($sums as $j => $x) $cats[$j] = ['category' => $x['category'], 'needs_reply' => $x['needs_reply'], 'sentiment' => $x['sentiment']];
        wi_json(['ok' => true, 'me' => (string) $_SESSION['email'], 'summaries' => (object) $cats, 'replies' => wi_replies(), 'labels' => $defs, 'can' => array_combine(WI_CHAT_PERMS, array_map('wi_chat_can', WI_CHAT_PERMS)), 'chat_labels' => $L['chats'][$aid] ?? (object) [],
            'assign' => $A[$aid] ?? (object) [], 'reminders' => $R, 'viewing' => (object) $viewing, 'users' => $users]);
    }
    if ($api === 'meta_set') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') wi_json(['ok' => false, 'error' => 'POST only.'], 405);
        wi_check_csrf();
        $acc = wi_current(); $aid = $acc['id'] ?? '';
        $in = json_decode(wi_input(), true) ?: [];
        $act = (string) ($in['action'] ?? '');
        $jid = preg_replace('/[^0-9A-Za-z@.:_-]/', '', (string) ($in['jid'] ?? ''));
        $me = (string) $_SESSION['email'];
        // chat permissions per action: quick replies, labels, follow-up reminders, assigning
        $metaNeed = ['reply_import' => 'quick', 'reply_save' => 'quick', 'reply_delete' => 'quick', 'label_def' => 'labels', 'label_def_delete' => 'labels', 'label_toggle' => 'labels', 'remind' => 'notes', 'remind_done' => 'notes'][$act] ?? '';
        if ($metaNeed !== '' && !wi_chat_can($metaNeed)) wi_json(['ok' => false, 'error' => 'You do not have permission for this in a conversation.'], 403);
        if ($act === 'assign' && !wi_chat_can('assign')) { // without the assign permission: take a free chat for yourself, or give back your own
            $to0 = strtolower(trim((string) ($in['to'] ?? ''))); $cur0 = strtolower((string) (wi_read('assign.json', [])[$aid][$jid]['to'] ?? ''));
            if (!(($to0 === strtolower($me) && $cur0 === '') || ($to0 === '' && $cur0 === strtolower($me)))) wi_json(['ok' => false, 'error' => 'You can take a free conversation or give back your own; ask your supervisor to assign it to someone else.'], 403); }
        // Super admin: give a client's number a copy of the operator's quick replies (same names are skipped).
        if ($act === 'reply_import') {
            if (!wi_is_super() || wi_ws($acc) === '') wi_json(['ok' => false, 'error' => 'Not allowed for this number.'], 403);
            $rs = wi_replies(); $have = array_map(fn($r) => mb_strtolower(trim((string) $r['title'])), $rs); $n = 0;
            foreach ((array) wi_read('replies.json', []) as $r) if (!in_array(mb_strtolower(trim((string) $r['title'])), $have, true)) { $r['id'] = bin2hex(random_bytes(4)); $r['by'] = $me; $r['updated_at'] = date('c'); $rs[] = $r; $n++; }
            usort($rs, fn($a, $b) => strcasecmp($a['title'], $b['title'])); wi_replies_save($rs); wi_log('reply_import', ['account' => $aid, 'count' => $n]);
            wi_json(['ok' => true, 'replies' => $rs, 'added' => $n]);
        }
        if ($act === 'reply_save' || $act === 'reply_delete') {
            if (!wi_can_reply()) wi_json(['ok' => false, 'error' => 'You do not have permission to reply.'], 403);
            $rs = wi_replies();
            if ($act === 'reply_delete') $rs = array_values(array_filter($rs, fn($r) => $r['id'] !== ($in['id'] ?? '')));
            else {
                $title = trim(mb_substr((string) ($in['title'] ?? ''), 0, 60)); $text = trim(mb_substr((string) ($in['text'] ?? ''), 0, 2000));
                if ($title === '' || $text === '') wi_json(['ok' => false, 'error' => 'Write a short name and the reply text.'], 422);
                $id = (string) ($in['id'] ?? '') ?: bin2hex(random_bytes(4));
                $rs = array_values(array_filter($rs, fn($r) => $r['id'] !== $id));
                // words or phrases that make the dashboard suggest this reply while someone types
                $trig = array_values(array_unique(array_filter(array_map(fn($x) => trim(mb_substr($x, 0, 80)), preg_split('/[\n,،]+/u', (string) ($in['triggers'] ?? ''))))));
                // members' keyword ("#تحصين"): one reply per keyword on this number
                $kw = trim(mb_substr(preg_replace('/^[#\/\s]+/u', '', (string) ($in['keyword'] ?? '')), 0, 30));
                if ($kw !== '') foreach ($rs as $o) if (wf_norm((string) ($o['keyword'] ?? '')) === wf_norm($kw)) wi_json(['ok' => false, 'error' => 'Another quick reply already uses this keyword.'], 422);
                $rs[] = ['id' => $id, 'title' => $title, 'text' => $text, 'keyword' => $kw, 'triggers' => array_slice($trig, 0, 30), 'by' => $me, 'updated_at' => date('c')];
                usort($rs, fn($a, $b) => strcasecmp($a['title'], $b['title']));
            }
            wi_replies_save($rs); wi_json(['ok' => true, 'replies' => $rs]);
        }
        if ($act === 'label_def' || $act === 'label_def_delete') {
            if (!wi_is_admin() && !in_array(wi_me()['role'] ?? '', ['client', 'lead'], true)) wi_json(['ok' => false, 'error' => 'Only the owner or a supervisor can change the label list.'], 403);
            $name = trim(mb_substr((string) ($in['name'] ?? ''), 0, 30)); $color = preg_match('/^#[0-9a-f]{6}$/i', (string) ($in['color'] ?? '')) ? $in['color'] : '#10b981';
            if ($act === 'label_def' && $name === '') wi_json(['ok' => false, 'error' => 'Name the label.'], 422);
            $ws = wi_ws($acc); $did = (string) ($in['id'] ?? '');
            $L = wi_update('labels.json', function ($L) use ($ws, $act, $did, $name, $color) { $L = (array) ($L ?: ['defs' => [], 'chats' => []]);
                $d = $ws === '' ? (array) ($L['defs'] ?? []) : (array) ($L['ws'][$ws] ?? []);
                if ($act === 'label_def_delete') $d = array_values(array_filter($d, fn($x) => $x['id'] !== $did)); else $d[] = ['id' => bin2hex(random_bytes(3)), 'name' => $name, 'color' => $color];
                if ($ws === '') $L['defs'] = $d; else $L['ws'][$ws] = $d; return $L; });
            wi_json(['ok' => true, 'labels' => $ws === '' ? $L['defs'] : $L['ws'][$ws]]);
        }
        if ($jid === '') wi_json(['ok' => false, 'error' => 'Choose a conversation.'], 422);
        if ($act === 'label_toggle') {
            $id = (string) ($in['id'] ?? ''); $cur = [];
            wi_update('labels.json', function ($L) use ($aid, $jid, $id, &$cur) { $L = (array) ($L ?: ['defs' => [], 'chats' => []]); // under the lock
                $cur = (array) ($L['chats'][$aid][$jid] ?? []); $cur = in_array($id, $cur, true) ? array_values(array_diff($cur, [$id])) : array_merge($cur, [$id]);
                if ($cur) $L['chats'][$aid][$jid] = $cur; else unset($L['chats'][$aid][$jid]); return $L; }, ['defs' => [], 'chats' => []]);
            wi_json(['ok' => true, 'labels' => $cur]);
        }
        if ($act === 'assign') {
            $to = strtolower(trim((string) ($in['to'] ?? '')));
            if ($to !== '' && !in_array($to, array_map(fn($p) => strtolower($p['email']), wi_account_people($acc)), true)) wi_json(['ok' => false, 'error' => 'This user does not work on this number.'], 422);
            $A = wi_update('assign.json', function ($A) use ($aid, $jid, $to, $me) { if ($to === '') unset($A[$aid][$jid]); else $A[$aid][$jid] = ['to' => $to, 'by' => $me, 'at' => date('c')]; return $A; }); // under the lock
            wi_log('assign', ['account' => $aid, 'jid' => $jid, 'to' => $to]);
            wi_json(['ok' => true, 'assign' => $A[$aid][$jid] ?? null]);
        }
        if ($act === 'remind' || $act === 'remind_done') {
            $R = wi_read('reminders.json', []);
            if ($act === 'remind_done') { foreach ($R as &$r) if ($r['id'] === ($in['id'] ?? '') && ($r['acc'] ?? '') === $aid) $r['done'] = date('c'); unset($r); }
            else {
                $at = strtotime((string) ($in['at'] ?? ''));
                if (!$at || $at < time() - 60) wi_json(['ok' => false, 'error' => 'Choose a time in the future.'], 422);
                $R[] = ['id' => bin2hex(random_bytes(4)), 'acc' => $aid, 'jid' => $jid, 'name' => mb_substr((string) ($in['name'] ?? ''), 0, 80), 'at' => date('c', $at),
                    'note' => trim(mb_substr((string) ($in['note'] ?? ''), 0, 300)), 'by' => $me, 'notified' => false, 'created_at' => date('c')];
            }
            $R = array_values(array_filter($R, fn($r) => empty($r['done']) || strtotime($r['done']) > time() - 30 * 86400));
            wi_write('reminders.json', $R); wi_json(['ok' => true]);
        }
        if ($act === 'viewing') {
            $V = wi_read('viewing.json', []);
            foreach ($V[$aid] ?? [] as $j => $who) foreach ($who as $e => $t) if (time() - $t > 120) unset($V[$aid][$j][$e]);
            $V[$aid][$jid][$me] = time();
            wi_write('viewing.json', $V); wi_json(['ok' => true]);
        }
        wi_json(['ok' => false, 'error' => 'Unknown action.'], 422);
    }
    if ($api === 'reply_fill') {
        // Fills a quick reply for one chat: {name} {phone} {due} {invoice} {date}
        $jid = preg_replace('/[^0-9A-Za-z@.:_-]/', '', (string) ($_GET['jid'] ?? ''));
        $text = (string) ($_GET['text'] ?? '');
        wi_json(['ok' => true, 'text' => ($ca = wi_current()) ? wi_reply_fill($text, $jid, $ca) : $text]);
    }
    if ($api === 'analyze') {
        $days = max(1, min(365, (int) ($_GET['days'] ?? 30)));
        $r = wi_gateway('/archive/analyze?days=' . $days);
        if (!empty($r['ok'])) {
            $c = wi_customers();
            foreach (['waiting', 'top'] as $k) foreach ($r[$k] as &$x) { $x['customer'] = $c[$x['phone'] ?? ''] ?? null; } unset($x);
            foreach ($r['entities']['phones'] as &$x) { $n = wi_norm_phone($x['value']); $x['customer'] = $c[$n] ?? null; $x['norm'] = $n; } unset($x);
        }
        if (($_GET['csv'] ?? '') !== '' && !empty($r['ok'])) {
            $kind = (string) $_GET['csv'];
            if (!isset($r['entities'][$kind]) && !in_array($kind, ['waiting', 'top'], true)) { http_response_code(404); exit; }
            wi_log('analytics_export', ['account' => wi_current()['id'] ?? '', 'kind' => $kind]);
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="whatsapp_' . $kind . '_' . $days . 'd_' . date('Y-m-d') . '.csv"');
            $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
            if (isset($r['entities'][$kind])) {
                fputcsv($out, ['Value', 'Times mentioned', 'Chats', 'Last seen', 'Customer']);
                foreach ($r['entities'][$kind] as $e) fputcsv($out, [$e['value'], $e['count'], count($e['chats']), $e['last'], $e['customer']['name'] ?? '']);
            } else {
                fputcsv($out, ['Name', 'Number', 'Received', 'Sent', 'Last message', 'Waiting (minutes)', 'Customer']);
                foreach ($r[$kind] as $e) fputcsv($out, [$e['name'], $e['phone'] ? '+' . $e['phone'] : '', $e['in'], $e['out'], $e['last'], $e['waiting_minutes'] ?? '', $e['customer']['name'] ?? '']);
            }
            fclose($out); exit;
        }
        wi_json($r);
    }
    if ($api === 'library') {
        $type = in_array($_GET['type'] ?? '', ['audio', 'all', 'sections'], true) || preg_match('/^[a-z_]{2,20}$/', (string) ($_GET['type'] ?? '')) && $_GET['type'] !== 'audio' ? $_GET['type'] : 'sections';
        $grouped = $type === 'sections' || ($type !== 'audio' && ($_GET['group'] ?? '1') === '1');
        $r = wi_gateway('/library?' . http_build_query(['type' => $type, 'q' => (string) ($_GET['q'] ?? ''), 'group' => $grouped ? '1' : '0', 'min' => (int) ($_GET['min'] ?? 60)]));
        if (!empty($r['ok']) && isset($r['rows'])) { $c = wi_customers(); foreach ($r['rows'] as &$x) $x['customer'] = $c[$x['phone'] ?? ''] ?? null; unset($x); }
        if (!empty($r['ok']) && isset($r['groups'])) {
            // Hide archived / deleted versions; show only archived ones when asked.
            $hidden = wi_read('lib_hidden.json', [])[wi_current()['id'] ?? ''] ?? [];
            $view = (string) ($_GET['view'] ?? '');
            $groups = []; $sections = []; $topics = []; $archivedN = 0;
            $aiRev = wi_read('lib_ai.json', [])[wi_current()['id'] ?? ''] ?? [];
            if ($aiRev) {
                // AI review: corrected section/topic/title, quality, and merges of near-duplicates.
                $byKey = []; foreach ($r['groups'] as $i => $g) $byKey[$g['best']['key'] ?? ''] = $i;
                foreach ($r['groups'] as $i => &$g) {
                    $gk = $g['best']['key'] ?? ''; $g['gkey'] = $gk;
                    $a = $aiRev[$gk] ?? null; if (!$a) continue;
                    $t = $a['merge_into'] ?? '';
                    if ($t !== '' && isset($byKey[$t]) && $byKey[$t] !== $i && empty($g['audio'])) {
                        $j = $byKey[$t]; $r['groups'][$j]['others'] = array_merge($r['groups'][$j]['others'] ?? [], [$g['best']], $g['others'] ?? []);
                        $r['groups'][$j]['chats'] = ($r['groups'][$j]['chats'] ?? 0) + ($g['chats'] ?? 0); $g = null; continue;
                    }
                    if (!empty($a['best_pick']) && $a['best_pick'] !== ($g['best']['key'] ?? '')) {
                        foreach ($g['others'] ?? [] as $oi => $o) if (($o['key'] ?? '') === $a['best_pick']) { $g['others'][$oi] = $g['best']; $g['best'] = $o; break; }
                    }
                    if (!empty($a['section'])) $g['section'] = $a['section'];
                    if (!empty($a['topic'])) $g['topic'] = $a['topic'];
                    if (!empty($a['title'])) $g['title'] = $a['title'];
                    $g['ai'] = ['quality' => $a['quality'] ?? null, 'note' => $a['note'] ?? '', 'valid' => $a['valid'] ?? null, 'compare' => $a['compare'] ?? null, 'formatted' => $a['formatted'] ?? ''];
                }
                unset($g);
                $r['groups'] = array_values(array_filter($r['groups']));
            }
            foreach ($r['groups'] as $g) {
                $vers = array_merge([$g['best']], $g['others'] ?? []);
                $keep = [];
                foreach ($vers as $v) {
                    $st = $hidden[$v['key'] ?? '']['state'] ?? '';
                    if ($st === 'archived') $archivedN++;
                    if ($view === 'archived' ? $st === 'archived' : $st === '') $keep[] = $v + ['hidden' => $st];
                }
                if (!$keep) continue;
                $g['best'] = $keep[0]; $g['others'] = array_slice($keep, 1); $g['versions'] = count($keep);
                if (empty($g['audio'])) $g['total'] = array_sum(array_map(fn($v) => (int) ($v['count'] ?? 1), $keep));
                $groups[] = $g;
                $sections[$g['section']] = ($sections[$g['section']] ?? 0) + 1;
                $topics[$g['section'] . '|' . $g['topic']] = ($topics[$g['section'] . '|' . $g['topic']] ?? 0) + 1;
            }
            $r['groups'] = $groups; $r['sections'] = $sections; $r['topics'] = $topics; $r['archived'] = $archivedN; $r['ai_reviewed'] = count($aiRev);
        }
        if (($_GET['export'] ?? '') === 'txt' && !empty($r['grouped'])) {
            // Grouped export: the best version of each group, by topic.
            wi_log('library_export', ['account' => wi_current()['id'] ?? '', 'type' => $type, 'grouped' => true]);
            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $type . '_best_versions_' . date('Y-m-d') . '.txt"');
            echo "\xEF\xBB\xBF";
            $byTopic = [];
            foreach ($r['groups'] as $g) {
                if (!empty($g['audio'])) continue;
                if (($_GET['section'] ?? '') !== '' && ($g['section'] ?? '') !== $_GET['section']) continue;
                if (($_GET['topic'] ?? '') !== '' && $g['topic'] !== $_GET['topic']) continue;
                $byTopic[(isset($g['section']) ? $g['section'] . ' — ' : '') . $g['topic']][] = $g;
            }
            foreach ($byTopic as $topic => $gs) {
                echo "════════ " . $topic . " ════════\r\n\r\n";
                foreach ($gs as $g) echo '◆ ' . $g['title'] . '  (' . $g['versions'] . ' versions · ' . $g['total'] . "×)\r\n" . str_replace("\n", "\r\n", $g['best']['text']) . "\r\n\r\n";
            }
            exit;
        }
        if (($_GET['export'] ?? '') === 'txt' && !empty($r['ok']) && $type !== 'audio') {
            wi_log('library_export', ['account' => wi_current()['id'] ?? '', 'type' => $type]);
            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $type . '_texts_' . date('Y-m-d') . '.txt"');
            echo "\xEF\xBB\xBF";
            foreach ($r['rows'] as $i => $x) echo '— ' . ($i + 1) . ' — (' . $x['count'] . "×)\r\n" . str_replace("\n", "\r\n", $x['text']) . "\r\n\r\n";
            exit;
        }
        wi_json($r);
    }
    if ($api === 'media_queue') {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            wi_check_csrf();
            if (!wi_can_manage(wi_current())) wi_json(['ok' => false, 'error' => 'Not allowed for this number.'], 403);
            wi_log('queue_audio', ['account' => wi_current()['id'] ?? '']);
            wi_json(wi_gateway('/media/queue-audio', []));
        }
        wi_json(wi_gateway('/media/queue'));
    }
