<?php
// WhatsApp Inbox — error log for the super admin: what went wrong lately, grouped by type with counts.
// Sources: the bot log (errors, gave up, failed sends), the AI calls that failed, each gateway's log, the PHP error logs.
// Keys and tokens never show: anything that looks like one is masked.

function wf_err_mask(string $s): string {
    $s = preg_replace('/\b(sk|gsk|AIza|xai|pk|rk)[-_A-Za-z0-9]{12,}/', '$1…', $s);
    return preg_replace('/(token|key|secret|password)=([^&\s]{4})[^&\s]*/i', '$1=$2…', $s);
}
/** One normalised group name for alike errors (numbers, ids and times replaced). */
function wf_err_norm(string $s): string { return trim(preg_replace(['/\.tmp\.[0-9a-f]+/i', '/[0-9a-f]{12,}/i', '/\d+/'], ['.tmp', '…', 'N'], wf_err_mask($s))); }
function wf_err_add(array &$G, string $type, string $where, string $what, string $at): void {
    $k = $type . '|' . $where . '|' . ($n = mb_substr(wf_err_norm($what), 0, 200));
    $g = $G[$k] ?? ['type' => $type, 'where' => $where, 'what' => $n, 'count' => 0, 'last' => ''];
    $g['count']++; if (strcmp($at, $g['last']) > 0) $g['last'] = $at; $G[$k] = $g;
}
/** Everything that went wrong in the last $hours, grouped. */
function wf_err_collect(int $hours = 48): array {
    $G = []; $since = date('c', time() - $hours * 3600); $sinceT = time() - $hours * 3600;
    foreach (wi_accounts() as $aid => $acc) {
        foreach (wi_bot_log((string) $aid, 2000) as $r) { $res = (string) ($r['result'] ?? ''); $at = (string) ($r['at'] ?? '');
            if (!in_array($res, ['error', 'gave_up', 'send_failed'], true) || strcmp($at, $since) < 0) continue;
            wf_err_add($G, $res === 'send_failed' ? 'gateway send' : 'bot', (string) $aid, $res . ': ' . ((string) ($r['why'] ?? '') ?: (string) ($r['error'] ?? '') ?: 'no reason given'), $at); }
        $dir = dirname(__DIR__, 2) . '/wa-local' . ($aid === 'main' ? '' : '/accounts/' . $aid); $gl = $dir . '/gateway.log';
        if (is_file($gl) && ($fh = @fopen($gl, 'rb'))) { $sz = filesize($gl); fseek($fh, max(0, $sz - 262144)); $buf = (string) fread($fh, 262144); fclose($fh);
            foreach (preg_split('/\R/', $buf) as $ln) { if (!preg_match('/(error|fail|exception|disconnected|EADDRINUSE)/i', $ln)) continue; if (preg_match('/invoice webhook .*ok=true/', $ln)) continue;
                $at = preg_match('/^(\d{4}-\d\d-\d\dT[\d:.]+Z?)/', $ln, $mt) ? $mt[1] : ''; if ($at === '' || strtotime($at) < $sinceT) continue; // a line with no time cannot be dated: an old line is not counted again
                wf_err_add($G, 'gateway', (string) $aid, preg_replace('/^\S+\s+/', '', $ln), $at); } }
    }
    foreach ((array) wi_read('job_errors.json', []) as $r) if (is_array($r) && ($r['at'] ?? 0) >= $sinceT) for ($i = 0; $i < min(50, (int) ($r['count'] ?? 1)); $i++) wf_err_add($G, 'scheduled job', (string) ($r['where'] ?? ''), (string) ($r['what'] ?? ''), date('c', (int) $r['at']));
    foreach (wi_ai_tasks() as $t) { if (($t['state'] ?? '') !== 'failed' || strcmp((string) ($t['at'] ?? ''), $since) < 0) continue;
        wf_err_add($G, 'AI', (string) (($t['account'] ?? '') ?: 'system') . ' · ' . ((string) ($t['model'] ?? '') ?: 'no model'), (string) ($t['task'] ?? '') . ': ' . (string) ($t['error'] ?? ''), (string) $t['at']); }
    foreach (['wa-inbox' => dirname(__DIR__) . '/error_log', 'site' => dirname(__DIR__, 2) . '/error_log'] as $fw => $f) { if (!is_file($f) || !($fh = @fopen($f, 'rb'))) continue;
        $sz = filesize($f); fseek($fh, max(0, $sz - 524288)); $buf = (string) fread($fh, 524288); fclose($fh);
        foreach (preg_split('/\R/', $buf) as $ln) {
            // manual command-line runs of the team are not the site's errors
            if (!preg_match('/^\[([^\]]+)\] PHP (Fatal error|Parse error|Warning|Notice|Deprecated):\s*(.*)$/', $ln, $mt) || str_contains($ln, 'Command line code')) continue;
            $ts = strtotime(preg_replace('/ [A-Za-z_\/]+$/', '', $mt[1])) ?: 0; if ($ts < $sinceT) continue;
            wf_err_add($G, in_array($mt[2], ['Fatal error', 'Parse error'], true) ? 'PHP fatal' : 'PHP warning', $fw, str_replace(dirname(__DIR__, 2) . '/', '', $mt[3]), date('c', $ts)); } }
    $G = array_values($G); $rank = ['PHP fatal' => 0, 'bot' => 1, 'AI' => 2, 'gateway send' => 3, 'gateway' => 4, 'PHP warning' => 5];
    usort($G, fn($a, $b) => [$rank[$a['type']] ?? 9, -$a['count']] <=> [$rank[$b['type']] ?? 9, -$b['count']]);
    return $G;
}
/** "5 min ago", "3 h ago", "2 days ago". */
function wf_err_ago(int $ts): string { $d = max(0, time() - $ts); return $d < 3600 ? sprintf(wi_t('%d min ago'), max(1, intdiv($d, 60))) : ($d < 86400 ? sprintf(wi_t('%d h ago'), intdiv($d, 3600)) : sprintf(wi_t('%d days ago'), intdiv($d, 86400))); }
/** What a known error means and what to do, in plain words (English keys, translated in i18n). */
function wf_err_explain(string $what): array {
    foreach ([
        ['/QR refs attempts ended/i', 'The number waited for a QR scan and nobody scanned it.', 'Open Numbers and scan the QR again, or delete the number if it is not used.'],
        ['/Connection Terminated|Connection Failure|Stream Errored|Connection Closed|connection lost/i', 'WhatsApp cut the connection for a moment.', 'Usually it reconnects by itself. If it keeps coming, check the phone has internet and WhatsApp is open.'],
        ['/logged ?out|401|unauthori[sz]ed/i', 'The number was signed out of WhatsApp.', 'Open Numbers and link it again with a QR.'],
        ['/EADDRINUSE/i', 'Two copies of the gateway tried to run for the same number.', 'Nothing to do if it is rare: one copy keeps running.'],
        ['/webhook is not configured|webhook .*ok=false/i', 'A connected system did not accept a message sent to it.', 'Open Integrations and check that system\'s webhook settings.'],
        ['/timed? ?out|timeout|cURL error 28/i', 'A service took too long to answer.', 'Usually passes by itself. If it keeps coming, check the service or the AI provider.'],
        ['/quota|rate.?limit|429|insufficient|billing|credit/i', 'The AI provider refused: limit or credit ran out.', 'Open AI providers: add credit, or move another provider to the top.'],
        ['/no reason given/i', 'The bot failed without saying why.', 'Open the bot log of this number to see the message.'],
        ['/PHP|\.php/i', 'A code error on the site.', 'Send it to the developer with the time.'],
    ] as [$re, $mean, $fix]) if (preg_match($re, $what)) return [$mean, $fix];
    return ['', ''];
}
/** The super admin's page: counts per type, then every group (type, where, what, how many, last time). */
function wf_err_page(): string {
    if (!wi_is_super()) return '';
    $h = in_array((int) ($_GET['h'] ?? 48), [6, 24, 48, 168], true) ? (int) ($_GET['h'] ?? 48) : 48; $G = wf_err_collect($h); $tot = [];
    foreach ($G as $g) $tot[$g['type']] = ($tot[$g['type']] ?? 0) + $g['count'];
    $o = '<section class="hero"><div class="hero-t"><span class="hero-ic">🧯</span><div><h1>' . wi_e(wi_t('Error log')) . '</h1><p class="muted">' . wi_e(wi_t('What went wrong lately, grouped: bot errors, AI failures, gateway problems and PHP errors. Keys never show.')) . '</p></div></div></section>';
    $o .= '<div class="row wrap">'; foreach ([6, 24, 48, 168] as $x) $o .= '<a class="chip-btn' . ($x === $h ? ' on' : '') . '" href="?p=errors&amp;h=' . $x . '">' . $x . ' h</a>'; $o .= '</div>';
    $o .= '<div class="cl-kpis">'; foreach ($tot as $t => $n) $o .= '<span class="cl-kpi"><b>' . (int) $n . '</b><small>' . wi_e(wi_t($t)) . '</small></span>'; $o .= '</div>';
    if (!$G) return $o . '<div class="card empty">' . wi_e(wi_t('No errors in this time.')) . '</div>';
    $accs = wi_accounts(); $ack = (array) wi_read('err_ack.json', []); $hidden = 0; $rows = '';
    foreach (array_slice($G, 0, 300) as $g) { $k = substr(sha1($g['type'] . '|' . $g['where'] . '|' . $g['what']), 0, 16);
        if (isset($ack[$k]) && strcmp((string) $g['last'], (string) $ack[$k]) <= 0) { $hidden++; continue; } // marked as seen and nothing new since
        $wn = (string) ($accs[$g['where']]['name'] ?? ''); [$mean, $fix] = wf_err_explain($g['what']); $ts = $g['last'] !== '' ? strtotime($g['last']) : 0;
        $sev = in_array($g['type'], ['PHP fatal', 'bot'], true) || $g['count'] >= 50 ? 'bad' : ($g['count'] >= 10 ? 'warn' : 'acc');
        $rows .= '<div class="err-row"><div class="err-top"><span class="sa-chip ' . $sev . '">' . (int) $g['count'] . '×</span> <b>' . wi_e(wi_t($g['type'])) . '</b> · ' . ($wn !== '' ? wi_e($wn) . ' <span class="muted small" dir="ltr">' . wi_e($g['where']) . '</span>' : '<span dir="ltr">' . wi_e($g['where']) . '</span>')
            . '<span class="muted small err-when">' . ($ts ? wi_e(wf_err_ago($ts)) : '') . '</span>'
            . '<form method="post" class="err-ack"><input type="hidden" name="_csrf" value="' . wi_e(wi_csrf()) . '"><input type="hidden" name="do" value="err_ack"><input type="hidden" name="k" value="' . $k . '"><input type="hidden" name="last" value="' . wi_e($g['last']) . '"><button class="btn ghost sm">✓ ' . wi_e(wi_t('Seen')) . '</button></form></div>'
            . ($mean !== '' ? '<p class="err-mean">' . wi_e(wi_t($mean)) . '<br><span class="muted">👉 ' . wi_e(wi_t($fix)) . '</span></p>' : '')
            . '<code class="err-raw" dir="ltr">' . wi_e($g['what']) . '</code></div>'; }
    $o .= '<div class="card">' . ($rows ?: '<div class="empty">' . wi_e(wi_t('No errors in this time.')) . '</div>') . '</div>';
    if ($hidden) $o .= '<p class="muted small">' . (int) $hidden . ' ' . wi_e(wi_t('marked as seen (they come back if they happen again)')) . '</p>';
    return $o;
}

/** Every minute (cron): a number with more than 3 bot errors in 10 minutes → one alert to the owner's watch group, at most once an hour per number. */
function wf_err_alert(): void {
    $state = (array) wi_read('err_alert.json', []);
    foreach (wi_accounts() as $aid => $acc) {
        if (empty($acc['enabled']) || !empty($acc['archived'])) continue;
        $n = wf_err_recent((string) $aid, 600); if ($n <= 3 || (int) ($state[$aid] ?? 0) > time() - 3600) continue;
        $c = function_exists('wf_bot_run_cfg') ? wf_bot_run_cfg((string) $aid) : []; $to = (string) ($c['watch_to'] ?? ''); $via = $acc;
        if ($to === '') { $m = wi_bot_run_main_watch(); if (!$m) continue; [$via, $to] = $m; }
        $last = ''; foreach (array_reverse(wi_bot_log((string) $aid, 60)) as $r) if (in_array($r['result'] ?? '', ['error', 'gave_up'], true)) { $last = (string) ($r['why'] ?? ''); break; }
        $s = wi_gateway('/chat/send', ['jid' => $to, 'text' => wf_msg('bot_errors_alert', ['number' => (string) ($acc['name'] ?? $aid), 'count' => (string) $n, 'why' => mb_substr(wf_err_mask($last), 0, 200)]), 'via' => 'alert'], $via);
        if (!empty($s['ok'])) { $state[$aid] = time(); wi_log('bot_errors_alert', ['account' => $aid, 'count' => $n]); }
    }
    wi_write('err_alert.json', $state);
}
/** How many bot errors a number had in the last $secs seconds. */
function wf_err_recent(string $aid, int $secs): int { $since = date('c', time() - $secs); $n = 0;
    foreach (wi_bot_log($aid, 200) as $r) if (in_array($r['result'] ?? '', ['error', 'gave_up'], true) && strcmp((string) ($r['at'] ?? ''), $since) >= 0) $n++; return $n; }
/** The main number's watch group (for a number that has none of its own): [account, group jid] or null. */
function wi_bot_run_main_watch(): ?array { $all = wi_accounts(); $m = $all['main'] ?? null; if (!$m || !function_exists('wf_bot_run_cfg')) return null; $to = (string) (wf_bot_run_cfg('main')['watch_to'] ?? ''); return $to !== '' ? [$m, $to] : null; }
