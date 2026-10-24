<?php
/* ---------- Business profile ("pack") + AI instruction templates ----------
 * Nothing about a particular business lives in the code: every AI instruction is a template with {{placeholders}}.
 * The placeholders come from the number's business profile (bot.json[aid]['pack']) and from the call itself.
 * Each template can be rewritten per number in Learning → AI instructions; the texts below are only the starting values. */

/** Ready-made business profiles. 'general' is the neutral starting point for any business. */
function wf_pack_presets(): array {
    $P = [
        'general' => ['label' => 'General business', 'industry' => 'a business that serves its customers on WhatsApp', 'language' => 'the customer\'s language',
            'dialect_rule' => 'Reply in the customer\'s own language: in Arabic, in their own country\'s dialect (Egyptian, Saudi and Gulf, Levantine, Iraqi, Moroccan…); if they write English, French or any other language, reply in that language.', 'output_language' => 'Arabic', 'staff' => 'Team', 'client' => 'Customer',
            'sacred_rule' => '', 'outside_people' => 'people who are not part of the team (outside experts, other companies)', 'case_types' => 'needs a specialist|not clear',
            'case_scope' => 'Do not give professional diagnoses; describe what they report.', 'concept_example' => 'a key idea the team relies on', 'term_example' => 'everyday words customers use for it',
            'choices_example' => '1️⃣ … 2️⃣ … 3️⃣ …', 'newcomer_rule' => 'Never assume a new person already has anything from you (an order, a plan, a file).',
            'library_kind' => 'messages and texts the business shares', 'library_sections' => 'FAQ, procedures, policies, offers, announcements, other', 'text_quality' => 'complete, correct, well formatted', 'text_errors' => 'wrong or outdated information',
            'ui_terms' => "Treatment group and follower => Follow-up group and follower\nTreatment group => Follow-up group\nDiagnosis => Assessment\nDiagnosed by => Assessed by\nConfirmed symptoms (one per line) => Main points (one per line)\nExtra symptoms => More details\nNo diagnosis group chosen yet => No reports group chosen yet",
            'case_ex_age' => '30', 'case_ex_kids' => '0', 'case_ex_country' => '', 'case_ex_points' => '', 'case_ex_assessment' => '', 'case_marital' => '', 'reception_pattern' => 'reception|استقبال', 'placement_m' => '', 'placement_f' => '', 'female_hint' => '',
            'lib_audio_words' => "مسموعة | مسموعه | صوت | صوتية | صوتيه | فويس | سماع", 'ack_words' => "تمام | حاضر | ماشي | اوك | ok | okay | الحمد لله | زي الفل | شكرا | شكراً | جزاك الله خيرا | بارك الله فيك | آمين | امين | يارب | ان شاء الله | إن شاء الله", 'lib_tags' => "#المكتبة | library | المكتبة\n#صوتيات | audio | الصوتيات\n#البرامج | programs | البرامج\n#نصوص | texts | النصوص المكتوبة", 'program_label' => 'Programs', 'audio_tag_category' => '', 'audio_categories' => "lesson | 🎓 | Lesson | #6366f1\nannouncement | 📣 | Announcement | #f59e0b\nother | 📁 | Other | #64748b", 'summary_labels' => '', 'ex_triggers' => 'التوصيل، امتى يوصل', 'ex_bot_name' => 'مساعد المتجر', 'ex_question' => 'كم مدة التوصيل؟', 'ex_message' => 'السلام عليكم، طلبت من يومين ولسه ما وصلش', 'ex_word' => 'التوصيل', 'ex_try' => 'الطلب اتأخر', 'ex_concept' => 'سياسة الاسترجاع', 'ex_search' => 'مواعيد العمل',
            'quran_check' => '', 'quick_reply_example' => 'for a delivery-times message: "التوصيل", "امتى يوصل"', 'summary_categories' => 'inquiry, complaint, payment, new_order, follow_up, support, social, other', 'kb_types' => 'faq|procedure|policy', 'case_labels' => 'متابعة، عاجل، جديد'],
    ];
    // CRM: sales and customer service — the starting profile of every client (SaaS) number. Everything here is editable in Business settings.
    $P['crm'] = ['label' => 'CRM — sales and customer service', 'industry' => 'شركة بتبيع منتجات أو خدمات وبتخدم عملاءها على واتساب',
        'staff' => 'فريق المبيعات وخدمة العملاء', 'client' => 'عميل', 'case_types' => 'hot lead|interested|needs follow-up|complaint|not interested',
        'case_scope' => 'Summarise what the customer wants, their budget and timing if they said it, and the next step; never promise prices or dates that are not in the sources.',
        'concept_example' => 'a product, a service or a policy', 'term_example' => 'the everyday words customers use for it', 'choices_example' => '1️⃣ سعر 2️⃣ مواعيد التوصيل 3️⃣ طريقة الدفع…',
        'newcomer_rule' => 'Never assume a new person already ordered or paid anything.', 'library_kind' => 'messages the company shares: offers, prices, FAQ and policies',
        'library_sections' => 'عروض, أسعار, التوصيل والدفع, سياسات وضمان, أسئلة شائعة, أخرى', 'summary_categories' => 'inquiry, order, complaint, payment, follow-up, other',
        'program_label' => 'خطط المتابعة', 'quick_reply_example' => 'for a delivery-times message: "التوصيل", "امتى يوصل"', 'case_marital' => '',
        'ui_terms' => implode("\n", [
            'Cases => العملاء', 'Followed cases => العملاء', 'Case => عميل', 'cases => عملاء', 'All cases => كل العملاء', 'New case => عميل جديد', 'Case details => تفاصيل العميل', 'Case data => بيانات العميل',
            'Case notes => ملاحظات', 'Case record => سجل العميل', 'Open the case record => افتح سجل العميل', 'Open the case => افتح سجل العميل', 'Save the case => احفظ العميل', 'Case saved and its file created => العميل اتحفظ واتعمله ملف',
            'No cases yet. => لسه مفيش عملاء.', 'Case settings => إعدادات العملاء', 'Case registry => سجل العملاء المحتملين', 'Case registry group => جروب العملاء المحتملين', 'Case groups => جروبات العملاء',
            'Case group => جروب عملاء', 'Case follow-up => متابعة العملاء', 'Cases & follow-up => العملاء والمتابعة', 'Cases &amp; follow-up => العملاء والمتابعة', '🗂️ Cases => 🗂️ العملاء',
            'Not a case (team) => مش عميل (فريق العمل)', 'Admin / therapist / team member — not a patient => مشرف / موظف / من فريق العمل — مش عميل', 'has a case => عنده سجل',
            'This number already has a case. Open its record instead? => الرقم ده عنده سجل بالفعل. تفتحه؟', 'Choose who follows the case… => اختار المسؤول عن العميل…',
            'Symptoms => الاحتياجات', 'Confirmed symptoms (one per line) => الاحتياجات (واحدة في كل سطر)', 'Extra symptoms => تفاصيل إضافية', 'Diagnosis => التقييم', 'Search name, number, diagnosis… => ابحث بالاسم أو الرقم أو التقييم…',
            'No diagnosis group chosen yet => لسه ما اخترتش جروب المتابعة', 'Reception group => جروب العملاء الجدد', 'chosen from the reception group => من جروب العملاء الجدد', 'Choose the reception group… => اختار جروب العملاء الجدد…',
            'Choose the visitor from the reception group. => اختار العميل من جروب العملاء الجدد.', 'Complaint => الطلب', 'Marital status => الحالة الاجتماعية',
            'Fill in the case: it is saved as a case under follow-up, gets its own file, and a copy of the report is posted to the diagnosis group. => اكتب بيانات العميل: بيتحفظ تحت المتابعة، وبيتعمله ملف، ونسخة من التقرير بتتبعت لجروب المتابعة.',
            'AI notes are a help for the team, not a medical diagnosis. => ملاحظات الذكاء الاصطناعي مساعدة للفريق.',
            'Open a new case for a person who describes symptoms in a private chat => افتح سجل عميل جديد لأي حد يطلب منتج أو خدمة في محادثة فردية',
            'Add private chats to the case record (for people who are cases) => ضم المحادثات الفردية لسجل العميل',
            'The AI reads all the conversations and writes a summary, the complaint, the symptoms and the progress. => الذكاء الاصطناعي بيقرا كل المحادثات ويكتب ملخص والطلب والاحتياجات والمرحلة.',
            'Case opened from a private chat => اتفتح سجل العميل من محادثة فردية', 'Learn from this group (questions + answers of the sheikh / admins) => اتعلّم من الجروب ده (أسئلة الأعضاء + ردود المشرفين)',
            'Case report: confirmed symptoms block => تقرير العميل: الاحتياجات', 'Case report: extra symptoms block => تقرير العميل: تفاصيل إضافية', 'Case report: diagnosis block => تقرير العميل: التقييم',
            'Scheduling &amp; programs => الجدولة وخطط المتابعة', 'Scheduling & programs => الجدولة وخطط المتابعة', 'Programs => خطط المتابعة', 'Treatment programs => خطط المتابعة',
            'Opened a case => فتح سجل عميل', 'Updated a case => حدّث سجل عميل', 'Daily report: cases => التقرير اليومي: العملاء', 'Case analysis => تحليل العميل',
            'Every case posted in your case-registry groups (name, number, voice note, information, diagnosis) — one entry per case, with labels. Select one or many to follow them. => كل عميل محتمل اتنشر في جروبات السجل (الاسم، الرقم، البيانات) — سطر لكل عميل، بتصنيفات. اختار واحد أو أكتر عشان تتابعهم.',
            'People in the groups you monitor (e.g. a case-reporting group): every message is archived, each person gets a case record, AI analysis and follow-up. => الناس في الجروبات اللي بتتابعها: كل رسالة بتتحفظ، وكل شخص بيتعمله سجل عميل وتحليل ومتابعة.',
        ]),
    ] + $P['general'];
    // IT / technical support: tickets instead of cases.
    $P['support'] = ['label' => 'IT — technical support', 'industry' => 'شركة دعم فني وخدمات IT بتحل مشاكل عملاءها التقنية على واتساب',
        'staff' => 'فريق الدعم الفني', 'client' => 'عميل', 'case_types' => 'عطل|طلب خدمة|استفسار|شكوى|متابعة',
        'case_scope' => 'Summarise the technical problem, the device or system, what was already tried and the next step; never guess a fix that is not in the sources.',
        'concept_example' => 'a known problem and its fix', 'term_example' => 'the words customers use for it (e.g. «النت فاصل»)', 'choices_example' => '1️⃣ النت 2️⃣ الجهاز 3️⃣ البرنامج…',
        'newcomer_rule' => 'Never assume a new person already has a ticket or a contract.', 'library_kind' => 'technical support messages: fixes, guides, FAQ and policies',
        'library_sections' => 'حلول المشاكل, شروحات, أسئلة شائعة, سياسات وأسعار, أخرى', 'summary_categories' => 'fault, service request, inquiry, complaint, payment, other',
        'program_label' => 'خطط الصيانة', 'quick_reply_example' => 'for a router restart guide: "النت فاصل", "الراوتر"', 'case_marital' => '',
        'ui_terms' => implode("\n", [
            'Cases => التذاكر', 'Followed cases => التذاكر', 'Case => تذكرة', 'cases => تذاكر', 'All cases => كل التذاكر', 'New case => تذكرة جديدة', 'Case details => تفاصيل التذكرة', 'Case data => بيانات التذكرة',
            'Case notes => ملاحظات', 'Case record => سجل التذكرة', 'Open the case record => افتح التذكرة', 'Open the case => افتح التذكرة', 'Save the case => احفظ التذكرة', 'Case saved and its file created => التذكرة اتحفظت',
            'No cases yet. => لسه مفيش تذاكر.', 'Case settings => إعدادات التذاكر', 'Case registry => سجل الطلبات', 'Case registry group => جروب الطلبات', 'Case groups => جروبات الدعم', 'Case group => جروب دعم',
            'Case follow-up => متابعة التذاكر', 'Cases & follow-up => التذاكر والمتابعة', 'Cases &amp; follow-up => التذاكر والمتابعة', '🗂️ Cases => 🗂️ التذاكر', 'Not a case (team) => مش تذكرة (فريق العمل)',
            'Admin / therapist / team member — not a patient => مشرف / فني / من فريق العمل — مش عميل', 'has a case => عنده تذكرة', 'This number already has a case. Open its record instead? => الرقم ده عنده تذكرة بالفعل. تفتحها؟',
            'Choose who follows the case… => اختار الفني المسؤول…', 'Symptoms => تفاصيل المشكلة', 'Confirmed symptoms (one per line) => تفاصيل المشكلة (واحدة في كل سطر)', 'Extra symptoms => تفاصيل إضافية',
            'Diagnosis => السبب / الحل', 'Search name, number, diagnosis… => ابحث بالاسم أو الرقم أو الحل…', 'No diagnosis group chosen yet => لسه ما اخترتش جروب الدعم', 'Reception group => جروب الطلبات الجديدة',
            'chosen from the reception group => من جروب الطلبات الجديدة', 'Choose the reception group… => اختار جروب الطلبات الجديدة…', 'Choose the visitor from the reception group. => اختار العميل من جروب الطلبات الجديدة.',
            'Complaint => المشكلة', 'AI notes are a help for the team, not a medical diagnosis. => ملاحظات الذكاء الاصطناعي مساعدة للفريق.',
            'Fill in the case: it is saved as a case under follow-up, gets its own file, and a copy of the report is posted to the diagnosis group. => اكتب بيانات التذكرة: بتتحفظ تحت المتابعة، ونسخة من التقرير بتتبعت لجروب الدعم.',
            'Open a new case for a person who describes symptoms in a private chat => افتح تذكرة جديدة لأي حد يوصف مشكلة في محادثة فردية', 'Add private chats to the case record (for people who are cases) => ضم المحادثات الفردية للتذكرة',
            'The AI reads all the conversations and writes a summary, the complaint, the symptoms and the progress. => الذكاء الاصطناعي بيقرا كل المحادثات ويكتب ملخص والمشكلة وتفاصيلها ومرحلة الحل.',
            'Case opened from a private chat => اتفتحت التذكرة من محادثة فردية', 'Learn from this group (questions + answers of the sheikh / admins) => اتعلّم من الجروب ده (أسئلة العملاء + ردود الفنيين)',
            'Case report: confirmed symptoms block => تقرير التذكرة: تفاصيل المشكلة', 'Case report: extra symptoms block => تقرير التذكرة: تفاصيل إضافية', 'Case report: diagnosis block => تقرير التذكرة: الحل',
            'Scheduling &amp; programs => الجدولة وخطط الصيانة', 'Scheduling & programs => الجدولة وخطط الصيانة', 'Programs => خطط الصيانة', 'Opened a case => فتح تذكرة', 'Updated a case => حدّث تذكرة',
            'Daily report: cases => التقرير اليومي: التذاكر', 'Case analysis => تحليل التذكرة',
        ]),
    ] + $P['crm'];
    // Emoji-only messages (no words): the bot answers like a person — a reaction, and for some a short line. "emojis => reaction | reply" per line; editable in Business settings.
    $emoji = "❤️ 🤍 💙 💚 💛 💜 ♥️ 😍 🥰 😘 => 🤍\n😂 🤣 😅 😁 😆 😄 => 😄\n👍 👌 ✅ 💯 => 👍\n🤲 🙏 => 🤲 | آمين يارب 🤲\n🌹 🌸 💐 🌷 => 🌹\n😢 😭 💔 😔 😞 😓 => 🤍 | ربنا يطمّن قلبك 🤍 إحنا معاك، احكيلنا لو حابب.\n👋 => 👋 | أهلاً بيك 🤍\n* => 🤍";
    $units = 'مر|مره|مرات|مرة|يوم|ايام|أيام|ساع|اسبوع|أسبوع|شهر|دقيق|times|days|hours|weeks|months|minutes'; // words after a count (the bot's made-up-count check)
    // The language and starting wording the code works with (lists, detection words, starting texts): a number may change any of them in its profile.
    $seeds = [
        'seed_text_stop_words' => 'في, من, على, الى, عن, ما, لا, يا, هو, هي, انا, انت, ده, دي, اللي, و, او, ثم, ان, كان, مع, هل, كده, بس, عشان, لو, كل, the, and',
        'seed_stat_stop_words' => 'the and you for are with this that from have your was not but can will عن في من على الى إلى ان أن هذا هذه انا أنا انت إنت مع لو اللي الي ده دي كده يا و او أو ما لا ايه إيه هو هي تم بس كان عشان علشان ممكن',
        'seed_style_end_emojis' => "🤲 => يشفي، شفا، اللهم، ربنا، بإذن الله، باذن الله، يعافي، دعاء، ادعو، أدعو\n🙏 => شكر، جزاك، بارك\n✅ => تم، خلاص، اتسجل، جاهز\n📖 => سورة، آية، اقرأ، اقرا، قراءة\n⏰ => الساعة، ميعاد، موعد\n📌 => ملاحظة، مهم",
        'seed_style_affection_words' => 'حبيبي، حبيبتي، حبايبي، يا قلبي، يا روحي، يا عمري، يا غالي، يا غالية، يا قمر، يا عسل، يا حلوة، يا جميلة، يا سكر، يا دلوعة، يا أميرة، يا اميرة، يا برنسيسة، يا ست البنات، يا ست الكل، منورة، منوره، نورتي، أحبك، بحبك، وحشتني، dear، honey، sweetheart، darling',
        'seed_style_prompt_rules' => "Call the person only by the name shown under WHO YOU TALK TO (or the name they gave in this chat). Never guess, invent or reuse another name — if no name is known, use none.\nGreetings and welcomes stay neutral and respectful: no compliments, flattery or emotional words (e.g. منورة، نورتي، يا قمر، يا أميرة), never pair a person's first name with praise, and be extra formal with women. A plain greeting and getting to the point is enough.\nNever say that something happened or did not happen (a message was deleted, a payment arrived, a booking or file was sent, someone saw it) unless the conversation or the sources show it. If you are not sure, say you will check or that the team will confirm. Never swear (والله، أقسم) to make a claim.\nNever deny being an automated assistant; if asked, say you are the digital assistant and a team member can join.\nNever mention a symptom, illness, family member, place, treatment step or past event about the person unless it is written in WHO YOU TALK TO or in the recent messages. A greeting, thanks or a short comment gets a short, natural reply — do not bring up their health or an earlier complaint, and do not give a new instruction nobody asked for. If you are not sure what they mean, ask one short question.\nNever repeat a sentence or a question you already sent in this conversation; if the customer did not answer it, take a different step or hand over to the team.\nKeep it WhatsApp-sized: 1–5 short lines. Emojis only when they really help (e.g. ✅ for a step, ⏰ for a time) — usually none; end with an emoji only if it fits what you just said (e.g. 🤲 after a dua), at most one, never decorative; never hearts, flowers, kisses or anything romantic.\nSTRICTLY RESPECTFUL: never use words of love or endearment (حبيبي، حبيبتي، يا قلبي، يا روحي، يا عمري، يا غالي، يا غالية، يا قمر، يا عسل، أحبك، وحشتني، love, dear, honey…), never comment on looks or voice, no flirting or anything that could be read as flirting or harassment; emojis are fine EXCEPT hearts of any colour, flowers/roses, kisses, love faces or anything romantic — even if the team examples or the person do. Address people with neutral respect, by name when known, and vary the wording; do NOT open with «يا أخي الكريم» / «أخي الكريم» / «أختي الكريمة» — prefer the person's name or no address at all.",
        'seed_stop_words' => 'وقف، توقف، كفاية، بس كده، stop',
        'seed_question_words' => 'ايه|إيه|ازاي|إزاي|ليه|امتى|إمتى|فين|هل|كيف|ماذا|متى|لماذا|أين|كام|مين|ممكن',
        'seed_personal_words' => 'موعد|ميعاد|تشخيص|حالتي|حالتى|اسمي|اسمى|رقمي|رقمى|فلوس|دفعت|تحويل|ايصال|إيصال|صوره|صورة|فويس|ريكورد',
        'seed_quran_markers' => 'قال تعالى|قوله تعالى|بسم الله الرحمن الرحيم',
        'seed_filler_words' => 'ده|دي|دا|كده|طب|طيب|ايوه|ايوا|لا|تمام|ماشي|اه|نعم',
        'seed_question_words_cache' => 'ايه|ازاي|ازاى|ليه|امتي|امتى|فين|كام|هل|ما هي|ما هو|ماهي|ماهو|كيف|لماذا|متي|متى|اين|عايز اعرف|ممكن اعرف',
        'seed_case_name_labels' => 'الاسم|اسم الحالة|الأسم|name',
        'seed_library_support' => "حلول المشاكل | حل|الحل|جرب|اعمل ريستارت|restart|إعادة تشغيل|اعاده تشغيل|fix | 🛠️\nشروحات | شرح|خطوات|طريقة|ازاي|إزاي|guide | 📘\nالشبكات والإنترنت | نت|انترنت|إنترنت|راوتر|واي فاي|wifi|شبكة|router | 🌐\nسياسات وأسعار | سعر|اسعار|أسعار|اشتراك|عقد|ضمان|سياس | 📜\nأسئلة شائعة | سؤال|اسئلة|أسئلة|استفسار|FAQ | ❓",
        'seed_library_sales' => "عروض | عرض|عروض|خصم|تخفيض|اوفر|offer | 🏷️\nأسعار | سعر|اسعار|أسعار|بكام|جنيه|ريال|درهم|price | 💰\nالتوصيل والدفع | توصيل|شحن|الدفع|تحويل|كاش|فودافون|delivery | 🚚\nسياسات وضمان | ضمان|استرجاع|استبدال|سياس | 📜\nأسئلة شائعة | سؤال|اسئلة|أسئلة|استفسار|FAQ | ❓",
        'seed_library_names' => 'عام | أخرى | صوتيات | رسالة صوتية | ملف صوتي',
        'seed_secret_patterns' => 'رمز دخول|رمز التحقق|كود التحقق|one-time password|verification code|\\bOTP\\b|password|كلمة المرور|كلمة السر',
        'seed_pay_commands' => 'موافق, موافقة, اعتماد, approve, ok, رفض, reject',
        'seed_money_words' => 'EGP, LE, L.E, جنيه, ج.م, ج, ريال, SAR, $, USD, دولار',
        'seed_gender_f' => 'ث, female, woman',
        'seed_gw_question_words' => 'ازاي|إزاي|ايه|إيه|ليه|امتى|هل|كيف|ممكن|عايز|عايزة|محتاج',
        'seed_ref_number_word' => 'رقم',
        'seed_ref_labels' => 'العملية|المرجع|الطلب|الفاتورة',
        'seed_gender_m' => 'ذكر, male, man',
    ];
    $P = wi_filter('pack_presets', $P); // business types brought by add-ons
    foreach ($P as $k => $v) $P[$k] += $seeds;
    foreach ($P as $k => $v) $P[$k] += ['emoji_replies' => $emoji, 'count_units' => $units, 'sacred_markers' => '', 'gate_words' => [], 'fun_cmd' => '#هزار', 'fun_off' => '#جد', 'restart_cmd' => '#ريستارت، ريستارت، #restart', 'ask_tag' => '#سؤال', 'continue_cmd' => '#كمل، #كمّل', 'rating_good_emojis' => '👍 ❤️ 🤍 💚 🙏 😍 🥰 💯 ✅ 🌹 👏', 'rating_bad_emojis' => '👎 😡 😠 🙄 💔 ❌ 😒', 'rating_good_words' => 'شكرا، شكراً، جزاك الله، جزاكم الله، تسلم، ربنا يكرمك، فادني، thanks', 'rating_bad_words' => 'غلط، مش ده، مش فاهم، مش مفهوم، مش صح، مش ده قصدي، wrong', 'analysis_axes' => '', 'analyze_cmd' => '', 'recs_cmd' => '', 'learn_group_words' => '', 'hold_cmd' => '#وقف_البوت، #ايقاف_البوت، وقف البوت', 'status_cmd' => '#حالة_البوت، /status، /حالة، حالة البوت', 'resume_cmd' => '#شغل_البوت، #تشغيل_البوت، شغل البوت', 'delete_cmd' => '#امسح', 'confirm_cmd' => '#تأكيد', 'fun_hours' => 2, 'nicknames' => 'يا صاحبي، يا صاحبى، يا صاحب، يا باشا، يا معلم، يا غالي'];
    return $P;
}
/** A starting word list or text of the business profile (seed_<key>), for this number or, without one, the starting profile. */
function wf_seed(string $key, string $aid = ''): string { static $base = []; if ($aid === '') return $base[$key] ??= (string) (wf_pack('')['seed_' . $key] ?? ''); return (string) (wf_pack($aid)['seed_' . $key] ?? ''); }
/** A seed list as words: split on commas (also the Arabic comma), "|" or new lines. */
function wf_seed_list(string $key, string $aid = ''): array { return array_values(array_filter(array_map('trim', preg_split('/[,\x{060C}|\n]+/u', wf_seed($key, $aid))), fn($w) => $w !== '')); }
/** A seed list as a regex alternation, each word quoted (the regex itself stays in the code). */
function wf_seed_alt(string $key, string $aid = '', string $d = '/'): string { return implode('|', array_map(fn($w) => preg_quote($w, $d), wf_seed_list($key, $aid))); }
/** A comma list setting of the business profile ("a, b, c") as a list. */
function wf_pack_list(string $aid, string $key): array { return array_values(array_filter(array_map('trim', explode(',', (string) (wf_pack($aid)[$key] ?? ''))))); }
/** The starting business type of a number: a client's (SaaS) number starts as CRM, the operator's as general. */
function wf_pack_default(string $aid): string { $a = wi_accounts()[$aid] ?? null;
    return $a && function_exists('wi_ws') && wi_ws($a) !== '' ? 'crm' : 'general'; }
/** A number's own profile values without the ones that only repeat a ready value: what equals this type's ready value
 *  is not kept, and the values of a type kept apart ('apart' => true) on another type (or another type's values on it) are dropped. */
function wf_pack_own(string $pre, array $p): array {
    $P = wf_pack_presets(); $mine = $P[$pre] ?? $P['general'];
    foreach ($p as $k => $v) { if ($k === 'preset') continue;
        if (array_key_exists($k, $mine) && $mine[$k] == $v) { unset($p[$k]); continue; }
        foreach ($P as $o => $op) if ($o !== $pre && (!empty($op['apart']) || !empty($mine['apart'])) && array_key_exists($k, $op) && $op[$k] == $v && $op[$k] != ($mine[$k] ?? null)) { unset($p[$k]); break; } }
    return $p;
}
function wf_pack(string $aid): array {
    $p = (array) (wi_read('bot.json', [])[$aid]['pack'] ?? []); $P = wf_pack_presets(); $p['preset'] ??= wf_pack_default($aid);
    // a type that is not there (its add-on removed) or not allowed on this number falls back to the starting type
    if (!isset($P[$p['preset']]) || !wi_filter('pack_allowed', true, (string) $p['preset'], $aid)) $p = ['preset' => wf_pack_default($aid)];
    $p = wf_pack_own((string) $p['preset'], $p);
    return $p + ($P[$p['preset']] ?? $P['general']) + ['lib_tags' => $P['general']['lib_tags'], 'lib_audio_words' => $P['general']['lib_audio_words']]; // every profile starts with the section tags
}
/** Audio categories of the current number's business profile: "key | icon | label | colour" per line. */
function wf_audio_cats(?string $aid = null): array {
    $p = wf_pack($aid ?? (string) (wi_current()['id'] ?? '')); $out = [];
    foreach (preg_split('/\R/u', (string) ($p['audio_categories'] ?? '')) as $l) { $x = array_map('trim', explode('|', $l)); if (count($x) < 3 || !preg_match('/^[a-z_]{2,20}$/', $x[0])) continue;
        $out[] = ['key' => $x[0], 'icon' => $x[1], 'label' => $x[2], 'color' => preg_match('/^#[0-9a-f]{3,8}$/i', $x[3] ?? '') ? $x[3] : '#64748b']; }
    return $out;
}
/** Instruction templates: key => title, what it is for, placeholders it receives, starting text. */
/** $aid: the number the instructions are for (an add-on brings its own only to the numbers of its type); default the open number. */
function wf_prompt_registry(?string $aid = null): array {
    $aid ??= (string) (function_exists('wi_current') ? (wi_current()['id'] ?? '') : '');
    return wi_filter('prompt_registry', [
        'bot_fun' => ['title' => 'Warm-up mode rule (added to the bot reply while it is on)', 'vars' => [],
            'text' => "- WARM-UP MODE (turned on by the owner): chat like a friendly, witty member of the group — light jokes, playful replies to ANY message, 1–2 short lines, no lessons or advice unless asked. Keep it clean and kind: no jokes about religion, the Quran, illness, a person's body or family. Confidence 0.8 for a fitting light reply.\n"],
        'bot_ask' => ['title' => 'Rule when a group member writes the ask tag (#سؤال), added to the bot reply', 'vars' => [],
            'text' => "- ASK TAG (the person wrote the ask tag to ask the bot directly): answer ANY question helpfully — general knowledge, a translation, an explanation, advice — even when it is not about the business. Short and clear, in the person's dialect; a translation is given in the language asked. Keep the rules about sacred texts. Confidence 0.85 for a clear answer.\n"],
        'bot_team_person' => ['title' => 'Rule when the writer is a member of the team (a staff member / admin), added to the bot reply', 'vars' => [],
            'text' => "- THE WRITER IS A MEMBER OF THE TEAM (a staff member or an admin), NOT a customer: never ask about their situation, never call them a case, never treat them as someone being served. Talk to them as a colleague, briefly, and only about what they said.\n"],
        'bot_self_name' => ['title' => 'Rule about the name of this WhatsApp number (it is the team\'s number, not a person), added to the bot reply', 'vars' => ['self'],
            'text' => "- \"{{self}}\" is the name of THIS WhatsApp number (you, the team's number), not a person: never call it a person and never tell people to follow up with it; say \"the team\" without a name unless the sources give the name of the person in charge.\n- Use the person's name only if they wrote it or the customer card gives it; never take a name from another person in the conversation.\n\n"],
        'bot_fix' => ['title' => 'Bot reply rewrite (a draft had made-up counts or quoted texts)', 'vars' => ['draft', 'why'],
            'text' => "\n\nYOUR DRAFT: {{draft}}\nIt uses {{why}}that are in no team example and no source. Rewrite it without them: give only what the examples and sources say, or ask the team's follow-up question. Same JSON."],
        'bot_reply' => ['title' => 'Bot reply (private chats)', 'vars' => ['owner_rules', 'guide', 'name_line', 'business', 'tone', 'style_rules', 'who', 'owner_check', 'avoid', 'examples', 'sources', 'conversation'],
            'text' => "{{owner_rules}}{{guide}}You are {{name_line}} replying on WhatsApp for THIS number only: {{business}}.\nOnly talk about what this business does; never mention services of other numbers.\n"
                . "HOW TO REPLY — behave like a real person from the team typing on their phone, not like a robot or a search engine:\n"
                . "- {{dialect_rule}} Tone: {{tone}}.\n"
                . "- Read the whole conversation first and react to what they actually said; if they share something hard, acknowledge it in a few warm words once, then help.\n"
                . "- Write naturally: short everyday sentences, the way people chat. Match the customer's length and energy — a short message gets a short reply.\n"
                . "- No canned customer-service phrases (\"تم استلام رسالتك\", \"سيتم التواصل معك\", \"نشكرك على تواصلك\"), no headings, no bullet lists except when offering choices, no signature.\n"
                . "- Vary your wording: do not open every message with the same greeting or end with the same sentence; greet back only if they greeted.\n"
                . "- One idea per message and at most one question.\n"
                . "- Answer what they really need, in your own words, like the TEAM EXAMPLES. Use the SOURCES as knowledge, not as text to paste. {{sacred_rule}}\n"
                . "- If something important is unclear, ask ONE short question instead of guessing. When the person seems unsure or says they cannot describe it (vague words, \"مش عارف\", \"تعبان\", \"مش عارف اوصف\"), you MUST NOT ask an open question: offer 3–7 short numbered choices they can answer with a number (e.g. {{choices_example}}) and say they can pick more than one; if they answer with numbers, map them to the choices you offered.\n"
                . "{{style_rules}}"
                . "- WHO YOU TALK TO: {{who}}\n"
                . "- A NEW person has nothing yet: welcome them and tell them the FIRST step to start (from the sources). {{newcomer_rule}}\n"
                . "- An EXISTING person: use what you know (their groups, program, case) and do not repeat the onboarding.\n"
                . "- Never invent prices, times, links, diagnoses or promises. Not covered by the sources, a complaint, payment, an emergency or a personal review → handoff=true and say kindly, in your own words, that someone from the team will reply soon.\n"
                . "{{owner_check}}"
                . "Reply JSON only: {\"reply\":\"…\",\"confidence\":0.0,\"used\":[\"S1\"],\"handoff\":false,\"clarify\":false,\"reason\":\"short {{output_language}} reason\",\"symptoms\":false,\"complaint\":\"\"}\n"
                . "Set clarify=true when your reply is only a short question asking the customer to explain (no facts given).\n"
                . "Set symptoms=true only when the customer describes their own complaint or symptoms; complaint = those symptoms in one short {{output_language}} line.\n\n"
                . "{{avoid}}TEAM EXAMPLES (real replies — copy this style):\n{{examples}}\nSOURCES:\n{{sources}}\nCONVERSATION:\n{{conversation}}"],
        'suggest_reply' => ['title' => 'Suggest a reply (inbox)', 'vars' => ['owner_rules', 'business', 'tone', 'chat', 'library'],
            'text' => "{{owner_rules}}You help this business reply on WhatsApp: {{business}}. Write the NEXT reply from 'Us' to the last message. {{dialect_rule}} Tone: {{tone}}, 1–6 short lines. If a library text below fits, you may include it EXACTLY as written. {{sacred_rule}} Never invent prices, diagnoses or promises. Reply JSON only: {\"reply\":\"…\",\"used\":[\"L1\"],\"note\":\"short {{output_language}} note for the agent (optional)\"}\n\nCHAT:\n{{chat}}\n\nLIBRARY:\n{{library}}"],
        'doc_verify' => ['title' => 'Check uploaded files against the groups', 'vars' => ['passages'],
            'text' => "You check passages of an uploaded file against how THIS team really answers in its groups.\nFor each passage (### P<id>) compare FILE SAYS with the TEAM ANSWERS that follow it.\n- agree: the team says the same thing or the passage fits their way (same verses, same steps, same advice).\n- conflict: the team does it differently or says the opposite (another method, other counts, a practice they do not use, a claim they reject).\n- unrelated: the team answers are about something else.\nJudge only by what is written; never by your own opinion of what is right.\nReturn JSON only: {\"items\":[{\"id\":\"P12\",\"verdict\":\"agree|conflict|unrelated\",\"why\":\"one short reason in the team's language\"}]}\n\nPASSAGES:\n{{passages}}"],
        'case_intake' => ['Case from a group message', ['fields', 'text'],
            'text' => "You assist {{industry}}. A team member wrote, in a WhatsApp group, the details of a person to open a file for them (the messages are below; later messages answer questions and complete or correct earlier ones).\n"
                . "Fill these fields ONLY from what is written — never guess or invent; leave a field empty (\"\") when it is not said. Keep the writer's words for long fields; symptoms = one item per line.\nFIELDS (key: meaning):\n{{fields}}\n"
                . "Reply JSON only: {\"fields\":{\"key\":\"value\"}}\nMESSAGES:\n{{text}}"],
        'case_analysis' => ['title' => 'Case analysis', 'vars' => ['messages'],
            'text' => "You assist {{industry}}. Analyse this person's messages (in a case-reporting group and private chat). {{case_scope}} Reply JSON only, {{output_language}} values:\n{\"summary\":\"3-5 lines\",\"complaint\":\"main complaint\",\"symptoms\":[\"…\"],\"suspected\":[{\"type\":\"{{case_types}}\",\"confidence\":\"low|medium|high\",\"why\":\"…\"}],\"severity\":1,\"progress\":\"better|same|worse|unknown\",\"timeline\":[{\"date\":\"YYYY-MM-DD\",\"event\":\"…\"}],\"data\":{\"gender\":\"\",\"age\":\"\",\"country\":\"\"},\"recommended\":\"suggested program/section from the library\",\"next_step\":\"one practical next step\",\"red_flags\":\"anything needing a doctor or urgent attention, else empty\"}\n(Write confidence and progress words in {{output_language}}.)\n\nMESSAGES:\n{{messages}}"],
        'library_clean' => ['title' => 'Clean library files', 'vars' => ['allowed_names', 'passages'],
            'text' => "You are cleaning passages of a knowledge base used by a WhatsApp assistant of {{industry}}. The passages are in {{language}}.\nFor each passage decide:\n- keep=false if it has no useful teaching content (headers, ads, contact info, thanks, file metadata, table of contents, repeated filler, links lists, greetings).\n- keep=true otherwise and return the cleaned text: keep the useful paragraphs and topics word for word as much as possible, fix broken lines, but REMOVE: names of {{outside_people}}, book/source references and citations, names of groups/channels/pages, websites, links, phone numbers and emails. Rephrase minimally so the sentence still reads well after removing a name. {{sacred_rule}}\nAllowed names (team / group admins, keep them): {{allowed_names}}\nAlso give a short {{output_language}} topic title (max 8 words) for each kept passage.\nReturn JSON only: {\"items\":[{\"n\":1,\"keep\":true,\"topic\":\"...\",\"text\":\"...\"}]} with one item per passage.\n\nPassages:{{passages}}"],
        'guide_grow' => ['title' => 'Grow the guide from chats', 'vars' => ['guide', 'relations', 'existing', 'texts'],
            'text' => "You maintain the concept guide of {{industry}}'s WhatsApp assistant. Texts are in {{language}}.\nGUIDE (approved concepts with their linked words):\n{{guide}}\nRelations: {{relations}}\nFrom the NEW TEXTS below, find everyday words/short phrases people use that belong to an approved concept and are NOT already in its words (e.g. a new symptom, something that breaks it, a remedy, a synonym). Use the words as people write them (short, 1-4 words). count = in how many different texts it appears.\nAlso propose up to 3 NEW concepts only for clearly recurring topics that no concept covers (skip: {{existing}}).\nNever include names of people, groups, sites or {{outside_people}}. Reply JSON only: {\"terms\":[{\"concept\":\"exact concept name\",\"term\":\"…\",\"relation\":\"symptom\",\"count\":2}],\"concepts\":[{\"name\":\"…\",\"explain\":\"…\",\"hint\":\"…\",\"terms\":[{\"term\":\"…\",\"relation\":\"related\"}]}]}\n\nNEW TEXTS:\n{{texts}}"],
        'guide_suggest' => ['title' => 'Suggest guide concepts', 'vars' => ['existing', 'relations', 'knowledge'],
            'text' => "From this WhatsApp team's question/answer knowledge ({{language}}), find up to 8 CONCEPTS the team relies on (for example {{concept_example}}) and, for each, the everyday words people use that point to it (for example {{term_example}}).\nSkip concepts already in the guide: {{existing}}\nRelations allowed: {{relations}}\nReply JSON only: {\"concepts\":[{\"name\":\"…\",\"explain\":\"one or two lines\",\"hint\":\"how the team answers, one line\",\"terms\":[{\"term\":\"…\",\"relation\":\"related\"}]}]}\n\nKNOWLEDGE:\n{{knowledge}}"],
        'quick_reply_mine' => ['title' => 'Quick reply suggestions', 'vars' => ['messages'],
            'text' => "These are messages a WhatsApp team sends again and again ({{language}}). For EACH numbered message give: a short name (2–4 {{output_language}} words) and 3–6 TRIGGER phrases — short words or phrases a team member would type, or the customer would write, right before this message is needed (e.g. {{quick_reply_example}}).\nReply JSON only: {\"items\":[{\"n\":1,\"title\":\"…\",\"triggers\":[\"…\"]}]}\n\n{{messages}}"],
        'chat_summary' => ['title' => 'Chat summary', 'vars' => ['conversation'],
            'text' => "You analyse a WhatsApp conversation between a business and one contact (it may be a group). Reply with JSON only:\n{\"summary\":\"3 short lines in the conversation language\",\"category\":\"one of: {{summary_categories}}\",\"sentiment\":\"positive|neutral|negative\",\"needs_reply\":true|false,\"next_action\":\"one short suggested next step in the same language\"}\n\nConversation:\n{{conversation}}"],
        'library_sort' => ['title' => 'Library: sort & check', 'vars' => ['known', 'items'],
            'text' => "You organise a library of {{library_kind}} ({{language}}). For EACH item below decide:\n- section: one of [{{library_sections}}]\n- topic: a short {{output_language}} topic (2-4 words), reuse the same wording for related items\n- title: a short clear {{output_language}} title (max 8 words)\n- quality: 1-5 ({{text_quality}} = 5)\n- valid: false if it contains a mistake (e.g. {{text_errors}}) or is incomplete/garbled, else true\n- note: very short {{output_language}} note on any problem, empty if none\n- merge_into: the id of ANOTHER group (from the items or the KNOWN list) that is essentially the same text, otherwise empty\nNever change the texts. Reply JSON only: {\"items\":[{\"id\":\"…\",\"section\":\"…\",\"topic\":\"…\",\"title\":\"…\",\"quality\":4,\"valid\":true,\"note\":\"\",\"merge_into\":\"\"}]}\n\nKNOWN groups (id | section | title):\n{{known}}\n\nITEMS:\n{{items}}"],
        'library_compare' => ['title' => 'Library: compare versions', 'vars' => ['versions'],
            'text' => "These are versions of the same {{language}} text ({{library_kind}}) shared on WhatsApp. Compare them and choose the BEST version: {{text_quality}}, no missing parts, no garbled characters. {{sacred_rule}} Reply JSON only:\n{\"best\":1,\"reason\":\"short {{output_language}} reason\",\"ranking\":[1,2,3],\"differences\":\"short {{output_language}} summary of the main differences\",\"problems\":{\"2\":\"short {{output_language}} problem in V2\"}}\n\n{{versions}}"],
        'library_format' => ['title' => 'Library: format text', 'vars' => ['text'],
            'text' => "Format this {{language}} WhatsApp text for clean reading. Do NOT change, add or remove any word, letter or diacritic. {{sacred_rule}} Only: fix spacing, put each item/step on its own line, keep numbering consistent, remove repeated empty lines and stray symbols, keep emojis that mark sections. Reply JSON only: {\"text\":\"…formatted text…\"}\n\nTEXT:\n{{text}}"],
        'rec_pick' => ['title' => 'Suggestions: which written text / program / recording fits this person', 'vars' => ['person', 'items'],
            'text' => "A person wrote this (their own words, and their case notes):\n{{person}}\n\nThese items can be sent to them (number) title — what it is for):\n{{items}}\nPick up to 3 that really fit what the person describes, best first. For each: n (the number), confidence 1-99 (how sure it fits), why (one short line in the person's language). Pick nothing that does not fit.\nReturn JSON only: {\"picks\":[{\"n\":0,\"confidence\":80,\"why\":\"...\"}]}"],
        'voice_text' => ['title' => 'Voice note to text (the model hears the audio)', 'vars' => [], 'text' => "Write down exactly what is said in this voice note, in its own language and dialect, word for word (no translation, no summary, no comments). If nothing can be understood write an empty text. Return JSON only: {\"text\":\"...\"}"],
        'quality_judge' => ['title' => 'Weekly quality test: score the bot\'s reply against the team\'s', 'vars' => ['question', 'team', 'bot'], 'text' => "A person asked a WhatsApp business ({{industry}}):\n{{question}}\n\nThe team answered:\n{{team}}\n\nThe assistant bot answered:\n{{bot}}\n\nScore the bot's answer from 1 to 5 against the team's: 5 = as right and useful as the team's (wording may differ), 3 = partly right or too general, 1 = wrong, harmful or invented. Give the topic in a few words and one short reason, in {{output_language}}. Return JSON only: {\"score\":4,\"topic\":\"...\",\"why\":\"...\"}"],
        'checkin_msg' => ['title' => 'Follow-up check-in: the short message the bot sends to a person under follow-up', 'vars' => ['name', 'stage', 'complaint', 'talk'], 'text' => "Write ONE short WhatsApp message (1-2 lines) from {{industry}} to a person under follow-up, asking warmly how they are now and whether they kept up with what they were given. Use their name if it is a real name ({{name}}). It must read like a caring person, in their dialect ({{dialect_rule}}), not like a form; mention one thing from their case or the last talk so it is personal; no new advice, no religious text, no promises, no questions list. Stage: {{stage}}. Complaint: {{complaint}}.\nLast talk:\n{{talk}}\nReturn JSON only: {\"text\":\"...\"}"],
        'kb_compress' => ['title' => 'Bot learning: shorten exchanges into one line each (a free model)', 'vars' => ['exchanges'],
            'text' => "Below are question → team answer exchanges from WhatsApp groups. For EACH one write ONE short line in the same language: the question in a few words, then → the useful part of the answer (advice, steps, times, names of programs or texts). Leave out names, phone numbers, links, greetings and chit-chat. If an exchange has nothing worth keeping write \"-\".\n\n{{exchanges}}\n\nReturn JSON only: {\"items\":[{\"id\":\"...\",\"line\":\"...\"}]}"],
        'kb_extract' => ['title' => 'Bot learning from chats', 'vars' => ['business', 'topics', 'exchanges'],
            'text' => "These are real WhatsApp exchanges of this business: {{business}} (customer message → the team's reply). Turn the USEFUL ones into reusable knowledge for an assistant bot. Skip chit-chat, greetings, personal case details, and replies that only make sense for that one person. Generalise the question; keep the team's answer wording and style, but remove names, phone numbers and personal details. {{sacred_rule}} Reuse these topics when they fit: [{{topics}}]. Reply JSON only:\n{\"items\":[{\"topic\":\"short {{output_language}} topic\",\"q\":\"general customer question\",\"a\":\"the answer the bot can give\",\"type\":\"{{kb_types}}\",\"from\":[\"pair ids used\"]}]}\n\nEXCHANGES:\n{{exchanges}}"],
        'profile_detect' => ['title' => 'Detect this number\'s business', 'vars' => ['name', 'messages'],
            'text' => "Below are real WhatsApp messages of ONE business number named \"{{name}}\". Describe this business for an assistant bot that will answer its customers. Reply JSON only, values in {{output_language}}:\n{\"profile\":\"2-3 lines: what the business does and offers\",\"audience\":\"who writes to it\",\"language\":\"languages/dialects customers use\",\"tone\":\"how the team talks\",\"common\":[\"5 most common customer requests\"],\"handoff\":[\"things the bot must leave to a human\"]}\n\nMESSAGES:\n{{messages}}"],
        'registry_extract' => ['title' => 'Extract cases from group reports', 'vars' => ['reports'],
            'text' => "These are case reports posted in the WhatsApp group of {{industry}} ({{language}}). For EACH report extract the case details. Do not invent anything that is not written. Reply JSON only:\n{\"items\":[{\"id\":\"…\",\"name\":\"name or empty\",\"phone\":\"main phone\",\"gender\":\"male|female| (in {{output_language}})\",\"age\":\"\",\"country\":\"\",\"complaint\":\"main complaint\",\"symptoms\":[\"…\"],\"diagnosis\":\"the assessment written in the report\",\"summary\":\"one line\",\"labels\":[\"2-4 short {{output_language}} tags e.g. {{case_labels}}\"]}]}\n\nREPORTS:\n{{reports}}"],
    ], $aid);
}
/** The instruction text for a task on this number: its own edited version, or the starting text; then {{placeholders}} are filled. */
function wf_prompt(string $key, string $aid, array $vars = []): string {
    $R = wf_prompt_registry($aid); $own = (array) (wi_read('bot.json', [])[$aid]['prompts'] ?? []);
    // an add-on may bring its own starting text for a business type (the number's own edited version always wins)
    $tpl = trim((string) ($own[$key] ?? '')) !== '' ? (string) $own[$key] : (string) wi_filter('prompt_text', (string) ($R[$key]['text'] ?? ''), $key, $aid);
    $vars += wf_pack($aid) + ['business' => wf_profile($aid), 'owner_rules' => function_exists('wf_owner_rules') ? wf_owner_rules(wf_bot_cfg($aid)) : ''];
    return preg_replace_callback('/\{\{(\w+)\}\}/', fn($m) => is_scalar($vars[$m[1]] ?? null) ? (string) $vars[$m[1]] : '', $tpl);
}
function wf_api_prompts(string $api): void {
    if ($api !== 'ai_prompts') return;
    $aid = wf_aid(); $all = wi_read('bot.json', []);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') { $in = wf_post(); wf_admin(); $do = (string) ($in['do'] ?? '');
        if ($do === 'pack') { $P = wf_pack_presets(); $pre = isset($P[$in['preset'] ?? '']) ? $in['preset'] : 'general'; $x = ['preset' => $pre];
            if (empty($in['reset'])) foreach (array_keys($P['general']) as $k) if ($k !== 'label' && isset($in['pack'][$k])) $x[$k] = mb_substr(trim((string) $in['pack'][$k]), 0, 2000);
            if (isset($x['lib_tags'])) { $seen = []; foreach (preg_split('/\R/u', $x['lib_tags']) as $l) { $t = wf_media_code_clean(trim(explode('|', $l)[0])); if ($t === '') continue; $n = str_replace(['-', ' '], '', wf_intake_norm($t));
                if (isset($seen[$n])) wi_json(['ok' => false, 'error' => 'The tag ' . $t . ' is written twice.'], 422);
                if (($why = wf_tag_taken($aid, $t, '', true)) !== '') wi_json(['ok' => false, 'error' => $t . ': ' . $why], 422); $seen[$n] = 1; } }
            $keep = array_intersect_key((array) ($all[$aid]['pack'] ?? []), array_flip(['lib_cmds'])); // set elsewhere (Bot → Commands)
            $all[$aid]['pack'] = wf_pack_own($pre, $x) + $keep; wi_write('bot.json', $all); wi_log('ai_pack_save', ['account' => $aid, 'preset' => $pre]); wi_json(['ok' => true]); }
        if ($do === 'prompt') { $k = (string) ($in['key'] ?? ''); if (!isset(wf_prompt_registry($aid)[$k])) wi_json(['ok' => false, 'error' => 'Unknown instruction.']);
            $t = trim((string) ($in['text'] ?? '')); if ($t === '' || !empty($in['reset'])) unset($all[$aid]['prompts'][$k]); else $all[$aid]['prompts'][$k] = mb_substr($t, 0, 20000);
            wi_write('bot.json', $all); wi_log('ai_prompt_save', ['account' => $aid, 'key' => $k]); wi_json(['ok' => true]); }
        wi_json(['ok' => false, 'error' => 'Unknown action.']); }
    $own = (array) ($all[$aid]['prompts'] ?? []); $list = [];
    foreach (wf_prompt_registry($aid) as $k => $r) $list[] = ['key' => $k, 'title' => $r['title'], 'vars' => $r['vars'], 'default' => $r['text'], 'text' => (string) ($own[$k] ?? ''), 'edited' => isset($own[$k])];
    $presets = []; foreach (wf_pack_presets() as $k => $p) $presets[$k] = $p;
    wi_json(['ok' => true, 'pack' => wf_pack($aid), 'own_pack' => $all[$aid]['pack'] ?? null, 'presets' => $presets, 'prompts' => $list, 'common' => array_values(array_filter(array_diff(array_keys(wf_pack_presets()['general']), ['label']), fn($k) => !str_starts_with($k, 'seed_'))) /* the starting word lists are kept out of this editor */]);
}
