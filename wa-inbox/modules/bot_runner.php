<?php
// WhatsApp Inbox — bot_runner (split out of features.php in v3.8, code unchanged)

/* ---------- Bot runner (cron): drafts or automatic replies, with safety limits ---------- */
function wf_bot_run_cfg(string $aid): array {
    return ((wi_read('bot.json', [])[$aid]['run'] ?? []) + ['mode' => 'off', 'min_conf' => 0.8, 'from' => 9, 'to' => 23, 'per_hour' => 20, 'human_pause' => 6, 'human_wait' => 10, 'dream_wait' => 120, 'case_private' => true, 'case_private_new' => false, 'since' => null, 'test' => [],
        'gather_secs' => 45, 'quiet_secs' => 6, 'chat_per_hour' => 12, 'group_person_hour' => 0, 'repeat_sim' => 0.7, 'group_gap' => 120, 'fun_gap' => 45, 'repeat_retry' => true, 'max_clarify' => 1, 'stop_words' => 'وقف، توقف، كفاية، بس كده، stop', 'typing_min' => 2, 'typing_max' => 8, 'warmup_until' => '', 'warmup_per_hour' => 5, 'crisis_pause_h' => 12,
        'scope' => 'private', 'groups_mode' => 'selected', 'groups' => [], 'group_default' => ['mode' => 'ai', 'trigger' => 'mention', 'text' => '', 'cooldown' => 24]]);
}
/** Chats where the bot is paused with /stop (until /start). */
function wf_bot_paused(string $aid): array { return wi_read('bot_paused.json', [])[$aid] ?? []; }
function wf_bot_pause(string $aid, string $jid, bool $pause, string $by, int $hours = 0): void {
    $all = wi_read('bot_paused.json', []);
    if ($pause) $all[$aid][$jid] = ['at' => date('c'), 'by' => $by] + ($hours > 0 ? ['until' => date('c', time() + $hours * 3600)] : []); else unset($all[$aid][$jid]); // with hours: the bot comes back by itself
    wi_write('bot_paused.json', $all);
    // /start = "answer here", even if the team is talking in this chat; /stop removes it.
    wi_update('bot_forced.json', function ($F) use ($pause, $aid, $jid, $by) { if ($pause) unset($F[$aid][$jid]); else $F[$aid][$jid] = ['at' => date('c'), 'by' => $by]; return $F; });
    wi_bot_log_add($aid, ['jid' => $jid, 'at' => date('c'), 'result' => $pause ? 'paused' : 'resumed', 'q' => $pause ? '/stop' : '/start', 'reply' => '', 'why' => $by]);
    if ($pause) { wi_update('bot_drafts.json', function ($d) use ($aid, $jid) { unset($d[$aid][$jid]); return $d; }); }
}
function wf_bot_drafts(string $aid): array { return wi_read('bot_drafts.json', [])[$aid] ?? []; }
/** Which groups the bot works in and how: 'ai' (interactive) or 'fixed' (a set message); trigger all / questions / mention. */
function wf_bot_group_cfg(array $c, string $gjid): ?array {
    if (($c['groups_mode'] ?? 'selected') === 'all') return ($c['groups'][$gjid] ?? []) + (array) $c['group_default'];
    return isset($c['groups'][$gjid]) ? $c['groups'][$gjid] + (array) $c['group_default'] : null;
}
function wf_clean_gcfg(array $g): array {
    return ['mode' => ($g['mode'] ?? '') === 'fixed' ? 'fixed' : 'ai', 'trigger' => in_array($g['trigger'] ?? '', ['all', 'questions', 'mention'], true) ? $g['trigger'] : 'mention',
        'text' => mb_substr(trim((string) ($g['text'] ?? '')), 0, 2000), 'cooldown' => max(1, min(720, (int) ($g['cooldown'] ?? 24))), 'name' => mb_substr((string) ($g['name'] ?? ''), 0, 120),
        'welcome' => !empty($g['welcome']) && $g['welcome'] !== '0', 'welcome_text' => mb_substr(trim((string) ($g['welcome_text'] ?? '')), 0, 2000),
        'learn' => !empty($g['learn']) && $g['learn'] !== '0',
        'visitors' => !empty($g['visitors']) && $g['visitors'] !== '0', 'bye' => !empty($g['bye']) && $g['bye'] !== '0', 'bye_text' => mb_substr(trim((string) ($g['bye_text'] ?? '')), 0, 1500), // visitors list: farewell to a person who leaves before answering
        'revisit' => !empty($g['revisit']) && $g['revisit'] !== '0', 'revisit_text' => mb_substr(trim((string) ($g['revisit_text'] ?? '')), 0, 2000), 'revisit_days' => max(0, min(365, (int) ($g['revisit_days'] ?? 3))), 'revisit_kick' => max(0, min(50, (int) ($g['revisit_kick'] ?? 0))), 'kick_text' => mb_substr(trim((string) ($g['kick_text'] ?? '')), 0, 1000), // a person who comes back
        'guide' => !empty($g['guide']) && $g['guide'] !== '0', 'guide_text' => mb_substr(trim((string) ($g['guide_text'] ?? '')), 0, 3000), // system help group: explains the system by the asker's permissions
        'fun' => !empty($g['fun']) && $g['fun'] !== '0', // warm-up mode allowed here (turned on from the phone)
        // Reception gate: "woman / man" → the member is asked for a recording; the recording → the reviewers are tagged
        'approve' => !empty($g['approve']) && $g['approve'] !== '0', // join requests approved automatically
        'gate' => !empty($g['gate']) && $g['gate'] !== '0', 'gate_f' => mb_substr(trim((string) ($g['gate_f'] ?? '')), 0, 300), 'gate_m' => mb_substr(trim((string) ($g['gate_m'] ?? '')), 0, 300),
        // New members who do not answer: a reminder after gate_remind_h hours, a last notice gate_final_h hours later, removal gate_kick_m minutes after it (0 = never removed)
        'gate_follow' => !isset($g['gate_follow']) || ($g['gate_follow'] && $g['gate_follow'] !== '0'), 'gate_remind_h' => max(1, min(72, (int) ($g['gate_remind_h'] ?? 3))), 'gate_final_h' => max(1, min(72, (int) ($g['gate_final_h'] ?? 3))), 'gate_kick_m' => max(0, min(1440, (int) ($g['gate_kick_m'] ?? 30))),
        'gate_notify' => preg_match('/^[0-9-]{6,40}@g\.us$/', (string) ($g['gate_notify'] ?? '')) ? (string) $g['gate_notify'] : '', // where the healer is asked to open the case
        'gate_review' => implode(', ', array_slice(array_values(array_filter(array_map('wf_phone', preg_split('/[\s,\x{060C}]+/u', (string) ($g['gate_review'] ?? ''))))), 0, 10))];
}
/* ---------- Warm-up mode: the owner types the word (#هزار) in any private chat on the phone; the bot then chats and jokes
 *  with everyone in the groups that allow it, for a few hours or until the stop word. A product feature for every business. ---------- */
function wf_fun_on(string $aid): bool { return (int) (wi_read('fun_mode.json', [])[$aid] ?? 0) > time(); }
/** The owner's word typed on the phone: on / off. Returns the note for the owner, or '' when the text is not the word. */
function wf_fun_command(string $aid, string $text): string {
    $P = wf_pack($aid); $on = (string) ($P['fun_cmd'] ?? ''); $off = (string) ($P['fun_off'] ?? ''); $t = wf_intake_norm(trim($text));
    if ($on === '' || $t === '') return '';
    if ($off !== '' && $t === wf_intake_norm($off)) { wi_update('fun_mode.json', function ($F) use ($aid) { unset($F[$aid]); return $F; }); return wf_msg('fun_off'); }
    if ($t !== wf_intake_norm($on)) return '';
    $h = max(1, (int) ($P['fun_hours'] ?? 2)); wi_update('fun_mode.json', function ($F) use ($aid, $h) { $F[$aid] = time() + $h * 3600; return $F; });
    return wf_msg('fun_on', ['hours' => (string) $h, 'off' => $off]);
}
/** The owner's commands sent from a test number to the bot's number: the warm-up words, and the restart words (business profile → restart_cmd).
 *  Returns [note, restart?], or null when the text is not a command. */
function wf_owner_command(array $acc, string $text): ?array {
    $aid = (string) $acc['id']; if (($n = wf_fun_command($aid, $text)) !== '') return [$n, false];
    $P = wf_pack($aid); $t = wf_intake_norm(trim($text)); if ($t === '') return null;
    $is = function (string $k) use ($P, $t) { foreach (preg_split('/[,\x{060C}|\n]+/u', (string) ($P[$k] ?? '')) as $w) { $w = wf_intake_norm(trim($w)); if ($w !== '' && $t === $w) return true; } return false; };
    $starts = function (string $k) use ($P, $t, $text) { $w = wf_intake_norm(trim((string) ($P[$k] ?? ''))); return $w !== '' && ($t === $w || str_starts_with($t, $w . ' ')) ? trim((string) (preg_split('/\s+/u', trim($text), 2)[1] ?? '')) : null; }; // the rest as written (the name is matched as typed)
    if ($is('restart_cmd')) return [wf_msg('bot_restart'), true];
    if ($is('status_cmd')) { $c = wf_bot_run_cfg($aid); $g = wi_gateway('/status', null, $acc); $st = wi_read('bot_state.json', [])[$aid] ?? []; $day = date('Y-m-d'); $k = ['sent' => 0, 'handoff' => 0, 'draft' => 0, 'skipped' => 0];
        foreach (wi_bot_log($aid, 500) as $l) if (str_starts_with(date('Y-m-d', strtotime((string) ($l['at'] ?? ''))), $day) && isset($k[$l['result'] ?? ''])) $k[$l['result']]++;
        $pz = count(array_filter(wf_bot_paused($aid), fn($p) => empty($p['until']) || strtotime((string) $p['until']) > time()));
        $mode = !empty($c['held']) ? wf_msg('bot_status_held') : ($c['mode'] === 'auto' ? wf_msg('bot_status_auto') : ($c['mode'] === 'draft' ? wf_msg('bot_status_draft') : wf_msg('bot_status_off')));
        return [wf_msg('bot_status', ['mode' => $mode, 'wa' => !empty($g['connected']) ? '🟢' : '🔴', 'up' => (string) round(((int) ($g['uptime_seconds'] ?? 0)) / 60), 'mem' => (string) (int) ($g['memory_mb'] ?? 0),
            'sent' => (string) $k['sent'], 'handoff' => (string) $k['handoff'], 'draft' => (string) $k['draft'], 'skipped' => (string) $k['skipped'], 'paused' => (string) $pz,
            'last' => !empty($st['last_sent']) ? date('H:i', strtotime((string) $st['last_sent'])) : '—', 'tick' => !empty($st['last_tick']) ? date('H:i', strtotime((string) $st['last_tick'])) : '—']), false]; }
    if ($is('hold_cmd') || $is('resume_cmd')) { $hold = $is('hold_cmd'); wi_update('bot.json', function ($B) use ($aid, $hold) { $B[$aid]['run']['held'] = $hold; return $B; }); wi_log('bot_hold', ['account' => $aid, 'held' => $hold]);
        return [wf_msg($hold ? 'owner_bot_hold' : 'owner_bot_resume', ['on' => trim((string) preg_split('/[,\x{060C}]+/u', (string) ($P['resume_cmd'] ?? ''))[0])]), false]; }
    if ($is('confirm_cmd')) { $p = (array) (wi_read('bot_state.json', [])[$aid]['owner_del'] ?? []);
        if (empty($p['ph']) || (int) ($p['at'] ?? 0) < time() - 300) return [wf_msg('case_del_none'), false];
        wi_update('bot_state.json', function ($S) use ($aid) { unset($S[$aid]['owner_del']); return $S; });
        return [wf_case_delete($aid, (string) $p['ph'], 'test number') ? wf_msg('case_del_done', ['name' => (string) $p['name']]) : wf_msg('case_del_none'), false]; }
    if (($q = $starts('delete_cmd')) !== null) { $hit = $q !== '' ? wf_intake_find($aid, $q, [], $acc) : [];
        if (count($hit) !== 1) return [wf_msg('case_del_none'), false];
        $ph = (string) array_key_first($hit); $c = $hit[$ph]; $name = trim((string) ($c['name'] ?? ($c['data']['name'] ?? ''))) ?: '+' . $ph;
        wi_update('bot_state.json', function ($S) use ($aid, $ph, $name) { $S[$aid]['owner_del'] = ['ph' => $ph, 'name' => $name, 'at' => time()]; return $S; });
        return [wf_msg('case_del_ask', ['name' => $name, 'no' => (string) ($c['no'] ?? ''), 'confirm' => (string) ($P['confirm_cmd'] ?? '')]), false]; }
    return null;
}
/** Group reply clean-up: no @numbers of other people, no names of other members, no "يا <name>" of someone else. */
function wf_reply_strict(string $t, array $otherNames, string $who): string {
    $t = preg_replace_callback('/@(\d{6,})/', fn($m) => $m[1] === $who ? $m[0] : '', $t);
    foreach ($otherNames as $n) { $n = trim(preg_replace('/[^\p{L}\p{N}\s]/u', ' ', (string) $n)); $n = trim(preg_replace('/\s+/u', ' ', $n)); if (mb_strlen($n) < 3) continue;
        $q = preg_quote($n, '/'); $t = preg_replace('/(^|\s)\x{064A}\x{0627}\s+' . $q . '/u', '$1', $t); } // only when the reply calls them ("يا NAME"): a name that is also a phrase stays in sentences
    return trim(preg_replace(['/[ \t]{2,}/u', '/\s+([\x{060C},.!?\x{061F}])/u'], [' ', '$1'], $t));
}
/** A short acknowledgement ("تمام", "الحمد لله", "حاضر") ends a talk: it is not a new question, the bot stays out. The words are a setting (business profile → ack_words). */
function wf_is_ack(string $aid, string $text): bool {
    $t = trim(wf_norm($text)); if ($t === '' || mb_strlen($t) > 40 || preg_match('/[\x{061F}?]/u', $text)) return false;
    $set = []; foreach (preg_split('/[|\n]/u', (string) (wf_pack($aid)['ack_words'] ?? wf_pack_presets()['general']['ack_words'] ?? '')) as $w) { $w = trim(wf_norm($w)); if ($w !== '') $set[$w] = 1; }
    if (!$set) return false; $left = $t; uksort($set, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
    foreach (array_keys($set) as $w) $left = str_replace($w, ' ', $left);
    return trim(preg_replace('/\s+/u', ' ', $left)) === '' || !wf_words($left) && trim($left) === '';
}
/** A message with no words, only emojis: a reaction like a person would give (and for some a short line), without the AI.
 *  Rules from the business profile "emoji_replies" ("emojis => reaction | reply", "*" = any other emoji). Returns what was done, or ''. */
function wf_bot_emoji(array $acc, string $jid, string $msgId, string $text, bool $reply = true): string {
    $t = trim($text); if ($t === '' || $msgId === '' || wf_words($t) || !preg_match('/\p{So}|\p{Sk}|\p{L}|[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $t)) return ''; // emojis, or a short "ok / thanks" (no question words); "?" alone is left
    $rule = null; $any = null;
    foreach (preg_split('/\R/u', (string) (wf_pack((string) $acc['id'])['emoji_replies'] ?? '')) as $l) { $x = array_map('trim', explode('=>', $l, 2)); if (count($x) < 2) continue;
        [$react, $line] = array_pad(array_map('trim', explode('|', $x[1], 2)), 2, '');
        if ($x[0] === '*') { $any = [$react, $line]; continue; }
        foreach (preg_split('/\s+/u', $x[0]) as $e) if ($e !== '' && mb_strpos($t, $e) !== false) { $rule = [$react, $line]; break 2; } }
    $rule = $rule ?? $any; if (!$rule) return '';
    if ($rule[0] !== '') wi_gateway('/chat/react', ['jid' => $jid, 'id' => $msgId, 'emoji' => $rule[0]], $acc);
    if ($reply && $rule[1] !== '') wi_gateway('/chat/send', ['jid' => $jid, 'text' => $rule[1], 'quote_id' => $msgId, 'by' => 'bot', 'via' => 'bot'], $acc);
    return trim($rule[0] . ' ' . ($reply ? $rule[1] : ''));
}
/** New members joined a group: send the welcome message with an @mention of each of them. */
function wf_bot_welcome(array $acc, string $gjid, array $members): array {
    $aid = (string) $acc['id']; $c = wf_bot_run_cfg($aid);
    if ($c['mode'] === 'off' || !in_array($c['scope'], ['both', 'groups'], true)) return ['skipped' => 'bot off for groups'];
    $g = wf_bot_group_cfg($c, $gjid); if (!$g || empty($g['welcome']) || trim((string) $g['welcome_text']) === '') return ['skipped' => 'no welcome for this group'];
    $jids = []; $tags = [];
    foreach ($members as $m) { $m = (string) $m; $num = wf_phone(explode('@', $m)[0]); if ($num === '') continue; $jids[] = $num . '@' . (str_ends_with($m, '@lid') ? 'lid' : 's.whatsapp.net'); $tags[] = '@' . $num; }
    if (!$jids) return ['skipped' => 'no members'];
    // A member welcomed in this group during the last 7 days is not welcomed again (left and joined back, or the join event came twice).
    // Members are only marked as welcomed once the welcome is really sent (or saved as a draft): a failure in between used to mark them and lose the message.
    $fresh = []; $now = time(); $W = (array) (wi_read('welcomed.json', [])[$aid][$gjid] ?? []);
    foreach ($jids as $j) if ((int) ($W[$j] ?? 0) < $now - 7 * 86400) $fresh[] = $j;
    if (!$fresh) return ['skipped' => 'already welcomed'];
    $jids = $fresh; $tags = array_map(fn($j) => '@' . explode('@', $j)[0], $fresh);
    $gname = trim((string) ($g['name'] ?? '')); // the group's name is saved with its settings: no need to build the whole chat list for it
    if ($gname === '' && strpos((string) $g['welcome_text'], '{group}') !== false) { $names = wi_gateway('/archive/chats?months=' . (int) wi_sys('months_long'), null, $acc); foreach ($names['chats'] ?? [] as $x) if ($x['jid'] === $gjid) $gname = (string) ($x['name'] ?? ''); }
    $markWelcomed = function () use ($aid, $gjid, $jids, $now) { wi_update('welcomed.json', function ($W) use ($aid, $gjid, $jids, $now) { foreach ($jids as $j) $W[$aid][$gjid][$j] = $now; $W[$aid][$gjid] = array_filter((array) ($W[$aid][$gjid] ?? []), fn($t) => $t > $now - 30 * 86400); return $W; }); };
    $text = strtr((string) $g['welcome_text'], ['{mention}' => implode(' ', $tags), '{group}' => $gname, '{name}' => implode(' ', $tags)]);
    if (strpos((string) $g['welcome_text'], '{mention}') === false && strpos((string) $g['welcome_text'], '{name}') === false) $text = implode(' ', $tags) . "\n" . $text; // always tag them
    $log = ['jid' => $gjid, 'name' => $gname . ' · ' . T_W('welcome'), 'q' => '➕ ' . implode(' ', $tags), 'reply' => $text, 'confidence' => 1, 'model' => 'welcome', 'at' => date('c'), 'group' => true];
    if ($c['mode'] === 'auto') {
        $r = wi_gateway('/chat/send', ['jid' => $gjid, 'text' => wf_bot_style($text, $aid), 'mentions' => $jids, 'by' => 'bot', 'via' => 'bot'], $acc);
        $log['result'] = !empty($r['ok']) ? 'sent' : 'send_failed'; if (empty($r['ok'])) $log['error'] = $r['error'] ?? ''; else $markWelcomed();
    } else { $markWelcomed(); $log['result'] = 'draft'; $log['why'] = 'suggest-only mode'; wi_update('bot_drafts.json', function ($d) use ($aid, $gjid, $log) { $d[$aid][$gjid] = $log; return $d; }); }
    wi_bot_log_add($aid, $log);
    return $log;
}
function T_W(string $s): string { return ['welcome' => 'ترحيب'][$s] ?? $s; }
function wf_is_question(string $t): bool {
    return (bool) preg_match('/[؟?]|\b(ايه|إيه|ازاي|إزاي|ليه|امتى|إمتى|فين|هل|كيف|ماذا|متى|لماذا|أين|كام|مين|ممكن)\b/u', $t);
}
/** Group messages: answers members' new messages in the chosen groups (as a reply to their message). */
function wf_bot_groups(array $acc, array $c, array &$st, array $team, array $chats): array {
    $aid = (string) $acc['id']; $did = []; $n = 0;
    $since = strtotime((string) ($c['since'] ?? 'now'));
    $h = wi_gateway('/health', null, $acc); $me = wf_phone(explode(':', (string) ($h['account'] ?? ''))[0]);
    foreach ($chats as $x) {
        if ($n >= 2 || empty($x['group'])) continue;
        $hasQ = !empty($st['gq'][$x['jid']]); // people waiting while an admin talks with them (looked at again every run)
        $last = $x['last'] ?? []; if (($last['dir'] ?? '') !== 'in' && !$hasQ) continue;
        $lt = strtotime((string) ($last['time'] ?? '')); if (!$hasQ && (!$lt || $lt < $since || $lt < time() - 3 * 3600)) continue;
        if (!$hasQ && ($st['gdone'][$x['jid']] ?? '') >= $last['time']) continue;
        $g = wf_bot_group_cfg($c, $x['jid']);
        if (!$g) { $st['gdone'][$x['jid']] = $last['time']; continue; }
        $t = wi_gateway('/archive/thread?limit=400&jid=' . rawurlencode($x['jid']), null, $acc); $rows = $t['rows'] ?? [];
        $from = max($since, strtotime((string) ($st['gdone'][$x['jid']] ?? '1970-01-01')));
        // Fair turns: several messages in a row from one person become ONE question (their latest, with the others as context),
        // people who were answered least recently go first, and one person gets at most 4 replies an hour in a group.
        $pend = []; $newest = null;
        foreach ($rows as $i => $m) {
            if (($m['dir'] ?? '') !== 'in' || strtotime((string) $m['time']) <= $from) continue;
            $newest = $m['time']; $w = wf_phone($m['from'] ?? ''); if ($w === '') continue;
            $tx = trim((string) ($m['text'] ?? ''));
            if (isset($pend[$w]) && $tx !== '') { $prev = trim((string) ($pend[$w][1]['text'] ?? '')); if ($prev !== '' && mb_strlen($prev) < 600) $m['text'] = $prev . "\n" . $tx; }
            elseif (isset($pend[$w]) && $tx === '') continue;
            $pend[$w] = [$i, $m];
        }
        if ($newest !== null) $st['gdone'][$x['jid']] = $newest;
        foreach ((array) ($st['gq'][$x['jid']] ?? []) as $qw => $qm) if (strtotime((string) ($qm['time'] ?? '')) < time() - 3 * 3600) unset($st['gq'][$x['jid']][$qw]); // too old: dropped
        foreach ((array) ($st['gq'][$x['jid']] ?? []) as $qw => $qm) if (!isset($pend[$qw])) { $qi = count($rows) - 1; foreach ($rows as $ri => $rm) if (($rm['id'] ?? '') === ($qm['id'] ?? '')) $qi = $ri; $pend[$qw] = [$qi, $qm]; }
        $gwait = max(0, (int) ($c['human_wait'] ?? 5)) * 60;
        // An admin talking with this member (a reply or a tag) in the last half hour: the member is the admin's for now.
        $adminWith = function (string $who) use ($rows, $team) { foreach (array_reverse($rows) as $r) { if (strtotime((string) $r['time']) < time() - 1800) break; $w = wf_phone($r['from'] ?? '');
            if (($r['dir'] ?? '') === 'in' && isset($team[$w]) && (wf_phone($r['quote_from'] ?? '') === $who || in_array($who, array_map('wf_phone', (array) ($r['mentions'] ?? [])), true))) return strtotime((string) $r['time']); } return 0; }; // when the admin last spoke to them
        $hourAgo = time() - 3600;
        foreach ($pend as $w => $_) $st['gwho'][$x['jid']][$w] = array_values(array_filter((array) ($st['gwho'][$x['jid']][$w] ?? []), fn($t) => $t > $hourAgo));
        $lastAns = []; foreach ($pend as $w => $_) { $ts = (array) ($st['gwho'][$x['jid']][$w] ?? []); $lastAns[$w] = $ts ? max($ts) : 0; }
        uksort($pend, fn($p, $q) => $lastAns[$p] <=> $lastAns[$q]);
        // Messages that tag the bot (or reply to it) are answered first, then the rest of the recent talk, each group of them in fair order.
        $isMen = fn($m) => $me !== '' && (in_array($me, array_map('wf_phone', (array) ($m['mentions'] ?? [])), true) || wf_phone($m['quote_from'] ?? '') === $me);
        $order = array_flip(array_keys($pend)); uksort($pend, fn($p, $q) => ((int) $isMen($pend[$q][1])) <=> ((int) $isMen($pend[$p][1])) ?: $order[$p] <=> $order[$q]);
        $fun = !empty($g['fun']) && wf_fun_on($aid); // warm-up mode: the owner typed the word on the phone — light chat with everyone in this group
        foreach ($pend as $pw => [$i, $m]) {
            if ($n >= 3) break; $r = [];
            if ((int) $c['group_person_hour'] > 0 && count($st['gwho'][$x['jid']][$pw] ?? []) >= (int) $c['group_person_hour']) continue; // the cap per person per hour in a group (0 = none): give others a turn
            $who = wf_phone($m['from'] ?? ''); $text = trim((string) ($m['text'] ?? ''));
            $wTags = [$who]; if (strlen($who) >= 14 && function_exists('wf_group_member_phone') && ($pn0 = wf_group_member_phone($acc, (string) $x['jid'], $who)['phone']) !== '') $wTags[] = $pn0; // the bot's replies to this person start with their tag (mobile number or long ID)
            if ($who !== '' && !isset($team[$who]) && $text !== '' && !wf_words($text) && ($g['trigger'] ?? 'mention') === 'all' && $c['mode'] === 'auto') { wf_bot_emoji($acc, (string) $x['jid'], (string) ($m['id'] ?? ''), $text, false); continue; } // only emojis in a group: a reaction, no message
            $mentioned = $me !== '' && (in_array($me, array_map('wf_phone', (array) ($m['mentions'] ?? [])), true) || wf_phone($m['quote_from'] ?? '') === $me);
            $askT = function_exists('wf_ask_tag') ? wf_ask_tag($aid) : ''; // the ask tag (#سؤال by default): the question is for the bot, answered at once like a tag of the bot
            $asked = $askT !== '' && wf_intake_has($text, $askT); if ($asked) { $mentioned = true; $text = trim(str_ireplace($askT, '', $text)) ?: $text; }
            // A group admin is answered when they ask a question or tag the bot — not while they answer someone else (a reply or a tag of another member).
            $adminAsks = isset($team[$who]) && $mentioned; // STRICT: healers and admins talk among themselves — the bot answers them only when they tag it or reply to it
            if ($who === '' || (isset($team[$who]) && !$adminAsks && !$fun && empty($g['guide'])) || $text === '' || !wf_words($text)) continue;
            if (!empty($g['guide']) && function_exists('wf_sysguide_known_cmd') ? wf_sysguide_known_cmd($acc, $text) : (function_exists('wf_intake_is_cmd') && wf_intake_is_cmd($aid, $text))) continue; // a command the system handles (a help group also skips the library tags and quick replies; a mistyped one is taught below) // a case command in a group that does not run commands: not a chat message, the bot stays quiet
            if (!$mentioned && wf_is_ack($aid, $text)) continue; // "تمام" / "الحمد لله": a talk ending, not a question
            $toOther = (!empty($m['quote_from']) && wf_phone((string) $m['quote_from']) !== $me) || array_diff(array_map('wf_phone', (array) ($m['mentions'] ?? [])), [$me]);
            if (!$mentioned && $toOther && !$fun) continue; // STRICT: a message to another member is not for the bot
            $lastTo = max(array_merge([0], (array) ($st['gwho'][$x['jid']][$who] ?? []))); if (!$mentioned && time() - $lastTo < (int) ($fun ? $c['fun_gap'] : (!empty($g['guide']) ? min(10, (int) $c['group_gap']) : $c['group_gap']))) { $st['gq'][$x['jid']][$who] = $m; continue; } // STRICT: one reply per person per 2 minutes (the rest waits)
            $trig = $fun ? 'all' : ($g['trigger'] ?? 'mention');
            if ($trig === 'mention' && !$mentioned) continue;
            if ($trig === 'questions' && !$mentioned && !wf_is_question($text) && (empty($g['guide']) || (mb_strpos($text, '#') === false && (count(wf_words($text)) < 3 || !wf_sysguide_user($who))))) continue; // a member's chat (not a user of the system) is answered only when it is a question or a tag // a help group lets the model decide whether a statement ("I can't open the page") is a request for help
            // An admin is talking with this member: the bot waits until the admin has been quiet for "human_wait" minutes, then answers.
            if (!$adminAsks && empty($g['guide']) && $gwait > 0 && ($aAt = $adminWith($who)) && time() - $aAt < $gwait) { $st['gq'][$x['jid']][$who] = $m; continue; }
            unset($st['gq'][$x['jid']][$who]);
            $log = ['jid' => $x['jid'], 'name' => ($x['name'] ?? '') . ' · ' . ($m['from_name'] ?? ('+' . $who)), 'q' => mb_substr($text, 0, 400), 'at' => date('c'), 'group' => true];
            if (($g['mode'] ?? 'ai') === 'fixed') {
                $lastFix = (int) ($st['gfixed'][$x['jid']][$who] ?? 0);
                if (trim((string) ($g['text'] ?? '')) === '' || time() - $lastFix < max(1, (int) ($g['cooldown'] ?? 24)) * 3600) continue;
                $reply = strtr((string) $g['text'], ['{name}' => (string) ($m['from_name'] ?? ''), '{group}' => (string) ($x['name'] ?? '')]);
                $log += ['reply' => $reply, 'confidence' => 1, 'model' => 'fixed message'];
                $ok = true; $st['gfixed'][$x['jid']][$who] = time();
            } elseif (!empty($g['guide']) && !$asked && (wf_sysguide_user($who) || mb_strpos($text, '#') !== false)) { // a system help group (a member's own question, with no tag, gets the bot's normal answer below): explains the system by what the asker's account may open
                $hist = []; foreach (array_slice($rows, max(0, $i - 80), 80) as $hm) { $ht = trim((string) ($hm['text'] ?? '')); if ($ht === '') continue;
                    if (($hm['dir'] ?? '') === 'in' && wf_phone($hm['from'] ?? '') === $who) $hist[] = ['from' => 'customer', 'text' => $ht];
                    elseif (($hm['via'] ?? '') === 'bot' && ($tg = array_values(array_filter($wTags, fn($t) => str_starts_with($ht, '@' . $t)))[0] ?? '') !== '') $hist[] = ['from' => 'bot', 'text' => trim(mb_substr($ht, mb_strlen('@' . $tg)))]; }
                $gav = []; foreach (array_slice($rows, -40) as $bm) if (($bm['via'] ?? '') === 'bot' && trim((string) ($bm['text'] ?? '')) !== '') $gav[] = (string) $bm['text']; // never the same steps again
                $r = wf_sysguide_answer($acc, $text, $who, array_slice($hist, -8), (string) ($g['guide_text'] ?? ''), array_slice($gav, -5));
                if (empty($r['ok']) || !empty($r['handoff'])) continue; // a help group never pings the admins for what it cannot answer
                $log += ['reply' => $r['reply'], 'confidence' => $r['confidence'], 'handoff' => false, 'model' => $r['task']['model'] ?? '', 'role' => 'guide'];
                $ok = $r['handoff'] || $r['confidence'] >= 0.5;
                if ($r['handoff']) $log['why'] = 'handed over to a person';
            } else {
                // Only this person's talk with the bot: their own messages and the bot's replies to them (other members' chatter mixed the names up)
                $hist = []; foreach (array_slice($rows, max(0, $i - 80), 80) as $hm) { $ht = trim((string) ($hm['text'] ?? '')); if ($ht === '') continue;
                    if (($hm['dir'] ?? '') === 'in' && wf_phone($hm['from'] ?? '') === $who) $hist[] = ['from' => 'customer', 'text' => $ht];
                    elseif (($hm['via'] ?? '') === 'bot' && ($tg = array_values(array_filter($wTags, fn($t) => str_starts_with($ht, '@' . $t)))[0] ?? '') !== '') $hist[] = ['from' => 'bot', 'text' => trim(mb_substr($ht, mb_strlen('@' . $tg)))]; }
                $hist = array_slice($hist, -8);
                $card = wf_customer_card($acc, $who . '@s.whatsapp.net');
                // Groups: the group's role (healer and ruqyah teacher by default), or the role this person's words call; the person keeps it for a while
                $rc = function_exists('wf_roles_cfg') ? wf_roles_cfg($aid) : ['group_role' => '']; $role = function_exists('wf_role_pick') ? wf_role_pick($aid, $x['jid'] . '|' . $who, $text, (string) ($g['role'] ?? $rc['group_role'])) : 'bot';
                if ($role === 'dreams' && !$mentioned && !wf_is_question($text) && $c['mode'] === 'auto' && (int) ($c['dream_wait'] ?? 0) > 0) { // a dream told in pieces: wait until the person stops, then answer all of it once
                    $dq = (array) ($st['dream'][$x['jid']][$who] ?? []); $parts = (array) ($dq['parts'] ?? []);
                    if (!in_array($text, $parts, true)) { $parts[] = $text; $dq['last'] = time(); }
                    $dq['parts'] = array_slice($parts, -8); $st['dream'][$x['jid']][$who] = $dq;
                    if (mb_strlen($text) < 160 && time() - (int) ($dq['last'] ?? 0) < (int) $c['dream_wait']) { $st['gq'][$x['jid']][$who] = $m; continue; }
                    $text = implode("\n", $dq['parts']); $log['q'] = mb_substr($text, 0, 400); unset($st['dream'][$x['jid']][$who]);
                }
                if ($role === 'crisis') { wf_role_crisis($acc, (string) $x['jid'], $text, (string) ($m['from_name'] ?? ''), (string) ($m['id'] ?? ''), $who); $n++; continue; }
                $mine = []; foreach (array_slice($rows, -60) as $bm) if (($bm['dir'] ?? '') === 'out' && ($bm['via'] ?? '') === 'bot' && trim((string) ($bm['text'] ?? '')) !== '') $mine[] = (string) $bm['text']; // never repeat what the bot already said here
                $r = wf_bot_answer($acc, $text, $hist, '', $card, ['avoid' => array_slice($mine, -4), 'role' => $role, 'jid' => $x['jid'] . '|' . $who] + ($fun ? ['fun' => true] : []) + ($asked ? ['ask' => true] : []) + (isset($team[$who]) ? ['team' => true] : []));
                if (empty($r['ok']) || trim((string) $r['reply']) === '') continue;
                $log += ['reply' => $r['reply'], 'confidence' => $r['confidence'], 'handoff' => $r['handoff'], 'model' => $r['task']['model'] ?? '', 'role' => $role];
                // Like private chats: confident answers, short clarifying questions, and the polite
                // "the sheikh / the team will answer you" reply when a person is needed are all sent.
                $clar = !empty($r['clarify']) || (mb_strlen($r['reply']) < 220 && preg_match('/[؟?]\s*$/u', trim($r['reply'])) && $r['confidence'] >= 0.3);
                if (!empty($r['mode']) && $r['mode'] !== $role && isset(wf_roles_cfg($aid)['roles'][$r['mode']])) $role = (string) $r['mode']; // the model's own choice of mode
                $gMin = in_array($role, ['dreams', 'support', 'healer'], true) ? min((float) $c['min_conf'], 0.65) : (float) $c['min_conf']; // the roles send from 0.65 in groups too, as in private chats
                $ok = $r['handoff'] || $clar || $r['confidence'] >= $gMin || ($asked && $r['confidence'] >= 0.5) || ($fun && trim((string) $r['reply']) !== '' && $r['confidence'] >= 0.3);
                if ($r['handoff']) $log['why'] = 'handed over to a person';
            }
            $n++;
            // STRICT: the reply tags only the person it answers and never names another member (names mixed up between people)
            $others = []; foreach (array_slice($rows, -150) as $om) { $on = trim((string) ($om['from_name'] ?? ($om['name'] ?? ''))); if ($on !== '' && wf_phone((string) ($om['from'] ?? '')) !== $who && mb_strlen($on) >= 3) $others[$on] = 1; }
            $log['reply'] = wf_reply_strict((string) ($log['reply'] ?? ''), array_keys($others), $who);
            $mentions = [];
            if (!empty($r['handoff'])) {
                // Handing over in a group: tag the group's admins so they get a notification.
                static $admCache = [];
                if (!isset($admCache[$x['jid']])) { $mm = wi_gateway('/groups/members?jid=' . rawurlencode($x['jid']), null, $acc); $admCache[$x['jid']] = array_values(array_filter($mm['members'] ?? [], fn($p) => !empty($p['admin']) && empty($p['is_lid']) && wf_phone($p['phone']) !== $me)); }
                foreach (array_slice($admCache[$x['jid']], 0, 8) as $p) { $mentions[] = wf_phone($p['phone']) . '@s.whatsapp.net'; }
                if ($mentions) $log['reply'] .= "\n" . implode(' ', array_map(fn($j) => '@' . explode('@', $j)[0], $mentions));
            }
            $hh = (int) date('G'); $inH = $c['from'] <= $c['to'] ? ($hh >= $c['from'] && $hh < $c['to']) : ($hh >= $c['from'] || $hh < $c['to']);
            if ($c['mode'] === 'auto' && $ok && $inH && ((int) $c['per_hour'] === 0 || count($st['sent']) < (int) $c['per_hour'])) {
                // every group reply tags the person it answers, so replies to different people never get mixed up
                $tagNum = $who; if (strlen($who) >= 14 && function_exists('wf_group_member_phone') && ($pn = wf_group_member_phone($acc, (string) $x['jid'], $who)['phone']) !== '') $tagNum = $pn; // the tag shows the mobile number, not the long WhatsApp ID
                $askJ = $tagNum . (strlen($tagNum) >= 14 ? '@lid' : '@s.whatsapp.net'); $gtext = '@' . $tagNum . ' ' . wf_bot_style($log['reply'], $aid, (string) $x['jid'], true);
                $s = wi_gateway('/chat/send', ['jid' => $x['jid'], 'text' => $gtext, 'by' => 'bot', 'via' => 'bot', 'quote_id' => $m['id'] ?? null, 'mentions' => array_values(array_unique(array_merge([$askJ], $mentions)))], $acc); wf_bot_intro_done(!empty($s['ok']));
                $log['result'] = !empty($s['ok']) ? (!empty($r['handoff']) ? 'handoff' : 'sent') : 'send_failed'; if (!empty($s['ok'])) { $st['sent'][] = time(); $st['gwho'][$x['jid']][$who][] = time(); } else $log['error'] = $s['error'] ?? '';
                if (!empty($s['ok']) && function_exists('wf_role_note')) wf_role_note($aid, $who, (string) ($log['role'] ?? 'bot'), $text, (string) $log['reply'], (string) ($x['name'] ?? ''));
            } else {
                $log['result'] = 'draft'; $log['why'] = $c['mode'] !== 'auto' ? 'suggest-only mode' : (!$ok ? 'low confidence' : (!$inH ? 'outside working hours' : 'hourly limit'));
                wi_update('bot_drafts.json', function ($d) use ($aid, $x, $log) { $d[$aid][$x['jid']] = $log; return $d; });
            }
            wi_bot_log_add($aid, $log);
            $did[] = $log;
        }
    }
    return $did;
}
/** One run for one account: answers new private messages (at most 2 per run). Returns what it did. */
/** How alike two messages are (0..1), by shared words. */
function wf_text_sim(string $a, string $b): float { $x = array_unique(wf_words($a)); $y = array_unique(wf_words($b)); if (count($x) < 3 || count($y) < 3) return 0.0; return 2 * count(array_intersect($x, $y)) / (count($x) + count($y)); } // Dice; messages under 3 words never count as a repeat
function wf_bot_tick(array $acc, bool $fast = false): array {
    if (wi_account_blocked($acc)) return ['did' => [], 'blocked' => true]; // the client's subscription ended or is paused
    wi_ctx_set('ai_acc', $acc); // AI calls of this number use its owner's keys (a client) or the system's
    $aid = (string) $acc['id']; $c = wf_bot_run_cfg($aid);
    // A new number warms up: fewer replies per hour until the chosen date (less risk of a WhatsApp block).
    if (!empty($c['warmup_until']) && strtotime((string) $c['warmup_until']) > time()) $c['per_hour'] = (int) $c['per_hour'] > 0 ? min((int) $c['per_hour'], max(1, (int) $c['warmup_per_hour'])) : max(1, (int) $c['warmup_per_hour']);
    if ($c['mode'] === 'off' || empty($acc['enabled'])) return ['skipped' => 'off'];
    // One run at a time per number (the instant hook and the every-minute check can meet).
    // The instant trigger waits its turn (up to 2 minutes) instead of giving up.
    $lkf = WI_DIR . '/bot_' . preg_replace('/\W/', '', $aid) . '.lock'; $lk = @fopen($lkf, 'c'); if (!$lk) return ['skipped' => 'lock']; @chmod($lkf, 0600);
    $got = flock($lk, LOCK_EX | LOCK_NB);
    for ($w = 0; !$got && $fast && $w < 120; $w++) { sleep(1); $got = flock($lk, LOCK_EX | LOCK_NB); }
    if (!$got) return ['skipped' => 'busy'];
    $h = (int) date('G'); $inHours = $c['from'] <= $c['to'] ? ($h >= $c['from'] && $h < $c['to']) : ($h >= $c['from'] || $h < $c['to']);
    $state = wi_read('bot_state.json', []); $st = $state[$aid] ?? ['done' => [], 'sent' => []];
    $st['last_tick'] = date('c'); $retry = (array) ($st['retry'] ?? []); // chats an admin asked to answer now (once, past the limits)
    $st['sent'] = array_values(array_filter($st['sent'] ?? [], fn($t) => $t > time() - 3600));
    $since = strtotime((string) ($c['since'] ?? 'now'));
    $team = wf_team($acc, (array) ($c['groups'] ?? [])); $paused = wf_bot_paused($aid); // group admins count as the team too
    $forced = wi_read('bot_forced.json', [])[$aid] ?? [];
    $skip = function (array $x, string $why, string $text = '') use (&$st, $aid) {
        // Recorded once per message so the owner can see why the bot stayed silent.
        $st['done'][$x['jid']] = $x['last']['time'];
        wi_bot_log_add($aid, ['jid' => $x['jid'], 'name' => $x['name'] ?? '', 'q' => mb_substr($text ?: (string) ($x['last']['text'] ?? ''), 0, 300), 'reply' => '', 'result' => 'skipped', 'why' => $why, 'at' => date('c')]);
    };
    $ch = wi_gateway('/archive/chats?months=' . (int) wi_sys('months_recent') , null, $acc);
    $did = []; $n = 0;
    if (empty($c['held']) && in_array($c['scope'], ['both', 'groups'], true)) { try { $did = array_merge($did, wf_bot_groups($acc, $c, $st, $team, $ch['chats'] ?? [])); } catch (Throwable $e) {} }
    // The number's chat with itself is the owner's test chat: what the owner types there is answered like a customer's message.
    $selfIds = []; $sAcc = (array) (wi_gateway('/status', null, $acc)['account'] ?? []); foreach (['id', 'lid'] as $k) { $d = preg_replace('/\D/', '', explode(':', explode('@', (string) ($sAcc[$k] ?? ''))[0])[0]); if ($d !== '') $selfIds[$d] = 1; }
    $selfMap = fn(array $rows) => array_map(fn($m) => ($m['dir'] ?? '') === 'out' && ($m['via'] ?? '') !== 'bot' ? ['dir' => 'in'] + $m : $m, $rows);
    foreach (($c['scope'] === 'groups' ? [] : ($ch['chats'] ?? [])) as $x) {
        if ($n >= 2) break;
        if (!empty($x['group']) || !str_ends_with((string) $x['jid'], '@s.whatsapp.net') && !str_ends_with((string) $x['jid'], '@lid') && !str_ends_with((string) $x['jid'], '@fb')) continue; // @fb: a Facebook page's Messenger chat
        $isSelf = isset($selfIds[explode('@', (string) $x['jid'])[0]]);
        if ($isSelf) { $sr = $selfMap((array) (wi_gateway('/archive/thread?limit=40&jid=' . rawurlencode($x['jid']), null, $acc)['rows'] ?? [])); $x['last'] = end($sr) ?: []; // commands typed there (# or /) are answered by the phone hook, not here
            if (preg_match('~^\s*[#/]~u', (string) ($x['last']['text'] ?? ''))) { $st['done'][$x['jid']] = (string) ($x['last']['time'] ?? ''); continue; } }
        $last = $x['last'] ?? []; if (($last['dir'] ?? '') !== 'in') continue;
        $lt = strtotime((string) ($last['time'] ?? '')); if (!$lt || (!isset($retry[$x['jid']]) && ($lt < $since || $lt < time() - 3 * 3600))) continue;
        $again = isset($retry[$x['jid']]); unset($st['retry'][$x['jid']]);
        if (!$again && ($st['done'][$x['jid']] ?? '') >= $last['time']) continue;          // already handled this message
        if (!$again && isset($paused[$x['jid']]) && (empty($paused[$x['jid']]['until']) || strtotime($paused[$x['jid']]['until']) > time())) continue; // /stop, or handed over to a person for a while
        if (($x['phone'] ?? '') === '' && str_ends_with((string) $x['jid'], '@lid')) { $cc = wi_contact($x['jid'], $acc); $x['phone'] = wf_phone((string) ($cc['phone'] ?? '')); }
        $isTest = $isSelf || in_array((string) ($x['phone'] ?? ''), (array) $c['test'], true); // test numbers (and the number's own test chat): always answered
        if ($isTest && ($oc = wf_owner_command($acc, trim((string) ($last['text'] ?? '')))) !== null) { // the owner's command from a test number: done, answered, no AI
            $st['done'][$x['jid']] = $last['time']; $s = wi_gateway('/chat/send', ['jid' => $x['jid'], 'text' => $oc[0], 'via' => 'bot'], $acc);
            wi_bot_log_add($aid, ['jid' => $x['jid'], 'name' => $x['name'] ?? '', 'q' => (string) ($last['text'] ?? ''), 'reply' => $oc[0], 'result' => !empty($s['ok']) ? 'sent' : 'send_failed', 'role' => 'command', 'at' => date('c')]);
            if ($oc[1]) { $state = wi_read('bot_state.json', []); $state[$aid] = $st; wi_write('bot_state.json', $state); wi_log('bot_restart', ['account' => $aid, 'by' => 'test number']); sleep(2); wi_gateway('/shutdown', [], $acc); return ['did' => [['jid' => $x['jid'], 'result' => 'restart']]]; } // the watchdog starts it again
            $n++; continue; }
        if (function_exists('wf_is_menu') && wf_is_menu($aid, trim((string) ($last['text'] ?? '')))) { // the commands list asked in a private chat
            $st['done'][$x['jid']] = $last['time']; $s = wi_gateway('/chat/send', ['jid' => $x['jid'], 'text' => wf_cmd_menu($aid), 'via' => 'bot'], $acc);
            wi_bot_log_add($aid, ['jid' => $x['jid'], 'name' => $x['name'] ?? '', 'q' => (string) ($last['text'] ?? ''), 'reply' => '#menu', 'result' => !empty($s['ok']) ? 'sent' : 'send_failed', 'role' => 'command', 'at' => date('c')]); $n++; continue; }
        if (function_exists('wf_cmd_custom_which') && ($cu = wf_cmd_custom_which($aid, trim((string) ($last['text'] ?? '')), false))) { // the owner's own command (Bot → Commands): its reply, at once
            $st['done'][$x['jid']] = $last['time']; wf_cmd_custom_run($acc, $cu, (string) $x['jid'], trim((string) ($last['text'] ?? '')), (string) ($last['id'] ?? ''), '', (string) ($x['name'] ?? '')); $n++; continue; }
        if (!empty($c['held'])) continue; // stopped from a test number: only the owner's commands are answered
        if (!$isTest && isset($team[(string) ($x['phone'] ?? '')]) && $team[(string) $x['phone']] !== 'group admin') continue; // team members / own numbers (a group admin is team inside the groups only: in private they may be a client)
        $isSys = fn($t) => (bool) preg_match('/^\s*(\x{26A0}\x{FE0F}|\x{2705}|\x{1F4CA}|\x{1F514}|\x{2757})/u', (string) $t) || wf_is_system_text((string) $t); // login codes, alerts, reports
        // Let them finish: the gateway asks right after the customer stopped typing (quiet time); the minute check waits the full gather time.
        if (time() - $lt < ($fast ? max(2, (int) $c['quiet_secs'] - 1) : max(15, (int) $c['gather_secs']))) continue;
        $t = wi_gateway('/archive/thread?jid=' . rawurlencode($x['jid']), null, $acc); $rows = $t['rows'] ?? []; if ($isSelf) $rows = $selfMap($rows);
        // A person from the team is handling this chat: stay out.
        $humanAt = 0; foreach ($rows as $m) if ($m['dir'] === 'out' && ($m['via'] ?? '') !== 'bot') $humanAt = max($humanAt, strtotime($m['time']));
        // The team was active here recently: give them a few minutes to answer the new message first.
        // Nobody answered in that time → the bot replies, so no message is left without a reply.
        $wait = max(0, (int) ($c['human_wait'] ?? 10)) * 60;
        // Like a person: the bot stops only while a real person from the team is talking here — for "human_wait" minutes after the team's
        // last message (after the bot's last reply). No team activity in that time: it carries on. Only bot and customer: it never waits.
        $botAt = 0; foreach ($rows as $m) if ($m['dir'] === 'out' && ($m['via'] ?? '') === 'bot') $botAt = max($botAt, strtotime($m['time']));
        if (!$isTest && !$again && !isset($forced[$x['jid']]) && $humanAt > $botAt && time() - $humanAt < $wait) continue; // the team is on it (checked again next minute)
        // The customer's latest messages (since our last reply).
        $q = []; for ($i = count($rows) - 1; $i >= 0 && $rows[$i]['dir'] === 'in'; $i--) array_unshift($q, $rows[$i]);
        $q = array_values(array_filter($q, fn($m) => !$isSys($m['text'] ?? ''))); // a login code or an alert after the customer's message does not hide it
        if (!$q) { $skip($x, 'a message the system sent by itself (login code, alert, report…)'); continue; }
        $text = trim(implode("\n", array_filter(array_map(fn($m) => (string) ($m['text'] ?? ''), $q))));
        // The customer asked the bot to stop: it stays silent in this chat until someone turns it back on.
        $sw = array_filter(array_map('wf_norm', wf_style_words((string) $c['stop_words']))); $tn = wf_norm($text);
        if ($sw && in_array($tn, $sw, true)) { wi_update('bot_paused.json', function ($P) use ($aid, $x) { $P[$aid][$x['jid']] = ['at' => date('c'), 'by' => 'customer asked to stop']; return $P; }); $skip($x, 'customer asked to stop — bot paused here', $text); continue; }
        // Per-chat limit and the bot's own recent replies here (used against repeating itself).
        $mine = []; $hourN = 0; foreach ($rows as $m) if ($m['dir'] === 'out' && ($m['via'] ?? '') === 'bot') { $mine[] = (string) ($m['text'] ?? ''); if (strtotime((string) $m['time']) > time() - 3600) $hourN++; }
        if (!$isTest && !$again && (int) $c['chat_per_hour'] > 0 && $hourN >= (int) $c['chat_per_hour']) { $skip($x, 'limit of bot replies per chat per hour', $text); continue; }
        $mine = array_slice($mine, -3);
        if ((string) ($last['id'] ?? '') === '') foreach (array_reverse($rows) as $m) if (($m['dir'] ?? '') === 'in') { $last['id'] = (string) ($m['id'] ?? ''); break; } // the chat list has no message id: reactions and quotes need it
        if ($c['mode'] === 'auto' && function_exists('wf_media_code_send') && wf_media_code_send($acc, (string) $x['jid'], $text, (string) ($last['id'] ?? ''))) { // a file's shortcut: the file, no AI
            $st['done'][$x['jid']] = $last['time']; $st['sent'][] = time(); wi_bot_log_add($aid, ['jid' => $x['jid'], 'name' => $x['name'] ?? '', 'q' => $text, 'reply' => '📎', 'result' => 'sent', 'role' => 'file', 'at' => date('c')]); $n++; continue; }
        if ($text !== '' && !wf_words($text) && $c['mode'] === 'auto' && $inHours && ($em = wf_bot_emoji($acc, (string) $x['jid'], (string) ($last['id'] ?? ''), $text)) !== '') { // only emojis: answered like a person
            $st['done'][$x['jid']] = $last['time']; $st['sent'][] = time(); wi_bot_log_add($aid, ['jid' => $x['jid'], 'name' => $x['name'] ?? '', 'q' => $text, 'reply' => $em, 'result' => 'sent', 'role' => 'emoji', 'at' => date('c')]); $n++; continue; }
        if ($text !== '' && wf_is_ack($aid, $text) && array_filter($rows, fn($m) => ($m['dir'] ?? '') === 'out')) { $skip($x, 'a short acknowledgement in a talk already going', $text); continue; } // a new chat is still greeted
        if ($text === '' || !wf_words($text)) { $skip($x, $text === '' ? 'photo / voice note — left to people' : 'no words (emoji / ?)', $text); continue; } // also emoji-only / "ok" // photos / voice (e.g. payment receipts) are left to people and the payment bot
        $hist = []; foreach (array_slice($rows, -12, -count($q) ?: null) as $m) if (in_array($m['dir'], ['in', 'out'], true) && trim((string) ($m['text'] ?? '')) !== '' && !$isSys($m['text'] ?? '')) $hist[] = ['from' => $m['dir'] === 'out' ? 'bot' : 'customer', 'text' => $m['text']];
        $card = wf_customer_card($acc, $x['jid'], $rows);
        $role = function_exists('wf_role_pick') ? wf_role_pick($aid, (string) $x['jid'], $text) : 'bot'; // which hat answers (healer, support, dreams…)
        if ($role === 'crisis') { $st['done'][$x['jid']] = $last['time']; if (!$isTest) wf_role_crisis($acc, (string) $x['jid'], $text, (string) ($x['name'] ?? '')); $did[] = ['jid' => $x['jid'], 'result' => 'crisis']; continue; }
        $r = wf_bot_answer($acc, $text, $hist, '', $card, ['avoid' => $mine, 'role' => $role, 'jid' => (string) $x['jid']]);
        if (!empty($r['mode']) && $r['mode'] !== $role && isset(wf_roles_cfg($aid)['roles'][$r['mode']])) $role = (string) $r['mode']; // the model's own choice of mode (notes, threshold, case file)
        $st['done'][$x['jid']] = $last['time']; $n++;
        if (empty($r['ok']) || trim((string) $r['reply']) === '') { $did[] = ['jid' => $x['jid'], 'result' => 'error', 'error' => $r['error'] ?? '']; continue; }
        // Asked to join / rejoin a group: the bot does it (automatic mode, working hours) and says what happened.
        $jr = null; if (!empty($r['join_group']) && $c['mode'] === 'auto' && $inHours) { $jr = wf_bot_group_join($acc, (string) $x['jid'], (string) ($x['phone'] ?? ''));
            if ($jr['result'] !== 'off') { $r['reply'] = wf_msg('bot_group_' . ($jr['result'] === 'limit' ? 'team' : $jr['result']), ['group' => (string) ($jr['group'] ?? '')]); $r['confidence'] = 1.0; $r['clarify'] = false;
                $r['handoff'] = in_array($jr['result'], ['team', 'limit'], true); } }
        // Never send (almost) the same thing again: one more try with a stronger model, then hand over to the team.
        $simOf = function ($rep) use ($mine) { $s = 0; foreach ($mine as $p) $s = max($s, wf_text_sim($rep, $p)); return $s; };
        $repeat = empty($jr) && $mine && $simOf($r['reply']) >= (float) $c['repeat_sim'];
        if ($repeat && !empty($c['repeat_retry'])) { $r2 = wf_bot_answer($acc, $text, $hist, '', $card, ['avoid' => $mine, 'no_fast' => true]); if (!empty($r2['ok']) && trim((string) $r2['reply']) !== '' && $simOf($r2['reply']) < (float) $c['repeat_sim']) { $r = $r2; $repeat = false; } }
        // Only N clarifying questions in a row: after that the team takes over instead of asking again.
        // A question the customer really answered (3+ words) does not count: a normal back-and-forth goes on; only questions left unanswered do.
        $askedBefore = 0; foreach (array_reverse($rows) as $m) { $mt = trim((string) ($m['text'] ?? ''));
            if ($m['dir'] === 'in') { if (count(preg_split('/\s+/u', $mt, -1, PREG_SPLIT_NO_EMPTY)) >= 3) break; continue; }
            if (($m['via'] ?? '') !== 'bot') break; if (preg_match('/[\x{061F}?]\s*$/u', $mt)) $askedBefore++; else break; }
        $isQ = !empty($r['clarify']) || preg_match('/[؟?]\s*$/u', trim((string) $r['reply']));
        if (!$isTest && ($repeat || ($isQ && $askedBefore >= max(1, (int) $c['max_clarify']) && empty($r['handoff'])))) { // a test number always gets the reply, so the owner sees what the bot says
            $why2 = $repeat ? 'same reply as before — handed to the team' : 'already asked to clarify — handed to the team';
            if (!$isTest) { wi_update('bot_paused.json', function ($P) use ($aid, $x, $c) { $P[$aid][$x['jid']] = ['at' => date('c'), 'by' => 'handoff', 'until' => date('c', time() + max(1, (int) $c['human_pause']) * 3600)]; return $P; }); }
            wi_update('bot_drafts.json', function ($d) use ($aid, $x, $text, $r, $why2) { $d[$aid][$x['jid']] = ['jid' => $x['jid'], 'name' => $x['name'] ?? '', 'q' => mb_substr($text, 0, 600), 'reply' => $r['reply'], 'confidence' => $r['confidence'], 'handoff' => true, 'model' => $r['task']['model'] ?? '', 'at' => date('c'), 'why' => $why2]; return $d; });
            wi_bot_log_add($aid, (wi_read('bot_drafts.json', [])[$aid][$x['jid']] ?? ['jid' => $x['jid'], 'why' => $why2]) + ['result' => 'draft']);
            $did[] = ['jid' => $x['jid'], 'result' => 'draft', 'why' => $why2]; continue;
        }
        // Handing over to a person: the polite "someone from the team will answer" reply is still sent,
        // then the bot steps back from this chat so it never talks over the team.
        $handoffSend = $r['handoff'] && $c['mode'] === 'auto' && $inHours && ((int) $c['per_hour'] === 0 || count($st['sent']) < (int) $c['per_hour']);
        // A short clarifying question is always safe to send (it states no facts), even with low confidence.
        $clarify = !empty($r['clarify']) || (mb_strlen($r['reply']) < 220 && preg_match('/[؟?]\s*$/u', trim($r['reply'])) && $r['confidence'] >= 0.3);
        $minConf = in_array($role, ['dreams', 'support', 'healer'], true) ? min((float) $c['min_conf'], 0.65) : (float) $c['min_conf']; // an interpretation or a caring reply is never certain: the roles send from 0.65
        $send = $c['mode'] === 'auto' && $inHours && ((int) $c['per_hour'] === 0 || count($st['sent']) < (int) $c['per_hour']) && ($isTest || $handoffSend || $clarify || $r['confidence'] >= $minConf);
        $why = $c['mode'] !== 'auto' ? 'suggest-only mode' : (!$inHours ? 'outside working hours' : (((int) $c['per_hour'] > 0 && count($st['sent']) >= (int) $c['per_hour']) ? 'hourly limit' : ($r['handoff'] ? 'handed over to a person' : ($r['confidence'] < $c['min_conf'] ? 'low confidence' : ''))));
        $log = ['role' => $role, 'jid' => $x['jid'], 'name' => $x['name'] ?? '', 'q' => mb_substr($text, 0, 600), 'reply' => $r['reply'], 'confidence' => $r['confidence'], 'handoff' => $r['handoff'], 'model' => $r['task']['model'] ?? '', 'at' => date('c')];
        if ($send) {
            $s = wi_gateway('/chat/send', ['jid' => $x['jid'], 'text' => wf_bot_style($r['reply'], $aid, $isTest ? '' : (string) $x['jid']), 'by' => 'bot', 'via' => 'bot', 'typing_ms' => (int) round(1000 * min(max(1, (float) $c['typing_max']), max((float) $c['typing_min'], mb_strlen($r['reply']) / 25) * (0.85 + mt_rand(0, 30) / 100)))], $acc); wf_bot_intro_done(!empty($s['ok']));
            $log['result'] = !empty($s['ok']) ? ($r['handoff'] ? 'handoff' : 'sent') : 'send_failed'; if (!empty($s['ok'])) { $st['sent'][] = time(); $st['last_sent'] = date('c'); } else $log['error'] = $s['error'] ?? '';
            if (!empty($s['ok']) && !$isTest && function_exists('wf_role_note')) wf_role_note($aid, explode('@', (string) $x['jid'])[0], $role, $text, (string) $r['reply'], '');
            if (!empty($s['ok']) && !$isTest && in_array($role, ['healer', 'dreams', 'support'], true) && function_exists('wf_role_fill_case')) { try { // what they told about themselves goes into their case file
                $mineIn = []; foreach (array_slice($rows, -30) as $hm) if (($hm['dir'] ?? '') === 'in') $mineIn[] = (string) ($hm['text'] ?? ''); wf_role_fill_case($aid, (string) ($x['phone'] ?? explode('@', (string) $x['jid'])[0]), $mineIn); } catch (Throwable $e) {} }
            if (!empty($s['ok']) && $r['handoff'] && !$isTest) { wi_update('bot_paused.json', function ($P) use ($aid, $x, $c) { $P[$aid][$x['jid']] = ['at' => date('c'), 'by' => 'handoff', 'until' => date('c', time() + max(1, (int) $c['human_pause']) * 3600)]; return $P; }); $log['why'] = 'handed over to a person'; }
        }
        if (!$send || !in_array($log['result'], ['sent', 'handoff'], true)) {
            wi_update('bot_drafts.json', function ($d) use ($aid, $x, $log, $why) { $d[$aid][$x['jid']] = $log + ['why' => $why]; return $d; });
            $log['result'] = $log['result'] ?? 'draft'; $log['why'] = $why;
        }
        // The private chat joins the person's case record (and, if chosen, a new case is opened when they describe symptoms).
        try { $log['case'] = wf_case_from_private($acc, $c, (string) ($x['phone'] ?? ''), $x['jid'], (string) ($x['name'] ?? ''), $text, $r); } catch (Throwable $e) {}
        wi_bot_log_add($aid, $log);
        $did[] = $log;
    }
    if (count($st['done']) > 3000) $st['done'] = array_slice($st['done'], -2000, null, true);
    $state = wi_read('bot_state.json', []); $state[$aid] = $st; wi_write('bot_state.json', $state);
    wi_ctx_set('ai_acc', null);
    return ['did' => $did];
}

/* ---------- Joining groups: the bot adds a person who asks to join (or rejoin) one of the chosen groups ---------- */
function wf_bot_join_cfg(string $aid): array { return (array) (wi_read('bot.json', [])[$aid]['join'] ?? []) + ['on' => false, 'groups' => [], 'removed' => 'readd']; }
/** The person's last membership event in a group: '' (none), 'add', 'left' (they left), 'removed' (an admin removed them). */
function wf_group_member_state(array $acc, string $grp, array $ids): string {
    $last = null; foreach ((array) (wi_gateway('/groups/events?jid=' . rawurlencode($grp), null, $acc)['events'] ?? []) as $e)
        if (in_array((string) ($e['member'] ?? ''), $ids, true) && ($last === null || strcmp((string) $e['time'], (string) $last['time']) > 0)) $last = $e;
    if (!$last) return ''; $a = (string) ($last['action'] ?? '');
    if ($a === 'remove') return ($last['by'] ?? '') !== '' && !in_array((string) $last['by'], $ids, true) ? 'removed' : 'left';
    return $a === 'add' ? 'add' : '';
}
/** Adds the person to the right group: the one they were removed from (if allowed), else the only chosen group, else the only chosen group they are not in.
 *  Returns ['result' => added|invited|already|team|limit|off, 'group' => name]. */
function wf_bot_group_join(array $acc, string $jid, string $phone): array {
    $aid = (string) $acc['id']; $J = wf_bot_join_cfg($aid); if (empty($J['on']) || !$J['groups']) return ['result' => 'off'];
    if ((int) (wi_read('group_adds.json', [])[$aid][date('Y-m-d')] ?? 0) >= (int) wi_sys('group_add_day')) return ['result' => 'limit'];
    $admin = []; foreach ((array) (wi_gateway('/groups', null, $acc)['groups'] ?? []) as $g) if (!empty($g['i_am_admin'])) $admin[$g['jid']] = (string) $g['name'];
    $groups = array_values(array_filter((array) $J['groups'], fn($g) => isset($admin[$g]))); if (!$groups) return ['result' => 'team'];
    $ids = array_values(array_filter([explode('@', $jid)[0], $phone])); $st = []; foreach ($groups as $g) $st[$g] = wf_group_member_state($acc, $g, $ids);
    $removed = array_keys(array_filter($st, fn($s) => $s === 'removed'));
    if ($removed && $J['removed'] !== 'readd') return ['result' => 'team', 'group' => $admin[$removed[0]]];
    $out = array_keys(array_filter($st, fn($s) => $s !== 'add'));
    $target = count($removed) === 1 ? $removed[0] : (count($groups) === 1 ? $groups[0] : (count($out) === 1 ? $out[0] : null));
    if (!$target) return ['result' => 'team'];
    $who = $phone !== '' ? $phone . '@s.whatsapp.net' : $jid;
    $r = wi_gateway('/groups/add', ['group' => $target, 'jid' => $who, 'private_invite' => true, 'text' => wf_msg('group_invite', ['group' => $admin[$target], 'link' => '', 'name' => ''])], $acc);
    $res = (string) ($r['result'] ?? 'failed');
    if (in_array($res, ['added', 'invited'], true)) wi_update('group_adds.json', function ($A) use ($aid) { $d = date('Y-m-d'); $A[$aid] = [$d => (int) ($A[$aid][$d] ?? 0) + 1]; return $A; });
    wi_log('bot_group_join', ['account' => $aid, 'jid' => $jid, 'group' => $target, 'result' => $res]);
    return ['result' => in_array($res, ['added', 'invited', 'already'], true) ? $res : 'team', 'group' => $admin[$target]];
}

/** Private chat → case record. Returns what was done ('' when nothing). */
function wf_case_from_private(array $acc, array $c, string $phone, string $jid, string $name, string $text, array $r): string {
    if (empty($c['case_private']) || $phone === '') return '';
    // A subscriber's own number keeps its own cases; the shared cases database is only for the owner's numbers.
    $base = !empty($acc['owner']) ? $acc : (wi_cases_account() ?: $acc); $bid = (string) $base['id'];
    $case = wf_case_get($bid, $phone);
    if (!$case) {
        if (empty($c['case_private_new']) || empty($r['symptoms'])) return '';
        $case = ['phone' => $phone, 'name' => $name, 'status' => 'new', 'created' => date('c'), 'by' => 'bot', 'manual' => true, 'groups' => [],
            'data' => ['complaint' => (string) ($r['complaint'] ?? ''), 'source' => 'private chat (' . ($acc['name'] ?? $acc['id']) . ')'], 'notes' => [['at' => date('c'), 'by' => 'bot', 'text' => 'Case opened from a private chat: ' . mb_substr($text, 0, 300)]]];
        $done = 'opened';
    } else {
        if (!empty($r['symptoms']) && !empty($r['complaint'])) $case['notes'][] = ['at' => date('c'), 'by' => 'bot', 'text' => 'From the private chat: ' . (string) $r['complaint']];
        $done = 'linked';
    }
    $new = $case; $num = (string) $acc['id'];
    wf_case_update($bid, $phone, function (?array $cur) use ($new, $num, $jid) { // on the stored case: notes added meanwhile stay
        $c = $cur ?? $new; if ($cur !== null && count((array) ($new['notes'] ?? [])) > count((array) ($cur['notes'] ?? []))) $c['notes'][] = end($new['notes']);
        $c['private'] = ['number' => $num, 'jid' => $jid, 'last' => date('c'), 'count' => (int) ($c['private']['count'] ?? 0) + 1]; return $c; });
    wf_case_snapshot($base, $phone); // the case file now holds the private chat too
    return $done;
}

/* ---------- Bot health: is the bot running, and which chats are waiting for a reply (with the reason) ---------- */
function wf_api_bot_health(string $api): void {
    // Bot → Joining groups: which groups the bot may add people to, and what to do with people an admin removed.
    if ($api === 'bot_join_cfg') { $acc = wi_current(); if (!$acc) wi_json(['ok' => false, 'error' => 'No number.']); $aid = (string) $acc['id'];
        $admin = []; foreach ((array) (wi_gateway('/groups', null, $acc)['groups'] ?? []) as $g) if (!empty($g['i_am_admin'])) $admin[] = ['jid' => $g['jid'], 'name' => (string) $g['name'], 'members' => (int) $g['members']];
        if ($_SERVER['REQUEST_METHOD'] === 'POST') { $in = wf_post(); wf_admin(); $ok = array_column($admin, 'jid');
            $J = ['on' => !empty($in['on']), 'groups' => array_values(array_intersect($ok, (array) ($in['groups'] ?? []))), 'removed' => ($in['removed'] ?? '') === 'team' ? 'team' : 'readd'];
            wi_update('bot.json', function ($B) use ($aid, $J) { $B[$aid]['join'] = $J; return $B; }); wi_log('bot_join_cfg', ['account' => $aid, 'groups' => count($J['groups'])]); }
        wi_json(['ok' => true, 'cfg' => wf_bot_join_cfg($aid), 'groups' => $admin, 'left' => max(0, (int) wi_sys('group_add_day') - (int) (wi_read('group_adds.json', [])[$aid][date('Y-m-d')] ?? 0))]); }
    if (!in_array($api, ['bot_health', 'bot_retry'], true)) return;
    $acc = wi_current(); if (!$acc) wi_json(['ok' => false, 'error' => 'No number.']); $aid = (string) $acc['id']; $c = wf_bot_run_cfg($aid);
    if ($api === 'bot_retry') {
        // Answer this chat now: un-pause it, forget "already handled", and let the next run pass the limits once.
        $in = wf_post(); $jid = wf_jid((string) ($in['jid'] ?? '')); if ($jid === '') wi_json(['ok' => false, 'error' => 'Choose a chat.']);
        wi_update('bot_paused.json', function ($P) use ($aid, $jid) { unset($P[$aid][$jid]); return $P; });
        wi_update('bot_state.json', function ($S) use ($aid, $jid) { unset($S[$aid]['done'][$jid]); $S[$aid]['retry'][$jid] = date('c'); return $S; });
        wi_log('bot_retry', ['account' => $aid, 'jid' => $jid]);
        if ($c['mode'] !== 'auto') wi_json(['ok' => true, 'note' => 'The bot is in suggest-only mode: the reply goes to Drafts.']);
        @set_time_limit(200); $r = wf_bot_tick($acc, true);
        foreach ((array) ($r['did'] ?? []) as $d) if (($d['jid'] ?? '') === $jid) wi_json(['ok' => true, 'result' => $d['result'] ?? '', 'why' => $d['why'] ?? ($d['error'] ?? '')]);
        wi_json(['ok' => true, 'result' => $r['skipped'] ?? 'queued', 'why' => '']);
    }
    $S = wi_read('bot_state.json', [])[$aid] ?? []; $paused = wf_bot_paused($aid); $h = wi_gateway('/health', null, $acc);
    $log = []; foreach (array_reverse(wi_bot_log($aid)) as $x) if (!isset($log[$x['jid'] ?? ''])) $log[$x['jid'] ?? ''] = $x;
    $team = wf_team($acc, (array) ($c['groups'] ?? [])); $waiting = [];
    foreach ((wi_gateway('/archive/chats?months=' . (int) wi_sys('months_recent'), null, $acc)['chats'] ?? []) as $x) {
        if (!empty($x['group']) || ($x['last']['dir'] ?? '') !== 'in') continue;
        $lt = strtotime((string) ($x['last']['time'] ?? '')); if (!$lt || $lt < time() - 86400 || $lt > time() - max(10, (int) $c['quiet_secs'] + 5)) continue;
        if (isset($team[wf_phone((string) ($x['phone'] ?? ''))])) continue;
        $j = (string) $x['jid']; $l = $log[$j] ?? null; $p = $paused[$j] ?? null;
        $isP = $p && (empty($p['until']) || strtotime($p['until']) > time());
        $why = $isP ? 'Paused in this chat'
            : ($c['mode'] !== 'auto' ? 'suggest-only mode' : ($l && strtotime((string) ($l['at'] ?? '')) >= $lt - 5 ? (string) (($l['why'] ?? '') ?: ($l['result'] ?? '')) : ($lt < time() - 3 * 3600 ? 'older than 3 hours' : 'not handled yet (team active or waiting)')));
        $waiting[] = ['jid' => $j, 'name' => (string) ($x['name'] ?? ''), 'phone' => (string) ($x['phone'] ?? ''), 'text' => mb_substr((string) ($x['last']['text'] ?? ''), 0, 120), 'at' => $x['last']['time'], 'why' => $why, 'paused_by' => $isP ? (string) ($p['by'] ?? '') : '', 'paused_until' => $isP ? (string) ($p['until'] ?? '') : ''];
    }
    usort($waiting, fn($a, $b) => strcmp((string) $b['at'], (string) $a['at']));
    $sent = count(array_filter((array) ($S['sent'] ?? []), fn($t) => $t > time() - 3600));
    $tickAge = !empty($S['last_tick']) ? time() - strtotime($S['last_tick']) : null;
    $state = $c['mode'] === 'off' ? 'off' : (empty($h['connected']) ? 'disconnected' : ($tickAge === null || $tickAge > 180 ? 'not running' : (((int) $c['per_hour'] > 0 && $sent >= (int) $c['per_hour']) ? 'hourly limit' : 'ok')));
    wi_json(['ok' => true, 'state' => $state, 'mode' => $c['mode'], 'connected' => !empty($h['connected']), 'last_tick' => $S['last_tick'] ?? null, 'last_sent' => $S['last_sent'] ?? null,
        'sent_hour' => $sent, 'per_hour' => (int) $c['per_hour'], 'waiting' => array_slice($waiting, 0, 50)]);
}
