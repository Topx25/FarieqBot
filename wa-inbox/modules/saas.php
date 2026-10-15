<?php
declare(strict_types=1);
/*
 * SaaS — Topx1.Host
 * Plans with real limits (numbers, team seats, bot, meetings, cases, backups, AI calls on the operator's keys),
 * self sign-up with a trial, and the public pricing page. Plans are edited by the super admin (Clients page);
 * these are only the starting values.
 */

/** The plans: the saved ones (Clients → Plans), or the starting set. */
function wi_plans(): array {
    $saved = wi_read('plans.json', []);
    if (is_array($saved) && $saved) return $saved;
    return [
        'trial' => ['name' => 'Trial', 'price' => 0, 'days' => 7, 'numbers' => 1, 'team' => 2, 'bot' => true, 'meetings' => true, 'cases' => true, 'backup' => false, 'ai_calls' => 200, 'public' => true, 'trial' => true],
        'basic' => ['name' => 'Basic', 'price' => 10, 'days' => 30, 'numbers' => 1, 'team' => 3, 'bot' => false, 'meetings' => false, 'cases' => true, 'backup' => true, 'ai_calls' => 0, 'public' => true, 'trial' => false],
        'pro' => ['name' => 'Pro', 'price' => 25, 'days' => 30, 'numbers' => 3, 'team' => 10, 'bot' => true, 'meetings' => true, 'cases' => true, 'backup' => true, 'ai_calls' => 2000, 'public' => true, 'trial' => false],
    ];
}
/** One plan, cleaned. */
function wi_plan_clean(array $p): array {
    return ['name' => mb_substr(trim((string) ($p['name'] ?? '')), 0, 40), 'price' => max(0, round((float) ($p['price'] ?? 0), 2)), 'days' => max(1, min(3650, (int) ($p['days'] ?? 30))),
        'numbers' => max(0, min(100, (int) ($p['numbers'] ?? 1))), 'team' => max(0, min(500, (int) ($p['team'] ?? 0))), 'ai_calls' => max(0, min(1000000, (int) ($p['ai_calls'] ?? 0))),
        'bot' => !empty($p['bot']), 'meetings' => !empty($p['meetings']), 'cases' => !empty($p['cases']), 'backup' => !empty($p['backup']), 'public' => !empty($p['public']), 'trial' => !empty($p['trial'])];
}
/** The plan a client is on (by its id), or null for a hand-made client. */
function wi_client_plan(array $client): ?array { $id = (string) ($client['plan_id'] ?? ''); $p = wi_plans()[$id] ?? null; return is_array($p) ? $p : null; }
/** Gives a client the limits and features of a plan (the super admin can still change a field by hand afterwards). */
function wi_plan_apply(array $client, string $planId, bool $setEnd): array {
    $p = wi_plans()[$planId] ?? null; if (!$p) return $client;
    $client['plan_id'] = $planId; $client['plan'] = (string) $p['name'];
    $client['max_numbers'] = (int) $p['numbers']; $client['max_team'] = (int) $p['team']; $client['ai_calls'] = (int) $p['ai_calls'];
    $client['can_bot'] = !empty($p['bot']); $client['can_meetings'] = !empty($p['meetings']); $client['can_cases'] = !empty($p['cases']); $client['can_backup'] = !empty($p['backup']);
    if ($setEnd) $client['expires'] = date('Y-m-d', strtotime('+' . (int) $p['days'] . ' days'));
    return $client;
}
/** How many numbers a client may link: their plan's, or 1 for a hand-made client. */
function wi_client_max_numbers(array $client): int { return max(0, (int) ($client['max_numbers'] ?? 1)); }

/* ---------- AI on the operator's keys: a monthly allowance per client ---------- */
/** True when the client has AI keys of their own that are switched on. */
function wi_client_has_ai(string $email): bool {
    $s = (array) wi_read('ai_providers_u' . substr(md5(strtolower($email)), 0, 10) . '.json', []);
    foreach ((array) ($s['providers'] ?? []) as $p) if (!empty($p['enabled']) && (!empty($p['api_key']) || !empty($p['extra_keys']))) return true;
    return false;
}
/** Calls left this month on the operator's keys for a client (0 = none). */
function wi_ai_allowance_left(array $client): int {
    $quota = (int) ($client['ai_calls'] ?? 0); if ($quota <= 0) return 0;
    $used = (int) (wi_read('ai_allowance.json', [])[strtolower((string) $client['email'])][date('Y-m')] ?? 0);
    return max(0, $quota - $used);
}
function wi_ai_allowance_use(string $email): void {
    $e = strtolower($email); $m = date('Y-m');
    wi_update('ai_allowance.json', function ($A) use ($e, $m) { $A[$e][$m] = (int) ($A[$e][$m] ?? 0) + 1; foreach ((array) $A[$e] as $k => $_) if ($k < date('Y-m', strtotime('-3 months'))) unset($A[$e][$k]); return $A; });
}

/* ---------- Self sign-up ---------- */
/** Creates a client on the trial plan. Returns '' or the reason it was refused. */
function wi_signup(array $in): string {
    if (!wi_sys('signup_on')) return 'Sign-up is closed. Contact us.';
    $name = trim(mb_substr((string) ($in['name'] ?? ''), 0, 80)); $biz = trim(mb_substr((string) ($in['business'] ?? ''), 0, 120));
    $email = strtolower(trim((string) ($in['email'] ?? ''))); $phone = wi_norm_phone((string) ($in['phone'] ?? ''));
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $phone === '') return 'Fill in your name, a valid email and your WhatsApp number.';
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? ''); $limited = false;
    wi_update('signup_ips.json', function ($S) use ($ip, &$limited) { $S = array_filter((array) $S, fn($t) => max((array) $t) > time() - 86400);
        $S[$ip] = array_values(array_filter((array) ($S[$ip] ?? []), fn($t) => $t > time() - 86400)); if (count($S[$ip]) >= 3) $limited = true; else $S[$ip][] = time(); return $S; });
    if ($limited) return 'Too many sign-ups from this connection today. Try again tomorrow.';
    foreach (wi_users() as $u) { if (strtolower((string) $u['email']) === $email) return 'This email already has an account. Sign in instead.'; if ((string) ($u['phone'] ?? '') === $phone) return 'This WhatsApp number already has an account. Sign in instead.'; }
    $planId = ''; foreach (wi_plans() as $id => $p) if (!empty($p['trial'])) { $planId = (string) $id; break; }
    if ($planId === '') return 'Sign-up is closed. Contact us.';
    $u = ['email' => $email, 'name' => $name, 'business' => $biz, 'phone' => $phone, 'role' => 'client', 'accounts' => [], 'can_reply' => true, 'can_link' => true, 'own_number' => true,
        'password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), 'active' => true, 'created_at' => date('c'), 'signup' => true];
    $u = wi_plan_apply($u, $planId, true);
    $all = wi_users(); $all[] = $u; wi_save_users($all);
    wi_log('signup', ['target' => $email, 'plan' => $planId]);
    return '';
}
