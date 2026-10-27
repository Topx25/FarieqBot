<?php
/* ---------- Public privacy policy and terms of service ----------
 * Facebook, Google and the app stores ask for these addresses: index.php?privacy and index.php?terms (no sign-in).
 * The text is written in Settings → System (privacy_text / terms_text); empty = the built-in general text below, filled with the
 * product name, the owner's name and the contact e-mail from the same settings. Lines starting with "# " are headings. */

/** The built-in text, one line per entry ("# " = heading), in English; the Arabic page is the same lines through the interface dictionary. */
function wf_legal_lines(string $kind): array {
    if ($kind === 'terms') return [
        '# Terms of service',
        '{product} is a messaging dashboard run by {owner} ({site}). By using it you agree to these terms.',
        '# The service',
        'The dashboard connects the business accounts you choose (WhatsApp numbers, Facebook pages) and lets your team read and answer their messages, comments and posts, with optional automatic replies.',
        '# Your responsibilities',
        'You connect only accounts you are allowed to manage, you follow the rules of WhatsApp and Meta, and you do not send spam or unlawful content.',
        '# Linking by QR code',
        'Numbers are linked the way WhatsApp Web is (a QR code), not through Meta\'s official business API. Using a number stays subject to WhatsApp\'s terms and rules: bulk or unwanted messages, spam or many messages to new contacts can get the number limited or banned by WhatsApp. We are not responsible for a number stopped by WhatsApp because of such use.',
        '# Your data belongs to you',
        'All the data of your account (messages, contacts, files, cases, reports and settings) belongs to you alone.',
        'Your data is kept in a separate, protected place for your account only; other clients cannot see it or reach it.',
        'We do not sell your data, do not share it with any party and do not use it for advertising.',
        'Nobody from our side opens your conversations or files, except when you ask us for technical support and allow it, or when the law requires it.',
        'When you turn on automatic replies, the message being answered is sent to the AI provider you chose, only to write the reply.',
        'You can download a copy of your data or ask for it to be deleted at any time.',
        '# Access to your account',
        'You are responsible for the people you add to your team and for what they can see and do.',
        'Keep your password and the sign-in codes private; we never ask you for them.',
        'Tell us at once if you think someone used your account without your permission.',
        '# Your responsibility for content and use',
        'You alone are responsible for every message, file and reply sent from your numbers, whether written by you, your team or your bot.',
        'You must have the consent of the people you message, and you must not send spam, bulk messages to people who did not ask, or misleading content.',
        'You must not use the service for anything unlawful, harmful, offensive, or that breaks the rights of others.',
        'You are responsible for checking what the bot says to your customers and for the information you give it.',
        'If a number is limited or banned by WhatsApp or Meta because of how it was used, that is your responsibility.',
        'You keep us free of any claim that comes from your use of the service or the content you send.',
        '# Your customers\' data',
        'For the data of your own customers, you are the owner and the one responsible for it; we only keep and process it for you to run the service.',
        'You must follow the data protection laws of your country and tell your customers how you use their data.',
        '# Plans and payment',
        'Each plan has its limits (numbers, storage, AI use); when a limit is reached, the related feature stops until the next period or an upgrade.',
        'Late payment can pause the account; your data stays kept for a period so you can come back.',
        '# Limits of our responsibility',
        'We are not responsible for losses caused by WhatsApp, Meta, internet or AI provider outages, or by how you or your team used the service.',
        '# Changes to these terms',
        'We may update these terms; you will be asked to read and accept the new text before using the dashboard again.',
        '# Availability',
        'The service is provided as it is; we work to keep it running but cannot promise it will never stop. We may change or end features with notice.',
        '# Ending',
        'You can disconnect your accounts and ask us to delete your data at any time. We may suspend accounts that break these terms.',
        '# Contact',
        '{email}',
    ];
    return [
        '# Privacy policy',
        '{product} is run by {owner} ({site}). This page explains what data the service handles and why.',
        '# What we handle',
        'When a business connects a WhatsApp number or a Facebook page, the service receives the messages, comments, names, profile pictures and files sent to that account, and the replies the business sends. It also keeps the access tokens needed to work with the account, stored encrypted.',
        '# Why',
        'Only to show these conversations to the business and its team, to send their replies, and — when the business turns it on — to suggest or send automatic replies. We do not sell data and do not use it for advertising.',
        '# Sharing',
        'Data is shared only with the services needed to run the dashboard (the messaging platforms themselves and, for automatic replies, an AI provider chosen by the business), and when the law requires it.',
        '# Keeping and deleting',
        'Data is kept while the account stays connected. A business can disconnect an account at any time; a person can ask for their data to be deleted by writing to {email}, and Facebook users can also use Facebook\'s data deletion request. We confirm and complete deletion within 30 days.',
        '# Security',
        'Access needs a sign-in with a one-time code; tokens and private files are encrypted; only the business\'s team sees its data.',
        '# Contact',
        '{email}',
    ];
}
function wf_legal_default(string $kind): string {
    static $ar = null; $ar ??= (array) require __DIR__ . '/../i18n.php'; $L = wf_legal_lines($kind);
    return implode("\n", $L) . "\n\n" . implode("\n", array_map(fn($l) => $ar[$l] ?? $l, $L)); // English, then Arabic
}
function wf_legal_page(string $kind): void {
    $kind = $kind === 'terms' ? 'terms' : 'privacy';
    $lang = in_array($_GET['lang'] ?? '', ['ar', 'en'], true) ? (string) $_GET['lang'] : wf_terms_lang(); $T = fn($x) => wi_e(wf_landing_t($x, $lang));
    if ($kind === 'terms') $text = wf_terms_text($lang);
    else { $text = trim((string) wi_sys('privacy_text')); if ($text === '') { $ar = $lang === 'ar' ? (array) require __DIR__ . '/../i18n.php' : []; $text = implode("\n", array_map(fn($l) => $ar[$l] ?? $l, wf_legal_lines('privacy'))); }
        $site = parse_url((string) wi_sys('base_url'), PHP_URL_HOST) ?: (string) ($_SERVER['HTTP_HOST'] ?? ''); $owner = trim((string) wi_sys('legal_owner')) ?: (string) wi_sys('brand_name'); $email = trim((string) wi_sys('legal_email'));
        $text = strtr($text, ['{product}' => (string) wi_sys('brand_name'), '{owner}' => $owner, '{site}' => $site, '{email}' => $email !== '' ? $email : $site]); }
    $html = ''; foreach (preg_split('/\R/u', $text) as $l) { $l = trim($l); if ($l === '') continue; $html .= str_starts_with($l, '# ') ? '<h2>' . wi_e(substr($l, 2)) . '</h2>' : '<p>' . wi_e($l) . '</p>'; }
    // the data card the clients see after the terms: how their chats teach the bot and how they are protected
    $data = ''; foreach (WF_DATA_INFO as $r) $data .= '<div class="i"><span>' . $r[0] . '</span><div><b>' . $T($r[1]) . '</b><p>' . $T($r[2]) . '</p></div></div>';
    $title = $kind === 'terms' ? 'Terms of service' : 'Privacy policy'; $other = $kind === 'terms' ? ['privacy', 'Privacy policy'] : ['terms', 'Terms of service'];
    header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: public, max-age=600');
    echo '<!doctype html><html lang="' . $lang . '" dir="' . ($lang === 'ar' ? 'rtl' : 'ltr') . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $T($title) . ' — ' . wi_e((string) wi_sys('brand_name')) . '</title>'
        . '<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;600;700&display=swap" rel="stylesheet">'
        . '<style>:root{--bg:#f1f4f7;--card:#fff;--fg:#1c2b33;--mut:#465a69;--line:#1c2b3314;--blue:#0866ff;--gold:#c9a24a;--tile:#e7eef8}@media (prefers-color-scheme:dark){:root{--bg:#0a1317;--card:#1c2b33;--fg:#f1f4f7;--mut:#c5d0d8;--line:#ffffff17;--tile:#26363f}}'
        . 'body{margin:0;background:var(--bg);color:var(--fg);font:16px/1.85 "IBM Plex Sans Arabic",system-ui,sans-serif}header{background:var(--card);border-bottom:1px solid var(--line)}header div{max-width:820px;margin:auto;padding:14px 20px;display:flex;align-items:center;gap:12px}header b{flex:1;font-size:18px}header a{color:var(--blue);text-decoration:none;font-size:14px;border:1px solid var(--line);border-radius:99px;padding:5px 14px}'
        . 'main{max-width:820px;margin:0 auto;padding:28px 20px 60px}.c{background:var(--card);border:1px solid var(--line);border-radius:22px;padding:26px 28px;margin-bottom:18px}h1{font-size:28px;margin:0 0 4px}h1+small{color:var(--mut)}h2{font-size:18px;margin:26px 0 6px;padding-inline-start:12px;border-inline-start:3px solid var(--gold)}p{margin:0 0 10px;color:var(--mut)}'
        . '.i{display:flex;gap:14px;padding:14px 0;border-top:1px solid var(--line)}.i>span{width:42px;height:42px;flex:none;border-radius:13px;background:var(--tile);display:grid;place-items:center;font-size:20px}.i b{color:var(--fg)}.i p{margin:2px 0 0}footer{text-align:center;color:var(--mut);font-size:13px}footer a{color:var(--blue)}</style></head><body>'
        . '<header><div><b>' . wi_e((string) wi_sys('brand_name')) . '</b><a href="?' . $kind . '=1&amp;lang=' . ($lang === 'ar' ? 'en">English' : 'ar">' . wi_e(wf_landing_t('Arabic', 'ar'))) . '</a><a href="./">' . $T('Home') . '</a></div></header><main>'
        . '<div class="c"><h1>' . $T($title) . '</h1><small>' . $T('Last updated') . ' <span dir="ltr">' . date('Y-m-d', (int) @filemtime(__FILE__)) . '</span></small>' . $html . '</div>'
        . '<div class="c"><h1>🛡️ ' . $T('Your data') . '</h1><small>' . $T('How your chats are used to teach the bot, and how they are protected.') . '</small>' . $data . '</div>'
        . '<footer><a href="?' . $other[0] . '=1&amp;lang=' . $lang . '">' . $T($other[1]) . '</a></footer></main></body></html>';
    exit;
}

/* ---------- Terms on a client's first sign-in ----------
 * A client sees the terms once before the dashboard (again when the text changes): Arabic when they sign in from an Arab country,
 * English from anywhere else (no country known: the interface language). The acceptance is kept with the time, IP, country and language. */
const WF_ARAB_CC = ['EG', 'SA', 'AE', 'KW', 'QA', 'BH', 'OM', 'YE', 'IQ', 'SY', 'JO', 'LB', 'PS', 'LY', 'TN', 'DZ', 'MA', 'SD', 'MR', 'SO', 'DJ', 'KM'];
/** The terms text with the placeholders filled, in one language. */
function wf_terms_text(string $lang): string {
    $own = trim((string) wi_sys('terms_text'));
    if ($own !== '') { $L = preg_split('/\R/u', $own); } else { $ar = $lang === 'ar' ? (array) require __DIR__ . '/../i18n.php' : []; $L = array_map(fn($l) => $ar[$l] ?? $l, wf_legal_lines('terms')); }
    $site = parse_url((string) wi_sys('base_url'), PHP_URL_HOST) ?: (string) ($_SERVER['HTTP_HOST'] ?? ''); $email = trim((string) wi_sys('legal_email'));
    $brand = $lang === 'en' ? (trim((string) wi_sys('brand_name_en')) ?: (string) wi_sys('brand_name')) : (string) wi_sys('brand_name'); // the English text names the product in English
    return strtr(implode("\n", $L), ['{product}' => $brand, '{owner}' => trim((string) wi_sys('legal_owner')) ?: $brand, '{site}' => $site, '{email}' => $email !== '' ? $email : $site]);
}
function wf_terms_lang(): string { $cc = wi_ip_country(); if ($cc !== '') return in_array($cc, WF_ARAB_CC, true) ? 'ar' : 'en'; return wi_lang() === 'ar' ? 'ar' : 'en'; }
function wf_terms_ver(): string { return substr(sha1(wf_terms_text('en')), 0, 12); }
/** This signed-in person still has to accept the terms (clients only; the super admin and team members never). */
function wf_terms_needed(): bool {
    $me = wi_me(); if (!$me || wi_is_super() || !empty($_SESSION['imp_from']) || ($me['role'] ?? '') !== 'client') return false; // the super admin viewing a client's panel is not asked
    $ok = (array) (((array) wi_read('terms_ok.json', []))[strtolower((string) $me['email'])] ?? []);
    return (string) ($ok['v'] ?? '') !== wf_terms_ver() || empty($ok['info']);
}
/** After the terms: one card on how the chats and groups data is used, kept and protected (accepted once). */
const WF_DATA_INFO = [
    ['💬', 'Your chats and groups', 'The messages of your private chats and groups that arrive from WhatsApp are kept on your account, so the AI can learn from them to answer on your behalf and on behalf of your team, in your own style.'],
    ['🧠', 'Why keeping them matters', 'If you choose not to keep the chat data, you will have to upload files for the bot to learn from in the knowledge base. That uses more tokens, takes longer to learn, and takes longer to tune the bot\'s reply style. It is not recommended.'],
    ['🔐', 'Our duty to protect your data', 'The site is bound to secure your data: it is never sold, shared or published, it is protected from damage and loss with backups, and it is encrypted to protect your privacy.'],
    ['🗑️', 'If you refuse', 'Every message of your numbers is deleted each time you sign out, and the bot then learns only from files you upload to the knowledge base. Teaching it from files directly costs you more tokens, takes longer, and its reply style takes longer to tune.'],
    ['👁️', 'You see who uses it', 'A section of your dashboard (Team → Activity) records every time a member of your team opens your data and what they did with it.'],
];

/** POST do=terms_accept: kept with the time, IP, country and language. */
function wf_terms_accept(): void {
    $me = wi_me(); if ($me && !empty($_POST['info'])) { $em = strtolower((string) $me['email']); wi_update('terms_ok.json', function ($A) use ($em) { $A = (array) $A; $keep = (string) $_POST['info'] === 'yes' ? 1 : 0; if (isset($A[$em])) { $A[$em]['info'] = date('c'); $A[$em]['keep'] = $keep; } return $A; }); wi_log('data_info_seen', ['email' => $em, 'keep' => (string) $_POST['info'] === 'yes']); header('Location: ./'); exit; }
    if (!$me || empty($_POST['agree'])) { header('Location: ./'); exit; } $em = strtolower((string) $me['email']); $row = ['v' => wf_terms_ver(), 'at' => date('c'), 'ip' => wi_ip(), 'country' => wi_ip_country(), 'lang' => (string) ($_POST['lang'] ?? '')];
    wi_update('terms_ok.json', function ($A) use ($em, $row) { $A = (array) $A; $A[$em] = $row; return $A; }); wi_log('terms_accept', ['email' => $em, 'v' => $row['v'], 'country' => $row['country']]); header('Location: ./'); exit;
}
/** The page shown instead of the dashboard until the terms are accepted. */
function wf_terms_gate(): void {
    $lang = in_array($_GET['tl'] ?? '', ['ar', 'en'], true) ? (string) $_GET['tl'] : wf_terms_lang(); $T = fn($s) => wi_e(wf_landing_t($s, $lang)); $html = '';
    $me = wi_me(); $ok = (array) (((array) wi_read('terms_ok.json', []))[strtolower((string) ($me['email'] ?? ''))] ?? []);
    if ((string) ($ok['v'] ?? '') === wf_terms_ver()) { wf_data_info_card($lang); exit; }
    foreach (preg_split('/\R/u', wf_terms_text($lang)) as $l) { $l = trim($l); if ($l === '') continue; $html .= str_starts_with($l, '# ') ? '<h2>' . wi_e(substr($l, 2)) . '</h2>' : '<p>' . wi_e($l) . '</p>'; }
    header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store');
    echo '<!doctype html><html lang="' . $lang . '" dir="' . ($lang === 'ar' ? 'rtl' : 'ltr') . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $T('Terms of service') . '</title>'
        . '<style>:root{--bg:#f0f2f5;--card:#fff;--fg:#1c1e21;--mut:#65676b;--line:#e4e6eb;--acc:#0866ff}@media (prefers-color-scheme:dark){:root{--bg:#18191a;--card:#242526;--fg:#e4e6eb;--mut:#b0b3b8;--line:#3e4042}}'
        . 'body{margin:0;background:var(--bg);color:var(--fg);font:15px/1.7 system-ui,-apple-system,"Segoe UI",Tahoma,sans-serif}main{max-width:720px;margin:24px auto;padding:0 16px}.c{background:var(--card);border-radius:14px;box-shadow:0 1px 3px rgba(0,0,0,.08);padding:20px 22px}'
        . '.t{max-height:55vh;overflow:auto;border:1px solid var(--line);border-radius:10px;padding:4px 16px;margin:12px 0}h1{font-size:22px;margin:0}h2{font-size:16px;margin:16px 0 4px}p{margin:0 0 6px}.m{color:var(--mut);font-size:13px}'
        . 'label{display:flex;gap:8px;align-items:flex-start;margin:12px 0;cursor:pointer}button{width:100%;border:0;border-radius:10px;padding:12px;background:var(--acc);color:#fff;font:inherit;font-weight:700;cursor:pointer}button:disabled{opacity:.5;cursor:default}.r{display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;margin-top:12px}a{color:var(--acc)}</style></head><body><main><div class="c">'
        . '<h1>📄 ' . $T('Terms of service') . '</h1><p class="m">' . $T('Please read and accept the terms to start using your dashboard.') . '</p><div class="t">' . $html . '</div>'
        . '<form method="post"><input type="hidden" name="_csrf" value="' . wi_e(wi_csrf()) . '"><input type="hidden" name="do" value="terms_accept"><input type="hidden" name="lang" value="' . $lang . '">'
        . '<label><input type="checkbox" name="agree" value="1" required onchange="this.form.querySelector(\'button\').disabled=!this.checked"> <span>' . $T('I have read the terms and I agree to them.') . '</span></label><button disabled>' . $T('Agree and continue') . '</button></form>'
        . '<div class="r m"><a href="?tl=' . ($lang === 'ar' ? 'en">English' : 'ar">' . wi_e(wf_landing_t('Arabic', 'ar'))) . '</a><form method="post" style="margin:0"><input type="hidden" name="_csrf" value="' . wi_e(wi_csrf()) . '"><input type="hidden" name="do" value="logout"><button style="width:auto;background:none;color:var(--acc);padding:0;font-weight:400">' . $T('Sign out') . '</button></form></div></div></main></body></html>';
    exit;
}
/** The data card shown once after the terms. */
function wf_data_info_card(string $lang): void {
    $T = fn($s) => wi_e(wf_landing_t($s, $lang)); $rows = ''; $li = fn(array $L) => '<ul>' . implode('', array_map(fn($x) => '<li>' . $T($x) . '</li>', $L)) . '</ul>';
    foreach (WF_DATA_INFO as $r) $rows .= '<div class="i"><span>' . $r[0] . '</span><div><b>' . $T($r[1]) . '</b><p>' . $T($r[2]) . '</p></div></div>';
    $yes = $li(['Your private chats and groups messages are kept on your account.', 'The bot learns from them how you and your team answer, at the lowest token cost.', 'You can still upload files to the knowledge base at any time.']);
    $no = $li(['Every message of your numbers (private chats, groups and their media) is deleted each time you sign out.', 'The bot cannot learn from your real conversations: you must upload files with your answers to the knowledge base.', 'Teaching the bot from files directly costs you more tokens, takes longer to learn, and takes longer to tune its reply style.', 'Only your own sign out deletes them, and anyone on your team who is working at that moment loses these messages too.', 'Deleted messages cannot be brought back.']);
    $dlg = fn(string $id, string $v, string $title, string $list, string $btn, string $cls) => '<div class="dl" id="' . $id . '" hidden><div class="dc"><h2>' . $T($title) . '</h2>' . $list
        . '<label class="ck"><input type="checkbox" required onchange="this.closest(\'.dc\').querySelector(\'.go\').disabled=!this.checked"> <span>' . $T('I have read this and I understand it.') . '</span></label>'
        . '<div class="b"><button name="info" value="' . $v . '" class="go ' . $cls . '" disabled>' . $T($btn) . '</button><button type="button" class="no" onclick="this.closest(\'.dl\').hidden=true">' . $T('Back') . '</button></div></div></div>';
    header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store');
    echo '<!doctype html><html lang="' . $lang . '" dir="' . ($lang === 'ar' ? 'rtl' : 'ltr') . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $T('Your data') . '</title>'
        . '<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;600&display=swap" rel="stylesheet">'
        . '<style>:root{--bg:#f6f5f2;--card:#fff;--fg:#16161a;--mut:#6b6b76;--line:rgba(22,22,26,.1);--gold:#b8925a;--gold2:#d9b97c;--tile:#efede8;--bad:#b42318}@media (prefers-color-scheme:dark){:root{--bg:#0e0f12;--card:#17191f;--fg:#f2f1ee;--mut:#a3a3ad;--line:rgba(255,255,255,.1);--tile:#1c1e24;--bad:#f97066}}'
        . 'body{margin:0;background:var(--bg);color:var(--fg);font:15px/1.8 "IBM Plex Sans Arabic",system-ui,sans-serif}main{max-width:700px;margin:28px auto;padding:0 16px}.c{background:var(--card);border:1px solid var(--line);border-radius:22px;box-shadow:0 18px 40px rgba(0,0,0,.08);padding:24px}'
        . 'h1{font-size:22px;margin:0 0 4px}h2{font-size:18px;margin:0 0 8px}.m{color:var(--mut);font-size:13.5px;margin:0 0 14px}.i{display:flex;gap:14px;padding:14px 0;border-top:1px solid var(--line)}.i>span{width:42px;height:42px;flex:none;border-radius:13px;background:var(--tile);display:grid;place-items:center;font-size:20px}.i b{font-size:15.5px}.i p{margin:2px 0 0;color:var(--mut);font-size:14px}'
        . '.b{display:flex;gap:10px;margin-top:16px}button{flex:1;border:0;border-radius:12px;padding:13px;background:linear-gradient(135deg,var(--gold),var(--gold2));color:#1b1406;font:inherit;font-weight:600;cursor:pointer}button.no{background:none;border:1px solid var(--line);color:var(--fg)}button.bad{background:var(--bad);color:#fff}button:disabled{opacity:.45;cursor:default}'
        . '.dl{position:fixed;inset:0;background:rgba(0,0,0,.45);display:grid;place-items:center;padding:16px}.dl[hidden]{display:none}.dc{background:var(--card);border-radius:20px;padding:22px;max-width:520px;box-shadow:0 24px 60px rgba(0,0,0,.3)}.dc ul{margin:0;padding-inline-start:20px}.dc li{margin:4px 0}.ck{display:flex;gap:8px;align-items:flex-start;margin-top:14px;cursor:pointer}</style></head><body><main><div class="c">'
        . '<h1>🛡️ ' . $T('Your data') . '</h1><p class="m">' . $T('How your chats are used to teach the bot, and how they are protected.') . '</p>' . $rows
        . '<form method="post"><input type="hidden" name="_csrf" value="' . wi_e(wi_csrf()) . '"><input type="hidden" name="do" value="terms_accept">'
        . '<div class="b"><button type="button" onclick="document.getElementById(\'dy\').hidden=false">' . $T('I agree') . '</button><button type="button" class="no" onclick="document.getElementById(\'dn\').hidden=false">' . $T('I refuse') . '</button></div>'
        . $dlg('dy', 'yes', 'Keep my chat data to teach the bot', $yes, 'Confirm and continue', '') . $dlg('dn', 'no', 'Refuse: delete my messages when I sign out', $no, 'Confirm the refusal', 'bad') . '</form></div></main></body></html>';
}
/** A client who refused: on sign out every message and media file of their own numbers is deleted (the numbers and settings stay). */
function wf_data_keep(string $email): bool { $ok = (array) (((array) wi_read('terms_ok.json', []))[strtolower($email)] ?? []); return !array_key_exists('keep', $ok) || (int) $ok['keep'] === 1; }
function wf_data_purge_chats(string $email): int {
    if (wf_data_keep($email)) return 0; $root = dirname(__DIR__, 2); $n = 0;
    $rm = function (string $p) use (&$rm, &$n) { if (is_link($p) || is_file($p)) { $n++; return @unlink($p); } if (!is_dir($p)) return false; foreach (array_diff((array) scandir($p), ['.', '..']) as $x) $rm($p . '/' . $x); return true; };
    foreach (wi_owned_accounts($email) as $id => $a) { $id = (string) ($a['id'] ?? $id); if (!empty($a['main']) || !preg_match('/^[a-z0-9_-]+$/', $id)) continue; $rm($root . '/wa-local/accounts/' . $id . '/archive'); $rm($root . '/storage/inbox_media/' . $id); }
    wi_log('chats_purged_on_signout', ['email' => strtolower($email), 'files' => $n]); return $n;
}
