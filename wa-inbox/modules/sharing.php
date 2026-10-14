<?php
// WhatsApp Inbox — sharing (split out of features.php in v3.8, code unchanged)

/** API calls of this area (moved unchanged from the old single switch). */
function wf_api_sharing(string $api): void {
    switch ($api) {
    /* ----- Sharing a page with another user (by email or WhatsApp number) ----- */
    case 'perm_share': {
        $in = wf_post(); $page = (string) ($in['page'] ?? ''); $lvl = (string) ($in['level'] ?? 'view');
        if (!wi_can_share($page)) wi_json(['ok' => false, 'error' => 'You cannot share this page.'], 403);
        if (!in_array($lvl, ['view', 'edit', ''], true)) $lvl = 'view';
        if ($lvl === 'edit' && !wi_is_owner() && wi_page_perm($page) !== 'edit') $lvl = 'view';
        $to = strtolower(trim((string) ($in['to'] ?? ''))); $ph = wi_norm_phone($to); $users = wi_users(); $hit = null;
        foreach ($users as $i => $u) if (!empty($u['active']) && ((filter_var($to, FILTER_VALIDATE_EMAIL) && strtolower($u['email']) === $to) || ($ph !== '' && ($u['phone'] ?? '') === $ph))) $hit = $i;
        if ($hit === null) wi_json(['ok' => false, 'error' => 'No user with this email or number — ask the admin to add them first.']);
        $u = $users[$hit];
        if (strtolower($u['email']) === strtolower((string) $_SESSION['email'])) wi_json(['ok' => false, 'error' => 'That is you.']);
        if (!in_array($u['role'] ?? '', ['viewer', 'manager'], true)) wi_json(['ok' => false, 'error' => ($u['name'] ?: $u['email']) . ' already has every page.']);
        if (!isset($u['pages']) && ($u['role'] ?? '') === 'viewer') { $u['pages'] = []; foreach (array_keys(wi_page_names()) as $k) if (!in_array($k, WI_ADMIN_PAGES, true)) $u['pages'][$k] = 'edit'; } // older user: keep what they had
        // Removing access is immediate; giving access waits until the person accepts it from their own account.
        if ($lvl === '') { unset($u['pages'][$page], $u['invites'][$page]); $msg = 'removed'; }
        else { $u['invites'][$page] = ['level' => $lvl, 'by' => $_SESSION['email'], 'by_name' => wi_me()['name'] ?? '', 'at' => date('c')]; $msg = 'invited'; }
        $u['shared'][$page] = ['by' => $_SESSION['email'], 'at' => date('c'), 'level' => $lvl];
        $users[$hit] = $u; wi_save_users($users); wi_log('page_share', ['target' => $u['email'], 'page' => $page, 'level' => $lvl]);
        wi_json(['ok' => true, 'name' => $u['name'] ?: $u['email'], 'status' => $msg]);
    }
    case 'perm_invite': {
        // The invited person accepts or declines a shared page from their own account.
        $in = wf_post(); $page = (string) ($in['page'] ?? ''); $users = wi_users();
        foreach ($users as &$u) if (strtolower($u['email']) === strtolower((string) $_SESSION['email'])) {
            $inv = $u['invites'][$page] ?? null; if (!$inv) wi_json(['ok' => false, 'error' => 'No invitation.']);
            if (!empty($in['accept'])) { $u['pages'][$page] = $inv['level']; $u['shared'][$page]['accepted_at'] = date('c'); }
            unset($u['invites'][$page]);
        }
        unset($u); wi_save_users($users); wi_log(!empty($in['accept']) ? 'share_accept' : 'share_decline', ['page' => $page]);
        wi_json(['ok' => true]);
    }
    case 'perm_list': {
        $page = (string) ($_GET['page'] ?? '');
        if (!wi_can_share($page)) wi_json(['ok' => false, 'error' => 'You cannot share this page.'], 403);
        $out = [];
        foreach (wi_users() as $u) if (!empty($u['active']) && in_array($u['role'] ?? '', ['viewer', 'manager'], true)) {
            if (in_array($u['pages'][$page] ?? '', ['view', 'edit'], true)) $out[] = ['name' => $u['name'] ?? '', 'email' => $u['email'], 'phone' => $u['phone'] ?? '', 'level' => $u['pages'][$page], 'by' => $u['shared'][$page]['by'] ?? '', 'pending' => false];
            elseif (isset($u['invites'][$page])) $out[] = ['name' => $u['name'] ?? '', 'email' => $u['email'], 'phone' => $u['phone'] ?? '', 'level' => $u['invites'][$page]['level'], 'by' => $u['invites'][$page]['by'] ?? '', 'pending' => true];
        }
        wi_json(['ok' => true, 'users' => $out, 'can_edit' => wi_is_owner() || wi_page_perm($page) === 'edit']);
    }

    }
}
