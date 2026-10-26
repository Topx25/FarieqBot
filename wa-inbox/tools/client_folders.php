<?php
// Moves the files of every client's number into clients/<client>/<number>/{media,whatsapp} (outside the site) and links the usual paths to it.
//   php tools/client_folders.php            dry run: lists what would move
//   php tools/client_folders.php --apply    moves
declare(strict_types=1);
chdir(dirname(__DIR__)); require 'lib.php'; require 'features.php';
if (PHP_SAPI !== 'cli') exit;
$apply = in_array('--apply', $argv, true); $root = dirname(__DIR__, 2);
foreach (wi_accounts() as $aid => $a) {
    $owner = function_exists('wi_private_owner') ? wi_private_owner($a) : strtolower((string) ($a['owner'] ?? ''));
    if ($aid === 'main' || $owner === '') continue;
    $paths = ['media' => $root . '/storage/inbox_media/' . $aid, 'whatsapp' => $root . '/wa-local/accounts/' . $aid];
    $st = array_map(fn($p) => is_link($p) ? 'linked' : (is_dir($p) ? 'folder' : 'none'), $paths);
    echo $aid, ' (', $owner, '): media ', $st['media'], ', whatsapp ', $st['whatsapp'];
    if (!in_array('folder', $st, true) && !in_array('none', $st, true)) { echo " — already in its own folder\n"; continue; }
    if (!$apply) { echo " — would move\n"; continue; }
    $d = wi_client_folder((string) $aid, $owner, true); echo ' → ', $d ? substr($d, strlen($root) + 1) : 'failed', "\n";
}
if (!$apply) echo "Dry run: nothing moved. Add --apply.\n";
