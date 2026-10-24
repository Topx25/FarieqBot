<?php
// One-time step (CLI): every number gets its business type stored, so it no longer depends on a guess.
// A number without a stored type used to take it from its case settings: that type is now written in its profile.
// (A type whose case_preset matches the number's case settings, e.g. the one an add-on brings; otherwise the starting type.)
// php tools/pack_type_store.php           → report only
// php tools/pack_type_store.php --apply   → saves it (a copy of bot.json is kept first)
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/lib.php'; require dirname(__DIR__) . '/features.php';
$apply = in_array('--apply', $argv, true); $bot = wi_read('bot.json', []); $cc = wi_read('case_cfg.json', []); $P = wf_pack_presets(); $n = 0;
foreach (wi_accounts() as $aid => $a) {
    if (isset($bot[$aid]['pack']['preset'])) continue;
    $case = (string) ($cc[$aid]['preset'] ?? ''); $type = '';
    foreach ($P as $k => $p) if ($case !== '' && (string) ($p['case_preset'] ?? '') === $case) { $type = (string) $k; break; }
    if ($type === '') $type = wf_pack_default((string) $aid);
    echo $aid, ': no stored type (case settings: ', $case ?: '-', ') → ', $type, "\n"; $bot[$aid]['pack']['preset'] = $type; $n++;
}
if (!$n) exit("every number has its type stored\n");
if (!$apply) exit("report only — add --apply to save\n");
$bk = WI_DIR . '/backup_types_' . date('Ymd_His'); @mkdir($bk, 0700, true); file_put_contents($bk . '/bot.json', json_encode(wi_read('bot.json', []), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
wi_update('bot.json', function ($B) use ($bot) { foreach ($bot as $aid => $x) if (isset($x['pack']['preset']) && !isset($B[$aid]['pack']['preset'])) $B[$aid]['pack']['preset'] = $x['pack']['preset']; return $B; });
wi_log('pack_type_store', ['count' => $n]); echo "saved; copy in $bk\n";
