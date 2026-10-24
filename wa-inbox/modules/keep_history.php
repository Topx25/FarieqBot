<?php
// WhatsApp Inbox — keeping a number's history. Nothing of a number is ever deleted: a removed number becomes an archived account
// (read only, no gateway), and when a different WhatsApp number is linked on an account the user chooses to merge the old chats
// with the new number or keep them apart (the old number then shows as its own read-only history entry).
// The archive files are never moved or rewritten: "apart" is a time line (history.json in the gateway's data folder).

/** The archive folder of an account (the number's own messages, written by its gateway). */
function wf_hist_dir(array $acc): string {
    $id = (string) ($acc['hist_of'] ?? $acc['id']); $root = dirname(__DIR__, 2) . '/wa-local';
    return $id === 'main' ? $root . '/archive' : $root . '/accounts/' . preg_replace('/[^a-z0-9_-]/', '', strtolower($id)) . '/archive';
}
function wf_hist_data_dir(string $aid): string { $root = dirname(__DIR__, 2) . '/wa-local'; return $aid === 'main' ? $root : $root . '/accounts/' . preg_replace('/[^a-z0-9_-]/', '', strtolower($aid)); }
/** The phone number a gateway is signed in with ('' when not connected). */
function wf_hist_phone(array $st): string { $id = (string) ($st['account']['id'] ?? ''); return preg_replace('/\D/', '', explode('@', explode(':', $id)[0])[0]); }
function wf_hist_get(string $aid): array { return (array) (wi_read('history.json', [])[$aid] ?? []) + ['phone' => '', 'pending' => null, 'mode' => '', 'olds' => []]; }
function wf_hist_set(string $aid, array $set): void { wi_update('history.json', function ($H) use ($aid, $set) { $H[$aid] = $set + (array) ($H[$aid] ?? []); return $H; }); }

/** Every minute (cron): the number each account is signed in with. A different number than before → the user is asked (merged until they answer). */
function wf_hist_scan(): void {
    foreach (wi_accounts() as $acc) {
        if (empty($acc['enabled']) || !empty($acc['archived']) || ($acc['channel'] ?? '') !== '' && ($acc['channel'] ?? '') !== 'wa') continue;
        $st = wi_gateway('/status', null, $acc); if (empty($st['connected'])) continue;
        $ph = wf_hist_phone($st); if ($ph === '') continue;
        wf_hist_seen((string) $acc['id'], $ph);
    }
}
/** Records the number an account is on; returns true when it changed (a question is waiting for the user). */
function wf_hist_seen(string $aid, string $phone, ?int $now = null): bool {
    $h = wf_hist_get($aid); $set = wf_hist_next($h, $phone, $now ?? time()); if ($set === null) return false;
    wf_hist_set($aid, $set); if (empty($set['pending'])) return false;
    wi_log('number_changed', ['account' => $aid, 'old' => $h['phone'], 'new' => $phone]);
    return true;
}
/** What to store when an account is seen on $phone: null = nothing changed; a first number is only recorded; another number waits for the user's choice. */
function wf_hist_next(array $h, string $phone, int $now): ?array {
    if ($phone === '' || ($h['phone'] ?? '') === $phone) return null;
    if (($h['phone'] ?? '') === '') return ['phone' => $phone, 'since' => date('c', $now)];
    // the archive's own time format, so times compare as text
    return ['phone' => $phone, 'since' => date('c', $now), 'pending' => ['old' => (string) $h['phone'], 'new' => $phone, 'at' => gmdate('Y-m-d\\TH:i:s.000\\Z', $now)]];
}
/** The user's answer: 'merge' (one history under the account) or 'separate' (the old number becomes a read-only history entry). */
function wf_hist_choose(string $aid, string $mode): string {
    $h = wf_hist_get($aid); $p = $h['pending']; if (!$p) return 'Nothing to choose.';
    if (!in_array($mode, ['merge', 'separate'], true)) return 'Choose merge or keep separate.';
    $set = ['pending' => null, 'mode' => $mode, 'chosen_at' => date('c')];
    if ($mode === 'separate') {
        $all = wi_accounts(); $acc = $all[$aid] ?? null; if (!$acc) return 'Unknown account.';
        // the gateway shows only what came after the change; the old number keeps everything before it
        @file_put_contents(wf_hist_data_dir($aid) . '/history.json', json_encode(['from' => $p['at']]));
        $oid = substr($aid, 0, 24) . '_h' . substr(md5($p['old'] . $p['at']), 0, 6);
        $all[$oid] = ['id' => $oid, 'name' => (string) $acc['name'] . ' (+' . $p['old'] . ')', 'port' => 0, 'token' => '', 'enabled' => false, 'archived' => true, 'archived_at' => date('c'),
            'hist_of' => $aid, 'hist_until' => $p['at'], 'hist_phone' => $p['old'], 'owner' => (string) ($acc['owner'] ?? ''), 'main' => false];
        wi_save_accounts($all);
        $set['olds'] = array_merge((array) $h['olds'], [['phone' => $p['old'], 'until' => $p['at'], 'account' => $oid]]);
    }
    wf_hist_set($aid, $set); wi_log('history_choice', ['account' => $aid, 'mode' => $mode]);
    return '';
}

/* ---------- reading an archived number (no gateway: its archive files, read only) ---------- */
/** Message rows of an archived account, newest first. opts: jid, limit, months. */
function wf_hist_rows(array $acc, array $o = []): array {
    $dir = wf_hist_dir($acc); $until = (string) ($acc['hist_until'] ?? ''); $jid = (string) ($o['jid'] ?? '');
    $files = glob($dir . '/[0-9][0-9][0-9][0-9]-[0-9][0-9].jsonl') ?: []; sort($files); $files = array_slice($files, -max(1, (int) ($o['months'] ?? 36)));
    $rows = []; $gone = [];
    foreach ($files as $f) { $fh = @fopen($f, 'rb'); if (!$fh) continue;
        while (($line = fgets($fh)) !== false) {
            if ($jid !== '' && strpos($line, '"jid":' . json_encode($jid)) === false) continue;
            $r = json_decode($line, true); if (!is_array($r)) continue;
            if ($until !== '' && (string) ($r['time'] ?? '') >= $until) continue;
            $d = (string) ($r['dir'] ?? '');
            if (in_array($d, ['deleted', 'hidden'], true)) { $gone[(string) ($r['id'] ?? '')] = 1; continue; }
            if (!in_array($d, ['in', 'out'], true)) continue;
            $rows[] = $r; }
        fclose($fh); }
    $rows = array_values(array_filter($rows, fn($r) => empty($r['id']) || !isset($gone[$r['id']])));
    usort($rows, fn($a, $b) => strcmp((string) $b['time'], (string) $a['time']));
    return array_slice($rows, 0, max(1, (int) ($o['limit'] ?? 5000)));
}
/** The gateway calls of an archived account, answered from its files. Nothing is ever sent from it. */
function wf_hist_gateway(string $path, ?array $post, array $acc): array {
    $p = (string) parse_url($path, PHP_URL_PATH); parse_str((string) parse_url($path, PHP_URL_QUERY), $q);
    if ($post !== null) return ['ok' => false, 'error' => 'This number is archived: its history is kept read only and nothing is sent from it.'];
    if ($p === '/status' || $p === '/health') return ['ok' => true, 'connected' => false, 'archived' => true, 'account' => null, 'last_error' => ''];
    if ($p === '/archive/thread') { $rows = array_reverse(wf_hist_rows($acc, ['jid' => (string) ($q['jid'] ?? ''), 'limit' => min(60000, (int) ($q['limit'] ?? 5000) ?: 5000), 'months' => 24])); return ['ok' => true, 'rows' => $rows, 'name' => '']; }
    if ($p === '/archive/chats') {
        $chats = [];
        foreach (array_reverse(wf_hist_rows($acc, ['limit' => 300000, 'months' => (int) ($q['months'] ?? 36) ?: 36])) as $r) {
            $k = (string) (($r['jid'] ?? '') ?: ($r['phone'] ?? '')); if ($k === '') continue;
            $c = $chats[$k] ?? ['jid' => (string) ($r['jid'] ?? ''), 'phone' => '', 'name' => '', 'group' => !empty($r['group']), 'count' => 0, 'in' => 0, 'out' => 0, 'last' => null];
            if (!empty($r['phone']) && $c['phone'] === '') $c['phone'] = (string) $r['phone'];
            if (!empty($r['name']) && $r['dir'] === 'in' && empty($r['group'])) $c['name'] = (string) $r['name'];
            $c['count']++; $c[$r['dir']]++; $c['last'] = ['time' => $r['time'], 'dir' => $r['dir'], 'text' => (string) ($r['text'] ?? ''), 'kind' => (string) ($r['kind'] ?? 'text')]; $chats[$k] = $c; }
        $chats = array_values($chats); usort($chats, fn($a, $b) => strcmp((string) ($b['last']['time'] ?? ''), (string) ($a['last']['time'] ?? '')));
        return ['ok' => true, 'chats' => $chats];
    }
    if ($p === '/archive' || $p === '/archive/search') { $s = mb_strtolower((string) ($q['q'] ?? '')); $rows = wf_hist_rows($acc, ['limit' => 300000]);
        if ($s !== '') $rows = array_values(array_filter($rows, fn($r) => str_contains(mb_strtolower((string) ($r['text'] ?? '')), $s)));
        return ['ok' => true, 'rows' => array_slice($rows, 0, min(5000, (int) ($q['limit'] ?? 300) ?: 300))]; }
    return ['ok' => false, 'error' => 'This number is archived: only its messages can be read.'];
}

/** The question shown on the account's pages while a number change waits for an answer. */
function wf_hist_banner(?array $acc): string {
    if (!$acc || !wi_can_manage($acc)) return ''; $p = wf_hist_get((string) $acc['id'])['pending']; if (!$p) return '';
    $f = function (string $mode, string $label, string $cls) use ($acc) { return '<form method="post" class="inline"><input type="hidden" name="_csrf" value="' . wi_e(wi_csrf()) . '"><input type="hidden" name="do" value="history_choice"><input type="hidden" name="acc" value="' . wi_e((string) $acc['id']) . '"><input type="hidden" name="mode" value="' . $mode . '"><button class="btn ' . $cls . ' sm">' . wi_e(wi_t($label)) . '</button></form>'; };
    return '<div class="card pad hist-ask"><b>📱 ' . wi_e(wi_t('A new number was linked on this account')) . '</b> <span dir="ltr">+' . wi_e($p['old']) . ' → +' . wi_e($p['new']) . '</span><p class="muted small">'
        . wi_e(wi_t('The old chats are kept either way. Merge them with the new number, or keep them separate as a read-only history of the old number?')) . '</p><div class="row">'
        . $f('merge', 'Merge the old chats into the new number', 'primary') . $f('separate', 'Keep them separate', 'ghost') . '</div></div>';
}
