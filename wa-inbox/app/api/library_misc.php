<?php
// WhatsApp Inbox API — library hide, photos, notes, broadcast lists, search. Included by app/api.php inside the request (same scope: $api, $apiPage…).
    /* ---------- Library: archive / delete duplicates (hidden from the library, never from WhatsApp) ---------- */
    if ($api === 'lib_hide') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') wi_json(['ok' => false, 'error' => 'POST only.'], 405);
        wi_check_csrf();
        $acc = wi_current(); if (!$acc || !wi_can_manage($acc)) wi_json(['ok' => false, 'error' => 'Not allowed for this number.'], 403);
        $in = json_decode(wi_input(), true) ?: [];
        $state = in_array($in['state'] ?? '', ['archived', 'deleted', 'restore'], true) ? $in['state'] : 'archived';
        $all = wi_read('lib_hidden.json', []);
        $n = 0;
        foreach (array_slice((array) ($in['keys'] ?? []), 0, 2000) as $k) {
            $k = preg_replace('/[^0-9a-zA-Z:_-]/', '', (string) $k); if ($k === '') continue;
            if ($state === 'restore') unset($all[$acc['id']][$k]); else $all[$acc['id']][$k] = ['state' => $state, 'at' => date('c'), 'by' => (string) $_SESSION['email']];
            $n++;
        }
        wi_write('lib_hidden.json', $all); wi_log('library_' . $state, ['account' => $acc['id'], 'count' => $n]);
        wi_json(['ok' => true, 'changed' => $n]);
    }
    if ($api === 'photo') {
        $jid = preg_replace('/[^0-9A-Za-z@.:_-]/', '', (string) ($_GET['jid'] ?? ''));
        $acc = wi_current();
        if (!$acc) { http_response_code(404); exit; }
        // Served straight from the gateway's photo cache on disk when we have it (fast, cached a week in the browser).
        $wl = dirname(__DIR__, 3) . '/wa-local';
        $pdir = !empty($acc['main']) ? $wl . '/photos' : $wl . '/accounts/' . preg_replace('/[^A-Za-z0-9_-]/', '', (string) $acc['id']) . '/photos';
        $pf = $pdir . '/' . preg_replace('/[^0-9A-Za-z_-]/', '_', $jid) . '.jpg';
        if (is_file($pf) && empty($_GET['dl'])) {
            $etag = '"' . md5($pf . filemtime($pf)) . '"';
            header('ETag: ' . $etag); header('Cache-Control: private, max-age=604800');
            if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) { http_response_code(304); exit; }
            header('Content-Type: image/jpeg'); header('Content-Length: ' . filesize($pf)); readfile($pf); exit;
        }
        $ch = curl_init('http://127.0.0.1:' . (int) $acc['port'] . '/contact/photo?jid=' . rawurlencode($jid));
        curl_setopt_array($ch, [CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $acc['token']], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 2]);
        $img = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE); curl_close($ch);
        if ($code !== 200 || !$img) { http_response_code(404); header('Cache-Control: private, max-age=' . (is_file(substr($pf, 0, -4) . '.none') ? 21600 : 20)); exit('No profile photo.'); }
        header('Content-Type: ' . (preg_match('#^image/(jpeg|png|webp)$#', (string) $type) ? $type : 'image/jpeg')); header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=604800');
        header('Content-Disposition: ' . (!empty($_GET['dl']) ? 'attachment' : 'inline') . '; filename="profile_' . preg_replace('/[^0-9A-Za-z]/', '', explode('@', $jid)[0]) . '.jpg"');
        echo $img; exit;
    }
    if ($api === 'notes') {
        $acc = wi_current(); $jid = preg_replace('/[^0-9A-Za-z@.:_-]/', '', (string) ($_GET['jid'] ?? ''));
        if (!$acc || $jid === '') wi_json(['ok' => false, 'error' => 'Choose a conversation.'], 422);
        $list = wi_read('notes.json', [])[$acc['id']][$jid] ?? [];
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            wi_check_csrf();
            $in = json_decode(wi_input(), true) ?: [];
            $email = (string) $_SESSION['email']; $admin = wi_is_admin(); $text = trim(mb_substr((string) ($in['text'] ?? ''), 0, 1000));
            if (!isset($in['delete']) && $text === '') wi_json(['ok' => false, 'error' => 'Write a note first.'], 422);
            $aidN = (string) $acc['id'];
            $N = wi_update('notes.json', function ($N) use ($aidN, $jid, $in, $email, $admin, $text) { // on the stored notes, under the lock: a colleague's note added meanwhile stays
                $list = (array) ($N[$aidN][$jid] ?? []);
                if (isset($in['delete'])) { $i = (int) $in['delete']; if (isset($list[$i]) && ($admin || strtolower((string) $list[$i]['by']) === strtolower($email))) array_splice($list, $i, 1); }
                else $list[] = ['text' => $text, 'by' => $email, 'time' => date('c')];
                $N[$aidN][$jid] = $list; return $N; });
            $list = $N[$aidN][$jid] ?? [];
            wi_log('note', ['account' => $acc['id'], 'jid' => $jid]);
        }
        wi_json(['ok' => true, 'notes' => $list]);
    }
    if ($api === 'lists' || $api === 'broadcast' || $api === 'broadcast_status' || $api === 'broadcast_control') {
        $acc = wi_current();
        if (!$acc || !wi_can_manage($acc)) wi_json(['ok' => false, 'error' => 'Not allowed for this number.'], 403);
        $all = wi_read('lists.json', []);
        $lists = $all[$acc['id']] ?? [];
        if ($api === 'broadcast_status') wi_json(wi_gateway('/broadcast/status'));
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            wi_check_csrf();
            $in = json_decode(wi_input(), true) ?: [];
            if ($api === 'broadcast_control') wi_json(wi_gateway('/broadcast/control', ['id' => (string) ($in['id'] ?? ''), 'action' => (string) ($in['action'] ?? '')]));
            if ($api === 'lists') {
                if (!empty($in['delete'])) { $lists = array_values(array_filter($lists, fn($l) => $l['id'] !== $in['delete'])); }
                else {
                    $name = trim(mb_substr((string) ($in['name'] ?? ''), 0, 80));
                    $members = [];
                    foreach ((array) ($in['members'] ?? []) as $m) { $j = preg_replace('/[^0-9A-Za-z@.:_-]/', '', (string) ($m['jid'] ?? '')); if ($j !== '') $members[$j] = ['jid' => $j, 'name' => mb_substr((string) ($m['name'] ?? ''), 0, 60)]; }
                    if ($name === '' || !$members) wi_json(['ok' => false, 'error' => 'Give the list a name and pick at least one chat.'], 422);
                    $id = (string) ($in['id'] ?? '') ?: bin2hex(random_bytes(4));
                    $lists = array_values(array_filter($lists, fn($l) => $l['id'] !== $id));
                    $lists[] = ['id' => $id, 'name' => $name, 'members' => array_values(array_slice($members, 0, 300)), 'updated_at' => date('c'), 'by' => (string) $_SESSION['email']];
                }
                $all[$acc['id']] = $lists; wi_write('lists.json', $all); wi_log('broadcast_list', ['account' => $acc['id']]);
                wi_json(['ok' => true, 'lists' => $lists]);
            }
            if ($api === 'broadcast') {
                $list = null; foreach ($lists as $l) if ($l['id'] === ($in['list'] ?? '')) $list = $l;
                if (!$list) wi_json(['ok' => false, 'error' => 'List not found.'], 404);
                $r = wi_gateway('/broadcast/start', ['name' => $list['name'], 'text' => (string) ($in['text'] ?? ''), 'recipients' => $list['members'], 'by' => (string) $_SESSION['email']]);
                wi_log('broadcast_start', ['account' => $acc['id'], 'list' => $list['id'], 'ok' => !empty($r['ok']), 'queued' => $r['queued'] ?? 0]);
                wi_json($r);
            }
        }
        wi_json(['ok' => true, 'lists' => $lists]);
    }
    if ($api === 'search') {
        $q = trim((string) ($_GET['q'] ?? ''));
        if (mb_strlen($q) < 2) wi_json(['ok' => true, 'rows' => []]);
        $r = wi_gateway('/archive?' . http_build_query(['q' => $q, 'limit' => 300, 'months' => 24]));
        if (!empty($r['ok'])) { $c = wi_customers(); foreach ($r['rows'] as &$x) $x['customer'] = $c[$x['phone'] ?? ''] ?? null; unset($x);
            if ($hide = wi_chats_taken_by_others((string) (wi_current()['id'] ?? ''))) $r['rows'] = array_values(array_filter($r['rows'], fn($x) => !isset($hide[$x['jid'] ?? '']))); }
        wi_json($r);
    }
