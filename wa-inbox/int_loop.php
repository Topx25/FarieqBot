<?php
// Integrations: new private messages go to each system's webhook every 3 seconds (started each minute by cron_features.php;
// one copy at a time, it ends itself before the next minute starts a new one).
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
chdir(__DIR__); require 'lib.php'; require 'features.php';
$lk = fopen(WI_DIR . '/int_loop.lock', 'c'); if (!$lk || !flock($lk, LOCK_EX | LOCK_NB)) exit;
$end = time() + 57;
while (time() < $end) { try { wf_int_tick(); } catch (Throwable $e) { wi_catch($e, 'int loop'); } sleep(3); }
