<?php
// WhatsApp Inbox — catalog: each number's products (name, price, photo, description). The bot answers prices from it and sends
// a product's photo when a person asks about one; the team sends a product from a chat. Photos live in the number's own media folder.

function wf_catalog(string $aid, bool $onlyOn = false): array {
    $L = array_values((array) (wi_read('catalog.json', [])[$aid] ?? []));
    return $onlyOn ? array_values(array_filter($L, fn($p) => !empty($p['on']))) : $L;
}
function wf_catalog_dir(string $aid): string { return dirname(__DIR__, 2) . '/storage/inbox_media/' . preg_replace('/[^A-Za-z0-9_-]/', '', $aid); }
function wf_catalog_price(array $p): string {
    $n = (float) ($p['price'] ?? 0); if ($n <= 0) return '';
    return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.') . ' ' . ((string) ($p['cur'] ?? '') ?: (string) wi_sys('currency'));
}
/** The catalog as a source for the bot's prompt ('' when empty). */
function wf_catalog_prompt(string $aid): string {
    $L = wf_catalog($aid, true); if (!$L) return ''; $o = '';
    foreach (array_slice($L, 0, 60) as $p) $o .= '- [' . $p['id'] . '] ' . $p['name'] . (($pr = wf_catalog_price($p)) !== '' ? ' — ' . $pr : '') . ((string) ($p['desc'] ?? '') !== '' ? ': ' . mb_substr(preg_replace('/\s+/u', ' ', (string) $p['desc']), 0, 220) : '') . "\n";
    return $o;
}
/** One product as a WhatsApp message: its photo (when it has one) with the name, price and description under it. */
function wf_catalog_send(array $acc, string $jid, string $id, string $via = 'team'): array {
    $aid = (string) $acc['id']; $p = null; foreach (wf_catalog($aid) as $x) if ($x['id'] === $id) $p = $x; if (!$p) return ['ok' => false, 'error' => 'Product not found.'];
    $text = '*' . $p['name'] . '*' . (($pr = wf_catalog_price($p)) !== '' ? "\n💰 " . $pr : '') . ((string) ($p['desc'] ?? '') !== '' ? "\n" . $p['desc'] : '') . ((string) ($p['link'] ?? '') !== '' ? "\n🔗 " . $p['link'] : '');
    $b = ['jid' => $jid, 'text' => $text, 'via' => $via]; if ((string) ($p['img'] ?? '') !== '' && is_file(dirname(__DIR__, 2) . '/storage/' . $p['img'])) $b['attachment'] = $p['img'];
    return wi_gateway('/chat/send', $b, $acc);
}
function wf_api_catalog(string $api): void {
    if (!in_array($api, ['catalog', 'catalog_save', 'catalog_del', 'catalog_img', 'catalog_send'], true)) return;
    $acc = wi_current(); if (!$acc) wi_json(['ok' => false, 'error' => 'No number chosen.'], 422); $aid = (string) $acc['id'];
    if ($api === 'catalog') wi_json(['ok' => true, 'items' => wf_catalog($aid), 'currency' => (string) wi_sys('currency')]);
    if ($api === 'catalog_img') { $id = preg_replace('/\W/', '', (string) ($_GET['id'] ?? '')); foreach (wf_catalog($aid) as $p) if ($p['id'] === $id && ($f = dirname(__DIR__, 2) . '/storage/' . ($p['img'] ?? '')) && is_file($f)) {
            header('Content-Type: ' . (mime_content_type($f) ?: 'image/jpeg')); header('Cache-Control: private, max-age=86400'); readfile($f); exit; }
        http_response_code(404); exit; }
    if ($api === 'catalog_send') { $in = wf_post(); $r = wf_catalog_send($acc, (string) ($in['jid'] ?? ''), (string) ($in['id'] ?? ''));
        if (!empty($r['ok'])) wi_log('catalog_send', ['id' => $in['id'] ?? '']); wi_json(['ok' => !empty($r['ok'])] + (empty($r['ok']) ? ['error' => (string) ($r['error'] ?? 'Not sent.')] : [])); }
    if (wi_page_perm('catalog') !== 'edit') wi_json(['ok' => false, 'error' => 'View only.'], 403);
    if ($api === 'catalog_del') { $id = preg_replace('/\W/', '', (string) (wf_post()['id'] ?? ''));
        wi_update('catalog.json', function ($C) use ($aid, $id) { foreach ((array) ($C[$aid] ?? []) as $k => $p) if ($p['id'] === $id) { if (!empty($p['img'])) @unlink(dirname(__DIR__, 2) . '/storage/' . $p['img']); unset($C[$aid][$k]); } $C[$aid] = array_values((array) ($C[$aid] ?? [])); return $C; });
        wi_json(['ok' => true]); }
    // save: a form with an optional photo; no id means a new product
    $in = $_POST ?: wf_post(); $name = trim((string) ($in['name'] ?? '')); if ($name === '') wi_json(['ok' => false, 'error' => 'Write the product name.']);
    $id = preg_replace('/\W/', '', (string) ($in['id'] ?? '')) ?: bin2hex(random_bytes(4)); $img = null;
    if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
        $mt = (string) mime_content_type($_FILES['file']['tmp_name']); $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mt] ?? '';
        if ($ext === '') wi_json(['ok' => false, 'error' => 'The photo must be JPG, PNG or WEBP.']); if ($_FILES['file']['size'] > 5 * 1048576) wi_json(['ok' => false, 'error' => 'The photo is larger than 5 MB.']);
        $d = wf_catalog_dir($aid); if (!is_dir($d)) @mkdir($d, 0700, true); $fn = 'cat_' . $id . '_' . time() . '.' . $ext;
        if (!move_uploaded_file($_FILES['file']['tmp_name'], $d . '/' . $fn)) wi_json(['ok' => false, 'error' => 'The photo was not saved.']); @chmod($d . '/' . $fn, 0600);
        $img = 'inbox_media/' . basename($d) . '/' . $fn; }
    $row = ['id' => $id, 'name' => mb_substr($name, 0, 120), 'price' => max(0, (float) str_replace(',', '.', (string) ($in['price'] ?? 0))), 'cur' => mb_substr(trim((string) ($in['cur'] ?? '')), 0, 8),
        'desc' => mb_substr(trim((string) ($in['desc'] ?? '')), 0, 1000), 'link' => mb_substr(trim((string) ($in['link'] ?? '')), 0, 300), 'cat' => mb_substr(trim((string) ($in['cat'] ?? '')), 0, 60), 'on' => !empty($in['on']) ? 1 : 0];
    wi_update('catalog.json', function ($C) use ($aid, $id, $row, $img) { $L = (array) ($C[$aid] ?? []); $f = false;
        foreach ($L as &$p) if ($p['id'] === $id) { if ($img && !empty($p['img'])) @unlink(dirname(__DIR__, 2) . '/storage/' . $p['img']); $p = $row + ['img' => $img ?? ($p['img'] ?? ''), 'at' => $p['at'] ?? date('c')]; $f = true; } unset($p);
        if (!$f) $L[] = $row + ['img' => (string) $img, 'at' => date('c')]; $C[$aid] = array_values($L); return $C; });
    wi_log('catalog_save', ['id' => $id]); wi_json(['ok' => true, 'id' => $id]);
}
/* ---------- Market: what sets this business apart, and its competitors — the bot answers comparisons and hesitation with it ---------- */
function wf_market(string $aid): array {
    $m = (array) (wi_read('market.json', [])[$aid] ?? []);
    return ['edge' => (string) ($m['edge'] ?? ''), 'offer' => (string) ($m['offer'] ?? ''), 'rivals' => array_values(array_filter((array) ($m['rivals'] ?? []), 'is_array'))];
}
/** The market as a block for the bot's prompt ('' when nothing is filled in). */
function wf_market_prompt(string $aid): string {
    $m = wf_market($aid); $o = '';
    if (trim($m['edge']) !== '') $o .= "WHAT SETS US APART:\n" . mb_substr(trim($m['edge']), 0, 1500) . "\n";
    if (trim($m['offer']) !== '') $o .= "WHAT WE CAN OFFER A HESITANT CUSTOMER (only this, nothing more):\n" . mb_substr(trim($m['offer']), 0, 600) . "\n";
    $r = array_filter($m['rivals'], fn($x) => !empty($x['on']) && trim((string) ($x['name'] ?? '')) !== '');
    if ($r) { $o .= "COMPETITORS (for your judgement only):\n"; foreach (array_slice($r, 0, 12) as $x) $o .= '- ' . $x['name'] . ((string) ($x['prices'] ?? '') !== '' ? ' — prices: ' . mb_substr((string) $x['prices'], 0, 200) : '') . ((string) ($x['weak'] ?? '') !== '' ? ' — where we do better: ' . mb_substr((string) $x['weak'], 0, 300) : '') . "\n"; }
    return $o === '' ? '' : $o . "When the customer compares us, names a competitor, says it is cheaper elsewhere or hesitates: answer politely with what sets us apart and, if listed, the offer. Never speak badly of a competitor by name, never claim anything not written above, never invent a discount.\n";
}
function wf_api_market(string $api): void {
    if ($api !== 'market') return;
    $acc = wi_current(); if (!$acc) wi_json(['ok' => false, 'error' => 'No number chosen.'], 422); $aid = (string) $acc['id'];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') { if (wi_page_perm('catalog') !== 'edit') wi_json(['ok' => false, 'error' => 'View only.'], 403); $in = wf_post();
        $rv = []; foreach (array_slice((array) ($in['rivals'] ?? []), 0, 30) as $x) { $n = mb_substr(trim((string) ($x['name'] ?? '')), 0, 80); if ($n === '') continue;
            $rv[] = ['name' => $n, 'prices' => mb_substr(trim((string) ($x['prices'] ?? '')), 0, 300), 'weak' => mb_substr(trim((string) ($x['weak'] ?? '')), 0, 500), 'on' => !empty($x['on']) ? 1 : 0]; }
        $m = ['edge' => mb_substr(trim((string) ($in['edge'] ?? '')), 0, 2000), 'offer' => mb_substr(trim((string) ($in['offer'] ?? '')), 0, 800), 'rivals' => $rv];
        wi_update('market.json', function ($M) use ($aid, $m) { $M[$aid] = $m; return $M; }); wi_log('market_save', ['rivals' => count($rv)]); wi_json(['ok' => true]); }
    wi_json(['ok' => true] + wf_market($aid));
}
/** AI market analysis from the business's own data: its catalog, what sets it apart, its competitors and what customers wrote lately
 * (price objections, competitors they named, why they hesitated). Saved with the market; also run once a week by itself. */
function wf_market_analyze(array $acc, string $focus = ''): array {
    $aid = (string) $acc['id']; wi_ctx_set('ai_acc', $acc); $m = wf_market($aid); $pack = function_exists('wf_pack') ? wf_pack($aid) : [];
    $cat = ''; foreach (array_slice(wf_catalog($aid, true), 0, 40) as $p) $cat .= '- ' . $p['name'] . (($pr = wf_catalog_price($p)) !== '' ? ' — ' . $pr : '') . "\n";
    $msgs = []; $since = time() - 30 * 86400;
    foreach (array_slice((array) (wi_gateway('/archive/chats?months=1', null, $acc)['chats'] ?? []), 0, 120) as $ch) { if (!empty($ch['group']) || strtotime((string) ($ch['last']['time'] ?? '')) < $since) continue;
        foreach ((array) (wi_gateway('/archive/thread?limit=30&jid=' . rawurlencode((string) $ch['jid']), null, $acc)['rows'] ?? []) as $r) { $t = trim((string) ($r['text'] ?? ''));
            if (($r['dir'] ?? '') === 'in' && mb_strlen($t) >= 12 && strtotime((string) ($r['time'] ?? '')) >= $since) $msgs[] = mb_substr(preg_replace('/\s+/u', ' ', $t), 0, 220); }
        if (count($msgs) >= 260) break; }
    $prompt = "You analyse the market of a business that sells and supports customers on WhatsApp. Write in the customers' Arabic dialect, short and practical.\n"
        . 'BUSINESS: ' . (string) ($pack['label'] ?? ($pack['preset'] ?? '')) . ' ' . mb_substr((string) ($pack['business'] ?? ($pack['about'] ?? '')), 0, 600) . "\n"
        . "OUR PRODUCTS AND PRICES:\n" . ($cat ?: "(none)\n") . "WHAT SETS US APART (owner's words):\n" . ($m['edge'] ?: '(empty)') . "\nOUR OFFER FOR THE HESITANT:\n" . ($m['offer'] ?: '(empty)') . "\nCOMPETITORS (owner's list):\n"
        . (implode("\n", array_map(fn($x) => '- ' . $x['name'] . ' | prices: ' . ($x['prices'] ?? '') . ' | we do better: ' . ($x['weak'] ?? ''), $m['rivals'])) ?: '(none)') . "\n"
        . "WHAT CUSTOMERS WROTE IN THE LAST 30 DAYS (one per line):\n" . (implode("\n", array_slice($msgs, 0, 260)) ?: '(none)') . "\n"
        . ($focus !== '' ? "THE OWNER WANTS THIS LOOKED AT IN PARTICULAR: " . mb_substr($focus, 0, 400) . "\n" : '')
        . "Use only what is written above; never invent competitors, prices or numbers. Return JSON: {\"summary\":\"3-4 lines\",\"objections\":[{\"what\":\"…\",\"count\":n,\"answer\":\"how the bot should answer it\"}],"
        . "\"competitors_seen\":[{\"name\":\"…\",\"mentions\":n,\"what\":\"what customers said\"}],\"price_notes\":\"…\",\"edge_suggestion\":\"a better 'what sets us apart' text\",\"offer_suggestion\":\"an offer idea, or empty\",\"actions\":[\"…\"]}";
    $ai = wi_ai('Market analysis', $prompt, 150, ['subject' => 'market']);
    if (empty($ai['ok']) || !is_array($ai['data'] ?? null)) return ['ok' => false, 'error' => (string) ($ai['error'] ?? 'AI failed.')];
    $a = $ai['data'] + ['at' => date('c'), 'messages' => count($msgs), 'focus' => mb_substr($focus, 0, 400)];
    wi_update('market.json', function ($M) use ($aid, $a) { $M[$aid]['analysis'] = $a; return $M; });
    return ['ok' => true, 'analysis' => $a];
}
function wf_api_market_ai(string $api): void {
    if ($api !== 'market_ai') return;
    $acc = wi_current(); if (!$acc) wi_json(['ok' => false, 'error' => 'No number chosen.'], 422);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') wi_json(['ok' => true, 'analysis' => (wi_read('market.json', [])[(string) $acc['id']]['analysis'] ?? null)]);
    if (wi_page_perm('catalog') !== 'edit') wi_json(['ok' => false, 'error' => 'View only.'], 403); @set_time_limit(240);
    wi_json(wf_market_analyze($acc, (string) (wf_post()['focus'] ?? '')));
}
