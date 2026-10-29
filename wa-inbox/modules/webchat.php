<?php
// Website chat: one line of script on any website shows a chat bubble. The number's bot answers the visitor;
// what it cannot answer (or "talk to a person") goes to the admin on WhatsApp, and the admin's reply to that message comes back to the visitor.

/** Every site: key => [aid, name, domains[], to, lang, hello, color, on]. */
function wf_wc_sites(): array { return (array) wi_read('webchat.json', []); }
function wf_wc_mine(): array {
    // the free website-chat plan: the sites this person made (they are sent from this system's number, which is not theirs)
    if (function_exists('wi_web_only') && wi_web_only()) { $me = strtolower((string) ((function_exists('wi_tenant') && wi_is_team(wi_me()) ? (wi_tenant(wi_me())['email'] ?? '') : (wi_me()['email'] ?? '')))); return array_filter(wf_wc_sites(), fn($s) => strtolower((string) ($s['owner'] ?? '')) === $me && $me !== ''); }
    $n = wf_int_numbers(); return array_filter(wf_wc_sites(), fn($s) => isset($n[(string) ($s['aid'] ?? '')])); }
/** The admin's WhatsApp: a phone number or a group (…@g.us). */
function wf_wc_to(string $to): string { $to = trim($to); if (preg_match('/^[0-9-]{6,40}@g\.us$/', $to)) return $to; $d = preg_replace('/\D/', '', $to); return strlen($d) >= 8 ? $d . '@s.whatsapp.net' : ''; }
/** The calling website is one of the site's domains (or a sub-domain of one). */
function wf_wc_origin_ok(array $s): string {
    $o = (string) ($_SERVER['HTTP_ORIGIN'] ?? ''); $h = strtolower((string) parse_url($o, PHP_URL_HOST));
    foreach ((array) ($s['domains'] ?? []) as $d) { $d = strtolower(trim((string) $d)); if ($d !== '' && $h !== '' && ($h === $d || str_ends_with($h, '.' . $d))) return $o; }
    return '';
}
/** Per visitor and per site: a few messages a minute, a cap a day. */
function wf_wc_allow(string $key, string $sid): bool {
    $ok = true; $now = time(); $ip = substr(hash('sha256', wi_ip()), 0, 16);
    wi_update('webchat_rate.json', function ($R) use ($key, $sid, $ip, $now, &$ok) { $R = (array) $R; $day = date('Y-m-d'); if (($R['day'] ?? '') !== $day) $R = ['day' => $day, 'n' => [], 't' => []];
        $R['n'][$key] = (int) ($R['n'][$key] ?? 0) + 1; $t = array_values(array_filter((array) ($R['t'][$ip] ?? []), fn($x) => $x > $now - 60));
        if ($R['n'][$key] > 2000 || count($t) >= 8) { $ok = false; return $R; } $t[] = $now; $R['t'][$ip] = $t; return $R; });
    return $ok;
}
function wf_wc_conv(string $sid): array { return (array) (wi_read('webchat_conv.json', [])[$sid] ?? []); }
function wf_wc_add(string $sid, string $key, array $m, array $set = []): void {
    wi_update('webchat_conv.json', function ($C) use ($sid, $key, $m, $set) { $C = (array) $C;
        if (mt_rand(1, 40) === 1) foreach ($C as $k => $c) if ((int) ($c['at'] ?? 0) < time() - 7 * 86400) unset($C[$k]);
        $c = (array) ($C[$sid] ?? ['k' => $key, 'msgs' => [], 'fwd' => []]); if ($m) { $c['seq'] = (int) ($c['seq'] ?? 0) + 1; $m += ['at' => time(), 'n' => $c['seq']]; $c['msgs'][] = $m; $c['msgs'] = array_slice($c['msgs'], -60); }
        $C[$sid] = $set + $c + ['at' => time()]; $C[$sid]['at'] = time(); return $C; });
}

/** ?wc=js|send|poll — public, no session. */
function wf_wc_public(): void {
    $key = preg_replace('/[^a-z0-9_]/', '', (string) ($_GET['k'] ?? '')); $s = wf_wc_sites()[$key] ?? null; $what = (string) $_GET['wc'];
    $acc = $s ? (wi_accounts()[(string) $s['aid']] ?? null) : null;
    if ($what === 'js') { header('Content-Type: application/javascript; charset=utf-8'); header('Cache-Control: public, max-age=300');
        if (!$s || empty($s['on']) || !$acc) exit('/* off */'); echo wf_wc_script($key, $s); exit; }
    header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); header('X-Robots-Tag: noindex');
    $out = function (array $a, int $code = 200): void { http_response_code($code); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; };
    $origin = $s ? wf_wc_origin_ok($s) : '';
    if ($origin !== '') { header('Access-Control-Allow-Origin: ' . $origin); header('Vary: Origin'); header('Access-Control-Allow-Headers: Content-Type'); header('Access-Control-Allow-Methods: GET, POST'); }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') exit;
    if (!$s || empty($s['on']) || !$acc || $origin === '' || wi_account_blocked($acc)) $out(['ok' => false], 403);
    $sid = preg_replace('/[^a-zA-Z0-9]/', '', (string) ($_GET['sid'] ?? '')); if (strlen($sid) < 16 || strlen($sid) > 40) $out(['ok' => false], 400);
    $c = wf_wc_conv($sid); if ($c && ($c['k'] ?? '') !== $key) $out(['ok' => false], 403);
    $lang = ($s['lang'] ?? 'ar') === 'en' ? 'en' : 'ar';
    if ($what === 'poll') { if ($c && !empty($c['fwd'])) wf_wc_pull($key, $s, $acc); $after = (int) ($_GET['after'] ?? 0);
        $cv = wf_wc_conv($sid); $out(['ok' => true, 'human' => !empty($cv['human']), 'ended' => !empty($cv['ended']), 'msgs' => array_values(array_filter((array) ($cv['msgs'] ?? []), fn($m) => (int) $m['n'] > $after && ($m['who'] ?? '') !== 'me'))]); }
    if ($what !== 'send' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') $out(['ok' => false], 404);
    $in = json_decode((string) file_get_contents('php://input', false, null, 0, 8000), true) ?: [];
    $msg = trim(mb_substr((string) ($in['msg'] ?? ''), 0, 800)); $human = !empty($in['human']);
    if (!empty($in['end']) || !empty($in['bot'])) { $c = wf_wc_conv($sid); if (!$c) $out(['ok' => true, 'msgs' => []]);
        if (!empty($in['bot'])) { wf_wc_add($sid, $key, [], ['human' => false]); $out(['ok' => true, 'human' => false, 'msgs' => []]); }
        wf_wc_add($sid, $key, [], ['ended' => date('c'), 'human' => false]);
        if (!empty($c['fwd']) && ($to = wf_wc_to((string) ($s['to'] ?? ''))) !== '') wi_gateway('/chat/send', ['jid' => $to, 'text' => wf_msg('webchat_ended', ['site' => (string) ($s['name'] ?? ''), 'name' => (string) ($c['name'] ?? '') ?: '-'])], $acc);
        wi_log('webchat_end', ['site' => $key]); $out(['ok' => true, 'ended' => true, 'human' => false, 'msgs' => []]); }
    if (!empty(wf_wc_conv($sid)['ended'])) $out(['ok' => true, 'ended' => true, 'msgs' => []]);
    $name = mb_substr(trim(strip_tags((string) ($in['name'] ?? ''))), 0, 60); $email = filter_var(trim((string) ($in['email'] ?? '')), FILTER_VALIDATE_EMAIL) ?: ''; $phone = substr(preg_replace('/\D/', '', (string) ($in['phone'] ?? '')), 0, 16); $page = mb_substr((string) ($in['page'] ?? ''), 0, 300);
    if ($msg === '' && !$human) $out(['ok' => false], 400);
    if (!wf_wc_allow($key, $sid)) $out(['ok' => true, 'msgs' => [['who' => 'bot', 'text' => wf_landing_t('Too many messages for now. Try again in a minute.', $lang)]]]);
    $set = array_filter(['name' => $name, 'phone' => $phone, 'email' => $email, 'page' => $page]); $c = wf_wc_conv($sid);
    if ($msg !== '') wf_wc_add($sid, $key, ['who' => 'me', 'text' => $msg], $set);
    $wasHuman = !empty($c['human']); $isHuman = $human || $wasHuman; $reply = '';
    // the bot answers only when the site is in bot mode, the number's bot is on and its Website chat channel is ticked; else every message goes to the team on WhatsApp
    $rc = function_exists('wf_bot_run_cfg') ? wf_bot_run_cfg((string) $s['aid']) : []; $botOn = ($s['mode'] ?? 'bot') === 'bot' && ($rc['mode'] ?? 'off') !== 'off' && ($rc['web_chat'] ?? true); if (!empty($s['landing'])) $botOn = ($s['mode'] ?? 'bot') === 'bot';
    // the number is not linked yet (or is offline): nothing can reach the team, so the bot keeps answering and the visitor's details are kept as a lead
    $gwOn = !empty(wi_gateway('/status', null, $acc)['connected']); if (!$gwOn && empty($s['landing'])) { $botOn = true; $isHuman = false; }
    if (!empty($s['free'])) $botOn = true; // the free plan: always the bot, from the site's own text (never this system's number bot)
    if (!$botOn) $isHuman = true;
    if (!$isHuman && $msg !== '') { $hist = []; foreach (array_slice((array) ($c['msgs'] ?? []), -8) as $m) $hist[] = ['from' => ($m['who'] ?? '') === 'me' ? 'customer' : 'bot', 'text' => (string) $m['text']];
        if (!empty($s['landing']) && function_exists('wf_landing_answer')) { $reply = wf_landing_answer($msg, array_map(fn($x) => ['me' => $x['from'] === 'customer', 'text' => $x['text']], $hist), $lang); $handoff = $reply === '' || !empty($GLOBALS['wf_landing_handoff']); }
        else { $r = $gwOn && empty($s['free']) ? wf_bot_answer($acc, $msg, $hist) : []; if (!empty($r['ok']) && trim((string) ($r['reply'] ?? '')) !== '' && empty($r['handoff'])) $reply = trim((string) $r['reply']);
            // the free plan: a monthly cap on bot replies (wi_sys webchat_free_ai, default 300); past it the visitor goes to the owner on WhatsApp
            $capped = false; if (!empty($s['free'])) { $mon = date('Y-m'); $cap = max(0, (int) (wi_sys('webchat_free_ai') ?: 300)); wi_update('webchat_usage.json', function ($U) use ($key, $mon, $cap, &$capped) { $U = (array) $U; $n = (int) ($U[$key][$mon] ?? 0); if ($n >= $cap) { $capped = true; return $U; } $U[$key] = [$mon => $n + 1]; return $U; }); }
            if ($reply === '' && !$capped) $reply = wf_wc_site_answer($key, $s, $msg, $hist); $handoff = $reply === '' || (!empty($r['handoff']) && $gwOn); } }
    if (!$gwOn && empty($s['landing']) && ($handoff ?? $human)) { $c = wf_wc_conv($sid); wi_update('webchat_leads.json', function ($A) use ($key, $c, $msg) { $A = (array) $A; $A[] = ['site' => $key, 'name' => (string) ($c['name'] ?? ''), 'phone' => (string) ($c['phone'] ?? ''), 'email' => (string) ($c['email'] ?? ''), 'text' => mb_substr($msg, 0, 800), 'at' => date('c')]; return array_slice($A, -2000); });
        $t = wf_landing_t('Thank you! The team will contact you on WhatsApp soon.', $lang); $new = []; if ($reply !== '') { wf_wc_add($sid, $key, ['who' => 'bot', 'text' => mb_substr($reply, 0, 2000)]); $new[] = ['who' => 'bot', 'text' => $reply]; }
        if (!$reply || $human) { wf_wc_add($sid, $key, ['who' => 'bot', 'text' => $t]); $new[] = ['who' => 'bot', 'text' => $t]; } wi_log('webchat_lead', ['site' => $key]); $out(['ok' => true, 'msgs' => $new, 'human' => false, 'last' => (int) (wf_wc_conv($sid)['seq'] ?? 0)]); }
    $new = []; $handoff = $handoff ?? $isHuman;
    if ($reply !== '') { wf_wc_add($sid, $key, ['who' => 'bot', 'text' => mb_substr($reply, 0, 2000)]); $new[] = ['who' => 'bot', 'text' => $reply]; }
    // the bot handed over (or could not answer, or the visitor asked for a person): the message goes to the admin on WhatsApp
    if ($handoff) { $c = wf_wc_conv($sid); $to = wf_wc_to((string) ($s['to'] ?? ''));
        $txt = $msg !== '' ? $msg : implode("\n", array_map(fn($m) => (string) $m['text'], array_slice(array_filter((array) ($c['msgs'] ?? []), fn($m) => ($m['who'] ?? '') === 'me'), -3)));
        $r = $to !== '' ? wi_gateway('/chat/send', ['jid' => $to, 'text' => wf_msg('webchat_to_admin', ['site' => (string) ($s['name'] ?? ''), 'name' => trim(((string) ($c['name'] ?? '') ?: '-') . (!empty($c['email']) ? ' · ' . $c['email'] : '')), 'phone' => !empty($c['phone']) ? '+' . $c['phone'] : '-', 'page' => (string) ($c['page'] ?? '') ?: '-', 'text' => mb_substr($txt, 0, 1500)]), 'via' => 'bot'], $acc) : [];
        $mid = (string) ($r['id'] ?? $r['message_id'] ?? '');
        if ($mid !== '') { wi_update('webchat_fwd.json', function ($F) use ($mid, $sid) { $F = (array) $F; $F[$mid] = $sid; return array_slice($F, -3000, null, true); }); }
        wf_wc_add($sid, $key, [], ['human' => true, 'fwd' => array_slice(array_merge((array) ($c['fwd'] ?? []), $mid !== '' ? [$mid] : []), -20)]);
        $t = wf_landing_t(!empty($r['ok']) ? 'Your message reached the team on WhatsApp. Keep this page open, the reply will show here.' : 'Could not reach the team now. Try again later.', $lang);
        if (!$wasHuman || empty($r['ok'])) { wf_wc_add($sid, $key, ['who' => 'bot', 'text' => $t]); $new[] = ['who' => 'bot', 'text' => $t]; } wi_log('webchat_to_admin', ['site' => $key, 'ok' => !empty($r['ok'])]); }
    $cv = wf_wc_conv($sid); $out(['ok' => true, 'msgs' => $new, 'human' => !empty($cv['human']), 'last' => (int) ($cv['seq'] ?? 0)]);
}
/** The text of the client's own site (home page of its first domain), kept for a day: what the bot answers from when the number has no answer. */
function wf_wc_site_text(string $key, array $s): string {
    $C = (array) wi_read('webchat_site_text.json', []); $e = (array) ($C[$key] ?? []); if ($e && time() - (int) ($e['t'] ?? 0) < 86400) return (string) $e['x'];
    $txt = ''; foreach (array_slice((array) ($s['domains'] ?? []), 0, 2) as $dm) { if (!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', (string) $dm)) continue;
        $ch = curl_init('https://' . $dm . '/'); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_USERAGENT => 'Mozilla/5.0 (site chat)', CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP]);
        $h = (string) curl_exec($ch); curl_close($ch); if ($h === '') continue;
        $h = preg_replace('#<(script|style|noscript|svg)[^>]*>.*?</\1>#si', ' ', $h); $t = html_entity_decode(strip_tags(preg_replace('#<(br|/p|/div|/li|/h[1-6])[^>]*>#i', "\n", $h)), ENT_QUOTES, 'UTF-8');
        $txt .= trim(preg_replace("/[ \t]+/u", ' ', preg_replace("/\n\s*\n+/u", "\n", $t))) . "\n"; if (mb_strlen($txt) > 2000) break; }
    $txt = mb_substr($txt, 0, 8000); $C[$key] = ['t' => time(), 'x' => $txt]; wi_write('webchat_site_text.json', $C); return $txt;
}
function wf_wc_site_answer(string $key, array $s, string $msg, array $hist): string {
    $h = ''; foreach ($hist as $x) $h .= (($x['from'] ?? '') === 'customer' ? 'Visitor: ' : 'Assistant: ') . mb_substr((string) $x['text'], 0, 400) . "\n";
    $ai = wi_ai('Website chat', wf_prompt('webchat_site', (string) $s['aid'], ['site' => (string) ($s['name'] ?? ''), 'pages' => trim(trim((string) ($s['about'] ?? '')) . "\n\n" . wf_wc_site_text($key, $s)), 'history' => $h, 'message' => $msg]), 60, ['subject' => 'webchat', 'prefer' => function_exists('wf_role_strong_model') ? wf_role_strong_model((string) $s['aid'], 'anthropic,gemini,opencode_go') : '']);
    return trim((string) ($ai['data']['reply'] ?? ''));
}
/** The admin's replies (WhatsApp replies quoting a forwarded message) are added to the visitors' chats. Once every 5 seconds per site. */
function wf_wc_pull(string $key, array $s, array $acc): void {
    $go = false; wi_update('webchat_pull.json', function ($P) use ($key, &$go) { $P = (array) $P; if ((int) ($P[$key] ?? 0) > time() - 5) return $P; $P[$key] = time(); $go = true; return $P; }); if (!$go) return;
    $to = wf_wc_to((string) ($s['to'] ?? '')); if ($to === '') return; $F = (array) wi_read('webchat_fwd.json', []); $seen = (array) wi_read('webchat_seen.json', []);
    // WhatsApp now often files the admin's own replies under a hidden id (…@lid), not the phone number: that chat is read too (found once by its phone, kept for a day)
    $jids = [$to]; if (str_ends_with($to, '@s.whatsapp.net')) { $ph = strstr($to, '@', true); $LM = (array) wi_read('webchat_lid.json', []); $e = (array) ($LM[$ph] ?? []);
        if (!$e || time() - (int) ($e['t'] ?? 0) > 86400) { $e = ['t' => time(), 'j' => []]; foreach ((array) (wi_gateway('/archive/chats?limit=400', null, $acc)['chats'] ?? []) as $ch) if (str_ends_with((string) ($ch['jid'] ?? ''), '@lid') && (string) ($ch['phone'] ?? '') === $ph) $e['j'][] = (string) $ch['jid']; $LM[$ph] = $e; wi_write('webchat_lid.json', $LM); }
        $jids = array_merge($jids, (array) $e['j']); }
    $rows = []; foreach ($jids as $j) $rows = array_merge($rows, (array) (wi_gateway('/archive/thread?limit=80&jid=' . rawurlencode($j), null, $acc)['rows'] ?? []));
    // a plain message (not a reply) goes to the visitor of the latest forwarded message, when that was within the last hour
    usort($rows, fn($x, $y) => strcmp((string) ($x['time'] ?? ''), (string) ($y['time'] ?? ''))); $lastF = null; $lastT = 0;
    foreach ($rows as $m) {
        $q = (string) ($m['quote'] ?? ''); $id = (string) ($m['id'] ?? ''); $t = trim((string) ($m['text'] ?? '')); $tm = strtotime((string) ($m['time'] ?? '')) ?: 0;
        if (($m['dir'] ?? '') === 'out') { if (isset($F[$id])) { $lastF = $id; $lastT = $tm; } continue; }
        if ($q === '' && $lastF !== null && $tm >= $lastT && $tm - $lastT <= 3600) $q = $lastF;
        if ($q === '' || $id === '' || $t === '' || !isset($F[$q]) || isset($seen[$id])) continue;
        // the admin's commands for this visitor: the words of seed_webchat_end end the chat, those of seed_webchat_bot give it back to the bot
        $cmd = mb_strtolower(trim($t, " /\t")); $isEnd = in_array($cmd, wf_seed_list('webchat_end'), true); if ($isEnd || in_array($cmd, wf_seed_list('webchat_bot'), true)) { $seen[$id] = time();
            wf_wc_add((string) $F[$q], $key, ['who' => 'sys', 'text' => wf_landing_t($isEnd ? 'The chat has ended. Thank you!' : 'The bot answers you now', ($s['lang'] ?? 'ar') === 'en' ? 'en' : 'ar')], $isEnd ? ['ended' => date('c'), 'human' => false] : ['human' => false]); continue; }
        $seen[$id] = time(); wf_wc_add((string) $F[$q], $key, ['who' => 'team', 'text' => mb_substr($t, 0, 2000)]); }
    if (count($seen) > 3000) $seen = array_slice($seen, -2000, null, true); wi_write('webchat_seen.json', $seen);
}
/** The widget script for one site. */
function wf_wc_script(string $key, array $s): string {
    $lang = ($s['lang'] ?? 'ar') === 'en' ? 'en' : 'ar'; $L = fn($e) => wf_landing_t($e, $lang);
    $cfg = ['api' => rtrim((string) wi_sys('base_url'), '/') . '/?k=' . $key . '&wc=', 'dir' => $lang === 'en' ? 'ltr' : 'rtl', 'color' => preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($s['color'] ?? '')) ? $s['color'] : '#25d366',
        'title' => (string) ($s['name'] ?? ''), 'hello' => trim((string) ($s['hello'] ?? '')) ?: $L('Hi! How can we help you?'), 'ph' => $L('Write your message'), 'send' => $L('Send'),
        'start' => $L('Start the chat'), 'intro' => $L('Your details, so the team can reach you if the chat is cut:'), 'name' => $L('Your name'), 'phone' => $L('Your WhatsApp number'), 'email' => $L('Your email (optional)'),
        'botoff' => $L('Talk to a person'), 'boton' => $L('Back to the bot'), 'end' => $L('End the chat'), 'endq' => $L('End this chat? A new one starts next time.'), 'ended' => $L('The chat has ended. Thank you!'), 'again' => $L('New chat'),
        'powered' => !empty($s['free']) ? $L('Powered by Fariek') : '', 'powered_url' => 'https://fariek.com/?utm_source=webchat&utm_medium=free',
        'person' => $L('A person answers you now'), 'bot' => $L('The bot answers you now'), 'need' => $L('Write your name and WhatsApp number.'), 'err' => $L('Could not send now. Try again.')];
    return '(function(){if(window.__fqwc)return;window.__fqwc=1;var C=' . json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_UNESCAPED_SLASHES) . ";\n" . <<<'JS'
var d=document,S,last=0,human=false,ended=false,me={};
var newSid=function(){try{S=Array.from(crypto.getRandomValues(new Uint8Array(16)),function(b){return('0'+b.toString(16)).slice(-2)}).join('')}catch(e){S=Math.random().toString(36).slice(2)+Date.now().toString(36)+Math.random().toString(36).slice(2)}try{localStorage.setItem('fqwc_sid',S)}catch(e){}};
try{S=localStorage.getItem('fqwc_sid');me=JSON.parse(localStorage.getItem('fqwc_me')||'{}')||{}}catch(e){}if(!S)newSid();
var side=C.dir==='rtl'?'left':'right',st=d.createElement('style'),c=C.color;
st.textContent='#fqwc-b{position:fixed;bottom:18px;'+side+':18px;z-index:2147483000;width:58px;height:58px;border-radius:50%;border:0;background:'+c+';color:#fff;font-size:26px;box-shadow:0 6px 20px rgba(0,0,0,.25);cursor:pointer}'
+'#fqwc-w{position:fixed;bottom:86px;'+side+':18px;z-index:2147483000;width:min(370px,calc(100vw - 36px));height:min(540px,75vh);background:#fff;color:#111;border-radius:18px;box-shadow:0 14px 44px rgba(0,0,0,.25);display:none;flex-direction:column;overflow:hidden;font:14px/1.55 system-ui,sans-serif;direction:'+C.dir+'}#fqwc-w.on{display:flex}'
+'#fqwc-h{background:'+c+';color:#fff;padding:10px 12px;display:flex;align-items:center;gap:8px}#fqwc-h b{flex:1;font-size:15px}#fqwc-h small{display:block;font-weight:400;opacity:.85;font-size:11.5px}#fqwc-h button{border:0;background:rgba(255,255,255,.18);color:#fff;border-radius:9px;height:30px;padding:0 9px;font:inherit;font-size:12px;cursor:pointer;white-space:nowrap}#fqwc-h button:hover{background:rgba(255,255,255,.3)}'
+'#fqwc-l{flex:1;overflow:auto;padding:12px;background:#f5f6f7}#fqwc-l>div{max-width:85%;margin:0 0 8px;padding:8px 11px;border-radius:12px;white-space:pre-wrap;word-wrap:break-word}#fqwc-l .me{margin-inline-start:auto;background:'+c+';color:#fff}#fqwc-l .bot,#fqwc-l .team{background:#fff;border:1px solid #e4e6eb}#fqwc-l .team{border-color:'+c+'}#fqwc-l .sys{max-width:100%;text-align:center;background:none;color:#65676b;font-size:12px;padding:2px}'
+'#fqwc-f{display:flex;gap:6px;padding:8px;border-top:1px solid #e4e6eb}#fqwc-f input{flex:1;border:1px solid #ccd0d5;border-radius:10px;padding:8px;font:inherit;min-width:0}#fqwc-f button,#fqwc-s button{border:0;border-radius:10px;padding:8px 14px;background:'+c+';color:#fff;font:inherit;cursor:pointer}'
+'#fqwc-s{flex:1;overflow:auto;padding:16px;display:flex;flex-direction:column;gap:8px;background:#f5f6f7}#fqwc-s p{margin:0 0 4px;color:#444}#fqwc-s input{border:1px solid #ccd0d5;border-radius:10px;padding:9px;font:inherit;background:#fff}#fqwc-s .er{color:#b42318;font-size:12px;min-height:16px}';
d.head.appendChild(st);
var b=d.createElement('button');b.id='fqwc-b';b.type='button';b.textContent='💬';var w=d.createElement('div');w.id='fqwc-w';
w.innerHTML='<div id="fqwc-h"><b><span id="fqwc-t"></span><small id="fqwc-m"></small></b><button type="button" id="fqwc-hu"></button><button type="button" id="fqwc-e"></button><button type="button" id="fqwc-c">✕</button></div>'
+'<form id="fqwc-s"><p id="fqwc-in"></p><input id="fqwc-n" autocomplete="name" maxlength="60"><input id="fqwc-p" inputmode="tel" autocomplete="tel" maxlength="20" dir="ltr"><input id="fqwc-em" type="email" autocomplete="email" maxlength="80" dir="ltr"><div class="er" id="fqwc-er"></div><button id="fqwc-go"></button></form>'
+'<div id="fqwc-l"></div><form id="fqwc-f"><input id="fqwc-i" autocomplete="off"><button></button></form>'+(C.powered?'<a id="fqwc-pw" href="'+C.powered_url+'" target="_blank" rel="noopener" style="display:block;text-align:center;font-size:11px;color:#8a94a6;padding:4px 0 6px;text-decoration:none">⚡ '+C.powered+'</a>':'');
d.body.appendChild(w);d.body.appendChild(b);
var $=function(i){return d.getElementById(i)},L=$('fqwc-l');$('fqwc-t').textContent=C.title;$('fqwc-in').textContent=C.intro;$('fqwc-n').placeholder=C.name;$('fqwc-p').placeholder=C.phone;$('fqwc-em').placeholder=C.email;$('fqwc-go').textContent=C.start;$('fqwc-e').textContent=C.end;$('fqwc-i').placeholder=C.ph;w.querySelector('#fqwc-f button').textContent=C.send;
var add=function(who,t){var e=d.createElement('div');e.className=who;var im=[];t=String(t).replace(/\[img:([a-z0-9-]+)\]/g,function(_,k){im.push(k);return''}).trim();e.textContent=t;im.forEach(function(k){var g=d.createElement('img');g.src=C.api.split('?')[0]+'?guide_img='+k+'&lang='+(C.dir==='rtl'?'ar':'en');g.style.cssText='display:block;width:100%;border-radius:10px;margin-top:6px';e.appendChild(g)});L.appendChild(e);L.scrollTop=L.scrollHeight};
var paint=function(){var ok=!!(me.name&&me.phone);$('fqwc-s').style.display=ok?'none':'flex';L.style.display=ok?'block':'none';$('fqwc-f').style.display=ok&&!ended?'flex':'none';$('fqwc-hu').style.display=ok&&!ended?'':'none';$('fqwc-e').textContent=ended?C.again:C.end;$('fqwc-e').style.display=ok?'':'none';$('fqwc-hu').textContent=human?C.boton:C.botoff;$('fqwc-m').textContent=ok?(human?C.person:C.bot):''};
var state=function(j){if(!j)return;if('human' in j)human=!!j.human;if(j.ended){if(!ended)add('sys',C.ended);ended=true}paint()};
var send=function(body){body.name=me.name||'';body.phone=me.phone||'';body.email=me.email||'';body.page=location.href;return fetch(C.api+'send&sid='+S,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)}).then(function(r){return r.json()}).then(function(j){(j.msgs||[]).forEach(function(m){add(m.who,m.text)});if(j.last)last=Math.max(last,j.last);state(j)}).catch(function(){add('sys',C.err)})};
$('fqwc-s').addEventListener('submit',function(e){e.preventDefault();var n=$('fqwc-n').value.trim(),p=$('fqwc-p').value.replace(/[^0-9+]/g,'');if(!n||p.replace(/\D/g,'').length<8){$('fqwc-er').textContent=C.need;return}me={name:n,phone:p,email:$('fqwc-em').value.trim()};try{localStorage.setItem('fqwc_me',JSON.stringify(me))}catch(x){}paint();if(!L.children.length)add('bot',C.hello);$('fqwc-i').focus()});
$('fqwc-f').addEventListener('submit',function(e){e.preventDefault();var i=$('fqwc-i'),t=i.value.trim();if(!t)return;i.value='';add('me',t);send({msg:t})});
$('fqwc-hu').addEventListener('click',function(){send(human?{bot:1}:{human:1})});
$('fqwc-e').addEventListener('click',function(){if(ended){newSid();ended=false;human=false;last=0;L.innerHTML='';add('bot',C.hello);paint();return}if(!confirm(C.endq))return;send({end:1})});
$('fqwc-c').addEventListener('click',function(){w.classList.remove('on')});
var poll=function(){if(!w.classList.contains('on'))return;fetch(C.api+'poll&sid='+S+'&after='+last).then(function(r){return r.json()}).then(function(j){(j.msgs||[]).forEach(function(m){if(m.n>last){last=m.n;if(m.who==='team'||m.who==='sys')add(m.who,m.text)}});state(j)}).catch(function(){})};
setInterval(poll,6000);b.addEventListener('click',function(){w.classList.toggle('on');if(w.classList.contains('on')){paint();if(me.name&&!L.children.length)add('bot',C.hello);poll()}});paint();
})();
JS;
}
/** The "Website chat" section of the Integrations page. */
function wf_wc_section(): string {
    $t = fn($s) => wi_e(wi_t($s)); $base = rtrim((string) wi_sys('base_url'), '/') . '/'; $nums = wf_int_numbers(); $csrf = '<input type="hidden" name="_csrf" value="' . wi_e(wi_csrf()) . '">';
    $o = '<div class="card"><div class="card-h"><h2>💬 ' . $t('Website chat') . '</h2></div><p class="muted small">' . $t('Put one line on any website: a chat bubble shows there, the number\'s bot answers the visitor, and what it cannot answer goes to you on WhatsApp. Reply to that WhatsApp message and the visitor sees your reply on the website.') . '</p>';
    foreach (wf_wc_mine() as $k => $s) { $on = !empty($s['on']);
        $o .= '<details class="sa-grp"><summary><b>' . wi_e((string) $s['name']) . '</b> <span class="muted small" dir="ltr">' . wi_e(implode(', ', (array) $s['domains'])) . '</span><span class="sa-chip ' . ($on ? 'ok">' . $t('On') : 'warn">' . $t('Off')) . '</span></summary><div style="padding:0 12px 12px">'
            . '<p class="small">' . $t('Put this line before </body> on the website:') . '</p><code dir="ltr" style="display:block;user-select:all;padding:8px;background:var(--card-in,#f5f6f7);border-radius:8px;word-break:break-all">' . wi_e('<script src="' . $base . '?wc=js&k=' . $k . '" async></script>') . '</code>'
            . wf_wc_howto()
            . '<p class="muted small">' . $t('Number') . ': ' . wi_e((string) ($nums[$s['aid']]['name'] ?? $s['aid'])) . ' · ' . $t('Messages to') . ': <span dir="ltr">' . wi_e((string) $s['to']) . '</span></p>'
            . '<form method="post" class="row" style="gap:6px">' . $csrf . '<input type="hidden" name="do" value="wc_act"><input type="hidden" name="k" value="' . wi_e($k) . '">'
            . '<select name="mode" onchange="this.form.querySelector(\'[name=act][value=mode]\').click()"><option value="bot"' . (($s['mode'] ?? 'bot') === 'bot' ? ' selected' : '') . '>' . $t('The bot first, then the team on WhatsApp') . '</option><option value="team"' . (($s['mode'] ?? 'bot') === 'team' ? ' selected' : '') . '>' . $t('The team on WhatsApp (every message reaches WhatsApp)') . '</option></select><button name="act" value="mode" hidden></button>'
            . '<button class="btn ghost sm" name="act" value="toggle">' . ($on ? $t('Switch off') : $t('Switch on')) . '</button><button class="btn ghost sm" name="act" value="delete" onclick="return confirm(this.dataset.q)" data-q="' . $t('Delete') . '?">' . $t('Delete') . '</button></form>'
            . '<form method="post" class="stack" style="margin-top:10px">' . $csrf . '<input type="hidden" name="do" value="wc_act"><input type="hidden" name="k" value="' . wi_e($k) . '"><input type="hidden" name="act" value="about">'
            . '<label>' . $t('About the company (the bot answers visitors from this, with the website pages)') . '<textarea name="about" rows="5" maxlength="6000" dir="auto" placeholder="' . $t('Services, products, prices, branches, working hours, contacts…') . '">' . wi_e((string) ($s['about'] ?? '')) . '</textarea></label><div><button class="btn primary sm">' . $t('Save') . '</button></div></form></div></details>'; }
    $opt = ''; foreach ($nums as $aid => $a) $opt .= '<option value="' . wi_e((string) $aid) . '">' . wi_e((string) ($a['name'] ?? $aid)) . '</option>';
    $free = function_exists('wi_web_only') && wi_web_only();
    if ($free && wf_wc_mine()) return $o . '<p class="muted small">' . $t('The free plan has one website. Upgrade the plan for more.') . '</p></div>';
    $o .= '<details><summary class="btn ghost sm">➕ ' . $t('Add a website') . '</summary><form method="post" class="form-grid" style="margin-top:10px">' . $csrf . '<input type="hidden" name="do" value="wc_save">'
        . '<label>' . $t('Website name') . '<input name="name" required maxlength="60"></label>'
        . '<label>' . $t('Domains (comma separated)') . '<input name="domains" required dir="ltr" placeholder="example.com"></label>'
        . ($free ? '' : '<label>' . $t('Number') . '<select name="aid">' . $opt . '</select></label>')
        . '<label>' . $t('Send what the bot cannot answer to (WhatsApp number or group ID)') . '<input name="to" required dir="ltr" placeholder="2010… / 1203…@g.us"></label>'
        . ($free ? '<p class="muted small gcf-wide">🤖 ' . $t('The bot answers your visitors from your website and the text below; what it cannot answer reaches you on WhatsApp.') . '</p>' : '') . '<label' . ($free ? ' hidden' : '') . '>' . $t('Who answers the visitors') . '<select name="mode"><option value="bot">' . $t('The bot first, then the team on WhatsApp') . '</option><option value="team">' . $t('The team on WhatsApp (every message reaches WhatsApp)') . '</option></select></label>'
        . '<label>' . $t('Welcome message') . '<input name="hello" maxlength="300"></label>'
        . '<label>' . $t('Language') . '<select name="lang"><option value="ar">' . $t('Arabic') . '</option><option value="en">' . $t('English') . '</option></select></label>'
        . '<label>' . $t('Color') . '<input type="color" name="color" value="#25d366"></label>' . (wi_is_super() ? '<label class="check"><input type="checkbox" name="landing" value="1"> ' . $t('This product\'s own page (replaces its chat bubble)') . '</label>' : '')
        . '<div><button class="btn primary">' . $t('Save') . '</button></div></form></details></div>';
    return $o;
}
/** Where to paste the line, per kind of website, and what to check when the bubble does not show. */
function wf_wc_howto(): string {
    $t = fn($s) => wi_e(wi_t($s)); $li = fn(array $a) => '<ol class="small">' . implode('', array_map(fn($x) => '<li>' . $t($x) . '</li>', $a)) . '</ol>';
    return '<details class="wc-howto"><summary class="small">📘 ' . $t('How to add it to your website') . '</summary>'
        . '<p class="small"><b>HTML</b></p>' . $li(['Open the page file (index.html) and paste the line just before </body>.', 'To show it on every page, paste it in each page or in the shared footer.'])
        . '<p class="small"><b>WordPress</b></p>' . $li(['Install the WPCode plugin (Insert Headers and Footers).', 'Code Snippets → Header & Footer: paste the line in Footer and save.'])
        . '<p class="small"><b>Shopify</b></p>' . $li(['Online Store → Themes → Edit code → theme.liquid: paste the line just before </body>.'])
        . '<p class="small"><b>Wix</b></p>' . $li(['Settings → Custom Code → Add Code: paste the line, choose All pages and Body – end.'])
        . '<p class="small"><b>Blogger</b></p>' . $li(['Theme → Edit HTML: paste the line just before </body>.'])
        . '<p class="small"><b>' . $t('Try it') . '</b></p>' . $li(['Open your website: a chat bubble shows in the corner.', 'Write a question: the bot answers.', 'Press Talk to a person: a message reaches you on WhatsApp. Reply to it and the reply shows on the website.'])
        . '<p class="small"><b>' . $t('The bubble does not show?') . '</b></p>' . $li(['The domain here must be the website\'s domain (www and other sub-domains work).', 'The website chat must be switched on here.', 'Clear the website cache (a cache plugin or Cloudflare).'])
        . '</details>';
}
/** The sites of one number, for its bot page (with the line to paste). */
function wf_wc_for(string $aid): array { $base = rtrim((string) wi_sys('base_url'), '/') . '/'; $out = [];
    foreach (wf_wc_sites() as $k => $s) if ((string) ($s['aid'] ?? '') === $aid) $out[] = ['k' => $k, 'name' => (string) ($s['name'] ?? ''), 'domains' => array_values((array) ($s['domains'] ?? [])), 'on' => !empty($s['on']), 'mode' => ($s['mode'] ?? 'bot') === 'team' ? 'team' : 'bot', 'code' => '<script src="' . $base . '?wc=js&k=' . $k . '" async></script>'];
    return $out; }
/** ?api=webchat_set {k, mode}: who answers a site's visitors (from the bot page). */
function wf_api_webchat(string $api): void {
    if ($api !== 'webchat_set') return; $in = wf_post(); $k = (string) ($in['k'] ?? ''); $s = wf_wc_sites()[$k] ?? null;
    if (!$s || !wi_can_manage(wi_accounts()[(string) $s['aid']] ?? null)) wi_json(['ok' => false, 'error' => 'Not allowed.'], 403);
    $mv = ($in['mode'] ?? '') === 'team' ? 'team' : 'bot'; wi_update('webchat.json', function ($A) use ($k, $mv) { $A[$k]['mode'] = $mv; return $A; }); wi_log('webchat_mode', ['site' => $k, 'mode' => $mv]); wi_json(['ok' => true]);
}
wi_hook_add('api_handlers', fn(array $H) => array_merge($H, ['wf_api_webchat']));
/** Saves from the Integrations page. */
function wf_wc_action(string $do): void {
    if ($do === 'wc_save') { $aid = (string) ($_POST['aid'] ?? ''); $to = trim((string) ($_POST['to'] ?? ''));
        // the free plan: one site, sent from this system's main number, the bot answers; it shows "Powered by" on the bubble
        $free = function_exists('wi_web_only') && wi_web_only(); if ($free) { if (wf_wc_mine()) { wi_flash('The free plan has one website. Upgrade the plan for more.', 'bad'); header('Location: ?p=integrations'); exit; } $aid = 'main'; $_POST['mode'] = 'bot'; }
        $dom = array_values(array_filter(array_map(fn($d) => preg_replace('#^(https?://)?(www\.)?([^/:\s]+).*$#i', '$3', strtolower(trim($d))), explode(',', (string) ($_POST['domains'] ?? ''))), fn($d) => preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $d)));
        if ((!$free && !isset(wf_int_numbers()[$aid])) || !$dom || wf_wc_to($to) === '') { wi_flash('Choose a number, a domain and a WhatsApp number or group.', 'bad'); header('Location: ?p=integrations'); exit; }
        $k = 'wc_' . bin2hex(random_bytes(8));
        wi_update('webchat.json', function ($A) use ($k, $aid, $dom, $to) { $A = (array) $A; $A[$k] = ['aid' => $aid, 'name' => mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 60), 'domains' => $dom, 'to' => $to, 'on' => true,
            'mode' => ($_POST['mode'] ?? '') === 'team' ? 'team' : 'bot', 'lang' => ($_POST['lang'] ?? '') === 'en' ? 'en' : 'ar', 'hello' => mb_substr(trim((string) ($_POST['hello'] ?? '')), 0, 300), 'color' => preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($_POST['color'] ?? '')) ? $_POST['color'] : '#25d366', 'landing' => wi_is_super() && !empty($_POST['landing']) ? 1 : 0, 'at' => date('c')]; return $A; });
        if ($free) { $ow = strtolower((string) (wi_me()['email'] ?? '')); wi_update('webchat.json', function ($A) use ($k, $ow) { $A[$k]['owner'] = $ow; $A[$k]['free'] = true; return $A; }); }
        wi_log('webchat_add', ['site' => $k, 'account' => $aid, 'free' => $free]); header('Location: ?p=integrations'); exit; }
    $k = (string) ($_POST['k'] ?? ''); if (!isset(wf_wc_mine()[$k])) { wi_flash('Not found.', 'bad'); header('Location: ?p=integrations'); exit; }
    $act = (string) ($_POST['act'] ?? '');
    if ($act === 'mode') { $mv = ($_POST['mode'] ?? '') === 'team' ? 'team' : 'bot'; wi_update('webchat.json', function ($A) use ($k, $mv) { $A[$k]['mode'] = $mv; return $A; }); }
    if ($act === 'about') { $ab = mb_substr(trim((string) ($_POST['about'] ?? '')), 0, 6000); wi_update('webchat.json', function ($A) use ($k, $ab) { $A[$k]['about'] = $ab; return $A; }); }
    if ($act === 'toggle') wi_update('webchat.json', function ($A) use ($k) { $A[$k]['on'] = empty($A[$k]['on']); return $A; });
    if ($act === 'delete') wi_update('webchat.json', function ($A) use ($k) { unset($A[$k]); return $A; });
    wi_log('webchat_' . $act, ['site' => $k]); header('Location: ?p=integrations'); exit;
}
