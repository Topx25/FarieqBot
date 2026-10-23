<?php
// WhatsApp Inbox — Business settings (?p=business): the type of business, its names and the words of the interface, per number.
// Everything a client changes here lives in bot.json[aid]['pack'] (business profile) and case_cfg.json[aid] (records).

/** Ready business types shown as cards: profile preset → records preset. */
function wf_business_types(): array {
    return ['crm' => ['icon' => '🤝', 'case' => 'crm', 'desc' => 'Customers, leads and deals: sales and customer service.'],
        'support' => ['icon' => '🛠️', 'case' => 'support', 'desc' => 'IT and technical support: tickets from new to solved.'],
        'general' => ['icon' => '🗂️', 'case' => 'general', 'desc' => 'Any business: general follow-up of the people who write to you.'],
        'ruqyah' => ['icon' => '🤲', 'case' => 'treatment', 'desc' => 'Healing follow-up: cases, symptoms and programs.']];
}
/** The spiritual (ruqyah) type is never offered to clients: only the super admin has it, and gives it to a client by hand (Clients → Edit plan). */
function wf_type_allowed(string $type, string $aid): bool {
    if ($type !== 'ruqyah') return true; if (wi_is_super()) return true;
    $o = wi_private_owner(wi_accounts()[$aid] ?? []); $c = $o !== '' ? wi_client_of(wi_find_user($o) ?: []) : null; return $c && !empty($c['allow_ruqyah']);
}
/** "Interface text => your word" lines ↔ rows. */
function wf_terms_rows(string $s): array { $o = []; foreach (preg_split('/\R/u', $s) as $l) { $x = array_map('trim', explode('=>', $l, 2)); if (count($x) === 2 && $x[0] !== '' && $x[1] !== '') $o[] = $x; } return $o; }

function wf_api_business(string $api): void {
    if ($api !== 'business_cfg') return;
    $acc = wi_current(); if (!$acc) wi_json(['ok' => false, 'error' => 'No number.']); $aid = (string) $acc['id'];
    $base = (array) require dirname(__DIR__) . '/i18n.php'; // the plain dictionary (without this number's words)
    // Another business never sees the healing wording of the plain dictionary: its words are shown as they read on its own panel.
    $heal = (string) (wf_pack($aid)['preset'] ?? '') === 'ruqyah'; $mine = []; foreach (wf_terms_rows((string) (wf_pack($aid)['ui_terms'] ?? '')) as $r) $mine[$r[0]] = $r[1];
    if (!$heal) foreach ($base as $en => $ar) if (isset($mine[$en])) $base[$en] = $mine[$en];
    if (isset($_GET['q'])) { // search the interface words to rename (Arabic or English)
        $q = mb_strtolower(trim((string) $_GET['q'])); $o = [];
        if (mb_strlen($q) >= 2) foreach ($base as $en => $ar) { if (strpos($en, '<') !== false || mb_strlen($en) > 90) continue;
            if (mb_strpos(mb_strtolower($ar), $q) !== false || mb_strpos(mb_strtolower($en), $q) !== false) { $o[] = ['from' => $en, 'ar' => $ar]; if (count($o) >= 40) break; } }
        wi_json(['ok' => true, 'items' => $o]); }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') { $in = wf_post(); wf_admin(); $do = (string) ($in['do'] ?? ''); $T = wf_business_types();
        if ($do === 'type') { $pre = isset($T[$in['type'] ?? '']) ? (string) $in['type'] : 'crm';
            if (!wf_type_allowed($pre, $aid)) wi_json(['ok' => false, 'error' => 'This type of business is not available for this panel.'], 403);
            wi_update('bot.json', function ($B) use ($aid, $pre) { $B[$aid]['pack'] = ['preset' => $pre]; return $B; }); // back to the ready values of that type
            wi_update('case_cfg.json', function ($C) use ($aid, $T, $pre) { $C[$aid] = ['preset' => $T[$pre]['case']]; return $C; });
            wf_acc_rules_write($aid, $pre); // library and content rules follow the type (restart the number's gateway to apply)
            wi_log('business_type', ['account' => $aid, 'type' => $pre]); wi_json(['ok' => true]); }
        if ($do === 'names') { $pk = []; foreach (['industry' => 300, 'client' => 40, 'staff' => 60, 'program_label' => 40] as $k => $max) if (isset($in[$k])) $pk[$k] = mb_substr(trim((string) $in[$k]), 0, $max);
            wi_update('bot.json', function ($B) use ($aid, $pk) { $B[$aid]['pack'] = wf_pack_own((string) wf_pack($aid)['preset'], array_merge((array) ($B[$aid]['pack'] ?? []), ['preset' => wf_pack($aid)['preset']], array_filter($pk, fn($v) => $v !== ''))); return $B; });
            $cc = []; foreach (['one' => 40, 'many' => 40, 'icon' => 8] as $k => $max) if (trim((string) ($in[$k] ?? '')) !== '') $cc[$k] = mb_substr(trim((string) $in[$k]), 0, $max);
            wi_update('case_cfg.json', function ($C) use ($aid, $cc) { $C[$aid] = array_merge(['preset' => wf_case_cfg($aid)['preset']], (array) ($C[$aid] ?? []), $cc); return $C; });
            wi_log('business_names', ['account' => $aid]); wi_json(['ok' => true]); }
        if ($do === 'terms') { $lines = [];
            foreach ((array) ($in['terms'] ?? []) as $r) { $f = trim((string) ($r[0] ?? '')); $w = trim(str_replace(["\n", '=>'], ' ', (string) ($r[1] ?? ''))); if ($f !== '' && $w !== '' && strpos($f, '=>') === false) $lines[$f] = mb_substr($f, 0, 300) . ' => ' . mb_substr($w, 0, 300); }
            wi_update('bot.json', function ($B) use ($aid, $lines) { $B[$aid]['pack'] = array_merge(wf_pack($aid), (array) ($B[$aid]['pack'] ?? []), ['ui_terms' => implode("\n", array_slice(array_values($lines), 0, 400))]); return $B; });
            wi_log('business_terms', ['account' => $aid, 'count' => count($lines)]); wi_json(['ok' => true]); }
        // Library sections of this number (its own rules file only — the shared file is the super admin's, Settings → Gateway rules)
        if ($do === 'library') { $f = wf_acc_rules_path($aid); if (!is_file($f)) wi_json(['ok' => false, 'error' => 'This number uses the shared library. Choose a type of business first.']);
            $R = json_decode((string) file_get_contents($f), true) ?: []; $S = [];
            foreach ((array) ($in['sections'] ?? []) as $x) { $n = mb_substr(trim((string) ($x['name'] ?? '')), 0, 40); $w = array_values(array_filter(array_map(fn($v) => preg_quote(trim($v), '/'), preg_split('/[,،\n]+/u', (string) ($x['words'] ?? '')))));
                if ($n === '' || !$w) continue; $S[] = ['name' => $n, 'pattern' => implode('|', array_slice($w, 0, 60)), 'body' => true, 'icon' => mb_substr(trim((string) ($x['icon'] ?? '')), 0, 4) ?: '📁']; }
            $R['library'] = array_merge((array) ($R['library'] ?? []), ['sections' => array_slice($S, 0, 30)]);
            file_put_contents($f, json_encode($R, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)); wi_log('business_library', ['account' => $aid, 'sections' => count($S)]); wi_json(['ok' => true]); }
        wi_json(['ok' => false, 'error' => 'Unknown action.']); }
    $p = wf_pack($aid); $c = wf_case_cfg($aid); $P = wf_pack_presets(); $types = [];
    foreach (wf_business_types() as $k => $t) if (wf_type_allowed($k, $aid) || $p['preset'] === $k) $types[] = ['key' => $k, 'icon' => $t['icon'], 'label' => (string) ($P[$k]['label'] ?? $k), 'desc' => $t['desc']];
    wi_json(['ok' => true, 'number' => (string) ($acc['name'] ?? $aid), 'type' => (string) $p['preset'], 'types' => $types,
        'names' => ['industry' => (string) ($p['industry'] ?? ''), 'client' => (string) ($p['client'] ?? ''), 'staff' => (string) ($p['staff'] ?? ''), 'program_label' => (string) ($p['program_label'] ?? ''), 'one' => (string) $c['one'], 'many' => (string) $c['many'], 'icon' => (string) $c['icon']],
        'terms' => array_map(fn($r) => ['from' => $r[0], 'to' => $r[1], 'ar' => $heal ? (string) ($base[$r[0]] ?? $r[0]) : ''], wf_terms_rows((string) ($p['ui_terms'] ?? ''))),
        'library' => is_file(wf_acc_rules_path($aid)) ? array_map(fn($s) => ['name' => (string) $s['name'], 'icon' => (string) ($s['icon'] ?? ''), 'words' => implode('، ', array_map(fn($v) => stripslashes($v), explode('|', (string) $s['pattern'])))], (array) ((json_decode((string) file_get_contents(wf_acc_rules_path($aid)), true) ?: [])['library']['sections'] ?? [])) : null,
        'can_ai' => wi_page_perm('learning') === 'edit', 'can_cases' => wi_page_perm('cases') === 'edit']);
}
