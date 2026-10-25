<?php
// WhatsApp Inbox — meetings (split out of features.php in v3.8, code unchanged)

/* ---------- Meetings: a gateway in front of Google Meet (fixed link, password, host opens, limit) ---------- */
/** A meeting this user may change: super, a meeting of one of their numbers, or one they created. */
/** Text of the public meeting pages, in the language of the system messages (Settings → System → msg_lang). */
function wf_mt(string $en): string { static $d = null; if (wi_sys('msg_lang') === 'en') return $en; $d ??= (array) require dirname(__DIR__) . '/i18n.php'; return (string) ($d[$en] ?? $en); }
/** A meeting of a private number is only for that number's owner and team (super admin included). */
/** A meeting belongs to its number: only users who work on that number (wi_my_accounts) reach it — never another client or the operator.
 *  A meeting whose number is gone: its creator only (an old one without a number: the operator's super admin too). */
function wf_meet_acc_ok(array $m): bool { $id = (string) ($m['acc'] ?? ''); $a = wi_accounts()[$id] ?? null;
    if ($a) return wi_may_see_account($a) && (wi_is_super() || isset(wi_my_accounts()[$id]));
    return ($id === '' && wi_is_super()) || (strtolower((string) ($m['by'] ?? '')) !== '' && strtolower((string) ($m['by'] ?? '')) === strtolower((string) ($_SESSION['email'] ?? ''))); }
function wf_meeting_mine(array $m): bool { if (!wf_meet_acc_ok($m)) return false; return wi_is_super() || isset(wi_my_accounts()[$m['acc'] ?? '']) || strtolower((string) ($m['by'] ?? '')) === strtolower((string) ($_SESSION['email'] ?? '')); }
function wf_meetings(): array { return wi_read('meetings.json', []); }
function wf_meet_public(array $m): array { return array_intersect_key($m, array_flip(['id', 'acc', 'invite', 'slug', 'title', 'desc', 'mode', 'limit', 'hosts', 'status', 'archived', 'fields', 'opened_at', 'opened_by', 'closed_at', 'created', 'by', 'ask_phone', 'record', 'transcript', 'space', 'rec_note', 'approve', 'auto_close', 'auto_after_host', 'host_in_at', 'ui', 'template'])) + ['when' => wf_meet_when($m), 'has_password' => !empty($m['pass']), 'has_link' => !empty($m['url']), 'joins' => count($m['joins'] ?? []), 'pending' => count(array_filter($m['joins'] ?? [], fn($j) => ($j['state'] ?? '') === 'pending' && $j['at'] >= ($m['opened_at'] ?? ''))), 'joined_now' => count(array_filter($m['joins'] ?? [], fn($j) => !empty($m['opened_at']) && $j['at'] >= $m['opened_at']))]; }
function wf_meet_wa_hosts(array $m): array { return array_map(fn($h) => ['phone' => $h['phone'], 'name' => $h['name'] ?? ''], (array) ($m['wa_hosts'] ?? [])); }
/** A host from WhatsApp (no dashboard login) uses a personal link: ?m=slug&h=token */
function wf_meet_wa_host(array $m, string $tok): ?array { if ($tok === '') return null; foreach ((array) ($m['wa_hosts'] ?? []) as $h) if (hash_equals((string) $h['token'], $tok)) return $h; return null; }
function wf_meet_can_host(array $m): bool { if (!wf_meet_acc_ok($m)) return false; return wi_is_super() || ((wi_is_admin() || (wi_me()['role'] ?? '') === 'client') && (isset(wi_my_accounts()[$m['acc'] ?? '']) || strtolower((string) ($m['by'] ?? '')) === strtolower((string) ($_SESSION['email'] ?? '')))) || in_array(strtolower((string) ($_SESSION['email'] ?? '')), array_map('strtolower', (array) ($m['hosts'] ?? [])), true); }
/** Turns automatic recording / transcript on or off for a Meet space (Google Meet API). */
function wf_meet_space_cfg(string $space, string $url, bool $rec, bool $tr): array {
    $c = wf_drive_cfg();
    if (empty($c['refresh_token'])) return ['ok' => false, 'error' => 'Connect your Google account first.'];
    if (strpos((string) ($c['scopes'] ?? ''), 'meetings.space') === false) return ['ok' => false, 'error' => 'Reconnect Google (Disconnect → Connect) and allow “Google Meet” so recording can be switched on automatically.'];
    $t = wf_drive_token(); if (empty($t['ok'])) return $t;
    if ($space === '' && $url !== '') { // a link made elsewhere: find its space (works only for meetings this Google account created)
        $code = preg_replace('#^https://meet\.google\.com/#', '', strtok($url, '?'));
        $g = wf_drive_req('GET', 'https://meet.googleapis.com/v2/spaces/' . rawurlencode($code), $t['token']);
        $space = (string) ($g['body']['name'] ?? '');
        if ($space === '') return ['ok' => false, 'error' => 'This Meet link was not made by the connected Google account — press “Create automatically” to make one that can record.'];
    }
    $cfg = ['artifactConfig' => ['recordingConfig' => ['autoRecordingGeneration' => $rec ? 'ON' : 'OFF'], 'transcriptionConfig' => ['autoTranscriptionGeneration' => $tr ? 'ON' : 'OFF']]];
    $r = wf_drive_req('PATCH', 'https://meet.googleapis.com/v2/' . $space . '?updateMask=config.artifactConfig.recordingConfig.autoRecordingGeneration,config.artifactConfig.transcriptionConfig.autoTranscriptionGeneration', $t['token'], json_encode(['config' => $cfg]), ['Content-Type: application/json']);
    if ($r['code'] >= 200 && $r['code'] < 300) return ['ok' => true, 'space' => $space];
    $msg = is_array($r['body']) ? (string) ($r['body']['error']['message'] ?? '') : '';
    return ['ok' => false, 'error' => 'Google refused automatic recording (HTTP ' . $r['code'] . ($msg ? ': ' . $msg : '') . '). Recording needs a Google Workspace plan that includes Meet recording — a free Gmail account cannot record.'];
}
function wf_meet_enter(array $m, string $name): void {
    $e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
            $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
            if (!preg_match('/Android|iPhone|iPad|iPod|Mobile/i', $ua)) { header('Location: ' . $m['url']); exit; }
            // Phones: buttons for the Meet app (Android intent / iOS tap on the link) or the browser.
            $code = preg_replace('#^https://meet\.google\.com/#', '', (string) $m['url']);
            $android = 'intent://meet.google.com/' . $code . '#Intent;scheme=https;package=com.google.android.apps.meetings;S.browser_fallback_url=' . rawurlencode((string) $m['url']) . ';end';
            $app = preg_match('/Android/i', $ua) ? $android : (string) $m['url'];
            echo wf_meet_shell($m, (string) $m['title'], ['top' => '<span class="pill live"><i></i>' . $e(wf_meet_ui(['ui' => wf_meet_ui_full($m)], 'pill_open', wf_mt('Open now'))) . '</span><h1 dir="auto">' . $e($m['title']) . '</h1>',
                'mid' => '<p class="s">' . wf_mt('If the app is not installed, it opens in the browser or asks you to install it.') . '</p>',
                'bot' => '<a class="a" href="' . $e($app) . '">' . wf_mt('📱 Open in the Google Meet app') . '</a><a class="b" href="' . $e($m['url']) . '" target="_blank" rel="noopener">' . wf_mt('🌐 Open in the browser') . '</a>'], ['state' => 'enter']);
    exit;
}
/** The wording and colours of a meeting's public pages: what the host wrote in the meeting's settings, else the built-in text. */
function wf_meet_ui(array $m, string $k, string $default): string { $v = trim((string) ($m['ui'][$k] ?? '')); return $v !== '' ? $v : $default; }
function wf_meet_ui_keys(): array { return ['accent', 'accent2', 'bg1', 'bg2', 'logo', 'waiting_text', 'waiting_note', 'form_text', 'form_btn', 'name_label', 'phone_label', 'pass_label', 'pending_text', 'pending_note', 'approved_text', 'rejected_text', 'full_text', 'missing_text', 'host_text']; }
/** A host has entered: guests who gave their details are accepted without waiting (setting "after a host has entered"). */
function wf_meet_host_in(array &$all, string $id): void { foreach ($all as &$x) if ($x['id'] === $id) $x['host_in_at'] = date('c'); unset($x); }
function wf_meet_auto_ok(array $m): bool { return !empty($m['auto_after_host']) && ($m['status'] ?? '') === 'open' && !empty($m['opened_at']); } // only a host can open the meeting, so once it is open the guests are let in at once
function wf_meet_pending_html(array $m, callable $e): string {
    $p = array_values(array_filter($m['joins'] ?? [], fn($j) => ($j['state'] ?? '') === 'pending' && $j['at'] >= ($m['opened_at'] ?? '')));
    if (!$p) return '<p class="m">' . wf_mt('Nobody is waiting for approval right now.') . '</p>';
    $h = '<p class="s"><b>' . wf_mt('⏳ Waiting for your approval (') . count($p) . ')</b></p><div class="rows">';
    foreach ($p as $j) $h .= '<form method="post" class="row" style="margin:0"><span dir="auto">' . $e($j['name']) . ($j['phone'] ? ' <small dir="ltr">+' . $e($j['phone']) . '</small>' : '') . '</span><input type="hidden" name="j" value="' . $e($j['id']) . '"><button name="do" value="ok">✔</button><button class="b" name="do" value="no">✖</button></form>';
    return $h . '</div><form method="post"><button class="a" name="do" value="okall">' . wf_mt('✔ Let everyone in') . '</button></form>';
}
/** Public page for one meeting (no login): waiting room, name/password form, then redirect to Meet. */
/** Sends the meeting link to everyone concerned from the meeting's number: the ticked groups and chats, the dashboard hosts (by phone) and the WhatsApp hosts (their private link). Returns the counts. */
function wf_meet_notify(array $m): array {
    $acc = wi_accounts()[(string) ($m['acc'] ?? '')] ?? null; if (!$acc) return ['sent' => 0, 'total' => 0];
    $link = wi_sys('base_url') . '?m=' . $m['slug']; $when = wf_meet_when($m); $text = wf_msg('meeting_invite', ['title' => (string) ($m['title'] ?? ''), 'link' => $link, 'desc' => (string) ($m['desc'] ?? ''), 'when' => $when > time() ? wf_meet_fmt($when) : '']);
    $to = []; foreach ((array) ($m['invite']['jids'] ?? []) as $j) $to[$j] = $text;
    foreach ((array) ($m['hosts'] ?? []) as $e) { $u = wi_find_user((string) $e); $ph = $u ? wf_phone((string) ($u['phone'] ?? '')) : ''; if ($ph !== '') $to[$ph . '@s.whatsapp.net'] ??= $text; }
    $hb = $link . '&h='; foreach ((array) ($m['wa_hosts'] ?? []) as $h) $to[$h['phone'] . '@s.whatsapp.net'] = wf_msg('meeting_host', ['title' => (string) ($m['title'] ?? ''), 'link' => $hb . $h['token']]);
    $sent = 0; $i = 0; @set_time_limit(300);
    foreach ($to as $jid => $t) { if ($i++) usleep(1500000); $r = wi_gateway('/chat/send', ['jid' => $jid, 'text' => $t, 'by' => (string) ($_SESSION['email'] ?? '')], $acc); if (!empty($r['ok'])) $sent++; }
    wi_log('meeting_notify', ['id' => (string) $m['id'], 'sent' => $sent, 'total' => count($to)]);
    return ['sent' => $sent, 'total' => count($to)];
}
/* ---------- the public page of a meeting: its look and every text are the host's choice (m['ui']), all optional ---------- */
/** Fonts the page may use: key => [CSS family, Google Fonts family ('' = none to load)]. */
function wf_meet_fonts(): array {
    return ['cairo' => ["'Cairo'", 'Cairo:wght@400;600;700;800'], 'tajawal' => ["'Tajawal'", 'Tajawal:wght@400;500;700;800'], 'ibm' => ["'IBM Plex Sans Arabic'", 'IBM+Plex+Sans+Arabic:wght@400;500;600;700'],
        'noto' => ["'Noto Kufi Arabic'", 'Noto+Kufi+Arabic:wght@400;600;700'], 'system' => ['system-ui', '']];
}
/** What the host may set on the page, by kind: colours, choices (allowed values), https images, texts. */
function wf_meet_ui_spec(): array {
    return ['colors' => ['accent', 'accent2', 'bg1', 'bg2', 'text', 'muted', 'card'],
        'choices' => ['theme' => ['dark', 'light', 'auto'], 'font' => array_keys(wf_meet_fonts()), 'width' => ['narrow', 'normal', 'wide'], 'align' => ['center', 'start'], 'text_align' => ['center', 'start'], 'text_size' => ['large', 'normal', 'xl'], 'rounded' => ['1', '0'], 'show_note' => ['1', '0'], 'show_phone' => ['0', '1'], 'pattern' => ['1', '0'], 'chips' => ['1', '0'], 'style' => ['glass', 'band'], 'palette' => ['night', 'day', 'violet']],
        'images' => ['logo', 'cover'],
        'texts' => ['org_name', 'waiting_text', 'waiting_note', 'form_text', 'form_btn', 'name_label', 'phone_label', 'pass_label', 'pending_text', 'pending_note', 'approved_text', 'rejected_text', 'full_text', 'missing_text', 'closed_text', 'host_text',
            'pill_waiting', 'pill_open', 'pill_pending', 'pill_full', 'pill_closed', 'countdown_label', 'footer']];
}
/** Custom CSS of the page (super admin only), made harmless: no tags, comments, imports, outside addresses or script-like values. */
function wf_meet_css_clean(string $css): string {
    $css = str_replace(['<', '>', '\\'], '', $css);
    $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);
    $css = (string) preg_replace('/@import[^;]*;?/i', '', $css);
    $css = (string) preg_replace('/url\s*\([^)]*\)?/i', 'none', $css);
    $css = (string) preg_replace('/(expression|javascript|vbscript|behavior|-moz-binding)\s*[:(]?/i', '', $css);
    return mb_substr(trim($css), 0, 4000);
}
/** The page settings sent by the editor, cleaned. A host who is not the super admin keeps the custom CSS that was there. */
function wf_meet_ui_clean(array $in, bool $super, array $old = []): array {
    $S = wf_meet_ui_spec(); $ui = [];
    foreach ($S['colors'] as $k) if (preg_match('/^#[0-9a-f]{6}$/i', trim((string) ($in[$k] ?? '')))) $ui[$k] = strtolower(trim((string) $in[$k]));
    foreach ($S['choices'] as $k => $ok) { $v = (string) ($in[$k] ?? ''); if (in_array($v, $ok, true)) $ui[$k] = $v; } // kept even when it is the default: it wins over the template's value
    foreach ($S['images'] as $k) { $v = trim((string) ($in[$k] ?? '')); if (preg_match('#^https://[^\s"\'<>()\\\\]{4,300}$#i', $v)) $ui[$k] = $v; }
    foreach ($S['texts'] as $k) { $v = trim(str_replace(["\r\n", "\r"], "\n", (string) ($in[$k] ?? ''))); if ($v !== '') $ui[$k] = mb_substr(strip_tags($v), 0, 400); }
    $r = (int) ($in['refresh'] ?? 0); if ($r >= 5 && $r <= 120 && $r !== 15) $ui['refresh'] = $r;
    $ph = preg_replace('/\D/', '', (string) ($in['org_phone'] ?? '')); if (strlen($ph) >= 8 && strlen($ph) <= 15) $ui['org_phone'] = $ph;
    $at = trim((string) ($in['schedule'] ?? '')); if (preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d$/', $at) && strtotime($at)) $ui['schedule'] = $at;
    $dl = trim((string) ($in['daily'] ?? '')); if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $dl)) $ui['daily'] = $dl; // a session held every day at the same time: the countdown always runs to the next one
    $css = $super ? wf_meet_css_clean((string) ($in['css'] ?? '')) : (string) ($old['css'] ?? ''); if ($css !== '') $ui['css'] = $css;
    return $ui;
}
/** The zone of the meeting times (the system's time zone, Cairo by default). */
function wf_meet_tz(): DateTimeZone { try { return new DateTimeZone((string) (wi_sys('timezone') ?: 'Africa/Cairo')); } catch (Throwable $e) { return new DateTimeZone('Africa/Cairo'); } }
/** When the meeting is set to start (a Unix time), or 0. The editor's time is read in the system's zone. */
function wf_meet_when(array $m): int {
    $s = (string) ($m['ui']['schedule'] ?? ''); $d = $s !== '' ? DateTime::createFromFormat('Y-m-d\TH:i', $s, wf_meet_tz()) : null;
    if ($d && $d->getTimestamp() > time() - 3600) return $d->getTimestamp(); // a one-off time, until an hour after it
    $dl = (string) ($m['ui']['daily'] ?? ''); if ($dl === '') return $d ? $d->getTimestamp() : 0;
    $n = new DateTime('now', wf_meet_tz()); [$h, $i] = array_map('intval', explode(':', $dl)); $t = (clone $n)->setTime($h, $i); // every day: the next occurrence (today, or tomorrow once it passed by an hour)
    if ($t->getTimestamp() < $n->getTimestamp() - 3600) $t->modify('+1 day');
    return $t->getTimestamp();
}
function wf_meet_fmt(int $t): string { return (new DateTime('@' . $t))->setTimezone(wf_meet_tz())->format('Y-m-d H:i'); }

/* ---------- looks: colour sets (each with its dark and light version) and the ready templates ---------- */
/** Colour sets: background top / bottom, text, muted text, card, border, input, chip and countdown box. */
function wf_meet_palettes(): array {
    $p = fn($b1, $b2, $tx, $mu, $cd, $bd, $in, $ch, $bx) => compact('b1', 'b2', 'tx', 'mu', 'cd', 'bd', 'in', 'ch', 'bx');
    return ['night' => ['dark' => $p('#1e3a5f', '#0b1220', '#f4efe4', '#bba77a', 'rgba(255,255,255,.06)', 'rgba(212,178,106,.35)', 'rgba(11,18,32,.5)', 'rgba(255,255,255,.07)', 'rgba(11,18,32,.55)'),
            'light' => $p('#faf6ee', '#f1e7d2', '#13233d', '#7a6a45', 'rgba(255,255,255,.72)', 'rgba(184,146,63,.38)', '#ffffff', 'rgba(19,35,61,.05)', '#fffaf0')],
        'day' => ['light' => $p('#f0fdf4', '#eef2ff', '#0f172a', '#64748b', '#ffffff', '#e2e8f0', '#ffffff', '#f8fafc', '#f8fafc'),
            'dark' => $p('#0b1120', '#0f172a', '#e2e8f0', '#94a3b8', '#111a2e', '#1e293b', '#0d1526', 'rgba(148,163,184,.1)', '#0d1526')],
        'violet' => ['dark' => $p('#3b1d6e', '#0f0a1f', '#f5f3ff', '#c4b5fd', 'rgba(255,255,255,.07)', 'rgba(196,181,253,.35)', 'rgba(15,10,31,.5)', 'rgba(255,255,255,.08)', 'rgba(15,10,31,.55)'),
            'light' => $p('#f5f3ff', '#ede9fe', '#1e1b4b', '#6d5bd0', 'rgba(255,255,255,.75)', 'rgba(139,92,246,.3)', '#ffffff', 'rgba(30,27,75,.05)', '#faf5ff')]];
}
/** The ready templates (read only). A user's own templates are saved per tenant (wf_meet_tpl_*). */
function wf_meet_tpl_shipped(): array {
    return ['night' => ['name' => 'Night and gold', 'ui' => ['palette' => 'night', 'accent' => '#d4b26a', 'accent2' => '#b8923f', 'theme' => 'dark', 'style' => 'glass', 'font' => 'cairo', 'pattern' => '1']],
        'day' => ['name' => 'Warm daylight', 'ui' => ['palette' => 'day', 'accent' => '#0f766e', 'accent2' => '#10b981', 'theme' => 'light', 'style' => 'band', 'font' => 'tajawal', 'pattern' => '1']],
        'violet' => ['name' => 'Modern violet', 'ui' => ['palette' => 'violet', 'accent' => '#8b5cf6', 'accent2' => '#ec4899', 'theme' => 'dark', 'style' => 'glass', 'font' => 'ibm', 'pattern' => '1']]];
}
/** The templates a tenant made ('' = the operator's own), newest first. */
function wf_meet_tpl_own(string $ws): array { $T = (array) (wi_read('meet_tpl.json', [])[strtolower($ws)] ?? []); uasort($T, fn($a, $b) => strcmp((string) ($b['updated'] ?? ''), (string) ($a['updated'] ?? ''))); return $T; }
/** Saves a tenant's template (new or the same id again); the ready ones cannot be changed. Returns the id, or an error starting with "!". */
function wf_meet_tpl_save(string $ws, array $in, bool $super): string {
    $ws = strtolower($ws); $id = preg_replace('/[^a-z0-9]/', '', strtolower((string) ($in['id'] ?? ''))); $name = mb_substr(trim(strip_tags((string) ($in['name'] ?? ''))), 0, 60);
    if (isset(wf_meet_tpl_shipped()[$id])) return '!' . wf_mt('A ready template cannot be changed — save it under a new name.');
    if ($name === '') return '!' . wf_mt('Give the template a name.');
    $own = wf_meet_tpl_own($ws); if ($id !== '' && !isset($own[$id])) $id = '';
    if ($id === '' && count($own) >= 20) return '!' . wf_mt('You have 20 templates already — delete one first.');
    $id = $id !== '' ? $id : 't' . bin2hex(random_bytes(5)); $ui = wf_meet_ui_clean((array) ($in['ui'] ?? []), $super, (array) ($own[$id]['ui'] ?? []));
    wi_update('meet_tpl.json', function ($T) use ($ws, $id, $name, $ui) { $o = (array) ($T[$ws][$id] ?? []); $T[$ws][$id] = ['name' => $name, 'ui' => $ui, 'created' => (string) ($o['created'] ?? date('c')), 'updated' => date('c')]; return $T; });
    return $id;
}
function wf_meet_tpl_delete(string $ws, string $id): bool { $ws = strtolower($ws); if (!isset(wf_meet_tpl_own($ws)[$id])) return false; wi_update('meet_tpl.json', function ($T) use ($ws, $id) { unset($T[$ws][$id]); return $T; }); return true; }
/** A template id the open number may use (a ready one or one of its tenant's), else ''. */
function wf_meet_tpl_id(string $id): string { $id = preg_replace('/[^a-z0-9]/', '', strtolower($id)); return isset(wf_meet_tpl_shipped()[$id]) || isset(wf_meet_tpl_own(wi_ws(wi_current() ?: []))[$id]) ? $id : ''; }
/** A meeting's settings over its template's (a ready one, or one of its tenant's), so every page and text has a value. */
function wf_meet_ui_full(?array $m): array {
    $id = (string) ($m['template'] ?? ''); $S = wf_meet_tpl_shipped(); $base = $S['night']['ui'];
    if (isset($S[$id])) $base = $S[$id]['ui'];
    elseif ($id !== '' && $m) { $acc = wi_accounts()[(string) ($m['acc'] ?? '')] ?? null; $own = wf_meet_tpl_own($acc ? wi_ws($acc) : ''); if (isset($own[$id])) $base = (array) $own[$id]['ui'] + $base; }
    // colours the old editor saved by itself (its built-in defaults, sent with every save) are not the host's choice: the template's apply
    $mine = array_filter((array) ($m['ui'] ?? []), fn($v, $k) => !in_array([$k, strtolower((string) $v)], [['bg1', '#ecfdf5'], ['bg2', '#eef2ff'], ['accent', '#12b886'], ['accent2', '#12b886'], ['text', '#0f172a'], ['card', '#ffffff']], true), ARRAY_FILTER_USE_BOTH);
    return $mine + $base;
}
/** The soft star-and-circle pattern, as an inline SVG in the given colour (no outside image). */
function wf_meet_pattern(string $hex): string {
    $svg = "<svg xmlns='http://www.w3.org/2000/svg' width='72' height='72' viewBox='0 0 72 72'><g fill='none' stroke='" . $hex . "' stroke-width='1.1'>"
        . "<path d='M36 8l8.2 19.8L64 36l-19.8 8.2L36 64l-8.2-19.8L8 36l19.8-8.2z'/><path d='M36 16l14.1 5.9L56 36l-5.9 14.1L36 56l-14.1-5.9L16 36l5.9-14.1z'/><circle cx='36' cy='36' r='6'/><circle cx='0' cy='0' r='9'/><circle cx='72' cy='0' r='9'/><circle cx='0' cy='72' r='9'/><circle cx='72' cy='72' r='9'/></g></svg>";
    return 'url("data:image/svg+xml,' . rawurlencode($svg) . '")';
}
/** Dark text on a light accent (gold), white text on a dark one. */
function wf_meet_on(string $hex): string { $h = ltrim($hex, '#'); $l = (0.299 * hexdec(substr($h, 0, 2)) + 0.587 * hexdec(substr($h, 2, 2)) + 0.114 * hexdec(substr($h, 4, 2))) / 255; return $l > 0.6 ? '#0b1220' : '#ffffff'; }

/** Every public page of a meeting in one look: head, colours, brand row, the card holding $inner, the footer and the state check.
 *  $o: state (shown on <body>), poll (ask for the state every few seconds), preview (inside the editor: never asks). */
function wf_meet_shell(?array $m, string $title, $inner, array $o = []): string {
    $z = is_array($inner) ? $inner + ['top' => '', 'mid' => '', 'bot' => ''] : ['top' => '', 'mid' => (string) $inner, 'bot' => ''];
    $e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); $u = wf_meet_ui_full($m); $en = wi_sys('msg_lang') === 'en';
    $theme = in_array($u['theme'] ?? '', ['dark', 'light', 'auto'], true) ? (string) $u['theme'] : 'dark'; $P = wf_meet_palettes()[(string) ($u['palette'] ?? 'night')] ?? wf_meet_palettes()['night'];
    $hex = fn($k, $d) => preg_match('/^#[0-9a-f]{6}$/i', (string) ($u[$k] ?? '')) ? (string) $u[$k] : $d;
    $a1 = $hex('accent', '#d4b26a'); $a2 = $hex('accent2', $a1); $font = wf_meet_fonts()[(string) ($u['font'] ?? 'cairo')] ?? wf_meet_fonts()['cairo'];
    // the host's own colours (when set) win over the colour set, in both themes
    $own = array_filter(['b1' => $u['bg1'] ?? '', 'b2' => $u['bg2'] ?? '', 'tx' => $u['text'] ?? '', 'mu' => $u['muted'] ?? '', 'cd' => $u['card'] ?? ''], fn($v) => preg_match('/^#[0-9a-f]{6}$/i', (string) $v));
    $vars = fn(array $p) => implode(';', array_map(fn($k, $v) => '--' . $k . ':' . $v, array_keys($p), array_values($own + $p)));
    $band = ($u['style'] ?? 'glass') === 'band'; $pat = ($u['pattern'] ?? '1') !== '0';
    $w = ['narrow' => 520, 'normal' => 640, 'wide' => 760][(string) ($u['width'] ?? 'normal')] ?? 640; $r = ($u['rounded'] ?? '1') === '0' ? '8px' : '22px';
    $css = ':root{--a1:' . $a1 . ';--a2:' . $a2 . ';--bt:' . wf_meet_on($a1) . ';--w:' . $w . 'px;--r:' . $r . ';' . $vars($P[$theme === 'light' ? 'light' : 'dark'] ?? reset($P)) . '}'
        . ($theme === 'auto' ? '@media (prefers-color-scheme:light){:root{' . $vars($P['light']) . '}}' : '')
        . '*{box-sizing:border-box}html,body{margin:0}body{min-height:100vh;min-height:100dvh;font:16px/1.6 ' . $font[0] . ',Tahoma,system-ui,sans-serif;color:var(--tx);-webkit-font-smoothing:antialiased;'
        . 'background:radial-gradient(130% 75% at 50% 0%,var(--b1),var(--b2)) fixed;background-color:var(--b2)}'
        . ($pat && !$band ? 'body:before{content:"";position:fixed;inset:0;background:' . wf_meet_pattern($a1) . ' center/120px;opacity:.10;pointer-events:none}' : '')
        . '.band{position:absolute;inset:0 0 auto 0;height:220px;background:' . ($pat ? wf_meet_pattern('#ffffff') . ' center/120px,' : '') . 'linear-gradient(135deg,var(--a1),var(--a2))}'
        // one column on an 8px rhythm: header 32px from the top, the card in the middle of what is left, the footer 24px above the bottom
        . '.wrap{position:relative;min-height:100vh;min-height:100dvh;display:flex;flex-direction:column;gap:32px;max-width:calc(var(--w) + 48px);margin:0 auto;padding:32px 24px 24px;' . (($u['align'] ?? 'center') === 'start' ? 'margin-inline-start:max(16px,6vw)' : '') . '}'
        . '.mid{flex:1;display:flex;flex-direction:column;justify-content:center}'
        . '.brand{display:flex;align-items:center;gap:12px;' . ($band ? 'color:#fff' : '') . '}.mark{width:40px;height:40px;flex:none;border-radius:12px;display:grid;place-items:center;font-size:17px;font-weight:800;color:var(--bt);background:linear-gradient(135deg,var(--a1),var(--a2));box-shadow:0 8px 18px -10px var(--a1)}'
        . '.brand img{height:40px;max-width:140px;object-fit:contain;border-radius:10px}.brand b{display:block;font-size:16px;font-weight:700;line-height:1.3}.brand small{display:block;font-size:12px;line-height:1.4;' . ($band ? 'opacity:.8' : 'color:var(--mu)') . '}'
        // the card is a tall panel (three quarters of the screen, the same size in every state): the head at the top, the content in the middle of the free space, the action at the bottom
        . '.card{display:flex;flex-direction:column;min-height:75vh;min-height:75dvh;background:var(--cd);border:1px solid var(--bd);border-radius:var(--r);overflow:hidden;-webkit-backdrop-filter:blur(14px);backdrop-filter:blur(14px);box-shadow:0 30px 70px -32px rgba(0,0,0,.55)}.cover{display:block;width:100%;aspect-ratio:16/6;object-fit:cover;flex:none}'
        // inside the card: 20px between blocks; the head (pill 12px title 8px description) and the joined pairs closer
        . '.in{flex:1;display:flex;flex-direction:column;gap:24px;padding:32px}.in *{margin:0}'
        . '.z-top{display:flex;flex-direction:column}.z-top>.pill{align-self:flex-start}.z-top>.pill+h1{margin-top:12px}.z-top>h1+.d{margin-top:8px}.z-top>.chips{margin-top:16px}'
        . (($u['text_align'] ?? 'center') === 'start' ? '' : '.z-top,.z-mid{text-align:center;align-items:center}.z-top>.pill{align-self:center}.chips{justify-content:center}.z-mid .f{text-align:start}') // headings centred unless the template says otherwise; the form fields stay aligned to the reading side
        . ['normal' => '', 'large' => '.z-mid .s{font-size:22px;line-height:1.6;font-weight:600}', 'xl' => '.z-mid .s{font-size:28px;line-height:1.5;font-weight:700}'][(string) ($u['text_size'] ?? 'large')] // the message in the middle of the card (waiting, pending, full) reads from a distance
        . '.z-mid{flex:1;display:flex;flex-direction:column;justify-content:center;gap:20px}.z-bot{display:flex;flex-direction:column;gap:8px}.z-bot:empty{display:none}.z-bot .n{text-align:center}'
        . 'form.grow{flex:1;display:flex;flex-direction:column;gap:20px}form.grow .fields{flex:1;display:flex;flex-direction:column;justify-content:center;gap:12px}form.grow button{margin-top:auto}'
        . '.pill{display:inline-flex;align-items:center;gap:8px;height:30px;padding:0 14px;font-size:13px;font-weight:700;line-height:1;border-radius:999px;color:var(--a1);border:1px solid var(--bd);background:color-mix(in srgb,var(--a1) 14%,transparent)}.pill i{width:8px;height:8px;flex:none;border-radius:50%;background:currentColor}'
        . '.pill.live i{background:#34d399;box-shadow:0 0 0 4px rgba(52,211,153,.2)}.pill.full i{background:#f87171}.pill.off{opacity:.85}'
        . 'h1{font-size:28px;line-height:1.25;font-weight:800}.d{font-size:15px;line-height:1.6;color:var(--mu);white-space:pre-line}.s{font-size:16px;line-height:1.7;white-space:pre-line}.m{color:var(--mu);font-size:13px;line-height:1.6;white-space:pre-line}'
        . '.chips{display:flex;flex-wrap:wrap;gap:8px}.chip{display:inline-flex;align-items:center;gap:6px;height:34px;padding:0 14px;font-size:13px;font-weight:600;line-height:1;white-space:nowrap;border-radius:999px;background:var(--ch);border:1px solid var(--bd)}'
        . '.cdb{display:flex;flex-direction:column;gap:16px}.cdl{color:var(--mu);font-size:13px;line-height:1.4}.cd{display:grid;grid-auto-flow:column;grid-auto-columns:1fr;gap:12px;direction:ltr}'
        . '.cd div{display:flex;flex-direction:column;align-items:center;gap:4px;background:var(--bx);border:1px solid var(--bd);border-radius:16px;padding:16px 8px}.cd b{font-size:32px;font-weight:800;line-height:1;color:var(--a1);font-variant-numeric:tabular-nums}.cd small{color:var(--mu);font-size:12px;line-height:1.2}'
        . '.ghost{display:flex;align-items:center;justify-content:center;gap:10px;height:52px;border-radius:14px;border:1px dashed var(--bd);color:var(--mu);font-size:15px;font-weight:800;background:var(--ch)}'
        . '.dot{width:9px;height:9px;border-radius:50%;background:var(--a1);animation:p 1.6s ease-in-out infinite}@keyframes p{0%,100%{opacity:.3;transform:scale(.8)}50%{opacity:1;transform:scale(1.2)}}@media (prefers-reduced-motion:reduce){.dot{animation:none}}'
        // the form: 12px between fields, 6px between a label and its box, 20px before the button
        . 'form{display:flex;flex-direction:column;gap:12px;margin:0}.f{display:flex;flex-direction:column;gap:6px}label{font-size:13px;font-weight:700;line-height:1.4}'
        . 'input{width:100%;height:48px;font:inherit;font-size:15px;padding:0 14px;border-radius:12px;border:1px solid var(--bd);background:var(--in);color:inherit}input:focus{outline:3px solid color-mix(in srgb,var(--a1) 35%,transparent);border-color:var(--a1)}'
        . 'button,.a{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;height:52px;text-decoration:none;font:inherit;font-size:15px;font-weight:800;border:0;border-radius:14px;padding:0 16px;margin:0;cursor:pointer;color:var(--bt);background:linear-gradient(135deg,var(--a1),var(--a2));box-shadow:0 14px 28px -16px var(--a1)}'
        . '.b{display:flex;align-items:center;justify-content:center;width:100%;height:52px;text-decoration:none;font:inherit;font-size:15px;font-weight:700;border-radius:14px;padding:0 16px;margin:0;cursor:pointer;color:var(--tx);background:transparent;border:1px solid var(--bd)}form .b{margin-top:0}'
        . '.e{background:rgba(248,113,113,.14);color:#f87171;border:1px solid rgba(248,113,113,.35);border-radius:12px;padding:12px 14px;font-size:14px;line-height:1.5}'
        . '.rows{display:flex;flex-direction:column}.row{display:flex;gap:8px;align-items:center;border-top:1px solid var(--bd);padding:8px 0;margin:0}.row span{flex:1}.row button,.row .b{width:auto;height:36px;margin:0;padding:0 14px;font-size:14px}'
        . '.wa{display:inline-flex;align-items:center;gap:6px;align-self:flex-start;color:var(--a1);text-decoration:none;font-weight:700;font-size:14px}'
        . '.foot{display:flex;flex-direction:column;align-items:center;gap:6px;text-align:center;color:var(--mu);font-size:12px;line-height:1.4}.foot .n{font-size:11px;opacity:.85}'
        . '@media (max-width:420px){.wrap{padding:24px 16px;gap:24px}.in{padding:24px;gap:20px}h1{font-size:24px}.cd b{font-size:26px}}'
        . (string) ($u['css'] ?? '');
    $fontLink = $font[1] !== '' ? '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=' . $font[1] . '&amp;display=swap">' : '';
    $state = (string) ($o['state'] ?? ''); $poll = !empty($o['poll']); $refresh = max(5, min(120, (int) ($u['refresh'] ?? ($state === 'pending' ? 5 : 15))));
    $brand = (string) wi_sys('brand_name'); $acc = $m ? (wi_accounts()[(string) ($m['acc'] ?? '')] ?? null) : null;
    $org = trim((string) ($u['org_name'] ?? '')) ?: trim((string) ($acc['name'] ?? '')) ?: $brand; $logo = trim((string) ($u['logo'] ?? ''));
    $noteIn = $poll && ($u['show_note'] ?? '1') !== '0' ? '<span class="n">' . $e(wf_meet_ui(['ui' => $u], $state === 'pending' ? 'pending_note' : 'waiting_note', $state === 'pending' ? wf_mt('This page refreshes by itself; you join as soon as the host agrees.') : wf_mt('This page refreshes by itself every 15 seconds.'))) . '</span>' : '';
    $h = '<!doctype html><html lang="' . ($en ? 'en" dir="ltr' : 'ar" dir="rtl') . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="' . ($theme === 'auto' ? 'dark light' : $theme) . '"><meta name="theme-color" content="' . $e(($own['b1'] ?? '') ?: ($P[$theme === 'light' ? 'light' : 'dark']['b1'] ?? '#0b1220')) . '"><title>' . $e($title) . '</title>'
        . $fontLink . ($poll ? '<noscript><meta http-equiv="refresh" content="' . $refresh . '"></noscript>' : '') . '<style>' . $css . '</style></head><body data-state="' . $e($state) . '">' . ($band ? '<div class="band"></div>' : '')
        . '<div class="wrap"><header class="brand">' . (preg_match('#^https://#', $logo) ? '<img alt="" src="' . $e($logo) . '">' : '<span class="mark">' . $e(mb_strtoupper(mb_substr($org, 0, 1))) . '</span>') . '<div><b>' . $e($org) . '</b>' . ($org !== $brand && $brand !== '' ? '<small>' . $e($brand) . '</small>' : '') . '</div></header>'
        . '<div class="mid"><main class="card">' . (preg_match('#^https://#', (string) ($u['cover'] ?? '')) ? '<img class="cover" alt="" src="' . $e($u['cover']) . '">' : '') . '<div class="in"><div class="z-top">' . $z['top'] . '</div><div class="z-mid">' . $z['mid'] . '</div><div class="z-bot">' . $z['bot'] . $noteIn . '</div></div></main></div>';
    $h .= '<footer class="foot"><span>' . $e(trim((string) ($u['footer'] ?? '')) !== '' ? (string) $u['footer'] : ($brand !== '' ? sprintf(wf_mt('Powered by %s'), $brand) : '')) . '</span></footer></div>';
    // the countdown ticks every second; the state is asked for every few seconds and the page reloads only when it changed
    // every visitor reads the set time in their own time zone (the server text, in the organiser's zone, stays for browsers without Intl)
    $js = '(function(){try{var L=document.documentElement.lang||"ar";[].forEach.call(document.querySelectorAll("[data-when]"),function(el){var d=new Date(+el.dataset.when*1000);var tz=Intl.DateTimeFormat().resolvedOptions().timeZone||"";el.textContent=new Intl.DateTimeFormat(L==="en"?"en-GB":"ar-EG",{weekday:"short",day:"numeric",month:"short",hour:"2-digit",minute:"2-digit",hour12:true}).format(d)+(tz?" ("+tz.split("/").pop().replace(/_/g," ")+")":"");el.title=tz;});}catch(e){}})();'
        . '(function(){var b=document.body;var cd=document.querySelector(".cd[data-at]");if(cd){var at=+cd.dataset.at*1000;var f=function(){var s=Math.max(0,Math.floor((at-Date.now())/1000));var v={d:Math.floor(s/86400),h:Math.floor(s%86400/3600),m:Math.floor(s%3600/60),s:s%60};'
        . 'Object.keys(v).forEach(function(k){var x=cd.querySelector("[data-k="+k+"]");if(x)x.textContent=(k==="d"?v[k]:("0"+v[k]).slice(-2));});if(s===0)setTimeout(function(){location.reload();},1500);};f();setInterval(f,1000);}'
        . ($poll && $m && empty($o['preview']) ? 'var u="?m=' . rawurlencode((string) $m['slug']) . '&poll=1";setInterval(function(){fetch(u,{cache:"no-store",credentials:"same-origin"}).then(function(r){return r.json();}).then(function(x){if(x&&x.state&&x.state!==b.dataset.state)location.reload();}).catch(function(){});},' . ($refresh * 1000) . ');' : '') . '})();';
    return $h . '<script>' . $js . '</script></body></html>';
}
/** The guest page of a meeting in one of its states (missing, waiting, closed, form, pending, rejected, full). */
function wf_meet_view(?array $m, string $state, string $err = '', bool $preview = false): string {
    $e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); $mu = $m; if ($mu) $mu['ui'] = wf_meet_ui_full($m); $u = (array) ($mu['ui'] ?? wf_meet_ui_full(null)); $t = fn($k, $d) => wf_meet_ui($mu ?? [], $k, $d);
    $title = $m ? (string) $m['title'] : wf_mt('Meeting'); $when = $m ? wf_meet_when($mu) : 0; $soon = $when > 0 && $when <= time() && in_array($state, ['waiting', 'closed'], true);
    $pill = ['missing' => ['off', $t('pill_missing', wf_mt('Not found'))], 'waiting' => ['wait', $soon ? wf_mt('Starting soon') : $t('pill_waiting', wf_mt('Not started yet'))], 'closed' => ['off', $t('pill_closed', wf_mt('Closed'))],
        'form' => ['live', $t('pill_open', wf_mt('Open now'))], 'pending' => ['wait', $t('pill_pending', wf_mt('Waiting for approval'))], 'rejected' => ['off', $t('pill_closed', wf_mt('Closed'))], 'full' => ['full', $t('pill_full', wf_mt('Full'))]][$state] ?? ['off', ''];
    // three zones: the head (pill, title, description, chips), the middle (text, countdown or form), the bottom (the action and the notes)
    $i = '<span class="pill ' . $pill[0] . '"><i></i>' . $e($pill[1]) . '</span><h1 dir="auto">' . $e($title) . '</h1>' . (!empty($m['desc']) ? '<p class="d" dir="auto">' . $e($m['desc']) . '</p>' : '')
        . ($when > 0 ? '<p class="d" dir="auto">🕒 ' . $e(wf_mt('In your time zone')) . ': <span dir="ltr" data-when="' . $when . '">' . $e(wf_meet_fmt($when)) . '</span></p>' : ''); $mid = ''; $bot = ''; // the organiser's wording stays; the visitor's own time is written under it
    // what to know before joining: when, how one gets in, how many, where
    if ($m && ($u['chips'] ?? '1') !== '0' && $state !== 'missing') { $ch = [];
        if ($when > 0) $ch[] = '📅 <span dir="ltr" data-when="' . $when . '">' . $e(wf_meet_fmt($when)) . '</span>'; // the browser rewrites it in the visitor's own time zone
        $ch[] = ($m['mode'] ?? 'auto') === 'password' ? '🔒 ' . $e(wf_mt('With a password')) : '⚡ ' . $e(wf_mt('Automatic join'));
        $ch[] = !empty($m['limit']) ? '👥 ' . $e(sprintf(wf_mt('Up to %d people'), (int) $m['limit'])) : '👥 ' . $e(wf_mt('Open to everyone'));
        $ch[] = '🎥 Google Meet';
        $i .= '<div class="chips">' . implode('', array_map(fn($x) => '<span class="chip">' . $x . '</span>', $ch)) . '</div>'; }
    $text = ['missing' => $t('missing_text', wf_mt('This link is wrong or the meeting was cancelled.')), 'waiting' => $t('waiting_text', wf_mt('The meeting has not started yet — waiting for the host to open it.')),
        'closed' => $t('closed_text', wf_mt('This meeting is closed now. When the host opens it again, this page lets you in.')), 'form' => (string) ($u['form_text'] ?? ''),
        'pending' => $t('pending_text', wf_mt('Your request was sent ✅ — wait until the host lets you in.')), 'rejected' => $t('rejected_text', wf_mt('The host did not let you into this meeting.')), 'full' => $t('full_text', wf_mt('The meeting is full.'))][$state] ?? '';
    if ($text !== '') $mid .= '<p class="s" dir="auto">' . $e($text) . '</p>';
    if (in_array($state, ['waiting', 'closed'], true)) {
        if ($when > time()) { $days = $when - time() >= 86400; $box = fn($k, $l) => '<div><b data-k="' . $k . '">00</b><small>' . $e($l) . '</small></div>';
            $mid .= '<div class="cdb"><div class="cdl">' . $e($t('countdown_label', wf_mt('Starts in'))) . '</div><div class="cd" data-at="' . $when . '">' . ($days ? $box('d', wf_mt('days')) : '') . $box('h', wf_mt('hours')) . $box('m', wf_mt('minutes')) . $box('s', wf_mt('seconds')) . '</div></div>'; }
        $bot .= '<div class="ghost"><span class="dot"></span>' . $e($soon ? wf_mt('Starting soon') : ($when > time() ? $t('pill_waiting', wf_mt('Not started yet')) : wf_mt('Waiting for the host'))) . '</div>';
    } elseif ($state === 'pending') $bot .= '<div class="ghost"><span class="dot"></span>' . $e(wf_mt('Waiting for the host')) . '</div>';
    elseif ($state === 'form') { $mid .= '<form method="post" class="grow"><div class="fields"><div class="f"><label for="f-n">' . $e($t('name_label', wf_mt('Name'))) . '</label><input id="f-n" name="name" required maxlength="60" autocomplete="name" value="' . $e($_POST['name'] ?? '') . '"></div>'
        . (!empty($m['ask_phone']) ? '<div class="f"><label for="f-p">' . $e($t('phone_label', wf_mt('Mobile number'))) . '</label><input id="f-p" name="phone" inputmode="tel" autocomplete="tel" dir="ltr" required value="' . $e($_POST['phone'] ?? '') . '"></div>' : '');
        foreach ((array) ($m['fields'] ?? []) as $k => $fd) $mid .= '<div class="f"><label for="f-' . (int) $k . '">' . $e($fd['label']) . (!empty($fd['req']) ? ' *' : '') . '</label><input id="f-' . (int) $k . '" name="f[' . (int) $k . ']" dir="auto" maxlength="300"' . (!empty($fd['req']) ? ' required' : '') . ' value="' . $e(((array) ($_POST['f'] ?? []))[$k] ?? '') . '"></div>';
        $mid .= (($m['mode'] ?? 'auto') === 'password' ? '<div class="f"><label for="f-x">' . $e($t('pass_label', wf_mt('Password'))) . '</label><input id="f-x" name="pass" type="password" required autocomplete="off"></div>' : '')
            . ($err !== '' ? '<div class="e" role="alert">' . $e($err) . '</div>' : '') . '</div><button>🎥 ' . $e($t('form_btn', wf_mt('Join the meeting'))) . '</button></form>'; }
    if (($u['show_phone'] ?? '0') === '1' && !empty($u['org_phone'])) $bot .= '<a class="wa" href="https://wa.me/' . $e($u['org_phone']) . '" target="_blank" rel="noopener">💬 <span dir="ltr">+' . $e($u['org_phone']) . '</span></a>';
    return wf_meet_shell($mu, $title, ['top' => $i, 'mid' => $mid, 'bot' => $bot], ['state' => $state, 'poll' => in_array($state, ['waiting', 'closed', 'pending', 'full'], true), 'preview' => $preview]);
}
/** The meeting's log: who opened the link, who joined, who opened or closed it and when; an opening before the set time keeps how many minutes early. */
/** Lets guests into Google Meet without knocking (Quick access on), for a space the connected Google account made; our page already checked who they are. Best effort: an error changes nothing. */
function wf_meet_space_open(array $m): void {
    $sp = (string) ($m['space'] ?? ''); if ($sp === '' || empty($m['auto_after_host']) || !function_exists('wf_drive_token')) return;
    $own = strtolower((string) (wi_accounts()[(string) ($m['acc'] ?? '')]['owner'] ?? '')); if (function_exists('wf_google_use')) wf_google_use($own); // the Google account of the meeting's panel, also from a host's page without login
    try { $t = wf_drive_token(); if (!empty($t['ok'])) wf_drive_req('PATCH', 'https://meet.googleapis.com/v2/' . $sp . '?updateMask=config.accessType', $t['token'], json_encode(['config' => ['accessType' => 'OPEN']]), ['Content-Type: application/json']); } catch (Throwable $e) { wi_catch($e, 'meet space open'); }
    if (function_exists('wf_google_use')) wf_google_use(null);
}
function wf_meet_log(array &$x, string $kind, string $who, array $more = []): void {
    $row = ['at' => date('c'), 'k' => $kind, 'who' => mb_substr($who, 0, 60)] + $more + (($cc = wi_ip_country()) !== '' ? ['cc' => $cc] : []); // the visitor's country
    if ($kind === 'open' && ($w = wf_meet_when($x)) > time()) { $row['early'] = (int) ceil(($w - time()) / 60); $row['set'] = wf_meet_fmt($w); }
    $x['log'][] = $row; $x['log'] = array_slice((array) $x['log'], -1500);
}
function wf_meet_page(string $slug): void {
    $all = wf_meetings(); $m = null; foreach ($all as $x) if (($x['slug'] ?? '') === $slug && empty($x['archived'])) $m = $x; // an archived meeting's link no longer opens
    header('Content-Type: text/html; charset=utf-8'); header('X-Robots-Tag: noindex'); header('Referrer-Policy: no-referrer');
    $e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
 $err = ''; $state = 'form';
    // A meeting closes by itself some hours after it was opened, so an old link never lets people in alone.
    if ($m && ($m['status'] ?? '') === 'open' && !empty($m['opened_at']) && strtotime($m['opened_at']) < time() - 3600 * max(1, (int) ($m['auto_close'] ?? 3))) {
        foreach ($all as &$x) if ($x['id'] === $m['id']) { $x['status'] = 'closed'; $x['closed_at'] = date('c'); $m = $x; } unset($x); wi_write('meetings.json', $all);
    }
    if ($m && ($m['status'] ?? '') === 'open' && wf_meet_auto_ok($m) && array_filter((array) ($m['joins'] ?? []), fn($j) => ($j['state'] ?? '') === 'pending')) { // a host is in: whoever was waiting is accepted
        foreach ($all as &$x) if ($x['id'] === $m['id']) { foreach ($x['joins'] as &$j) if (($j['state'] ?? '') === 'pending') $j['state'] = 'approved'; unset($j); $m = $x; } unset($x); wi_write('meetings.json', $all); }
    $host = $m ? wf_meet_wa_host($m, (string) ($_GET['h'] ?? '')) : null;
    if ($m && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && !isset($_GET['poll']) && !isset($_GET['go'])) {
        $dev = substr(hash('sha256', wi_ip() . '|' . (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 12); $k = $host ? 'host_view' : 'view'; $seen = false;
        foreach (array_reverse((array) ($m['log'] ?? [])) as $r) { if (strtotime((string) $r['at']) < time() - 600) break; if (($r['dev'] ?? '') === $dev && $r['k'] === $k) { $seen = true; break; } }
        if (!$seen) { foreach ($all as &$x) if ($x['id'] === $m['id']) { wf_meet_log($x, $k, $host ? ($host['name'] ?: '+' . $host['phone']) : '', ['dev' => $dev]); $m = $x; } unset($x); wi_write('meetings.json', $all); }
    }
    if ($host) {
        // Personal host page: open/close the meeting, then go to Meet.
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            foreach ($all as &$x) if ($x['id'] === $m['id']) {
                if (in_array($_POST['do'] ?? '', ['ok', 'no', 'okall'], true)) foreach ($x['joins'] as &$j) if (($j['state'] ?? '') === 'pending' && ($_POST['do'] === 'okall' || ($j['id'] ?? '') === ($_POST['j'] ?? ''))) $j['state'] = $_POST['do'] === 'no' ? 'rejected' : 'approved';
                unset($j);
                if (($_POST['do'] ?? '') === 'open' && !empty($x['url'])) { $x['status'] = 'open'; $x['opened_at'] = date('c'); $x['opened_by'] = ($host['name'] ?: '+' . $host['phone']) . ' (WhatsApp)'; wf_meet_log($x, 'open', $x['opened_by']); wf_meet_space_open($x); }
                elseif (($_POST['do'] ?? '') === 'close') { $x['status'] = 'closed'; $x['closed_at'] = date('c'); wf_meet_log($x, 'close', ($host['name'] ?: '+' . $host['phone']) . ' (WhatsApp)'); }
                $m = $x;
            }
            unset($x); if (($_POST['do'] ?? '') === 'open') wf_meet_host_in($all, $m['id']); wi_write('meetings.json', $all);
            if (($_POST['do'] ?? '') === 'open' && !empty($m['url'])) { header('Location: ' . $m['url']); exit; }
        }
        if (isset($_GET['go']) && ($m['status'] ?? '') === 'open' && !empty($m['url'])) { wf_meet_host_in($all, $m['id']); wi_write('meetings.json', $all); header('Location: ' . $m['url']); exit; } // the host presses Join: from now on guests are accepted (if so set)
        $open = ($m['status'] ?? '') === 'open';
        $hu = ['ui' => wf_meet_ui_full($m)];
        echo wf_meet_shell($m, (string) $m['title'], ['top' => '<span class="pill ' . ($open ? 'live' : 'off') . '"><i></i>' . $e($open ? wf_meet_ui($hu, 'pill_open', wf_mt('Open now')) : wf_meet_ui($hu, 'pill_closed', wf_mt('Closed'))) . '</span><h1 dir="auto">' . $e($m['title']) . '</h1>',
            'mid' => '<p class="s" dir="auto">' . $e(wf_meet_ui($m, 'host_text', wf_mt('Hello') . ' ' . ($host['name'] ?: '') . ' ' . wf_mt('— you are one of the meeting hosts.'))) . '</p>'
            . '<p class="m">' . ($open ? wf_mt('🟢 The meeting is open now') : wf_mt('⚪ The meeting is closed — people wait until you open it')) . '</p>'
            . (!$open && ($hw = wf_meet_when($m)) > time() ? '<div class="cdb"><div class="cdl">' . $e(wf_meet_ui($hu, 'countdown_label', wf_mt('Starts in'))) . '</div><div class="cd" data-at="' . $hw . '">' . ($hw - time() >= 86400 ? '<div><b data-k="d">00</b><small>' . $e(wf_mt('days')) . '</small></div>' : '') . '<div><b data-k="h">00</b><small>' . $e(wf_mt('hours')) . '</small></div><div><b data-k="m">00</b><small>' . $e(wf_mt('minutes')) . '</small></div><div><b data-k="s">00</b><small>' . $e(wf_mt('seconds')) . '</small></div></div>'
                . '<p class="m" dir="auto">⏰ ' . $e(wf_mt('Please do not open the meeting before the set time')) . ' (<span dir="ltr" data-when="' . $hw . '">' . $e(wf_meet_fmt($hw)) . '</span>)</p></div>' : '')
            . ($open && !empty($m['approve']) ? wf_meet_pending_html($m, $e) : ''),
            'bot' => (empty($m['url']) ? '<p class="m">' . wf_mt('No Google Meet link has been added yet.') . '</p>' : ($open ? '<a class="a" href="?m=' . rawurlencode($slug) . '&h=' . rawurlencode((string) $_GET['h']) . '&go=1">' . wf_mt('🎥 Join Google Meet') . '</a><form method="post" style="margin:0"><button class="b" name="do" value="close">' . wf_mt('⏹ Close the meeting') . '</button></form>' : '<form method="post" style="margin:0"' . (($hw2 = wf_meet_when($m)) > time() ? ' onsubmit="return confirm(this.dataset.q)" data-q="' . $e(wf_mt('The set time has not come yet. Open the meeting now anyway?')) . '"' : '') . '><button name="do" value="open">' . wf_mt('▶️ Open the meeting and join') . '</button></form>'))
            . '<p class="m n">' . wf_mt('This link is yours only — do not share it.') . '</p>' . ($open ? '<script>setTimeout(function(){location.reload()},10000)</script>' : '')], ['state' => 'host']);
        exit;
    }
    if (!$m) { $state = 'missing'; }
    // closed after it was opened, with no later start set: "closed"; otherwise people wait for it to open
    elseif (($m['status'] ?? 'closed') !== 'open') { $state = !empty($m['closed_at']) && wf_meet_when($m) <= time() ? 'closed' : 'waiting'; }
    elseif (!empty($m['limit']) && count(array_filter($m['joins'] ?? [], fn($j) => $j['at'] >= $m['opened_at'])) >= (int) $m['limit']) { $state = 'full'; }
    if ($m && $state === 'form' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // Attempt limit: 8 wrong passwords per device per 15 minutes.
        $ipk = substr(hash('sha256', wi_ip() . '|' . $slug), 0, 20); $G = wi_read('meet_guard.json', []);
        $G[$ipk] = array_values(array_filter($G[$ipk] ?? [], fn($t) => $t > time() - 900));
        $extra = []; $ferr = ''; foreach ((array) ($m['fields'] ?? []) as $fi => $fd) { $v = trim(mb_substr((string) (((array) ($_POST['f'] ?? []))[$fi] ?? ''), 0, 300)); if ($v !== '') $extra[(string) $fd['label']] = $v; elseif (!empty($fd['req']) && $ferr === '') $ferr = wf_mt('Fill in:') . ' ' . $fd['label']; }
        $name = trim(mb_substr((string) ($_POST['name'] ?? ''), 0, 60)); $phone = preg_replace('/\D/', '', (string) ($_POST['phone'] ?? ''));
        if (count($G[$ipk]) >= 8) $err = '' . wf_mt('Too many attempts — try again in 15 minutes.') . '';
        elseif ($name === '') $err = '' . wf_mt('Write your name.') . '';
        elseif (!empty($m['ask_phone']) && strlen($phone) < 8) $err = '' . wf_mt('Write your mobile number.') . '';
        elseif ($ferr !== '') $err = $ferr;
        elseif (($m['mode'] ?? 'auto') === 'password' && !password_verify((string) ($_POST['pass'] ?? ''), (string) ($m['pass'] ?? ''))) { $err = '' . wf_mt('Wrong password.') . ''; $G[$ipk][] = time(); }
        wi_write('meet_guard.json', $G);
        if ($err === '') {
            $jid = bin2hex(random_bytes(8)); $ap = !empty($m['approve']) && !wf_meet_auto_ok($m);
            foreach ($all as &$x) if ($x['id'] === $m['id']) { $x['joins'][] = ['id' => $jid, 'name' => $name, 'phone' => $phone, 'extra' => $extra, 'at' => date('c'), 'ip' => substr(hash('sha256', wi_ip()), 0, 12), 'cc' => wi_ip_country(), 'state' => $ap ? 'pending' : 'approved']; $x['joins'] = array_slice($x['joins'], -2000); wf_meet_log($x, 'join', $name . ($phone !== '' ? ' +' . $phone : '')); }
            unset($x); wi_write('meetings.json', $all);
            if ($ap) { setcookie('mj_' . $m['id'], $jid, ['expires' => time() + 6 * 3600, 'path' => (string) wi_sys('cookie_path'), 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']); header('Location: ?m=' . rawurlencode($slug)); exit; }
            wf_meet_enter($m, $name); }
    }
    // Guest already asked to join: wait for the host's decision.
    $mine = $m ? (string) ($_COOKIE['mj_' . $m['id']] ?? '') : '';
    if ($m && $mine !== '' && ($m['status'] ?? '') === 'open') foreach ($m['joins'] ?? [] as $j) if (($j['id'] ?? '') === $mine && $j['at'] >= $m['opened_at']) {
        if ($j['state'] === 'approved') { if (isset($_GET['poll'])) { header('Content-Type: application/json'); echo json_encode(['state' => 'approved']); exit; } wf_meet_enter($m, $j['name']); }
        $state = $j['state'] === 'rejected' ? 'rejected' : 'pending';
    }
    if ($m && isset($_GET['preview']) && in_array($_GET['preview'], ['waiting', 'closed', 'form', 'pending', 'rejected', 'full'], true) && wi_logged_in() && wf_meet_can_host($m)) $state = (string) $_GET['preview']; // the host looks at a page as a guest sees it
    // the page asks for its state every few seconds (no reload while nothing changes)
    if (isset($_GET['poll'])) { header('Content-Type: application/json'); header('Cache-Control: no-store'); echo json_encode(['state' => $state]); exit; }
    echo wf_meet_view($m, $state, $err); exit;
}


/** API calls of this area (moved unchanged from the old single switch). */
function wf_api_meetings(string $api): void {
    switch ($api) {
    /* ----- Meetings ----- */
    case 'settings_overview': {
        // One page for the whole system: Google connection, numbers, users, AI, health, report, backup.
        // The operator's system page (its Google connection, AI, users, backup): super admin only, always the operator's own Google.
        wf_admin(); if (!wi_is_super()) wi_json(['ok' => false, 'error' => 'Super admin only.'], 403); wf_google_use(''); $c = wf_drive_cfg(); wf_google_use(null); $acc = [];
        foreach (wi_my_accounts() as $a) { $h = wi_gateway('/health', null, $a); $acc[] = ['id' => $a['id'], 'name' => $a['name'], 'connected' => !empty($h['connected']), 'payments' => !empty($a['payments']), 'bot' => wf_bot_run_cfg((string) $a['id'])['mode']]; }
        $ai = wi_ai_call(['action' => 'ai_list'], 25); $aiOn = count(array_filter($ai['providers'] ?? [], fn($p) => !empty($p['active'])));
        $rep = wi_read('report.json', []);
        wi_json(['ok' => true, 'google' => ['connected' => !empty($c['refresh_token']), 'email' => $c['email'] ?? '', 'calendar' => strpos((string) ($c['scopes'] ?? ''), 'calendar') !== false, 'drive' => strpos((string) ($c['scopes'] ?? ''), 'drive') !== false, 'drive_read' => strpos((string) ($c['scopes'] ?? ''), 'drive.readonly') !== false, 'missing' => $c['missing'] ?? array_values(array_filter(['drive.readonly', 'meetings.space.created'], fn($k) => strpos((string) ($c['scopes'] ?? ''), $k) === false)), 'connected_at' => $c['connected_at'] ?? null, 'meet' => strpos((string) ($c['scopes'] ?? ''), 'meetings.space') !== false, 'client_id' => $c['client_id'] ?? '', 'has_secret' => !empty($c['client_secret']), 'redirect' => wf_drive_redirect(), 'pending' => !empty($c['state'])],
            'accounts' => $acc, 'users' => count(wi_users()), 'ai' => ['active' => $aiOn, 'total' => count($ai['providers'] ?? []), 'mode' => $ai['mode'] ?? ''],
            'report' => ['enabled' => !empty($rep['enabled']), 'hour' => $rep['hour'] ?? 21], 'backup' => ['enabled' => !empty($c['enabled']), 'last' => $c['last'] ?? null],
            'version' => (((array) require dirname(__DIR__) . '/version.php')[0]['v'] ?? ''), 'ui_theme' => wi_config()['ui_theme'] ?? 'google', 'ui_colors' => wi_config()['ui_colors'] ?? null, 'palette' => WF_UI_PALETTE, 'canvases' => WF_UI_CANVAS]);
    }
    case 'acc_invoices': {
        // Invoices on/off for one number: payment-receipt bot, sending invoices from a chat, customer invoices, overdue reminders.
        $in = wf_post(); $id = (string) ($in['acc'] ?? ''); $acc = wi_accounts()[$id] ?? null;
        if (!$acc || !wi_is_admin() || !wi_can_manage($acc)) wi_json(['ok' => false, 'error' => 'Not allowed for this number.'], 403);
        $on = !empty($in['on']);
        if (!empty($acc['main'])) { $c = wi_config(); $c['main_payments'] = $on; wi_write('config.json', $c); }
        else { $all = wi_accounts(); $all[$id]['payments'] = $on; wi_save_accounts($all); }
        wi_log('invoices_' . ($on ? 'on' : 'off'), ['account' => $id]); wi_json(['ok' => true, 'on' => $on]);
    }
    case 'ui_colors': {
        // Studio theme: accent from the checked palette (each comes with its chart partner) + a background tone.
        $in = wf_post(); if (!wi_is_super()) wi_json(['ok' => false, 'error' => 'Only the super admin can change the colours.'], 403);
        $p = WF_UI_PALETTE[(int) ($in['accent'] ?? 0)] ?? WF_UI_PALETTE[0]; $cv = WF_UI_CANVAS[(int) ($in['canvas'] ?? 0)] ?? WF_UI_CANVAS[0];
        $c = wi_config(); $c['ui_colors'] = ['acc' => $p[1], 'acc2' => $p[2], 'canvas' => $cv[1], 'accent_i' => (int) ($in['accent'] ?? 0), 'canvas_i' => (int) ($in['canvas'] ?? 0)];
        if (!empty($in['apply'])) $c['ui_theme'] = 'studio';
        wi_write('config.json', $c); wi_log('ui_colors', $c['ui_colors']); wi_json(['ok' => true]);
    }
    case 'ui_theme': {
        // Super admin chooses the dashboard theme for everyone.
        $in = wf_post(); if (!wi_is_super()) wi_json(['ok' => false, 'error' => 'Only the super admin can change the theme.'], 403);
        $t = in_array($in['theme'] ?? '', ['google', 'classic', 'orchid', 'studio', 'aurora', 'fq-violet', 'fq-night', 'fq-ink', 'fq-dawn', 'fq-meta'], true) ? $in['theme'] : 'google';
        $c = wi_config(); $c['ui_theme'] = $t; wi_write('config.json', $c); wi_log('ui_theme', ['theme' => $t]);
        wi_json(['ok' => true]);
    }
    case 'meetings': {
        $all = wf_meetings(); $out = [];
        $myAcc = wi_my_accounts();
        foreach ($all as $m) if (wf_meet_acc_ok($m) && (wi_is_super() || isset($myAcc[$m['acc'] ?? '']) || strtolower((string) ($m['by'] ?? '')) === strtolower((string) $_SESSION['email']) || wf_meet_can_host($m))) $out[] = wf_meet_public($m) + ['wa_hosts' => wf_meet_wa_hosts($m), 'can_host' => wf_meet_can_host($m), 'url' => wf_meet_can_host($m) ? ($m['url'] ?? '') : ''];
        $users = ($ca = wi_current()) ? wi_account_people($ca) : []; // hosts to pick from: only people who work on this number
        // The super admin owns no number of their own: they pick the number a new meeting belongs to.
        $numbers = wi_is_super() ? array_values(array_map(fn($a) => ['id' => (string) $a['id'], 'name' => wi_account_label($a)], array_filter(wi_accounts(), fn($a) => !empty($a['enabled']) && empty($a['archived']) && ($a['channel'] ?? '') !== 'fb'))) : [];
        wi_json(['ok' => true, 'meetings' => array_reverse($out), 'users' => $users, 'numbers' => $numbers, 'base' => wi_sys('base_url') . '?m=', 'super' => wi_is_super(), 'fonts' => array_keys(wf_meet_fonts())]);
    }
    case 'meeting_templates': case 'meeting_template_save': case 'meeting_template_delete': {
        // page templates: the ready ones (read only) and this tenant's own (kept per owner of the open number, at most 20)
        wf_admin(); $ws = wi_ws(wi_current() ?: []);
        if ($api === 'meeting_template_save') { $in = wf_post(); $id = wf_meet_tpl_save($ws, $in, wi_is_super()); if (str_starts_with($id, '!')) wi_json(['ok' => false, 'error' => substr($id, 1)]); wi_log('meeting_template_save', ['id' => $id]); }
        if ($api === 'meeting_template_delete') { $in = wf_post(); if (!wf_meet_tpl_delete($ws, (string) ($in['id'] ?? ''))) wi_json(['ok' => false, 'error' => wf_mt('Only your own templates can be deleted.')]); wi_log('meeting_template_delete', ['id' => (string) $in['id']]); }
        $list = []; foreach (wf_meet_tpl_shipped() as $k => $x) $list[] = ['id' => $k, 'name' => wf_mt($x['name']), 'ui' => $x['ui'], 'ready' => true];
        foreach (wf_meet_tpl_own($ws) as $k => $x) $list[] = ['id' => (string) $k, 'name' => (string) $x['name'], 'ui' => (array) $x['ui'], 'ready' => false, 'updated' => (string) ($x['updated'] ?? '')];
        wi_json(['ok' => true, 'templates' => $list, 'id' => $id ?? '', 'max' => 20]);
    }
    case 'meeting_preview': {
        // the editor's preview: the real page, drawn with the settings being edited (nothing saved)
        $in = wf_post(); wf_admin(); $old = []; foreach (wf_meetings() as $x) if ($x['id'] === (string) ($in['id'] ?? '') && wf_meeting_mine($x)) $old = $x;
        $m = ['id' => 'preview', 'slug' => 'preview', 'title' => mb_substr(trim((string) ($in['title'] ?? '')), 0, 120) ?: wf_mt('New meeting'), 'desc' => mb_substr(trim((string) ($in['desc'] ?? '')), 0, 600),
            'mode' => ($in['mode'] ?? '') === 'password' ? 'password' : 'auto', 'ask_phone' => !empty($in['ask_phone']),
            'fields' => array_values(array_map(fn($f) => ['label' => mb_substr(trim((string) ($f['label'] ?? '')), 0, 60), 'req' => !empty($f['req'])], array_filter(array_slice((array) ($in['fields'] ?? []), 0, 10), fn($f) => trim((string) ($f['label'] ?? '')) !== ''))),
            'ui' => wf_meet_ui_clean((array) ($in['ui'] ?? []), wi_is_super(), (array) ($old['ui'] ?? [])), 'template' => wf_meet_tpl_id((string) ($in['template'] ?? '')), 'acc' => (string) ($old['acc'] ?? wf_aid())];
        $st = in_array($in['state'] ?? '', ['waiting', 'closed', 'form', 'pending', 'rejected', 'full'], true) ? (string) $in['state'] : 'form';
        wi_json(['ok' => true, 'html' => wf_meet_view($m, $st, '', true)]);
    }
    case 'meeting_save': {
        $in = wf_post(); wf_admin(); $all = wf_meetings(); $id = (string) ($in['id'] ?? '');
        // Only meetings of this user's numbers (or their own) can be changed or deleted.
        foreach ($all as $x) if ($x['id'] === $id && !wf_meeting_mine($x)) wi_json(['ok' => false, 'error' => 'This meeting belongs to another number.'], 403);
        if (in_array($in['do'] ?? '', ['archive', 'unarchive'], true)) { $ok = false; // a finished or closed meeting is kept in the archive instead of deleted
            foreach ($all as &$x) if ($x['id'] === $id) { if (($x['status'] ?? '') === 'open' && $in['do'] === 'archive') wi_json(['ok' => false, 'error' => 'Close the meeting first.']); $x['archived'] = $in['do'] === 'archive' ? date('c') : null; $ok = true; } unset($x);
            if (!$ok) wi_json(['ok' => false, 'error' => 'Not found.']); wi_write('meetings.json', $all); wi_log('meeting_' . $in['do'], ['id' => $id]); wi_json(['ok' => true]); }
        if (($in['do'] ?? '') === 'delete') { $all = array_values(array_filter($all, fn($x) => $x['id'] !== $id)); wi_write('meetings.json', $all); wi_json(['ok' => true]); }
        $url = trim((string) ($in['url'] ?? ''));
        if ($url !== '' && !preg_match('#^https://meet\.google\.com/[a-z0-9\-]+(\?[\w=&.%-]*)?$#i', $url)) wi_json(['ok' => false, 'error' => 'Paste a Google Meet link (https://meet.google.com/…).']);
        $slug = strtolower(trim(preg_replace('/[^a-z0-9-]+/i', '-', (string) ($in['slug'] ?? '')), '-'));
        if ($slug === '') $slug = substr(bin2hex(random_bytes(4)), 0, 8);
        foreach ($all as $x) if ($x['slug'] === $slug && $x['id'] !== $id) wi_json(['ok' => false, 'error' => 'This link name is already used.']);
        $idx = null; foreach ($all as $i => $x) if ($x['id'] === $id) $idx = $i;
        if ($idx !== null && !(wi_is_super() && wf_meet_acc_ok($all[$idx])) && !isset(wi_my_accounts()[$all[$idx]['acc'] ?? '']) && strtolower((string) ($all[$idx]['by'] ?? '')) !== strtolower((string) $_SESSION['email'])) wi_json(['ok' => false, 'error' => 'This meeting belongs to another number.'], 403);
        // A new meeting always belongs to a number: the user's current one, or the one the super admin picked in the form.
        $newAcc = wi_is_super() && isset(wi_accounts()[(string) ($in['acc'] ?? '')]) ? (string) $in['acc'] : wf_aid();
        if ($idx === null && $newAcc === '') wi_json(['ok' => false, 'error' => wi_is_super() ? 'Choose the number this meeting belongs to.' : 'No WhatsApp account available for this user.'], 403);
        if ($idx === null) { $all[] = ['id' => bin2hex(random_bytes(6)), 'created' => date('c'), 'by' => $_SESSION['email'], 'acc' => $newAcc, 'status' => 'closed', 'joins' => []]; $idx = count($all) - 1; }
        $m = $all[$idx];
        $m['title'] = mb_substr(trim((string) ($in['title'] ?? '')), 0, 120) ?: 'Meeting'; $m['desc'] = mb_substr(trim((string) ($in['desc'] ?? '')), 0, 600);
        $m['slug'] = $slug; if ($url !== '') $m['url'] = $url;
        $m['mode'] = ($in['mode'] ?? '') === 'password' ? 'password' : 'auto';
        if (trim((string) ($in['pass'] ?? '')) !== '') $m['pass'] = password_hash((string) $in['pass'], PASSWORD_DEFAULT);
        if ($m['mode'] === 'password' && empty($m['pass'])) wi_json(['ok' => false, 'error' => 'Set a password for password mode.']);
        $m['limit'] = max(0, min(1000, (int) ($in['limit'] ?? 0))); $m['ask_phone'] = !empty($in['ask_phone']);
        $m['fields'] = []; foreach (array_slice((array) ($in['fields'] ?? []), 0, 10) as $f) { $l = mb_substr(trim((string) ($f['label'] ?? '')), 0, 60); if ($l !== '') $m['fields'][] = ['label' => $l, 'req' => !empty($f['req'])]; } // extra details asked on the join page
        if (!empty($in['space']) && $url !== '') $m['space'] = (string) $in['space']; elseif ($url !== '' && $url !== ($all[$idx]['url'] ?? '')) unset($m['space']);
        $rec = !empty($in['record']); $tr = !empty($in['transcript']); $m['rec_note'] = '';
        if (($rec || $tr || !empty($m['record']) || !empty($m['transcript'])) && ($rec !== !empty($m['record']) || $tr !== !empty($m['transcript']) || !empty($in['space']))) {
            // Automatic recording / transcript is a setting of the Meet space itself — it starts by itself when the call starts.
            $sp = wf_meet_space_cfg((string) ($m['space'] ?? ''), (string) ($m['url'] ?? ''), $rec, $tr);
            if (!empty($sp['ok'])) $m['space'] = $sp['space']; else $m['rec_note'] = $sp['error'];
        }
        $m['record'] = $rec; $m['transcript'] = $tr;
        $m['auto_after_host'] = !empty($in['auto_after_host']); // guests who gave their details are accepted once any host has entered
        // Who gets the link: the groups and chats ticked here, plus every host (dashboard hosts by phone, WhatsApp hosts by their private link); sent after this save when on_save is ticked, or later from the card
        $m['invite'] = ['on_save' => !empty($in['invite_on_save']), 'jids' => array_values(array_unique(array_filter(array_map(fn($j) => wf_jid((string) $j), array_slice((array) ($in['invite_jids'] ?? []), 0, 60)))))];
        // the page's look and texts: every value checked (colours, allowed choices, https images, plain texts; custom CSS by the super admin only)
        $m['ui'] = wf_meet_ui_clean((array) ($in['ui'] ?? []), wi_is_super(), (array) ($m['ui'] ?? []));
        $m['template'] = wf_meet_tpl_id((string) ($in['template'] ?? ''));
        $m['approve'] = !empty($in['approve']); $m['auto_close'] = max(1, min(24, (int) ($in['auto_close'] ?? 3)));
        $m['hosts'] = array_values(array_filter(array_map('strval', (array) ($in['hosts'] ?? []))));
        $old = []; foreach ((array) ($m['wa_hosts'] ?? []) as $h) $old[$h['phone']] = $h;
        $m['wa_hosts'] = [];
        foreach ((array) ($in['wa_hosts'] ?? []) as $h) { $ph = wf_phone((string) ($h['phone'] ?? '')); if ($ph === '' || isset($m['wa_hosts'][$ph])) continue;
            $m['wa_hosts'][$ph] = ['phone' => $ph, 'name' => mb_substr(trim((string) ($h['name'] ?? '')), 0, 60), 'token' => $old[$ph]['token'] ?? bin2hex(random_bytes(12))]; }
        $m['wa_hosts'] = array_values(array_slice($m['wa_hosts'], 0, 30));
        $all[$idx] = $m; wi_write('meetings.json', $all); wi_log('meeting_save', ['slug' => $slug]);
        $sent = !empty($m['invite']['on_save']) ? wf_meet_notify($m) : null;
        wi_json(['ok' => true, 'sent' => $sent]);
    }
    case 'meeting_notify': {
        // The link goes out now to everyone concerned (the saved groups and chats, and every host).
        $in = wf_post(); wf_admin();
        foreach (wf_meetings() as $m) if ($m['id'] === ($in['id'] ?? '')) { if (!wf_meeting_mine($m)) wi_json(['ok' => false, 'error' => 'This meeting belongs to another number.'], 403); $r = wf_meet_notify($m); wi_json(['ok' => $r['sent'] > 0 || !$r['total'], 'sent' => $r['sent'], 'total' => $r['total'], 'error' => $r['total'] && !$r['sent'] ? 'Sending failed.' : '']); }
        wi_json(['ok' => false, 'error' => 'Not found.']);
    }
    case 'meeting_state': {
        $in = wf_post(); $all = wf_meetings(); $idx = null;
        foreach ($all as $i => $m) if ($m['id'] === ($in['id'] ?? '')) $idx = $i;
        if ($idx === null) wi_json(['ok' => false, 'error' => 'Not found.']);
        $m = $all[$idx];
        if (!wf_meet_can_host($m)) wi_json(['ok' => false, 'error' => 'Only the host(s) of this meeting can open or close it.'], 403);
        if (($in['do'] ?? '') === 'open' && !empty($m['archived'])) wi_json(['ok' => false, 'error' => 'Take the meeting out of the archive first.']);
        if (($in['do'] ?? '') === 'open') { if (empty($m['url'])) wi_json(['ok' => false, 'error' => 'Add the Google Meet link first.']); $m['status'] = 'open'; $m['opened_at'] = date('c'); $m['opened_by'] = $_SESSION['email']; $m['host_in_at'] = date('c'); }
        else { $m['status'] = 'closed'; $m['closed_at'] = date('c'); }
        if (($in['do'] ?? '') === 'open') wf_meet_space_open($m);
        if (in_array($in['do'] ?? '', ['open', 'close'], true)) { $me = wi_me(); wf_meet_log($m, (string) $in['do'], (string) ((($me['name'] ?? '') ?: ($_SESSION['email'] ?? '')))); }
        $all[$idx] = $m; wi_write('meetings.json', $all); wi_log('meeting_' . ($in['do'] ?? ''), ['id' => $m['id']] + (!empty(end($m['log'])['early']) ? ['early_min' => end($m['log'])['early']] : []));
        wi_json(['ok' => true, 'url' => ($in['do'] ?? '') === 'open' ? $m['url'] : '']);
    }
    case 'meeting_create_link': {
        // A new Google Meet link made through Google Calendar (needs Google connected with calendar access).
        $in = wf_post(); wf_admin();
        $c = wf_drive_cfg();
        if (empty($c['refresh_token'])) wi_json(['ok' => false, 'error' => wf_google_ws() !== '' ? 'Connect your Google account first (My account → Google account).' : 'Connect your Google account first (Health, report & backup → Google Drive backup).', 'need' => 'connect']);
        if (!empty($c['scopes']) && strpos((string) $c['scopes'], 'calendar') === false) wi_json(['ok' => false, 'error' => 'Your Google connection has no calendar access yet — press “Disconnect” then “Connect Google Drive” again and allow the calendar.', 'need' => 'reconnect']);
        $t = wf_drive_token(); if (empty($t['ok'])) wi_json($t);
        if (strpos((string) ($c['scopes'] ?? ''), 'meetings.space') !== false) {
            $r = wf_drive_req('POST', 'https://meet.googleapis.com/v2/spaces', $t['token'], json_encode(['config' => ['accessType' => 'OPEN']]), ['Content-Type: application/json']);
            if (!empty($r['body']['meetingUri'])) { wi_log('meeting_link_created', ['via' => 'meet']); wi_json(['ok' => true, 'url' => $r['body']['meetingUri'], 'space' => $r['body']['name'] ?? '']); }
        }
        $title = mb_substr(trim((string) ($in['title'] ?? 'Meeting')), 0, 120) ?: 'Meeting';
        $start = time() + 300;
        $ev = ['summary' => $title, 'description' => 'Created by ' . wi_sys('brand_name') . ' (meeting gateway).',
            'start' => ['dateTime' => gmdate('Y-m-d\TH:i:s\Z', $start)], 'end' => ['dateTime' => gmdate('Y-m-d\TH:i:s\Z', $start + 3600)],
            'conferenceData' => ['createRequest' => ['requestId' => bin2hex(random_bytes(8)), 'conferenceSolutionKey' => ['type' => 'hangoutsMeet']]]];
        $r = wf_drive_req('POST', 'https://www.googleapis.com/calendar/v3/calendars/primary/events?conferenceDataVersion=1&sendUpdates=none', $t['token'], json_encode($ev), ['Content-Type: application/json']);
        $link = (string) ($r['body']['hangoutLink'] ?? '');
        if ($link === '') wi_json(['ok' => false, 'error' => 'Google did not create a Meet link (HTTP ' . $r['code'] . ': ' . (is_array($r['body']) ? ($r['body']['error']['message'] ?? '') : '') . ').', 'need' => $r['code'] === 403 ? 'reconnect' : '']);
        wi_log('meeting_link_created', ['title' => $title]);
        wi_json(['ok' => true, 'url' => $link, 'event' => $r['body']['htmlLink'] ?? '']);
    }
    case 'meeting_approve': {
        $in = wf_post(); $all = wf_meetings();
        foreach ($all as &$x) if ($x['id'] === ($in['id'] ?? '')) { if (!wf_meet_can_host($x)) wi_json(['ok' => false, 'error' => 'Hosts only.'], 403);
            foreach ($x['joins'] as &$j) if (($j['state'] ?? '') === 'pending' && (($in['j'] ?? '') === '*' || ($j['id'] ?? '') === ($in['j'] ?? ''))) $j['state'] = ($in['do'] ?? '') === 'no' ? 'rejected' : 'approved';
            unset($j); wi_write('meetings.json', $all); wi_json(['ok' => true]); }
        wi_json(['ok' => false, 'error' => 'Not found.']);
    }
    case 'meeting_log': {
        $id = (string) ($_GET['id'] ?? ''); foreach (wf_meetings() as $m) if ($m['id'] === $id) { if (!wf_meet_can_host($m)) wi_json(['ok' => false, 'error' => 'Hosts only.'], 403);
            $rows = array_reverse(array_map(fn($r) => array_diff_key($r, ['dev' => 1]) + ['device' => substr((string) ($r['dev'] ?? ''), 0, 6)], (array) ($m['log'] ?? [])));
            wi_json(['ok' => true, 'log' => array_slice($rows, 0, 500), 'when' => wf_meet_when($m)]); }
        wi_json(['ok' => false, 'error' => 'Not found.']);
    }
    case 'meeting_joins': {
        $id = (string) ($_GET['id'] ?? ''); foreach (wf_meetings() as $m) if ($m['id'] === $id) { if (!wf_meet_can_host($m)) wi_json(['ok' => false, 'error' => 'Hosts only.'], 403); wi_json(['ok' => true, 'joins' => array_reverse($m['joins'] ?? []), 'opened_at' => $m['opened_at'] ?? null]); }
        wi_json(['ok' => false, 'error' => 'Not found.']);
    }
    case 'meeting_send': {
        // Sends the gateway link (never the real Meet link) on WhatsApp to chats and/or groups.
        $in = wf_post(); if (!wi_can_reply()) wi_json(['ok' => false, 'error' => 'You do not have permission to reply.'], 403); $text = trim((string) ($in['text'] ?? '')); @set_time_limit(300);
        $jids = array_values(array_unique(array_filter(array_map(fn($j) => wf_jid((string) $j), (array) ($in['jids'] ?? [$in['jid'] ?? ''])))));
        if (!$jids || $text === '') wi_json(['ok' => false, 'error' => 'Choose a chat and write the message.']);
        $macc = null; foreach (wf_meetings() as $mm) if ($mm['id'] === (string) ($in['id'] ?? '') && wf_meeting_mine($mm)) $macc = wi_accounts()[(string) ($mm['acc'] ?? '')] ?? null; // the meeting's number sends
        $sent = 0; $fail = [];
        foreach (array_slice($jids, 0, 60) as $i => $j) { if ($i) usleep(1500000); // gentle pace so WhatsApp doesn't flag the number
            $r = wi_gateway('/chat/send', ['jid' => $j, 'text' => $text, 'by' => (string) $_SESSION['email']], $macc); if (!empty($r['ok'])) $sent++; else $fail[] = $j; }
        wi_json(['ok' => $sent > 0, 'sent' => $sent, 'failed' => $fail, 'error' => $sent ? '' : 'Sending failed.']);
    }
    case 'meeting_host_links': {
        // Each WhatsApp host gets a personal link to open/close the meeting; optionally sent to them now.
        $in = wf_post(); wf_admin();
        foreach (wf_meetings() as $m) if ($m['id'] === ($in['id'] ?? '')) {
            if (!wf_meeting_mine($m)) wi_json(['ok' => false, 'error' => 'This meeting belongs to another number.'], 403);
            $base = wi_sys('base_url') . '?m=' . $m['slug'] . '&h='; $out = []; $sent = 0;
            foreach ((array) ($m['wa_hosts'] ?? []) as $h) {
                $out[] = ['phone' => $h['phone'], 'name' => $h['name'], 'link' => $base . $h['token']];
                if (!empty($in['send']) && (empty($in['phone']) || $in['phone'] === $h['phone'])) {
                    $r = wi_gateway('/chat/send', ['jid' => $h['phone'] . '@s.whatsapp.net', 'text' => wf_msg('meeting_host', ['title' => $m['title'], 'link' => $base . $h['token']]), 'by' => (string) $_SESSION['email']], wi_accounts()[(string) ($m['acc'] ?? '')] ?? null);
                    if (!empty($r['ok'])) $sent++; usleep(1200000);
                }
            }
            wi_json(['ok' => true, 'hosts' => $out, 'sent' => $sent]);
        }
        wi_json(['ok' => false, 'error' => 'Not found.']);
    }
    case 'meeting_targets': {
        // Groups (and their members) to pick hosts from or to send the link to. The super admin, who has no number of their own, names the number (?acc=).
        $ta = wi_is_super() && isset(wi_accounts()[(string) ($_GET['acc'] ?? '')]) ? wi_accounts()[(string) $_GET['acc']] : null; $taId = $ta ? (string) $ta['id'] : wf_aid();
        if ($taId === '') wi_json(['ok' => false, 'error' => 'Choose the number this meeting belongs to.'], 400);
        if (!empty($_GET['group'])) { $mm = wi_gateway('/groups/members?jid=' . rawurlencode(wf_jid($_GET['group'])), null, $ta);
            $names = []; foreach (wi_read('known_members.json', [])[$taId][$_GET['group']]['members'] ?? [] as $k) $names[$k['p']] = $k['n'];
            $out = []; foreach ($mm['members'] ?? [] as $x) { if (!empty($x['is_lid'])) continue; $ph = wf_phone((string) $x['phone']); if ($ph === '') continue; $out[] = ['phone' => $ph, 'name' => (string) ($x['name'] ?? '') ?: ($names[$ph] ?? ''), 'admin' => !empty($x['admin'])]; }
            usort($out, fn($a, $b) => $b['admin'] <=> $a['admin']);
            wi_json(['ok' => true, 'members' => $out]); }
        $r = wi_gateway('/groups', null, $ta); if (empty($r['ok'])) wi_json($r);
        // Private chats (saved contacts and people we talked to), newest first.
        $ch = wi_gateway('/archive/chats?months=' . (int) wi_sys('months_long'), null, $ta); $people = [];
        foreach ($ch['chats'] ?? [] as $c) { if (!empty($c['group']) || !preg_match('/@(s\.whatsapp\.net|lid)$/', (string) $c['jid'])) continue;
            $ph = wf_phone((string) ($c['phone'] ?? '')); if ($ph === '' && str_ends_with((string) $c['jid'], '@s.whatsapp.net')) $ph = explode('@', (string) $c['jid'])[0];
            $people[] = ['jid' => $ph !== '' ? $ph . '@s.whatsapp.net' : $c['jid'], 'name' => (string) ($c['name'] ?? ''), 'phone' => $ph, 'last' => $c['last']['time'] ?? ''];
            if (count($people) >= 3000) break; }
        $seen = array_flip(array_column($people, 'phone'));
        foreach ((wi_gateway('/contacts', null, $ta)['contacts'] ?? []) as $c) if (!isset($seen[$c['phone']])) { $seen[$c['phone']] = 1; $people[] = ['jid' => $c['phone'] . '@s.whatsapp.net', 'name' => $c['name'], 'phone' => $c['phone'], 'last' => '']; }
        wi_json(['ok' => true, 'people' => $people, 'groups' => array_values(array_map(fn($g) => ['jid' => $g['jid'], 'name' => $g['name'] ?? $g['subject'] ?? $g['jid'], 'size' => (int) ($g['members'] ?? 0)], array_filter($r['groups'], fn($g) => empty($g['left']))))]);
    }

    }
}
