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
        'alert_fork' => ['Server refused new processes (the account is full)', ['count', 'threads', 'mem'], "⚠️ السيرفر رفض يفتح عمليات جديدة {count} مرة في آخر دقيقة (الحساب اتملى).\n🧵 العمليات: {threads}\n🧠 الذاكرة: {mem} MB\nقفل أي حاجة مش محتاجها، ولو اتكرر اطلب من الاستضافة ترفع حدود الحساب.", "⚠️ The server refused to start new processes {count} times in the last minute (the account is full).\n🧵 Threads: {threads}\n🧠 Memory: {mem} MB\nClose what is not needed; if it repeats, ask the host to raise the account limits."],
        'number_idle_warn' => ['A number is not linked (needs the QR): daily warning', ['name', 'days', 'left'], "⚠️ الرقم «{name}» مش متصل بقاله {days} يوم ومحتاج يتربط بالـ QR.\nافتح صفحة الربط وامسح الكود، وإلا هيتوقف بعد {left} يوم.", "⚠️ The number «{name}» has not been linked for {days} days and needs its QR scanned.\nOpen the link page and scan it, or it is switched off in {left} days."],
        'number_idle_stopped' => ['A number not linked was switched off', ['name', 'days'], "⛔ الرقم «{name}» اتوقف لأنه ما اتربطش {days} يوم. علشان ترجّعه: شغّله من صفحة الأرقام وامسح الـ QR.", "⛔ The number «{name}» was switched off after {days} days without being linked. To bring it back: switch it on in Numbers and scan the QR."],
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
        'gate_ask_f' => ['Reception group: a woman is asked for her recording (quoting her)', ['name'], "سجلي سورة الفاتحة أو آية الكرسي بصوتك ليتم نقلك لجروب النساء 🎙️", "Record Al-Fatiha or Ayat Al-Kursi in your own voice to be moved to the women's group 🎙️"],
        'gate_ask_m' => ['Reception group: a man is asked for his recording (quoting him)', ['name'], "سجل سورة الفاتحة أو آية الكرسي بصوتك ليتم نقلك لجروب الرجال 🎙️", "Record Al-Fatiha or Ayat Al-Kursi in your own voice to be moved to the men's group 🎙️"],
        'gate_voice' => ['Reception group: a recording arrived — the reviewers are tagged (quoting it)', ['reviewers', 'name'], "{reviewers}\n🎙️ وصل تسجيل صوتي من {name} للمراجعة والنقل للجروب.", "{reviewers}\n🎙️ A recording from {name} arrived for review and moving to the group."],
        'gate_moved' => ['Reception group: a member was moved — the healer is asked to open the case (in the cases group)', ['healer', 'name', 'phone', 'group'], "{healer}\n📂 تم نقل {name} لجروب {group}.\nافتح ملف الحالة: #حالة {phone}", "{healer}\n📂 {name} was moved to {group}.\nOpen the case file: #case {phone}"],
        'gate_remind' => ['Reception group: reminder to a new member who has not answered', ['mention'], "{mention} 🌿 لسه مستنيين ردك: اكتب «رجل» أو «امرأة» علشان نكمل إجراءات نقلك لجروب العلاج.", "{mention} 🌿 We are still waiting for your answer: write «man» or «woman» so we can move you to the treatment group."],
        'gate_final' => ['Reception group: last warning before removal', ['mention', 'minutes'], "{mention} ⚠️ تنبيه أخير: لو مفيش رد خلال {minutes} دقيقة هيتم حذفك من الجروب تلقائياً، وتقدر تنضم تاني في أي وقت.", "{mention} ⚠️ Last notice: without an answer within {minutes} minutes you will be removed from the group automatically; you can join again any time."],
        'case_diag_note' => ['Diagnosis group: a stage change, as a note on the case (quoting its report)', ['no', 'name', 'status', 'reason', 'by'], "🆔 ID {no} · {name}\n{status}\nالسبب: {reason}\n(بواسطة {by})", "🆔 ID {no} · {name}\n{status}\nReason: {reason}\n(by {by})"],
        'fun_on' => ['Warm-up mode on (to the owner, on their own number)', ['hours', 'off'], "😄 وضع الهزار اشتغل في الجروبات المسموح فيها لمدة {hours} ساعة. علشان توقفه اكتب {off}", "😄 Warm-up mode is on in the allowed groups for {hours} hours. Write {off} to stop it."],
        'bot_restart' => ['Service restart asked from a test number (to the owner)', [], "🔄 جاري إعادة تشغيل الخدمة، دقيقة وترجع.", "🔄 Restarting the service, back in a minute."],
        'cmd_menu_own' => ['Commands list: title of the own commands', [], "⭐ *أوامر خاصة*", "⭐ *Own commands*"],
        'owner_bot_hold' => ['Bot stopped from a test number (to the owner)', ['on'], "⏸️ البوت وقف عن الرد في الخاص والجروبات. علشان يرجع اكتب {on}", "⏸️ The bot stopped answering in private chats and groups. Write {on} to turn it back on."],
        'owner_bot_resume' => ['Bot back on from a test number (to the owner)', ['on'], "▶️ البوت رجع يرد عادي.", "▶️ The bot is answering again."],
        'case_del_ask' => ['Delete a record from a test number: asks to confirm', ['name', 'no', 'confirm'], "🗑️ هتتمسح {name} (ID {no}) نهائياً. للتأكيد اكتب {confirm} خلال 5 دقايق.", "🗑️ {name} (ID {no}) will be deleted for good. Write {confirm} within 5 minutes to confirm."],
        'case_del_done' => ['Delete a record from a test number: done', ['name'], "✅ اتمسحت {name}.", "✅ {name} was deleted."],
        'case_del_none' => ['Delete a record from a test number: nothing found / nothing to confirm', [], "⚠️ ما لقيتش حاجة واحدة بالاسم أو الرقم ده، أو مفيش مسح مستني تأكيد.", "⚠️ No single record matches, or nothing is waiting to be confirmed."],
        'bot_status' => ['Bot status (to the owner, from a test number or the own phone)', ['mode', 'wa', 'up', 'mem', 'sent', 'handoff', 'draft', 'skipped', 'paused', 'last', 'tick'], "🤖 حالة البوت: {mode}\n📱 الواتساب: {wa} · شغال من {up} دقيقة · {mem} MB\n\nالنهارده:\n✅ ردود اتبعتت: {sent}\n🙋 اتحوّلت للفريق: {handoff}\n📝 مسودات: {draft}\n⏭️ اتسابت: {skipped}\n⏸️ محادثات البوت واقف فيها: {paused}\n\nآخر رد: {last} · آخر فحص: {tick}", "🤖 Bot: {mode}\n📱 WhatsApp: {wa} · up {up} min · {mem} MB\n\nToday:\n✅ sent: {sent}\n🙋 handed to the team: {handoff}\n📝 drafts: {draft}\n⏭️ skipped: {skipped}\n⏸️ chats where the bot is paused: {paused}\n\nLast reply: {last} · last check: {tick}"],
        'bot_status_auto' => ['Bot status word: automatic', [], "شغال تلقائي ✅", "automatic ✅"],
        'bot_status_draft' => ['Bot status word: suggest only', [], "مسودات بس 📝", "suggest only 📝"],
        'bot_status_off' => ['Bot status word: off', [], "مقفول ⚪", "off ⚪"],
        'bot_status_held' => ['Bot status word: stopped from a test number', [], "واقف مؤقتاً ⏸️", "stopped for now ⏸️"],
        'case_deep_msg' => ['Case analysis report (#تحليل) — the shared reference for the team', ['name', 'no', 'date', 'summary', 'axes', 'stage', 'progress', 'bar', 'trend', 'stages', 'recs', 'flags'], "🩺 *تقرير تشخيص ومتابعة الحالة*\n━━━━━━━━━━━━━━\n🆔 رقم الحالة: {no}\n👤 الاسم: {name}\n📅 تاريخ التقرير: {date}\n━━━━━━━━━━━━━━\n\n📋 *ملخص الحالة*\n{summary}\n\n📊 *التقييم حسب الجوانب*\n{axes}\n\n🧭 *مسار العلاج*\nالمرحلة الحالية: {stage}\nنسبة التقدّم: {progress}% {bar}\nالاتجاه: {trend}\n{stages}\n\n🎯 *التوصيات*\n{recs}{flags}\n━━━━━━━━━━━━━━\n_تقرير مساعد للمعالج، ولا يغني عن التقييم المباشر._", "🩺 *Case diagnosis & follow-up report*\n━━━━━━━━━━━━━━\n🆔 Case: {no}\n👤 Name: {name}\n📅 Date: {date}\n━━━━━━━━━━━━━━\n\n📋 *Summary*\n{summary}\n\n📊 *Assessment by angle*\n{axes}\n\n🧭 *Treatment course*\nCurrent stage: {stage}\nProgress: {progress}% {bar}\nTrend: {trend}\n{stages}\n\n🎯 *Recommendations*\n{recs}{flags}\n━━━━━━━━━━━━━━\n_An aid for the healer; it does not replace a direct assessment._"],
        'case_deep_levels' => ['Case report: level words from low to high scores, separated by |', [], "حرج | ضعيف | متوسط | جيد | ممتاز", "critical | weak | moderate | good | excellent"],
        'case_deep_trends' => ['Case report: trend words (better | same | worse | unknown)', [], "تحسّن | ثابت | تراجع | غير محدد", "improving | stable | declining | unknown"],
        'case_deep_evidence' => ['Case report: the word before the evidence', [], "الدليل:", "Evidence:"],
        'case_deep_flags' => ['Case report: title of the urgent part', [], "⚠️ *تنبيهات تستدعي المتابعة العاجلة*", "⚠️ *Needs urgent attention*"],
        'case_deep_wait' => ['Case analysis: the case was found, the analysis is running', ['name', 'no'], "⏳ جاري فحص وتحليل حالة {name} (ID {no})، وسوف يتم الرد في أقرب وقت.", "⏳ Checking and analysing the case of {name} (ID {no}); the answer follows shortly."],
        'case_deep_none' => ['Case analysis: no single case found', ['cmd'], "⚠️ اكتب {cmd} وبعده رقم الحالة (ID) أو الاسم أو منشن للشخص.", "⚠️ Write {cmd} then the case ID, the name or a tag of the person."],
        'fun_off' => ['Warm-up mode off (to the owner)', [], "✅ وضع الهزار وقف، والبوت رجع لشغله العادي.", "✅ Warm-up mode is off; the bot is back to normal."],
        'case_status_done' => ['Case from a group: stage changed by a command (to the writer)', ['name', 'case', 'person', 'status', 'reason'], "✅ {person}: {status}\nالسبب: {reason}\n(بواسطة {name})", "✅ {person}: {status}\nReason: {reason}\n(by {name})"],
        'case_not_found' => ['Case from a group: no case matches', ['case', 'query', 'diag'], "⚠️ مفيش {case} باسم أو رقم «{query}».\nادخل جروب التشخيص «{diag}» وشوف ID ال{case}، وبعدين اكتبه مكان الاسم:\n#الحالة ID #اتحسنت السبب", "⚠️ No {case} matches «{query}».\nOpen the diagnosis group «{diag}», find the {case} ID and write it instead of the name:\n#case ID #improved reason"],
        'case_many' => ['Case from a group: several cases match', ['case', 'list'], "لقيت أكتر من {case}، اكتب الرقم بالظبط:\n{list}", "Several matches, write the exact number:\n{list}"],
        'case_no_reason' => ['Case from a group: a stage command without a reason', ['status'], "اكتب السبب بعد {status} علشان يتسجل في الملف.", "Write the reason after {status} so it is saved on the file."],
        'list_cases' => ['Group command "#قائمة #الحالة": the cases by stage', ['case', 'count', 'list'], "📋 {case} ({count})\n\n{list}", "📋 {case} ({count})\n\n{list}"],
        'list_shortcuts' => ['Group command "#قائمة #الاختصارات": the shortcuts', ['count', 'list'], "⚡ الاختصارات ({count})\n{list}", "⚡ Shortcuts ({count})\n{list}"],
        'list_programs' => ['Group command "#قائمة #البرامج": the programs (days)', ['count', 'list'], "📚 البرامج ({count})\n{list}", "📚 Programs ({count})\n{list}"],
        'lib_menu' => ['Library commands: the list (group)', ['list'], "📖 أوامر المكتبة:\n{list}\n\nاكتب الأمر واسم الملف أو البرنامج أو الرقية.", "📖 Library commands:\n{list}\n\nWrite the command and the name."],
        'lib_section' => ['Section tag (e.g. #صوتيات): the files of that section with their shortcuts', ['title', 'count', 'list'], "📚 {title} ({count})\n{list}\n\nاكتب الاختصار (#...) وهيتبعت الملف.", "📚 {title} ({count})\n{list}\n\nWrite a shortcut (#...) and the file is sent."],
        'lib_section_empty' => ['Section tag: nothing in that section yet', ['title', 'count', 'list'], "📚 {title}: لسه مافيش ملفات في القسم ده.", "📚 {title}: nothing in this section yet."],
        'program_enrolled' => ['Program tag written in a private chat: the person follows the program and gets its first day now', ['name', 'days', 'day', 'text', 'rest'], "✅ اشتركت في «{name}» ({days} يوم). اليوم {day}:\n\n{text}{rest}", "✅ You joined «{name}» ({days} days). Day {day}:\n\n{text}{rest}"],
        'program_rest' => ['Program tag: added after day one when the program has more days', [], "\n\n— باقي الأيام هتوصلك يوم بيوم.", "\n\n— the next days arrive one by one."],
        'program_already' => ['Program tag written again by someone who already follows it', ['name'], "أنت مشترك بالفعل في برنامج «{name}» وبيوصلك يوم بيوم.", "You already follow «{name}» and receive it day by day."],
        'vault_otp' => ['Code to open the private folder of the media library', ['code', 'minutes'], "🔐 كود فتح مجلد الملفات الخاصة: {code}\nصالح {minutes} دقايق. لو ما طلبتوش تجاهل الرسالة.", "🔐 Code to open the private files folder: {code}\nValid for {minutes} minutes. If you did not ask for it, ignore this message."],
        'staff_prefix' => ['Team member reply: how the name goes in front of a message', ['name', 'text'], "{name}: {text}", "{name}: {text}"],
        'staff_welcome' => ['Team member welcome: sent before their first message in a chat', ['name', 'business'], "أهلاً بك 🌷 معاك {name} من فريق {business}، وهكمل معاك من هنا.", "Hello 🌷 this is {name} from {business}, I will continue with you from here."],
        'lib_many' => ['Library commands: several matches', ['query', 'list'], "لقيت أكتر من حاجة باسم «{query}»، اكتب الاسم بالظبط:\n{list}", "Several matches for «{query}», write the exact name:\n{list}"],
        'lib_not_found' => ['Library commands: nothing found (with the nearest tags)', ['query', 'hint'], "⚠️ مش لاقي «{query}» في المكتبة.{hint}", "⚠️ «{query}» is not in the library.{hint}"],
        'lib_close' => ['Library commands: nothing with that name, the closest texts', ['query', 'list', 'hint'], "⚠️ مش لاقي «{query}» بالاسم ده. أقرب حاجة لقيتها:\n{list}\nاكتب الاسم بالظبط.{hint}", "⚠️ Nothing is named «{query}». The closest I found:\n{list}\nWrite the exact name.{hint}"],
        'lib_try_tags' => ['Library commands: the nearest tags to try', ['tags'], "\n🔎 جرّب بوسم أقرب: {tags}", "\n🔎 Try the nearest tags: {tags}"],
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
