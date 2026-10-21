<?php
/* ---------- What a "case" is on each number (Cases page → Case settings): its name, the stages' names and the form fields.
 * The stage keys stay fixed (new / follow / improving / closed) so reports and follow-up keep working; only the words change. ---------- */
function wf_case_presets(): array {
    $f = fn(array $labels, array $off = []) => array_map(fn($k) => ['on' => !in_array($k, $off, true), 'label' => $labels[$k]], array_combine(array_keys($labels), array_keys($labels)));
    $words = ['the' => 'ال', 'list' => '#قائمة', 'shortcuts' => '#الاختصارات', 'programs' => '#البرامج', 'library' => '#المكتبة', 'archive' => '#ارشف', 'voice' => 'التسجيل الصوتي', 'menu' => '#menu', 'menu_alias' => '#منيو, #مينيو, #الاوامر, #اوامر, #الأوامر, #help, #commands, #مساعده, #المساعده, #قائمه, #قايمه, #القائمه',
        // #menu: per command — what it does, how to write it, an example ({case} / {many} = what one case / several are called)
        'help' => [
            'sec_cases' => '📁 {many}', 'sec_lists' => '📋 القوائم', 'sec_library' => '📖 المكتبة', 'sec_files' => '🏷️ اختصارات الملفات (اكتب الاختصار لوحده)', 'sec_quick' => '⚡ الردود الجاهزة (اكتب الكلمة)', 'more' => 'و{n} كمان في اللوحة', 'inventory' => 'جرد المكتبة كله (الملفات واختصاراتها والردود والبرامج)', 'how' => 'اكتب:', 'eg' => 'مثال:',
            'new' => ['فتح {case} جديدة', '{cmd} @الشخص + التفاصيل', '{cmd} @الشخص + الطلب والتفاصيل'],
            'lookup' => ['عرض ملف {case}', '{cmd} الاسم أو الرقم أو ID', '{cmd} 5'],
            'stage' => ['تغيير مرحلة ال{case}', 'أمر المرحلة + ID أو الاسم + السبب', '{cmd} 5 النوم اتحسن'],
            'archive' => ['أرشفة {case}', '{cmd} ID أو الاسم', '{cmd} 5'],
            'list' => ['قائمة {many} أو الاختصارات أو البرامج', '{cmd} + اللي عايزه', '{cmd} #الاختصارات'],
            'send' => ['إرسال ملف أو تسجيل من المكتبة', '{cmd} اسم الملف', '{cmd} اسم الملف'],
            'program' => ['إرسال برنامج', '{cmd} اسم البرنامج', '{cmd} اسم البرنامج'],
            'text' => ['إرسال نص مكتوب', '{cmd} اسم النص', '{cmd} اسم النص'],
        ]]; // group command words (Case from a group)
    // spiritual healing only: its own wording and examples, never shown to other businesses
    $wordsT = array_replace_recursive($words, ['voice' => 'التسجيل الصوتي للحالة', 'help' => ['new' => [2 => '{cmd} @الشخص السن والبلد والأعراض'], 'list' => [2 => '{cmd} #الحالة'],
        'send' => [2 => '{cmd} رقية العين'], 'program' => [0 => 'إرسال برنامج علاج', 2 => '{cmd} العين والحسد'], 'text' => [0 => 'إرسال رقية مكتوبة', 1 => '{cmd} اسم الرقية', 2 => '{cmd} المس العاشق']]]);
    $L = [
        'treatment' => ['label' => 'Patients / healing follow-up', 'one' => 'حالة', 'many' => 'الحالات', 'icon' => '🩺',
            'statuses' => ['new' => '🆕 جديدة', 'follow' => '🩺 تحت المتابعة', 'improving' => '📈 في تحسن', 'closed' => '✅ اتقفلت', 'postponed' => '⏸️ اتأجلت', 'removed' => '🗑️ اتحذفت', 'archived' => '📦 مؤرشفة'],
            'cmds' => ['improving' => '#اتحسنت', 'postponed' => '#اتأجلت', 'removed' => '#اتحذفت', 'closed' => '#اتقفلت', 'follow' => '#تحت_المتابعة', 'archived' => '#ارشف'],
            'fields' => $f(['name' => 'الاسم', 'phone' => 'الرقم', 'join' => 'تاريخ الانضمام', 'age' => 'السن', 'marital' => 'الحالة الاجتماعية', 'kids' => 'عدد الأولاد', 'country' => 'البلد', 'follower' => 'متابعة', 'story' => 'القصة / التاريخ', 'symptoms' => 'الأعراض المؤكدة', 'extra' => 'أعراض إضافية', 'diagnosis' => 'تشخيص', 'diag_by' => 'شخّصها', 'placement' => 'ملاحظة المكان'])],
        'support' => ['label' => 'Customer support tickets', 'one' => 'تذكرة', 'many' => 'التذاكر', 'icon' => '🎫',
            'statuses' => ['new' => '🆕 جديدة', 'follow' => '🔧 جاري الحل', 'improving' => '⏳ مستنية رد العميل', 'closed' => '✅ اتحلت', 'postponed' => '⏸️ اتأجلت', 'removed' => '🗑️ اتحذفت', 'archived' => '📦 مؤرشفة'],
            'fields' => $f(['name' => 'اسم العميل', 'phone' => 'الرقم', 'join' => 'تاريخ الفتح', 'age' => 'السن', 'marital' => 'الحالة الاجتماعية', 'kids' => 'عدد الأولاد', 'country' => 'البلد', 'follower' => 'المسؤول', 'story' => 'وصف المشكلة', 'symptoms' => 'تفاصيل المشكلة', 'extra' => 'ملاحظات إضافية', 'diagnosis' => 'التصنيف / الحل', 'diag_by' => 'حلّها', 'placement' => 'ملاحظة'], ['age', 'marital', 'kids'])],
        'crm' => ['label' => 'CRM — leads and customers', 'one' => 'عميل', 'many' => 'العملاء', 'icon' => '🤝',
            'statuses' => ['new' => '🆕 جديد', 'follow' => '📞 جاري التواصل', 'improving' => '💼 عرض / تفاوض', 'closed' => '✅ تم', 'postponed' => '⏸️ اتأجل', 'removed' => '🗑️ اتحذف', 'archived' => '📦 مؤرشف'],
            'fields' => $f(['name' => 'الاسم', 'phone' => 'الرقم', 'join' => 'تاريخ الإضافة', 'age' => 'السن', 'marital' => 'الحالة الاجتماعية', 'kids' => 'عدد الأولاد', 'country' => 'البلد', 'follower' => 'المسؤول',
                'story' => 'الطلب / الاحتياج', 'symptoms' => 'المنتجات أو الخدمات المهتم بيها', 'extra' => 'ملاحظات إضافية', 'diagnosis' => 'قيمة الصفقة', 'diag_by' => 'المصدر (إعلان، توصية…)', 'placement' => 'الشركة'], ['age', 'marital', 'kids'])],
        'general' => ['label' => 'General follow-up', 'one' => 'متابعة', 'many' => 'المتابعات', 'icon' => '🗂️',
            'statuses' => ['new' => '🆕 جديدة', 'follow' => '🔁 تحت المتابعة', 'improving' => '📈 بتتقدم', 'closed' => '✅ خلصت', 'postponed' => '⏸️ اتأجلت', 'removed' => '🗑️ اتحذفت', 'archived' => '📦 مؤرشفة'],
            'fields' => $f(['name' => 'الاسم', 'phone' => 'الرقم', 'join' => 'التاريخ', 'age' => 'السن', 'marital' => 'الحالة الاجتماعية', 'kids' => 'عدد الأولاد', 'country' => 'البلد', 'follower' => 'المسؤول', 'story' => 'التفاصيل', 'symptoms' => 'النقاط الأساسية', 'extra' => 'ملاحظات', 'diagnosis' => 'النتيجة', 'diag_by' => 'بواسطة', 'placement' => 'ملاحظة'], ['age', 'marital', 'kids'])],
    ];
    foreach ($L as $k => $p) $L[$k] = $p + ['words' => $k === 'treatment' ? $wordsT : $words];
    return $L;
}
/** This number's case settings: its own choice, or (older numbers) the preset that fits its business profile. */
function wf_case_cfg(string $aid): array {
    $own = (array) (wi_read('case_cfg.json', [])[$aid] ?? []); $P = wf_case_presets();
    // without its own choice: follows the business type (healing → treatment, CRM → crm, else general)
    $pp = function_exists('wf_pack') ? (string) (wf_pack($aid)['preset'] ?? '') : ''; $def = ['ruqyah' => 'treatment', 'crm' => 'crm', 'support' => 'support'][$pp] ?? 'general';
    $base = $P[$own['preset'] ?? $def] ?? $P['general'];
    $c = $own + $base; $c['statuses'] = ((array) ($own['statuses'] ?? [])) + $base['statuses']; $c['fields'] = ((array) ($own['fields'] ?? [])) + $base['fields'];
    $c['preset'] = $own['preset'] ?? $def;
    return $c;
}
function wf_case_statuses(string $aid): array { return wf_case_cfg($aid)['statuses']; }
/** The report posted to the group, built from this number's field names (a field switched off or left empty is left out). */
function wf_case_report(string $aid, array $v): string {
    $F = wf_case_cfg($aid)['fields']; $on = fn($k) => !empty($F[$k]['on']) && trim((string) ($v[$k] ?? '')) !== ''; $lbl = fn($k) => (string) ($F[$k]['label'] ?? $k);
    $L = [];
    if ($on('join')) { $L[] = $lbl('join') . ': ' . $v['join']; $L[] = ''; }
    $L[] = $lbl('name') . ': ' . $v['name']; if (trim((string) $v['phone']) !== '') $L[] = $lbl('phone') . ': +' . $v['phone']; // always shown (a case from a group may come without a number)
    foreach (['age', 'marital', 'kids', 'country'] as $k) if ($on($k)) $L[] = $lbl($k) . ': ' . $v[$k];
    if ($on('follower')) { $L[] = ''; $L[] = $lbl('follower') . ': ' . $v['follower']; }
    if ($on('story')) { $L[] = ''; $L[] = $v['story']; }
    if ($on('symptoms')) { $L[] = ''; $L[] = $lbl('symptoms') . ':'; $L[] = ''; foreach (array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) $v['symptoms'])))) as $i => $x) { $L[] = ($i + 1) . '. ' . preg_replace('/^\s*\d+\s*[\.\-\)]\s*/u', '', $x); $L[] = ''; } }
    if ($on('extra')) { $L[] = $lbl('extra') . ':'; $L[] = ''; $L[] = $v['extra']; }
    if ($on('diagnosis')) { $L[] = ''; $L[] = $lbl('diagnosis') . ': ' . ($v['diag_by'] ?? ''); $L[] = $v['diagnosis']; }
    if ($on('placement')) $L[] = $v['placement'];
    return trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $L)));
}
function wf_api_case_cfg(string $api): void {
    if ($api !== 'case_cfg') return;
    $aid = wf_aid(); if ($aid === '') wi_json(['ok' => false, 'error' => 'No number.']);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') { $in = wf_post(); wf_admin();
        $P = wf_case_presets(); $pre = isset($P[$in['preset'] ?? '']) ? $in['preset'] : 'general';
        $x = ['preset' => $pre];
        if (empty($in['reset'])) {
            foreach (['one', 'many', 'icon'] as $k) if (trim((string) ($in[$k] ?? '')) !== '') $x[$k] = mb_substr(trim((string) $in[$k]), 0, $k === 'icon' ? 8 : 40);
            foreach (array_keys($P[$pre]['statuses']) as $k) if (trim((string) ($in['statuses'][$k] ?? '')) !== '') $x['statuses'][$k] = mb_substr(trim((string) $in['statuses'][$k]), 0, 40);
            foreach (array_keys($P[$pre]['fields']) as $k) if (isset($in['fields'][$k])) $x['fields'][$k] = ['on' => !empty($in['fields'][$k]['on']), 'label' => mb_substr(trim((string) ($in['fields'][$k]['label'] ?? '')), 0, 40) ?: $P[$pre]['fields'][$k]['label']];
        }
        wi_update('case_cfg.json', function ($C) use ($aid, $x) { $C[$aid] = $x; return $C; });
        wi_log('case_cfg_save', ['account' => $aid, 'preset' => $pre]); wi_json(['ok' => true, 'cfg' => wf_case_cfg($aid)]); }
    $presets = []; foreach (wf_case_presets() as $k => $p) $presets[$k] = $p;
    wi_json(['ok' => true, 'cfg' => wf_case_cfg($aid), 'presets' => $presets]);
}
