<?php
// The product page's live chat: a visitor asks about the product and the AI answers from the product facts and the public plans.
// Public (no sign-in), so every visitor is limited per hour and per day, and all visitors together per day (General settings).

/** The visitor's language on the product page: ?lang, else the saved choice, else Arabic. */
function wf_landing_lang(): string { $q = (string) ($_GET['lang'] ?? ($_COOKIE['wi_lang'] ?? '')); return $q === 'en' ? 'en' : 'ar'; }
/** A product page text in the visitor's language (the English text is the key, as in i18n.php). */
function wf_landing_t(string $en, string $lang): string { static $ar = null; if ($lang === 'en') return $en; $ar ??= (array) require dirname(__DIR__) . '/i18n.php'; return (string) ($ar[$en] ?? $en); }
/** The product facts the chat answers from: the brand, the public plans and the owner's own facts text. */
function wf_landing_facts(): string {
    $cur = (string) (wi_sys('plan_currency') ?: '$'); $L = [];
    foreach (array_filter(wi_plans(), fn($p) => !empty($p['public'])) as $p) { $p = wi_plan_clean($p);
        $L[] = '- ' . $p['name'] . ': ' . ($p['price'] > 0 ? $cur . rtrim(rtrim(number_format((float) $p['price'], 2, '.', ''), '0'), '.') : 'free') . ', ' . $p['numbers'] . ' WhatsApp numbers, ' . ($p['team'] ?: 'unlimited') . ' team members' . (!empty($p['trial']) ? ', trial' : ''); }
    return 'Brand: ' . wi_sys('brand_name') . "\nPlans:\n" . ($L ? implode("\n", $L) : '- none published') . "\nSign-up open: " . (wi_sys('signup_on') ? 'yes' : 'no') . "\n" . trim((string) wi_sys('landing_chat_facts'))
        . (defined('WF_DATA_INFO') ? "\nData and privacy (answer privacy questions from this, with confidence):\n" . implode("\n", array_map(fn($r) => '- ' . $r[1] . ': ' . $r[2], WF_DATA_INFO)) : '')
        . (function () { $g = wf_landing_guide(); $h = (array) ($g['_bot_hints'] ?? []); unset($g['_bot_hints']); return "\nSystem guide (every page of the dashboard):\n" . implode("\n", array_map(fn($k, $v) => '- ' . $v['icon'] . ' ' . $v['title'] . ' (Arabic menu name: ' . wf_landing_t($v['title'], 'ar') . '; picture key: ' . $k . '): ' . $v['desc'], array_keys($g), $g)) . ($h ? "\nBot settings, with examples:\n- " . implode("\n- ", $h) : ''); })()
        . (function_exists('wf_terms_text') ? "\nTerms of service:\n" . mb_substr(wf_terms_text('en'), 0, 6000) : '');
}
/** The system guide for the product chat: every page's title and description, read from the pages themselves (cached until a file changes). Super admin and healing pages are left out. */
function wf_landing_guide(): array {
    $root = dirname(__DIR__); $files = array_merge(glob($root . '/src/app/*.js') ?: [], glob($root . '/src/features/*.js') ?: [], [$root . '/app/page.php', __FILE__]);
    $sig = md5(implode('|', array_map(fn($f) => $f . @filemtime($f), $files))); $c = (array) wi_read('landing_guide.json', []);
    if (($c['sig'] ?? '') === $sig) return (array) $c['pages'];
    $skip = '/ruqyah|healing|symptom|super|client panel|every client|error log|plans and subscriptions|system overview/i'; $P = [];
    $add = function (string $ic, string $t, string $d) use (&$P, $skip) { $t = trim(stripslashes($t)); $d = trim(stripslashes($d)); if ($t === '' || !preg_match('/^[A-Z]/', $t) || str_contains($t, '_') || $d === '' || preg_match($skip, $t . ' ' . $d)) return;
        $k = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($t)), '-'); if ($k !== '' && !isset($P[$k])) $P[$k] = ['icon' => $ic, 'title' => $t, 'desc' => mb_substr($d, 0, 400)]; };
    $add('🏠', 'Dashboard', 'The first page after sign-in: today\'s messages, the chats waiting for a reply, the bot\'s work and the team at a glance.');
    $add('💬', 'Conversations', 'Every WhatsApp chat of your numbers in one inbox: reply as the team, send files and voice notes, use saved replies, ask the bot for a suggested reply, label chats, hide them, and switch the bot on or off for one chat.');
    $add('🔌', 'Bot channels', 'On the Bot page, the Channels card has three on/off switches: Private chats, Groups and Website chat. Switch on only Groups to have the bot answer in groups alone; each group is then set up in the Groups tab (mode, trigger word, hours, anti-spam).');
    $add('📣', 'Broadcast', 'Send one message to many customers or groups, now or at a set time, with a safe pace so the number is not banned.');
    foreach ($files as $f) { $src = (string) @file_get_contents($f);
        if (preg_match_all("/hero\\('([^']+)', '([^']+)', '((?:[^'\\\\]|\\\\.)+)'/u", $src, $m, PREG_SET_ORDER)) foreach ($m as $x) $add($x[1], $x[2], $x[3]);
        if (preg_match_all('#<span class="hero-ic">([^<]+)</span><div><h1>([^<]+)</h1><p class="muted">([^<]+)</p>#u', $src, $m, PREG_SET_ORDER)) foreach ($m as $x) $add($x[1], $x[2], $x[3]); }
    $hints = []; $bs = (string) @file_get_contents($root . '/src/features/11-botrun.js');
    if (preg_match_all("/T\\('(Example: (?:[^'\\\\]|\\\\.)+)'\\)/u", $bs, $m)) foreach ($m[1] as $x) if (!preg_match($skip, $x)) $hints[] = mb_substr(stripslashes($x), 0, 300);
    $P['_bot_hints'] = $hints;
    wi_write('landing_guide.json', ['sig' => $sig, 'pages' => $P]); return $P;
}
/** GET ?guide_img=key: a picture card of one page of the system (its icon, name, place in the menu and what it does). */
function wf_guide_img(): void {
    $k = preg_replace('/[^a-z0-9-]/', '', (string) ($_GET['guide_img'] ?? '')); $lang = ($_GET['lang'] ?? '') === 'en' ? 'en' : 'ar'; $g = wf_landing_guide()[$k] ?? null;
    if (!$g || $k === '_bot_hints') { http_response_code(404); exit; }
    $e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_XML1, 'UTF-8'); $t = wf_landing_t($g['title'], $lang); $d = wf_landing_t($g['desc'], $lang); $rtl = $lang === 'ar';
    $lines = []; $cur = ''; foreach (preg_split('/\s+/u', $d) as $w) { if (mb_strlen($cur . ' ' . $w) > 52) { $lines[] = $cur; $cur = $w; } else $cur = trim($cur . ' ' . $w); } if ($cur !== '') $lines[] = $cur; $lines = array_slice($lines, 0, 6);
    $x = $rtl ? 560 : 40; $an = $rtl ? 'end' : 'start'; $body = '';
    foreach ($lines as $i => $l) $body .= '<text x="' . $x . '" y="' . (190 + $i * 30) . '" text-anchor="' . $an . '" font-size="18" fill="#4a4a55">' . $e($l) . '</text>';
    $menu = ''; $all = wf_landing_guide(); $items = array_values(array_filter(array_map(fn($kk) => $all[$kk] ?? null, ['dashboard', 'conversations', 'groups', 'broadcast', 'bot', 'learning', 'files', 'numbers']))); if (!in_array($g, $items, true)) $items[] = $g;
    foreach ($items as $i => $it) { $on = $it['title'] === $g['title']; $menu .= '<rect x="' . ($rtl ? 612 : 12) . '" y="' . (70 + $i * 34) . '" width="176" height="28" rx="8" fill="' . ($on ? '#16161a' : 'transparent') . '"/><text x="' . ($rtl ? 778 : 22) . '" y="' . (89 + $i * 34) . '" text-anchor="' . $an . '" font-size="13" fill="' . ($on ? '#fff' : '#4a4a55') . '">' . $e($it['icon'] . ' ' . mb_substr(wf_landing_t($it['title'], $lang), 0, 22)) . '</text>'; }
    header('Content-Type: image/svg+xml; charset=utf-8'); header('Cache-Control: public, max-age=3600');
    echo '<svg xmlns="http://www.w3.org/2000/svg" width="800" height="420" viewBox="0 0 800 420" direction="' . ($rtl ? 'rtl' : 'ltr') . '" font-family="IBM Plex Sans Arabic, Tahoma, sans-serif">'
        . '<rect width="800" height="420" rx="22" fill="#f6f5f2"/><rect x="' . ($rtl ? 600 : 0) . '" y="0" width="200" height="420" fill="#fff"/><text x="' . ($rtl ? 778 : 22) . '" y="44" text-anchor="' . $an . '" font-size="18" font-weight="700" fill="#16161a">' . $e((string) wi_sys('brand_name')) . '</text>' . $menu
        . '<rect x="' . ($rtl ? 20 : 220) . '" y="30" width="560" height="360" rx="20" fill="#fff" stroke="rgba(22,22,26,.08)"/>'
        . '<rect x="' . ($rtl ? 504 : 240) . '" y="56" width="56" height="56" rx="16" fill="#efede8"/><text x="' . ($rtl ? 532 : 268) . '" y="96" text-anchor="middle" font-size="28">' . $e($g['icon']) . '</text>'
        . '<text x="' . ($rtl ? 488 : 312) . '" y="92" text-anchor="' . $an . '" font-size="26" font-weight="700" fill="#16161a">' . $e($t) . '</text><rect x="' . ($rtl ? 40 : 240) . '" y="130" width="520" height="3" rx="2" fill="#d9b97c"/>' . str_replace('x="' . $x . '"', 'x="' . ($rtl ? 560 : 240) . '"', $body) . '</svg>';
    exit;
}
/** Counts one message of this visitor; false when a limit is reached. */
function wf_landing_chat_allow(): bool {
    $ip = hash('sha256', wi_ip()); $ok = true; $now = time();
    $perH = max(1, (int) (wi_sys('landing_chat_hour') ?: 15)); $perD = max($perH, (int) (wi_sys('landing_chat_day') ?: 40)); $all = max(1, (int) (wi_sys('landing_chat_total') ?: 500));
    wi_update('landing_chat.json', function ($S) use ($ip, $now, $perH, $perD, $all, &$ok) {
        $S = (array) $S; $day = date('Y-m-d'); if (($S['day'] ?? '') !== $day) $S = ['day' => $day, 'n' => 0, 'ip' => []];
        $t = array_values(array_filter((array) ($S['ip'][$ip] ?? []), fn($x) => $x > $now - 86400));
        if ((int) $S['n'] >= $all || count($t) >= $perD || count(array_filter($t, fn($x) => $x > $now - 3600)) >= $perH) { $ok = false; return $S; }
        $t[] = $now; $S['ip'][$ip] = $t; $S['n'] = (int) $S['n'] + 1; return $S; });
    return $ok;
}
/** POST ?landing_chat=1 {msg, hist:[{me, text}], lang}: the answer as JSON. */
function wf_landing_chat(): void {
    header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); header('X-Robots-Tag: noindex');
    $out = function (array $a): void { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; };
    if (!wi_sys('landing_chat_on') || $_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(404); $out(['ok' => false]); }
    $in = json_decode((string) file_get_contents('php://input', false, null, 0, 20000), true) ?: [];
    $msg = trim(mb_substr((string) ($in['msg'] ?? ''), 0, 500)); $lang = ($in['lang'] ?? '') === 'en' ? 'en' : 'ar';
    if ($msg === '') $out(['ok' => false]);
    if (!wf_landing_chat_allow()) $out(['ok' => true, 'reply' => wf_landing_t('Too many questions for now. Try again later.', $lang)]);
    $reply = wf_landing_answer($msg, array_slice((array) ($in['hist'] ?? []), -8), $lang);
    wi_log('landing_chat', ['ok' => $reply !== '', 'lang' => $lang]);
    $out(['ok' => true, 'reply' => $reply !== '' ? mb_substr($reply, 0, 1200) : wf_landing_t('Could not answer now. Try again.', $lang)]);
}
/** The product page answer: the AI with the product facts, on the first paid model that is on (System setting landing_chat_models, default Anthropic then Gemini then OpenCode Go) ('' when it cannot answer). $hist rows: {me, text}. */
function wf_landing_answer(string $msg, array $hist, string $lang): string {
    $h = ''; foreach ($hist as $x) if (is_array($x)) $h .= (!empty($x['me']) ? 'Visitor: ' : 'Assistant: ') . mb_substr(trim((string) ($x['text'] ?? '')), 0, 400) . "\n";
    $ai = wi_ai('Product page chat', wf_prompt('landing_chat', '', ['facts' => wf_landing_facts(), 'history' => $h, 'message' => $msg, 'lang' => $lang === 'en' ? 'English' : 'Egyptian Arabic']), 60, ['subject' => 'landing', 'prefer' => function_exists('wf_role_strong_model') ? wf_role_strong_model('main', (string) (wi_sys('landing_chat_models') ?: 'anthropic,gemini,opencode_go')) : '']);
    return trim((string) ($ai['data']['reply'] ?? ''));
}
/** The website chat site that serves the product page (marked landing): its widget replaces the AI-only one, so a visitor can reach the support team on WhatsApp. */
function wf_landing_wc_key(): string { foreach ((array) wi_read('webchat.json', []) as $k => $x) if (!empty($x['landing']) && !empty($x['on'])) return (string) $k; return ''; }
/** The chat widget, added to the product page when the chat is on. */
function wf_landing_chat_widget(string $lang): string {
    if (!wi_sys('landing_chat_on')) return '';
    if (($wk = wf_landing_wc_key()) !== '') return '<script src="./?k=' . $wk . '&amp;wc=js" defer></script>';
    $j = fn($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS); $h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $w = []; foreach (['title' => 'Ask us anything', 'hello' => 'Hi! Ask me about the product, the plans or how to start.', 'ph' => 'Write your question', 'send' => 'Send', 'err' => 'Could not answer now. Try again.'] as $k => $en) $w[$k] = wf_landing_t($en, $lang);
    $dir = $lang === 'en' ? 'ltr' : 'rtl'; $side = $lang === 'en' ? 'right' : 'left';
    return '<style>#lc-b{position:fixed;bottom:calc(18px + env(safe-area-inset-bottom,0px));' . $side . ':18px;z-index:60;width:58px;height:58px;border-radius:50%;border:0;background:#25d366;color:#fff;font-size:26px;cursor:pointer;box-shadow:0 8px 24px rgba(0,0,0,.25)}'
        . '#lc-w{position:fixed;bottom:calc(86px + env(safe-area-inset-bottom,0px));' . $side . ':18px;z-index:60;width:min(360px,calc(100vw - 36px));height:min(480px,70vh);background:#fff;color:#111;border-radius:18px;box-shadow:0 18px 48px rgba(0,0,0,.3);display:flex;flex-direction:column;overflow:hidden;direction:' . $dir . ';font:14px/1.6 system-ui,Cairo,sans-serif}'
        . '#lc-w[hidden]{display:none}#lc-h{background:#075e54;color:#fff;padding:12px 16px;font-weight:600}#lc-m{flex:1;overflow-y:auto;padding:12px;display:flex;flex-direction:column;gap:8px;background:#efeae2}'
        . '.lc-x{max-width:85%;padding:8px 12px;border-radius:12px;white-space:pre-wrap;overflow-wrap:anywhere}.lc-x.me{align-self:flex-end;background:#d9fdd3}.lc-x.bot{align-self:flex-start;background:#fff}'
        . '#lc-f{display:flex;gap:6px;padding:8px;border-top:1px solid #ddd}#lc-i{flex:1;min-width:0;border:1px solid #ccc;border-radius:20px;padding:8px 12px;font:inherit}#lc-s{border:0;border-radius:20px;background:#25d366;color:#fff;padding:0 14px;font:inherit;cursor:pointer}</style>'
        . '<button id="lc-b" type="button" aria-label="' . $h($w['title']) . '">💬</button><div id="lc-w" hidden><div id="lc-h">' . $h($w['title']) . '</div><div id="lc-m"></div><form id="lc-f"><input id="lc-i" maxlength="500" autocomplete="off" placeholder="' . $h($w['ph']) . '"><button id="lc-s">' . $h($w['send']) . '</button></form></div>'
        . '<script>(function(){var W=' . $j($w) . ',L=' . $j($lang) . ',H=[],b=document.getElementById("lc-b"),w=document.getElementById("lc-w"),m=document.getElementById("lc-m"),f=document.getElementById("lc-f"),i=document.getElementById("lc-i");'
        . 'function add(t,me){var d=document.createElement("div");d.className="lc-x "+(me?"me":"bot");var im=[];t=String(t).replace(/\[img:([a-z0-9-]+)\]/g,function(_,k){im.push(k);return""}).trim();d.textContent=t;im.forEach(function(k){var g=document.createElement("img");g.src="./?guide_img="+k+"&lang="+L;g.style.cssText="display:block;width:100%;border-radius:10px;margin-top:6px";d.appendChild(g)});m.appendChild(d);m.scrollTop=m.scrollHeight;return d}'
        . 'b.onclick=function(){w.hidden=!w.hidden;if(!w.hidden&&!m.children.length)add(W.hello,false);if(!w.hidden)i.focus()};'
        . 'f.onsubmit=function(e){e.preventDefault();var t=i.value.trim();if(!t)return;i.value="";add(t,true);var p=add("...",false);'
        . 'fetch("./?landing_chat=1",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({msg:t,hist:H.slice(-8),lang:L})}).then(function(r){return r.json()}).then(function(d){p.textContent=d&&d.reply?d.reply:W.err;H.push({me:1,text:t});if(d&&d.reply)H.push({me:0,text:d.reply})}).catch(function(){p.textContent=W.err})}})();</script>';
}
