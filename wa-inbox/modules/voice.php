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
    if (function_exists('wf_voice_case_note')) wf_voice_case_note($acc, $jid, $t, (int) ($m['mref']['seconds'] ?? 0), (string) ($m['time'] ?? ''));
    return $t;
}
/** A reply spoken as a WhatsApp voice note (Gemini speech, made into an Ogg/Opus note): its path under storage/, '' when it cannot be made. */
function wf_voice_speak(string $aid, string $text, string $gender = ''): string {
    $text = trim(preg_replace('/[*_~`]+/u', '', $text)); if ($text === '' || mb_strlen($text) > 1500) return '';
    $ff = dirname(__DIR__, 2) . '/wa-local/node_modules/ffmpeg-static/ffmpeg'; if (!is_executable($ff)) return '';
    require_once __DIR__ . '/lib/WiAiProviders.php'; $P = new WiAiProviders(wi_ai_scope()); $keys = [];
    foreach ($P->providers() as $p) if (!empty($p['enabled']) && ($p['api'] ?? '') === 'gemini') { $keys = array_merge($keys, (array) ($p['api_keys'] ?? [])); }
    $model = (string) (wf_bot_run_cfg($aid)['voice_tts_model'] ?? '') ?: 'gemini-2.5-flash-preview-tts'; $rc0 = wf_bot_run_cfg($aid); $voice = $gender === 'm' ? ((string) ($rc0['voice_tts_male'] ?? '') ?: 'Charon') : ((string) ($rc0['voice_tts_voice'] ?? '') ?: 'Kore'); // a woman's voice for women, a man's for men
    $body = json_encode(['contents' => [['parts' => [['text' => (string) (wf_pack($aid)['tts_style'] ?? 'Read this aloud warmly and calmly, in the same dialect it is written in:') . "\n" . $text]]]],
        'generationConfig' => ['responseModalities' => ['AUDIO'], 'speechConfig' => ['voiceConfig' => ['prebuiltVoiceConfig' => ['voiceName' => $voice]]]]], JSON_UNESCAPED_UNICODE);
    $pcm = '';
    foreach (array_slice(array_unique($keys), 0, 4) as $k) {
        $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . $k]]);
        $r = json_decode((string) curl_exec($ch), true); curl_close($ch);
        $b64 = (string) ($r['candidates'][0]['content']['parts'][0]['inlineData']['data'] ?? ''); if ($b64 !== '') { $pcm = (string) base64_decode($b64); break; }
        wi_log('voice_tts_fail', ['error' => mb_substr((string) ($r['error']['message'] ?? 'no audio'), 0, 200)]);
    }
    if (strlen($pcm) < 2000) return '';
    $dir = dirname(__DIR__, 2) . '/storage/inbox_uploads'; if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $base = 'tts_' . bin2hex(random_bytes(6)); $in = $dir . '/' . $base . '.pcm'; $out = $dir . '/' . $base . '.ogg'; file_put_contents($in, $pcm);
    $pr = @proc_open([$ff, '-y', '-loglevel', 'error', '-f', 's16le', '-ar', '24000', '-ac', '1', '-i', $in, '-c:a', 'libopus', '-b:a', '32k', '-application', 'voip', $out], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pp);
    $rc = is_resource($pr) ? proc_close($pr) : 1; @unlink($in);
    if ($rc !== 0 || !is_file($out) || filesize($out) < 500) { @unlink($out); return ''; }
    foreach ((array) glob($dir . '/tts_*.ogg') as $f) if (@filemtime($f) < time() - 2 * 86400) @unlink($f); // old notes cleared
    return 'inbox_uploads/' . $base . '.ogg';
}
/** Woman or man ('f' / 'm', '' unknown) for the voice of a spoken reply: from the case file and the person's own words (Arabic verbs and adjectives show it), asked once and kept. */
function wf_person_gender(string $aid, string $jid, string $name, string $text): string {
    $G = (array) (wi_read('voice_gender.json', [])[$aid] ?? []); if (isset($G[$jid])) return (string) $G[$jid];
    $ph = function_exists('wf_phone') ? wf_phone(explode('@', $jid)[0]) : ''; $cs = $ph !== '' && function_exists('wf_case_get') ? (wf_case_get($aid, $ph) ?? []) : [];
    $hint = trim((string) ($cs['data']['gender'] ?? ($cs['gender'] ?? '')));
    $ai = wi_ai('Voice: woman or man', "Is this WhatsApp customer a woman or a man? Use the gender noted in their file if any, else their name and how they write about themselves (Arabic verb and adjective forms).\nNOTED GENDER: " . ($hint ?: '(none)') . "\nNAME: " . mb_substr($name, 0, 60) . "\nTHEIR MESSAGES:\n" . mb_substr($text, 0, 1200) . "\nReturn JSON {\"gender\":\"f\"|\"m\"|\"?\"}.", 40, ['subject' => 'voice gender', 'prefer' => function_exists('wf_fast_model') ? wf_fast_model(true) : '']);
    $g = (string) ($ai['data']['gender'] ?? ''); $g = in_array($g, ['f', 'm'], true) ? $g : '';
    if ($g !== '') wi_update('voice_gender.json', function ($V) use ($aid, $jid, $g) { $V[$aid][$jid] = $g; return $V; });
    return $g;
}

/** Every voice note of a person with a case: its words and a short reading of it go into the case file as a note, for the team. */
function wf_voice_case_note(array $acc, string $jid, string $text, int $sec, string $time): void {
    $aid = (string) $acc['id']; if (!function_exists('wf_cases') || !function_exists('wf_case_update')) return;
    $num = preg_replace('/\D/', '', explode('@', $jid)[0]); $ph = '';
    foreach (wf_cases($aid) as $p => $c) { if (!is_array($c)) continue; if ((string) $p === $num || in_array($num, array_map(fn($l) => preg_replace('/\D/', '', (string) $l), (array) ($c['lids'] ?? [])), true)) { $ph = (string) $p; break; } }
    if ($ph === '' && str_ends_with($jid, '@lid')) { $cp = wf_phone((string) (wi_contact($jid, $acc)['phone'] ?? '')); if ($cp !== '' && wf_case_get($aid, $cp)) $ph = $cp; }
    if ($ph === '') return; // no case for this person: the words are kept with the message only
    $ai = wi_ai('Voice note reading', "A person under follow-up sent this voice note (written out below). In 1-2 short lines in their Arabic dialect, for the team: what they say, their mood, and anything new or urgent. Never diagnose.\nVOICE NOTE:\n" . mb_substr($text, 0, 2500) . "\nReturn JSON {\"reading\":\"…\",\"urgent\":true|false}.", 40, ['subject' => 'voice reading', 'prefer' => function_exists('wf_fast_model') ? wf_fast_model(true) : '']);
    $rd = trim((string) ($ai['data']['reading'] ?? '')); $urgent = !empty($ai['data']['urgent']);
    $note = wf_msg('voice_case_note', ['sec' => (string) $sec, 'text' => mb_substr($text, 0, 2500), 'reading' => $rd, 'urgent' => $urgent ? '⚠️' : '']);
    wf_case_update($aid, $ph, function (?array $x) use ($note) { if ($x === null) return null; $x['notes'][] = ['at' => date('c'), 'by' => 'bot', 'text' => $note]; return $x; });
}
