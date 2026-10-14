<?php
// Builds app.js and features.js from their source parts in src/app/*.js and src/features/*.js (joined in file-name order, as they are,
// so each file keeps one shared scope). After editing a part:  /usr/local/bin/php wa-inbox/build-js.php   (add --check to only compare)
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$check = in_array('--check', $argv, true); $bad = 0;
foreach (['app', 'features'] as $name) {
    $parts = glob(__DIR__ . '/src/' . $name . '/*.js'); sort($parts); if (!$parts) continue;
    $js = ''; foreach ($parts as $p) $js .= file_get_contents($p); $js = rtrim($js, "\n") . "\n"; $f = __DIR__ . '/' . $name . '.js';
    if ($check) { $same = $js === file_get_contents($f); $bad += $same ? 0 : 1; echo $name . '.js ' . ($same ? "is up to date\n" : "differs from src/$name — run the build\n"); }
    else { file_put_contents($f, $js); echo "$name.js built from " . count($parts) . " parts\n"; }
}
exit($bad ? 1 : 0);
