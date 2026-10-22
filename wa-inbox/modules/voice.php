<?php
// WhatsApp Inbox — voice: a voice note written as text (a model that hears audio), so the bot can answer it like a message.
// Each note is written once and kept; notes longer than the set limit are left to people.

/** The provider that can hear audio (Gemini), with its first model: [provider row, model] or null. */
function wf_voice_provider(): ?array {
    static $m = false; if ($m !== false) return $m; $m = null;
    try { require_once __DIR__ . '/lib/WiAiProviders.php'; $P = new WiAiProviders(wi_ai_scope());
        foreach ($P->providers() as $id => $p) { if (empty($p['enabled']) || ($p['api'] ?? '') !== 'gemini' || (isset($p['health']['ok']) && !$p['health']['ok'])) continue; $ch = $P->model_chain($p, false); if ($ch) { $m = [$P, $p, (string) $ch[0]]; break; } } } catch (Throwable $e) {}
    return $m;
}
/** The text of a voice message (row of the archive), '' when it cannot be written. */
function wf_voice_text(array $acc, array $m, string $jid): string {
    $id = (string) ($m['id'] ?? ''); if ($id === '') return ''; $aid = (string) $acc['id'];
    $cache = (array) (wi_read('voice_text.json', [])[$aid] ?? []); if (isset($cache[$id])) return (string) $cache[$id]['t'];
    $max = (int) (wf_bot_run_cfg($aid)['voice_max_sec'] ?? 120); $sec = (int) ($m['mref']['seconds'] ?? 0); if ($max > 0 && $sec > $max) return '';
    $att = (string) ($m['attachment'] ?? '');
    if ($att === '') { $r = wi_gateway('/media/fetch', ['id' => $id, 'jid' => $jid], $acc); $att = (string) ($r['attachment'] ?? ''); }
    $file = dirname(__DIR__, 2) . '/storage/' . ltrim($att, '/'); if ($att === '' || !is_file($file) || filesize($file) > 8 * 1048576) return '';
    $pv = wf_voice_provider(); if (!$pv) return '';
    [$P, $prov, $model] = $pv; $mime = preg_match('/\.(mp3)$/i', $file) ? 'audio/mpeg' : (preg_match('/\.(m4a|mp4)$/i', $file) ? 'audio/mp4' : 'audio/ogg');
    $res = $P->vision_json(wf_prompt('voice_text', $aid), (string) file_get_contents($file), $mime, ['gemini']); // the same path as photos: every key and model of the provider, in turn
    $t = !empty($res['ok']) ? trim((string) ($res['data']['text'] ?? '')) : '';
    if ($t === '') return '';
    wi_update('voice_text.json', function ($V) use ($aid, $id, $t) { $V[$aid][$id] = ['t' => mb_substr($t, 0, 3000), 'at' => time()]; if (count($V[$aid]) > 3000) $V[$aid] = array_slice($V[$aid], -2000, null, true); return $V; });
    wi_log('voice_text', ['account' => $aid, 'chars' => mb_strlen($t)]);
    return $t;
}
