<?php
// Duplicate passages in a number's lessons, by match percentage (Learning settings dup_pct / sim_pct, or --dup= / --sim=).
// Usage: php tools/kb_dedupe.php <account id> [--apply] [--dup=90] [--sim=75]
// Every passage gets its fingerprint (kept: it is not content). The older passage is the original; a later one that matches it at dup% or more
// is a repeat, and between sim% and dup% it is similar. Without --apply: a report only (counts per file, a sample). With --apply: repeats are
// taken out of the answers (dropped = 1, verdict "duplicate", with their original), never deleted; similar ones are marked for review.
chdir(dirname(__DIR__)); require 'lib.php'; require 'features.php';
$aid = (string) ($argv[1] ?? ''); if ($aid === '') { fwrite(STDERR, "account id?\n"); exit(1); }
$apply = in_array('--apply', $argv, true); $cfg = wf_learn_cfg($aid); $dPct = (int) $cfg['dup_pct']; $sPct = (int) $cfg['sim_pct'];
foreach ($argv as $a) { if (str_starts_with($a, '--dup=')) $dPct = (int) substr($a, 6); if (str_starts_with($a, '--sim=')) $sPct = (int) substr($a, 6); }
$m = wi_db(); if (!$m || !wf_kb_dedupe_db()) { fwrite(STDERR, "no database\n"); exit(1); }
$e = $m->real_escape_string($aid);
// fingerprints for the passages that have none yet
$q = $m->query("SELECT id, text FROM wi_learn_chunks WHERE base='$e' AND mh IS NULL"); $n = 0;
while ($q && ($x = $q->fetch_assoc())) { $m->query("UPDATE wi_learn_chunks SET mh='" . wf_kb_minhash((string) $x['text']) . "' WHERE id=" . (int) $x['id']); $n++; }
echo "fingerprints added: $n\n";
$rows = []; $q = $m->query("SELECT k.id, k.doc_id, k.idx, k.mh, k.dropped, k.verdict, d.name FROM wi_learn_chunks k JOIN wi_learn_docs d ON d.id = k.doc_id WHERE k.base='$e' AND d.status='active' ORDER BY k.doc_id, k.idx");
while ($q && ($x = $q->fetch_assoc())) $rows[] = $x;
$bands = []; $kept = []; $dups = []; $sims = []; $perDoc = [];
foreach ($rows as $r) { $id = (int) $r['id']; $doc = (string) $r['name']; $perDoc[$doc] ??= ['all' => 0, 'dup' => 0, 'sim' => 0, 'from' => []]; $perDoc[$doc]['all']++;
    if ((int) $r['dropped'] === 1 && $r['verdict'] !== 'duplicate') continue; // already taken out for another reason
    $best = 0; $bid = 0; $seen = [];
    foreach (wf_kb_mh_bands($r['mh']) as $bk) foreach ($bands[$bk] ?? [] as $oid) { if (isset($seen[$oid])) continue; $seen[$oid] = 1; $p = wf_kb_mh_pct($r['mh'], $kept[$oid]['mh']); if ($p > $best) { $best = $p; $bid = $oid; } }
    if ($best >= $dPct) { $dups[] = [$id, $bid, $best]; $perDoc[$doc]['dup']++; $src = (string) $kept[$bid]['name']; $perDoc[$doc]['from'][$src] = ($perDoc[$doc]['from'][$src] ?? 0) + 1; continue; }
    if ($best >= $sPct) { $sims[] = [$id, $bid, $best]; $perDoc[$doc]['sim']++; }
    $kept[$id] = $r; foreach (wf_kb_mh_bands($r['mh']) as $bk) $bands[$bk][] = $id; }
$all = count($rows); echo "\npassages: $all · repeats (≥{$dPct}%): " . count($dups) . ' (' . ($all ? round(count($dups) * 100 / $all) : 0) . "%) · similar ({$sPct}–" . ($dPct - 1) . "%): " . count($sims) . "\n\n";
foreach ($perDoc as $doc => $d) if ($d['dup'] || $d['sim']) { arsort($d['from']); echo '- ', mb_substr($doc, 0, 70), ': ', $d['dup'], '/', $d['all'], ' repeats (', round($d['dup'] * 100 / max(1, $d['all'])), '%)', $d['from'] ? ' mostly from «' . mb_substr((string) array_key_first($d['from']), 0, 50) . '»' : '', ' · similar ', $d['sim'], "\n"; }
$txt = function (int $id) use ($m) { $r = $m->query("SELECT text FROM wi_learn_chunks WHERE id=$id")->fetch_assoc(); return mb_substr(preg_replace('/\s+/u', ' ', (string) ($r['text'] ?? '')), 0, 110); };
echo "\nsample repeats:\n"; foreach (array_slice($dups, 0, 3) as [$a, $b, $p]) echo "  $p%  #$a «" . $txt($a) . "»\n        = #$b «" . $txt($b) . "»\n";
echo "sample similar:\n"; foreach (array_slice($sims, 0, 2) as [$a, $b, $p]) echo "  $p%  #$a «" . $txt($a) . "»\n        ~ #$b «" . $txt($b) . "»\n";
if (!$apply) { echo "\n(dry run: nothing changed. Run with --apply to take the repeats out of the answers.)\n"; exit; }
foreach ($dups as [$a, $b, $p]) $m->query("UPDATE wi_learn_chunks SET dropped=1, verdict='duplicate', verdict_why='" . $m->real_escape_string($p . '% = #' . $b) . "' WHERE id=$a");
foreach ($sims as [$a, $b, $p]) $m->query("UPDATE wi_learn_chunks SET verdict='similar', verdict_why='" . $m->real_escape_string($p . '% ~ #' . $b) . "' WHERE id=$a AND (verdict IS NULL OR verdict='')");
wi_log('kb_dedupe', ['account' => $aid, 'repeats' => count($dups), 'similar' => count($sims), 'dup_pct' => $dPct, 'sim_pct' => $sPct]);
echo "applied: " . count($dups) . " repeats taken out of the answers, " . count($sims) . " marked similar\n";
