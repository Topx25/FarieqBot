<?php
// Farieq add-on — Instagram: direct messages of the Instagram business account linked to a connected Facebook page,
// read every minute into that page's inbox (the same conversations list, marked as Instagram) and answered by the bot like Messenger.
// Off unless General settings → addon_instagram is on. Uses the Facebook module's connection as it is; it never changes the Facebook files.

function wf_ig_on(): bool { return (bool) wi_sys('addon_instagram') && function_exists('wf_fb_graph'); }
/** The Instagram business account id linked to the page of this Facebook account ('' when none), looked up once a day. */
function wf_ig_id(string $aid): string {
    $S = (array) (wi_read('ig.json', [])[$aid] ?? []);
    if (($S['checked'] ?? 0) > time() - 86400) return (string) ($S['ig_id'] ?? '');
    $r = wf_fb_graph($aid, 'GET', 'me', ['fields' => 'instagram_business_account{id,username}']);
    $id = (string) ($r['instagram_business_account']['id'] ?? ''); $un = (string) ($r['instagram_business_account']['username'] ?? '');
    wi_update('ig.json', function ($I) use ($aid, $id, $un, $r) { $I[$aid] = ['ig_id' => $id, 'username' => $un, 'checked' => time(), 'error' => empty($r['ok']) ? (string) ($r['error'] ?? '') : ''] + (array) ($I[$aid] ?? []); return $I; });
    return $id;
}
/** New Instagram direct messages read from the page (every minute). Saved like Messenger rows, with source "ig". Returns how many new incoming ones. */
function wf_ig_sync(array $acc): int {
    $aid = (string) $acc['id']; if (!wf_ig_on() || !wf_fb_is($acc) || wf_fb_token($aid) === '') return 0;
    $ig = wf_ig_id($aid); if ($ig === '') return 0;
    $S = (array) (wi_read('ig.json', [])[$aid] ?? []); $since = (int) ($S['sync_at'] ?? 0) ?: time() - 86400;
    $r = wf_fb_graph($aid, 'GET', 'me/conversations', ['platform' => 'instagram', 'fields' => 'updated_time,participants,messages.limit(25){id,message,from,created_time,attachments{mime_type,name,image_data,video_data,file_url}}', 'limit' => 15]);
    if (empty($r['ok'])) { wi_update('ig.json', function ($I) use ($aid, $r) { $I[$aid]['error'] = (string) ($r['error'] ?? ''); return $I; }); return 0; }
    $new = 0; $newest = $since;
    foreach ((array) ($r['data'] ?? []) as $conv) {
        $ut = strtotime((string) ($conv['updated_time'] ?? '')) ?: 0; if ($ut < $since - 120) continue; $newest = max($newest, $ut);
        $who = ''; $uname = ''; foreach ((array) ($conv['participants']['data'] ?? []) as $pp) if ((string) ($pp['id'] ?? '') !== $ig) { $who = (string) $pp['id']; $uname = (string) ($pp['username'] ?? $pp['name'] ?? ''); }
        if ($who === '') continue; $jid = $who . '@fb';
        $known = []; foreach (wf_fb_rows($aid, ['jid' => $jid, 'limit' => 200]) as $o) if (!empty($o['id'])) $known[$o['id']] = 1;
        foreach (array_reverse((array) ($conv['messages']['data'] ?? [])) as $m) {
            $mid = (string) ($m['id'] ?? ''); if ($mid === '' || isset($known[$mid])) continue; $ts = strtotime((string) ($m['created_time'] ?? '')) ?: time(); if ($ts < $since - 120) continue;
            $out = (string) ($m['from']['id'] ?? '') === $ig; $text = trim((string) ($m['message'] ?? ''));
            $row = ['dir' => $out ? 'out' : 'in', 'jid' => $jid, 'phone' => $who, 'id' => $mid, 'ts' => $ts, 'src' => 'ig'] + ($out ? ['via' => 'page'] : ['name' => '📷 ' . ($uname !== '' ? '@' . $uname : (string) ($m['from']['username'] ?? 'Instagram'))]);
            $att = (array) ($m['attachments']['data'][0] ?? []);
            if ($att) { $mime = (string) ($att['mime_type'] ?? ''); $url = (string) ($att['image_data']['url'] ?? $att['video_data']['url'] ?? $att['file_url'] ?? ''); $k = strpos($mime, 'image/') === 0 ? 'image' : (strpos($mime, 'video/') === 0 ? 'video' : (strpos($mime, 'audio/') === 0 ? 'voice' : 'document'));
                wf_fb_append($aid, $row + ['kind' => $k, 'text' => $text, 'attachment' => wf_fb_save_media($aid, $url, $mid, $k === 'voice' ? 'audio' : $k), 'file_name' => (string) ($att['name'] ?? '')]); }
            elseif ($text !== '') wf_fb_append($aid, $row + ['kind' => 'text', 'text' => $text]); else continue;
            if (!$out) $new++; } }
    wi_update('ig.json', function ($I) use ($aid, $newest, $new) { $I[$aid]['sync_at'] = $newest; $I[$aid]['error'] = ''; if ($new > 0) $I[$aid]['in_at'] = gmdate('c'); return $I; });
    return $new;
}
/** Every minute (cron): each connected page's Instagram is caught up and the bot answers what came in. */
function wf_ig_sync_all(): void {
    if (!wf_ig_on()) return;
    foreach (wi_accounts() as $acc) { if (!wf_fb_is($acc) || empty($acc['enabled'])) continue;
        try { if (wf_ig_sync($acc) > 0 && function_exists('wf_bot_tick') && wf_bot_run_cfg((string) $acc['id'])['mode'] !== 'off') wf_bot_tick($acc, true); } catch (Throwable $e) { wi_log('ig_sync_error', ['account' => $acc['id'], 'error' => $e->getMessage()]); } }
}
/** API: the Instagram state of the open page (linked account, last sync, last error) for its settings. */
function wf_api_farieq_instagram(string $api): void {
    if ($api !== 'ig_state') return;
    $acc = wi_current(); if (!$acc || !wf_fb_is($acc)) wi_json(['ok' => false, 'error' => 'Open a Facebook page first.']);
    $S = (array) (wi_read('ig.json', [])[(string) $acc['id']] ?? []);
    wi_json(['ok' => true, 'on' => wf_ig_on(), 'username' => (string) ($S['username'] ?? ''), 'sync_at' => (int) ($S['sync_at'] ?? 0), 'error' => (string) ($S['error'] ?? '')]);
}
