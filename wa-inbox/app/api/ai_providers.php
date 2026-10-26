<?php
// WhatsApp Inbox API — AI providers. Included by app/api.php inside the request (same scope: $api, $apiPage…).
    /* ---------- AI providers (shared with the invoice system, admins only) ---------- */
    if ($api === 'ai_providers') {
        if (!wi_is_super() && wi_page_perm('ai') !== 'edit') wi_json(['ok' => false, 'error' => 'Admins only.'], 403); // a client edits their own keys
        // hidden providers (switched off and listed apart): per AI scope, the super admin's or the client's own keys
        $hScope = wi_is_super() ? 'system' : strtolower((string) (wi_me()['email'] ?? '')); $hMark = function (array $r) use ($hScope): array { $H = (array) (wi_read('ai_hidden.json', [])[$hScope] ?? []);
            foreach ((array) ($r['providers'] ?? []) as $hi => $hp) if (is_array($hp)) $r['providers'][$hi]['hidden'] = in_array((string) ($hp['id'] ?? ''), $H, true); return $r; };
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') wi_json($hMark(wi_ai_call(['action' => 'ai_list'], 30) + ['engine' => wi_sys('ai_engine')]));
        wi_check_csrf();
        @set_time_limit(150);
        $in = json_decode(wi_input(), true) ?: [];
        $act = ['refresh' => 'ai_refresh', 'models' => 'ai_models', 'mode' => 'ai_mode'][$in['do'] ?? ''] ?? 'ai_save';
        $body = ['action' => $act, 'id' => (string) ($in['id'] ?? ''), 'mode' => (string) ($in['mode'] ?? '')];
        if ($act === 'ai_save') foreach (['enabled', 'api_key', 'clear_key', 'extra_keys', 'clear_extra', 'keys', 'key_mode', 'model', 'plan', 'daily_limit', 'order', 'base_url', 'name'] as $k) if (isset($in[$k])) $body[$k] = $in[$k];
        if (isset($in['hidden']) && $body['id'] !== '') { $hid = $body['id']; $on = !empty($in['hidden']);
            wi_update('ai_hidden.json', function ($A) use ($hScope, $hid, $on) { $L = array_values(array_diff((array) ($A[$hScope] ?? []), [$hid])); if ($on) $L[] = $hid; $A[$hScope] = $L; return $A; }); }
        $res = $hMark(wi_ai_call($body, 140));
        wi_log('ai_providers', ['do' => $act, 'id' => $body['id']]);
        wi_json($res);
    }
