<?php
// WhatsApp Inbox API — audio library. Included by app/api.php inside the request (same scope: $api, $apiPage…).
    /* ---------- Audio library: one entry per recording, renamed / categorised / archived ---------- */
    if ($api === 'files') {
        $r = wi_gateway('/library?' . http_build_query(['type' => 'files', 'q' => (string) ($_GET['q'] ?? '')]));
        if (!empty($r['ok'])) { $c = wi_customers(); foreach ($r['items'] as &$x) $x['customer'] = $c[$x['phone'] ?? ''] ?? null; unset($x); }
        wi_json($r);
    }
    if ($api === 'audio_lib') {
        function wi_sender(array $b, array $c): string {
            if (($b['dir'] ?? '') === 'out') return wi_t('You');
            if (!empty($b['group'])) { $f = (string) ($b['from'] ?? ''); return ($c[$f]['name'] ?? '') ?: (($b['from_name'] ?? '') ?: ($f !== '' ? '+' . $f : '')); }
            return ($c[$b['phone'] ?? '']['name'] ?? '') ?: ($b['name'] ?? '');
        }
        $acc = wi_current(); $aid = $acc['id'] ?? '';
        $meta = wi_read('audio_meta.json', [])[$aid] ?? []; $mediaMeta = (array) (wi_read('media_meta.json', [])[$aid] ?? []); // name & shortcut live with the file
        $r = wi_gateway('/library?type=sections&min=999999');
        if (empty($r['ok'])) wi_json($r);
        $out = []; $c = wi_customers();
        foreach ($r['groups'] as $g) {
            if (empty($g['audio'])) continue;
            $key = $g['sig'] !== '' ? 'f:' . $g['sig'] : 'i:' . ($g['best']['id'] ?? '');
            $m = $meta[$key] ?? [];
            $items = array_merge([$g['best']], $g['others'] ?? []);
            $times = array_filter(array_map(fn($x) => $x['time'] ?? '', $items));
            $out[] = ['key' => $key, 'title' => ($m['title'] ?? '') !== '' ? $m['title'] : (string) ($mediaMeta[current(array_filter(array_column($items, 'attachment'))) ?: '']['title'] ?? ''), 'code' => (string) ($mediaMeta[current(array_filter(array_column($items, 'attachment'))) ?: '']['code'] ?? ''), 'auto_title' => $g['title'], 'category' => $m['category'] ?? (!empty($g['tagged']) ? (string) (wf_pack((string) (wi_current()['id'] ?? ''))['audio_tag_category'] ?? '') : ''), 'archived' => !empty($m['archived']),
                'note' => $m['note'] ?? '', 'copies' => count($items), 'chats' => $g['chats'], 'seconds' => $g['best']['seconds'] ?? null, 'size' => $g['best']['size'] ?? null,
                'first' => $times ? min($times) : null, 'last' => $times ? max($times) : null, 'attachment' => current(array_filter(array_column($items, 'attachment'))) ?: '',
                'fetchable' => (bool) array_filter(array_column($items, 'fetchable')), 'fetch_id' => (string) (current(array_filter($items, fn($x) => !empty($x['fetchable'])))['id'] ?? ''),
                'jid' => $g['best']['jid'] ?? '', 'from' => wi_sender($g['best'], $c), 'group' => !empty($g['best']['group']) ? ($g['best']['name'] ?? '') : '', 'kind' => $g['best']['kind'] ?? 'audio'];
        }
        wi_json(['ok' => true, 'items' => $out]);
    }
    if ($api === 'audio_cats') { // the categories of this number's audio library: read, and save the whole list
        $acc = wi_current(); $aid = (string) ($acc['id'] ?? '');
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            wi_check_csrf(); if (!$acc || !wi_can_manage($acc)) wi_json(['ok' => false, 'error' => 'Not allowed for this number.'], 403);
            $in = json_decode(wi_input(), true) ?: []; $lines = []; $seen = [];
            foreach (array_slice((array) ($in['cats'] ?? []), 0, 30) as $c) {
                $label = trim(str_replace(['|', "\n", "\r"], ' ', mb_substr((string) ($c['label'] ?? ''), 0, 30))); if ($label === '') continue;
                $key = preg_match('/^[a-z_]{2,20}$/', (string) ($c['key'] ?? '')) ? (string) $c['key'] : ''; while ($key === '' || isset($seen[$key])) { $key = ''; for ($i = 0; $i < 8; $i++) $key .= chr(97 + random_int(0, 25)); }
                $seen[$key] = 1; $icon = trim(str_replace(['|', "\n"], ' ', mb_substr((string) ($c['icon'] ?? ''), 0, 4))) ?: '📁'; $col = preg_match('/^#[0-9a-f]{6}$/i', (string) ($c['color'] ?? '')) ? $c['color'] : '#64748b';
                $lines[] = $key . ' | ' . $icon . ' | ' . $label . ' | ' . $col; }
            if (!$lines) wi_json(['ok' => false, 'error' => 'Keep at least one category.'], 422);
            wi_update('bot.json', function ($B) use ($aid, $lines) { $B[$aid]['pack'] = array_merge(wf_pack($aid), (array) ($B[$aid]['pack'] ?? []), ['audio_categories' => implode("\n", $lines)]); return $B; });
            wi_log('audio_cats', ['account' => $aid, 'count' => count($lines)]); wi_json(['ok' => true]);
        }
        wi_json(['ok' => true, 'cats' => wf_audio_cats($aid)]);
    }
    if ($api === 'audio_meta') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') wi_json(['ok' => false, 'error' => 'POST only.'], 405);
        wi_check_csrf();
        $acc = wi_current(); if (!$acc || !wi_can_manage($acc)) wi_json(['ok' => false, 'error' => 'Not allowed for this number.'], 403);
        $in = json_decode(wi_input(), true) ?: [];
        $all = wi_read('audio_meta.json', []);
        $keys = array_slice(array_filter(array_map(fn($k) => preg_replace('/[^0-9A-Za-z:._\/;-]/', '', (string) $k), (array) ($in['keys'] ?? [$in['key'] ?? '']))), 0, 2000);
        foreach ($keys as $k) {
            $m = $all[$acc['id']][$k] ?? [];
            if (array_key_exists('title', $in)) $m['title'] = trim(mb_substr(str_replace(['\\', '/', ':', '*', '?', '"', '<', '>', '|'], ' ', (string) $in['title']), 0, 120));
            if (array_key_exists('category', $in)) $m['category'] = in_array($in['category'], array_merge(array_column(wf_audio_cats(), 'key'), ['']), true) ? $in['category'] : '';
            if (array_key_exists('archived', $in)) $m['archived'] = !empty($in['archived']);
            if (array_key_exists('note', $in)) $m['note'] = trim(mb_substr((string) $in['note'], 0, 300));
            $m['by'] = (string) $_SESSION['email']; $m['at'] = date('c');
            $all[$acc['id']][$k] = $m;
        }
        wi_write('audio_meta.json', $all); wi_log('audio_meta', ['account' => $acc['id'], 'count' => count($keys)]);
        wi_json(['ok' => true, 'changed' => count($keys)]);
    }
    if ($api === 'fetch_media') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') wi_json(['ok' => false, 'error' => 'POST only.'], 405);
        wi_check_csrf();
        @set_time_limit(300);
        $in = json_decode(wi_input(), true) ?: [];
        $acc = wi_current();
        $ch = curl_init('http://' . wi_gw_host() . ':' . (int) $acc['port'] . '/media/fetch'); $wiAuth = 'Authorization: Bearer ' . $acc['token'];
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(array_filter(['id' => (string) ($in['id'] ?? ''), 'jid' => (string) ($in['jid'] ?? '')])), CURLOPT_HTTPHEADER => ['Content-Type: application/json', $wiAuth], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 240]);
        $r = json_decode((string) curl_exec($ch), true); curl_close($ch);
        wi_json(is_array($r) ? $r : ['ok' => false, 'error' => 'Gateway did not respond.']);
    }
