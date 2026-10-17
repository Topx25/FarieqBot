<?php
// WhatsApp Inbox — JSON API: every ?api=… request (moved out of index.php; runs in index.php's scope: $api, wi_* and wf_* are available).
/* ---------------- JSON API (signed-in only) ---------------- */
if (isset($_GET['api'])) {
    if (!wi_logged_in()) wi_json(['ok' => false, 'error' => 'Sign in required.'], 401);
    $api = (string) $_GET['api'];
    // API calls only read the session: release its lock so a slow WhatsApp gateway never freezes other pages.
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    if ($api === 'jslog') { // browser errors, for debugging the layout
        $in = json_decode(wi_input(), true) ?: [];
        $L = wi_read('js_errors.json', []); $L[] = ['at' => date('c'), 'by' => $_SESSION['email'] ?? '', 'page' => mb_substr((string) ($in['page'] ?? ''), 0, 200), 'msg' => mb_substr((string) ($in['msg'] ?? ''), 0, 500), 'info' => mb_substr((string) ($in['info'] ?? ''), 0, 1500)];
        wi_write('js_errors.json', array_slice($L, -100)); wi_json(['ok' => true]);
    }
    if ($api === 'qr_mine') {
        if (wi_page_perm('inbox') !== 'edit') wi_json(['ok' => false, 'error' => 'You do not have access to this page.'], 403);
        // Long messages the team sends again and again → candidate quick replies, with suggested trigger phrases (AI names them).
        if (!wi_can_reply()) wi_json(['ok' => false, 'error' => 'You do not have permission to reply.'], 403);
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close(); @set_time_limit(200);
        require_once dirname(__DIR__) . '/features.php';
        $rows = wi_gateway('/archive?months=' . (int) wi_sys('months_short') . '&limit=60000')['rows'] ?? []; $groups = [];
        foreach ($rows as $m) { if (($m['dir'] ?? '') !== 'out' || ($m['via'] ?? '') === 'bot') continue; $t = trim((string) ($m['text'] ?? '')); if (mb_strlen($t) < 60) continue;
            $k = mb_substr(wf_norm($t), 0, 400); $groups[$k] ??= ['text' => $t, 'n' => 0, 'last' => '']; $groups[$k]['n']++; $groups[$k]['last'] = max($groups[$k]['last'], (string) ($m['time'] ?? '')); }
        $have = array_map(fn($r) => mb_substr(wf_norm((string) $r['text']), 0, 400), wi_replies());
        $cands = array_values(array_filter($groups, fn($g, $k) => $g['n'] >= 3 && !in_array($k, $have, true), ARRAY_FILTER_USE_BOTH));
        usort($cands, fn($a, $b) => $b['n'] <=> $a['n']); $cands = array_slice($cands, 0, 15);
        if (!$cands) wi_json(['ok' => true, 'items' => []]);
        $list = ''; foreach ($cands as $i => $c) $list .= '### ' . ($i + 1) . "\n" . mb_substr($c['text'], 0, 700) . "\n\n";
        $ai = wi_ai('Quick reply suggestions', wf_prompt('quick_reply_mine', (string) (wi_current()['id'] ?? ''), ['messages' => $list]), 150, ['subject' => 'quick replies']);
        $names = []; foreach ((array) ($ai['data']['items'] ?? []) as $x) $names[(int) ($x['n'] ?? 0)] = $x;
        $out = []; foreach ($cands as $i => $c) $out[] = ['text' => $c['text'], 'count' => $c['n'], 'last' => $c['last'], 'title' => (string) ($names[$i + 1]['title'] ?? mb_substr($c['text'], 0, 30)), 'triggers' => array_values(array_map('strval', (array) ($names[$i + 1]['triggers'] ?? [])))];
        wi_json(['ok' => true, 'items' => $out, 'ai' => !empty($ai['ok'])]);
    }
    // A chat or group hidden from this user: refused by its address too (opening it from a link, its media, notes, export…).
    if (($H = wi_hidden_jids()) && isset($H[(string) ($_GET['jid'] ?? ($_GET['g'] ?? ''))])) wi_json(['ok' => false, 'error' => 'This conversation is hidden from you.'], 403);
    // Who sees a chat (its header → 🔒): the client or admin hides it from chosen team members.
    if ($api === 'hide_for') {
        $jid = (string) ($_GET['jid'] ?? ''); if ($jid === '' || !wi_can_users()) wi_json(['ok' => false, 'error' => 'Not allowed.'], 403);
        $acc = wi_current(); $all = wi_users(); $rows = [];
        if ($_SERVER['REQUEST_METHOD'] === 'POST') { $in = json_decode(wi_input(), true) ?: []; $em = strtolower((string) ($in['email'] ?? '')); $hit = false;
            foreach ($all as &$u) { if (strtolower((string) $u['email']) !== $em || !wi_user_in_scope($u) || in_array($u['role'] ?? '', ['super', 'client'], true)) continue; $hj = (array) ($u['hide_jids'] ?? []);
                $hj = !empty($in['hide']) ? array_values(array_unique(array_merge($hj, [$jid]))) : array_values(array_diff($hj, [$jid])); $u['hide_jids'] = $hj; $hit = true; } unset($u);
            if (!$hit) wi_json(['ok' => false, 'error' => 'Not allowed.'], 403); wi_save_users($all); wi_log('hide_for', ['jid' => $jid, 'target' => $em, 'hide' => !empty($in['hide'])]); }
        foreach (wi_users() as $u) { if (in_array($u['role'] ?? '', ['super', 'client'], true) || !wi_user_in_scope($u) || strtolower((string) $u['email']) === strtolower((string) ($_SESSION['email'] ?? ''))) continue;
            $sees = !$acc || wi_is_team($u) || isset(array_flip((array) ($u['accounts'] ?? []))[$acc['id']]); if (!$sees) continue;
            $rows[] = ['email' => (string) $u['email'], 'name' => (string) ($u['name'] ?? '') ?: (string) $u['email'], 'role' => (string) ($u['role'] ?? ''), 'hidden' => in_array($jid, (array) ($u['hide_jids'] ?? []), true)]; }
        wi_json(['ok' => true, 'users' => $rows]);
    }
    // Page permissions: no access → refused; view only → no changes (POST).
    wi_ctx_set('api_page', $apiPage = wi_api_page($api));
    if ($apiPage !== '') { $pp = wi_page_perm($apiPage);
        if ($pp === '') wi_json(['ok' => false, 'error' => 'You do not have access to this page.'], 403);
        if ($pp === 'view' && $_SERVER['REQUEST_METHOD'] === 'POST') wi_json(['ok' => false, 'error' => 'View only — you cannot change this page.'], 403); }
    // Cases are one shared database: every request of the Cases page (and the group lists of the case form) runs on the cases number,
    // so a user with the Cases permission works on the same cases without being linked to that number.
    if (($apiPage === 'cases' || ($api === 'meeting_targets' && ($_GET['ctx'] ?? '') === 'cases')) && wi_page_perm('cases') !== '' && !wi_current_is_own() && !wi_tenant() && ($ca = wi_cases_account()) && wi_may_see_account($ca)) wi_ctx_set('acc', $ca); // a client's panel always works on its own numbers // an owner works on their own number's cases
    // Inside a conversation: what this user may do there (chat permissions), and which conversations an employee may open.
    $chatNeed = ['send' => 'reply', 'react' => 'reply', 'suggest_reply' => 'reply', 'reply_fill' => 'reply', 'send_media' => 'media', 'export' => 'export',
        'send_invoice' => 'invoices', 'chat_invoices' => 'invoices', 'inv_reminder' => 'invoices', 'delete_msg' => 'delete_msg', 'delete_chat' => 'delete_chat', 'media_send' => 'media', 'forward_text' => 'reply', 'group_add' => 'groups', 'group_invite_send' => 'groups', 'group_admin_list' => 'groups', 'group_removed' => 'groups'];
    if ($api === 'notes' && $_SERVER['REQUEST_METHOD'] === 'POST') $chatNeed['notes'] = 'notes';
    if (isset($chatNeed[$api]) && !wi_chat_can($chatNeed[$api])) wi_json(['ok' => false, 'error' => 'You do not have permission for this in a conversation.'], 403);
    if (wi_chat_scope() !== 'all') {
        $cjBody = $_SERVER['REQUEST_METHOD'] === 'POST' && !$_FILES ? (json_decode(wi_input(), true) ?: []) : [];
        $cj = preg_replace('/[^0-9A-Za-z@.:_-]/', '', (string) ($_GET['jid'] ?? $_POST['jid'] ?? ($cjBody['jid'] ?? '')));
        if ($cj !== '' && !wi_chat_visible((string) (wi_current()['id'] ?? ''), $cj)) wi_json(['ok' => false, 'error' => 'This conversation is handled by a colleague.'], 403);
    }
    require dirname(__DIR__) . '/features.php';
    wi_features_api($api); // new features (cases, groups, schedule, programs, status, health, backup…) — returns only if not handled
    require __DIR__ . '/api/media_lib.php'; // media library: index, captions, send / forward
    require __DIR__ . '/api/chats.php'; // chats, threads, sending, media, export, deleting, groups, contact, live events
    require __DIR__ . '/api/teamwork.php'; // teamwork: quick replies, labels, assignment, reminders, viewing; analytics; library
    require __DIR__ . '/api/audio.php'; // audio library
    require __DIR__ . '/api/invoices.php'; // invoices from the chat and overdue reminders
    require __DIR__ . '/api/summary.php'; // AI summary and category per chat
    require __DIR__ . '/api/library_ai.php'; // library: AI review in batches
    require __DIR__ . '/api/library_tools.php'; // library: AI tools per group
    require __DIR__ . '/api/ai_providers.php'; // AI providers
    require __DIR__ . '/api/library_misc.php'; // library hide, photos, notes, broadcast lists, search
    wi_json(['ok' => false, 'error' => 'Unknown request.'], 404);
}
