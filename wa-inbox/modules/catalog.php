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
