<?php
/* ---------- Staff chat identity (Topx1.Host) ----------
 * A team member's replies from the dashboard go out with their name in front ("يوسف: the message"), and the first time they write in a chat —
 * even an old one — a welcome message that introduces them goes first. Both the format and the welcome text are message templates (Messages
 * settings); the name, "add my name" and "welcome" are set per user (Users). Employees and supervisors have both on unless it is changed. */

function wf_staff_cfg(?array $u): array {
    $team = $u && in_array($u['role'] ?? '', WI_TEAM_ROLES, true);
    $name = trim((string) ($u['chat_name'] ?? '')) ?: trim((string) ($u['name'] ?? ''));
    return ['prefix' => $u && array_key_exists('chat_prefix', $u) ? !empty($u['chat_prefix']) : $team, 'welcome' => $u && array_key_exists('chat_welcome', $u) ? !empty($u['chat_welcome']) : $team, 'name' => $name];
}
function wf_staff_greeted(string $aid, string $jid, string $email): bool { return isset(((array) (wi_read('staff_greeted.json', [])[$aid][$jid] ?? []))[strtolower($email)]); }
function wf_staff_mark(string $aid, string $jid, string $email): void {
    wi_update('staff_greeted.json', function ($A) use ($aid, $jid, $email) { $A[$aid][$jid][strtolower($email)] = date('c'); if (count($A[$aid]) > 5000) $A[$aid] = array_slice($A[$aid], -4000, null, true); return $A; });
}
/** What goes out for a reply typed by $u in chat $jid: ['welcome' => text or '', 'text' => the reply with the name in front]. */
function wf_staff_prepare(array $acc, string $jid, ?array $u, string $text): array {
    $c = wf_staff_cfg($u); $aid = (string) ($acc['id'] ?? ''); $out = ['welcome' => '', 'text' => $text];
    if ($c['name'] === '') return $out;
    if ($c['prefix'] && trim($text) !== '') $out['text'] = wf_msg('staff_prefix', ['name' => $c['name'], 'text' => $text]);
    if ($c['welcome'] && !str_ends_with($jid, '@g.us') && !wf_staff_greeted($aid, $jid, (string) $u['email'])) $out['welcome'] = wf_msg('staff_welcome', ['name' => $c['name'], 'business' => (string) ($acc['name'] ?? '')]);
    return $out;
}
