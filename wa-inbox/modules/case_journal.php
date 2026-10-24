<?php
// WhatsApp Inbox — case_journal: the lasting record of every case — who of the team did what and when, where,
// and every file sent by the person or to them (a link to the file already kept, never a second copy).
// Filled every few minutes from the new messages only; the follow-up trail, the reports and the bot read it.

function wf_journal_db(): ?mysqli {
    static $ok = null; $m = wi_db(); if (!$m) return null;
    if ($ok === null) $ok = (bool) $m->query("CREATE TABLE IF NOT EXISTS wi_journal (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, base VARCHAR(40) NOT NULL, ph VARCHAR(20) NOT NULL, t INT UNSIGNED NOT NULL,
        who VARCHAR(40) NOT NULL DEFAULT '', place VARCHAR(80) NOT NULL DEFAULT '', kind VARCHAR(12) NOT NULL DEFAULT '', how VARCHAR(8) NOT NULL DEFAULT 'sure', mid VARCHAR(64) NOT NULL DEFAULT '', att VARCHAR(255) NOT NULL DEFAULT '', txt VARCHAR(400) NOT NULL DEFAULT '',
        UNIQUE KEY one (base, mid, ph), KEY bp (base, ph, t)) DEFAULT CHARSET=utf8mb4");
    return $ok ? $m : null;
}
/** Every ID → case phone of the open and closed cases (mobile numbers and WhatsApp's long IDs). */
function wf_journal_ids(array $acc, array $chats): array {
    $aid = (string) $acc['id']; $own = [];
    foreach (wf_cases($aid) as $ph => $c) { if (!is_array($c)) continue; $own[(string) $ph] = (string) $ph; foreach ((array) ($c['lids'] ?? []) as $l) if (($d = preg_replace('/\D/', '', (string) $l)) !== '') $own[$d] = (string) $ph; }
    foreach ($chats as $ch) { $j = (string) ($ch['jid'] ?? ''); if (!empty($ch['group']) || !str_ends_with($j, '@lid')) continue; $p = wf_phone((string) ($ch['phone'] ?? '')) ?: wf_phone((string) (wi_contact($j, $acc)['phone'] ?? '')); if ($p !== '' && isset($own[$p])) $own[explode('@', $j)[0]] = $p; }
    return $own;
}
/** Adds the new messages of the chats that moved since the last run. Returns how many lines were added. */
function wf_journal_ingest(array $acc, int $backDays = 1): int {
    $m = wf_journal_db(); if (!$m) return 0; $aid = (string) $acc['id'];
    $ck = (array) (wi_read('journal_ckpt.json', [])[$aid] ?? []); $chats = (array) (wi_gateway('/archive/chats?months=1', null, $acc)['chats'] ?? []);
    $own = wf_journal_ids($acc, $chats); if (!$own) return 0;
    $who = fn($v) => $own[preg_replace('/\D/', '', explode(':', explode('@', (string) $v)[0])[0])] ?? null;
    $st = $m->prepare("INSERT INTO wi_journal (base, ph, t, who, place, kind, how, mid, att, txt) VALUES (?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE att = IF(att = '', VALUES(att), att)"); $n = 0; // a file downloaded after its message gets its link on the next pass
    $put = function (string $ph, int $t, string $w, string $place, string $kind, array $msg) use ($st, $aid, &$n) { $mid = (string) ($msg['id'] ?? ''); if ($mid === '') return; $att = mb_substr((string) ($msg['attachment'] ?? ''), 0, 255); $how = 'sure';
        $tx = mb_substr(preg_replace('/\s+/u', ' ', trim((string) ($msg['text'] ?? ''))) ?: '[' . (string) ($msg['kind'] ?? '') . ']', 0, 400); $st->bind_param('ssisssssss', $aid, $ph, $t, $w, $place, $kind, $how, $mid, $att, $tx); $st->execute(); $n += max(0, $st->affected_rows); };
    $me = preg_replace('/\D/', '', explode(':', (string) (wi_gateway('/status', null, $acc)['account']['id'] ?? ''))[0]);
    foreach ($chats as $ch) { $j = (string) ($ch['jid'] ?? ''); $grp = !empty($ch['group']); $last = (string) ($ch['last']['time'] ?? '');
        if ($last === '' || (!$grp && $who($j) === null) || (($ck[$j] ?? '') >= $last && strtotime($last) < time() - 3600)) continue; // quiet chats are skipped; the last hour is read again for late file links
        $from = isset($ck[$j]) ? gmdate('Y-m-d\TH:i:s', strtotime((string) $ck[$j]) - 3600) : gmdate('Y-m-d\TH:i:s', time() - $backDays * 86400); $team = $grp ? wf_team($acc, [$j => []]) : [];
        foreach ((array) (wi_gateway('/archive/thread?limit=400&jid=' . rawurlencode($j), null, $acc)['rows'] ?? []) as $r) { if ((string) ($r['time'] ?? '') <= $from) continue; $t = strtotime((string) $r['time']); $out = ($r['dir'] ?? '') === 'out';
            $isFile = (string) ($r['attachment'] ?? '') !== '' || !in_array((string) ($r['kind'] ?? 'text'), ['text', ''], true);
            if (!$grp) { $ph = $who($j); $w = !$out ? 'person' : ((($r['via'] ?? '') === 'bot') ? 'bot' : 'owner'); $put($ph, $t, $w, 'private', $isFile ? 'file' : ($out ? 'answer' : 'msg'), $r); continue; }
            $w = $out ? 'owner' : wf_phone((string) ($r['from'] ?? '')); if (!$out && ($ph = $who($w)) !== null) { $put($ph, $t, 'person', $j, $isFile ? 'file' : 'msg', $r); continue; }
            if (!$out && !isset($team[$w])) continue; if ($out && ($r['via'] ?? '') === 'bot') $w = 'bot';
            $to = []; if (($p = $who($r['quote_from'] ?? '')) !== null) $to[$p] = 1; foreach ((array) ($r['mentions'] ?? []) as $x) if (($p = $who($x)) !== null) $to[$p] = 1;
            if (preg_match_all('/@(\d{8,})/', (string) ($r['text'] ?? ''), $mm)) foreach ($mm[1] as $x) if (($p = $who($x)) !== null) $to[$p] = 1;
            foreach (array_keys($to) as $p) $put($p, $t, $w, $j, $isFile ? 'file' : 'answer', $r); }
        $ck[$j] = $last; }
    wi_update('journal_ckpt.json', function ($K) use ($aid, $ck) { $K[$aid] = $ck; return $K; });
    return $n;
}
/** A case's journal, oldest first (at most $limit, newest kept). */
function wf_journal(string $aid, string $ph, int $limit = 400, string $kind = ''): array {
    $m = wf_journal_db(); if (!$m) return []; $q = "SELECT t, who, place, kind, how, mid, att, txt FROM wi_journal WHERE base=? AND ph=?" . ($kind !== '' ? " AND kind=?" : '') . " ORDER BY t DESC LIMIT ?";
    $st = $m->prepare($q); if ($kind !== '') $st->bind_param('sssi', $aid, $ph, $kind, $limit); else $st->bind_param('ssi', $aid, $ph, $limit); $st->execute(); $r = $st->get_result(); $o = [];
    while ($x = $r->fetch_assoc()) $o[] = $x; return array_reverse($o);
}
/** One line for the bot's prompt: who follows this person, the team's last step and the last file sent to them. */
function wf_journal_brief(string $aid, string $ph, array $names = []): string {
    $J = wf_journal($aid, $ph, 120); if (!$J) return '';
    if (!$names) { $tf = WI_DIR . '/team_watch_' . preg_replace('/\W/', '', $aid) . '.json'; if (is_file($tf)) $names = (array) (json_decode((string) file_get_contents($tf), true)['names'] ?? []); } $lastTeam = null; $lastFile = null; $who = []; // the team members' names come from the team page's last read
    foreach ($J as $e) { if (in_array($e['who'], ['person', 'bot'], true)) continue; $lastTeam = $e; $who[$e['who']] = ($who[$e['who']] ?? 0) + 1; if ($e['kind'] === 'file') $lastFile = $e; }
    if (!$lastTeam) return ''; arsort($who); $fw = (string) array_key_first($who);
    $nm = fn($w) => $w === 'owner' ? 'the number\'s owner' : ($names[$w] ?? ('a team member (+' . $w . ')'));
    return 'FOLLOW-UP RECORD: followed by ' . $nm($fw) . '; the team\'s last step ' . date('Y-m-d', (int) $lastTeam['t']) . ' by ' . $nm((string) $lastTeam['who']) . ': "' . mb_substr((string) $lastTeam['txt'], 0, 160) . '"'
        . ($lastFile ? '; last file sent to them ' . date('Y-m-d', (int) $lastFile['t']) . ': ' . mb_substr((string) $lastFile['txt'], 0, 80) : '') . '. Never contradict what the team told them; build on it.';
}
function wf_api_case_journal(string $api): void {
    if ($api !== 'case_files') return;
    $acc = wi_current(); if (!$acc) wi_json(['ok' => false, 'error' => 'No number chosen.'], 422); $aid = (string) $acc['id']; $ph = wf_phone((string) ($_GET['phone'] ?? ''));
    $c = wf_case_get($aid, $ph); if ($c && wf_case_is_private($c) && !wf_case_private_ok()) wi_json(['ok' => false, 'error' => 'Case not found.']);
    $f = array_values(array_filter(wf_journal($aid, $ph, 300, 'file'), fn($x) => $x['att'] !== ''));
    wi_json(['ok' => true, 'files' => array_map(fn($x) => ['t' => (int) $x['t'], 'who' => $x['who'], 'place' => $x['place'], 'att' => $x['att'], 'txt' => $x['txt']], array_reverse($f))]);
}
