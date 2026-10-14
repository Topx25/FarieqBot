<?php
// WhatsApp Inbox — ai_activity (split out of features.php in v3.8, code unchanged)

/** API calls of this area (moved unchanged from the old single switch). */
function wf_api_ai_activity(string $api): void {
    switch ($api) {
    /* ----- AI activity: running tasks + recent log with model and tokens ----- */
    case 'ai_tasks': {
        $d = wi_ai_tasks();
        foreach ($d as &$x) if ($x['state'] === 'running' && strtotime($x['at']) < time() - 600) $x['state'] = 'failed';
        unset($x);
        if (!wi_is_super()) { $mine = array_keys(wi_my_accounts()); $d = array_values(array_filter($d, fn($x) => ($x['account'] ?? '') === '' ? ($x['user'] ?? '') === ($_SESSION['email'] ?? '') : in_array($x['account'], $mine, true))); }
        $today = date('Y-m-d'); $sum = ['tasks' => 0, 'tokens_in' => 0, 'tokens_out' => 0, 'failed' => 0];
        foreach ($d as $x) if (substr($x['at'], 0, 10) === $today) { $sum['tasks']++; $sum['tokens_in'] += $x['tokens_in'] ?? 0; $sum['tokens_out'] += $x['tokens_out'] ?? 0; if ($x['state'] === 'failed') $sum['failed']++; }
        $running = array_values(array_filter($d, fn($x) => $x['state'] === 'running'));
        wi_json(['ok' => true, 'running' => $running, 'recent' => array_slice(array_reverse($d), 0, (int) ($_GET['n'] ?? 8)), 'today' => $sum]);
    }

    }
}
