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
    $nums = []; foreach (wi_accounts() as $aid => $a) { $hs = wi_gateway('/status', null, $a); $nums[] = ['a' => $a, 'up' => !empty($hs['connected'])]; }
    $up = count(array_filter($nums, fn($n) => $n['up']));
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
        . $kpi('?p=accounts', 'Numbers online', $up . ' / ' . count($nums), $up === count($nums) ? $t('All connected') : $t('Some are offline'), $up === count($nums) ? 'ok' : 'bad')
        . $kpi('?p=ai', 'AI this month', wi_e(number_format($aiAll)), $t('tokens, all clients'), 'acc')
        . '</div>';
    $o .= '<div class="sa-row"><div class="card"><div class="card-h"><h2>' . $t('Renewals and trials ending soon') . '</h2><a class="link" href="?p=clients&amp;f=expiring">' . $t('View all') . ' →</a></div>';
    if (!$S['due']) $o .= '<div class="empty">' . $t('No subscription ends within 7 days.') . '</div>';
    else { $o .= '<div style="overflow-x:auto"><table class="tbl"><tr><th>' . $t('Client') . '</th><th>' . $t('Plan') . '</th><th>' . $t('Ends on') . '</th><th>' . $t('Payment method') . '</th><th>' . $t('Status') . '</th></tr>';
        foreach (array_slice($S['due'], 0, 8) as $d) { $c = $d['c']; $dd = (int) $d['s']['days']; $pm = (string) ($c['pay_method'] ?? '');
            $o .= '<tr><td dir="auto">' . $name($c) . '</td><td dir="auto">' . wi_e((string) (($c['plan'] ?? '') ?: '—')) . '</td><td dir="ltr">' . wi_e((string) $c['expires']) . '</td><td>' . ($pm !== '' && isset(wi_pay_methods()[$pm]) ? $t(wi_pay_methods()[$pm]) : '—') . '</td>'
                . '<td><span class="sa-chip ' . ($dd < 0 ? 'bad' : (!empty($d['p']['trial']) ? 'acc' : 'warn')) . '">' . ($dd < 0 ? wi_e(sprintf(wi_t('%d day(s) ago'), -$dd)) : wi_e(sprintf(wi_t('%d day(s) left'), $dd))) . '</span></td></tr>'; }
        $o .= '</table></div>'; }
    $o .= '</div><div class="card"><div class="card-h"><h2>' . $t('Number and gateway health') . '</h2><a class="link" href="?p=health">' . $t('View all') . ' →</a></div><div style="overflow-x:auto"><table class="tbl">';
    foreach ($nums as $n) $o .= '<tr><td dir="auto">' . ($n['up'] ? '🟢' : '🔴') . ' ' . wi_e(wi_account_label($n['a'])) . '</td><td class="muted">' . wi_e((string) (($n['a']['gateway'] ?? '') ?: 'local')) . '</td><td><span class="sa-chip ' . ($n['up'] ? 'ok">' . $t('Connected') : 'bad">' . $t('Offline')) . '</span></td></tr>';
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
    return $o;
}
