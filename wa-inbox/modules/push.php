<?php
// WhatsApp Inbox — the installable app (PWA) and its phone notifications (Web Push).
// Standard Web Push with VAPID keys made once by this server and payloads encrypted as RFC 8291 (aes128gcm): no outside library.
// Subscriptions are kept per user (push_subs.json); a dead one (404 / 410) is removed by the next send.

/** This server's VAPID keys: made on first use and kept with the other stored data. ['pem' => private key, 'pub' => public key, base64url]. */
function wi_push_keys(): array {
    $k = (array) wi_read('push_vapid.json', []);
    if (!empty($k['pem']) && !empty($k['pub'])) return $k;
    $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    openssl_pkey_export($key, $pem); $d = openssl_pkey_get_details($key);
    $k = ['pem' => $pem, 'pub' => wi_b64u("\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT)), 'at' => date('c')];
    wi_write('push_vapid.json', $k);
    return $k;
}
function wi_b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function wi_b64u_dec(string $s): string { return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); }

/** A DER ECDSA signature as the 64 raw bytes a JWT needs. */
function wi_push_der2raw(string $der): string {
    $o = 3; $lr = ord($der[$o]); $r = substr($der, $o + 1, $lr); $o += 1 + $lr + 1; $ls = ord($der[$o]); $s = substr($der, $o + 1, $ls);
    return str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT) . str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);
}
/** A raw P-256 public point (65 bytes) as a key PHP can use. */
function wi_push_pubkey(string $raw) {
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw;
    return openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n");
}

/** Sends one notification to one subscription. Returns the HTTP status (201 = delivered to the push service; 404 / 410 = gone). */
function wi_push_send_one(array $sub, array $data): int {
    $ep = (string) ($sub['endpoint'] ?? ''); $p256 = wi_b64u_dec((string) ($sub['keys']['p256dh'] ?? '')); $auth = wi_b64u_dec((string) ($sub['keys']['auth'] ?? ''));
    if (!preg_match('#^https://#', $ep) || strlen($p256) !== 65 || strlen($auth) !== 16) return 0;
    $V = wi_push_keys(); $u = parse_url($ep);
    // the VAPID token: who sends (this site) and for which push service
    $h = wi_b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256'])); $c = wi_b64u(json_encode(['aud' => $u['scheme'] . '://' . $u['host'], 'exp' => time() + 12 * 3600, 'sub' => 'mailto:' . ((string) wi_sys('support_email') ?: 'support@fariek.com')]));
    openssl_sign($h . '.' . $c, $sig, $V['pem'], OPENSSL_ALGO_SHA256); $jwt = $h . '.' . $c . '.' . wi_b64u(wi_push_der2raw($sig));
    // the payload, encrypted for this browser only (RFC 8291)
    $eph = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]); $ed = openssl_pkey_get_details($eph);
    $ephPub = "\x04" . str_pad($ed['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ed['ec']['y'], 32, "\0", STR_PAD_LEFT);
    $secret = openssl_pkey_derive(wi_push_pubkey($p256), $eph, 32); if (!$secret) return 0;
    $ikm = hash_hkdf('sha256', $secret, 32, "WebPush: info\0" . $p256 . $ephPub, $auth);
    $salt = random_bytes(16);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt); $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
    $plain = json_encode($data, JSON_UNESCAPED_UNICODE) . "\x02";
    $enc = openssl_encrypt($plain, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    $body = $salt . pack('N', 4096) . chr(65) . $ephPub . $enc . $tag;
    $ch = curl_init($ep);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Content-Type: application/octet-stream', 'Content-Encoding: aes128gcm', 'TTL: 86400', 'Urgency: high', 'Authorization: vapid t=' . $jwt . ', k=' . $V['pub']]]);
    curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return $code;
}

/** A notification to every phone / browser of one user. data: title, body, url, tag. */
function wi_push_user(string $email, array $data): int {
    $email = strtolower($email); $subs = (array) (wi_read('push_subs.json', [])[$email] ?? []); if (!$subs) return 0;
    $ok = 0; $gone = [];
    foreach ($subs as $id => $s) { $code = wi_push_send_one((array) $s, $data + ['url' => './']); if ($code >= 200 && $code < 300) $ok++; elseif (in_array($code, [404, 410], true)) $gone[] = $id; }
    if ($gone) wi_update('push_subs.json', function ($P) use ($email, $gone) { foreach ($gone as $g) unset($P[$email][$g]); return $P; });
    return $ok;
}
/** A notification to the people of one number: its owner (or the admins), and the team users who see that number. */
function wi_push_account(array $acc, array $data, array $only = []): int {
    $aid = (string) $acc['id']; $to = [];
    foreach (wi_account_watchers($acc) as $u) $to[strtolower((string) $u['email'])] = 1;
    foreach (wi_users() as $u) if (!empty($u['active']) && in_array($aid, (array) ($u['accounts'] ?? []), true)) $to[strtolower((string) $u['email'])] = 1;
    if ($only) $to = array_intersect_key($to, array_flip(array_map('strtolower', $only)));
    $n = 0; foreach (array_keys($to) as $e) $n += wi_push_user($e, $data);
    return $n;
}

/** ?api=push_key | push_sub | push_unsub | push_test: the browser asks for the key, saves its subscription, removes it, or tries one. */
function wf_api_push(string $api): void {
    if (!in_array($api, ['push_key', 'push_sub', 'push_unsub', 'push_test'], true)) return;
    $me = strtolower((string) ($_SESSION['email'] ?? '')); if ($me === '') wi_json(['ok' => false, 'error' => 'Sign in first.'], 401);
    if ($api === 'push_key') wi_json(['ok' => true, 'key' => wi_push_keys()['pub'], 'count' => count((array) (wi_read('push_subs.json', [])[$me] ?? []))]);
    $in = wf_post();
    if ($api === 'push_sub') {
        $s = (array) ($in['sub'] ?? []); $ep = (string) ($s['endpoint'] ?? '');
        if (!preg_match('#^https://#', $ep) || empty($s['keys']['p256dh']) || empty($s['keys']['auth'])) wi_json(['ok' => false, 'error' => 'Not a valid subscription.']);
        $id = substr(md5($ep), 0, 16); $row = ['endpoint' => $ep, 'keys' => ['p256dh' => (string) $s['keys']['p256dh'], 'auth' => (string) $s['keys']['auth']], 'ua' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 160), 'at' => date('c')];
        wi_update('push_subs.json', function ($P) use ($me, $id, $row) { $P[$me][$id] = $row; return $P; });
        wi_log('push_on', []); wi_json(['ok' => true]);
    }
    if ($api === 'push_unsub') { $id = substr(md5((string) ($in['endpoint'] ?? '')), 0, 16); wi_update('push_subs.json', function ($P) use ($me, $id) { unset($P[$me][$id]); return $P; }); wi_json(['ok' => true]); }
    $n = wi_push_user($me, ['title' => wi_t('Fariek'), 'body' => wi_t('Notifications are on. This is how new chats and alerts will reach you.'), 'url' => './?p=inbox', 'tag' => 'test']);
    wi_json(['ok' => $n > 0, 'sent' => $n, 'error' => $n ? '' : 'Nothing reached this device. Turn the notifications on again.']);
}
wi_hook_add('api_handlers', fn(array $H) => array_merge($H, ['wf_api_push']));

/* ---------- The installable app: manifest, icons and the service worker (public, no session) ---------- */
/** ?pwa=manifest | sw | icon (s=192|512, m=1 for the maskable one). */
function wf_pwa_serve(string $what): void {
    if ($what === 'manifest') {
        header('Content-Type: application/manifest+json; charset=utf-8'); header('Cache-Control: public, max-age=3600');
        $name = (string) wi_brand('brand_name');
        echo json_encode(['name' => $name, 'short_name' => mb_substr($name, 0, 12), 'id' => './', 'start_url' => './?p=inbox&pwa=1', 'scope' => './', 'display' => 'standalone', 'orientation' => 'any',
            'background_color' => '#ffffff', 'theme_color' => '#0866ff', 'dir' => 'auto', 'lang' => 'ar',
            'icons' => [['src' => 'pwa-192.png', 'sizes' => '192x192', 'type' => 'image/png'], ['src' => 'pwa-512.png', 'sizes' => '512x512', 'type' => 'image/png'], ['src' => 'pwa-512m.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable']],
            'shortcuts' => [['name' => wi_t('Conversations'), 'url' => './?p=inbox'], ['name' => wi_t('Groups'), 'url' => './?p=groups']]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    if ($what === 'sw') {
        header('Content-Type: text/javascript; charset=utf-8'); header('Cache-Control: no-cache'); header('Service-Worker-Allowed: ./');
        readfile(__DIR__ . '/lib/sw.js'); exit;
    }
    if ($what === 'icon') {
        $s = (int) ($_GET['s'] ?? 192) === 512 ? 512 : 192; $mask = !empty($_GET['m']);
        $cf = WI_DIR . '/pwa_icon_' . $s . ($mask ? 'm' : '') . '.png';
        if (!is_file($cf)) {
            // a white chat bubble on the blue of the product (the maskable one keeps the bubble inside the safe circle)
            $im = imagecreatetruecolor($s, $s); imagesavealpha($im, true); imagealphablending($im, true);
            $blue = imagecolorallocate($im, 8, 102, 255); $white = imagecolorallocate($im, 255, 255, 255); $clear = imagecolorallocatealpha($im, 0, 0, 0, 127);
            imagefill($im, 0, 0, $mask ? $blue : $clear);
            if (!$mask) imagefilledellipse($im, (int) ($s / 2), (int) ($s / 2), $s - 2, $s - 2, $blue);
            $k = $mask ? 0.36 : 0.46; $w = (int) ($s * $k * 1.25); $h = (int) ($s * $k);
            imagefilledellipse($im, (int) ($s / 2), (int) ($s * 0.47), $w, $h, $white);
            imagefilledpolygon($im, [(int) ($s * 0.36), (int) ($s * 0.6), (int) ($s * 0.31), (int) ($s * ($mask ? 0.7 : 0.75)), (int) ($s * 0.46), (int) ($s * 0.64)], $white);
            foreach ([0.38, 0.5, 0.62] as $x) imagefilledellipse($im, (int) ($s * $x), (int) ($s * 0.47), (int) ($s * 0.07), (int) ($s * 0.07), $blue);
            imagepng($im, $cf); imagedestroy($im);
        }
        header('Content-Type: image/png'); header('Cache-Control: public, max-age=604800'); readfile($cf); exit;
    }
    http_response_code(404); exit;
}
