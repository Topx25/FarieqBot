<?php
/* ---------- Settings → System: values that differ per installation (brand, address, time zone, country, sign-in limits, bridge) ---------- */
function wf_sys_fields(): array {
    return [
        'legal_owner' => ['Owner name on the privacy policy and terms pages (empty = product name)', 'text'], 'legal_email' => ['Contact e-mail on those pages', 'text'],
        'privacy_text' => ['Privacy policy page (index.php?privacy) — empty = the built-in text; "# " starts a heading', 'longtext'], 'terms_text' => ['Terms of service page (index.php?terms) — empty = the built-in text', 'longtext'],
        'brand_name' => ['Product name', 'text'], 'brand_logo' => ['Logo (emoji or short text)', 'text'], 'device_name' => ['Device name shown on linked phones', 'text'],
        'base_url' => ['Dashboard address (used in links sent on WhatsApp, Google sign-in, meetings)', 'url'], 'cookie_path' => ['Sign-in cookie path', 'text'],
        'timezone' => ['Time zone', 'tz'], 'country_code' => ['Country code for local numbers (e.g. 20, 966)', 'digits'], 'trunk_prefix' => ['Local prefix to drop (usually 0)', 'digits'],
        'idle_min' => ['Sign out after inactivity (minutes)', 'int'], 'otp_min' => ['Login code valid for (minutes)', 'int'], 'max_fails' => ['Failed attempts before a lock', 'int'], 'lock_min' => ['Lock time (minutes)', 'int'],
        'changelog' => ['What\'s new page: product (customers\' version) or full (development log, super admin)', 'text'], 'refresh_pct' => ['Page refresh pace in % (100 = normal, 200 = half as often)', 'int'], 'max_send_mb' => ['Largest file sent from a chat (MB)', 'int'], 'landing_cta_url' => ['Landing page (/welcome): the main button\'s link (empty = sign-in)', 'text'], 'landing_cta_label' => ['Landing page: the main button\'s words', 'text'], 'plan_currency' => ['Currency shown with the plans\' prices', 'text'], 'idle_warn_days' => ['A number not linked (needs the QR): warn after (days)', 'int'], 'idle_stop_days' => ['A number not linked: switch it off after (days)', 'int'], 'idle_qr_min' => ['A stopped number runs this long (minutes) after its QR page is opened', 'int'], 'group_add_day' => ['People added to groups from chats, per number per day (protects the number)', 'int'], 'keyword_per_hour' => ['Members\' keyword replies (#keyword), at most per number per hour', 'int'], 'keyword_cooldown_min' => ['The same person asking the same keyword in a chat is answered once per (minutes)', 'int'], 'notify_clients' => ['Remind clients on WhatsApp before their subscription ends (1 = on, 0 = admins only)', 'int01'], 'max_learn_mb' => ['Largest learning document (MB)', 'int'], 'max_status_mb' => ['Largest status upload (MB)', 'int'], 'max_export_mb' => ['Largest media included in a chat export (MB)', 'int'], 'months_recent' => ['Months of chats the bot and guide growth look at', 'int'], 'months_short' => ['Months read for reports, groups and suggestions', 'int'], 'months_long' => ['Months of history for numbers, customers and learning', 'int'], 'ai_safety' => ['Gemini safety filter (BLOCK_NONE, BLOCK_ONLY_HIGH, BLOCK_MEDIUM_AND_ABOVE)', 'text'], 'currency' => ['Currency shown when an invoice has none (e.g. EGP, SAR, USD)', 'text'], 'number_locale' => ['Number / date format for Arabic (e.g. ar-EG, ar-SA)', 'text'], 'msg_lang' => ['Language of the starting message templates (ar / en)', 'text'], 'backup_folder' => ['Google Drive backup folder name', 'text'],
        'ai_engine' => ['AI engine: local (own keys, AI providers page) or bridge (through the invoice system)', 'text'], 'addon_invoices' => ['Invoices add-on (1 = on, 0 = off)', 'int01'], 'invoices_config' => ['Invoice system config.php path (empty = next to this app)', 'text'], 'trusted_proxies' => ['Trusted proxies whose visitor-IP header is believed (IP ranges, space separated; default = Cloudflare)', 'text'], 'bridge_url' => ['AI / invoice bridge address', 'url'], 'bridge_host' => ['Bridge host header (optional)', 'text'],
    ];
}
function wf_api_system(string $api): void {
    if ($api !== 'sys_settings') return;
    if (!wi_is_super()) wi_json(['ok' => false, 'error' => 'Super admin only.'], 403);
    $F = wf_sys_fields();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') { $in = (array) (wf_post()['sys'] ?? []); $c = wi_read('config.json', []); $S = (array) ($c['system'] ?? []);
        foreach ($F as $k => [, $type]) { if (!array_key_exists($k, $in)) continue; $v = trim((string) $in[$k]);
            if ($type === 'int') $v = max(1, min(1440, (int) $v));
            elseif ($type === 'int01') $v = (int) $v ? 1 : 0;
            elseif ($type === 'digits') $v = preg_replace('/\D/', '', $v);
            elseif ($type === 'tz' && !in_array($v, timezone_identifiers_list(), true)) wi_json(['ok' => false, 'error' => 'Unknown time zone.']);
            elseif ($type === 'url' && $v !== '' && !preg_match('#^https?://#i', $v)) wi_json(['ok' => false, 'error' => 'An address must start with http:// or https://']);
            if ($k === 'ai_engine' && !in_array($v, ['local', 'bridge'], true)) wi_json(['ok' => false, 'error' => 'AI engine must be local or bridge.']);
            if ($k === 'base_url' && $v !== '') $v = rtrim($v, '/') . '/';
            if ($k === 'cookie_path') $v = '/' . trim($v, '/') . (trim($v, '/') !== '' ? '/' : '');
            $S[$k] = is_string($v) ? mb_substr($v, 0, $type === 'longtext' ? 20000 : 300) : $v; }
        $c['system'] = $S; wi_write('config.json', $c); wi_log('system_settings_save', ['keys' => array_keys($in)]); wi_json(['ok' => true]); }
    $out = []; foreach ($F as $k => [$label, $type]) $out[] = ['key' => $k, 'label' => $label, 'type' => $type, 'value' => wi_sys($k), 'default' => wi_sys_defaults()[$k] ?? ''];
    wi_json(['ok' => true, 'fields' => $out, 'timezones' => timezone_identifiers_list()]);
}
/* ---------- Gateway content rules (wa-local/rules.json): never-stored texts, stripped link domains, text tags ---------- */
function wf_gw_rules_file(): string { return dirname(__DIR__, 2) . '/wa-local/rules.json'; }
/** Where a number's own rules live (main: wa-local/rules.main.json, others: wa-local/accounts/<id>/rules.json). */
function wf_acc_rules_path(string $aid): string { $wl = dirname(__DIR__, 2) . '/wa-local'; return $aid === 'main' ? $wl . '/rules.main.json' : $wl . '/accounts/' . preg_replace('/[^a-z0-9_-]/', '', $aid) . '/rules.json'; }
/** The rules file a number's gateway reads: its own, or the shared one. */
function wf_acc_rules_file(string $aid): string { $f = wf_acc_rules_path($aid); return $aid !== '' && is_file($f) ? $f : wf_gw_rules_file(); }
/** Library sections for a type of business (sales, IT support). */
function wf_library_rules(string $type): array {
    $sec = fn($n, $p, $i) => ['name' => $n, 'pattern' => $p, 'body' => true, 'icon' => $i];
    $S = $type === 'support'
        ? [$sec('حلول المشاكل', 'حل|الحل|جرب|اعمل ريستارت|restart|إعادة تشغيل|اعاده تشغيل|fix', '🛠️'), $sec('شروحات', 'شرح|خطوات|طريقة|ازاي|إزاي|guide', '📘'),
           $sec('الشبكات والإنترنت', 'نت|انترنت|إنترنت|راوتر|واي فاي|wifi|شبكة|router', '🌐'), $sec('سياسات وأسعار', 'سعر|اسعار|أسعار|اشتراك|عقد|ضمان|سياس', '📜'), $sec('أسئلة شائعة', 'سؤال|اسئلة|أسئلة|استفسار|FAQ', '❓')]
        : [$sec('عروض', 'عرض|عروض|خصم|تخفيض|اوفر|offer', '🏷️'), $sec('أسعار', 'سعر|اسعار|أسعار|بكام|جنيه|ريال|درهم|price', '💰'), $sec('التوصيل والدفع', 'توصيل|شحن|الدفع|تحويل|كاش|فودافون|delivery', '🚚'),
           $sec('سياسات وضمان', 'ضمان|استرجاع|استبدال|سياس', '📜'), $sec('أسئلة شائعة', 'سؤال|اسئلة|أسئلة|استفسار|FAQ', '❓')];
    return ['topics' => [], 'default_topic' => 'عام', 'other_section' => 'أخرى', 'audio_section' => 'صوتيات', 'voice_title' => 'رسالة صوتية', 'audio_title' => 'ملف صوتي', 'audio_tag' => '', 'sections' => $S];
}
/** Writes a number's own rules for its type of business: the shared protections, no healing tags, that type's library. */
function wf_acc_rules_write(string $aid, string $type): void {
    $g = json_decode((string) @file_get_contents(wf_gw_rules_file()), true) ?: []; $f = wf_acc_rules_path($aid); if (!is_dir(dirname($f))) return;
    @file_put_contents($f, json_encode(['secret_patterns' => $g['secret_patterns'] ?? [], 'strip_link_domains' => $g['strip_link_domains'] ?? [], 'tags' => [], 'library' => wf_library_rules($type)], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    wi_log('acc_rules_written', ['account' => $aid, 'type' => $type]);
}
/** A client's number gets its own rules once (never overwritten afterwards). */
function wf_client_rules_ensure(array $acc): void {
    $aid = (string) ($acc['id'] ?? ''); if ($aid === '' || wi_ws($acc) === '' || is_file(wf_acc_rules_path($aid))) return;
    wf_acc_rules_write($aid, (string) (wf_pack($aid)['preset'] ?? 'crm'));
}
function wf_api_gw_rules(string $api): void {
    if ($api !== 'gw_rules') return;
    if (!wi_is_super()) wi_json(['ok' => false, 'error' => 'Super admin only.'], 403);
    $f = wf_gw_rules_file();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') { $in = (array) (wf_post()['rules'] ?? []);
        $lines = fn($v) => array_values(array_filter(array_map('trim', is_array($v) ? $v : preg_split('/\R/u', (string) $v))));
        $ok = fn($p) => @preg_match('/' . str_replace('/', '\/', $p) . '/u', '') !== false;
        $out = ['secret_patterns' => $lines($in['secret_patterns'] ?? ''), 'strip_link_domains' => array_values(array_filter(array_map(fn($d) => strtolower(preg_replace('/[^A-Za-z0-9.-]/', '', $d)), $lines($in['strip_link_domains'] ?? '')))), 'tags' => []];
        foreach ((array) ($in['tags'] ?? []) as $t) { $k = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($t['key'] ?? ''))); if ($k === '') continue;
            $out['tags'][] = ['key' => $k, 'label' => mb_substr(trim((string) ($t['label'] ?? $k)), 0, 40), 'patterns' => $lines($t['patterns'] ?? ''), 'quran_index' => !empty($t['quran_index']), 'tashkeel_ratio' => max(0, min(1, (float) ($t['tashkeel_ratio'] ?? 0)))]; }
        // Library: "name => pattern" per line (sections: end with " [heading]" to count a section only from the heading / whole text, never the first lines)
        $pairs = function ($v, bool $sec) use ($lines) { $o = []; foreach ($lines($v) as $l) { $p = array_map('trim', explode('=>', $l, 2)); if (count($p) < 2 || $p[0] === '' || $p[1] === '') continue;
            $head = (bool) preg_match('/\s*\[heading\]\s*$/', $p[1]); $pat = trim(preg_replace('/\s*\[heading\]\s*$/', '', $p[1]));
            $x = ['name' => mb_substr($p[0], 0, 40), 'pattern' => $pat]; if ($sec) $x['body'] = !$head; $o[] = $x; } return $o; };
        $oldLib = (json_decode((string) @file_get_contents($f), true) ?: [])['library'] ?? []; $icons = []; foreach ((array) ($oldLib['sections'] ?? []) as $x) if (!empty($x['icon'])) $icons[$x['name']] = $x['icon']; // icons are kept by section name
        $lb = (array) ($in['library'] ?? []);
        $out['library'] = ['topics' => $pairs($lb['topics'] ?? '', false), 'default_topic' => mb_substr(trim((string) ($lb['default_topic'] ?? '')), 0, 40), 'sections' => array_map(fn($x) => $x + (isset($icons[$x['name']]) ? ['icon' => $icons[$x['name']]] : []), $pairs($lb['sections'] ?? '', true)),
            'tag_section' => trim((string) ($lb['tag_tag'] ?? '')) !== '' ? ['icon' => (string) ($oldLib['tag_section']['icon'] ?? ''), 'tag' => preg_replace('/[^a-z0-9_]/', '', strtolower((string) $lb['tag_tag'])), 'name' => mb_substr(trim((string) ($lb['tag_name'] ?? '')), 0, 40), 'unless' => trim((string) ($lb['tag_unless'] ?? ''))] : null,
            'other_section' => mb_substr(trim((string) ($lb['other_section'] ?? '')), 0, 40), 'audio_section' => mb_substr(trim((string) ($lb['audio_section'] ?? '')), 0, 40),
            'voice_title' => mb_substr(trim((string) ($lb['voice_title'] ?? '')), 0, 60), 'audio_title' => mb_substr(trim((string) ($lb['audio_title'] ?? '')), 0, 60), 'audio_tag' => preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($lb['audio_tag'] ?? '')))];
        $libPats = array_merge(array_column($out['library']['topics'], 'pattern'), array_column($out['library']['sections'], 'pattern'), array_filter([$out['library']['tag_section']['unless'] ?? '']));
        foreach (array_merge($out['secret_patterns'], $libPats, ...array_map(fn($t) => $t['patterns'], $out['tags'] ?: [['patterns' => []]])) as $p) if (!$ok($p)) wi_json(['ok' => false, 'error' => 'This pattern is not valid: ' . mb_substr($p, 0, 80)]);
        // payment bot: words that approve / reject a held receipt in the owner's own chat, and the money words for amounts
        $out['payment_commands'] = array_slice(array_values(array_filter(array_map(fn($w) => mb_substr(trim($w), 0, 30), preg_split('/[,،\n]+/u', (string) ($in['payment_commands'] ?? ''))))), 0, 30);
        $out['money_words'] = array_slice(array_values(array_filter(array_map(fn($w) => mb_substr(trim($w), 0, 20), preg_split('/[,،\n]+/u', (string) ($in['money_words'] ?? ''))))), 0, 40);
        // Media downloaded automatically and the size limit of each kind (MB)
        $md = (array) ($in['media'] ?? []); $kinds = ['image', 'sticker', 'audio', 'video', 'document'];
        $out['media'] = ['auto' => array_values(array_intersect($kinds, (array) ($md['auto'] ?? []))), 'max_mb' => []];
        foreach ($kinds as $k) $out['media']['max_mb'][$k] = max(1, min(500, (int) ($md['max_mb'][$k] ?? 0) ?: ['image' => 8, 'sticker' => 8, 'audio' => 64, 'video' => 64, 'document' => 32][$k]));
        if (in_array('audio', $out['media']['auto'], true)) $out['media']['auto'][] = 'voice';
        wi_write('gw_rules.json', $out); // in the database; the file below is the copy the gateway reads
        if (@file_put_contents($f, json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false) wi_json(['ok' => false, 'error' => 'Could not write the rules file.']);
        wi_log('gw_rules_save', ['tags' => count($out['tags'])]); wi_json(['ok' => true]); }
    $r = wi_read('gw_rules.json', []) ?: (json_decode((string) @file_get_contents($f), true) ?: []);
    wi_json(['ok' => true, 'rules' => ['secret_patterns' => $r['secret_patterns'] ?? [], 'strip_link_domains' => $r['strip_link_domains'] ?? [], 'tags' => $r['tags'] ?? [], 'library' => $r['library'] ?? new stdClass(), 'payment_commands' => $r['payment_commands'] ?? ['موافق', 'موافقة', 'اعتماد', 'approve', 'ok', 'رفض', 'reject'], 'money_words' => $r['money_words'] ?? ['EGP', 'LE', 'L.E', 'جنيه', 'ج.م', 'ج', 'ريال', 'SAR', '$', 'USD', 'دولار'],
        'media' => $r['media'] ?? ['auto' => ['image', 'sticker', 'audio', 'voice', 'video', 'document'], 'max_mb' => ['image' => 8, 'sticker' => 8, 'audio' => 64, 'video' => 64, 'document' => 32]]]]);
}
/* ---------- Settings → Menu: rename sections and pages, change icons, hide pages from the menu ---------- */
function wf_api_menu_cfg(string $api): void {
    if ($api !== 'menu_cfg') return;
    if (!wi_is_super()) wi_json(['ok' => false, 'error' => 'Super admin only.'], 403);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') { $in = wf_post(); $c = wi_read('config.json', []);
        if (!empty($in['reset'])) unset($c['menu']);
        else { $m = ['sections' => [], 'items' => []];
            foreach ((array) ($in['sections'] ?? []) as $id => $t) if (trim((string) $t) !== '') $m['sections'][preg_replace('/\W/', '', (string) $id)] = mb_substr(trim((string) $t), 0, 40);
            foreach ((array) ($in['items'] ?? []) as $k => $o) { $k = preg_replace('/\W/', '', (string) $k); if ($k === 'settings' && !empty($o['hidden'])) $o['hidden'] = 0; // Settings can never be hidden
                $m['items'][$k] = ['label' => mb_substr(trim((string) ($o['label'] ?? '')), 0, 40), 'icon' => mb_substr(trim((string) ($o['icon'] ?? '')), 0, 8), 'hidden' => !empty($o['hidden'])]; }
            $c['menu'] = $m; }
        wi_write('config.json', $c); wi_log('menu_save', []); wi_json(['ok' => true]); }
    wi_json(['ok' => true, 'menu' => wi_menu()]);
}
