<?php
// One-time check (CLI): cases now belong to their own number — the old shared "cases number" setting (config cases_account) is no longer read.
// Prints, per number, how many cases it holds, and the users who opened the Cases page of another number through that setting.
// php tools/cases_per_number.php           → report only (nothing changes)
// php tools/cases_per_number.php --apply   → also removes the old setting from config.json (a copy is kept first)
// No case is moved: each case stays on the number it is stored under, so the cases of the old shared number keep working there.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/lib.php'; require dirname(__DIR__) . '/features.php';
$apply = in_array('--apply', $argv, true); $cfg = wi_read('config.json', []); $old = (string) ($cfg['cases_account'] ?? '');
echo 'old shared cases number: ', $old !== '' ? $old : '(none)', "\n";
foreach (wi_accounts() as $aid => $a) { $n = count(wf_cases((string) $aid)); if ($n) echo '  ', $aid, ': ', $n, " cases\n"; }
if ($old !== '' && ($ca = wi_accounts()[$old] ?? null)) {
    // users with the Cases page who may see that number but are not on it: they saw its cases through the old setting, now they see their own number's
    foreach (wi_users() as $u) { if (empty($u['active']) || ($u['role'] ?? '') === 'super') continue;
        $mine = (array) ($u['accounts'] ?? []); $pc = (string) ($u['pages']['cases'] ?? '');
        if ($pc !== '' && !in_array($old, $mine, true) && strtolower((string) ($ca['owner'] ?? '')) !== strtolower((string) $u['email']) && wi_may_see_account($ca, $u)) echo '  note: ', $u['email'], " had the Cases page through the shared number; give them that number in Users if they still follow its cases\n"; }
}
if (!$apply) exit("report only — add --apply to remove the old setting\n");
if ($old === '') exit("nothing to change\n");
$bk = WI_DIR . '/backup_cases_' . date('Ymd_His'); @mkdir($bk, 0700, true); file_put_contents($bk . '/config.json', json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
unset($cfg['cases_account']); wi_write('config.json', $cfg); wi_log('cases_per_number', ['old' => $old]);
echo "removed; copy in $bk\n";
