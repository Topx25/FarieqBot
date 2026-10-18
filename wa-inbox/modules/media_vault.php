<?php
/* ---------- Private folder of the media library: the files are encrypted on disk (Topx1.Host) ----------
 * AES-256-GCM, one random key per number kept encrypted in the secrets table (db.php). A hidden file is encrypted, its plain copy is deleted
 * and the encrypted copy lives in storage/vault/<number>/. It is decrypted only while the folder is open (password or WhatsApp code).
 * The index of the encrypted files is media_vault_files.json (this module is its only owner). */

const WF_VAULT_MAX = 134217728; // 128 MB per file: the file is encrypted in one piece
function wf_vault_dir(string $aid): string { $d = dirname(__DIR__, 2) . '/storage/vault/' . preg_replace('/[^a-z0-9_-]/', '', $aid); if (!is_dir($d)) { @mkdir($d, 0755, true); @file_put_contents(dirname($d) . '/.htaccess', "Require all denied\n"); } return $d; }
function wf_vault_files(string $aid): array { return (array) (wi_read('media_vault_files.json', [])[$aid] ?? []); }
function wf_vault_dek(string $aid): string {
    static $keys = []; if (isset($keys[$aid])) return $keys[$aid]; // wi_secret() remembers an empty answer for the whole request, so the key is kept here once made
    $n = 'media_vault_dek:' . $aid; $k = wi_secret($n); if ($k !== '' && strlen((string) base64_decode($k)) === 32) return $keys[$aid] = (string) base64_decode($k);
    $raw = random_bytes(32); if (!wi_secret_set($n, base64_encode($raw))) throw new RuntimeException('The encryption key could not be saved.'); return $keys[$aid] = $raw;
}
function wf_vault_seal(string $aid, string $key, string $plain): string { $iv = random_bytes(12); $tag = ''; $ct = openssl_encrypt($plain, 'aes-256-gcm', wf_vault_dek($aid), OPENSSL_RAW_DATA, $iv, $tag, $aid . '|' . $key); return $ct === false ? '' : $iv . $tag . $ct; }
function wf_vault_open(string $aid, string $key, string $blob): ?string {
    if (strlen($blob) < 29) return null; $p = openssl_decrypt(substr($blob, 28), 'aes-256-gcm', wf_vault_dek($aid), OPENSSL_RAW_DATA, substr($blob, 0, 12), substr($blob, 12, 16), $aid . '|' . $key); return $p === false ? null : $p;
}
/** Hides one file: encrypted into the private folder, the plain copy deleted. Returns '' or an error text. */
function wf_vault_hide(string $aid, string $key): string {
    $src = dirname(__DIR__, 2) . '/storage/' . $key; if (!is_file($src)) return 'File not found.';
    if (filesize($src) > WF_VAULT_MAX) return 'The file is too large for the private folder (128 MB).';
    $blob = wf_vault_seal($aid, $key, (string) file_get_contents($src)); if ($blob === '') return 'Encryption failed.';
    $id = bin2hex(random_bytes(10)) . '.enc'; if (file_put_contents(wf_vault_dir($aid) . '/' . $id, $blob) === false) return 'Could not store the encrypted file.'; @chmod(wf_vault_dir($aid) . '/' . $id, 0600);
    $meta = ['enc' => $id, 'size' => (int) filesize($src), 'at' => date('c', (int) filemtime($src))];
    wi_update('media_vault_files.json', function ($A) use ($aid, $key, $meta) { $A[$aid][$key] = $meta; return $A; });
    @unlink($src); return '';
}
/** Takes one file out: decrypted back to where it was. Returns '' or an error text. */
function wf_vault_show(string $aid, string $key): string {
    $m = wf_vault_files($aid)[$key] ?? null; if (!$m) return 'File not found.';
    $blob = (string) @file_get_contents(wf_vault_dir($aid) . '/' . $m['enc']); $plain = wf_vault_open($aid, $key, $blob); if ($plain === null) return 'The file could not be decrypted.';
    $dst = dirname(__DIR__, 2) . '/storage/' . $key; if (!is_dir(dirname($dst))) @mkdir(dirname($dst), 0755, true);
    if (file_put_contents($dst, $plain) === false) return 'Could not restore the file.'; @chmod($dst, 0644);
    @unlink(wf_vault_dir($aid) . '/' . $m['enc']); wi_update('media_vault_files.json', function ($A) use ($aid, $key) { unset($A[$aid][$key]); return $A; }); return '';
}
/** Files hidden with the first version of the private folder (only flagged, still plain on disk): encrypted now. */
function wf_vault_migrate(string $aid): int {
    $n = 0; $have = wf_vault_files($aid);
    foreach ((array) (wi_read('media_meta.json', [])[$aid] ?? []) as $key => $m) if (!empty($m['vault']) && !isset($have[$key]) && is_file(dirname(__DIR__, 2) . '/storage/' . $key)) { if (wf_vault_hide($aid, (string) $key) === '') $n++; }
    return $n;
}
