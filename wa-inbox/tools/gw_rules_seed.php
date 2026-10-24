<?php
// One step of the deploy that moved the gateway's word lists out of its code (CLI): every rules file the gateways read gets the
// lists it does not have yet (login-code patterns, payment commands, money words, question words, reference labels, words left out of counts).
// php tools/gw_rules_seed.php           → shows what would be added
// php tools/gw_rules_seed.php --apply   → adds it (nothing already in a file is changed); run it before the gateways restart
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/lib.php'; require dirname(__DIR__) . '/features.php';
$apply = in_array('--apply', $argv, true); $r = wf_gw_rules_seed($apply);
foreach ($r as $f => $keys) echo basename(dirname($f)) . '/' . basename($f), ': ', implode(', ', $keys), "\n";
echo $r ? ($apply ? "added\n" : "dry run — add --apply to write\n") : "every rules file has the lists\n";
