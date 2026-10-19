<?php
/* ---------- Visitors of the treatment groups ----------
 * A group can keep a list of the people who come in ("Visitors list" in the group's settings). The list is built from the group's join
 * and leave events, so it also covers the past. Two optional messages, both written in the group's settings (nothing is built in):
 *  - farewell: a person leaves the group before finishing the reception steps (no recording sent, not moved on) → the farewell text is posted in the group;
 *  - returning: a person comes in again after leaving → the returning text is posted with the person and the
 *    group's admins tagged. Joining another of our groups within "days between visits" of leaving one is a move, not a visit.
 * Texts: {name} the person, {mention} their tag, {group} the group's name, {count} the number of visits. */

function wf_vis_state(string $aid): array { return (array) (wi_read('visitors.json', [])[$aid] ?? []) + ['people' => [], 'cur' => []]; }
function wf_vis_groups(string $aid): array {
    $c = wf_bot_run_cfg($aid); $out = [];
    foreach ((array) ($c['groups'] ?? []) as $jid => $g) { $g = wf_bot_group_cfg($c, (string) $jid); if ($g && !empty($g['visitors'])) $out[(string) $jid] = $g; }
    return $out;
}
/** The mobile number of a group member known by a WhatsApp ID (a long "lid"): ['phone' => ..., 'name' => ...] from the group's member list, kept for a minute. '' phone when unknown. */
function wf_group_member_phone(array $acc, string $gjid, string $id): array {
    static $cache = []; $k = (string) $acc['id'] . '|' . $gjid; $id = preg_replace('/\D/', '', $id);
    if (!isset($cache[$k]) || $cache[$k]['at'] < time() - 60) $cache[$k] = ['at' => time(), 'm' => (array) (wi_gateway('/groups/members?jid=' . rawurlencode($gjid), null, $acc)['members'] ?? [])];
    foreach ($cache[$k]['m'] as $x) if ($id !== '' && ($id === preg_replace('/\D/', '', (string) ($x['lid'] ?? '')) || $id === preg_replace('/\D/', '', (string) ($x['jid'] ?? '')) || $id === preg_replace('/\D/', '', (string) ($x['phone'] ?? '')))) return ['phone' => preg_replace('/\D/', '', (string) ($x['phone'] ?? '')), 'name' => (string) ($x['name'] ?? '')];
    return ['phone' => '', 'name' => ''];
}
/** Runs every minute: new join / leave events of the groups that keep a list. */
function wf_vis_due(array $accounts): void {
    foreach ($accounts as $acc) {
        $aid = (string) $acc['id']; if (wi_account_blocked($acc)) continue; $groups = wf_vis_groups($aid); if (!$groups) continue;
        $c = wf_bot_run_cfg($aid); $S = wf_vis_state($aid); $gate = (array) (wi_read('gate_state.json', [])[$aid] ?? []); $sent = 0; $dirty = false; $names = null;
        foreach ($groups as $gjid => $g) {
            $ev = (array) (wi_gateway('/groups/events?jid=' . rawurlencode($gjid), null, $acc)['events'] ?? []); if (!$ev) continue;
            usort($ev, fn($a, $b) => strcmp((string) $a['time'], (string) $b['time']));
            $boot = !isset($S['cur'][$gjid]); $cur = (string) ($S['cur'][$gjid] ?? ''); $gap = max(0, (int) ($g['revisit_days'] ?? 3)) * 86400;
            foreach ($ev as $e) {
                $t = (string) $e['time']; if (!$boot && $t <= $cur) continue; $act = (string) ($e['action'] ?? ''); $m = preg_replace('/\D/', '', (string) ($e['member'] ?? '')); if ($m === '' || !in_array($act, ['add', 'remove'], true)) { $cur = max($cur, $t); continue; }
                $ts = strtotime($t); $p = (array) ($S['people'][$m] ?? ['visits' => [], 'left' => []]); $new = false;
                if ($act === 'add') {
                    $lv = (array) end($p['visits']); $ll = $p['left'] ? (array) end($p['left']) : []; $leftSince = $ll && (int) $ll['t'] >= (int) ($lv['t'] ?? 0);
                    // A new visit: the first join; or a join after the person has left. Coming back to the same group always counts; going to another of our groups
                    // within the days between visits is a move, not a visit; a second "add" without a leave in between is the same visit.
                    if (!$p['visits'] || ($leftSince && ((string) $ll['g'] === $gjid || $ts - (int) $ll['t'] >= $gap))) { $p['visits'][] = ['t' => $ts, 'g' => $gjid]; $new = true; }
                } else if (($e['by'] ?? '') === $m || ($e['by'] ?? '') === '') $p['left'][] = ['t' => $ts, 'g' => $gjid];
                if (!empty($gate[$gjid][$m]['n']) && empty($p['name'])) $p['name'] = (string) $gate[$gjid][$m]['n'];
                $S['people'][$m] = $p; $dirty = true; $cur = max($cur, $t);
                if ($boot || $c['mode'] !== 'auto' || $sent >= 4 || $ts < time() - 3 * 3600) continue; // history only builds the list; late events are not announced
                $name = (string) ($p['name'] ?? '') !== '' ? (string) $p['name'] : '+' . $m;
                $kickAt = (int) ($g['revisit_kick'] ?? 0);
                if ($act === 'add' && $new && $kickAt > 0 && count($p['visits']) >= $kickAt && !isset(wf_team($acc, (array) ($c['groups'] ?? []))[$m])) { // the Nth entry: accepted (join requests are approved by the group setting) and removed again
                    $kj = ''; for ($try = 0; $try < 2 && $kj === ''; $try++) { if ($try) sleep(3); foreach ((array) (wi_gateway('/groups/members?jid=' . rawurlencode($gjid), null, $acc)['members'] ?? []) as $x) if (in_array($m, [preg_replace('/\D/', '', (string) ($x['lid'] ?? '')), preg_replace('/\D/', '', (string) ($x['jid'] ?? '')), preg_replace('/\D/', '', (string) ($x['phone'] ?? ''))], true) && empty($x['admin'])) { $kj = (string) $x['jid']; break; } }
                    $r = $kj !== '' ? wi_gateway('/groups/remove', ['group' => $gjid, 'jid' => $kj], $acc) : ['ok' => false];
                    if (empty($r['ok'])) { $p['kick_try'] = ($p['kick_try'] ?? 0) + 1; }
                    else if (trim((string) ($g['kick_text'] ?? '')) !== '') wi_gateway('/chat/send', ['jid' => $gjid, 'text' => strtr((string) $g['kick_text'], ['{name}' => $name, '{mention}' => $name, '{count}' => (string) count($p['visits'])]), 'by' => 'bot', 'via' => 'bot'], $acc);
                    $S['people'][$m] = $p; wi_log('visitor_kick', ['account' => $aid, 'group' => $gjid, 'visits' => count($p['visits']), 'removed' => !empty($r['ok'])]); continue;
                }
                if ($act === 'add' && $new && count($p['visits']) >= 2 && !empty($g['revisit']) && trim((string) $g['revisit_text']) !== '') {
                    if ($names === null) { $names = []; foreach ((array) (wi_gateway('/groups', null, $acc)['groups'] ?? []) as $x) $names[(string) ($x['id'] ?? $x['jid'] ?? '')] = (string) ($x['subject'] ?? $x['name'] ?? ''); }
                    $mm = (array) (wi_gateway('/groups/members?jid=' . rawurlencode($gjid), null, $acc)['members'] ?? []); $who = wf_group_member_phone($acc, $gjid, $m); $ph = $who['phone'] !== '' ? $who['phone'] : $m; // the tag shows the mobile number, not the long WhatsApp ID
                    if (empty($p['name']) && $who['name'] !== '') $name = $who['name'];
                    $adm = array_values(array_filter($mm, fn($x) => !empty($x['admin']) && empty($x['is_lid'])));
                    $adj = array_map(fn($x) => wf_phone((string) $x['phone']) . '@s.whatsapp.net', array_slice($adm, 0, 8)); $adt = implode(' ', array_map(fn($j) => '@' . explode('@', $j)[0], $adj));
                    $txt = strtr((string) $g['revisit_text'], ['{name}' => $name, '{mention}' => '@' . $ph, '{group}' => $names[$gjid] ?? (string) ($g['name'] ?? ''), '{count}' => (string) count($p['visits'])]);
                    if (strpos((string) $g['revisit_text'], '{mention}') === false) $txt = '@' . $ph . ' ' . $txt;
                    $r = wi_gateway('/chat/send', ['jid' => $gjid, 'text' => trim($txt . ($adt !== '' ? "\n" . $adt : '')), 'mentions' => array_merge([$ph . '@s.whatsapp.net'], $adj), 'by' => 'bot', 'via' => 'bot'], $acc);
                    wi_log('visitor_return', ['account' => $aid, 'group' => $gjid, 'visits' => count($p['visits']), 'sent' => !empty($r['ok'])]); $sent++;
                } elseif ($act === 'remove' && ($e['by'] ?? '') === $m && !empty($g['bye']) && trim((string) $g['bye_text']) !== '' && empty($gate[$gjid][$m]['v']) && empty($gate[$gjid][$m]['moved']) && empty($p['bye'][$gjid])) {
                    $txt = strtr((string) $g['bye_text'], ['{name}' => $name, '{mention}' => $name, '{group}' => (string) ($g['name'] ?? ''), '{count}' => (string) count($p['visits'])]);
                    $r = wi_gateway('/chat/send', ['jid' => $gjid, 'text' => $txt, 'by' => 'bot', 'via' => 'bot'], $acc);
                    if (!empty($r['ok'])) { $p['bye'][$gjid] = $ts; $S['people'][$m] = $p; }
                    wi_log('visitor_bye', ['account' => $aid, 'group' => $gjid, 'sent' => !empty($r['ok'])]); $sent++;
                }
            }
            $S['cur'][$gjid] = $cur !== '' ? $cur : gmdate('c'); $dirty = true;
        }
        if ($dirty) wi_update('visitors.json', function ($V) use ($aid, $S) { $V[$aid] = $S; return $V; });
    }
}
/** The list for the page: [{phone, name, visits, first, last, returning}] newest visit first. */
function wf_vis_list(string $aid): array {
    $S = wf_vis_state($aid); $gate = (array) (wi_read('gate_state.json', [])[$aid] ?? []); $out = [];
    foreach ($S['people'] as $m => $p) { $v = array_column((array) ($p['visits'] ?? []), 't'); if (!$v) continue; $name = (string) ($p['name'] ?? '');
        if ($name === '') foreach ($gate as $people) if (!empty($people[$m]['n'])) { $name = (string) $people[$m]['n']; break; }
        $out[] = ['phone' => (string) $m, 'name' => $name, 'visits' => count($v), 'first' => date('c', (int) min($v)), 'last' => date('c', (int) max($v)), 'returning' => count($v) >= 2]; }
    usort($out, fn($a, $b) => strcmp($b['last'], $a['last']));
    return array_slice($out, 0, 2000);
}
function wf_api_visitors(string $api): void {
    if ($api !== 'visitors') return;
    $aid = wf_aid(); if ($aid === '') wi_json(['ok' => false, 'error' => 'No number.']);
    wi_json(['ok' => true, 'groups' => count(wf_vis_groups($aid)), 'list' => wf_vis_list($aid)]);
}
