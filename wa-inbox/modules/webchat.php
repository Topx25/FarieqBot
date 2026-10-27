<?php
// Website chat: one line of script on any website shows a chat bubble. The number's bot answers the visitor;
// what it cannot answer (or "talk to a person") goes to the admin on WhatsApp, and the admin's reply to that message comes back to the visitor.

/** Every site: key => [aid, name, domains[], to, lang, hello, color, on]. */
function wf_wc_sites(): array { return (array) wi_read('webchat.json', []); }
function wf_wc_mine(): array { $n = wf_int_numbers(); return array_filter(wf_wc_sites(), fn($s) => isset($n[(string) ($s['aid'] ?? '')])); }
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
        $out(['ok' => true, 'msgs' => array_values(array_filter((array) (wf_wc_conv($sid)['msgs'] ?? []), fn($m) => (int) $m['n'] > $after && ($m['who'] ?? '') !== 'me'))]); }
    if ($what !== 'send' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') $out(['ok' => false], 404);
    $in = json_decode((string) file_get_contents('php://input', false, null, 0, 8000), true) ?: [];
    $msg = trim(mb_substr((string) ($in['msg'] ?? ''), 0, 800)); $human = !empty($in['human']);
    $name = mb_substr(trim(strip_tags((string) ($in['name'] ?? ''))), 0, 60); $phone = substr(preg_replace('/\D/', '', (string) ($in['phone'] ?? '')), 0, 16); $page = mb_substr((string) ($in['page'] ?? ''), 0, 300);
    if ($msg === '' && !$human) $out(['ok' => false], 400);
    if (!wf_wc_allow($key, $sid)) $out(['ok' => true, 'msgs' => [['who' => 'bot', 'text' => wf_landing_t('Too many messages for now. Try again in a minute.', $lang)]]]);
    $set = array_filter(['name' => $name, 'phone' => $phone, 'page' => $page]); $c = wf_wc_conv($sid);
    if ($msg !== '') wf_wc_add($sid, $key, ['who' => 'me', 'text' => $msg], $set);
    $wasHuman = !empty($c['human']); $isHuman = $human || $wasHuman; $reply = '';
    // the bot answers only when the site is in bot mode, the number's bot is on and its Website chat channel is ticked; else every message goes to the team on WhatsApp
    $rc = function_exists('wf_bot_run_cfg') ? wf_bot_run_cfg((string) $s['aid']) : []; $botOn = ($s['mode'] ?? 'bot') === 'bot' && ($rc['mode'] ?? 'off') !== 'off' && ($rc['web_chat'] ?? true); if (!empty($s['landing'])) $botOn = ($s['mode'] ?? 'bot') === 'bot';
    if (!$botOn) $isHuman = true;
    if (!$isHuman && $msg !== '') { $hist = []; foreach (array_slice((array) ($c['msgs'] ?? []), -8) as $m) $hist[] = ['from' => ($m['who'] ?? '') === 'me' ? 'customer' : 'bot', 'text' => (string) $m['text']];
        if (!empty($s['landing']) && function_exists('wf_landing_answer')) { $reply = wf_landing_answer($msg, array_map(fn($x) => ['me' => $x['from'] === 'customer', 'text' => $x['text']], $hist), $lang); $handoff = $reply === ''; }
        else { $r = wf_bot_answer($acc, $msg, $hist); if (!empty($r['ok']) && trim((string) ($r['reply'] ?? '')) !== '') $reply = trim((string) $r['reply']); $handoff = $reply === '' || !empty($r['handoff']); } }
    $new = []; $handoff = $handoff ?? $isHuman;
    if ($reply !== '') { wf_wc_add($sid, $key, ['who' => 'bot', 'text' => mb_substr($reply, 0, 2000)]); $new[] = ['who' => 'bot', 'text' => $reply]; }
    // the bot handed over (or could not answer, or the visitor asked for a person): the message goes to the admin on WhatsApp
    if ($handoff) { $c = wf_wc_conv($sid); $to = wf_wc_to((string) ($s['to'] ?? ''));
        $txt = $msg !== '' ? $msg : implode("\n", array_map(fn($m) => (string) $m['text'], array_slice(array_filter((array) ($c['msgs'] ?? []), fn($m) => ($m['who'] ?? '') === 'me'), -3)));
        $r = $to !== '' ? wi_gateway('/chat/send', ['jid' => $to, 'text' => wf_msg('webchat_to_admin', ['site' => (string) ($s['name'] ?? ''), 'name' => (string) ($c['name'] ?? '') ?: '-', 'phone' => !empty($c['phone']) ? '+' . $c['phone'] : '-', 'page' => (string) ($c['page'] ?? '') ?: '-', 'text' => mb_substr($txt, 0, 1500)]), 'via' => 'bot'], $acc) : [];
        $mid = (string) ($r['id'] ?? $r['message_id'] ?? '');
        if ($mid !== '') { wi_update('webchat_fwd.json', function ($F) use ($mid, $sid) { $F = (array) $F; $F[$mid] = $sid; return array_slice($F, -3000, null, true); }); }
        wf_wc_add($sid, $key, [], ['human' => true, 'fwd' => array_slice(array_merge((array) ($c['fwd'] ?? []), $mid !== '' ? [$mid] : []), -20)]);
        $t = wf_landing_t(!empty($r['ok']) ? 'Your message reached the team on WhatsApp. Keep this page open, the reply will show here.' : 'Could not reach the team now. Try again later.', $lang);
        if (!$wasHuman || empty($r['ok'])) { wf_wc_add($sid, $key, ['who' => 'bot', 'text' => $t]); $new[] = ['who' => 'bot', 'text' => $t]; } wi_log('webchat_to_admin', ['site' => $key, 'ok' => !empty($r['ok'])]); }
    $out(['ok' => true, 'msgs' => $new, 'last' => (int) (wf_wc_conv($sid)['seq'] ?? 0)]);
}
/** The admin's replies (WhatsApp replies quoting a forwarded message) are added to the visitors' chats. Once every 5 seconds per site. */
function wf_wc_pull(string $key, array $s, array $acc): void {
    $go = false; wi_update('webchat_pull.json', function ($P) use ($key, &$go) { $P = (array) $P; if ((int) ($P[$key] ?? 0) > time() - 5) return $P; $P[$key] = time(); $go = true; return $P; }); if (!$go) return;
    $to = wf_wc_to((string) ($s['to'] ?? '')); if ($to === '') return; $F = (array) wi_read('webchat_fwd.json', []); $seen = (array) wi_read('webchat_seen.json', []);
    foreach ((array) (wi_gateway('/archive/thread?limit=80&jid=' . rawurlencode($to), null, $acc)['rows'] ?? []) as $m) {
        $q = (string) ($m['quote'] ?? ''); $id = (string) ($m['id'] ?? ''); $t = trim((string) ($m['text'] ?? ''));
        if ($q === '' || $id === '' || $t === '' || !isset($F[$q]) || isset($seen[$id])) continue;
        $seen[$id] = time(); wf_wc_add((string) $F[$q], $key, ['who' => 'team', 'text' => mb_substr($t, 0, 2000)]); }
    if (count($seen) > 3000) $seen = array_slice($seen, -2000, null, true); wi_write('webchat_seen.json', $seen);
}
/** The widget script for one site. */
function wf_wc_script(string $key, array $s): string {
    $lang = ($s['lang'] ?? 'ar') === 'en' ? 'en' : 'ar'; $L = fn($e) => wf_landing_t($e, $lang);
    $cfg = ['api' => rtrim((string) wi_sys('base_url'), '/') . '/?k=' . $key . '&wc=', 'dir' => $lang === 'en' ? 'ltr' : 'rtl', 'color' => preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($s['color'] ?? '')) ? $s['color'] : '#25d366',
        'title' => (string) ($s['name'] ?? ''), 'hello' => trim((string) ($s['hello'] ?? '')) ?: $L('Hi! How can we help you?'), 'ph' => $L('Write your message'), 'send' => $L('Send'), 'human' => $L('Talk to a person'),
        'name' => $L('Your name (optional)'), 'phone' => $L('Your WhatsApp (optional)'), 'err' => $L('Could not send now. Try again.')];
    return '(function(){if(window.__fqwc)return;window.__fqwc=1;var C=' . json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_UNESCAPED_SLASHES) . ";\n" . <<<'JS'
var S;try{S=localStorage.getItem('fqwc_sid');if(!S){S=Array.from(crypto.getRandomValues(new Uint8Array(16)),function(b){return('0'+b.toString(16)).slice(-2)}).join('');localStorage.setItem('fqwc_sid',S)}}catch(e){S=Math.random().toString(36).slice(2)+Date.now().toString(36)+Math.random().toString(36).slice(2)}
var last=0,side=C.dir==='rtl'?'left':'right',d=document,st=d.createElement('style');
st.textContent='#fqwc-b{position:fixed;bottom:18px;'+side+':18px;z-index:2147483000;width:58px;height:58px;border-radius:50%;border:0;background:'+C.color+';color:#fff;font-size:26px;box-shadow:0 6px 20px rgba(0,0,0,.25);cursor:pointer}#fqwc-w{position:fixed;bottom:86px;'+side+':18px;z-index:2147483000;width:min(360px,calc(100vw - 36px));height:min(500px,72vh);background:#fff;color:#111;border-radius:16px;box-shadow:0 12px 40px rgba(0,0,0,.25);display:none;flex-direction:column;overflow:hidden;font:14px/1.5 system-ui,sans-serif;direction:'+C.dir+'}#fqwc-w.on{display:flex}#fqwc-h{background:'+C.color+';color:#fff;padding:12px 14px;font-weight:700}#fqwc-l{flex:1;overflow:auto;padding:12px;background:#f5f6f7}#fqwc-l div{max-width:85%;margin:0 0 8px;padding:8px 11px;border-radius:12px;white-space:pre-wrap;word-wrap:break-word}#fqwc-l .me{margin-inline-start:auto;background:'+C.color+';color:#fff}#fqwc-l .bot,#fqwc-l .team{background:#fff;border:1px solid #e4e6eb}#fqwc-l .team{border-color:'+C.color+'}#fqwc-f{display:flex;gap:6px;padding:8px;border-top:1px solid #e4e6eb}#fqwc-f input{flex:1;border:1px solid #ccd0d5;border-radius:10px;padding:8px;font:inherit;min-width:0}#fqwc-f button,#fqwc-x button{border:0;border-radius:10px;padding:8px 12px;background:'+C.color+';color:#fff;font:inherit;cursor:pointer}#fqwc-x{display:flex;gap:6px;padding:6px 8px 0;flex-wrap:wrap}#fqwc-x input{flex:1;min-width:120px;border:1px solid #ccd0d5;border-radius:8px;padding:6px;font:inherit;font-size:12px}#fqwc-x button{background:#fff;color:#111;border:1px solid #ccd0d5;font-size:12px;padding:6px 10px}';
d.head.appendChild(st);
var b=d.createElement('button');b.id='fqwc-b';b.type='button';b.textContent='💬';var w=d.createElement('div');w.id='fqwc-w';
w.innerHTML='<div id="fqwc-h"></div><div id="fqwc-l"></div><div id="fqwc-x"><input id="fqwc-n"><input id="fqwc-p" inputmode="tel"><button type="button" id="fqwc-hu"></button></div><form id="fqwc-f"><input id="fqwc-i" autocomplete="off"><button></button></form>';
d.body.appendChild(w);d.body.appendChild(b);
var $=function(i){return d.getElementById(i)},L=$('fqwc-l');$('fqwc-h').textContent=C.title;$('fqwc-n').placeholder=C.name;$('fqwc-p').placeholder=C.phone;$('fqwc-hu').textContent=C.human;$('fqwc-i').placeholder=C.ph;w.querySelector('#fqwc-f button').textContent=C.send;
var add=function(who,t){var e=d.createElement('div');e.className=who;e.textContent=t;L.appendChild(e);L.scrollTop=L.scrollHeight};add('bot',C.hello);
var send=function(body){body.name=$('fqwc-n').value;body.phone=$('fqwc-p').value;body.page=location.href;return fetch(C.api+'send&sid='+S,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)}).then(function(r){return r.json()}).then(function(j){(j.msgs||[]).forEach(function(m){add(m.who,m.text)});if(j.last)last=Math.max(last,j.last)}).catch(function(){add('bot',C.err)})};
$('fqwc-f').addEventListener('submit',function(e){e.preventDefault();var i=$('fqwc-i'),t=i.value.trim();if(!t)return;i.value='';add('me',t);send({msg:t})});
$('fqwc-hu').addEventListener('click',function(){send({human:1})});
var poll=function(){if(!w.classList.contains('on'))return;fetch(C.api+'poll&sid='+S+'&after='+last).then(function(r){return r.json()}).then(function(j){(j.msgs||[]).forEach(function(m){if(m.n>last){last=m.n;if(m.who==='team')add('team',m.text)}})}).catch(function(){})};
setInterval(poll,6000);b.addEventListener('click',function(){w.classList.toggle('on');if(w.classList.contains('on'))$('fqwc-i').focus()});
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
            . '<button class="btn ghost sm" name="act" value="toggle">' . ($on ? $t('Switch off') : $t('Switch on')) . '</button><button class="btn ghost sm" name="act" value="delete" onclick="return confirm(this.dataset.q)" data-q="' . $t('Delete') . '?">' . $t('Delete') . '</button></form></div></details>'; }
    $opt = ''; foreach ($nums as $aid => $a) $opt .= '<option value="' . wi_e((string) $aid) . '">' . wi_e((string) ($a['name'] ?? $aid)) . '</option>';
    $o .= '<details><summary class="btn ghost sm">➕ ' . $t('Add a website') . '</summary><form method="post" class="form-grid" style="margin-top:10px">' . $csrf . '<input type="hidden" name="do" value="wc_save">'
        . '<label>' . $t('Website name') . '<input name="name" required maxlength="60"></label>'
        . '<label>' . $t('Domains (comma separated)') . '<input name="domains" required dir="ltr" placeholder="example.com"></label>'
        . '<label>' . $t('Number') . '<select name="aid">' . $opt . '</select></label>'
        . '<label>' . $t('Send what the bot cannot answer to (WhatsApp number or group ID)') . '<input name="to" required dir="ltr" placeholder="2010… / 1203…@g.us"></label>'
        . '<label>' . $t('Who answers the visitors') . '<select name="mode"><option value="bot">' . $t('The bot first, then the team on WhatsApp') . '</option><option value="team">' . $t('The team on WhatsApp (every message reaches WhatsApp)') . '</option></select></label>'
        . '<label>' . $t('Welcome message') . '<input name="hello" maxlength="300"></label>'
        . '<label>' . $t('Language') . '<select name="lang"><option value="ar">' . $t('Arabic') . '</option><option value="en">' . $t('English') . '</option></select></label>'
        . '<label>' . $t('Color') . '<input type="color" name="color" value="#25d366"></label>'
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
        $dom = array_values(array_filter(array_map(fn($d) => preg_replace('#^(https?://)?(www\.)?([^/:\s]+).*$#i', '$3', strtolower(trim($d))), explode(',', (string) ($_POST['domains'] ?? ''))), fn($d) => preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $d)));
        if (!isset(wf_int_numbers()[$aid]) || !$dom || wf_wc_to($to) === '') { wi_flash('Choose a number, a domain and a WhatsApp number or group.', 'bad'); header('Location: ?p=integrations'); exit; }
        $k = 'wc_' . bin2hex(random_bytes(8));
        wi_update('webchat.json', function ($A) use ($k, $aid, $dom, $to) { $A = (array) $A; $A[$k] = ['aid' => $aid, 'name' => mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 60), 'domains' => $dom, 'to' => $to, 'on' => true,
            'mode' => ($_POST['mode'] ?? '') === 'team' ? 'team' : 'bot', 'lang' => ($_POST['lang'] ?? '') === 'en' ? 'en' : 'ar', 'hello' => mb_substr(trim((string) ($_POST['hello'] ?? '')), 0, 300), 'color' => preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($_POST['color'] ?? '')) ? $_POST['color'] : '#25d366', 'at' => date('c')]; return $A; });
        wi_log('webchat_add', ['site' => $k, 'account' => $aid]); header('Location: ?p=integrations'); exit; }
    $k = (string) ($_POST['k'] ?? ''); if (!isset(wf_wc_mine()[$k])) { wi_flash('Not found.', 'bad'); header('Location: ?p=integrations'); exit; }
    $act = (string) ($_POST['act'] ?? '');
    if ($act === 'mode') { $mv = ($_POST['mode'] ?? '') === 'team' ? 'team' : 'bot'; wi_update('webchat.json', function ($A) use ($k, $mv) { $A[$k]['mode'] = $mv; return $A; }); }
    if ($act === 'toggle') wi_update('webchat.json', function ($A) use ($k) { $A[$k]['on'] = empty($A[$k]['on']); return $A; });
    if ($act === 'delete') wi_update('webchat.json', function ($A) use ($k) { unset($A[$k]); return $A; });
    wi_log('webchat_' . $act, ['site' => $k]); header('Location: ?p=integrations'); exit;
}
