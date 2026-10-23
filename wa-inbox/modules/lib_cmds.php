<?php
/* ---------- Library commands in a group (spiritual-healing numbers only) ----------
 * "#ارسال <name>" sends a file of the media library, "#برنامج <name>" a program, "#رقية <name>" a written ruqyah
 * (copied exactly, never retyped), "#الأوامر" the list of commands. The words come from the business profile
 * (Business settings → its preset "lib_cmds"); a profile without them has no library commands. */

function wf_libcmd_words(string $aid): array { return array_filter((array) (wf_pack($aid)['lib_cmds'] ?? [])); }
/** Which library command this text is, or ''. */
function wf_libcmd_which(string $aid, string $text): string {
    if (mb_strpos($text, '#') === false) return '';
    foreach (wf_libcmd_words($aid) as $k => $w) if (wf_intake_has($text, (string) $w)) return (string) $k;
    return '';
}
/** The media library of a number (not the deleted ones): key, kind, title, name, caption, tags. */
function wf_libcmd_media(array $acc): array {
    $aid = (string) $acc['id']; $root = dirname(__DIR__, 2) . '/storage/'; $meta = (array) (wi_read('media_meta.json', [])[$aid] ?? []);
    $names = (array) (wi_gateway('/archive/media-kinds', null, $acc)['names'] ?? []); $out = [];
    $keys = []; foreach (array_merge(glob($root . 'inbox_media/' . $aid . '/*.*') ?: [], glob($root . 'inbox_media/' . $aid . '/*/*.*') ?: []) as $p) $keys[] = substr($p, strlen($root));
    foreach ((array) wi_read('uploads.json', []) as $f => $a) if ($a === $aid) $keys[] = 'inbox_uploads/' . $f;
    foreach ($keys as $k) { $m = (array) ($meta[$k] ?? []); if (!empty($m['hidden']) || !empty($m['vault']) || !is_file($root . $k)) continue;
        $out[$k] = ['key' => $k, 'title' => (string) ($m['title'] ?? ''), 'name' => preg_replace('/\.[A-Za-z0-9]{2,4}$/', '', (string) ($names[$k] ?? '')), 'caption' => (string) ($m['caption'] ?? ''), 'tags' => implode(' ', (array) ($m['tags'] ?? []))]; }
    return $out;
}
/** Best matches of a name among labels: exact, then starts with, then contains (all words). */
function wf_libcmd_match(string $q, array $labels): array {
    $q = trim(wf_intake_norm($q)); if ($q === '') return [];
    $words = array_filter(array_map(fn($w) => mb_strlen($w) > 3 && str_starts_with($w, "\u{648}") ? mb_substr($w, 1) : $w, preg_split('/\s+/u', $q))); $exact = []; $start = []; $all = []; // a leading "و" is the word "and", not part of the name
    foreach ($labels as $k => $ls) foreach ((array) $ls as $l) { $l = trim(wf_intake_norm((string) $l)); if ($l === '') continue;
        if ($l === $q) { $exact[$k] = 1; break; }
        if (str_starts_with($l, $q)) $start[$k] = 1;
        elseif (!array_filter($words, fn($w) => mb_strpos($l, $w) === false)) $all[$k] = 1; }
    return array_keys($exact ?: ($start ?: $all));
}
/** The nearest tags to a name: section tags, files' shortcuts, texts' and programs' tags — those sharing words with the query first, else the section tags. */
function wf_libtag_suggest(string $aid, string $q): array {
    $words = array_values(array_filter(preg_split('/\s+/u', trim(wf_intake_norm($q))), fn($w) => mb_strlen($w) > 2)); $cand = [];
    foreach (wf_libtags($aid) as $x) $cand[$x['tag']] = [$x['title'], 1];
    foreach ((array) (wi_read('media_meta.json', [])[$aid] ?? []) as $m) if (!empty($m['code']) && empty($m['hidden'])) $cand[(string) $m['code']] = [(string) ($m['title'] ?? ''), 0];
    foreach (wf_lib_texts($aid) as $t) if (trim((string) ($t['code'] ?? '')) !== '') $cand[(string) $t['code']] = [(string) $t['title'] . ' ' . (string) ($t['keywords'] ?? ''), 0];
    foreach (wf_programs($aid) as $p) if (trim((string) ($p['code'] ?? '')) !== '') $cand[(string) $p['code']] = [(string) ($p['name'] ?? ''), 0];
    $score = []; foreach ($cand as $tag => [$label, $sec]) { $blob = wf_intake_norm($tag . ' ' . $label); $n = 0; foreach ($words as $w) if (mb_strpos($blob, $w) !== false) $n++; if ($n) $score[$tag] = $n * 10 + $sec; }
    arsort($score); $out = array_slice(array_keys($score), 0, 2); // the closest specific tags, then the section tags
    foreach (wf_libtags($aid) as $x) if (count($out) < 6 && !in_array($x['tag'], $out, true)) $out[] = $x['tag'];
    return $out;
}
/** "Not found" with the nearest tags to try instead. */
function wf_libcmd_nf(string $aid, string $q): string {
    $t = wf_libtag_suggest($aid, $q);
    return wf_msg('lib_not_found', ['query' => mb_substr($q, 0, 40), 'hint' => $t ? wf_msg('lib_try_tags', ['tags' => implode('  ', $t)]) : '']);
}
/** "#رقية X مسموعة": the audio word (a setting) in the name means the recording, not the written text. Returns the name without it, or null. */
function wf_libcmd_audio_name(string $aid, string $q): ?string {
    $set = array_filter(array_map(fn($w) => trim(wf_intake_norm($w)), preg_split('/[|\n]/u', (string) (wf_pack($aid)['lib_audio_words'] ?? wf_pack_presets()['general']['lib_audio_words'] ?? ''))));
    $words = preg_split('/\s+/u', trim($q)); $keep = []; $found = false;
    foreach ($words as $w) { if (in_array(trim(wf_intake_norm($w)), $set, true)) $found = true; else $keep[] = $w; }
    return $found ? trim(implode(' ', $keep)) : null;
}
function wf_libcmd_hook(array $acc, string $jid, string $text, string $id, string $from = ''): void {
    $aid = (string) $acc['id']; $cmd = wf_libcmd_which($aid, $text); if ($cmd === '') return;
    [$tagT, $tagM] = wf_group_tag($from); $say = fn(string $t, array $extra = []) => wi_gateway('/chat/send', ['jid' => $jid, 'text' => isset($extra['attachment']) ? $t : $tagT . $t, 'quote_id' => $id, 'mentions' => isset($extra['attachment']) ? [] : $tagM, 'via' => 'bot'] + $extra, $acc); // a file goes with its own caption
    $q = trim(preg_replace(['/#\S+/u', '/@\d+/', '/["«»“”]/u'], '', $text)); // the name, without the command or tags
    $W = wf_libcmd_words($aid);
    if ($cmd === 'menu' || $q === '') { $say(wf_msg('lib_menu', ['list' => implode("\n", array_map(fn($k, $w) => '• ' . $w . ' …', array_keys(array_diff_key($W, ['menu' => 1])), array_diff_key($W, ['menu' => 1])))])); return; }
    $many = function (array $labels) use ($say, $q) { $say(wf_msg('lib_many', ['query' => mb_substr($q, 0, 40), 'list' => implode("\n", array_map(fn($l) => '• ' . $l, array_slice($labels, 0, 8)))])); };
    if (wf_media_code_in($aid, $text) !== '') { wf_media_code_send($acc, $jid, $text, $id); wi_log('lib_cmd', ['account' => $aid, 'group' => $jid, 'cmd' => 'send']); return; } // "#ارسال #رقية عامة": the file with that code
    if ($cmd === 'send' && preg_match_all('/#\S+/u', $text, $mm)) foreach ($mm[0] as $tok) if (wf_media_code_find($aid, $tok) !== '') { wf_media_code_send($acc, $jid, $tok, $id); wi_log('lib_cmd', ['account' => $aid, 'group' => $jid, 'cmd' => 'send']); return; } // "#ارسال #رقية-عامة"
    if ($cmd === 'send') {
        $items = wf_libcmd_media($acc); $hit = wf_libcmd_match($q, array_map(fn($x) => [$x['title'], $x['name'], $x['caption'], $x['tags']], $items));
        if (!$hit) { $say(wf_libcmd_nf($aid, $q)); return; }
        if (count($hit) > 1) { $many(array_map(fn($k) => $items[$k]['title'] ?: ($items[$k]['name'] ?: mb_substr($items[$k]['caption'], 0, 50)), $hit)); return; }
        $x = $items[$hit[0]]; $r = $say($x['caption'], ['attachment' => $x['key']]);
        if (!empty($r['ok'])) wi_update('media_meta.json', function ($M) use ($aid, $x) { $M[$aid][$x['key']]['used'] = (int) ($M[$aid][$x['key']]['used'] ?? 0) + 1; return $M; });
    } elseif ($cmd === 'program') {
        $P = []; foreach (wf_programs($aid) as $p) $P[(string) $p['id']] = $p; $hit = wf_libcmd_match($q, array_map(fn($p) => [$p['name'] ?? ''], $P));
        if (!$hit) { $say(wf_libcmd_nf($aid, $q)); return; }
        if (count($hit) > 1) { $many(array_map(fn($k) => (string) $P[$k]['name'], $hit)); return; }
        $say(wf_program_message($P[$hit[0]]));
    } elseif ($cmd === 'text') {
        if (($an = wf_libcmd_audio_name($aid, $q)) !== null && $an !== '') { // the recording of it
            $inv = array_values(array_filter(wf_lib_inventory($acc)['items'], fn($i) => in_array($i['kind'], ['audio', 'voice'], true))); $ah = wf_libcmd_match($an, array_map(fn($i) => [$i['title'], $i['name'], $i['caption'], $i['code']], $inv));
            if (!$ah) { $say(wf_libcmd_nf($aid, $an)); return; }
            if (count($ah) > 1) { $many(array_map(fn($k) => $inv[$k]['title'] ?: ($inv[$k]['name'] ?: mb_substr($inv[$k]['caption'], 0, 50)), array_slice($ah, 0, 8))); return; }
            $say($inv[$ah[0]]['caption'], ['attachment' => $inv[$ah[0]]['key']]); wi_log('lib_cmd', ['account' => $aid, 'group' => $jid, 'cmd' => 'audio']); return; }
        $words = array_values(array_filter(preg_split('/\s+/u', $q), fn($w) => mb_strlen($w) >= 3)); usort($words, fn($x, $y) => mb_strlen($y) <=> mb_strlen($x));
        $g = []; $seenG = []; $words = array_map(fn($w) => mb_strlen($w) > 3 && str_starts_with($w, "\u{648}") ? mb_substr($w, 1) : $w, $words); usort($words, fn($x, $y) => mb_strlen($y) <=> mb_strlen($x)); // "والحسد" is "الحسد"
        foreach (array_unique(array_merge([$q], array_slice($words, 0, 2))) as $try) foreach ((array) (wi_gateway('/library?type=all&group=1&q=' . rawurlencode(mb_strtolower($try)), null, $acc)['groups'] ?? []) as $cg) { $ck = (string) ($cg['best']['key'] ?? $cg['title'] ?? ''); if (!isset($seenG[$ck])) { $seenG[$ck] = 1; $g[] = $cg; } } // the whole name and its main words: every candidate once
        foreach (wf_lib_text_groups($aid) as $mg) { $blob = wf_intake_norm($mg['title'] . ' ' . $mg['keywords'] . ' ' . $mg['code'] . ' ' . $mg['best']['text']); $nq = trim(wf_intake_norm($q)); if ($nq !== '' && !array_filter(preg_split('/\s+/u', $nq), fn($w) => mb_strpos($blob, $w) === false)) $g[] = $mg; } // texts written by hand: by title, keywords or text
        $T = []; foreach ($g as $i => $x) $T[$i] = ['title' => trim(mb_substr((string) ($x['title'] ?? ''), 0, 70)), 'text' => (string) ($x['best']['text'] ?? ''), 'kw' => (string) ($x['keywords'] ?? ''), 'cover' => (string) ($x['cover'] ?? '')];
        $hit = wf_libcmd_match($q, array_map(fn($x) => [$x['title'], $x['kw']], $T)); // the name is in its title or keywords
        $byText = false; if (!$hit) { $hit = wf_libcmd_match($q, array_map(fn($x) => [$x['text']], $T)); $byText = (bool) $hit; } // else every word is in its text — only a hint: it is never sent as the answer
        if ($byText) { $say(wf_msg('lib_close', ['query' => mb_substr($q, 0, 40), 'list' => implode("\n", array_map(fn($k) => '• ' . $T[$k]['title'], array_slice($hit, 0, 5))), 'hint' => ($t = wf_libtag_suggest($aid, $q)) ? wf_msg('lib_try_tags', ['tags' => implode('  ', $t)]) : ''])); return; }
        if (!$hit) { $say(wf_libcmd_nf($aid, $q)); return; }
        $kind = trim(wf_intake_norm(ltrim((string) ($W['text'] ?? ''), '#'))); // "#رقية العقد": the word after the command is the name, and the text must be of the kind the command names
        if (count($hit) === 1 && $kind !== '' && mb_strpos(wf_intake_norm($T[$hit[0]]['title'] . ' ' . ($g[$hit[0]]['section'] ?? '') . ' ' . ($g[$hit[0]]['topic'] ?? '')), $kind) === false) { // one text, but not of that kind: shown as the closest, never sent
            $say(wf_msg('lib_close', ['query' => mb_substr($q, 0, 40), 'list' => implode("\n", array_map(fn($k) => '• ' . $T[$k]['title'], array_slice(array_values(array_unique(array_merge($hit, array_keys($T)))), 0, 5))), 'hint' => ($tg = wf_libtag_suggest($aid, $q)) ? wf_msg('lib_try_tags', ['tags' => implode('  ', $tg)]) : ''])); return; }
        if (count($hit) > 1) { $many(array_map(fn($k) => $T[$k]['title'], array_slice($hit, 0, 8))); return; }
        if ($T[$hit[0]]['cover'] !== '') { $say(mb_substr($T[$hit[0]]['text'], 0, 3900), ['attachment' => $T[$hit[0]]['cover']]); wi_log('lib_cmd', ['account' => $aid, 'group' => $jid, 'cmd' => $cmd]); return; } // its cover goes with it
        $say(mb_substr($T[$hit[0]]['text'], 0, 3900)); // copied as it was written, never retyped
    }
    wi_log('lib_cmd', ['account' => $aid, 'group' => $jid, 'cmd' => $cmd]);
}

/* ---------- File shortcuts: a library file has its own code ("#shortcut-name"); writing it in a chat or group sends the file ---------- */
function wf_media_code_clean(string $c): string { $c = trim(preg_replace('/\s+/u', '-', trim($c))); if ($c === '') return ''; return mb_substr(str_starts_with($c, '#') ? $c : '#' . $c, 0, 60); }
/** Why a tag cannot be used (a section tag, a quick reply's keyword or another file's shortcut already has it), or ''. */
function wf_tag_taken(string $aid, string $tag, string $exceptKey = '', bool $skipSections = false): string {
    $want = str_replace(['-', ' '], '', wf_intake_norm(wf_media_code_clean($tag))); if ($want === '' || $want === '#') return '';
    if (!$skipSections) foreach (wf_libtags($aid) as $x) if (wf_libtag_key($x['tag']) === wf_libtag_key($tag)) return 'This tag is already a section tag.';
    $k = wf_media_code_find($aid, $tag); if ($k !== '' && $k !== $exceptKey) return 'This shortcut is already used by another file.';
    foreach (wf_programs($aid) as $p) if (('prog:' . ($p['id'] ?? '')) !== $exceptKey && trim((string) ($p['code'] ?? '')) !== '' && str_replace(['-', ' '], '', wf_intake_norm(wf_media_code_clean((string) $p['code']))) === $want) return 'This tag is already used by a program.';
    foreach (wf_lib_texts($aid) as $x) if (('text:' . ($x['id'] ?? '')) !== $exceptKey && trim((string) ($x['code'] ?? '')) !== '' && str_replace(['-', ' '], '', wf_intake_norm(wf_media_code_clean((string) $x['code']))) === $want) return 'This tag is already used by a library text.';
    $acc = wi_accounts()[$aid] ?? null;
    if ($acc && function_exists('wi_replies_for')) foreach (wi_replies_for($acc) as $r) if (str_replace(['-', ' '], '', wf_intake_norm(wf_media_code_clean((string) ($r['keyword'] ?? '')))) === $want) return 'This tag is already a quick reply keyword.';
    return '';
}
/** True the first time this message (its id, or its text in this minute) asks for a tag; false when it was already answered. */
function wf_code_once(string $aid, string $jid, string $text, string $quote): bool {
    $k = substr(md5($aid . '|' . $jid . '|' . ($quote !== '' ? $quote : trim($text) . '|' . intdiv(time(), 60))), 0, 16); $first = false;
    wi_update('code_done.json', function ($D) use ($k, &$first) { $D = array_filter((array) $D, fn($t) => $t > time() - 900); if (!isset($D[$k])) { $D[$k] = time(); $first = true; } return $D; });
    return $first;
}
/** The key of the file whose code matches (spelling variants alike), or ''. */
function wf_media_code_find(string $aid, string $code): string {
    $want = str_replace(['-', ' '], '', wf_intake_norm(wf_media_code_clean($code))); if ($want === '' || $want === '#') return '';
    foreach ((array) (wi_read('media_meta.json', [])[$aid] ?? []) as $k => $m) { $c = (string) ($m['code'] ?? ''); if ($c !== '' && empty($m['hidden']) && empty($m['vault']) && str_replace(['-', ' '], '', wf_intake_norm($c)) === $want) return (string) $k; }
    return '';
}
/** The file a message names by its code: the code alone ("#رقية-عامة"), written with spaces ("#رقية عامة"), or after the send word ("#ارسال #رقية عامة"). */
function wf_media_code_in(string $aid, string $text): string {
    $t = trim($text); if ($t === '' || mb_strpos($t, '#') === false || mb_strlen($t) > 80) return '';
    $send = (string) (wf_libcmd_words($aid)['send'] ?? '');
    if ($send !== '' && wf_intake_has($t, $send)) $t = trim(preg_replace('/' . preg_quote($send, '/') . '/u', '', $t, 1) ?? $t); // the send word itself is not part of the code
    if ($t === '' || $t[0] !== '#') return '';
    return wf_media_code_find($aid, $t);
}
/** The program whose tag this message is (the tag alone), or null. */
function wf_program_code_in(string $aid, string $text): ?array {
    $t = trim($text); if ($t === '' || $t[0] !== '#' || mb_strlen($t) > 70) return null; $want = str_replace(['-', ' '], '', wf_intake_norm(wf_media_code_clean($t)));
    foreach (wf_programs($aid) as $p) if (trim((string) ($p['code'] ?? '')) !== '' && str_replace(['-', ' '], '', wf_intake_norm(wf_media_code_clean((string) $p['code']))) === $want) return $p;
    return null;
}
/** The program as one message: its name, description and every day. */
function wf_program_message(array $p): string {
    $days = implode("\n\n", array_map(fn($d) => wf_msg('lib_program_day', ['day' => (string) $d['day'], 'text' => (string) $d['text']]), (array) ($p['days'] ?? [])));
    return mb_substr('📚 ' . $p['name'] . (trim((string) ($p['desc'] ?? '')) !== '' ? "\n" . $p['desc'] : '') . "\n\n" . $days, 0, 3900);
}
/** A message that names a file's code: the file is sent there (with its caption). True when sent. */
function wf_media_code_send(array $acc, string $jid, string $text, string $quote = ''): bool {
    if (function_exists('wf_rec_used') && ($wf_c = trim($text)) !== '' && $wf_c[0] === '#' && mb_strlen($wf_c) <= 70 && (wf_program_code_in((string) $acc['id'], $wf_c) || wf_lib_text_code_in((string) $acc['id'], $wf_c) || wf_media_code_in((string) $acc['id'], $wf_c) !== '')) wf_rec_used((string) $acc['id'], $wf_c); // how often each item is sent (the suggestions list the most used)
    if ((wf_libtag_which((string) $acc['id'], $text) || wf_program_code_in((string) $acc['id'], $text) || wf_lib_text_code_in((string) $acc['id'], $text) || wf_media_code_in((string) $acc['id'], $text) !== '') && !wf_code_once((string) $acc['id'], $jid, $text, $quote)) return true; // the same message delivered twice, or caught by two paths: answered once
    if (wf_libtag_which((string) $acc['id'], $text)) return wf_libtag_send($acc, $jid, $text, $quote); // a section tag: the list of that section
    if ($lt = wf_lib_text_code_in((string) $acc['id'], $text)) return wf_lib_text_send($acc, $jid, $lt, $quote) || true; // a library text's tag: the text with its cover
    if (($prog = wf_program_code_in((string) $acc['id'], $text)) && !str_ends_with($jid, '@g.us')) { // a private chat: it follows the program (its days arrive one by one), the person is told
        $aidP = (string) $acc['id']; $new = wf_program_enroll($aidP, $jid, $prog, 'tag'); $d1 = (array) (((array) ($prog['days'] ?? []))[0] ?? []); $n = count((array) ($prog['days'] ?? []));
        foreach ((array) ($prog['days'] ?? []) as $dd) if ((int) ($dd['day'] ?? 0) === 1) $d1 = $dd; // day 1 when there is one, else the program's first step
        if ($new && $d1) wf_program_mark_sent($aidP, $jid, (string) $prog['id'], (int) $d1['day']); else $d1 = [];
        $msg = $new ? wf_msg('program_enrolled', ['name' => (string) $prog['name'], 'days' => (string) $n, 'day' => (string) ($d1['day'] ?? 1), 'text' => (string) ($d1['text'] ?? ''), 'rest' => $n > 1 ? wf_msg('program_rest', []) : '']) : wf_msg('program_already', ['name' => (string) $prog['name']]);
        $r = wi_gateway('/chat/send', ['jid' => $jid, 'text' => trim($msg), 'via' => 'bot'] + ($quote !== '' ? ['quote_id' => $quote] : []), $acc);
        wi_log('program_code', ['account' => (string) $acc['id'], 'jid' => $jid, 'enrolled' => $new, 'sent' => !empty($r['ok'])]); return true; }
    if ($prog = wf_program_code_in((string) $acc['id'], $text)) { $r = wi_gateway('/chat/send', ['jid' => $jid, 'text' => wf_program_message($prog), 'via' => 'bot'] + ($quote !== '' ? ['quote_id' => $quote] : []), $acc); wi_log('program_code', ['account' => (string) $acc['id'], 'jid' => $jid, 'sent' => !empty($r['ok'])]); return true; } // a program's tag: the program
    $key = wf_media_code_in((string) $acc['id'], $text); if ($key === '') return false;
    $m = (array) (wi_read('media_meta.json', [])[(string) $acc['id']][$key] ?? []);
    $r = wi_gateway('/chat/send', ['jid' => $jid, 'text' => (string) ($m['caption'] ?? ''), 'attachment' => $key, 'via' => 'bot'] + ($quote !== '' ? ['quote_id' => $quote] : []), $acc);
    if (!empty($r['ok'])) wi_update('media_meta.json', function ($M) use ($acc, $key) { $a = (string) $acc['id']; $M[$a][$key]['used'] = (int) ($M[$a][$key]['used'] ?? 0) + 1; return $M; });
    wi_log('media_code', ['account' => (string) $acc['id'], 'jid' => $jid, 'sent' => !empty($r['ok'])]);
    return !empty($r['ok']);
}

/* ---------- Section tags: writing "#صوتيات" in a chat or group lists that section with the shortcuts of its files ----------
 * One line per tag in the business profile (Learning → AI instructions → lib_tags): "#tag | scope | title".
 * Scopes: library (every file), audio, video, images, documents, programs. An empty field turns the tags off. */
function wf_libtags(string $aid): array {
    $raw = (string) (wf_pack($aid)['lib_tags'] ?? wf_pack_presets()['general']['lib_tags'] ?? ''); $out = [];
    foreach (preg_split('/\R/u', $raw) as $l) { $x = array_map('trim', explode('|', $l)); $t = wf_media_code_clean($x[0] ?? '');
        if ($t === '' || !in_array($x[1] ?? '', ['library', 'audio', 'video', 'images', 'documents', 'programs', 'texts'], true)) continue; $out[] = ['tag' => $t, 'scope' => $x[1], 'title' => $x[2] ?? ltrim($t, '#')]; }
    return $out;
}
/** The section tag this message is (the tag alone, spelling variants alike), or null. */
function wf_libtag_key(string $tag): string { return preg_replace('/^#\x{0627}\x{0644}/u', '#', str_replace(['-', ' '], '', wf_intake_norm(wf_media_code_clean($tag)))); } // "#المكتبة" = "#مكتبة"
function wf_libtag_which(string $aid, string $text): ?array {
    $t = trim($text); if ($t === '' || $t[0] !== '#' || mb_strlen($t) > 60) return null;
    $want = wf_libtag_key($t);
    foreach (wf_libtags($aid) as $x) if (wf_libtag_key($x['tag']) === $want) return $x;
    return null;
}
/** Sends the list of a section: each file's name and shortcut (programs: their names). True when it was a section tag. */
function wf_libtag_send(array $acc, string $jid, string $text, string $quote = ''): bool {
    $aid = (string) $acc['id']; $x = wf_libtag_which($aid, $text); if (!$x) return false;
    $lines = [];
    if ($x['scope'] === 'texts') { foreach (wf_lib_texts($aid) as $t) $lines[] = '• ' . $t['title'] . (trim((string) ($t['code'] ?? '')) !== '' ? ' — ' . $t['code'] : '');
        foreach ((array) (wi_gateway('/library?type=sections&min=60', null, $acc)['groups'] ?? []) as $g) if (empty($g['audio']) && trim((string) ($g['title'] ?? '')) !== '' && count($lines) < 60) $lines[] = '• ' . trim(mb_substr((string) $g['title'], 0, 70)); }
    elseif ($x['scope'] === 'programs') foreach (wf_programs($aid) as $p) $lines[] = '• ' . (string) ($p['name'] ?? '') . (trim((string) ($p['code'] ?? '')) !== '' ? ' — ' . $p['code'] : '');
    else { $inv = wf_lib_inventory($acc)['items']; $want = ['audio' => ['audio', 'voice'], 'video' => ['video', 'gif'], 'images' => ['image', 'sticker'], 'documents' => ['document']][$x['scope']] ?? null;
        foreach ($inv as $i) { if ($want && !in_array($i['kind'], $want, true)) continue; $n = $i['title'] ?: ($i['name'] ?: mb_substr((string) $i['caption'], 0, 40)); if ($n === '' && $i['code'] === '') continue;
            $lines[] = '• ' . ($n !== '' ? $n : $i['kind']) . ($i['code'] !== '' ? ' — ' . $i['code'] : ''); } }
    $total = count($lines); $lines = array_slice($lines, 0, 60);
    $say = wf_msg($lines ? 'lib_section' : 'lib_section_empty', ['title' => $x['title'], 'count' => (string) $total, 'list' => implode("\n", $lines)]);
    $r = wi_gateway('/chat/send', ['jid' => $jid, 'text' => mb_substr($say, 0, 3900), 'via' => 'bot'] + ($quote !== '' ? ['quote_id' => $quote] : []), $acc);
    wi_log('lib_tag', ['account' => $aid, 'jid' => $jid, 'scope' => $x['scope'], 'sent' => !empty($r['ok'])]);
    return true;
}

/* ---------- Library inventory: everything in the library and every shortcut, built fresh on each request (always the latest) ---------- */
function wf_lib_inventory(array $acc): array {
    $aid = (string) $acc['id']; $root = dirname(__DIR__, 2) . '/storage/'; $meta = (array) (wi_read('media_meta.json', [])[$aid] ?? []);
    $mk = wi_gateway('/archive/media-kinds', null, $acc); $kinds = (array) ($mk['kinds'] ?? []); $names = (array) ($mk['names'] ?? []);
    $items = []; $keys = [];
    foreach (array_merge(glob($root . 'inbox_media/' . $aid . '/*.*') ?: [], glob($root . 'inbox_media/' . $aid . '/*/*.*') ?: []) as $pf) { $f = basename($pf); if (preg_match('/^[A-Za-z0-9_.-]{1,80}$/', $f) && !str_ends_with($f, '.part') && is_file($pf)) $keys[] = substr($pf, strlen($root)); }
    foreach ((array) wi_read('uploads.json', []) as $f => $a) if ($a === $aid) $keys[] = 'inbox_uploads/' . $f;
    foreach ($keys as $k) { $m = (array) ($meta[$k] ?? []); if (!empty($m['hidden']) || !empty($m['vault']) || !is_file($root . $k)) continue;
        $ext = strtolower(pathinfo($k, PATHINFO_EXTENSION)); $kind = (string) ($kinds[$k] ?? (in_array($ext, ['jpg', 'png'], true) ? 'image' : ($ext === 'webp' ? 'sticker' : (in_array($ext, ['mp4', '3gp', 'mov'], true) ? 'video' : (in_array($ext, ['ogg', 'opus', 'mp3', 'm4a', 'aac', 'wav'], true) ? 'audio' : 'document')))));
        $items[] = ['key' => $k, 'kind' => $kind, 'title' => (string) ($m['title'] ?? ''), 'name' => (string) ($names[$k] ?? ''), 'code' => (string) ($m['code'] ?? ''), 'folder' => (string) ($m['folder'] ?? ''),
            'caption' => mb_substr((string) ($m['caption'] ?? ''), 0, 120), 'size' => (int) filesize($root . $k), 'used' => (int) ($m['used'] ?? 0), 'at' => date('Y-m-d', (int) filemtime($root . $k))]; }
    usort($items, fn($a, $b) => [$a['code'] === '', $a['folder'], $a['kind'], $a['title'] ?: $a['name']] <=> [$b['code'] === '', $b['folder'], $b['kind'], $b['title'] ?: $b['name']]); // shortcuts first
    $quick = []; foreach (function_exists('wi_replies_for') ? wi_replies_for($acc) : [] as $r) $quick[] = ['keyword' => trim((string) ($r['keyword'] ?? '')), 'title' => (string) ($r['title'] ?? '')];
    $progs = array_map(fn($p) => ['name' => (string) ($p['name'] ?? ''), 'days' => count((array) ($p['days'] ?? []))], function_exists('wf_programs') ? wf_programs($aid) : []);
    $byKind = []; $size = 0; foreach ($items as $x) { $byKind[$x['kind']] = ($byKind[$x['kind']] ?? 0) + 1; $size += $x['size']; }
    return ['at' => date('c'), 'items' => $items, 'quick' => $quick, 'programs' => $progs, 'summary' => ['files' => count($items), 'size' => $size, 'by_kind' => $byKind, 'with_code' => count(array_filter($items, fn($x) => $x['code'] !== '')), 'named' => count(array_filter($items, fn($x) => $x['title'] !== ''))]];
}
/** The inventory as WhatsApp text: the counts, every shortcut with its name, the quick replies and the programs. */
function wf_lib_inventory_text(array $acc): string {
    $v = wf_lib_inventory($acc); $s = $v['summary']; $L = [];
    foreach ($v['items'] as $x) if ($x['code'] !== '') $L[] = '• ' . $x['code'] . ' — ' . ($x['title'] ?: ($x['name'] ?: $x['kind']));
    return wf_msg('lib_inventory', ['files' => (string) $s['files'], 'size' => (string) round($s['size'] / 1048576) . ' MB', 'codes' => (string) $s['with_code'], 'named' => (string) $s['named'],
        'shortcuts' => $L ? implode("\n", array_slice($L, 0, 120)) : '—', 'quick' => $v['quick'] ? implode("\n", array_map(fn($q) => '• ' . ($q['keyword'] !== '' ? '#' . ltrim($q['keyword'], '#/') . ' — ' : '') . $q['title'], array_slice($v['quick'], 0, 60))) : '—',
        'programs' => $v['programs'] ? implode("\n", array_map(fn($p) => '• ' . $p['name'] . ' (' . $p['days'] . ')', $v['programs'])) : '—']);
}

