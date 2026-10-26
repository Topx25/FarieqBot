<?php
// Imports questions and answers (another system's knowledge base) into a number's knowledge base, approved.
//   php tools/import_kb.php <aid> <kb.json> [--apply] [--replace]
// kb.json: [{"q": "…", "a": "…", "topic": "…"}, …]   (also accepted: question/answer, title/content)
// --replace first removes the number's current items (kept in a copy, learn_kb_before_import_<aid>.json).
declare(strict_types=1);
chdir(dirname(__DIR__)); require 'lib.php'; require 'features.php';
if (PHP_SAPI !== 'cli') exit;
$aid = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) ($argv[1] ?? ''))); $in = json_decode((string) @file_get_contents((string) ($argv[2] ?? '')), true);
if ($aid === '' || !isset(wi_accounts()[$aid]) || !is_array($in)) exit("Usage: php tools/import_kb.php <aid> <kb.json> [--apply] [--replace]\n");
$apply = in_array('--apply', $argv, true); $replace = in_array('--replace', $argv, true);
// a source label (manual, site…) is not a topic: the question names it instead
$items = []; foreach (array_values($in) as $r) { if (!is_array($r)) continue;
    $q = trim((string) ($r['q'] ?? $r['question'] ?? $r['title'] ?? '')); $a = trim((string) ($r['a'] ?? $r['answer'] ?? $r['content'] ?? ''));
    if ($q === '' || $a === '') continue; $id = substr(md5($aid . '|' . mb_strtolower($q)), 0, 12);
    $items[$id] = ['id' => $id, 'topic' => mb_substr(($tp = trim((string) ($r['topic'] ?? $r['category'] ?? ''))) !== '' && !in_array(strtolower($tp), ['manual', 'official', 'site', 'auto', 'faq'], true) ? $tp : mb_substr($q, 0, 60), 0, 80), 'q' => mb_substr($q, 0, 500), 'a' => mb_substr($a, 0, 4000),
        'type' => 'import', 'sources' => 1, 'status' => 'approved', 'at' => date('c'), 'by' => 'import']; }
$cur = (array) (wi_read('learn_kb.json', [])[$aid] ?? []);
echo "Number $aid: ", count($cur), " items now, ", count($items), " to import", $replace ? ' (replacing all current items)' : ' (same question = updated)', "\n";
foreach (array_slice($items, 0, 3) as $x) echo '  · ', mb_substr($x['q'], 0, 70), "\n";
if (!$apply) exit("Dry run: nothing written. Add --apply.\n");
if ($replace && $cur) { wi_write('learn_kb_before_import_' . $aid . '.json', $cur); }
wi_update('learn_kb.json', function ($K) use ($aid, $items, $replace) { $K[$aid] = ($replace ? [] : (array) ($K[$aid] ?? [])); foreach ($items as $id => $x) $K[$aid][$id] = $x; return $K; });
wi_log('kb_import', ['account' => $aid, 'items' => count($items), 'replace' => $replace]);
echo "Imported.\n";
