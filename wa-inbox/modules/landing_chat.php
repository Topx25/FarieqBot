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
    return 'Brand: ' . wi_sys('brand_name') . "\nPlans:\n" . ($L ? implode("\n", $L) : '- none published') . "\nSign-up open: " . (wi_sys('signup_on') ? 'yes' : 'no') . "\n" . trim((string) wi_sys('landing_chat_facts'));
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
    $hist = ''; foreach (array_slice((array) ($in['hist'] ?? []), -8) as $h) if (is_array($h)) $hist .= (!empty($h['me']) ? 'Visitor: ' : 'Assistant: ') . mb_substr(trim((string) ($h['text'] ?? '')), 0, 400) . "\n";
    $ai = wi_ai('Product page chat', wf_prompt('landing_chat', '', ['facts' => wf_landing_facts(), 'history' => $hist, 'message' => $msg, 'lang' => $lang === 'en' ? 'English' : 'Egyptian Arabic']), 60, ['subject' => 'landing']);
    $reply = trim((string) ($ai['data']['reply'] ?? ''));
    wi_log('landing_chat', ['ok' => $reply !== '', 'lang' => $lang]);
    $out(['ok' => true, 'reply' => $reply !== '' ? mb_substr($reply, 0, 1200) : wf_landing_t('Could not answer now. Try again.', $lang)]);
}
/** The chat widget, added to the product page when the chat is on. */
function wf_landing_chat_widget(string $lang): string {
    if (!wi_sys('landing_chat_on')) return '';
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
        . 'function add(t,me){var d=document.createElement("div");d.className="lc-x "+(me?"me":"bot");d.textContent=t;m.appendChild(d);m.scrollTop=m.scrollHeight;return d}'
        . 'b.onclick=function(){w.hidden=!w.hidden;if(!w.hidden&&!m.children.length)add(W.hello,false);if(!w.hidden)i.focus()};'
        . 'f.onsubmit=function(e){e.preventDefault();var t=i.value.trim();if(!t)return;i.value="";add(t,true);var p=add("...",false);'
        . 'fetch("./?landing_chat=1",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({msg:t,hist:H.slice(-8),lang:L})}).then(function(r){return r.json()}).then(function(d){p.textContent=d&&d.reply?d.reply:W.err;H.push({me:1,text:t});if(d&&d.reply)H.push({me:0,text:d.reply})}).catch(function(){p.textContent=W.err})}})();</script>';
}
