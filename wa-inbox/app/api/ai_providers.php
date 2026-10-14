<?php
// WhatsApp Inbox API — AI providers. Included by app/api.php inside the request (same scope: $api, $apiPage…).
    /* ---------- AI providers (shared with the invoice system, admins only) ---------- */
    if ($api === 'ai_providers') {
        if (!wi_is_super() && wi_page_perm('ai') !== 'edit') wi_json(['ok' => false, 'error' => 'Admins only.'], 403); // a client edits their own keys
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') wi_json(wi_ai_call(['action' => 'ai_list'], 30) + ['engine' => wi_sys('ai_engine')]);
        wi_check_csrf();
        @set_time_limit(150);
        $in = json_decode(wi_input(), true) ?: [];
        $act = ['refresh' => 'ai_refresh', 'mode' => 'ai_mode'][$in['do'] ?? ''] ?? 'ai_save';
        $body = ['action' => $act, 'id' => (string) ($in['id'] ?? ''), 'mode' => (string) ($in['mode'] ?? '')];
        if ($act === 'ai_save') foreach (['enabled', 'api_key', 'clear_key', 'extra_keys', 'clear_extra', 'model', 'plan', 'daily_limit', 'order', 'base_url', 'name'] as $k) if (isset($in[$k])) $body[$k] = $in[$k];
        $res = wi_ai_call($body, 140);
        wi_log('ai_providers', ['do' => $act, 'id' => $body['id']]);
        wi_json($res);
    }
