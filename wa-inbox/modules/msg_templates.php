<?php
/* ---------- Message templates: every text the system itself sends on WhatsApp (alerts, codes, reminders, report lines).
 * Edited in Settings → Message templates; {placeholders} are filled when sending. The texts below are only the starting values,
 * in the language chosen in Settings → System ("msg_lang"). ---------- */
/** Is this text one of the messages the system sends by itself (login code, alerts, reports, reminders…)? Built from the templates
 *  (the installation's own wording and the defaults, both languages), so the bot never answers them — even from a test number. */
function wf_is_system_text(string $text): bool {
    static $rx = null; $text = trim($text); if ($text === '') return false;
    if ($rx === null) { $rx = []; $own = (array) wi_read('msg_templates.json', []);
        foreach (wf_msg_registry() as $k => $m) foreach (array_filter([(string) ($own[$k]['ar'] ?? ''), (string) ($own[$k]['en'] ?? ''), (string) ($m[2] ?? ''), (string) ($m[3] ?? '')]) as $tpl) {
            $head = trim(mb_substr(preg_split('/\{\w+\}/u', $tpl)[0], 0, 40)); if (mb_strlen($head) >= 8) $rx[] = '/^' . preg_quote($head, '/') . '/u'; } } // the fixed start of each template
    foreach ($rx as $r) if (preg_match($r, $text)) return true;
    return false;
}
function wf_msg_registry(): array {
    return [
        'otp' => ['Login code', ['brand', 'code', 'minutes'], "🔐 كود الدخول لـ {brand}: {code}\nصالح لمدة {minutes} دقايق. لو ما طلبتهوش تجاهل الرسالة.", "🔐 {brand} verification code: {code}\nValid for {minutes} minutes. If you did not request it, ignore this message."],
        'alert_resources' => ['Server resources alert', ['mem', 'mem_limit', 'threads', 'threads_limit'], "⚠️ موارد السيرفر قربت من الحد\n🧠 الذاكرة: {mem} / {mem_limit} MB\n🧵 العمليات: {threads} / {threads_limit}", "⚠️ Server resources are close to the limit\n🧠 Memory: {mem} / {mem_limit} MB\n🧵 Processes: {threads} / {threads_limit}"],
        'alert_down' => ['Number health alert', ['number', 'reason', 'link'], "⚠️ تنبيه صحة الرقم\n📱 {number}\n{reason}\n{link}", "⚠️ Number health alert\n📱 {number}\n{reason}\n{link}"],
        'alert_back' => ['Number back online', ['number'], "✅ رجع الاتصال\n📱 {number}", "✅ Back online\n📱 {number}"],
        'alert_send_failed' => ['Many failed sends', ['number', 'count'], "⚠️ {number}: {count} رسالة فشلت خلال 24 ساعة — راجع صفحة صحة الرقم.", "⚠️ {number}: {count} messages failed in 24 hours — check the number health page."],
        'reason_logged_out' => ['Alert reason: number logged out', [], "تم تسجيل الخروج — لازم تربط الرقم تاني (QR)", "Logged out — link the number again (QR)"],
        'reason_gateway_down' => ['Alert reason: gateway not running', [], "البوابة لا تعمل", "The gateway is not running"],
        'reason_disconnected' => ['Alert reason: disconnected', ['error'], "مفصول: {error}", "Disconnected: {error}"],
        'client_expiring_admin' => ['Clients: a subscription ends soon (to admins)', ['name', 'days', 'date', 'email'], "⏳ اشتراك العميل {name} ({email}) هينتهي خلال {days} يوم ({date}).", "⏳ {name} ({email}): the subscription ends in {days} day(s) ({date})."],
        'client_ended_admin' => ['Clients: a subscription ended (to admins)', ['name', 'date', 'email'], "⛔ اشتراك العميل {name} ({email}) انتهى يوم {date}. لوحته وبوته اتوقفوا.", "⛔ {name} ({email}): the subscription ended on {date}. The panel and its bot are stopped."],
        'client_expiring' => ['Clients: your subscription ends soon (to the client)', ['name', 'days', 'date'], "أهلاً {name}، اشتراكك في اللوحة هينتهي خلال {days} يوم ({date}). تواصل معانا للتجديد.", "Hello {name}, your subscription ends in {days} day(s) ({date}). Contact us to renew."],
        'client_ended' => ['Clients: your subscription ended (to the client)', ['name', 'date'], "أهلاً {name}، اشتراكك انتهى يوم {date} واللوحة اتوقفت مؤقتاً. تواصل معانا للتجديد وكل بياناتك محفوظة.", "Hello {name}, your subscription ended on {date} and the panel is paused. Contact us to renew — all your data is kept."],
        'bot_group_added' => ['Bot: person added to a group', ['group'], "تمت إضافتك لجروب «{group}» ✅", "You have been added to «{group}» ✅"],
        'bot_group_invited' => ['Bot: personal group invitation sent', ['group'], "بعتلك دعوة خاصة لجروب «{group}» هنا في الشات، اضغط عليها وهتدخل على طول.", "I sent you a personal invitation to «{group}» here in the chat — tap it to join."],
        'bot_group_already' => ['Bot: person is already in the group', ['group'], "إنت موجود بالفعل في جروب «{group}». لو مش ظاهر عندك ابعتلنا وهنساعدك.", "You are already in «{group}». If you cannot see it, tell us and we will help."],
        'bot_group_team' => ['Bot: joining left to the team', ['group'], "تمام، هبلغ الفريق يضيفك للجروب في أقرب وقت.", "Alright, I will ask the team to add you to the group soon."],
        'case_intake_ask' => ['Case from a group: questions to the writer (quoting their message)', ['name', 'case', 'questions', 'minutes'], "شكراً {name} 🌿\nعلشان أفتح ملف ال{case}، محتاج أعرف كمان:\n{questions}\n\nرد هنا في الجروب خلال {minutes} دقيقة، ولو مفيش رد هيتفتح الملف باللي موجود.", "Thanks {name} 🌿\nTo open the {case} file I also need:\n{questions}\n\nReply here in the group within {minutes} minutes; otherwise the file is opened with what we have."],
        'case_intake_done' => ['Case from a group: file opened (to the writer)', ['name', 'case', 'person', 'missing'], "✅ تم فتح ملف ال{case} لـ {person} وإرسال نسخة لجروب التشخيص.\nناقص: {missing}", "✅ The {case} file for {person} is open and a copy was sent to the diagnosis group.\nMissing: {missing}"],
        'case_intake_exists' => ['Case from a group: the number already has a file (to the writer)', ['case', 'person', 'no', 'follower'], "📁 {person} ليه ملف {case} بالفعل (رقم {no}) — المتابع: {follower}.\nمفيش ملف جديد. لو فيه تفاصيل جديدة أو تسجيل صوتي ابعتهم مع الوسم وهيتضافوا لنفس الملف.", "📁 {person} already has a {case} file (no. {no}) — follower: {follower}.\nNo new file is opened. Send new details or a voice recording with the keyword and they are added to the same file."],
        'case_intake_updated' => ['Case from a group: new details added to the existing file (to the writer)', ['case', 'person', 'no'], "✅ اتضافت التفاصيل الجديدة لملف ال{case} بتاع {person} (رقم {no}) واتبعت التحديث لجروب التشخيص.", "✅ The new details were added to the {case} file of {person} (no. {no}) and the update was sent to the diagnosis group."],
        'case_status_done' => ['Case from a group: stage changed by a command (to the writer)', ['name', 'case', 'person', 'status', 'reason'], "✅ {person}: {status}\nالسبب: {reason}\n(بواسطة {name})", "✅ {person}: {status}\nReason: {reason}\n(by {name})"],
        'case_not_found' => ['Case from a group: no case matches', ['case', 'query'], "⚠️ مفيش {case} باسم أو رقم «{query}». اكتب الاسم أو الرقم أو اعمل منشن للشخص.", "⚠️ No {case} matches «{query}». Write the name or number, or tag the person."],
        'case_many' => ['Case from a group: several cases match', ['case', 'list'], "لقيت أكتر من {case}، اكتب الرقم بالظبط:\n{list}", "Several matches, write the exact number:\n{list}"],
        'case_no_reason' => ['Case from a group: a stage command without a reason', ['status'], "اكتب السبب بعد {status} علشان يتسجل في الملف.", "Write the reason after {status} so it is saved on the file."],
        'list_cases' => ['Group command "#قائمة #الحالة": the cases by stage', ['case', 'count', 'list'], "📋 {case} ({count})\n\n{list}", "📋 {case} ({count})\n\n{list}"],
        'list_shortcuts' => ['Group command "#قائمة #الاختصارات": the shortcuts', ['count', 'list'], "⚡ الاختصارات ({count})\n{list}", "⚡ Shortcuts ({count})\n{list}"],
        'list_programs' => ['Group command "#قائمة #البرامج": the programs (days)', ['count', 'list'], "📚 البرامج ({count})\n{list}", "📚 Programs ({count})\n{list}"],
        'lib_menu' => ['Library commands: the list (group)', ['list'], "📖 أوامر المكتبة:\n{list}\n\nاكتب الأمر واسم الملف أو البرنامج أو الرقية.", "📖 Library commands:\n{list}\n\nWrite the command and the name."],
        'lib_many' => ['Library commands: several matches', ['query', 'list'], "لقيت أكتر من حاجة باسم «{query}»، اكتب الاسم بالظبط:\n{list}", "Several matches for «{query}», write the exact name:\n{list}"],
        'lib_not_found' => ['Library commands: nothing found', ['query'], "⚠️ مش لاقي «{query}» في المكتبة.", "⚠️ «{query}» is not in the library."],
        'lib_program_day' => ['Library commands: one day of a program', ['day', 'text'], "📅 اليوم {day}\n{text}", "📅 Day {day}\n{text}"],
        'crisis_reply' => ['Bot: words that show danger (sent once, then a person takes over)', ['name'], "{name} إحنا معاك ومهتمين بيك جدًا 🤍\nكلامك مهم، وفي حد من الفريق هيكلمك حالًا.\nلو حاسس إنك ممكن تأذي نفسك دلوقتي، كلّم الطوارئ في بلدك فورًا أو حد قريب منك يكون جنبك.", "{name}, we are with you and we care 🤍\nSomeone from the team will talk to you right now.\nIf you might hurt yourself now, call your local emergency number or someone close to be with you."],
        'crisis_alert' => ['Bot: alert to the team about words that show danger', ['name', 'phone', 'text'], "🔴 تنبيه عاجل: {name} ({phone}) كتب كلام فيه خطر. البوت وقف في المحادثة دي — محتاج حد يرد حالًا.\n«{text}»", "🔴 Urgent: {name} ({phone}) wrote words that show danger. The bot stopped in this chat — a person must answer now.\n«{text}»"],
        'cmd_menu' => ['Commands list ("#menu" in WhatsApp)', ['list', 'menu'], "📋 *أوامر البوت*\nاكتب الأمر في الجروب، والبوت يرد عليك بمنشن.\n\n━━━━━━━━━━\n\n{list}\n\n━━━━━━━━━━\n💡 {menu} يعرض القائمة دي في أي وقت.", "📋 *Bot commands*\nWrite the command in the group; the bot answers with a tag.\n\n━━━━━━━━━━\n\n{list}\n\n━━━━━━━━━━\n💡 {menu} shows this list any time."],
        'bot_hold' => ['Bot: the team stepped into the chat and stopped (sent once, then the bot carries on)', ['name', 'minutes'], "لحظات من فضلك 🤍 الشيخ معانا وهيرد عليك، ولو اتأخر هكمل معاك أنا خلال {minutes} دقايق.", "One moment please 🤍 our team will answer you; if they are delayed I will carry on with you within {minutes} minutes."],
        'lib_inventory' => ['Library inventory ("#قائمة #المكتبة")', ['files', 'size', 'codes', 'named', 'shortcuts', 'quick', 'programs'], "📋 *جرد المكتبة*\n{files} ملف · {size} · {named} باسم · {codes} باختصار\n\n🏷️ *اختصارات الملفات*\n{shortcuts}\n\n⚡ *الردود الجاهزة*\n{quick}\n\n📚 *البرامج*\n{programs}", "📋 *Library inventory*\n{files} files · {size} · {named} named · {codes} with a shortcut\n\n🏷️ *File shortcuts*\n{shortcuts}\n\n⚡ *Quick replies*\n{quick}\n\n📚 *Programs*\n{programs}"],
        'group_invite' => ['Group invite link (sent from a chat when WhatsApp does not allow adding the person)', ['group', 'link', 'name'], "أهلاً {name}، دي دعوة للانضمام لجروب «{group}»:\n{link}", "Hello {name}, here is your invitation to join «{group}»:\n{link}"],
        'backup_failed' => ['Backup failed', ['error'], "⚠️ النسخ الاحتياطي على Google Drive فشل: {error}", "⚠️ Google Drive backup failed: {error}"],
        'package_failed' => ['Backup package failed', ['error'], "⚠️ نسخة الحزمة فشلت: {error}", "⚠️ Backup package failed: {error}"],
        'followup_reminder' => ['Follow-up reminder (to a team member)', ['name', 'number', 'note', 'link'], "⏰ تذكير متابعة\n{name}{number}{note}\n{link}", "⏰ Follow-up reminder\n{name}{number}{note}\n{link}"],
        'meeting_host' => ['Meeting host link', ['title', 'link'], "🎙️ إنت مضيف اجتماع: {title}\nمن الرابط ده تفتح الاجتماع للناس وتدخل Google Meet:\n{link}\n(الرابط خاص بيك — ما تبعتهوش لحد)", "🎙️ You host the meeting: {title}\nOpen it for people and join Google Meet from this link:\n{link}\n(This link is yours only — do not share it)"],
        'case_report' => ['Case report posted to the group (a line whose values are all empty is left out)', ['join', 'name', 'phone', 'age', 'marital', 'kids', 'country', 'follower', 'story', 'symptoms', 'extra', 'diag_by', 'diagnosis', 'placement'],
            "تاريخ الانضمام: {join}\n\nالاسم: {name}\nالرقم: +{phone}\nالسن: {age}\nالحالة الاجتماعية: {marital}\nعدد الأولاد: {kids}\nالبلد: {country}\n\nمتابعة: {follower}\n\n{story}\n\n{symptoms}\n\n{extra}\n\n{diagnosis}\n{placement}",
            "Joined: {join}\n\nName: {name}\nPhone: +{phone}\nAge: {age}\nMarital status: {marital}\nChildren: {kids}\nCountry: {country}\n\nFollowed by: {follower}\n\n{story}\n\n{symptoms}\n\n{extra}\n\n{diagnosis}\n{placement}"],
        'case_symptoms' => ['Case report: confirmed symptoms block', ['list'], "الأعراض المؤكدة:\n\n{list}", "Confirmed symptoms:\n\n{list}"],
        'case_extra' => ['Case report: extra symptoms block', ['text'], "أعراض إضافية:\n\n{text}", "Extra symptoms:\n\n{text}"],
        'case_diagnosis' => ['Case report: diagnosis block', ['by', 'text'], "تشخيص: {by}\n{text}", "Assessment by {by}:\n{text}"],
        'payment_methods' => ['Payment methods (used as {methods} in invoice reminders)', [], '', ''],
        'report_title' => ['Daily report: title', ['date'], "📊 تقرير اليوم — {date}", "📊 Daily report — {date}"],
        'report_number' => ['Daily report: number line', ['number', 'state'], "\n📱 {number} {state}", "\n📱 {number} {state}"],
        'report_online' => ['Daily report: connected', [], '✅', '✅'],
        'report_offline' => ['Daily report: not connected', [], '⚠️ غير متصل', '⚠️ not connected'],
        'report_chats' => ['Daily report: chats', ['chats', 'waiting'], "💬 محادثات نشطة: {chats} · ⏳ محتاجة رد: {waiting}", "💬 Active chats: {chats} · ⏳ waiting for a reply: {waiting}"],
        'report_cases' => ['Daily report: cases', ['new', 'follow'], "🩺 حالات جديدة: {new} · تحت المتابعة: {follow}", "🩺 New cases: {new} · under follow-up: {follow}"],
        'report_programs' => ['Daily report: program messages', ['sent'], "📅 رسائل البرامج: {sent}", "📅 Program messages: {sent}"],
        'report_disconnects' => ['Daily report: disconnects', ['count'], "🔌 انقطاعات خلال 24 ساعة: {count}", "🔌 Disconnects in 24 hours: {count}"],
        'report_invoices' => ['Daily report: overdue invoices', ['count', 'due'], "\n🧾 فواتير متأخرة: {count} · إجمالي المستحق: {due}", "\n🧾 Overdue invoices: {count} · total due: {due}"],
    ];
}
function wf_msg_default(string $key): string { $r = wf_msg_registry()[$key] ?? null; if (!$r) return ''; return (string) (wi_sys('msg_lang') === 'en' ? $r[3] : $r[2]); }
/** The text to send: the installation's own version or the starting text, with {placeholders} filled. */
function wf_msg(string $key, array $vars = []): string {
    $own = (array) (wi_read('config.json', [])['messages'] ?? []);
    $t = array_key_exists($key, $own) ? (string) $own[$key] : wf_msg_default($key);
    $vars += ['brand' => (string) wi_sys('brand_name'), 'link' => (string) wi_sys('base_url'), 'date' => date('d/m/Y')];
    // A line whose {placeholders} are all empty is left out (optional fields).
    $t = implode("\n", array_filter(explode("\n", $t), function ($line) use ($vars) { if (!preg_match_all('/\{(\w+)\}/', $line, $m)) return true; foreach ($m[1] as $k) if (!array_key_exists($k, $vars) || trim((string) $vars[$k]) !== '') return true; return false; }));
    return strtr($t, array_combine(array_map(fn($k) => '{' . $k . '}', array_keys($vars)), array_map('strval', array_values($vars))));
}
function wf_api_msg_templates(string $api): void {
    if ($api !== 'msg_templates') return;
    if (!wi_is_super()) wi_json(['ok' => false, 'error' => 'Super admin only.'], 403); // shared by every number, including the login code message
    $c = wi_read('config.json', []); $own = (array) ($c['messages'] ?? []);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') { $in = wf_post(); $k = (string) ($in['key'] ?? '');
        if (!isset(wf_msg_registry()[$k])) wi_json(['ok' => false, 'error' => 'Unknown template.']);
        if (!empty($in['reset'])) unset($own[$k]); else $own[$k] = mb_substr((string) ($in['text'] ?? ''), 0, 4000);
        $c['messages'] = $own; wi_write('config.json', $c); wi_log('msg_template_save', ['key' => $k]); wi_json(['ok' => true]); }
    $list = []; foreach (wf_msg_registry() as $k => $r) $list[] = ['key' => $k, 'title' => $r[0], 'vars' => array_merge($r[1], ['brand', 'link', 'date']), 'default' => wf_msg_default($k), 'text' => array_key_exists($k, $own) ? (string) $own[$k] : wf_msg_default($k), 'edited' => array_key_exists($k, $own)];
    wi_json(['ok' => true, 'templates' => $list]);
}
