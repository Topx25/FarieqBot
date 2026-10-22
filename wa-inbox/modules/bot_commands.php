<?php
// WhatsApp Inbox — bot_commands: every # command of a number in one place (Bot → Commands), editable,
// and the owner's own commands (a fixed reply or an AI instruction) that the bot knows at once in groups and private chats.

/** The owner's own commands: [{tag, kind: reply|ai, text, where: all|groups|private, title, on}]. */
function wf_cmds_custom(string $aid): array { return array_values(array_filter((array) (wi_read('bot.json', [])[$aid]['commands'] ?? []), 'is_array')); }
/** The owner's command this message starts with (on, allowed in this kind of chat), or null. */
function wf_cmd_custom_which(string $aid, string $text, bool $group): ?array {
    $t = trim($text); if ($t === '' || mb_strpos($t, '#') === false) return null;
    $first = wf_intake_norm((string) preg_split('/\s+/u', $t)[0]);
    foreach (wf_cmds_custom($aid) as $c) { if (empty($c['on']) || ($c['where'] ?? 'all') === ($group ? 'private' : 'groups')) continue;
        if (wf_intake_norm((string) $c['tag']) === $first) return $c; }
    return null;
}
/** Runs the owner's command: the fixed reply, or the AI answer with the command's instruction. Returns the text sent ('' when nothing). */
function wf_cmd_custom_run(array $acc, array $c, string $jid, string $text, string $id, string $from = '', string $name = ''): string {
    $aid = (string) $acc['id']; $rest = trim(mb_substr(trim($text), mb_strlen((string) preg_split('/\s+/u', trim($text))[0])));
    if (($c['kind'] ?? 'reply') === 'ai') {
        $r = wf_bot_answer($acc, trim((string) $c['text'] . "\n\n" . $rest), [], '', null, ['jid' => $jid . '|' . $from]);
        $out = !empty($r['ok']) ? trim((string) $r['reply']) : '';
    } else $out = trim(strtr((string) $c['text'], ['{name}' => $name, '{text}' => $rest]));
    if ($out === '') return '';
    [$tagT, $tagM] = str_ends_with($jid, '@g.us') ? wf_group_tag($from) : ['', []];
    $s = wi_gateway('/chat/send', ['jid' => $jid, 'text' => $tagT . $out, 'quote_id' => $id ?: null, 'mentions' => $tagM, 'via' => 'bot'], $acc);
    wi_bot_log_add($aid, ['jid' => $jid, 'name' => $name, 'q' => mb_substr($text, 0, 300), 'reply' => $out, 'result' => !empty($s['ok']) ? 'sent' : 'send_failed', 'role' => 'command', 'group' => str_ends_with($jid, '@g.us'), 'at' => date('c')]);
    return $out;
}
/** The tag that sends a group question straight to the bot (business profile → ask_tag), '' when off. */
function wf_ask_tag(string $aid): string { return trim((string) (wf_pack($aid)['ask_tag'] ?? '')); }

/** Every # command of this number: id, tag, area, what it does, editable or not (and where it is edited instead). */
function wf_cmds_inventory(array $acc): array {
    $aid = (string) $acc['id']; $cc = wf_case_cfg($aid); $w = (array) ($cc['words'] ?? []); $P = wf_pack($aid); $L = [];
    $add = function (string $id, string $tag, string $area, string $what, string $arg = '', bool $edit = true, string $where = '') use (&$L) { if (trim($tag) !== '') $L[] = compact('id', 'tag', 'area', 'what', 'arg', 'edit', 'where'); };
    $add('intake.keyword', (string) wf_intake_cfg($aid)['keyword'], 'Cases', 'Open a new case from a group');
    $add('intake.lookup', wf_intake_lookup_kw($aid), 'Cases', 'Show a case file (name, number or tag)');
    foreach ((array) $cc['statuses'] as $k => $lbl) { if ($k === 'new' || ($k === 'archived' && !empty($w['archive']))) continue;
        $tag = (string) ($cc['cmds'][$k] ?? ('#' . preg_replace('/\s+/u', '_', trim(preg_replace('/[^\p{L}\p{N}\s_]+/u', '', (string) $lbl)))));
        $add('case.cmds.' . $k, $tag, 'Cases', 'Move a case to the stage:', trim((string) $lbl)); }
    if (!empty($w['archive'])) $add('case.words.archive', (string) $w['archive'], 'Cases', 'Archive a case');
    $add('case.words.menu', (string) ($w['menu'] ?? ''), 'Lists', 'The list of all commands');
    $add('case.words.menu_alias', (string) ($w['menu_alias'] ?? ''), 'Lists', 'Other names of the commands list (separated by ,)');
    foreach (['list' => 'The list word (with a list name after it)', 'shortcuts' => 'List of the files\' shortcuts', 'programs' => 'List of the programs', 'library' => 'List of the library'] as $k => $what) $add('case.words.' . $k, (string) ($w[$k] ?? ''), 'Lists', $what);
    foreach (wf_libcmd_words($aid) as $k => $c) $add('lib.' . $k, (string) $c, 'Library', 'Library command:', (string) $k);
    foreach (preg_split('/\R/u', (string) ($P['lib_tags'] ?? '')) as $i => $line) { $x = array_map('trim', explode('|', $line)); if (count($x) >= 2 && $x[0] !== '') $add('tags.' . $i, $x[0], 'Library', 'Section tag:', (string) ($x[2] ?? $x[1])); }
    $add('pack.ask_tag', (string) ($P['ask_tag'] ?? ''), 'Bot', 'A question in a group sent straight to the bot');
    $add('pack.fun_cmd', (string) ($P['fun_cmd'] ?? ''), 'Bot', 'Warm-up mode on (from a test number)');
    $add('pack.fun_off', (string) ($P['fun_off'] ?? ''), 'Bot', 'Warm-up mode off (from a test number)');
    $add('pack.analyze_cmd', (string) ($P['analyze_cmd'] ?? ''), 'Cases', 'Analyse a case by angle and its treatment progress: the command + ID, name or tag');
    $add('pack.continue_cmd', (string) ($P['continue_cmd'] ?? ''), 'Bot', 'Typed by the owner in a customer\'s chat: the bot answers there again (separated by ,)');
    $add('pack.status_cmd', (string) ($P['status_cmd'] ?? ''), 'Bot', 'The bot\'s status (from a test number or the own phone, separated by ,)');
    $add('pack.hold_cmd', (string) ($P['hold_cmd'] ?? ''), 'Bot', 'Stop the bot (from a test number)');
    $add('pack.resume_cmd', (string) ($P['resume_cmd'] ?? ''), 'Bot', 'Turn the bot back on (from a test number)');
    $add('pack.delete_cmd', (string) ($P['delete_cmd'] ?? ''), 'Bot', 'Delete a record for good: the command + ID or name (from a test number)');
    $add('pack.confirm_cmd', (string) ($P['confirm_cmd'] ?? ''), 'Bot', 'Confirm the delete (from a test number)');
    $add('pack.restart_cmd', (string) ($P['restart_cmd'] ?? ''), 'Bot', 'Restart the service (from a test number, separated by ,)');
    foreach ((array) (wi_read('media_meta.json', [])[$aid] ?? []) as $k => $m) if (trim((string) ($m['code'] ?? '')) !== '' && empty($m['hidden'])) $add('media.' . $k, (string) $m['code'], 'Files', 'Sends the file:', (string) ($m['title'] ?? $k));
    if (function_exists('wi_replies_for')) foreach (wi_replies_for($acc) as $r) if (trim((string) ($r['keyword'] ?? '')) !== '') $add('quick.' . ($r['id'] ?? ''), '#' . ltrim(trim((string) $r['keyword']), '#/'), 'Quick replies', 'Quick reply:', (string) ($r['title'] ?? ''), false, 'Quick replies');
    return $L;
}
/** The words of a command row (a list of names is split on commas), normalised. */
function wf_cmds_words(string $tag): array { $o = []; foreach (preg_split('/[,\x{060C}]+/u', $tag) as $x) if (($x = wf_intake_norm(trim($x))) !== '') $o[$x] = 1; return $o; }
/** Saves one command's new tag where it lives. Returns '' or the error. */
function wf_cmds_save(array $acc, string $id, string $tag): string {
    $aid = (string) $acc['id']; $tag = trim(mb_substr(preg_replace('/\s+/u', ' ', $tag), 0, 300));
    $multi = in_array($id, ['case.words.menu_alias', 'pack.restart_cmd', 'pack.status_cmd', 'pack.hold_cmd', 'pack.resume_cmd', 'pack.continue_cmd'], true);
    if (!$multi && $tag !== '' && !preg_match('/^#\S+$/u', $tag)) return 'A command is one word starting with #.';
    if (in_array($id, ['intake.keyword', 'case.words.menu', 'case.words.list'], true) && $tag === '') return 'This command cannot be empty.';
    $mine = wf_cmds_words($tag);
    foreach (wf_cmds_inventory($acc) as $r) { if ($r['id'] === $id) continue; if (array_intersect_key($mine, wf_cmds_words((string) $r['tag']))) return 'This command is already used.'; }
    foreach (wf_cmds_custom($aid) as $c) if (isset($mine[wf_intake_norm((string) $c['tag'])])) return 'This command is already used.';
    [$a, $k] = array_pad(explode('.', $id, 2), 2, '');
    if ($a === 'intake' && in_array($k, ['keyword', 'lookup'], true)) { wi_update('case_intake_cfg.json', function ($A) use ($aid, $k, $tag) { $A[$aid][$k] = $tag; return $A; }); return ''; }
    if ($a === 'case') { [$part, $key] = array_pad(explode('.', $k, 2), 2, ''); if (!in_array($part, ['cmds', 'words'], true) || $key === '') return 'Unknown command.';
        $full = (array) (wf_case_cfg($aid)[$part] ?? []); $full[$key] = $tag; // the whole list is kept, so the other words stay as they were
        wi_update('case_cfg.json', function ($A) use ($aid, $part, $full) { $A[$aid][$part] = $full; return $A; }); return ''; }
    if ($a === 'pack' && in_array($k, ['ask_tag', 'fun_cmd', 'fun_off', 'restart_cmd', 'status_cmd', 'continue_cmd', 'analyze_cmd', 'hold_cmd', 'resume_cmd', 'delete_cmd', 'confirm_cmd'], true)) { wi_update('bot.json', function ($A) use ($aid, $k, $tag) { $A[$aid]['pack'][$k] = $tag; return $A; }); return ''; }
    if ($a === 'lib') { $all = wf_libcmd_words($aid); if (!isset($all[$k])) return 'Unknown command.'; $all[$k] = $tag; wi_update('bot.json', function ($A) use ($aid, $all) { $A[$aid]['pack']['lib_cmds'] = $all; return $A; }); return ''; }
    if ($a === 'tags') { $lines = preg_split('/\R/u', (string) (wf_pack($aid)['lib_tags'] ?? '')); if ($tag === '' || !isset($lines[(int) $k])) return 'This command cannot be empty.';
        $x = array_map('trim', explode('|', $lines[(int) $k])); $x[0] = $tag; $lines[(int) $k] = implode(' | ', $x);
        wi_update('bot.json', function ($A) use ($aid, $lines) { $A[$aid]['pack']['lib_tags'] = implode("\n", $lines); return $A; }); return ''; }
    if ($a === 'media') { if ($tag === '') return 'This command cannot be empty.'; $ok = false;
        wi_update('media_meta.json', function ($A) use ($aid, $k, $tag, &$ok) { if (isset($A[$aid][$k])) { $A[$aid][$k]['code'] = wf_media_code_clean($tag); $ok = true; } return $A; }); return $ok ? '' : 'Unknown command.'; }
    return 'This command is edited in its own page.';
}
/** Saves the owner's own commands (the whole list). Returns '' or the error. */
function wf_cmds_custom_save(array $acc, array $list): string {
    $aid = (string) $acc['id']; $taken = []; foreach (wf_cmds_inventory($acc) as $r) $taken += wf_cmds_words((string) $r['tag']);
    $out = []; $seen = [];
    foreach (array_slice($list, 0, 100) as $c) { if (!is_array($c)) continue;
        $tag = trim((string) ($c['tag'] ?? '')); if ($tag === '') continue; if ($tag[0] !== '#') $tag = '#' . $tag;
        if (!preg_match('/^#\S{1,40}$/u', $tag)) return 'A command is one word starting with #.';
        $n = wf_intake_norm($tag); if (isset($seen[$n]) || isset($taken[$n])) return 'This command is already used.'; $seen[$n] = 1;
        $out[] = ['tag' => $tag, 'title' => mb_substr(trim((string) ($c['title'] ?? '')), 0, 80), 'kind' => ($c['kind'] ?? '') === 'ai' ? 'ai' : 'reply', 'text' => mb_substr(trim((string) ($c['text'] ?? '')), 0, 3000),
            'where' => in_array($c['where'] ?? '', ['groups', 'private'], true) ? $c['where'] : 'all', 'on' => !empty($c['on'])]; }
    wi_update('bot.json', function ($A) use ($aid, $out) { $A[$aid]['commands'] = $out; return $A; });
    return '';
}

function wf_api_bot_commands(string $api): void {
    if ($api !== 'bot_commands') return;
    $acc = wi_current(); if (!$acc) wi_json(['ok' => false, 'error' => 'No number chosen.'], 422);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') { $in = wf_post(); wf_admin();
        $err = isset($in['custom']) ? wf_cmds_custom_save($acc, (array) $in['custom']) : wf_cmds_save($acc, (string) ($in['id'] ?? ''), (string) ($in['tag'] ?? ''));
        if ($err !== '') wi_json(['ok' => false, 'error' => $err]);
        wi_log('bot_commands', ['account' => $acc['id'], 'id' => isset($in['custom']) ? 'custom' : (string) ($in['id'] ?? '')]); }
    wi_json(['ok' => true, 'list' => wf_cmds_inventory($acc), 'custom' => wf_cmds_custom((string) $acc['id'])]);
}
