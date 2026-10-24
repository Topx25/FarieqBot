<?php
// WhatsApp Inbox — registry (split out of features.php in v3.8, code unchanged)

/* ---------- Case registry groups: each post (name, number, voice note, diagnosis) becomes one entry ---------- */
function wf_registry_groups(string $aid): array { return wi_read('registry_groups.json', [])[$aid] ?? []; }
/** Phone numbers written in a text (Arabic digits too). Local numbers get the country code from Settings → System. */
function wf_phones_in(string $t): array {
    $t = strtr($t, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
    preg_match_all('/(?<![\d@])(?:\+|00)?\d[\d \-\.]{8,17}\d(?![\d])/', $t, $m); // @123… is a mention (WhatsApp ID), not a phone
    $out = [];
    foreach ($m[0] as $x) {
        $d = preg_replace('/\D/', '', $x);
        if (str_starts_with($d, '00')) $d = substr($d, 2);
        // A local number (written without the country code) gets the country code from Settings → System.
        $cc = (string) wi_sys('country_code'); $tp = (string) wi_sys('trunk_prefix');
        if ($cc !== '' && !str_starts_with($x, '+') && !str_starts_with($d, $cc)) { if ($tp !== '' && str_starts_with($d, $tp) && strlen($d) >= 9 && strlen($d) <= 11) $d = $cc . substr($d, strlen($tp)); elseif (strlen($d) >= 8 && strlen($d) <= 10) $d = $cc . $d; }
        if (strlen($d) >= 10 && strlen($d) <= 15) $out[] = $d;
    }
    return array_values(array_unique($out));
}
/**
 * Splits a registry group into entries: a post with a phone number starts an entry; the same poster's
 * voice notes / photos / extra lines within 20 minutes belong to it (also a voice note sent just before).
 */
function wf_registry_scan(array $acc): array {
    $aid = (string) $acc['id'];
    $all = wi_read('registry.json', []); $old = $all[$aid] ?? [];
    $entries = [];
    foreach (wf_registry_groups($aid) as $gjid => $g) {
        $rows = array_values(array_filter(wf_group_rows($acc, $gjid), fn($m) => in_array($m['dir'] ?? '', ['in', 'out'], true)));
        $cur = []; $orphans = [];
        foreach ($rows as $m) {
            $poster = ($m['dir'] === 'out') ? 'me' : wf_phone($m['from'] ?? '');
            $t = strtotime((string) $m['time']);
            $item = ['id' => $m['id'] ?? '', 'kind' => $m['kind'] ?? 'text', 'time' => $m['time'], 'text' => (string) ($m['text'] ?? ''), 'attachment' => $m['attachment'] ?? '', 'fetchable' => empty($m['attachment']) && !empty($m['mref'])];
            $phones = wf_phones_in((string) ($m['text'] ?? ''));
            if ($phones) {
                $id = $item['id'] ?: md5($gjid . $m['time']);
                $prev = array_values(array_filter($orphans[$poster] ?? [], fn($o) => $t - strtotime($o['time']) <= 600));
                $entries[$id] = ['id' => $id, 'group' => $gjid, 'group_name' => $g['name'] ?? '', 'poster' => $poster, 'poster_name' => $m['from_name'] ?? '', 'time' => $m['time'], 'phones' => $phones, 'items' => array_merge($prev, [$item])];
                $orphans[$poster] = []; $cur[$poster] = ['id' => $id, 't' => $t];
                continue;
            }
            if (isset($cur[$poster]) && $t - $cur[$poster]['t'] <= 1200) { $entries[$cur[$poster]['id']]['items'][] = $item; $cur[$poster]['t'] = $t; continue; }
            $orphans[$poster][] = $item;
        }
    }
    foreach ($entries as $id => &$e) {
        $o = $old[$id] ?? [];
        $e['text'] = trim(implode("\n", array_filter(array_map(fn($i) => $i['text'], $e['items']))));
        $e['voice'] = count(array_filter($e['items'], fn($i) => in_array($i['kind'], ['voice', 'audio'], true)));
        static $nl = null; $nl ??= wf_seed_alt('case_name_labels');
        if (preg_match('/(?:' . $nl . ')\s*[:：\-]?\s*([^\n\d]{2,40})/u', $e['text'], $mm)) $e['name_guess'] = trim($mm[1]);
        foreach (['labels', 'ai', 'followed', 'case', 'note'] as $k) if (isset($o[$k])) $e[$k] = $o[$k];
        $e['labels'] = $e['labels'] ?? [];
    }
    unset($e);
    uasort($entries, fn($a, $b) => strcmp((string) $b['time'], (string) $a['time']));
    $all[$aid] = $entries; wi_write('registry.json', $all);
    return $entries;
}
/** Follow a registry entry: a case keyed by the patient's phone, filled from the entry. */
function wf_registry_follow(array $acc, array $e): ?string {
    $aid = (string) $acc['id'];
    $ph = $e['phones'][0] ?? ''; if ($ph === '') return null;
    wf_case_update($aid, $ph, function (?array $c) use ($e, $ph) { // on the stored case, under the lock
        $c = $c ?? ['phone' => $ph, 'status' => 'follow', 'created' => date('c'), 'notes' => [], 'data' => []];
        $ai = $e['ai'] ?? [];
        $c['name'] = $c['name'] ?? ($ai['name'] ?? ($e['name_guess'] ?? ''));
        foreach (['complaint' => 'complaint', 'diagnosis' => 'diagnosis', 'gender' => 'gender', 'age' => 'age', 'country' => 'country'] as $k => $src) if (empty($c['data'][$k]) && !empty($ai[$src])) $c['data'][$k] = mb_substr((string) $ai[$src], 0, 1000);
        if (empty($c['data']['symptoms']) && !empty($ai['symptoms'])) $c['data']['symptoms'] = implode('، ', (array) $ai['symptoms']);
        $c['registry'] = array_values(array_unique(array_merge((array) ($c['registry'] ?? []), [$e['id']])));
        $c['labels'] = array_values(array_unique(array_merge((array) ($c['labels'] ?? []), (array) ($e['labels'] ?? []))));
        $c['tracked'] = true; if (($c['status'] ?? 'new') === 'new') $c['status'] = 'follow';
        foreach (array_slice($e['phones'], 1) as $alt) $c['lids'] = array_values(array_unique(array_merge((array) ($c['lids'] ?? []), [$alt])));
        return $c; });
    return $ph;
}
