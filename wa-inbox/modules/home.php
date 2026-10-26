<?php
// WhatsApp Inbox — home (split out of features.php in v3.8, code unchanged)

/** API calls of this area (moved unchanged from the old single switch). */
function wf_api_home(string $api): void {
    switch ($api) {
    /* ----- Home page: everything that needs attention, in one call ----- */
    case 'home': {
        $aid = wf_aid(); $me = wi_me(); $acc = [];
        if (!wi_cases_only()) foreach (wi_my_accounts() as $a) { $h = wi_gateway('/health', null, $a); $acc[] = ['id' => $a['id'], 'name' => $a['name'], 'connected' => !empty($h['connected']), 'bot' => wf_bot_run_cfg((string) $a['id'])['mode'], 'current' => $a['id'] === $aid]; }
        $dr = array_values(wi_read('bot_drafts.json', [])[$aid] ?? []); usort($dr, fn($x, $y) => strcmp($y['at'] ?? '', $x['at'] ?? ''));
        $rem = array_values(array_filter(wi_read('reminders.json', []), fn($r) => ($r['acc'] ?? '') === $aid && empty($r['done'])));
        usort($rem, fn($x, $y) => strcmp($x['at'] ?? '', $y['at'] ?? ''));
        $due = array_values(array_filter($rem, fn($r) => strtotime((string) ($r['at'] ?? '')) <= time()));
        $meet = []; if (wi_page_perm('meetings') !== '') foreach (wf_meetings() as $m) if (($m['status'] ?? '') === 'open' && wf_meet_acc_ok($m) && (wi_is_super() || isset(wi_my_accounts()[$m['acc'] ?? '']) || wf_meet_can_host($m))) { $p = wf_meet_public($m); $meet[] = ['title' => $m['title'], 'pending' => $p['pending'], 'joined' => $p['joined_now'], 'can_host' => wf_meet_can_host($m)]; }
        $ai = wi_ai_tasks(); $today = date('Y-m-d'); $aiN = 0; $aiTok = 0;
        $myA = wi_my_accounts(); foreach ($ai as $x) if (substr((string) ($x['at'] ?? ''), 0, 10) === $today && isset($myA[$x['account'] ?? ''])) { $aiN++; $aiTok += ($x['tokens_in'] ?? 0) + ($x['tokens_out'] ?? 0); }
        $cases = 0; try { if (wi_page_perm('cases') !== '') $cases = count(wf_cases($aid)); } catch (Throwable $e) {}
        $log = wi_bot_log($aid); $botToday = count(array_filter($log, fn($x) => substr((string) ($x['at'] ?? ''), 0, 10) === $today && ($x['result'] ?? '') === 'sent'));
        $pages = []; foreach (wi_page_names() as $k => $n) $pages[$k] = wi_page_perm($k);
        wi_json(['ok' => true, 'name' => ($me['name'] ?? '') ?: ($me['email'] ?? ''), 'accounts' => $acc,
            'drafts' => ['count' => count($dr), 'items' => array_map(fn($d) => ['name' => $d['name'] ?? '', 'q' => mb_substr((string) ($d['q'] ?? ''), 0, 90), 'at' => $d['at'] ?? '', 'jid' => $d['jid'] ?? ''], array_slice($dr, 0, 4))],
            'reminders' => ['due' => count($due), 'total' => count($rem), 'items' => array_map(fn($r) => ['name' => $r['name'] ?? '', 'note' => $r['note'] ?? '', 'at' => $r['at'] ?? '', 'jid' => $r['jid'] ?? ''], array_slice($rem, 0, 5))],
            'meetings' => $meet, 'cases' => $cases, 'bot_today' => $botToday, 'ai' => ['tasks' => $aiN, 'tokens' => $aiTok], 'pages' => $pages,
            'backup' => wi_is_admin() ? (wf_drive_cfg()['last'] ?? null) : null, 'google' => wf_home_google()]);
    }

    }
}

/** The Google connection on the home page: the system's for an admin, the panel owner's own for a client (null for a team user). */
function wf_home_google(): ?array {
    if (!function_exists('wf_drive_cfg')) return null;
    $me = wi_me(); $ws = wf_google_ws(); $own = $ws !== '' && $ws !== '-' && strtolower((string) ($me['email'] ?? '')) === $ws;
    if (!$own && !($ws === '' && wi_is_admin())) return null;
    $c = wf_drive_cfg(); if ($own && empty($c['client_id'])) return null;
    return ['connected' => !empty($c['refresh_token']), 'broken' => !empty($c['broken']), 'email' => (string) ($c['email'] ?? ''), 'link' => $own ? '?p=me' : '?p=backup'];
}
