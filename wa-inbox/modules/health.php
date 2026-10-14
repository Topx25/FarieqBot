<?php
// WhatsApp Inbox — health (split out of features.php in v3.8, code unchanged)

/** API calls of this area (moved unchanged from the old single switch). */
function wf_api_health(string $api): void {
    switch ($api) {
    /* ----- 9) Number health ----- */
    case 'health': {
        $out = [];
        foreach (wi_my_accounts() as $acc) {
            $h = wi_gateway('/health', null, $acc);
            $out[] = ['id' => $acc['id'], 'name' => $acc['name'], 'enabled' => !empty($acc['enabled']), 'restarts' => wi_guard_restarts((string) $acc['id'])] + (is_array($h) ? $h : []);
        }
        wi_json(['ok' => true, 'accounts' => $out, 'alerts' => array_intersect_key(wi_read('health_alerts.json', []), wi_my_accounts()), 'resources' => wi_is_super() ? wi_read('resources.json', null) : null]); // only this user's numbers; server resources for the owner
    }

    }
}
