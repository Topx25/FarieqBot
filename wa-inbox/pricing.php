<?php
declare(strict_types=1);
/*
 * Public pricing and sign-up page — Topx1.Host
 * Shows the plans marked "shown" and, when sign-up is on, opens a trial panel for a new client.
 * The new client signs in with their email and the code sent to their WhatsApp (which also proves the number).
 */
require __DIR__ . '/lib.php';
if (!is_file(WI_DIR . '/installed.json') && !wi_users()) { header('Location: install.php'); exit; }
wi_boot();
$done = false; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(wi_csrf(), (string) ($_POST['_csrf'] ?? ''))) $err = 'The form expired. Please try again.';
    elseif (trim((string) ($_POST['website'] ?? '')) !== '') $done = true; // a robot filled the hidden field: nothing is created
    else { $err = wi_signup($_POST); $done = $err === ''; }
}
$plans = array_filter(wi_plans(), fn($p) => !empty($p['public']));
$cur = (string) (wi_sys('currency') ?: 'USD'); $brand = (string) wi_sys('brand_name'); $logo = (string) wi_sys('brand_logo');
$v = fn($k) => wi_e((string) ($_POST[$k] ?? ''));
ob_start('wi_translate_html');
?><!doctype html>
<html lang="<?= wi_e(wi_lang()) ?>" dir="<?= wi_lang() === 'ar' ? 'rtl' : 'ltr' ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= wi_e($brand) ?> — Plans</title>
<style>
:root{--bg:#f4f6fb;--card:#fff;--ink:#1d2433;--muted:#667085;--line:#e3e7ef;--acc:#16a34a;--acc2:#0f7a35;--bad:#b42318}
@media (prefers-color-scheme:dark){:root{--bg:#0e131d;--card:#161d2b;--ink:#e7ebf3;--muted:#98a2b6;--line:#283246;--acc:#22c55e;--acc2:#4ade80;--bad:#ff8a80}}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.6 system-ui,-apple-system,"Segoe UI",Tahoma,sans-serif}
.w{max-width:1080px;margin:0 auto;padding:24px 16px}header{text-align:center;margin:20px 0 28px}h1{font-size:28px;margin:6px 0}.m{color:var(--muted)}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:14px}.p{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:20px;display:flex;flex-direction:column;gap:8px}
.p h2{margin:0;font-size:19px}.pr{font-size:28px;font-weight:700}.pr small{font-size:14px;color:var(--muted);font-weight:400}.p ul{margin:6px 0 0;padding-inline-start:18px}.p li{margin:2px 0}
.no{color:var(--muted);text-decoration:line-through}.tag{display:inline-block;font-size:12px;border:1px solid var(--acc);color:var(--acc);border-radius:99px;padding:0 8px}
form{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:20px;max-width:520px;margin:28px auto 0;display:grid;gap:10px}
label{display:grid;gap:4px;font-size:14px}input{padding:10px 12px;border:1px solid var(--line);border-radius:9px;background:transparent;color:inherit;font:inherit}
button{background:var(--acc);color:#fff;border:0;border-radius:9px;padding:11px;font:inherit;font-weight:600;cursor:pointer}.e{color:var(--bad);border:1px solid currentColor;border-radius:9px;padding:10px}
.ok{background:var(--card);border:1px solid var(--acc);border-radius:14px;padding:22px;max-width:520px;margin:28px auto 0;text-align:center}.hp{position:absolute;left:-9999px}
a{color:var(--acc2)}footer{text-align:center;margin:30px 0 10px;font-size:13px}
</style></head><body><div class="w">
<header><div style="font-size:34px"><?= wi_e($logo) ?></div><h1><?= wi_e($brand) ?></h1><p class="m">WhatsApp inbox for your team, an AI assistant that answers like you, cases, broadcasts and backups.</p></header>
<div class="grid">
<?php foreach ($plans as $pid => $p): ?>
  <div class="p"><h2><?= wi_e((string) $p['name']) ?> <?php if (!empty($p['trial'])): ?><span class="tag">Free trial</span><?php endif; ?></h2>
    <div class="pr"><?= (float) $p['price'] > 0 ? wi_e(rtrim(rtrim(number_format((float) $p['price'], 2), '0'), '.') . ' ' . $cur) : 'Free' ?> <small>/ <?= (int) $p['days'] ?> days</small></div>
    <ul>
      <li><?= (int) $p['numbers'] ?> WhatsApp number(s)</li>
      <li><?= (int) $p['team'] ?> team member(s)</li>
      <li class="<?= !empty($p['bot']) ? '' : 'no' ?>">AI assistant (bot)</li>
      <?php if ((int) $p['ai_calls'] > 0): ?><li><?= (int) $p['ai_calls'] ?> AI replies a month included</li><?php endif; ?>
      <li class="<?= !empty($p['cases']) ? '' : 'no' ?>">Cases and follow-up</li>
      <li class="<?= !empty($p['meetings']) ? '' : 'no' ?>">Meetings</li>
      <li class="<?= !empty($p['backup']) ? '' : 'no' ?>">Backups to your Google Drive</li>
    </ul></div>
<?php endforeach; ?>
</div>
<?php if ($done): ?>
  <div class="ok"><h2>✅ Your panel is ready</h2><p>Sign in with your email. The sign-in code reaches you on WhatsApp.</p><p><a href="./">Sign in →</a></p></div>
<?php elseif (wi_sys('signup_on')): ?>
  <form method="post" autocomplete="on"><h2 style="margin:0">Start your free trial</h2>
    <?php if ($err): ?><div class="e"><?= wi_e(wi_t($err)) ?></div><?php endif; ?>
    <input type="hidden" name="_csrf" value="<?= wi_e(wi_csrf()) ?>"><input class="hp" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">
    <label>Your name<input name="name" value="<?= $v('name') ?>" required maxlength="80" dir="auto"></label>
    <label>Business name<input name="business" value="<?= $v('business') ?>" maxlength="120" dir="auto"></label>
    <label>Email<input type="email" name="email" value="<?= $v('email') ?>" required dir="ltr"></label>
    <label>Your WhatsApp number (with country code)<input name="phone" value="<?= $v('phone') ?>" required dir="ltr" placeholder="+20 10…"></label>
    <button>Create my panel</button><p class="m" style="margin:0;font-size:13px">No card needed. You link your WhatsApp number from the panel by scanning a QR code.</p>
  </form>
<?php else: ?>
  <p class="m" style="text-align:center;margin-top:24px">To subscribe, contact us. Already a client? <a href="./">Sign in</a></p>
<?php endif; ?>
<footer class="m">© <?= date('Y') ?> <?= wi_e($brand) ?></footer>
</div></body></html>
