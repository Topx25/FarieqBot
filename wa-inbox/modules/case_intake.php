<?php
/* ---------- A case opened from a group with a keyword ("#حالة" + the details) ----------
 * Someone in a group writes the keyword with the person's details. The AI reads them into this number's case fields
 * (Cases → Case settings); what is missing and marked "ask" is asked to the writer in the group (quoting them).
 * Their next messages in that group, within the wait time, complete it. Completed or not when the time is up,
 * the case is saved (status "being worked on") and its report is posted to the diagnosis group.
 * Everything here — keyword, groups, wait, which fields are asked, the wording — is a per-number setting or a template. */

function wf_intake_cfg(string $aid): array {
    $own = (array) (wi_read('case_intake_cfg.json', [])[$aid] ?? []);
    return $own + ['on' => 1, 'keyword' => '#' . str_replace(' ', '_', (string) wf_case_cfg($aid)['one']), // "#" + what one case is called on this number
         'wait_min' => 30, 'groups' => [], 'diag' => '', 'ask' => ['name', 'phone', 'age', 'country', 'story', 'symptoms', 'voice'], 'confirm' => 1, 'mention' => 0]; // mention 1: only when this number is tagged; 0: the keyword alone is enough
}
/** The diagnosis group: the one chosen, else the first group of the case registry. */
function wf_intake_diag(string $aid): string { $c = wf_intake_cfg($aid); return $c['diag'] !== '' ? $c['diag'] : (string) array_key_first(wf_registry_groups($aid) ?: [] ) ; }
/** Fields the AI fills: the switched-on fields of the case form (the date and the follower are filled by the system). */
function wf_intake_fields(string $aid): array {
    $F = wf_case_cfg($aid)['fields']; $out = [];
    foreach ($F as $k => $f) if ((!empty($f['on']) || in_array($k, ['name', 'phone'], true)) && !in_array($k, ['join', 'diag_by'], true)) $out[$k] = (string) $f['label'];
    return $out;
}
/** What may be asked: the form fields and the case's voice recording. */
function wf_intake_askable(string $aid): array { return wf_intake_fields($aid) + ['voice' => (string) (wf_case_cfg($aid)['words']['voice'] ?? wi_t('Voice recording of the case'))]; } // the group's wording, not the dashboard language
/** Spelling that people mix up (ة/ه, أ/إ/آ/ا, ى/ي, _ and spaces) does not change a keyword: "#حاله" is "#حالة". */
function wf_intake_norm(string $s): string { return mb_strtolower(str_replace(["\u{0629}", "\u{0623}", "\u{0625}", "\u{0622}", "\u{0649}", '_', "\u{0640}"], ["\u{0647}", "\u{0627}", "\u{0627}", "\u{0627}", "\u{064A}", ' ', ''], $s)); }
function wf_intake_has(string $text, string $kw): bool { $kw = trim($kw); return $kw !== '' && mb_strpos(wf_intake_norm($text), wf_intake_norm($kw)) !== false; }
function wf_intake_has_kw(string $aid, string $text): bool { return wf_intake_has($text, (string) wf_intake_cfg($aid)['keyword']); }
/** A new case needs someone to open it for: a tagged person or a phone number (an explanation that only mentions the keyword opens nothing). */
function wf_intake_has_target(array $in, string $text): bool { return !empty($in['mentions']) || (bool) preg_match('/(?:\+|00)?\d[\d\s\-]{7,16}\d/', $text); }

/** The AI reads all that the writer sent so far into the case fields. */
function wf_intake_extract(string $aid, string $text): array {
    $F = wf_intake_fields($aid); $list = implode("\n", array_map(fn($k, $l) => '- ' . $k . ': ' . $l, array_keys($F), $F));
    $ai = wi_ai('Case from a group', wf_prompt('case_intake', $aid, ['fields' => $list, 'text' => mb_substr($text, 0, 8000)]), 150, ['subject' => mb_substr($text, 0, 60)]);
    $d = (array) (($ai['data'] ?? [])['fields'] ?? []); $out = [];
    foreach ($F as $k => $l) { $v = $d[$k] ?? ''; if (is_array($v)) $v = implode("\n", array_map('strval', $v)); $v = trim(mb_substr((string) $v, 0, $k === 'story' || $k === 'symptoms' ? 4000 : 300)); if ($v !== '') $out[$k] = $v; }
    return ['ok' => !empty($ai['ok']), 'fields' => $out, 'error' => (string) ($ai['error'] ?? '')];
}
/** Who follows the case: the follower named in the details ("المتابع: …"), else the team member who wrote it —
 *  never this number itself (when the team writes from the bot's own phone, the follower is asked for). */
function wf_intake_follower(array $acc, array $pend, array $fields): string {
    $f = trim((string) ($fields['follower'] ?? '')); if ($f !== '') return mb_substr($f, 0, 60);
    $from = (string) ($pend['from'] ?? '');
    if ($from === '' || !empty($pend['self'])) return ''; // written from this number's own phone
    return trim((string) ($pend['name'] ?? '')) ?: '+' . $from;
}
function wf_intake_missing(string $aid, array $fields, string $phone, array $voices = [], ?string $follower = null): array {
    $F = wf_intake_askable($aid); $miss = [];
    foreach ((array) wf_intake_cfg($aid)['ask'] as $k) { if (!isset($F[$k])) continue; if ($k === 'voice' ? !$voices : ($k === 'phone' ? $phone === '' : trim((string) ($fields[$k] ?? '')) === '')) $miss[$k] = $F[$k]; }
    if ($follower === '' && isset($F['follower'])) $miss['follower'] = $F['follower']; // the follower is not known: ask who follows
    return $miss;
}
/** The person's number: written in the details, else the first person tagged (not the writer, not this number). */
function wf_intake_phone(array $fields, array $mentions, string $from, $acc): string {
    $p = wi_norm_phone((string) ($fields['phone'] ?? '')) ?: '';
    if ($p === '' && preg_match('/(?:\+|00)?\d[\d\s\-]{7,16}\d/', (string) ($fields['phone'] ?? ''), $m)) $p = preg_replace('/\D/', '', $m[0]);
    if ($p === '') { $self = preg_replace('/\D/', '', (string) ($acc['phone'] ?? ''));
        foreach ($mentions as $j) { $d = preg_replace('/\D/', '', explode('@', (string) $j)[0]); if (str_ends_with((string) $j, '@s.whatsapp.net') && $d !== '' && $d !== $from && $d !== $self) { $p = $d; break; } } }
    return strlen($p) >= 8 ? $p : '';
}

/** What is already known about the person's number: their case file, else their WhatsApp name. Only fills empty fields. */
function wf_intake_known(string $aid, string $phone, array $people): array {
    if ($phone === '') return [];
    $F = wf_intake_fields($aid); $out = [];
    $case = wf_cases($aid)[$phone] ?? null;
    if ($case) { if (trim((string) ($case['name'] ?? '')) !== '') $out['name'] = (string) $case['name'];
        foreach ((array) ($case['data'] ?? []) as $k => $v) if (isset($F[$k]) && !isset($out[$k])) { $v = is_array($v) ? implode("\n", array_map('strval', $v)) : trim((string) $v); if ($v !== '') $out[$k] = $v; } }
    if (!isset($out['name'])) foreach ($people as $pj => $nm) if (explode('@', (string) $pj)[0] === $phone && $nm !== '') { $out['name'] = $nm; break; }
    return $out;
}
/* ---------- Follow-up commands in a group ----------
 * "#الحالة <name | number | tag>" posts the case report; "#اتحسنت <name | number | tag> <reason>" (one command per stage,
 * Case settings presets → cmds, else "#" + the stage name) moves the case to that stage with the reason as a note. */
function wf_intake_cmds(string $aid): array {
    $cc = wf_case_cfg($aid); $out = [];
    foreach ((array) $cc['statuses'] as $k => $lbl) { if ($k === 'new') continue;
        $words = trim(preg_replace('/[^\p{L}\p{N}\s_]+/u', '', (string) $lbl)); if ($words !== '') $out['#' . preg_replace('/\s+/u', '_', $words)] = $k;
        if (!empty($cc['cmds'][$k])) $out[(string) $cc['cmds'][$k]] = $k; }
    if (!empty($cc['words']['archive']) && isset($cc['statuses']['archived'])) $out[(string) $cc['words']['archive']] = 'archived'; // every preset
    uksort($out, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a)); // longest first
    return $out;
}
function wf_intake_lookup_kw(string $aid): string { return (string) (wf_intake_cfg($aid)['lookup'] ?? ('#' . (wf_case_cfg($aid)['words']['the'] ?? '') . str_replace(' ', '_', (string) wf_case_cfg($aid)['one']))); }
/** Cases matching a tag, a number (full or its end), a case key, or part of the name. */
function wf_intake_find(string $aid, string $q, array $mentions, $acc, bool $inGroup = false): array {
    $all = wf_cases($aid); if ($inGroup) $all = array_filter($all, fn($c) => is_array($c) && !wf_case_is_private($c)); $self = preg_replace('/\D/', '', (string) ($acc['phone'] ?? ''));
    foreach ($mentions as $j) { $d = preg_replace('/\D/', '', explode('@', (string) $j)[0]); if ($d !== '' && $d !== $self && isset($all[$d])) return [$d => $all[$d]]; }
    $q = trim(preg_replace('/@\d+/', '', $q)); if ($q === '') return [];
    if (isset($all[$q])) return [$q => $all[$q]];
    if (preg_match('/^(?:id|ID)?\s*#?(\d{1,5})$/u', $q, $m)) { foreach ($all as $k => $c) if ((int) ($c['no'] ?? 0) === (int) $m[1]) return [$k => $c]; return []; } // the case ID
    $dg = preg_replace('/\D/', '', $q); $hit = [];
    if (strlen($dg) >= 6) { foreach ($all as $k => $c) if (str_ends_with((string) $k, ltrim($dg, '0')) || str_ends_with(preg_replace('/\D/', '', (string) ($c['phone'] ?? '')), ltrim($dg, '0'))) $hit[$k] = $c; if ($hit) return $hit; }
    $n = mb_strtolower($q);
    $word = fn(string $hay, string $w) => (bool) preg_match('/(^|\s)' . preg_quote($w, '/') . '(\s|$)/u', $hay); // whole words only: "Ali" is not "Alia"
    foreach ($all as $k => $c) { $nm = mb_strtolower(trim((string) ($c['name'] ?? ''))); if ($nm === '') continue;
        if ($nm === $n || (mb_strlen($n) >= 2 && $word($nm, $n)) || (mb_strlen($n) >= 3 && str_starts_with($nm, $n)) || (mb_strlen($nm) >= 3 && $word($n, $nm))) $hit[$k] = $c; }
    $exact = array_filter($hit, fn($c) => mb_strtolower(trim((string) ($c['name'] ?? ''))) === $n); if (count($exact) === 1) return $exact;
    return $hit;
}
/** "#قائمة" + what: the cases by stage, the shortcuts, or the programs — posted in the group. */
/** The text of a list command ("#قائمة" + what), or null when it is not one. Used in groups (sent) and from the bot's phone (the typed message becomes the list). */
function wf_intake_list_text(array $acc, string $text, array $L): ?string {
    $out = null; $GLOBALS['WF_LIST_ONLY'] = true; ob_start();
    try { $ok = wf_intake_list($acc, '', $text, '', $L); } finally { ob_end_clean(); unset($GLOBALS['WF_LIST_ONLY']); }
    return $ok ? (string) ($GLOBALS['WF_LIST_OUT'] ?? '') : null;
}
function wf_intake_list(array $acc, string $jid, string $text, string $id, array $L, string $from = ''): bool {
    $aid = (string) $acc['id']; $cc = wf_case_cfg($aid); $out = '';
    if (!empty($L['library']) && wf_intake_has($text, (string) $L['library']) && function_exists('wf_lib_inventory_text')) { $out = wf_lib_inventory_text($acc); $what = 'library'; } // the library's inventory
    elseif (wf_intake_has($text, (string) $L['shortcuts'])) {
        $rows = array_map(fn($r) => '• ' . (trim((string) ($r['keyword'] ?? '')) !== '' ? '#' . ltrim(trim((string) $r['keyword']), '#/') . ' — ' : '') . (string) ($r['title'] ?? ''), wi_replies_for($acc));
        $out = wf_msg('list_shortcuts', ['count' => (string) count($rows), 'list' => implode("\n", array_slice($rows, 0, 80))]); $what = 'shortcuts';
    } elseif (wf_intake_has($text, (string) $L['programs'])) {
        $rows = array_map(fn($p) => '• ' . (string) ($p['name'] ?? '') . ' (' . count((array) ($p['days'] ?? [])) . ')', wf_programs($aid));
        $out = wf_msg('list_programs', ['count' => (string) count($rows), 'list' => implode("\n", array_slice($rows, 0, 80))]); $what = 'programs';
    } elseif (wf_intake_has($text, wf_intake_lookup_kw($aid)) || wf_intake_has($text, '#' . (string) $cc['many']) || wf_intake_has($text, '#' . (string) $cc['one'])) {
        $all = array_filter(wf_cases($aid), fn($c) => is_array($c) && !wf_case_is_private($c)); $by = []; // group lists never show private-chat cases
        foreach ($all as $k => $c) if (($c['status'] ?? '') !== 'archived') $by[(string) ($c['status'] ?? 'new')][] = '• ' . ($c['no'] ?? '') . ' · ' . (trim((string) ($c['name'] ?? '')) ?: '+' . $k) . ' — ' . $k;
        $parts = []; foreach ((array) $cc['statuses'] as $k => $lbl) if (!empty($by[$k])) $parts[] = $lbl . ' (' . count($by[$k]) . ")\n" . implode("\n", array_slice($by[$k], 0, 40));
        $out = wf_msg('list_cases', ['case' => (string) $cc['many'], 'count' => (string) count($all), 'list' => implode("\n\n", $parts)]); $what = 'cases';
    } else return false;
    if (!empty($GLOBALS['WF_LIST_ONLY'])) { $GLOBALS['WF_LIST_OUT'] = mb_substr($out, 0, 3900); return true; } // only the text was asked for
    [$tagT, $tagM] = wf_group_tag($from);
    wi_gateway('/chat/send', ['jid' => $jid, 'text' => $tagT . mb_substr($out, 0, 3900), 'quote_id' => $id, 'mentions' => $tagM, 'via' => 'bot'], $acc);
    wi_log('case_list_cmd', ['account' => $aid, 'group' => $jid, 'what' => $what]);
    return true;
}
/** Is this text a group command (list, lookup or a stage)? Cheap: no lookups. */
function wf_intake_is_cmd(string $aid, string $text): bool {
    if (mb_strpos($text, '#') === false) return false;
    $w = (array) (wf_case_cfg($aid)['words'] ?? []);
    if (!empty($w['list']) && wf_intake_has($text, (string) $w['list'])) return true; // "#قائمة #حالة" is a list, not a new case
    if (wf_intake_has($text, wf_intake_lookup_kw($aid))) return true;
    foreach (array_keys(wf_intake_cmds($aid)) as $c) if (wf_intake_has($text, $c)) return true;
    return false;
}
/** Handles a follow-up command; true when the message was one. */
function wf_intake_command(array $acc, array $in, string $jid, string $from, string $text, string $id): bool {
    $aid = (string) $acc['id']; $one = (string) wf_case_cfg($aid)['one']; $st = wf_case_statuses($aid);
    $L = (array) (wf_intake_cfg($aid)['lists'] ?? []) + (array) (wf_case_cfg($aid)['words'] ?? []) + ['list' => '', 'shortcuts' => '', 'programs' => ''];
    if ($L['list'] !== '' && wf_intake_has($text, (string) $L['list'])) return wf_intake_list($acc, $jid, $text, $id, $L, $from); // "#قائمة #الحالة" before "#الحالة"
    $kw = wf_intake_lookup_kw($aid); $status = null; $cmd = '';
    foreach (wf_intake_cmds($aid) as $c => $k) if (wf_intake_has($text, $c)) { $cmd = $c; $status = $k; break; } // a stage (#اتحسنت…) wins: "#الحالة … #اتحسنت" changes it
    if ($cmd === '' && wf_intake_has($text, $kw)) $cmd = $kw;
    if ($cmd === '') return false;
    $rest = trim(preg_replace('/#\S+/u', '', $text)); // the command words (#…) are not the person
    $mentions = array_map('strval', (array) ($in['mentions'] ?? []));
    [$tagT, $tagM] = wf_group_tag($from); $say = fn(string $t) => wi_gateway('/chat/send', ['jid' => $jid, 'text' => $tagT . $t, 'quote_id' => $id, 'mentions' => $tagM, 'via' => 'bot'], $acc);
    // the person: first line (or the words before the reason) — a tag, a number or a name
    $lines = preg_split('/\R/u', $rest); $ref = trim((string) array_shift($lines)); $reason = trim(implode("\n", $lines));
    if (preg_match('/(^|\R)\s*#[^\s:\x{061B}]+\s*[:\x{061B}]/u', $text)) { // a filled form: "#case: name", "#mobile: +212…", "#reason: …" — only the labelled values (a title line or a lone #stage is skipped)
        $vals = []; foreach (preg_split('/\R/u', $text) as $ln) if (preg_match('/^\s*#?[^:\x{061B}\n]{1,30}[:\x{061B}]\s*(.+)$/u', $ln, $mm) && ($x = trim(preg_replace('/#\S+/u', '', $mm[1]))) !== '') $vals[] = $x;
        $ph = ''; foreach ($vals as $i => $x) if (preg_match('/^(?:\+|00)?\d[\d\s-]{7,16}\d$/', $x)) { $ph = $x; unset($vals[$i]); break; }
        // the number may sit on a line without a label ("#الحالة +212…" then "#السبب: …"): it is still the person
        if ($ph === '' && preg_match('/(?:\+|00)?\d[\d\s-]{7,16}\d/', $text, $pm)) $ph = trim($pm[0]);
        $vals = array_values($vals); $ref = $ph !== '' ? $ph : (string) array_shift($vals); if ($ph !== '' && count($vals) > 1) array_shift($vals); // with a number, the name line is not the reason
        $reason = trim(implode("\n", $vals));
    }
    $hits = wf_intake_find($aid, $ref, $mentions, $acc, true);
    if (!$hits && $ref !== '') { $w = preg_split('/\s+/u', preg_replace('/@\d+/', '', $ref)); // "aamer reason words": try the first 1–3 words as the name
        for ($i = min(3, count($w)); $i >= 1 && !$hits; $i--) { $h = wf_intake_find($aid, implode(' ', array_slice($w, 0, $i)), [], $acc, true); if (count($h) === 1) { $hits = $h; $reason = trim(implode(' ', array_slice($w, $i)) . "\n" . $reason); } } }
    elseif ($hits && $mentions) $reason = trim(preg_replace('/@\d+/', '', $ref) . "\n" . $reason);
    // not found: a number anywhere in the message is tried, then close names are offered ("did you mean…") instead of a flat no
    if (!$hits && preg_match('/(?:\+|00)?\d[\d\s-]{7,16}\d/', $text, $pm) && ($h = wf_intake_find($aid, trim($pm[0]), [], $acc, true))) { if (count($h) === 1) { $hits = $h; $reason = trim($ref . "\n" . $reason); } else $hits = $h; }
    if (!$hits && $ref !== '') { $sug = []; $words = array_filter(preg_split('/\s+/u', mb_strtolower(preg_replace('/@\d+/', '', $ref))), fn($w) => mb_strlen($w) >= 3);
        foreach (wf_cases($aid) as $k => $c) { if (!is_array($c) || wf_case_is_private($c)) continue; $nm = mb_strtolower(trim((string) ($c['name'] ?? ''))); if ($nm === '') continue; $best = 0;
            foreach ($words as $w) foreach (preg_split('/\s+/u', $nm) as $nw) { similar_text($w, $nw, $pc); $best = max($best, $pc); }
            if ($best >= 70) $sug[$k] = [$best, $c]; }
        if ($sug) { uasort($sug, fn($a, $b) => $b[0] <=> $a[0]);
            $say(wf_msg('case_did_you_mean', ['case' => $one, 'query' => mb_substr($ref, 0, 40), 'list' => implode("\n", array_map(fn($k, $x) => '• ' . ($x[1]['name'] ?? '') . ' — ID ' . ($x[1]['no'] ?? '') . ' — ' . $k, array_keys(array_slice($sug, 0, 3, true)), array_slice($sug, 0, 3, true))), 'kw' => $kw]));
            wi_log('case_did_you_mean', ['account' => $aid, 'query' => mb_substr($ref, 0, 40)]); return true; } }
    if (!$hits) { $dj = wf_intake_diag($aid); $dn = ''; if ($dj !== '') foreach ((array) (wi_gateway('/groups', null, $acc)['groups'] ?? []) as $g) if ((string) ($g['id'] ?? $g['jid'] ?? '') === $dj) $dn = (string) ($g['subject'] ?? $g['name'] ?? '');
        $say(wf_msg('case_not_found', ['case' => $one, 'query' => mb_substr($ref, 0, 40), 'diag' => $dn])); return true; }
    if (count($hits) > 1) { $say(wf_msg('case_many', ['case' => $one, 'list' => implode("\n", array_map(fn($k, $c) => '• ' . ($c['name'] ?? '') . ' — ID ' . ($c['no'] ?? '') . ' — ' . $k, array_keys(array_slice($hits, 0, 8, true)), array_slice($hits, 0, 8, true)))])); return true; }
    $key = (string) array_key_first($hits); $case = $hits[$key]; $by = trim((string) ($in['name'] ?? '')) ?: '+' . $from;
    if (!$mentions && $ref !== '' && trim($reason) === '') { // "aamer the reason" on one line: the words that are not the name or the number
        $nm = mb_strtolower((string) ($case['name'] ?? ''));
        $reason = trim(implode(' ', array_filter(preg_split('/\s+/u', $ref), fn($w) => !preg_match('/^[+\d-]{6,}$/', $w) && $w !== (string) ($case['no'] ?? '') && ($nm === '' || mb_strpos($nm, mb_strtolower($w)) === false)))); }
    if ($status === null) { // lookup: the report and the stage
        $rep = trim((string) ($case['report'] ?? '')) ?: wf_case_report($aid, ['name' => $case['name'] ?? '', 'phone' => $key] + array_map(fn($v) => is_array($v) ? implode("\n", $v) : (string) $v, (array) ($case['data'] ?? [])));
        $say('ID ' . ($case['no'] ?? '') . ' · ' . ($st[$case['status'] ?? 'new'] ?? '') . "\n\n" . $rep); wi_log('case_lookup', ['account' => $aid, 'group' => $jid, 'case' => $key]); return true; }
    if ($reason === '' && $status !== 'archived') { $say(wf_msg('case_no_reason', ['status' => $cmd])); return true; } // archiving needs no reason
    $note = ['at' => date('c'), 'by' => $by, 'text' => ($st[$status] ?? $status) . ($reason !== '' ? ' — ' . mb_substr($reason, 0, 2000) : '')];
    wf_case_update($aid, $key, function (?array $c) use ($status, $from, $note) { if ($c === null) return null; // changed in place: a note added from the dashboard meanwhile stays
        $c['status_log'][] = ['from' => $c['status'] ?? 'new', 'to' => $status, 'at' => date('c'), 'by' => 'group:' . $from];
        $c['status'] = $status; $c['updated'] = date('c'); $c['notes'][] = $note; return $c; });
    $say(wf_msg('case_status_done', ['name' => $by, 'case' => $one, 'person' => ($case['name'] ?? '') ?: '+' . $key, 'status' => $st[$status] ?? $status, 'reason' => mb_substr($reason, 0, 300)]));
    wf_case_diag_note($acc, $key, $jid, (string) ($st[$status] ?? $status), $reason, $by);
    wi_log('case_status_cmd', ['account' => $aid, 'group' => $jid, 'case' => $key, 'to' => $status]);
    return true;
}

/** A stage change written in another group is noted in the diagnosis group too, as a reply to the case's report there when it is known. */
function wf_case_diag_note(array $acc, string $key, string $fromJid, string $status, string $reason, string $by): void {
    $aid = (string) $acc['id']; $c = wf_case_get($aid, $key); if (!$c || wf_case_is_private($c)) return; // a private-chat case is never posted to the diagnosis group
    $diag = (string) ($c['diag_jid'] ?? '') ?: wf_intake_diag($aid); if ($diag === '' || $diag === $fromJid) return; // written in the diagnosis group itself: already there
    $txt = wf_msg('case_diag_note', ['no' => (string) ($c['no'] ?? ''), 'name' => (string) ($c['name'] ?? '') ?: '+' . $key, 'status' => $status, 'reason' => mb_substr($reason, 0, 1500), 'by' => $by]);
    $q = !empty($c['diag_msg']) && ($c['diag_jid'] ?? '') === $diag ? (string) $c['diag_msg'] : '';
    if ($q === '') foreach (array_reverse((array) (wi_gateway('/archive/thread?limit=600&jid=' . rawurlencode($diag), null, $acc)['rows'] ?? [])) as $m) { $t = (string) ($m['text'] ?? ''); // an older report: found by the number in it
        if (mb_strlen($t) > 60 && (strpos($t, '+' . $key) !== false || strpos($t, $key) !== false)) { $q = (string) ($m['id'] ?? ''); break; } }
    wi_gateway('/chat/send', ['jid' => $diag, 'text' => $txt, 'via' => 'bot'] + ($q !== '' ? ['quote_id' => $q] : []), $acc);
}
/** "#menu": every command this number answers in WhatsApp, with what it does (words and help text from the case presets). */
function wf_cmd_menu(string $aid): string {
    $cc = wf_case_cfg($aid); $w = (array) ($cc['words'] ?? []); $h = (array) ($w['help'] ?? []); $one = (string) $cc['one'];
    $f = fn(string $t, string $cmd = '') => str_replace(['{case}', '{many}', '{cmd}'], [$one, (string) $cc['many'], $cmd], $t);
    $item = function (string $k, string $cmd, string $cmds = '') use ($h, $f) { $x = (array) ($h[$k] ?? []); if (count($x) < 3) return '';
        return '▪️ *' . $f((string) $x[0]) . "*\n" . ($cmds !== '' ? $cmds . "\n" : '') . ($h['how'] ?? '') . ' ' . $f((string) $x[1], $cmd) . "\n" . ($h['eg'] ?? '') . ' ' . $f((string) $x[2], $cmd); };
    $B = [];
    $st = []; foreach ((array) ($cc['cmds'] ?? []) as $k => $c) if ($k !== 'archived') $st[] = $c;
    $cases = array_filter([$item('new', (string) wf_intake_cfg($aid)['keyword']), $item('lookup', wf_intake_lookup_kw($aid)),
        $st ? $item('stage', (string) $st[0], implode(' · ', $st)) : '', !empty($w['archive']) ? $item('archive', (string) $w['archive']) : '']);
    if ($cases) $B[] = $f((string) ($h['sec_cases'] ?? '')) . "\n\n" . implode("\n\n", $cases);
    if (!empty($w['list'])) $B[] = $f((string) ($h['sec_lists'] ?? '')) . "\n\n" . $item('list', (string) $w['list']);
    $lib = []; if (function_exists('wf_libcmd_words')) foreach (wf_libcmd_words($aid) as $k => $c) if ($k !== 'menu' && ($x = $item($k, (string) $c)) !== '') $lib[] = $x;
    if ($lib) $B[] = $f((string) ($h['sec_library'] ?? '')) . "\n\n" . implode("\n\n", $lib);
    // the files' shortcuts (#shortcut-name → the recording) and the quick replies' keywords
    $codes = []; foreach ((array) (wi_read('media_meta.json', [])[$aid] ?? []) as $m) if (trim((string) ($m['code'] ?? '')) !== '' && empty($m['hidden'])) $codes[] = '• ' . $m['code'] . (trim((string) ($m['title'] ?? '')) !== '' ? ' — ' . $m['title'] : '');
    $more = fn(array $L) => count($L) > 30 ? implode("\n", array_slice($L, 0, 30)) . "\n" . str_replace('{n}', (string) (count($L) - 30), (string) ($h['more'] ?? '')) : implode("\n", $L);
    if ($codes) $B[] = $f((string) ($h['sec_files'] ?? '')) . "\n" . $more($codes);
    $acc = wi_accounts()[$aid] ?? null; $quick = [];
    if ($acc && function_exists('wi_replies_for')) foreach (wi_replies_for($acc) as $r) if (trim((string) ($r['keyword'] ?? '')) !== '') $quick[] = '• #' . ltrim(trim((string) $r['keyword']), '#/') . ' — ' . (string) ($r['title'] ?? '');
    if ($quick) $B[] = $f((string) ($h['sec_quick'] ?? '')) . "\n" . $more($quick);
    $own = []; if (function_exists('wf_cmds_custom')) foreach (wf_cmds_custom($aid) as $cu) if (!empty($cu['on'])) $own[] = '• ' . $cu['tag'] . (($cu['title'] ?? '') !== '' ? ' — ' . $cu['title'] : '');
    if ($own) $B[] = wf_msg('cmd_menu_own') . "\n" . $more($own);
    return wf_msg('cmd_menu', ['list' => implode("\n\n━━━━━━━━━━\n\n", $B), 'menu' => (string) ($w['menu'] ?? '#menu')]);
}
/** Is this message asking for the commands list? "#menu" in any case (#Menu, #MENU), its other names (#منيو, #الاوامر, #help…),
 *  a small typo of them (#mnue, #منيوو), or the list word alone / with a word it does not know ("#قائمة", "#قائمة #مش_موجود"). */
function wf_is_menu(string $aid, string $text): bool {
    // "# الاوامر" (a space after #) is the same
    $t = trim(preg_replace('/^#\s+/u', '#', trim($text))); if ($t === '' || $t[0] !== '#' || mb_strlen($t) > 40) return false;
    $w = (array) (wf_case_cfg($aid)['words'] ?? []);
    $names = array_filter(array_map(fn($x) => wf_intake_norm(trim($x)), array_merge([(string) ($w['menu'] ?? '#menu')], preg_split('/[,\x{060C}]+/u', (string) ($w['menu_alias'] ?? '')))));
    $tags = preg_split('/\s+/u', wf_intake_norm($t)); $first = (string) $tags[0];
    $sorted = function (string $x) { $c = preg_split('//u', $x, -1, PREG_SPLIT_NO_EMPTY); sort($c); return implode('', $c); };
    foreach ($names as $n) { if ($first === $n) { if (count($tags) === 1) return true; break; } // with more words: the list word below decides
        if (count($tags) === 1 && mb_strlen($n) >= 5 && preg_match('/^#[a-z]+$/', $n) && preg_match('/^#[a-z]+$/', $first) && (levenshtein($first, $n) <= 1 || $sorted($first) === $sorted($n))) return true; // #mneu, #menuu, #mnue
        if (count($tags) === 1 && mb_strlen($n) >= 5 && !preg_match('/[a-z]/', $n) && abs(mb_strlen($first) - mb_strlen($n)) <= 1 && similar_text($first, $n) >= strlen($n) - 2) return true; } // #منيوو
    $list = wf_intake_norm((string) ($w['list'] ?? '')); // the list word alone, or with nothing it knows
    if ($list !== '' && $first === $list) { $known = array_filter(array_map(fn($x) => wf_intake_norm((string) $x), [wf_intake_lookup_kw($aid), '#' . wf_case_cfg($aid)['many'], '#' . wf_case_cfg($aid)['one'], $w['shortcuts'] ?? '', $w['programs'] ?? '', $w['library'] ?? '']));
        foreach (array_slice($tags, 1) as $x) if (in_array($x, $known, true)) return false; return true; }
    return false;
}
/** A group reply tags the person it answers (so replies to different people never get mixed up): the text gets "@number " and the tag. */
function wf_group_tag(string $from): array { $from = preg_replace('/\D/', '', $from); return $from === '' ? ['', []] : ['@' . $from . ' ', [$from . (strlen($from) >= 14 ? '@lid' : '@s.whatsapp.net')]]; }
/** Called by the gateway for a group message that has the keyword, or from someone the bot is waiting for. */
/** Reception gate (a group setting): a new member writes "woman" / "man" → asked once for a recording, quoting them;
 *  their recording → the reviewers are tagged to check it and move them. True when it handled the message. */
/** A link, a group / channel invite or a phone number in a message (mentions do not count). */
function wf_spam_is(string $text): bool {
    $t = preg_replace('/@\d{6,}/', '', $text);
    return (bool) preg_match('~https?://|www\.|chat\.whatsapp\.com|wa\.me/|whatsapp\.com/channel|t\.me/|telegram\.me|bit\.ly|tinyurl|\b[a-z0-9-]{2,}\.(com|net|org|info|xyz|link|site|online|shop|store|me|io|co)\b~i', $t)
        || preg_match('/(?:\+|00)?\d[\d\s\-]{8,}\d/', $t);
}
/** Anti-spam in a group (Bot → groups): a member outside the team sends a link, an invite or a number → the message is deleted for everyone and the number removed. */
function wf_spam_hook(array $acc, array $in, callable $reply): bool {
    $aid = (string) $acc['id']; $jid = wf_jid($in['jid'] ?? ''); if (!str_ends_with($jid, '@g.us') || !empty($in['from_me'])) return false;
    $c = wf_bot_run_cfg($aid); if ($c['mode'] !== 'auto' || wi_account_blocked($acc)) return false; // removing people is done only when the bot runs on its own
    $g = wf_bot_group_cfg($c, $jid); if (!$g || empty($g['antispam'])) return false;
    $text = trim((string) ($in['text'] ?? '')); if ($text === '' || str_contains($text, '#') || !wf_spam_is($text)) return false; // a #command (a case with its number) is never spam
    $from = wf_phone((string) ($in['from'] ?? '')); if ($from === '' || isset(wf_team($acc, (array) ($c['groups'] ?? []))[$from])) return false; // the team posts links freely
    if (strlen($from) >= 14) { $lp = wf_phone((string) (wi_contact($from . '@lid', $acc)['phone'] ?? '')); if ($lp === '' || isset(wf_team($acc, (array) ($c['groups'] ?? []))[$lp])) return false; } // a hidden id that cannot be matched to a phone: never removed (it may be a team member)
    $reply(['ok' => false, 'done' => true]); ignore_user_abort(true); @set_time_limit(90);
    $id = preg_replace('/[^0-9A-Za-z]/', '', (string) ($in['id'] ?? '')); $del = [];
    for ($i = 0; $i < 4 && empty($del['ok']); $i++) { if ($i) sleep(2); $del = wi_gateway('/chat/delete-msg', ['jid' => $jid, 'id' => $id, 'admin' => true, 'by' => 'antispam'], $acc); } // the message reaches the archive a moment later
    $who = (string) ($in['from'] ?? ''); $who = str_contains($who, '@') ? $who : $from . (strlen($from) >= 14 ? '@lid' : '@s.whatsapp.net');
    $rm = wi_gateway('/groups/remove', ['group' => $jid, 'jid' => $who], $acc);
    wi_log('group_spam', ['account' => $aid, 'group' => $jid, 'from' => $from, 'deleted' => !empty($del['everyone']), 'removed' => (string) ($rm['result'] ?? ($rm['error'] ?? '')), 'text' => mb_substr($text, 0, 200)]);
    return true;
}
function wf_gate_hook(array $acc, array $in, callable $reply): bool {
    $aid = (string) $acc['id']; $jid = wf_jid($in['jid'] ?? ''); if (!str_ends_with($jid, '@g.us') || !empty($in['from_me'])) return false;
    $c = wf_bot_run_cfg($aid); if ($c['mode'] !== 'auto' || wi_account_blocked($acc)) return false;
    $g = wf_bot_group_cfg($c, $jid); if (!$g || empty($g['gate'])) return false;
    $from = wf_phone((string) ($in['from'] ?? '')); if ($from === '' || isset(wf_team($acc, (array) ($c['groups'] ?? []))[$from])) return false; // the team talks freely
    $id = preg_replace('/[^0-9A-Za-z]/', '', (string) ($in['id'] ?? '')); $text = trim((string) ($in['text'] ?? ''));
    $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 60); $now = time(); $act = '';
    if (!!($g['gate_follow'] ?? true)) wi_update('gate_state.json', function ($S) use ($aid, $jid, $from, $now) { if (isset($S[$aid][$jid][$from]['j']) && empty($S[$aid][$jid][$from]['r'])) $S[$aid][$jid][$from]['r'] = $now; return $S; }); // they answered: no reminders
    if ((array) ($in['voices'] ?? [])) $act = 'voice';
    else {
        $W = (array) (wf_pack($aid)['gate_words'] ?? []); $tok = array_filter(preg_split('/[\s,.\x{060C}!?\x{061F}]+/u', wf_intake_norm($text)));
        $hit = function (string $list) use ($tok) { foreach (preg_split('/[,\x{060C}\n]+/u', $list) as $w) if (($w = wf_intake_norm(trim($w))) !== '' && in_array($w, $tok, true)) return true; return false; };
        // "السلام عليكم … أنا إمرأة ولكم جزيل الشكر" counts; a question that mentions a woman does not
        if ($tok && count($tok) <= 15 && !wf_is_question($text)) { if ($hit($g['gate_f'] !== '' ? $g['gate_f'] : (string) ($W['f'] ?? ''))) $act = 'f'; elseif ($hit($g['gate_m'] !== '' ? $g['gate_m'] : (string) ($W['m'] ?? ''))) $act = 'm'; }
    }
    if ($act === '') return false;
    // the gender said again with no recording yet: asked again after an hour
    $go = false; // once per member: the question once a day, the reviewers once per 30 minutes
    wi_update('gate_state.json', function ($S) use ($aid, $jid, $from, $act, $now, $name, &$go) { $k = $act === 'voice' ? 'v' : 'a'; $last = (int) ($S[$aid][$jid][$from][$k] ?? 0);
        if ($name !== '') $S[$aid][$jid][$from]['n'] = $name;
        $go = $now - $last > ($act === 'voice' ? 1800 : (empty($S[$aid][$jid][$from]['v']) ? 3600 : 86400)); if ($go) { $S[$aid][$jid][$from][$k] = $now; if ($act !== 'voice') $S[$aid][$jid][$from]['g'] = $act; }
        foreach ((array) ($S[$aid][$jid] ?? []) as $p => $r) if (max((int) ($r['a'] ?? 0), (int) ($r['v'] ?? 0), (int) ($r['j'] ?? 0)) < $now - 30 * 86400) unset($S[$aid][$jid][$p]); return $S; });
    $reply(['ok' => false, 'done' => true]); ignore_user_abort(true); @set_time_limit(60);
    if (!$go) return true;
    [$tagT, $tagM] = wf_group_tag($from);
    if ($act === 'voice') {
        $rev = array_values(array_filter(array_map('wf_phone', explode(',', (string) $g['gate_review'])))); if (!$rev) return true;
        $txt = wf_msg('gate_voice', ['reviewers' => implode(' ', array_map(fn($p) => '@' . $p, $rev)), 'name' => $name !== '' ? $name : '@' . $from]);
        $r = wi_gateway('/chat/send', ['jid' => $jid, 'text' => $txt, 'mentions' => array_map(fn($p) => $p . '@s.whatsapp.net', $rev), 'quote_id' => $id, 'via' => 'bot'], $acc);
    } else $r = wi_gateway('/chat/send', ['jid' => $jid, 'text' => $tagT . wf_msg($act === 'f' ? 'gate_ask_f' : 'gate_ask_m', ['name' => $name]), 'mentions' => $tagM, 'quote_id' => $id, 'via' => 'bot'], $acc);
    wi_log('reception_gate', ['account' => $aid, 'group' => $jid, 'step' => $act, 'sent' => !empty($r['ok'])]);
    return true;
}
/** A member who went through a reception gate joined another of our groups (a team member moved them):
 *  the team member who added them (else the reviewers) is tagged in the gate's cases group to open the case, with the member's number. */
function wf_gate_moved(array $acc, string $gjid, array $members, string $by): void {
    $aid = (string) $acc['id']; $c = wf_bot_run_cfg($aid); if ($c['mode'] !== 'auto') return;
    $S = (array) (wi_read('gate_state.json', [])[$aid] ?? []); $names = null;
    foreach ($S as $gate => $people) {
        if ($gate === $gjid) continue; $g = wf_bot_group_cfg($c, (string) $gate); if (!$g || empty($g['gate']) || $g['gate_notify'] === '') continue;
        foreach ($members as $m) {
            $num = wf_phone(explode('@', (string) $m)[0]); $p = (array) ($people[$num] ?? []); if (!$p || !empty($p['moved'])) continue; // not from the reception group, or already done
            $mine = false; wi_update('gate_state.json', function ($X) use ($aid, $gate, $num, &$mine) { if (empty($X[$aid][$gate][$num]['moved'])) { $X[$aid][$gate][$num]['moved'] = time(); $mine = true; } return $X; });
            if (!$mine) continue; // another join of the same member already sent it
            $by = wf_phone($by); $who = $by !== '' ? [$by] : array_values(array_filter(array_map('wf_phone', explode(',', (string) $g['gate_review']))));
            $tags = implode(' ', array_map(fn($x) => '@' . $x, $who)); $jids = array_map(fn($x) => $x . (strlen($x) > 13 ? '@lid' : '@s.whatsapp.net'), $who);
            if ($names === null) { $names = []; foreach ((array) (wi_gateway('/groups', null, $acc)['groups'] ?? []) as $x) $names[(string) ($x['id'] ?? $x['jid'] ?? '')] = (string) ($x['subject'] ?? $x['name'] ?? ''); }
            $txt = wf_msg('gate_moved', ['follower' => $tags, 'name' => (string) ($p['n'] ?? '') !== '' ? (string) $p['n'] : '+' . $num, 'phone' => '+' . $num, 'group' => $names[$gjid] ?? '']);
            $r = wi_gateway('/chat/send', ['jid' => $g['gate_notify'], 'text' => $txt, 'mentions' => $jids, 'via' => 'bot'], $acc);
            wi_log('reception_moved', ['account' => $aid, 'from_group' => $gate, 'to_group' => $gjid, 'sent' => !empty($r['ok'])]);
        }
    }
}
/** New members of a reception group: their join time starts the follow-up (reminder, last notice, removal) until they answer. */
function wf_gate_joined(array $acc, string $gjid, array $members): void {
    $aid = (string) $acc['id']; $c = wf_bot_run_cfg($aid); $g = wf_bot_group_cfg($c, $gjid);
    if (!$g || empty($g['gate']) || !($g['gate_follow'] ?? true)) return;
    $now = time(); $nums = array_values(array_filter(array_map(fn($m) => wf_phone(explode('@', (string) $m)[0]), $members)));
    wi_update('gate_state.json', function ($S) use ($aid, $gjid, $nums, $now) { foreach ($nums as $n) { $p = (array) ($S[$aid][$gjid][$n] ?? []);
        unset($p['r'], $p['rm'], $p['fn'], $p['k']); $p['j'] = $now; $S[$aid][$gjid][$n] = $p; } return $S; });
}
/** Every minute (cron): members who joined a reception group and did not finish (no recording sent, not moved) get a reminder, then a last notice, then are removed. */
function wf_gate_due(array $accounts): void {
    $now = time(); $all = wi_read('gate_state.json', []);
    foreach ($accounts as $acc) {
        $aid = (string) $acc['id']; if (empty($all[$aid]) || wi_account_blocked($acc)) continue;
        $c = wf_bot_run_cfg($aid); if ($c['mode'] !== 'auto') continue; $sent = 0;
        foreach ((array) $all[$aid] as $gjid => $people) {
            $g = wf_bot_group_cfg($c, (string) $gjid); if (!$g || empty($g['gate']) || !($g['gate_follow'] ?? true)) continue;
            $rh = (int) ($g['gate_remind_h'] ?? 3) * 3600; $fh = $rh + (int) ($g['gate_final_h'] ?? 3) * 3600; $km = (int) ($g['gate_kick_m'] ?? 30) * 60;
            foreach ((array) $people as $num => $p) {
                if ($sent >= 5) break 2; // a few per minute
                $j = (int) ($p['j'] ?? 0); if (!$j || !empty($p['v']) || !empty($p['moved']) || !empty($p['k']) || $j < $now - 7 * 86400) continue;
                $step = ''; if (empty($p['rm']) && $now - $j >= $rh && $now - $j < $fh) $step = 'rm'; elseif (empty($p['fn']) && $now - $j >= $fh) $step = 'fn'; elseif (!empty($p['fn']) && $km > 0 && $now - (int) $p['fn'] >= $km) $step = 'k';
                if ($step === '') continue;
                static $mem = []; if (!isset($mem[$gjid])) { $mem[$gjid] = []; foreach ((array) (wi_gateway('/groups/members?jid=' . rawurlencode((string) $gjid), null, $acc)['members'] ?? []) as $pp) { $ph = wf_phone((string) ($pp['phone'] ?? '')); $id = (string) ($pp['jid'] ?? $pp['id'] ?? ''); if ($ph !== '') $mem[$gjid][$ph] = $id; $mem[$gjid][wf_phone(explode('@', $id)[0])] = $id; } }
                if ($mem[$gjid] && !isset($mem[$gjid][(string) $num])) { wi_update('gate_state.json', function ($S) use ($aid, $gjid, $num) { $S[$aid][$gjid][$num]['k'] = time(); $S[$aid][$gjid][$num]['left'] = 1; return $S; }); continue; } // already left or moved: nothing to send
                $mj = (string) ($mem[$gjid][(string) $num] ?? ((string) $num . (strlen((string) $num) > 13 ? '@lid' : '@s.whatsapp.net'))); $tag = '@' . $num;
                if ($step === 'k') $r = wi_gateway('/groups/remove', ['group' => $gjid, 'jid' => $mj], $acc);
                else $r = wi_gateway('/chat/send', ['jid' => $gjid, 'text' => wf_msg($step === 'rm' ? 'gate_remind' : 'gate_final', ['mention' => $tag, 'minutes' => (string) (int) ($g['gate_kick_m'] ?? 30)]), 'mentions' => [$mj], 'via' => 'bot'], $acc);
                wi_update('gate_state.json', function ($S) use ($aid, $gjid, $num, $step, $now) { $S[$aid][$gjid][$num][$step] = $now; return $S; });
                wi_log('reception_follow', ['account' => $aid, 'group' => $gjid, 'step' => $step, 'ok' => !empty($r['ok']), 'result' => $r['result'] ?? ($r['error'] ?? '')]);
                $sent++;
            }
        }
    }
}
function wf_intake_hook(array $acc, array $in): void {
    $aid = (string) $acc['id']; $c = wf_intake_cfg($aid); $jid = wf_jid($in['jid'] ?? ''); $from = preg_replace('/\D/', '', (string) ($in['from'] ?? ''));
    $text = trim(mb_substr((string) ($in['text'] ?? ''), 0, 8000)); $id = preg_replace('/[^0-9A-Za-z]/', '', (string) ($in['id'] ?? ''));
    $reply = function (array $x) { header('Content-Type: application/json'); header('Connection: close'); $b = json_encode($x); header('Content-Length: ' . strlen($b)); echo $b; flush(); if (function_exists('fastcgi_finish_request')) fastcgi_finish_request(); };
    // the same message reaches this hook more than once (the gateway sends it again): only the first one is handled
    if ($id !== '') { $sd = WI_DIR . '/intake_seen'; if (!is_dir($sd)) @mkdir($sd, 0700, true);
        if (mt_rand(1, 50) === 1) foreach ((array) glob($sd . '/*') as $f) if (@filemtime($f) < time() - 86400) @unlink($f);
        $fh = @fopen($sd . '/' . preg_replace('/\W/', '', $aid) . '_' . $id, 'x'); if (!$fh) { $reply(['ok' => false, 'done' => true]); exit; } fclose($fh); }
    if (wf_spam_hook($acc, $in, $reply)) exit; // anti-spam: delete and remove
    if (wf_gate_hook($acc, $in, $reply)) exit; // reception gate: woman / man → recording → reviewers
    $diag = wf_intake_diag($aid);
    if ($text !== '' && str_ends_with($jid, '@g.us') && !wi_account_blocked($acc) && wf_libcmd_which($aid, $text) !== '') { // library commands (spiritual healing numbers)
        $reply(['ok' => false, 'done' => true]); ignore_user_abort(true); @set_time_limit(120); wf_libcmd_hook($acc, $jid, $text, $id, $from); exit; }
    if ($text !== '' && str_ends_with($jid, '@g.us') && !wi_account_blocked($acc) && function_exists('wf_media_code_find') && (wf_media_code_in($aid, $text) !== '' || wf_libtag_which($aid, $text) || wf_program_code_in($aid, $text) || wf_lib_text_code_in($aid, $text))) { // a file's shortcut (alone or after the send word): the file
        $reply(['ok' => false, 'done' => true]); wf_media_code_send($acc, $jid, $text, $id); exit; }
    if ($text !== '' && str_ends_with($jid, '@g.us') && !wi_account_blocked($acc) && preg_match('/^\s*#\S+\s*$/u', $text) && function_exists('wi_replies_for')) { // a quick reply's keyword ("#keyword"): its text, in the group
        $typed = wf_norm(ltrim(trim($text), '#')); $hit = array_values(array_filter(wi_replies_for($acc), fn($r) => ($k = wf_norm(ltrim(trim((string) ($r['keyword'] ?? '')), '#/'))) !== '' && $k === $typed));
        if (count($hit) === 1) { $reply(['ok' => false, 'done' => true]); [$tagT, $tagM] = wf_group_tag($from);
            wi_gateway('/chat/send', ['jid' => $jid, 'text' => $tagT . wi_reply_fill((string) $hit[0]['text'], $jid, $acc), 'quote_id' => $id, 'mentions' => $tagM, 'via' => 'bot'], $acc); wi_log('reply_from_group', ['account' => $aid, 'group' => $jid, 'title' => $hit[0]['title'] ?? '']); exit; } }
    if ($text !== '' && str_ends_with($jid, '@g.us') && !wi_account_blocked($acc) && function_exists('wf_cmd_custom_which') && ($cu = wf_cmd_custom_which($aid, $text, true))) { // the owner's own command (Bot → Commands)
        $reply(['ok' => false, 'done' => true]); ignore_user_abort(true); @set_time_limit(120); wf_cmd_custom_run($acc, $cu, $jid, $text, $id, $from, (string) ($in['name'] ?? '')); exit; }
    if ($text !== '' && str_ends_with($jid, '@g.us') && !wi_account_blocked($acc) && function_exists('wf_case_recs_command') && ($rw = trim((string) (wf_pack($aid)['recs_cmd'] ?? ''))) !== '' && wf_intake_has(mb_substr($text, 0, mb_strlen($rw) + 2), $rw)) { // "#توصيات" + case
        $reply(['ok' => false, 'done' => true]); ignore_user_abort(true); @set_time_limit(300); [$tagT, $tagM] = wf_group_tag($from); $msg = wf_case_recs_command($acc, $text, (array) ($in['mentions'] ?? []), '', $jid, $tagM);
        if ($msg !== '') wi_gateway('/chat/send', ['jid' => $jid, 'text' => $tagT . $msg, 'quote_id' => $id, 'mentions' => $tagM, 'via' => 'bot'], $acc); exit; }
    if ($text !== '' && str_ends_with($jid, '@g.us') && !wi_account_blocked($acc) && function_exists('wf_case_deep_command') && ($dw = trim((string) (wf_pack($aid)['analyze_cmd'] ?? ''))) !== '' && wf_intake_has(mb_substr($text, 0, mb_strlen($dw) + 2), $dw)) { // "#تحليل" + case: its analysis in the group
        $reply(['ok' => false, 'done' => true]); ignore_user_abort(true); @set_time_limit(260); [$tagT, $tagM] = wf_group_tag($from); $msg = wf_case_deep_command($acc, $text, (array) ($in['mentions'] ?? []), '+' . $from, '', $jid, $tagM);
        if ($msg !== '') wi_gateway('/chat/send', ['jid' => $jid, 'text' => $tagT . $msg, 'quote_id' => $id, 'mentions' => $tagM, 'via' => 'bot'], $acc); exit; }
    $menuW = (string) (wf_case_cfg($aid)['words']['menu'] ?? ''); // "#menu": the list of commands, in any group
    if ($text !== '' && str_ends_with($jid, '@g.us') && !wi_account_blocked($acc) && wf_is_menu($aid, $text)) {
        $reply(['ok' => false, 'done' => true]); [$tagT, $tagM] = wf_group_tag($from);
        wi_gateway('/chat/send', ['jid' => $jid, 'text' => $tagT . wf_cmd_menu($aid), 'quote_id' => $id, 'mentions' => $tagM, 'via' => 'bot'], $acc); wi_log('cmd_menu', ['account' => $aid, 'group' => $jid]); exit; }
    // a follow-up command (#الحالة, #اتحسنت …): also in the diagnosis group
    if (!empty($c['on']) && !wi_account_blocked($acc) && str_ends_with($jid, '@g.us') && $from !== '' && $text !== '' && wf_intake_is_cmd($aid, $text)
        && ($jid === $diag || !$c['groups'] || in_array($jid, (array) $c['groups'], true))) {
        if (empty($in['self'])) wf_team_learn($aid, $from, 'wrote a case command in a group');
        $reply(['ok' => false, 'done' => true]); ignore_user_abort(true); @set_time_limit(120); wf_intake_command($acc, $in, $jid, $from, $text, $id); exit; }
    if (empty($c['on']) || wi_account_blocked($acc) || !str_ends_with($jid, '@g.us') || $from === '' || ($text === '' && empty($in['voices'])) || $jid === $diag || ($c['groups'] && !in_array($jid, (array) $c['groups'], true))) { $reply(['ok' => false, 'done' => true]); exit; }
    $pk = $jid . '|' . $from; $pend = (array) (wi_read('case_intake.json', [])[$aid][$pk] ?? []);
    $isNew = wf_intake_has_kw($aid, $text) && (empty($c['mention']) || !empty($in['tagged_me'])) && wf_intake_has_target($in, $text);
    if ($isNew && empty($in['self'])) wf_team_learn($aid, $from, 'opened a case in a group'); // who writes cases is a team member
    if ($isNew && $pend) { // the writer sends the keyword again: the same person (or a waiting case with nothing in it) continues, another person starts a new one
        $newPhone = wf_intake_phone(['phone' => $text], array_map('strval', (array) ($in['mentions'] ?? [])), $from, $acc);
        $oldPhone = wf_intake_phone((array) ($pend['fields'] ?? []), (array) ($pend['mentions'] ?? []), $from, $acc);
        if ($oldPhone === '' && !array_filter((array) ($pend['fields'] ?? []))) { wi_update('case_intake.json', function ($A) use ($aid, $pk) { unset($A[$aid][$pk]); return $A; }); $pend = []; }
        elseif ($newPhone !== '' && $newPhone === $oldPhone) $isNew = false; }
    if (!$isNew && (!$pend || strtotime((string) $pend['deadline']) < time())) { $reply(['ok' => false, 'done' => true]); exit; }
    if ($pend && in_array($id, (array) ($pend['ids'] ?? []), true)) { $reply(['ok' => true]); exit; } // the same message twice
    if ($isNew && !wf_intake_first_time($aid, $id)) { $reply(['ok' => false, 'done' => true]); exit; } // WhatsApp delivered the keyword message again
    $wait = max(0, (int) $c['wait_min']) * 60;
    $reply(['ok' => true, 'wait' => $wait]);
    ignore_user_abort(true); @set_time_limit(300);
    if ($isNew && $pend) wf_intake_finish($acc, $pk); // a new case from the same writer: the one waiting is saved first
    if ($isNew) $pend = ['jid' => $jid, 'from' => $from, 'name' => mb_substr(trim((string) ($in['name'] ?? '')), 0, 60), 'msg_id' => $id, 'at' => date('c'), 'texts' => [], 'ids' => [], 'mentions' => [], 'people' => [], 'voices' => [], 'asked' => false, 'self' => !empty($in['from_me'])];
    foreach ((array) ($in['people'] ?? []) as $pp) { $pj = (string) ($pp['jid'] ?? ''); if ($pj !== '') $pend['people'][$pj] = mb_substr(trim((string) ($pp['name'] ?? '')), 0, 60); }
    if ($text !== '') $pend['texts'][] = $text; $pend['ids'][] = $id;
    foreach ((array) ($in['voices'] ?? []) as $vo) { $vf = (string) ($vo['file'] ?? ''); $vi = preg_replace('/[^0-9A-Za-z]/', '', (string) ($vo['id'] ?? ''));
        if (preg_match('#^inbox_media/' . preg_quote($aid, '#') . '/(?:[0-9-]{3,40}/)?[A-Za-z0-9_.-]{1,80}$#', $vf) && $vi !== '' && !in_array($vi, array_column((array) ($pend['voices'] ?? []), 'id'), true)) $pend['voices'][] = ['id' => $vi, 'file' => $vf]; }
    $pend['voices'] = array_slice((array) ($pend['voices'] ?? []), 0, 5);
    $pend['mentions'] = array_values(array_unique(array_merge((array) $pend['mentions'], array_map('strval', array_slice((array) ($in['mentions'] ?? []), 0, 10)))));
    if ($text !== '') { $x = wf_intake_extract($aid, implode("\n\n", $pend['texts'])); $pend['fields'] = $x['fields'] + (array) ($pend['fields'] ?? []); } // a recording alone needs no AI
    $pend['fields'] = (array) ($pend['fields'] ?? []);
    $phone = wf_intake_phone($pend['fields'], $pend['mentions'], $from, $acc);
    if ($phone !== '' && ($old = wf_case_get($aid, $phone))) { // the number already has a file: never a second one — new details are added to it, nothing new gets a short answer
        $od = (array) ($old['data'] ?? []);
        $newF = array_filter(array_diff_key((array) $pend['fields'], ['name' => 1, 'phone' => 1]), fn($x, $k) => trim((string) $x) !== '' && wf_intake_norm((string) $x) !== wf_intake_norm(is_array($od[$k] ?? null) ? implode("\n", $od[$k]) : (string) ($od[$k] ?? '')), ARRAY_FILTER_USE_BOTH);
        $newV = array_filter((array) $pend['voices'], fn($vo) => !in_array($vo['file'], array_column((array) ($old['voices'] ?? []), 'file'), true));
        if (!$newF && !$newV) {
            wi_update('case_intake.json', function ($A) use ($aid, $pk) { unset($A[$aid][$pk]); return $A; });
            [$tagT, $tagM] = wf_group_tag($from);
            $r = wi_gateway('/chat/send', ['jid' => $jid, 'mentions' => $tagM, 'text' => $tagT . wf_msg('case_intake_exists', ['case' => (string) wf_case_cfg($aid)['one'], 'person' => (string) ($old['name'] ?? '') ?: ('+' . $phone), 'no' => (string) ($old['no'] ?? ''), 'follower' => (string) ($old['data']['follower'] ?? '')]), 'quote_id' => $pend['msg_id'], 'via' => 'bot'], $acc);
            wi_log('case_intake_exists', ['account' => $aid, 'group' => $jid, 'case' => $phone, 'sent' => !empty($r['ok'])]); return; }
        $pend['asked'] = true; $pend['deadline'] = date('c'); // details for a file that exists: added now, no questions
    }
    $pend['fields'] += wf_intake_known($aid, $phone, (array) $pend['people']); // what is already known about the tagged number
    $miss = wf_intake_missing($aid, $pend['fields'], $phone, (array) $pend['voices'], wf_intake_follower($acc, $pend, $pend['fields']));
    $ask = $miss && $wait > 0 && empty($pend['asked']);
    if ($ask) { $pend['asked'] = true; $pend['deadline'] = date('c', time() + $wait); }
    $saved = wf_intake_save($aid, $pk, $pend, $isNew, $ask);
    if ($saved === null) return; // finished meanwhile (time up, or a new case from the writer)
    if (!empty($saved['_ask'])) { // ask once, quoting the writer; their next messages complete it
        $q = implode("\n", array_map(fn($i, $l) => ($i + 1) . '. ' . $l, array_keys(array_values($miss)), array_values($miss)));
        [$tagT, $tagM] = wf_group_tag($from);
        $r = wi_gateway('/chat/send', ['jid' => $jid, 'mentions' => $tagM, 'text' => $tagT . wf_msg('case_intake_ask', ['name' => $pend['name'], 'case' => (string) wf_case_cfg($aid)['one'], 'questions' => $q, 'minutes' => (string) (int) $c['wait_min']]), 'quote_id' => $pend['msg_id'], 'via' => 'bot'], $acc);
        wi_log('case_intake_ask', ['account' => $aid, 'group' => $jid, 'missing' => array_keys($miss), 'sent' => !empty($r['ok'])]); return; }
    $miss = wf_intake_missing($aid, (array) $saved['fields'], wf_intake_phone((array) $saved['fields'], (array) $saved['mentions'], $from, $acc), (array) $saved['voices'], wf_intake_follower($acc, $saved, (array) $saved['fields']));
    if ($miss && !empty($saved['asked']) && strtotime((string) $saved['deadline']) > time()) return; // still waiting for the rest
    wf_intake_finish($acc, $pk);
}
/** Saves the writer's waiting case merged with what other requests added meanwhile (a fast second answer is never lost).
 *  null when it was finished meanwhile (only a new case starts a record). '_ask' tells the one request that should ask. */
function wf_intake_save(string $aid, string $pk, array $pend, bool $isNew, bool $ask): ?array {
    $out = null;
    wi_update('case_intake.json', function ($A) use ($aid, $pk, $pend, $isNew, $ask, &$out) {
        $cur = $A[$aid][$pk] ?? null;
        if ($cur === null && !$isNew) return $A;
        if ($cur && ($cur['msg_id'] ?? '') === ($pend['msg_id'] ?? '')) {
            foreach (['texts', 'ids', 'mentions'] as $k) $pend[$k] = array_values(array_unique(array_merge((array) ($cur[$k] ?? []), (array) ($pend[$k] ?? []))));
            $v = []; foreach (array_merge((array) ($cur['voices'] ?? []), (array) ($pend['voices'] ?? [])) as $x) $v[$x['id']] = $x; $pend['voices'] = array_slice(array_values($v), 0, 5);
            $pend['people'] = (array) ($pend['people'] ?? []) + (array) ($cur['people'] ?? []); $pend['fields'] = (array) ($pend['fields'] ?? []) + (array) ($cur['fields'] ?? []);
            if (!empty($cur['asked'])) { $ask = false; $pend['asked'] = true; $pend['deadline'] = $cur['deadline']; }
        }
        $A[$aid][$pk] = $pend; $out = $pend + ['_ask' => $ask]; return $A; });
    return $out;
}
/** false when this keyword message was already handled (kept one day). */
function wf_intake_first_time(string $aid, string $id): bool {
    if ($id === '') return true; $first = false;
    wi_update('case_intake_seen.json', function ($S) use ($aid, $id, &$first) { $S[$aid] = array_filter((array) ($S[$aid] ?? []), fn($t) => $t > time() - 86400);
        if (!isset($S[$aid][$id])) { $first = true; $S[$aid][$id] = time(); } return $S; });
    return $first;
}

/** Save the case (complete or not) and post its report to the diagnosis group. */
function wf_intake_finish(array $acc, string $pk): array {
    $aid = (string) $acc['id']; $pend = null;
    wi_update('case_intake.json', function ($A) use ($aid, $pk, &$pend) { $pend = $A[$aid][$pk] ?? null; unset($A[$aid][$pk]); return $A; });
    if (!$pend) return ['ok' => false];
    $f = (array) ($pend['fields'] ?? []); $c = wf_intake_cfg($aid);
    $phone = wf_intake_phone($f, (array) ($pend['mentions'] ?? []), (string) $pend['from'], $acc);
    if ($phone === '' && trim((string) ($f['name'] ?? '')) === '') { wi_log('case_intake_dropped', ['account' => $aid, 'group' => $pend['jid'] ?? '']); return ['ok' => false, 'dropped' => true]; } // nobody to open it for: no empty files
    $key = $phone !== '' ? $phone : 'g' . substr(md5($pk . $pend['at']), 0, 10); // no number given: its own record, completed later from the Cases page
    $by = wf_intake_follower($acc, $pend, $f); // the team member who follows (never this number)
    $sym = array_values(array_filter(array_map(fn($x) => preg_replace('/^\s*(\d+\s*[\.\-\)]|[-•*])\s*/u', '', trim($x)), preg_split('/\R/u', (string) ($f['symptoms'] ?? '')))));
    $v = ['join' => date('d/m/Y'), 'name' => (string) ($f['name'] ?? ''), 'phone' => $phone, 'follower' => $by, 'diag_by' => $by] + $f; $v['symptoms'] = implode("\n", $sym);
    $report = wf_case_report($aid, $v);
    $voices = (array) ($pend['voices'] ?? []); $miss = wf_intake_missing($aid, $f, $phone, $voices, $by);
    $old = wf_case_get($aid, $key); // a file that exists is updated, never opened again
    wf_case_update($aid, $key, function (?array $case) use ($key, $v, $by, $f, $sym, $report, $pend, $voices, $miss, $c, $old) { // built on the stored case under the lock: nothing added meanwhile is lost
        $case = $case ?? ['phone' => $key, 'created' => date('c'), 'notes' => [], 'groups' => []];
        $case['name'] = $v['name'] !== '' ? $v['name'] : ($case['name'] ?? ''); $case['status'] = 'follow'; $case['manual'] = true;
        $data = array_filter(['join' => $v['join'], 'follower' => $by] + array_diff_key($f, ['name' => 1, 'phone' => 1, 'symptoms' => 1]) + ['symptoms' => $sym]);
        $case['data'] = $data + (array) ($case['data'] ?? []);
        $case['report'] = $report; $case['report_at'] = date('c'); $case['by'] = 'group:' . $pend['from'];
        $case['groups'][$pend['jid']] = $case['groups'][$pend['jid']] ?? $pend['jid'];
        foreach ($voices as $vo) if (!in_array($vo['file'], array_column((array) ($case['voices'] ?? []), 'file'), true)) $case['voices'][] = $vo + ['at' => date('c'), 'by' => $by, 'group' => $pend['jid']];
        $case['notes'][] = ['at' => date('c'), 'by' => $by, 'text' => ($old ? 'Details added from the group keyword ' : 'Case opened from the group keyword ') . $c['keyword'] . ($miss ? ' — missing: ' . implode(', ', $miss) : '') . '.'];
        return $case; });
    $diag = wf_intake_diag($aid); $sent = null;
    $report = wf_report_id($report, wf_case_get($aid, $key)['no'] ?? ''); // the case ID on top of the copy
    if ($diag !== '') { $sent = wi_gateway('/chat/send', ['jid' => $diag, 'text' => $report, 'via' => 'bot'], $acc);
        if (!empty($sent['id'])) wf_case_update($aid, $key, function (?array $x) use ($sent, $diag) { if ($x === null) return null; $x['diag_msg'] = (string) $sent['id']; $x['diag_jid'] = $diag; return $x; }); // later stage changes reply to it
        foreach ($voices as $vo) wi_gateway('/chat/send', ['jid' => $diag, 'attachment' => $vo['file'], 'via' => 'bot'], $acc); } // the recording follows the report
    [$tagT, $tagM] = wf_group_tag((string) $pend['from']);
    if (!empty($c['confirm']) && $old) wi_gateway('/chat/send', ['jid' => $pend['jid'], 'mentions' => $tagM, 'text' => $tagT . wf_msg('case_intake_updated', ['case' => (string) wf_case_cfg($aid)['one'], 'person' => $v['name'] ?: ('+' . $phone), 'no' => (string) (wf_case_get($aid, $key)['no'] ?? '')]), 'quote_id' => $pend['msg_id'], 'via' => 'bot'], $acc);
    elseif (!empty($c['confirm'])) wi_gateway('/chat/send', ['jid' => $pend['jid'], 'mentions' => $tagM, 'text' => $tagT . wf_msg('case_intake_done', ['name' => $by, 'case' => (string) wf_case_cfg($aid)['one'], 'person' => $v['name'] ?: ('+' . $phone),
        'missing' => $miss ? implode(', ', $miss) : '']), 'quote_id' => $pend['msg_id'], 'via' => 'bot'], $acc);
    wi_log('case_intake', ['account' => $aid, 'group' => $pend['jid'], 'case' => $key, 'missing' => array_keys($miss), 'posted' => !empty($sent['ok'])]);
    return ['ok' => true, 'case' => $key];
}
/** Every minute (cron): writers who did not answer in time — their case is saved with what was given. */
function wf_intake_due(array $accounts): void {
    foreach ((array) wi_read('case_intake.json', []) as $aid => $P) { $acc = $accounts[$aid] ?? null; if (!$acc) continue;
        foreach ((array) $P as $pk => $p) if (strtotime((string) ($p['deadline'] ?? $p['at'] ?? 'now')) < time()) wf_intake_finish($acc, (string) $pk); }
}

function wf_api_case_intake(string $api): void {
    if ($api !== 'case_intake_cfg') return;
    $acc = wi_current(); $aid = (string) ($acc['id'] ?? ''); if ($aid === '') wi_json(['ok' => false, 'error' => 'No number.']);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') { $in = wf_post(); wf_admin(); $F = wf_intake_askable($aid);
        $x = ['on' => !empty($in['on']) ? 1 : 0, 'keyword' => mb_substr(trim((string) ($in['keyword'] ?? '')), 0, 30), 'wait_min' => max(0, min(1440, (int) ($in['wait_min'] ?? 30))),
            'groups' => array_values(array_filter(array_map('wf_jid', (array) ($in['groups'] ?? [])), fn($j) => str_ends_with($j, '@g.us'))), 'diag' => str_ends_with($d = wf_jid($in['diag'] ?? ''), '@g.us') ? $d : '',
            'ask' => array_values(array_intersect(array_map('strval', (array) ($in['ask'] ?? [])), array_keys($F))), 'confirm' => !empty($in['confirm']) ? 1 : 0, 'mention' => !empty($in['mention']) ? 1 : 0];
        if ($x['keyword'] === '') unset($x['keyword']); // back to the default
        wi_update('case_intake_cfg.json', function ($C) use ($aid, $x) { $C[$aid] = $x; return $C; });
        wi_log('case_intake_cfg', ['account' => $aid, 'on' => $x['on']]); wi_json(['ok' => true, 'cfg' => wf_intake_cfg($aid)]); }
    $groups = []; foreach ((array) (wi_gateway('/archive/chats?months=6', null, $acc)['chats'] ?? []) as $ch) if (!empty($ch['group'])) $groups[(string) $ch['jid']] = (string) ($ch['name'] ?? '') ?: (string) $ch['jid'];
    foreach (wf_registry_groups($aid) as $j => $g) $groups[$j] ??= (string) ($g['name'] ?? $j);
    $wait = (array) (wi_read('case_intake.json', [])[$aid] ?? []);
    wi_json(['ok' => true, 'cfg' => wf_intake_cfg($aid), 'diag' => wf_intake_diag($aid), 'fields' => wf_intake_askable($aid), 'groups' => $groups, 'waiting' => count($wait)]);
}
