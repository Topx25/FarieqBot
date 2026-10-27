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
    $text = trim((string) wi_sys($kind . '_text')); if ($text === '') $text = wf_legal_default($kind);
    $site = parse_url((string) wi_sys('base_url'), PHP_URL_HOST) ?: (string) ($_SERVER['HTTP_HOST'] ?? '');
    $owner = trim((string) wi_sys('legal_owner')) ?: (string) wi_sys('brand_name'); $email = trim((string) wi_sys('legal_email'));
    $text = strtr($text, ['{product}' => (string) wi_sys('brand_name'), '{owner}' => $owner, '{site}' => $site, '{email}' => $email !== '' ? $email : $site]);
    $html = ''; foreach (preg_split('/\R/u', $text) as $l) { $l = trim($l); if ($l === '') continue; $html .= str_starts_with($l, '# ') ? '<h2 dir="auto">' . wi_e(substr($l, 2)) . '</h2>' : '<p dir="auto">' . wi_e($l) . '</p>'; }
    header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: public, max-age=600');
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . wi_e(($kind === 'terms' ? 'Terms of service' : 'Privacy policy') . ' — ' . wi_sys('brand_name')) . '</title>'
        . '<style>body{margin:0;background:#f6f8f7;color:#1f2a27;font:16px/1.7 system-ui,-apple-system,"Segoe UI",Tahoma,sans-serif}main{max-width:760px;margin:0 auto;padding:32px 20px 60px}h2{font-size:19px;margin:28px 0 6px}h2:first-child{font-size:26px;margin-top:0}p{margin:0 0 10px}'
        . '@media (prefers-color-scheme:dark){body{background:#0f1514;color:#e6ece9}}</style></head><body><main>' . $html . '</main></body></html>';
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
    $T = fn($s) => wi_e(wf_landing_t($s, $lang)); $rows = '';
    foreach (WF_DATA_INFO as $r) $rows .= '<div class="i"><span>' . $r[0] . '</span><div><b>' . $T($r[1]) . '</b><p>' . $T($r[2]) . '</p></div></div>';
    header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store');
    echo '<!doctype html><html lang="' . $lang . '" dir="' . ($lang === 'ar' ? 'rtl' : 'ltr') . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $T('Your data') . '</title>'
        . '<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;600&display=swap" rel="stylesheet">'
        . '<style>:root{--bg:#f6f5f2;--card:#fff;--fg:#16161a;--mut:#6b6b76;--line:rgba(22,22,26,.08);--gold:#b8925a;--gold2:#d9b97c;--tile:#efede8}@media (prefers-color-scheme:dark){:root{--bg:#0e0f12;--card:#17191f;--fg:#f2f1ee;--mut:#a3a3ad;--line:rgba(255,255,255,.08);--tile:#1c1e24}}'
        . 'body{margin:0;background:var(--bg);color:var(--fg);font:15px/1.8 "IBM Plex Sans Arabic",system-ui,sans-serif}main{max-width:680px;margin:28px auto;padding:0 16px}.c{background:var(--card);border:1px solid var(--line);border-radius:22px;box-shadow:0 18px 40px rgba(0,0,0,.08);padding:24px}'
        . 'h1{font-size:22px;margin:0 0 4px}.m{color:var(--mut);font-size:13.5px;margin:0 0 14px}.i{display:flex;gap:14px;padding:14px 0;border-top:1px solid var(--line)}.i>span{width:42px;height:42px;flex:none;border-radius:13px;background:var(--tile);display:grid;place-items:center;font-size:20px}.i b{font-size:15.5px}.i p{margin:2px 0 0;color:var(--mut);font-size:14px}'
        . '.b{display:flex;gap:10px;margin-top:16px}.b button{margin:0}button.no{background:none;border:1px solid var(--line);color:var(--fg)}button{width:100%;margin-top:16px;border:0;border-radius:12px;padding:13px;background:linear-gradient(135deg,var(--gold),var(--gold2));color:#1b1406;font:inherit;font-weight:600;cursor:pointer}</style></head><body><main><div class="c">'
        . '<h1>🛡️ ' . $T('Your data') . '</h1><p class="m">' . $T('How your chats are used to teach the bot, and how they are protected.') . '</p>' . $rows
        . '<form method="post"><input type="hidden" name="_csrf" value="' . wi_e(wi_csrf()) . '"><input type="hidden" name="do" value="terms_accept"><div class="b"><button name="info" value="yes" onclick="return confirm(' . wi_e(json_encode(wf_landing_t('Confirm: your chat data will be kept to teach the bot.', $lang), JSON_UNESCAPED_UNICODE)) . ')">' . $T('I agree') . '</button><button name="info" value="no" class="no" onclick="return confirm(' . wi_e(json_encode(wf_landing_t('Are you sure? Without your chat data you will have to upload files for the bot to learn from, which uses more tokens and takes longer.', $lang), JSON_UNESCAPED_UNICODE)) . ')">' . $T('I refuse') . '</button></div><p class="m" style="margin-top:10px">' . $T('If you refuse, the bot learns only from the files you upload to the knowledge base.') . '</p></form></div></main></body></html>';
}
