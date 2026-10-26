<?php
// WhatsApp Inbox — the super admin's system overview (?p=saas): clients, plans, revenue, numbers, AI use and system health on one page.

/** The overview's figures: client states, sign-ups, expected monthly revenue, renewals due, AI use per client. */
function wf_saas_stats(): array {
    $cls = array_values(array_filter(wi_users(), fn($u) => ($u['role'] ?? '') === 'client')); $plans = wi_plans();
    $o = ['active' => 0, 'trial' => 0, 'ended' => 0, 'signups' => 0, 'revenue' => 0.0, 'due' => [], 'ai' => [], 'clients' => count($cls)];
    $since = time() - 30 * 86400;
    foreach ($cls as $c) {
        $s = wi_client_state($c); $p = $plans[(string) ($c['plan_id'] ?? '')] ?? null; $ended = in_array($s['state'], ['expired', 'suspended'], true);
        if ($ended) $o['ended']++; elseif ($p && !empty($p['trial'])) $o['trial']++; else $o['active']++;
        if (!empty($c['created_at']) && strtotime((string) $c['created_at']) >= $since) $o['signups']++;
        // a paid plan's price spread over 30 days, so every period counts as a month
        if (!$ended && $p && empty($p['trial']) && (float) ($p['price'] ?? 0) > 0) $o['revenue'] += (float) $p['price'] * 30 / max(1, (int) ($p['days'] ?? 30));
        if ($s['days'] !== null && $s['days'] <= 7 && $s['days'] >= -7) $o['due'][] = ['c' => $c, 's' => $s, 'p' => $p];
        $u = wf_client_usage((string) $c['email']); $o['ai'][] = ['c' => $c, 'calls' => $u['ai_calls'], 'tokens' => $u['ai_tokens']];
    }
    usort($o['due'], fn($a, $b) => $a['s']['days'] <=> $b['s']['days']);
    usort($o['ai'], fn($a, $b) => $b['tokens'] <=> $a['tokens']);
    return $o;
}
/** The page. */
function wf_saas_page(): string {
    if (!wi_is_super()) return '';
    $S = wf_saas_stats(); $t = fn($s) => wi_e(wi_t($s)); $cur = wi_e((string) (wi_sys('plan_currency') ?: '$'));
    $name = fn($c) => wi_e((string) (($c['panel_name'] ?? '') ?: (($c['name'] ?? '') ?: $c['email'])));
    // a switched-off number and a Facebook page have no WhatsApp gateway to check
    $nums = []; foreach (wi_accounts() as $aid => $a) { $kind = ($a['channel'] ?? '') === 'fb' ? 'fb' : (empty($a['enabled']) ? 'off' : 'wa');
        $nums[] = ['a' => $a, 'kind' => $kind, 'up' => $kind === 'wa' && !empty(wi_gateway('/status', null, $a)['connected'])]; }
    $live = array_filter($nums, fn($n) => $n['kind'] === 'wa'); $up = count(array_filter($live, fn($n) => $n['up']));
    $errs = function_exists('wf_err_collect') ? array_sum(array_column(wf_err_collect(24), 'count')) : 0;
    $aiAll = array_sum(array_column($S['ai'], 'tokens'));
    $kpi = fn($href, $label, $val, $note, $cls) => '<a class="sa-kpi" href="' . $href . '"><small>' . $t($label) . '</small><b dir="ltr">' . $val . '</b><span class="sa-chip ' . $cls . '">' . $note . '</span></a>';
    $o = '<section class="hero"><div class="hero-t"><span class="hero-ic">🏢</span><div><h1>' . $t('System overview') . '</h1><p class="muted">' . $t('Clients, plans, revenue, numbers, AI use and system health in one place. Every figure opens the list behind it.') . '</p></div></div>'
        . '<a class="btn primary" href="?p=users&amp;new=client">➕ ' . $t('New client') . '</a></section>';
    $o .= '<div class="sa-kpis">'
        . $kpi('?p=clients&amp;f=active', 'Active clients', (int) $S['active'], $t('Paid or free plans'), 'ok')
        . $kpi('?p=clients', 'On trial', (int) $S['trial'], $t('Trial plan'), 'acc')
        . $kpi('?p=clients&amp;f=ended', 'Ended or paused', (int) $S['ended'], $t('Need follow-up'), $S['ended'] ? 'bad' : 'ok')
        . $kpi('?p=clients', 'Sign-ups (30 days)', (int) $S['signups'], wi_e(sprintf(wi_t('%d clients in all'), $S['clients'])), 'acc')
        . $kpi('?p=clients', 'Expected revenue a month', $cur . wi_e(number_format($S['revenue'], 0)), $t('From paid plans'), 'ok')
        . $kpi('?p=clients&amp;f=expiring', 'Renewals within 7 days', count(array_filter($S['due'], fn($d) => $d['s']['days'] >= 0)), $t('Renew from the Clients page'), count($S['due']) ? 'warn' : 'ok')
        . $kpi('?p=accounts', 'Numbers online', $up . ' / ' . count($live), $up === count($live) ? $t('All connected') : $t('Some are offline'), $up === count($live) ? 'ok' : 'bad')
        . $kpi('#ai-cost', 'AI this month', '$' . wi_e(number_format(wf_ai_month_cost(), 2)), wi_e(number_format($aiAll)) . ' ' . $t('tokens, all clients'), 'acc')
        . '</div>';
    $o .= '<div class="sa-row"><div class="card"><div class="card-h"><h2>' . $t('Renewals and trials ending soon') . '</h2><a class="link" href="?p=clients&amp;f=expiring">' . $t('View all') . ' →</a></div>';
    if (!$S['due']) $o .= '<div class="empty">' . $t('No subscription ends within 7 days.') . '</div>';
    else { $o .= '<div style="overflow-x:auto"><table class="tbl"><tr><th>' . $t('Client') . '</th><th>' . $t('Plan') . '</th><th>' . $t('Ends on') . '</th><th>' . $t('Payment method') . '</th><th>' . $t('Status') . '</th></tr>';
        foreach (array_slice($S['due'], 0, 8) as $d) { $c = $d['c']; $dd = (int) $d['s']['days']; $pm = (string) ($c['pay_method'] ?? '');
            $o .= '<tr><td dir="auto">' . $name($c) . '</td><td dir="auto">' . wi_e((string) (($c['plan'] ?? '') ?: '—')) . '</td><td dir="ltr">' . wi_e((string) $c['expires']) . '</td><td>' . ($pm !== '' && isset(wi_pay_methods()[$pm]) ? $t(wi_pay_methods()[$pm]) : '—') . '</td>'
                . '<td><span class="sa-chip ' . ($dd < 0 ? 'bad' : (!empty($d['p']['trial']) ? 'acc' : 'warn')) . '">' . ($dd < 0 ? wi_e(sprintf(wi_t('%d day(s) ago'), -$dd)) : wi_e(sprintf(wi_t('%d day(s) left'), $dd))) . '</span></td></tr>'; }
        $o .= '</table></div>'; }
    $o .= '</div><div class="card"><div class="card-h"><h2>' . $t('Number and gateway health') . '</h2><a class="link" href="?p=health">' . $t('View all') . ' →</a></div><div style="overflow-x:auto"><table class="tbl">';
    foreach ($nums as $n) { $st = $n['kind'] === 'fb' ? ['🔵', 'acc', 'Facebook page'] : ($n['kind'] === 'off' ? ['⚪', '', 'Switched off'] : ($n['up'] ? ['🟢', 'ok', 'Connected'] : ['🔴', 'bad', 'Offline']));
        $o .= '<tr><td dir="auto">' . $st[0] . ' ' . wi_e(wi_account_label($n['a'])) . '</td><td class="muted">' . ($n['kind'] === 'wa' ? wi_e((string) (($n['a']['gateway'] ?? '') ?: 'local')) : '') . '</td><td><span class="sa-chip ' . $st[1] . '">' . $t($st[2]) . '</span></td></tr>'; }
    $o .= '</table></div></div></div>';
    $max = max(1, (int) ($S['ai'][0]['tokens'] ?? 0));
    $o .= '<div class="sa-row"><div class="card"><div class="card-h"><h2>' . $t('AI use per client this month') . '</h2><a class="link" href="?p=ai">' . $t('AI providers') . ' →</a></div>';
    $ai = array_filter($S['ai'], fn($x) => $x['tokens'] > 0);
    if (!$ai) $o .= '<div class="empty">' . $t('No AI use this month yet.') . '</div>';
    foreach (array_slice($ai, 0, 8) as $x) $o .= '<div class="sa-bar"><span dir="auto">' . $name($x['c']) . '</span><i><b style="width:' . max(2, (int) round($x['tokens'] * 100 / $max)) . '%"></b></i><em dir="ltr">' . wi_e(number_format($x['tokens'])) . '</em></div>';
    $o .= '</div><div class="card"><div class="card-h"><h2>' . $t('System and security') . '</h2></div><div style="overflow-x:auto"><table class="tbl">'
        . '<tr><td><a href="?p=errors&amp;h=24">' . $t('Errors in the last 24 hours') . '</a></td><td><span class="sa-chip ' . ($errs ? 'warn' : 'ok') . '">' . (int) $errs . '</span></td></tr>'
        . '<tr><td><a href="?p=users">' . $t('Users and permissions') . '</a></td><td><span class="sa-chip acc">' . count(wi_users()) . '</span></td></tr>'
        . '<tr><td><a href="?p=backup">' . $t('Backup') . '</a></td><td>→</td></tr>'
        . '<tr><td><a href="?p=settings">' . $t('General settings') . '</a></td><td>→</td></tr>'
        . '<tr><td><a href="?landing=1" target="_blank" rel="noopener">' . $t('Product page') . '</a></td><td>↗</td></tr>'
        . '</table></div></div></div>';
    return $o . wf_saas_ai_card() . wf_saas_reports_card();
}
/** Price in USD per million tokens of an AI model: the super admin's own price, else a starting estimate by model family. */
function wf_ai_price(string $model): array {
    $own = (array) (wi_config()['ai_prices'] ?? []); if (isset($own[$model])) return ['in' => (float) $own[$model]['in'], 'out' => (float) $own[$model]['out'], 'own' => true];
    foreach (['flash-lite' => [0.10, 0.40], 'gemini' => [0.30, 2.50], 'opus' => [5, 25], 'sonnet' => [3, 15], 'haiku' => [1, 5], 'deepseek' => [0.27, 1.10], 'glm' => [0.20, 1.10], 'gpt' => [1.25, 10]] as $k => $p)
        if (stripos($model, $k) !== false) return ['in' => (float) $p[0], 'out' => (float) $p[1], 'own' => false];
    return ['in' => 0.0, 'out' => 0.0, 'own' => false];
}
function wf_ai_cost(string $model, int $in, int $out): float { $p = wf_ai_price($model); return ($in * $p['in'] + $out * $p['out']) / 1000000; }
/** The AI cost card: this month per provider and model (calls, tokens in and out, price, estimated cost), then the latest calls by task. */
function wf_saas_ai_card(): string {
    $t = fn($s) => wi_e(wi_t($s)); $n = fn($x) => wi_e(number_format((float) $x));
    $usd = fn($x) => '$' . wi_e(number_format((float) $x, $x < 1 ? 3 : 2));
    $mon = (array) (wi_read('usage.json', [])['_models'][date('Y-m')] ?? []); $by = [];
    foreach ($mon as $md => $r) { $pv = strtok((string) $md, ' ') ?: 'unknown'; $by[$pv][$md] = $r; }
    ksort($by); $tot = ['calls' => 0, 'in' => 0, 'out' => 0, 'cost' => 0.0];
    $o = '<div class="card" id="ai-cost"><div class="card-h"><h2>' . $t('AI tokens and estimated cost this month') . '</h2><a class="link" href="?p=ai">' . $t('AI providers') . ' →</a></div>'
        . '<p class="muted small">' . $t('Prices are in USD per million tokens. Change any price to your provider\'s real price and save; the cost is an estimate.') . '</p>';
    if (!$by) return $o . '<div class="empty">' . $t('No AI use this month yet.') . '</div></div>';
    $o .= '<form method="post"><input type="hidden" name="_csrf" value="' . wi_e(wi_csrf()) . '"><input type="hidden" name="do" value="ai_prices"><div style="overflow-x:auto"><table class="tbl sa-ai">'
        . '<tr><th>' . $t('Provider') . '</th><th>' . $t('Model') . '</th><th>' . $t('Calls') . '</th><th>' . $t('Tokens in') . '</th><th>' . $t('Tokens out') . '</th><th>' . $t('Price in') . '</th><th>' . $t('Price out') . '</th><th>' . $t('Estimated cost') . '</th></tr>';
    foreach ($by as $pv => $ms) { $pc = 0.0; $pt = ['calls' => 0, 'in' => 0, 'out' => 0];
        uasort($ms, fn($a, $b) => ($b['in'] + $b['out']) <=> ($a['in'] + $a['out']));
        foreach ($ms as $md => $r) { $p = wf_ai_price((string) $md); $c = wf_ai_cost((string) $md, (int) $r['in'], (int) $r['out']); $pc += $c; foreach ($pt as $k => $v) $pt[$k] += (int) $r[$k];
            $nm = wi_e('price[' . $md . ']');
            $o .= '<tr><td>' . wi_e($pv) . '</td><td dir="ltr">' . wi_e(trim(substr((string) $md, strlen($pv)))) . '</td><td>' . $n($r['calls']) . '</td><td>' . $n($r['in']) . '</td><td>' . $n($r['out']) . '</td>'
                . '<td><input type="number" step="0.01" min="0" name="' . $nm . '[in]" value="' . wi_e((string) $p['in']) . '" style="width:80px" dir="ltr"></td><td><input type="number" step="0.01" min="0" name="' . $nm . '[out]" value="' . wi_e((string) $p['out']) . '" style="width:80px" dir="ltr"></td><td><b>' . $usd($c) . '</b></td></tr>'; }
        $o .= '<tr class="sa-sum"><td colspan="2"><b>' . wi_e($pv) . '</b></td><td>' . $n($pt['calls']) . '</td><td>' . $n($pt['in']) . '</td><td>' . $n($pt['out']) . '</td><td colspan="2"></td><td><b>' . $usd($pc) . '</b></td></tr>';
        foreach ($pt as $k => $v) $tot[$k] += $v; $tot['cost'] += $pc; }
    $o .= '<tr class="sa-sum"><td colspan="2"><b>' . $t('Total') . '</b></td><td>' . $n($tot['calls']) . '</td><td>' . $n($tot['in']) . '</td><td>' . $n($tot['out']) . '</td><td colspan="2"></td><td><b>' . $usd($tot['cost']) . '</b></td></tr></table></div>'
        . '<button class="btn primary sm">' . $t('Save prices') . '</button></form>';
    // the latest calls (the log keeps the last 400) by task, with their number, tokens and cost
    $tk = []; $from = '';
    foreach (wi_ai_tasks() as $x) { if (($x['state'] ?? '') !== 'done') continue; $k = (string) (($x['task'] ?? '') ?: 'Other'); $i = (int) ($x['tokens_in'] ?? 0); $u = (int) ($x['tokens_out'] ?? 0);
        $from = $from === '' ? (string) ($x['at'] ?? '') : min($from, (string) ($x['at'] ?? $from));
        $tk[$k] = ['calls' => ($tk[$k]['calls'] ?? 0) + 1, 'tok' => ($tk[$k]['tok'] ?? 0) + $i + $u, 'cost' => ($tk[$k]['cost'] ?? 0) + wf_ai_cost((string) ($x['model'] ?? ''), $i, $u)]; }
    uasort($tk, fn($a, $b) => $b['cost'] <=> $a['cost']);
    if ($tk) { $o .= '<h3 style="margin:16px 0 6px">' . $t('Latest calls by task') . ' <small class="muted" dir="ltr">' . wi_e(substr($from, 0, 16)) . ' →</small></h3><div style="overflow-x:auto"><table class="tbl"><tr><th>' . $t('Task') . '</th><th>' . $t('Calls') . '</th><th>' . $t('Tokens') . '</th><th>' . $t('Estimated cost') . '</th></tr>';
        foreach (array_slice($tk, 0, 15, true) as $k => $r) $o .= '<tr><td dir="auto">' . $t($k) . '</td><td>' . $n($r['calls']) . '</td><td>' . $n($r['tok']) . '</td><td>' . $usd($r['cost']) . '</td></tr>';
        $o .= '</table></div>'; }
    return $o . '</div>';
}
/** This month's estimated AI cost, all models. */
function wf_ai_month_cost(): float { $c = 0.0; foreach ((array) (wi_read('usage.json', [])['_models'][date('Y-m')] ?? []) as $md => $r) $c += wf_ai_cost((string) $md, (int) ($r['in'] ?? 0), (int) ($r['out'] ?? 0)); return $c; }

/* Six-month AI report: usage.json keeps six months, so every half year (January to June, July to December) is saved as one
   report (HTML and PDF) under .data/ai_reports before its months leave usage.json. Built daily in the period's last 3 days,
   built again (final) once the new period starts, and a month is only dropped from usage.json when its report is there. */

/** The half year a month belongs to: '2026-H2'. */
function wf_ai_period(string $month): string { return substr($month, 0, 4) . '-H' . ((int) substr($month, 5, 2) <= 6 ? '1' : '2'); }
/** The months of a half year. */
function wf_ai_period_months(string $p): array { $y = substr($p, 0, 4); $s = substr($p, -1) === '1' ? 1 : 7; $o = []; for ($i = 0; $i < 6; $i++) $o[] = $y . '-' . sprintf('%02d', $s + $i); return $o; }
function wf_ai_report_dir(): string { $d = WI_DIR . '/ai_reports'; if (!is_dir($d)) @mkdir($d, 0750, true); return $d; }
/** The PDF library (mPDF): the path in General settings (pdf_autoload), else the copy on this server. */
function wf_pdf_lib(): bool {
    if (class_exists('\Mpdf\Mpdf')) return true;
    foreach (array_filter([(string) wi_sys('pdf_autoload'), dirname(__DIR__, 2) . '/ai-inv/vendor/autoload.php', dirname(__DIR__) . '/vendor/autoload.php']) as $f) if (is_file($f)) { require_once $f; if (class_exists('\Mpdf\Mpdf')) return true; }
    return false;
}
/** A label in both languages: Arabic from i18n.php, then the English. */
function wf_ai_bi(string $s): string { static $ar = null; $ar ??= (array) (@include dirname(__DIR__) . '/i18n.php'); return isset($ar[$s]) ? $ar[$s] . ' · ' . $s : $s; }
/** Builds a half year's report (HTML, and PDF when the library is there). Returns what was written. */
function wf_ai_report_build(string $p, bool $final): array {
    $U = wi_read('usage.json', []); $months = wf_ai_period_months($p); $L = fn($s) => htmlspecialchars(wf_ai_bi($s));
    $n = fn($x) => number_format((float) $x); $usd = fn($x) => '$' . number_format((float) $x, 2);
    $tot = ['calls' => 0, 'in' => 0, 'out' => 0, 'cost' => 0.0]; $byPv = []; $rows = '';
    foreach ($months as $m) { $mc = 0.0; $mt = ['calls' => 0, 'in' => 0, 'out' => 0];
        foreach ((array) ($U['_models'][$m] ?? []) as $md => $r) { $c = wf_ai_cost((string) $md, (int) $r['in'], (int) $r['out']); $pv = strtok((string) $md, ' ') ?: 'unknown';
            $rows .= '<tr><td>' . $m . '</td><td>' . htmlspecialchars($pv) . '</td><td>' . htmlspecialchars(trim(substr((string) $md, strlen($pv)))) . '</td><td>' . $n($r['calls']) . '</td><td>' . $n($r['in']) . '</td><td>' . $n($r['out']) . '</td><td>' . $usd($c) . '</td></tr>';
            foreach ($mt as $k => $v) $mt[$k] += (int) $r[$k]; $mc += $c;
            $b = $byPv[$pv] ?? ['calls' => 0, 'in' => 0, 'out' => 0, 'cost' => 0.0]; foreach (['calls', 'in', 'out'] as $k) $b[$k] += (int) $r[$k]; $b['cost'] += $c; $byPv[$pv] = $b; }
        foreach ($mt as $k => $v) $tot[$k] += $v; $tot['cost'] += $mc; }
    $cl = ''; foreach (array_filter(wi_users(), fn($u) => ($u['role'] ?? '') === 'client') as $c) { $calls = $tok = 0;
        foreach ($months as $m) { $u = wf_client_usage((string) $c['email'], $m); $calls += $u['ai_calls']; $tok += $u['ai_tokens']; }
        if ($tok > 0) $cl .= '<tr><td>' . htmlspecialchars((string) (($c['panel_name'] ?? '') ?: (($c['name'] ?? '') ?: $c['email']))) . '</td><td>' . $n($calls) . '</td><td>' . $n($tok) . '</td></tr>'; }
    $pvRows = ''; ksort($byPv); foreach ($byPv as $pv => $b) $pvRows .= '<tr><td>' . htmlspecialchars($pv) . '</td><td>' . $n($b['calls']) . '</td><td>' . $n($b['in']) . '</td><td>' . $n($b['out']) . '</td><td>' . $usd($b['cost']) . '</td></tr>';
    $th = fn(array $h) => '<tr>' . implode('', array_map(fn($x) => '<th>' . $L($x) . '</th>', $h)) . '</tr>';
    $body = '<h1>' . $L('AI usage report') . ' ' . $p . '</h1><p>' . $months[0] . ' / ' . $months[5] . ' · ' . $L($final ? 'Final' : 'Draft') . ' · ' . date('Y-m-d H:i') . '</p><p>' . $L('Prices are in USD per million tokens. Change any price to your provider\'s real price and save; the cost is an estimate.') . '</p>'
        . '<h2>' . $L('Total') . '</h2><table>' . $th(['Calls', 'Tokens in', 'Tokens out', 'Estimated cost']) . '<tr><td>' . $n($tot['calls']) . '</td><td>' . $n($tot['in']) . '</td><td>' . $n($tot['out']) . '</td><td><b>' . $usd($tot['cost']) . '</b></td></tr></table>'
        . '<h2>' . $L('Provider') . '</h2><table>' . $th(['Provider', 'Calls', 'Tokens in', 'Tokens out', 'Estimated cost']) . $pvRows . '</table>'
        . '<h2>' . $L('Month') . ' / ' . $L('Model') . '</h2><table>' . $th(['Month', 'Provider', 'Model', 'Calls', 'Tokens in', 'Tokens out', 'Estimated cost']) . $rows . '</table>'
        . ($cl !== '' ? '<h2>' . $L('Clients') . '</h2><table>' . $th(['Client', 'Calls', 'Tokens']) . $cl . '</table>' : '');
    $css = 'body{font-family:sans-serif;color:#1c1e21;font-size:12px}h1{color:#0866ff;font-size:20px}h2{font-size:14px;margin-top:18px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #e4e6eb;padding:5px 7px;text-align:right}th{background:#f0f2f5}';
    $html = '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>' . $p . '</title><style>' . $css . '</style></head><body>' . $body . '</body></html>';
    $d = wf_ai_report_dir(); $out = ['html' => false, 'pdf' => false];
    $out['html'] = file_put_contents($d . '/' . $p . '.html', $html) !== false;
    if (wf_pdf_lib()) { try { $pdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'tempDir' => sys_get_temp_dir(), 'autoScriptToLang' => true, 'autoLangToFont' => true, 'format' => 'A4-L']);
        $pdf->SetDirectionality('rtl'); $pdf->WriteHTML($html); $pdf->Output($d . '/' . $p . '.pdf', 'F'); $out['pdf'] = is_file($d . '/' . $p . '.pdf') && filesize($d . '/' . $p . '.pdf') > 1000; } catch (Throwable $e) { wi_catch($e, 'ai report pdf'); } }
    $st = (array) wi_read('ai_reports.json', []); $st[$p] = ['at' => date('c'), 'final' => $final, 'html' => $out['html'], 'pdf' => $out['pdf']]; wi_write('ai_reports.json', $st);
    wi_log('ai_report', ['period' => $p, 'final' => $final] + $out);
    return $out;
}
/** A half year's report is saved and final (HTML, and PDF when the library is there). */
function wf_ai_report_ok(string $p): bool {
    $r = (array) (((array) wi_read('ai_reports.json', []))[$p] ?? []); $d = wf_ai_report_dir();
    return !empty($r['final']) && is_file($d . '/' . $p . '.html') && (!wf_pdf_lib() || is_file($d . '/' . $p . '.pdf'));
}
/** Hourly (before usage.json drops old months): a draft each day in the period's last 3 days, the final report once the next period starts. */
function wf_ai_report_cron(): void {
    $now = date('Y-m'); $cur = wf_ai_period($now); $st = (array) wi_read('ai_reports.json', []);
    $endM = wf_ai_period_months($cur)[5]; $daysLeft = (int) date('t') - (int) date('j');
    if ($now === $endM && $daysLeft < 3 && substr((string) ($st[$cur]['at'] ?? ''), 0, 10) !== date('Y-m-d')) wf_ai_report_build($cur, false);
    $U = wi_read('usage.json', []); $periods = [];
    foreach (array_keys((array) ($U['_models'] ?? [])) as $m) if (wf_ai_period((string) $m) !== $cur) $periods[wf_ai_period((string) $m)] = true;
    foreach (array_keys($periods) as $p) if (!wf_ai_report_ok($p)) wf_ai_report_build($p, true);
}
/** Download (super admin): ?ai_report=2026-H2&fmt=pdf|html */
function wf_ai_report_send(string $p, string $fmt): void {
    if (!wi_is_super() || !preg_match('/^\d{4}-H[12]$/', $p)) { http_response_code(404); exit; }
    $fmt = $fmt === 'html' ? 'html' : 'pdf'; $f = wf_ai_report_dir() . '/' . $p . '.' . $fmt;
    if (!is_file($f)) { http_response_code(404); exit('Not found.'); }
    header('Content-Type: ' . ($fmt === 'pdf' ? 'application/pdf' : 'text/html; charset=utf-8')); header('Content-Disposition: attachment; filename="ai-report-' . $p . '.' . $fmt . '"');
    header('Content-Length: ' . filesize($f)); header('X-Robots-Tag: noindex'); readfile($f); exit;
}
/** The saved reports on the overview, with download links and a button to build the current half year now. */
function wf_saas_reports_card(): string {
    $t = fn($s) => wi_e(wi_t($s)); $st = (array) wi_read('ai_reports.json', []); krsort($st);
    $o = '<div class="card"><div class="card-h"><h2>' . $t('Six-month AI reports') . '</h2></div><p class="muted small">' . $t('Every half year is saved as one report (HTML and PDF) before its months leave the six-month window. A month is never dropped while its report is missing.') . '</p>';
    if (!wf_pdf_lib()) $o .= '<p class="bad-t small">' . $t('The PDF library is not installed on this server: reports are saved as HTML only.') . '</p>';
    if ($st) { $o .= '<div style="overflow-x:auto"><table class="tbl"><tr><th>' . $t('Period') . '</th><th>' . $t('Status') . '</th><th>' . $t('Built on') . '</th><th>' . $t('Download') . '</th></tr>';
        foreach ($st as $p => $r) $o .= '<tr><td dir="ltr">' . wi_e((string) $p) . '</td><td><span class="sa-chip ' . (!empty($r['final']) ? 'ok">' . $t('Final') : 'warn">' . $t('Draft')) . '</span></td><td dir="ltr">' . wi_e(substr((string) ($r['at'] ?? ''), 0, 16)) . '</td><td>'
            . (!empty($r['pdf']) ? '<a class="btn ghost sm" href="?ai_report=' . wi_e((string) $p) . '&amp;fmt=pdf">PDF</a> ' : '') . (!empty($r['html']) ? '<a class="btn ghost sm" href="?ai_report=' . wi_e((string) $p) . '&amp;fmt=html">HTML</a>' : '') . '</td></tr>';
        $o .= '</table></div>'; }
    // when the next ones are built (all by the hourly job, nothing to press)
    $cur = wf_ai_period(date('Y-m')); $end = wf_ai_period_months($cur)[5]; $last = date('Y-m-t', strtotime($end . '-01'));
    $next = date('Y-m-d', strtotime($last . ' +1 day')); $draft = date('Y-m-d', strtotime($last . ' -2 days'));
    return $o . '<p class="small">🕒 ' . wi_e(sprintf(wi_t('Automatic: the %1$s report is built as a draft every day from %2$s to %3$s, and as the final report on %4$s.'), $cur, $draft, $last, $next)) . '</p></div>';
}
