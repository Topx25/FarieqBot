<?php
// WhatsApp Inbox API — invoices from the chat and overdue reminders. Included by app/api.php inside the request (same scope: $api, $apiPage…).
    /* ---------- Invoices from the chat + overdue reminders per number ---------- */
    if ($api === 'chat_invoices') {
        if (!wi_sys('addon_invoices') || empty(wi_current()['payments'])) wi_json(['ok' => false, 'off' => true, 'error' => 'Invoices are switched off for this number (Settings → WhatsApp numbers).']);
        $jid = preg_replace('/[^0-9A-Za-z@.:_-]/', '', (string) ($_GET['jid'] ?? ''));
        $cc = wi_chat_customer($jid);
        $acc = wi_current();
        $set = wi_read('inv_reminders.json', [])[$acc['id'] ?? ''][$jid] ?? null;
        if (!$cc['customer']) wi_json(['ok' => true, 'customer' => null, 'phone' => $cc['phone'], 'reminder' => $set]);
        $inv = wi_customer_invoices((int) $cc['customer']['id']);
        foreach ($inv as &$i) $i['overdue'] = wi_is_overdue($i); unset($i);
        wi_json(['ok' => true, 'customer' => $cc['customer'], 'phone' => $cc['phone'], 'invoices' => $inv, 'reminder' => $set]);
    }
    if ($api === 'send_invoice') {
        if (!wi_sys('addon_invoices') || empty(wi_current()['payments'])) wi_json(['ok' => false, 'off' => true, 'error' => 'Invoices are switched off for this number (Settings → WhatsApp numbers).']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') wi_json(['ok' => false, 'error' => 'POST only.'], 405);
        wi_check_csrf();
        if (!wi_can_reply()) wi_json(['ok' => false, 'error' => 'You do not have permission to reply.'], 403);
        $in = json_decode(wi_input(), true) ?: [];
        $jid = preg_replace('/[^0-9A-Za-z@.:_-]/', '', (string) ($in['jid'] ?? ''));
        $cc = wi_chat_customer($jid);
        if (!$cc['customer']) wi_json(['ok' => false, 'error' => 'This number is not a customer in the invoice system.'], 422);
        $ids = array_map('intval', array_column(wi_customer_invoices((int) $cc['customer']['id']), 'id'));
        $iid = (int) ($in['invoice_id'] ?? 0);
        if (!in_array($iid, $ids, true)) wi_json(['ok' => false, 'error' => 'This invoice does not belong to this customer.'], 403);
        @set_time_limit(180);
        $img = wi_bridge(['action' => 'invoice_image', 'invoice_id' => $iid], 90);
        if (empty($img['ok'])) wi_json($img);
        $r = wi_gateway('/chat/send', ['jid' => $jid, 'text' => trim(mb_substr((string) ($in['text'] ?? ''), 0, 1000)), 'attachment' => $img['attachment'], 'by' => (string) $_SESSION['email']]);
        wi_log('send_invoice', ['account' => wi_current()['id'] ?? '', 'jid' => $jid, 'invoice' => $img['reference'] ?? $iid, 'ok' => !empty($r['ok'])]);
        wi_json($r + ['reference' => $img['reference'] ?? '']);
    }
    if ($api === 'inv_reminder') {
        if (!wi_sys('addon_invoices') || empty(wi_current()['payments'])) wi_json(['ok' => false, 'off' => true, 'error' => 'Invoices are switched off for this number (Settings → WhatsApp numbers).']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') wi_json(['ok' => false, 'error' => 'POST only.'], 405);
        wi_check_csrf();
        $acc = wi_current(); if (!$acc || !wi_can_manage($acc)) wi_json(['ok' => false, 'error' => 'Not allowed for this number.'], 403);
        $in = json_decode(wi_input(), true) ?: [];
        $jid = preg_replace('/[^0-9A-Za-z@.:_-]/', '', (string) ($in['jid'] ?? ''));
        $all = wi_read('inv_reminders.json', []);
        $cc = wi_chat_customer($jid);
        if (!empty($in['enabled']) && !$cc['customer']) wi_json(['ok' => false, 'error' => 'This number is not a customer in the invoice system.'], 422);
        $prev = $all[$acc['id']][$jid] ?? [];
        $all[$acc['id']][$jid] = [
            'enabled' => !empty($in['enabled']), 'every_days' => max(1, min(30, (int) ($in['every_days'] ?? 3))), 'hour' => max(9, min(21, (int) ($in['hour'] ?? 11))),
            'with_image' => !empty($in['with_image']), 'max_times' => max(1, min(20, (int) ($in['max_times'] ?? 5))),
            'message' => trim(mb_substr((string) ($in['message'] ?? ''), 0, 1000)) ?: "مرحباً {name} 👋\nنذكّرك بالفاتورة {invoice} المستحقة بمبلغ {due}.\nللدفع: {methods}\nشكراً لك 🌟",
            'phone' => $cc['phone'], 'biller_id' => $cc['customer']['id'] ?? null, 'name' => $cc['customer']['person'] ?? $cc['name'],
            'last_sent' => $prev['last_sent'] ?? null, 'sent_count' => (int) ($prev['sent_count'] ?? 0), 'by' => (string) $_SESSION['email'], 'updated_at' => date('c'),
        ];
        wi_write('inv_reminders.json', $all); wi_log('inv_reminder', ['account' => $acc['id'], 'jid' => $jid, 'enabled' => !empty($in['enabled'])]);
        wi_json(['ok' => true, 'reminder' => $all[$acc['id']][$jid]]);
    }
