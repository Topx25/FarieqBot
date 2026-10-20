<?php
/* ---------- Public privacy policy and terms of service ----------
 * Facebook, Google and the app stores ask for these addresses: index.php?privacy and index.php?terms (no sign-in).
 * The text is written in Settings → System (privacy_text / terms_text); empty = the built-in general text below, filled with the
 * product name, the owner's name and the contact e-mail from the same settings. Lines starting with "# " are headings. */

function wf_legal_default(string $kind): string {
    if ($kind === 'terms') return "# Terms of service\n{product} is a messaging dashboard run by {owner} ({site}). By using it you agree to these terms.\n"
        . "# The service\nThe dashboard connects the business accounts you choose (WhatsApp numbers, Facebook pages) and lets your team read and answer their messages, comments and posts, with optional automatic replies.\n"
        . "# Your responsibilities\nYou connect only accounts you are allowed to manage, you follow the rules of WhatsApp and Meta, and you do not send spam or unlawful content.\n"
        . "# Availability\nThe service is provided as it is; we work to keep it running but cannot promise it will never stop. We may change or end features with notice.\n"
        . "# Ending\nYou can disconnect your accounts and ask us to delete your data at any time. We may suspend accounts that break these terms.\n"
        . "# Contact\n{email}\n\n"
        . "# شروط الاستخدام\n{product} لوحة لإدارة الرسائل يشغّلها {owner} ({site}). باستخدامك لها توافق على هذه الشروط.\n"
        . "# الخدمة\nتربط اللوحة حسابات النشاط التي تختارها (أرقام واتساب وصفحات فيسبوك) ويقرأ فريقك رسائلها وتعليقاتها ومنشوراتها ويرد عليها، مع ردود تلقائية اختيارية.\n"
        . "# مسؤولياتك\nتربط فقط الحسابات المسموح لك بإدارتها، وتلتزم بقواعد واتساب وميتا، ولا ترسل رسائل مزعجة أو محتوى مخالفاً للقانون.\n"
        . "# التوفر\nتُقدَّم الخدمة كما هي؛ نعمل على استمرارها دون ضمان عدم توقفها، وقد نغيّر الميزات أو نوقفها مع إشعار.\n"
        . "# الإنهاء\nيمكنك فصل حساباتك وطلب حذف بياناتك في أي وقت، وقد نوقف الحسابات المخالفة لهذه الشروط.\n"
        . "# التواصل\n{email}";
    return "# Privacy policy\n{product} is run by {owner} ({site}). This page explains what data the service handles and why.\n"
        . "# What we handle\nWhen a business connects a WhatsApp number or a Facebook page, the service receives the messages, comments, names, profile pictures and files sent to that account, and the replies the business sends. It also keeps the access tokens needed to work with the account, stored encrypted.\n"
        . "# Why\nOnly to show these conversations to the business and its team, to send their replies, and — when the business turns it on — to suggest or send automatic replies. We do not sell data and do not use it for advertising.\n"
        . "# Sharing\nData is shared only with the services needed to run the dashboard (the messaging platforms themselves and, for automatic replies, an AI provider chosen by the business), and when the law requires it.\n"
        . "# Keeping and deleting\nData is kept while the account stays connected. A business can disconnect an account at any time; a person can ask for their data to be deleted by writing to {email}, and Facebook users can also use Facebook's data deletion request. We confirm and complete deletion within 30 days.\n"
        . "# Security\nAccess needs a sign-in with a one-time code; tokens and private files are encrypted; only the business's team sees its data.\n"
        . "# Contact\n{email}\n\n"
        . "# سياسة الخصوصية\n{product} يشغّلها {owner} ({site}). توضح هذه الصفحة البيانات التي تتعامل معها الخدمة ولماذا.\n"
        . "# ما نتعامل معه\nعند ربط النشاط لرقم واتساب أو صفحة فيسبوك، تستقبل الخدمة الرسائل والتعليقات والأسماء وصور الملف الشخصي والملفات المرسلة لهذا الحساب، وردود النشاط عليها، وتحتفظ برموز الوصول اللازمة مشفّرة.\n"
        . "# لماذا\nفقط لعرض هذه المحادثات للنشاط وفريقه، وإرسال ردودهم، واقتراح أو إرسال ردود تلقائية عندما يفعّلها النشاط. لا نبيع البيانات ولا نستخدمها للإعلانات.\n"
        . "# المشاركة\nتُشارك البيانات فقط مع الخدمات اللازمة لتشغيل اللوحة (منصات المراسلة نفسها، ومزوّد الذكاء الاصطناعي الذي يختاره النشاط للردود التلقائية)، وعندما يطلب القانون ذلك.\n"
        . "# الحفظ والحذف\nتُحفظ البيانات ما دام الحساب مربوطاً. يمكن للنشاط فصل الحساب في أي وقت، ويمكن لأي شخص طلب حذف بياناته بالكتابة إلى {email}، ولمستخدمي فيسبوك أيضاً استخدام طلب حذف البيانات من فيسبوك. نؤكد الحذف وننفذه خلال 30 يوماً.\n"
        . "# الأمان\nالدخول برمز لمرة واحدة، ورموز الوصول والملفات الخاصة مشفّرة، ولا يرى بيانات النشاط إلا فريقه.\n"
        . "# التواصل\n{email}";
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
