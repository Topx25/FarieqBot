<?php
// WhatsApp Inbox — the plans page for signed-in clients ("Upgrade the plan"): every public plan with what it has, the client's own plan marked,
// and a button that asks for a plan on WhatsApp (the product's own number). A visitor who is not signed in goes to the product page's prices.
require __DIR__ . '/lib.php';
require __DIR__ . '/features.php';
if (!wi_logged_in()) { header('Location: ./?landing=1#pricing'); exit; }
$me = wi_me(); $owner = (function_exists('wi_is_team') && wi_is_team($me) && function_exists('wi_tenant')) ? (wi_tenant($me) ?? $me) : $me;
$current = (string) ($owner['plan_id'] ?? '');
$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); $t = fn($en) => wi_t($en);
$cur = (string) (wi_sys('plan_currency') ?: '$');
// the request goes to the product's own WhatsApp number, with the plan and the account in the message
$main = wi_accounts()['main'] ?? null; $sup = $main && function_exists('wi_account_phone') ? wi_account_phone($main) : '';
$cta = function (string $pid, array $p, bool $hot) use ($e, $t, $current, $sup, $owner) {
    if ($pid === $current) return '<span class="btn cur">✓ ' . $e($t('Your plan')) . '</span>';
    $msg = wi_t('I want the plan') . ': ' . wi_t((string) $p['name']) . ' — ' . (string) ($owner['email'] ?? '');
    $href = $sup !== '' ? 'https://wa.me/' . $sup . '?text=' . rawurlencode($msg) : './?p=dashboard';
    return '<a class="btn ' . ($hot ? 'p' : 'g') . '" href="' . $e($href) . '" target="_blank" rel="noopener">' . $e($t('Request this plan')) . '</a>';
};
$cards = wi_plan_cards($e, $t, $cur, $current, $cta);
$rtl = wi_lang() === 'ar';
?><!doctype html>
<html lang="<?= $rtl ? 'ar' : 'en' ?>" dir="<?= $rtl ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title><?= $e($t('Plans and prices')) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--bg:#f5f7fb;--card:#fff;--ink:#141a2b;--mut:#5b6478;--line:#e3e8f2;--blue:#0866ff;--gold:#b8860b;--gold-soft:#fbf3dd;--ok:#0a7d3e}
@media (prefers-color-scheme:dark){:root{--bg:#0d1120;--card:#151b2e;--ink:#eef1f8;--mut:#9aa3b8;--line:#263050;--blue:#4c8dff;--gold:#e0b44a;--gold-soft:#2c2614;--ok:#48c27a}}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.7 "IBM Plex Sans Arabic",system-ui,sans-serif}
.w{max-width:1180px;margin:0 auto;padding:28px max(16px,env(safe-area-inset-left,0px)) 48px}
.top{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:8px}
.top a{color:var(--blue);text-decoration:none;font-weight:600}
h1{font-size:30px;margin:6px 0}.sub{color:var(--mut);margin:0 0 22px}
.plans{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px;align-items:stretch}
.plan{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:20px;display:flex;flex-direction:column;gap:8px;position:relative}
.plan.hot{border:2px solid var(--gold)}.plan.mine{outline:3px solid var(--blue);outline-offset:-1px}
.plan .eyebrow{align-self:flex-start;background:var(--gold-soft);color:var(--gold);font-size:12px;font-weight:600;border-radius:999px;padding:2px 10px}
.plan h3{margin:0;font-size:19px}.price{font-size:28px;font-weight:700}.price small{font-size:13px;color:var(--mut);font-weight:500}
.plan ul{list-style:none;padding:0;margin:4px 0 12px;flex:1}.plan li{padding:6px 0;border-bottom:1px dashed var(--line);font-size:14px;display:flex;gap:8px}
.plan li::before{content:"✓";color:var(--ok);font-weight:700}.plan li.no{color:var(--mut);text-decoration:line-through;opacity:.7}.plan li.no::before{content:"✕";color:var(--mut)}
.btn{display:block;text-align:center;padding:10px 14px;border-radius:12px;font-weight:600;text-decoration:none;border:1px solid var(--line);color:var(--ink);background:var(--card)}
.btn.p{background:var(--blue);color:#fff;border-color:transparent}.btn.cur{background:transparent;color:var(--blue);border-color:var(--blue);cursor:default}
.note{margin-top:20px;color:var(--mut);font-size:13.5px}
.cmp-w{overflow-x:auto;margin-top:10px;border-radius:16px;border:1px solid var(--line);background:var(--card)}.cmp{width:100%;border-collapse:collapse;min-width:640px}.cmp th,.cmp td{padding:10px 14px;border-bottom:1px solid var(--line);text-align:center;white-space:nowrap}.cmp thead th{background:var(--bg)}.cmp tbody th{text-align:start;font-weight:500;white-space:normal;min-width:170px}.cmp-y{color:var(--ok);font-weight:700}.cmp-n{color:var(--mut);opacity:.6}.cmp td small{color:var(--mut)}.cmp .mine{background:rgba(8,102,255,.08)}
</style>
</head>
<body><div class="w">
  <div class="top"><a href="./">← <?= $e($t('Back to the dashboard')) ?></a></div>
  <h1><?= $e($t('Plans and prices')) ?></h1>
  <p class="sub"><?= $e($t('Everything each plan has. Press Request this plan and we set it up for you.')) ?></p>
  <div class="plans"><?= $cards ?></div>
  <h2 style="margin:30px 0 4px"><?= $e($t('Compare the plans')) ?></h2><?= wi_plan_compare($e, $t, $cur, $current) ?>
  <p class="note"><?= $e($t('Your plan is marked in blue. The prices are per month unless a card says otherwise.')) ?></p>
</div></body></html>
