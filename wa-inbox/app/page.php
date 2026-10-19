<?php
// WhatsApp Inbox — the page: layout, menu and every page's template (moved out of index.php; variables come from index.php).
?><!doctype html>
<html lang="en" dir="ltr">
<script>try{var t=localStorage.getItem('wi_theme');if(t==='dark'||t==='light')document.documentElement.setAttribute('data-theme',t);}catch(e){}</script>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= htmlspecialchars((string) wi_brand('brand_name')) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="app.css?v=<?= (int) @filemtime(dirname(__DIR__) . '/app.css') ?>">
<link rel="stylesheet" href="theme.css?v=<?= (int) @filemtime(dirname(__DIR__) . '/theme.css') + (int) @filemtime(dirname(__DIR__) . '/features.js') ?>">
<link rel="stylesheet" href="pro.css?v=<?= (int) @filemtime(dirname(__DIR__) . '/pro.css') ?>">
<?php $uiTheme = wi_config()['ui_theme'] ?? 'google'; if (in_array($uiTheme, ['google', 'orchid', 'studio', 'aurora'], true)): ?><link rel="stylesheet" href="<?= $uiTheme ?>.css?v=<?= (int) @filemtime(dirname(__DIR__) . '/' . $uiTheme . '.css') ?>"><?php endif; ?>
<?php if ($uiTheme === 'studio'): $uc = wi_config()['ui_colors'] ?? []; $hx = fn($v, $d) => preg_match('/^#[0-9a-f]{6}$/i', (string) $v) ? $v : $d; ?><link href="https://fonts.googleapis.com/css2?family=Readex+Pro:wght@400;500;600&display=swap" rel="stylesheet"><style>:root{--acc:<?= $hx($uc['acc'] ?? '', '#2563eb') ?>;--acc2:<?= $hx($uc['acc2'] ?? '', '#d97706') ?>;--canvas:<?= $hx($uc['canvas'] ?? '', '#f7f8fb') ?>}</style><?php endif; ?>
<?php if ($uiTheme === 'orchid'): ?><link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600&display=swap" rel="stylesheet"><?php endif; ?>
</head>
<body>
<?php if (!$me): ?>
<main class="auth">
  <section class="auth-card">
    <div class="brand"><span class="logo"><?= htmlspecialchars((string) wi_brand('brand_logo')) ?></span><div><b><?= htmlspecialchars((string) wi_brand('brand_name')) ?></b><small>WhatsApp conversation archive</small></div></div>
    <?php if ($flash): ?><div class="alert <?= wi_e($flash['t']) ?>"><?= wi_e($flash['m']) ?></div><?php endif; ?>
    <form method="post" class="stack">
      <input type="hidden" name="_csrf" value="<?= wi_e(wi_csrf()) ?>">
      <?php if (!empty($_SESSION['pending'])): ?>
        <input type="hidden" name="do" value="otp">
        <label>Code sent to your WhatsApp<input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" required autofocus class="otp" placeholder="••••••"></label>
        <button class="btn primary">Sign in</button>
      <?php else: ?>
        <input type="hidden" name="do" value="login">
        <label>Email<input type="email" name="email" autocomplete="username" required autofocus dir="ltr"></label>
        <label>Password<input type="password" name="password" autocomplete="current-password" required dir="ltr"></label>
        <button class="btn primary">Continue</button>
      <?php endif; ?>
    </form>
    <?php if (!empty($_SESSION['pending'])): ?><form method="post"><input type="hidden" name="_csrf" value="<?= wi_e(wi_csrf()) ?>"><input type="hidden" name="do" value="cancel"><button class="btn ghost block">Back</button></form><?php endif; ?>
    <p class="muted center small">Sign in with your password, then a code sent to your WhatsApp.</p>
    <p class="center small lang-switch"><a href="?lang=en">English</a> · <a href="?lang=ar">العربية</a></p>
  </section>
</main>
<?php else: ?>
<div class="app shell-v3" data-sec="<?= wi_e((string) ($_GET['sec'] ?? '')) ?>" data-page="<?= wi_e($page) ?>" data-csrf="<?= wi_e(wi_csrf()) ?>" data-acc="<?= wi_e($cur['id'] ?? '') ?>" data-canusers="<?= wi_can_users() ? '1' : '' ?>">
  <aside class="side">
    <div class="brand side-handle" title="Drag to move the menu"><span class="logo"><?= htmlspecialchars((string) wi_brand('brand_logo')) ?></span><div><b><?= htmlspecialchars((string) wi_brand('brand_name')) ?></b><small id="gw-state">…</small></div>
      <button type="button" class="lock-btn" id="menu-lock" title="Lock / unlock the menu">🔓</button></div>
    <form method="post" class="acc-switch">
      <input type="hidden" name="_csrf" value="<?= wi_e(wi_csrf()) ?>"><input type="hidden" name="do" value="switch_account"><input type="hidden" name="back" value="?p=<?= wi_e($page) ?>">
      <label>WhatsApp account<select name="acc" onchange="this.form.submit()">
        <?php foreach ($mine as $a): ?><option value="<?= wi_e($a['id']) ?>" <?= $cur && $cur['id'] === $a['id'] ? 'selected' : '' ?>><?= wi_e($a['name']) ?></option><?php endforeach; ?>
        <?php if (!$mine): ?><option>No account assigned</option><?php endif; ?>
      </select></label>
    </form>
    <?php $waPages = ['accounts', 'link', 'gateway']; $chatPages = ['inbox', 'search']; ?>
    <nav id="side-nav">
      <?php foreach (wi_menu() as $sec): $vis = array_values(array_filter($sec['items'], fn($it) => !$it['hidden'] && (wi_menu_can($it['who'], (bool) $manager) || ($it['key'] === 'broadcast' && wi_is_team()))
        && ($it['key'] === 'dashboard' || !in_array($it['who'], ['all', 'manager'], true) || array_filter($it['pages'], fn($pg) => wi_page_perm($pg) !== '')))); // only what this user may open if (!$vis) continue; ?>
      <div class="nav-h"><?= wi_e($sec['title']) ?></div>
      <?php foreach ($vis as $it): ?><a href="<?= wi_e($it['href'] ?? '?p=' . $it['key']) ?>" class="<?= in_array($page, $it['pages'], true) ? 'on' : '' ?>"><i><?= wi_e($it['icon']) ?></i><?= wi_e($it['label']) ?></a>
      <?php endforeach; endforeach; ?>
    </nav>
    <div class="lang-switch side-lang"><a href="?p=<?= wi_e($page) ?>&amp;lang=en" class="<?= wi_lang() === 'en' ? 'on' : '' ?>">English</a><a href="?p=<?= wi_e($page) ?>&amp;lang=ar" class="<?= wi_lang() === 'ar' ? 'on' : '' ?>">العربية</a></div>
    <a href="?p=changes" class="ver-chip <?= $page === 'changes' ? 'on' : '' ?>">🆕 <?= wi_e($versions[0]['v'] ?? '') ?></a>
    <div class="me">
      <a href="?p=me" class="avatar" title="My account"><?= wi_e(mb_strtoupper(mb_substr(($me['name'] ?? '') ?: $me['email'], 0, 1))) ?></a>
      <div class="me-t"><b><?= wi_e(($me['name'] ?? '') ?: $me['email']) ?></b><small><?= ['super' => 'Super admin', 'admin' => 'Admin', 'manager' => 'Manager', 'client' => 'Client', 'lead' => 'Supervisor', 'agent' => 'Employee'][$me['role'] ?? ''] ?? 'Viewer' ?></small></div>
      <form method="post"><input type="hidden" name="_csrf" value="<?= wi_e(wi_csrf()) ?>"><input type="hidden" name="do" value="logout"><button class="icon-btn" title="Sign out">⎋</button></form>
    </div>
  </aside>
  <main class="main<?= in_array($page, ['inbox'], true) ? ' is-inbox' : '' ?>">
    <?php foreach ((array) ($me['invites'] ?? []) as $ipg => $inv): ?><div class="inv-bar" data-inv="<?= wi_e($ipg) ?>">📨 <span><b><?= wi_e($inv['by_name'] ?? $inv['by'] ?? '') ?></b> <?= wi_e(wi_t('shared with you')) ?> «<?= wi_e(wi_t(wi_page_names()[$ipg] ?? $ipg)) ?>» — <?= wi_e(wi_t(($inv['level'] ?? 'view') === 'edit' ? 'view & edit' : 'view only')) ?></span><button class="btn sm primary" data-inv-a="1">✔ <?= wi_e(wi_t('Accept')) ?></button><button class="btn sm ghost" data-inv-a="0">✖ <?= wi_e(wi_t('Decline')) ?></button></div><?php endforeach; ?>
    <?php if (!empty($_SESSION['imp_from'])): ?><div class="imp-bar">👁 <span>You are viewing as <b><?= wi_e(($me['name'] ?? '') ?: $me['email']) ?></b> (<?= wi_e($me['email']) ?>) — everything you do is recorded under your own account.</span><form method="post"><input type="hidden" name="_csrf" value="<?= wi_e(wi_csrf()) ?>"><input type="hidden" name="do" value="impersonate_end"><button class="btn sm primary">↩ Back to my account</button></form></div><?php endif; ?>
    <header class="topbar">
      <div class="tb-title"><span class="tb-ic" id="tb-ic">💬</span><b id="tb-name"></b></div>
      <form class="tb-search"<?= wi_cases_only() ? ' hidden' : '' ?> method="get" action="./"><input type="hidden" name="p" value="search"><span>🔎</span><input type="search" name="q" placeholder="<?= wi_e(wi_t('Search all messages…')) ?>" dir="auto"></form>
      <div class="tb-right">
        <?php if (!wi_cases_only()): ?><form method="post" class="tb-acc">
          <input type="hidden" name="_csrf" value="<?= wi_e(wi_csrf()) ?>"><input type="hidden" name="do" value="switch_account"><input type="hidden" name="back" value="?p=<?= wi_e($page) ?>">
          <span class="tb-dot" id="tb-dot"></span><select name="acc" onchange="this.form.submit()" title="WhatsApp account">
            <?php foreach ($mine as $a): ?><option value="<?= wi_e($a['id']) ?>" <?= $cur && $cur['id'] === $a['id'] ? 'selected' : '' ?>>📱 <?= wi_e($a['name']) ?></option><?php endforeach; ?>
          </select>
        </form><?php endif; ?>
        <a class="tb-btn" href="?p=<?= wi_e($page) ?>&amp;lang=<?= wi_lang() === 'ar' ? 'en' : 'ar' ?>" title="Language"><?= wi_lang() === 'ar' ? 'EN' : 'ع' ?></a>
        <a class="tb-btn tb-ver" href="?p=changes" title="What's new">🆕 <?= wi_e($versions[0]['v'] ?? '') ?></a>
        <div class="tb-me" title="<?= wi_e($me['email'] ?? '') ?>"><a href="?p=me" class="avatar" title="My account"><?= wi_e(mb_strtoupper(mb_substr(($me['name'] ?? '') ?: ($me['email'] ?? '?'), 0, 1))) ?></a>
          <form method="post"><input type="hidden" name="_csrf" value="<?= wi_e(wi_csrf()) ?>"><input type="hidden" name="do" value="logout"><button class="tb-btn tb-out" title="Sign out"><svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg><span>Sign out</span></button></form></div>
      </div>
    </header>
    <?php if ($flash): ?><div class="alert <?= wi_e($flash['t']) ?>"><?= wi_e($flash['m']) ?></div><?php endif; ?>
    <?php if ($me && ($myCl = wi_client_of($me)) && ($mySt = wi_client_state($myCl))['state'] === 'expiring'): ?><div class="alert bad">⏳ <?= wi_e(sprintf(wi_t('Your subscription ends in %d day(s), on %s. Contact the administrator to renew it.'), max(0, (int) $mySt['days']), (string) $myCl['expires'])) ?></div><?php endif; ?>
    <?php if (in_array($page, $waPages, true)): ?>
      <div class="page-tabs"><span class="pt-title">📱 WhatsApp</span>
        <a href="?p=accounts" class="<?= $page === 'accounts' ? 'on' : '' ?>">📱 Accounts</a>
        <a href="?p=link" class="<?= $page === 'link' ? 'on' : '' ?>">🔗 Link / QR</a>
        <a href="?p=gateway" class="<?= $page === 'gateway' ? 'on' : '' ?>">🛡️ Gateway &amp; Guard</a></div>
    <?php elseif (in_array($page, $autoPages, true)): ?>
      <div class="page-tabs"><span class="pt-title">⏰ Scheduling &amp; programs</span>
        <a href="?p=schedule" class="<?= $page === 'schedule' ? 'on' : '' ?>">⏰ Scheduled messages</a>
        <a href="?p=programs" class="<?= $page === 'programs' ? 'on' : '' ?>">💊 <?= wi_e((string) (wf_pack((string) (wi_current()['id'] ?? ''))['program_label'] ?? 'Programs')) ?></a>
        <?php if (wi_is_admin()): ?><a href="?p=status" class="<?= $page === 'status' ? 'on' : '' ?>">🟢 WhatsApp Status</a><?php endif; ?></div>
    <?php elseif (in_array($page, $sysPages, true)): ?>
      <div class="page-tabs"><span class="pt-title">❤️ System</span>
        <a href="?p=health" class="<?= $page === 'health' ? 'on' : '' ?>">❤️ Number health</a>
        <a href="?p=report" class="<?= $page === 'report' ? 'on' : '' ?>">📊 Daily report</a>
        <a href="?p=backup" class="<?= $page === 'backup' ? 'on' : '' ?>">☁️ Google Drive backup</a></div>
    <?php elseif (in_array($page, ['library', 'audio', 'files', 'media'], true)): $libSec = (string) ($_GET['sec'] ?? ''); ?>
      <?php $libBin = $page === 'media' && !empty($_GET['bin']); ?>
      <nav class="lib-nav">
        <div class="ln-group"><span class="ln-h"><span aria-hidden="true">🎞️</span> <span>Media and files</span></span>
          <a href="?p=media" class="<?= $page === 'media' && !$libBin ? 'on' : '' ?>">🖼️ Media</a>
          <a href="?p=audio" class="<?= $page === 'audio' ? 'on' : '' ?>">🎧 Audio</a>
          <a href="?p=files" class="<?= $page === 'files' ? 'on' : '' ?>">📄 Files</a>
          <a href="?p=media&amp;bin=1" class="ln-bin <?= $libBin ? 'on' : '' ?>">🗑 Deleted</a></div>
        <div class="ln-group"><span class="ln-h"><span aria-hidden="true">📚</span> <span>Texts</span></span>
        <a href="?p=library" class="<?= $page === 'library' && $libSec === '' ? 'on' : '' ?>">🗂️ All</a>
        <?php $libR = (json_decode((string) @file_get_contents(wf_acc_rules_file((string) ($cur['id'] ?? ''))), true) ?: [])['library'] ?? []; $libTabs = [];
          foreach ((array) ($libR['sections'] ?? []) as $sx) $libTabs[] = [$sx['name'], $sx['icon'] ?? '📁', $sx['name']];
          if (!empty($libR['tag_section']['name'])) $libTabs[] = [$libR['tag_section']['name'], $libR['tag_section']['icon'] ?? '📁', $libR['tag_section']['name']]; // sections from Settings → Gateway rules
          foreach ($libTabs as $t): ?>
          <a href="?p=library&amp;sec=<?= rawurlencode($t[0]) ?>" class="<?= $page === 'library' && $libSec === $t[0] ? 'on' : '' ?>"><?= $t[1] ?> <?= wi_e($t[2]) ?></a>
        <?php endforeach; ?></div></nav>
    <?php elseif (in_array($page, $chatPages, true)): ?>
      <div class="page-tabs"><span class="pt-title">💬 Conversations</span>
        <a href="?p=inbox" class="<?= $page === 'inbox' ? 'on' : '' ?>">💬 Chats</a>
        <a href="?p=search" class="<?= $page === 'search' ? 'on' : '' ?>">🔎 Search messages</a></div>
    <?php endif; ?>

    <?php if ($page === 'dashboard'): ?>
      <section class="home">
        <div class="h-hero">
          <div class="h-hello"><small id="h-date"></small><h1 id="h-greet">…</h1><p class="muted" id="since">…</p><div class="h-accs" id="h-accs"></div></div>
          <div class="h-quick" id="h-quick"></div>
        </div>
        <div class="h-kpis" id="kpis"><div class="kpi skeleton"></div><div class="kpi skeleton"></div><div class="kpi skeleton"></div><div class="kpi skeleton"></div></div>
        <div class="h-grid">
          <div class="card h-chart"><div class="card-h"><h2>📊 Messages per day — last 7 days</h2><div class="legend"><span class="dot in"></span>Received <span class="dot out"></span>Sent</div></div><div id="chart" class="h-svg"></div><div class="h-mini" id="kpis2"></div></div>
          <div class="card h-attn"><div class="card-h"><h2>🔔 Needs your attention</h2><button class="icon-btn" data-reload title="Refresh">↻</button></div><div id="h-attn" class="h-list"><div class="empty">Loading…</div></div></div>
          <div class="card h-top"><div class="card-h"><h2>🔥 Most active conversations</h2><a href="?p=inbox" class="link">All conversations →</a></div><div id="top" class="top-list"></div></div>
          <div class="card h-sys"><div class="card-h"><h2>🛰️ Numbers &amp; bot</h2></div><div id="h-sys" class="h-list"></div></div>
        </div>
        <div class="h-short" id="h-short"></div>
      </section>

    <?php elseif ($page === 'inbox'): ?>
      <section class="inbox card">
        <aside class="chat-list">
          <div class="chat-list-h">
            <div class="view-bar" id="view-bar"><input type="search" id="chat-q" placeholder="Search name or number…" dir="auto">
              <button type="button" class="icon-btn" data-view="focus" title="Full screen: hide the header and menus">⛶</button>
              <button type="button" class="icon-btn" data-view="thumbs" title="Show / hide photo and video previews">🖼️</button>
              <button type="button" class="icon-btn" data-view="hidden" title="Show the hidden chats">🙈</button>
              <button type="button" class="icon-btn" data-view="list" title="Hide the chat list">📋</button>
              <?php if (count(wi_my_accounts()) > 1): ?><button type="button" class="icon-btn" data-view="all" title="All my numbers in one list">🔀</button><?php endif; ?></div>
            <div class="chips" id="chat-filter"><button class="on" data-f="">All</button><button data-f="customers">Customers</button><button data-f="others">Others</button><button data-f="groups">Groups</button><button data-f="mine">👤 Mine</button><button data-f="unassigned">Unassigned</button><button data-f="remind">⏰ Follow-up</button></div>
            <div class="chips labels-filter" id="label-filter"></div>
          </div>
          <div id="due-box" class="due-box" hidden></div>
          <div class="classify-bar"><button type="button" class="chip-btn sm" id="classify-btn">🧠 Classify recent chats</button><span class="muted small" id="classify-st"></span></div>
          <div id="chats" class="chats"><div class="empty">Loading…</div></div>
        </aside>
        <section class="thread">
          <header class="thread-h" id="thread-h"><span class="muted">Choose a conversation</span></header>
          <aside class="info-panel" id="info-panel" hidden></aside>
          <div class="sum-box" id="sum-box" hidden></div>
          <div class="msgs" id="msgs"><div class="empty big">💬<br>Choose a conversation from the list</div></div>
          <?php if (wi_can_reply()): ?>
          <div class="qr-pop" id="qr-pop" hidden></div>
          <form class="composer" id="composer" hidden>
            <label class="attach-btn lbl" title="Send a photo, audio or PDF"><span>📎</span><em>Attach</em><input type="file" id="attach" accept="image/jpeg,image/png,image/webp,audio/*,.ogg,.mp3,.m4a,.wav,application/pdf" hidden></label>
            <button type="button" class="attach-btn lbl" id="ml-btn" title="Send from the media library"><span>🖼️</span><em>Library</em></button>
            <button type="button" class="attach-btn lbl" id="qr-btn" title="Quick replies (type / or #)"><span>⚡</span><em>Quick replies</em></button>
            <textarea id="reply" rows="1" maxlength="4000" placeholder="Type a reply… ( / for quick replies)" dir="auto"></textarea>
            <button class="btn primary" id="send-btn" title="Send">➤</button>
          </form>
          <div class="attach-preview" id="attach-preview" hidden></div>
          <p class="safe-note muted small" id="safe-note" hidden>🛡️ Replies only in existing chats · typing indicator · max 1 message / 3 s and 20 / minute.</p>
          <?php endif; ?>
        </section>
      </section>

      <dialog id="qr-dlg" class="dlg qr-dlg">
        <div class="qr-head"><h2>⚡ Quick replies</h2><input type="search" id="qr-q" placeholder="Search quick replies…" dir="auto"><button type="button" class="btn ghost sm" data-close>✕</button></div>
        <div class="qr-body">
          <aside class="qr-side">
            <div class="qr-side-h"><b id="qr-count"></b><button type="button" class="btn ghost sm" id="qr-new">➕ New</button></div>
            <div id="qr-list" class="qr-list"></div>
            <button type="button" class="btn ghost sm qr-mine-btn" id="qr-mine">✨ Suggest from repeated messages</button>
            <?php if (wi_is_super() && $cur && wi_ws($cur) !== ''): ?><button type="button" class="btn ghost sm qr-mine-btn" id="qr-import" title="This number belongs to a client and has its own list">📥 Copy my quick replies to this number</button><?php endif; ?>
            <div id="qr-mined"></div>
          </aside>
          <section class="qr-edit">
            <input type="hidden" id="qr-id">
            <h3 id="qr-mode">New quick reply</h3>
            <label>Short name<input id="qr-title" maxlength="60" placeholder="e.g. payment methods"></label>
            <label>Reply text<textarea id="qr-text" rows="7" maxlength="2000" dir="auto"></textarea></label>
            <p class="muted small qr-vars">Variables: <code>{name}</code> <code>{phone}</code> <code>{due}</code> (customer balance) <code>{invoice}</code> (open invoice) <code>{date}</code></p>
            <label>Members' keyword <small class="muted">(a member sends #keyword in a group or chat → this reply is sent to them)</small><span class="qr-kw"><b>#</b><input id="qr-kw" maxlength="30" placeholder="تحصين" dir="auto"></span></label>
            <label>Trigger words<textarea id="qr-trig" rows="3" dir="auto" placeholder="<?= wi_e((string) (wf_pack((string) (wi_current()['id'] ?? ''))['ex_triggers'] ?? '')) ?>"></textarea></label>
            <p class="muted small">When someone types one of these in a chat, this reply is suggested. One per line or separated by commas.</p>
          </section>
        </div>
        <div class="qr-foot"><span class="muted small" id="qr-msg"></span><button type="button" class="btn primary" id="qr-save">💾 Save reply</button></div>
      </dialog>
      <dialog id="inv-dlg" class="dlg wide"><div class="stack pad">
        <div class="row" style="justify-content:space-between;align-items:center"><h2>🧾 Invoices</h2><button type="button" class="btn ghost sm" data-close>✕</button></div>
        <div id="inv-body"><div class="empty">Loading…</div></div>
      </div></dialog>
      <dialog id="rm-dlg" class="dlg"><div class="stack pad">
        <div class="row" style="justify-content:space-between;align-items:center"><h2>⏰ Follow-up reminder</h2><button type="button" class="btn ghost sm" data-close>✕</button></div>
        <div class="row wrap" id="rm-quick"><button type="button" class="chip-btn" data-in="60">In 1 hour</button><button type="button" class="chip-btn" data-in="180">In 3 hours</button><button type="button" class="chip-btn" data-tomorrow="10">Tomorrow 10:00</button><button type="button" class="chip-btn" data-in="10080">In a week</button></div>
        <label>When<input type="datetime-local" id="rm-at"></label>
        <label>Note<input id="rm-note" maxlength="300" dir="auto" placeholder="What to follow up on"></label>
        <p class="muted small">You get it here and on your WhatsApp at that time.</p>
        <button type="button" class="btn primary" id="rm-save">Set reminder</button>
      </div></dialog>
      <dialog id="lb-dlg" class="dlg"><div class="stack pad">
        <div class="row" style="justify-content:space-between;align-items:center"><h2>🏷️ Labels</h2><button type="button" class="btn ghost sm" data-close>✕</button></div>
        <div id="lb-list" class="stack"></div>
        <div class="row"><input id="lb-name" maxlength="30" placeholder="New label" dir="auto"><input type="color" id="lb-color" value="#10b981" style="width:56px;padding:4px"><button type="button" class="btn primary" id="lb-add">Add</button></div>
      </div></dialog>

    <?php elseif ($page === 'clients'):
        $cls = array_values(array_filter(wi_users(), fn($u) => ($u['role'] ?? '') === 'client')); $cf = in_array($_GET['f'] ?? '', ['active', 'expiring', 'ended'], true) ? $_GET['f'] : 'all';
        $stOf = []; $cnt = ['all' => count($cls), 'active' => 0, 'expiring' => 0, 'ended' => 0];
        foreach ($cls as $c) { $s = wi_client_state($c); $stOf[$c['email']] = $s; $cnt[$s['state'] === 'expiring' ? 'expiring' : (in_array($s['state'], ['expired', 'suspended'], true) ? 'ended' : 'active')]++; }
        $show = array_values(array_filter($cls, function ($c) use ($cf, $stOf) { $s = $stOf[$c['email']]['state']; return $cf === 'all' || ($cf === 'expiring' && $s === 'expiring') || ($cf === 'ended' && in_array($s, ['expired', 'suspended'], true)) || ($cf === 'active' && $s === 'active'); }));
        usort($show, fn($a, $b) => strcmp((string) (($a['expires'] ?? '') ?: '9999'), (string) (($b['expires'] ?? '') ?: '9999'))); // ending soonest first
        $lastAct = function (array $c): ?string { $m = wi_db(); if (!$m) return null; $em = array_map(fn($u) => "'" . $m->real_escape_string(strtolower((string) $u['email'])) . "'", array_merge([$c], wi_team_of((string) $c['email'])));
            $r = $m->query("SELECT MAX(at) FROM wi_audit WHERE LOWER(user) IN (" . implode(',', $em) . ") AND acting_as = ''"); return $r ? ($r->fetch_row()[0] ?? null) : null; };
        $stInfo = ['active' => ['ok', '✅', 'Active'], 'expiring' => ['warn', '⏳', 'Ends soon'], 'expired' => ['bad', '⛔', 'Ended'], 'suspended' => ['off', '⏸', 'Paused']];
        $fx = ['can_bot' => ['🤖', 'Bot'], 'can_meetings' => ['🎥', 'Meetings'], 'can_cases' => ['🗂️', 'Cases'], 'can_backup' => ['💾', 'Backup']];
        $mb = fn($b) => $b >= 1073741824 ? round($b / 1073741824, 1) . ' GB' : round($b / 1048576) . ' MB'; ?>
      <section class="hero"><div class="hero-t"><span class="hero-ic">🏪</span><div><h1>Clients</h1><p class="muted">Every client panel: plan, end date, number, team and this month's usage. An ended or paused panel stops (sign-in, bot, scheduled messages); its data is kept.</p></div></div>
        <a class="btn primary" href="?p=users&amp;new=client">➕ New client</a></section>
      <div class="cl-kpis">
        <?php foreach (['all' => ['🏪', 'All clients'], 'active' => ['✅', 'Active'], 'expiring' => ['⏳', 'End within 7 days'], 'ended' => ['⛔', 'Ended or paused']] as $k => $x): ?>
          <a class="cl-kpi<?= $cf === $k ? ' on' : '' ?> k-<?= $k ?>" href="?p=clients&amp;f=<?= $k ?>"><span><?= $x[0] ?></span><b><?= (int) $cnt[$k] ?></b><small><?= wi_e(wi_t($x[1])) ?></small></a>
        <?php endforeach; ?>
      </div>
      <div class="row wrap" style="margin:4px 0 12px"><div class="search-xl"><span>🔎</span><input type="search" id="cl-q" placeholder="Search name, email or number…" dir="auto"></div><span class="muted small">Usage of <?= wi_e(date('Y-m')) ?> · updated every hour</span></div>
      <?php if (!$show): ?><div class="card empty"><?= $cls ? 'No client in this filter.' : 'No clients yet. Add one with «New client».' ?></div><?php endif; ?>
      <div class="cl-list">
      <?php foreach ($show as $c): $s = $stOf[$c['email']]; $si = $stInfo[$s['state']]; $nums = wi_owned_accounts((string) $c['email']); $team = wi_team_of((string) $c['email']); $us = wf_client_usage((string) $c['email']); $la = $lastAct($c); ?>
        <article class="card cl-card st-<?= $si[0] ?>" data-q="<?= wi_e(mb_strtolower(($c['name'] ?? '') . ' ' . $c['email'] . ' ' . ($c['phone'] ?? '') . ' ' . implode(' ', array_column($nums, 'name')))) ?>">
          <div class="cl-top"><span class="avatar"><?= wi_e(mb_strtoupper(mb_substr(($c['name'] ?? '') ?: $c['email'], 0, 1))) ?></span>
            <div class="cl-who"><b dir="auto"><?= wi_e(($c['name'] ?? '') ?: $c['email']) ?></b><small dir="ltr"><?= wi_e($c['email']) ?> · +<?= wi_e((string) ($c['phone'] ?? '')) ?></small></div>
            <span class="cl-st <?= $si[0] ?>"><?= $si[1] ?> <?= wi_e(wi_t($si[2])) ?></span></div>
          <div class="cl-grid">
            <div><small>Plan</small><b dir="auto"><?= wi_e((string) (($c['plan'] ?? '') ?: '—')) ?></b></div>
            <div><small>Ends on</small><b dir="ltr"><?= !empty($c['expires']) ? wi_e((string) $c['expires']) : '<span class="muted">' . wi_e(wi_t('No end date')) . '</span>' ?></b><?php if ($s['days'] !== null): ?><em class="<?= $s['days'] < 0 ? 'bad-t' : ($s['days'] <= 7 ? 'warn-t' : 'muted') ?>"><?= $s['days'] < 0 ? wi_e(sprintf(wi_t('%d day(s) ago'), -$s['days'])) : wi_e(sprintf(wi_t('%d day(s) left'), $s['days'])) ?></em><?php endif; ?></div>
            <div><small>WhatsApp number</small><?php if (!$nums): ?><b class="muted">Not linked yet</b><?php else: foreach ($nums as $na): $hs = wi_gateway('/status', null, $na); ?><b dir="auto"><?= !empty($hs['connected']) ? '🟢' : '🔴' ?> <?= wi_e((string) $na['name']) ?></b><?php endforeach; endif; ?></div>
            <div><small>Team</small><b><?= count($team) ?> / <?= wi_team_seats($c) ?></b></div>
            <div><small>Messages this month</small><b><?= number_format($us['in'] + $us['out']) ?></b><em class="muted">↓ <?= number_format($us['in']) ?> · ↑ <?= number_format($us['out']) ?> · 🤖 <?= number_format($us['bot']) ?></em></div>
            <div><small>AI this month</small><b><?= number_format($us['ai_calls']) ?></b><em class="muted"><?= number_format($us['ai_tokens']) ?> tokens</em></div>
            <div><small>Stored media</small><b><?= $mb($us['media_bytes']) ?></b></div>
            <div><small>Last activity</small><b dir="ltr"><?= $la ? wi_e(substr((string) $la, 0, 16)) : '—' ?></b></div>
          </div>
          <div class="ux-tags"><?php foreach ($fx as $k => $f): ?><span class="<?= !empty($c[$k]) ? 'on' : '' ?>"><?= $f[0] ?> <?= wi_e(wi_t($f[1])) ?></span><?php endforeach; ?></div>
          <?php if (!empty($c['client_note'])): ?><p class="cl-note" dir="auto">📝 <?= wi_e((string) $c['client_note']) ?></p><?php endif; ?>
          <div class="cl-act">
            <button type="button" class="btn ghost sm" data-cl-edit="<?= wi_e(json_encode(['email' => $c['email'], 'name' => ($c['name'] ?? '') ?: $c['email'], 'plan' => $c['plan'] ?? '', 'plan_id' => (string) ($c['plan_id'] ?? ''), 'slug' => (string) ($c['slug'] ?? ''), 'domains' => implode(', ', (array) ($c['domains'] ?? [])), 'brand_name' => (string) ($c['brand_name'] ?? ''), 'brand_logo' => (string) ($c['brand_logo'] ?? ''), 'max_numbers' => wi_client_max_numbers($c), 'ai_calls' => (int) ($c['ai_calls'] ?? 0), 'expires' => $c['expires'] ?? '', 'max_team' => (int) ($c['max_team'] ?? 0), 'note' => $c['client_note'] ?? '', 'active' => !empty($c['active']), 'can_bot' => !empty($c['can_bot']), 'can_meetings' => !empty($c['can_meetings']), 'can_cases' => !empty($c['can_cases']), 'can_backup' => !empty($c['can_backup']), 'allow_ruqyah' => !empty($c['allow_ruqyah'])], JSON_UNESCAPED_UNICODE)) ?>">✏️ Edit plan</button>
            <form method="post" class="inline cl-ext"><input type="hidden" name="_csrf" value="<?= wi_e(wi_csrf()) ?>"><input type="hidden" name="do" value="client_extend"><input type="hidden" name="f" value="<?= wi_e($cf) ?>"><input type="hidden" name="email" value="<?= wi_e($c['email']) ?>">
              <span class="muted small">Renew:</span><button class="chip-btn sm" name="days" value="30">+30</button><button class="chip-btn sm" name="days" value="90">+90</button><button class="chip-btn sm" name="days" value="365">+1 year</button></form>
            <form method="post" class="inline"><input type="hidden" name="_csrf" value="<?= wi_e(wi_csrf()) ?>"><input type="hidden" name="do" value="client_toggle"><input type="hidden" name="f" value="<?= wi_e($cf) ?>"><input type="hidden" name="email" value="<?= wi_e($c['email']) ?>"><button class="btn ghost sm" onclick="return confirm('<?= !empty($c['active']) ? 'Pause this panel? The client and their team cannot sign in and the bot stops; nothing is deleted.' : 'Resume this panel?' ?>')"><?= !empty($c['active']) ? '⏸ Pause' : '▶️ Resume' ?></button></form>
            <form method="post" class="inline"><input type="hidden" name="_csrf" value="<?= wi_e(wi_csrf()) ?>"><input type="hidden" name="do" value="impersonate"><input type="hidden" name="email" value="<?= wi_e($c['email']) ?>"><button class="btn ghost sm" title="See the panel as this client">👁 Sign in as</button></form>
          </div>
        </article>
      <?php endforeach; ?>
      </div>
      <details class="card pad" style="margin:12px 0"><summary><b>📦 Plans and sign-up</b> <small class="muted">— the limits each plan gives, and whether visitors may open a trial themselves</small></summary>
        <form method="post" class="stack" style="margin-top:10px"><input type="hidden" name="_csrf" value="<?= wi_e(wi_csrf()) ?>"><input type="hidden" name="do" value="plans_save">
          <label class="check"><input type="checkbox" name="signup_on" value="1" <?= wi_sys('signup_on') ? 'checked' : '' ?>> Visitors may sign up (a trial on the plan marked «trial») — page: <a href="pricing.php" target="_blank" rel="noopener">pricing.php</a></label>
          <label>SaaS domain <small class="muted">— each client may get name.this-domain (point *.this-domain to this server)</small><input name="saas_domain" value="<?= wi_e((string) wi_sys('saas_domain')) ?>" dir="ltr" placeholder="crm.example.com" style="max-width:320px"></label>
          <div style="overflow-x:auto"><table class="tbl"><tr><th>ID</th><th>Name</th><th>Price</th><th>Days</th><th>Numbers</th><th>Team</th><th>AI calls / month</th><th>Bot</th><th>Meetings</th><th>Cases</th><th>Backup</th><th>Shown</th><th>Trial</th><th>Delete</th></tr>
          <?php $prow = 0; foreach (wi_plans() + ['' => ['name' => '', 'price' => 0, 'days' => 30, 'numbers' => 1, 'team' => 0, 'ai_calls' => 0]] as $pid => $pp): $n = 'plans[' . $prow++ . ']'; ?>
            <tr><td><input name="<?= $n ?>[id]" value="<?= wi_e((string) $pid) ?>" size="7" placeholder="new" dir="ltr"></td><td><input name="<?= $n ?>[name]" value="<?= wi_e((string) $pp['name']) ?>" size="9" dir="auto"></td>
              <td><input type="number" step="0.01" min="0" name="<?= $n ?>[price]" value="<?= wi_e((string) $pp['price']) ?>" style="width:70px"></td><td><input type="number" min="1" name="<?= $n ?>[days]" value="<?= (int) $pp['days'] ?>" style="width:60px"></td>
              <td><input type="number" min="0" name="<?= $n ?>[numbers]" value="<?= (int) $pp['numbers'] ?>" style="width:55px"></td><td><input type="number" min="0" name="<?= $n ?>[team]" value="<?= (int) $pp['team'] ?>" style="width:55px"></td>
              <td><input type="number" min="0" name="<?= $n ?>[ai_calls]" value="<?= (int) $pp['ai_calls'] ?>" style="width:75px"></td>
              <?php foreach (['bot', 'meetings', 'cases', 'backup', 'public', 'trial'] as $fk): ?><td><input type="checkbox" name="<?= $n ?>[<?= $fk ?>]" value="1" <?= !empty($pp[$fk]) ? 'checked' : '' ?>></td><?php endforeach; ?>
              <td><?php if ($pid !== ''): ?><input type="checkbox" name="<?= $n ?>[del]" value="1"><?php endif; ?></td></tr>
          <?php endforeach; ?></table></div>
          <p class="muted">Prices are shown in <?= wi_e((string) (wi_sys('currency') ?: 'USD')) ?>. A client on a plan gets its limits when you choose the plan for them; their end date is extended with the renew buttons.</p>
          <div class="row"><button class="btn primary">💾 Save plans</button></div>
        </form></details>
      <dialog id="cl-dlg" class="dlg ux-dlg"><form method="post" class="stack pad">
        <input type="hidden" name="_csrf" value="<?= wi_e(wi_csrf()) ?>"><input type="hidden" name="do" value="client_save"><input type="hidden" name="f" value="<?= wi_e($cf) ?>"><input type="hidden" name="email" id="cl-email">
        <div class="ux-head"><h2 id="cl-title">Edit plan</h2><button type="button" class="btn ghost sm" data-close>✕</button></div>
        <div class="ux-g2"><label>Plan <small class="muted">(changing it applies its limits and features)</small><select name="plan_id" id="cl-pid"><option value="">Custom</option><?php foreach (wi_plans() as $pid => $pp): ?><option value="<?= wi_e((string) $pid) ?>"><?= wi_e((string) $pp['name']) ?></option><?php endforeach; ?></select></label>
          <label>Plan name<input name="plan" id="cl-plan" maxlength="40" placeholder="e.g. Basic, Pro" dir="auto"></label>
          <label>WhatsApp numbers<input type="number" name="max_numbers" id="cl-nums" min="0" max="100"></label>
          <label>Subdomain <small class="muted">(<?= wi_sys('saas_domain') ? 'name.' . wi_e((string) wi_sys('saas_domain')) : 'set the SaaS domain in Settings first' ?>)</small><input name="slug" id="cl-slug" maxlength="30" dir="ltr" placeholder="client1"></label>
          <label>Their own domains <small class="muted">(CNAME to this server, comma separated)</small><input name="domains" id="cl-doms" dir="ltr" placeholder="crm.client.com"></label>
          <label>Brand name on their address<input name="brand_name" id="cl-bn" maxlength="60" dir="auto"></label>
          <label>Logo (emoji)<input name="brand_logo" id="cl-bl" maxlength="8"></label>
          <label>AI calls a month on our keys <small class="muted">(when they have no keys of their own)</small><input type="number" name="ai_calls" id="cl-ai" min="0"></label>
          <label>Ends on <small class="muted">(empty = no end)</small><input type="date" name="expires" id="cl-exp"></label>
          <label>Team users (supervisors + employees)<input type="number" name="max_team" id="cl-team" min="0" max="500"></label>
          <label class="check" style="align-self:end"><input type="checkbox" name="active" value="1" id="cl-act"> Panel active</label></div>
        <div class="ux-feats"><?php foreach ($fx as $k => $f): ?><label class="ux-ft"><input type="checkbox" name="<?= $k ?>" value="1" data-cf="<?= $k ?>"><span class="ux-ri"><?= $f[0] ?></span><b><?= wi_e(wi_t($f[1])) ?></b></label><?php endforeach; ?></div>
        <label class="check"><input type="checkbox" name="allow_ruqyah" value="1" id="cl-ruq"> 🤲 Allow the spiritual (ruqyah) type of business <small class="muted">— never offered to clients unless you allow it here</small></label>
        <label>Internal note (only you see it)<textarea name="client_note" id="cl-note" rows="2" maxlength="500" dir="auto"></textarea></label>
        <div class="row"><button class="btn primary">💾 Save</button><button type="button" class="btn ghost" data-close>Cancel</button></div>
      </form></dialog>
      <script>document.addEventListener('DOMContentLoaded', function () { var d = document.getElementById('cl-dlg'), q = function (id) { return document.getElementById(id); };
        function syncFt() { [].forEach.call(d.querySelectorAll('.ux-ft'), function (l) { l.classList.toggle('on', l.querySelector('input').checked); }); }
        document.addEventListener('click', function (e) { var b = e.target.closest('[data-cl-edit]'); if (b) { var c = JSON.parse(b.getAttribute('data-cl-edit'));
            q('cl-email').value = c.email; q('cl-title').textContent = c.name; q('cl-plan').value = c.plan; q('cl-pid').value = c.plan_id || ''; q('cl-slug').value = c.slug || ''; q('cl-doms').value = c.domains || ''; q('cl-bn').value = c.brand_name || ''; q('cl-bl').value = c.brand_logo || ''; q('cl-nums').value = c.max_numbers; q('cl-ai').value = c.ai_calls; q('cl-exp').value = c.expires; q('cl-team').value = c.max_team; q('cl-note').value = c.note; q('cl-act').checked = c.active; q('cl-ruq').checked = !!c.allow_ruqyah;
            [].forEach.call(d.querySelectorAll('[data-cf]'), function (i) { i.checked = !!c[i.dataset.cf]; }); syncFt(); d.showModal(); }
          if (e.target.closest('#cl-dlg [data-close]')) d.close(); });
        d.addEventListener('change', syncFt);
        q('cl-q').addEventListener('input', function () { var v = this.value.trim().toLowerCase(); [].forEach.call(document.querySelectorAll('.cl-card'), function (c) { c.hidden = v !== '' && c.dataset.q.indexOf(v) === -1; }); }); });</script>
    <?php elseif ($page === 'me'): ?>
      <section class="hero"><div class="hero-t"><span class="hero-ic">👤</span><div><h1>My account</h1><p class="muted">Your name, your WhatsApp number for login codes, and your password.</p></div></div></section>
      <div class="card" style="max-width:560px"><form method="post" class="stack pad">
        <input type="hidden" name="_csrf" value="<?= wi_e(wi_csrf()) ?>"><input type="hidden" name="do" value="my_account">
        <label>Email<input value="<?= wi_e($me['email'] ?? '') ?>" disabled dir="ltr"></label>
        <label>Name<input name="name" value="<?= wi_e($me['name'] ?? '') ?>"></label>
        <label>WhatsApp number<input name="phone" value="<?= wi_e($me['phone'] ?? '') ?>" inputmode="tel" required dir="ltr"></label>
        <label>New password (leave empty to keep it)<input type="password" name="password" minlength="10" autocomplete="new-password" dir="ltr"></label>
        <label>Current password<input type="password" name="current" required autocomplete="current-password" dir="ltr"></label>
        <p class="muted small">Role: <b><?= wi_e(['super' => 'Super admin', 'admin' => 'Admin', 'manager' => 'Manager', 'client' => 'Client'][$me['role'] ?? ''] ?? 'Viewer') ?></b> · Numbers: <?= wi_e(implode(', ', array_map(fn($a) => $a['name'], wi_my_accounts())) ?: '—') ?></p>
        <div class="row"><button class="btn primary">Save</button></div>
      </form></div>
      <?php if (($me['role'] ?? '') !== 'super' && wi_owned_accounts((string) $me['email']) && empty($_SESSION['imp_from'])): // owners of a private number: their own Google and backup ?>
      <?php if (!empty($_GET['drive'])): ?><div class="alert ok" style="max-width:560px"><?= wi_e((string) $_GET['drive']) ?></div><?php endif; ?>
      <div class="card" style="max-width:560px" id="gg-card"><div class="card-h"><h2>🔗 Google account</h2><span class="badge" id="gg-badge">…</span></div>
        <div class="pad stack"><p class="muted small">Your own Google account for your panel: importing learning files from your Google Drive and creating Google Meet links on your calendar. Only your panel uses it.</p>
          <div id="gg-body" class="muted small">Loading…</div><div class="row" id="gg-act"></div></div></div>
      <?php if (wi_can_self_backup($me)): ?>
      <div class="card" style="max-width:560px" id="cb-card"><div class="card-h"><h2>💾 Backup to my Google Drive</h2><span class="badge" id="cb-badge">…</span></div>
        <div class="pad stack"><p class="muted small">All chats and data of your numbers (cases, notes, bot, learning, team) in one file in your own Google Drive. Connect your Google account above first.</p>
          <div class="row wrap"><label class="check"><input type="checkbox" id="cb-en"> Every day at</label><select id="cb-hour"><?php for ($h = 0; $h < 24; $h++): ?><option value="<?= $h ?>"><?= sprintf('%02d:00', $h) ?></option><?php endfor; ?></select>
            <label class="check"><input type="checkbox" id="cb-media"> Include photos and recordings</label><label>Keep the last <input type="number" id="cb-keep" min="1" max="30" style="width:64px"> copies</label></div>
          <div class="row"><button type="button" class="btn ghost sm" id="cb-save">💾 Save</button><button type="button" class="btn primary sm" id="cb-run">⬆️ Back up now</button></div>
          <div id="cb-last" class="muted small"></div></div></div>
      <script>document.addEventListener('DOMContentLoaded', function () { var T = window.T || function (s) { return s; }, q = function (id) { return document.getElementById(id); };
        function load() { WI.get('client_backup').then(function (r) { if (!r.ok) { q('cb-last').textContent = r.error || ''; return; } var c = r.cfg;
          q('cb-en').checked = !!c.enabled; q('cb-hour').value = c.hour; q('cb-media').checked = !!c.media; q('cb-keep').value = c.keep;
          q('cb-badge').textContent = r.running ? T('Running…') : (c.last ? (c.last.ok ? '✓ ' + c.last.at.slice(0, 16).replace('T', ' ') : '⚠️ ' + T('Failed')) : T('Never'));
          q('cb-last').textContent = c.last ? (c.last.ok ? c.last.name + ' · ' + Math.round(c.last.size / 1048576 * 10) / 10 + ' MB · ' + r.files.length + ' ' + T('copies on Drive') : c.last.error) : '';
          if (r.running) setTimeout(load, 15000); }); }
        q('cb-save').addEventListener('click', function () { WI.post('client_backup', { do: 'settings', enabled: q('cb-en').checked ? 1 : 0, hour: q('cb-hour').value, media: q('cb-media').checked ? 1 : 0, keep: q('cb-keep').value }).then(function (r) { alert(r.ok ? '✓ ' + T('Saved') : r.error); load(); }); });
        q('cb-run').addEventListener('click', function () { WI.post('client_backup', { do: 'run' }).then(function (r) { if (!r.ok) { alert(r.error); return; } alert(T('The backup started in the background. It takes a few minutes.')); load(); }); });
        load(); });</script>
      <?php endif; ?>
      <script>document.addEventListener('DOMContentLoaded', function () { var esc = function (s) { var d = document.createElement('div'); d.textContent = String(s == null ? '' : s); return d.innerHTML; }, T = window.T || function (s) { return s; };
        var b = document.getElementById('gg-body'), a = document.getElementById('gg-act'), g = document.getElementById('gg-badge');
        function load() { WI.get('my_google').then(function (r) { if (!r.ok) { b.textContent = r.error || ''; return; }
          g.textContent = r.connected ? T('Connected') : T('Not connected'); g.className = 'badge ' + (r.connected ? 'ok' : '');
          b.innerHTML = !r.ready ? T('Google sign-in is not set up on this installation yet. Ask the administrator.') : (r.connected ? '✅ ' + esc(r.email || '') + (r.missing && r.missing.length ? '<br>⚠️ ' + T('Some permissions were not given:') + ' ' + esc(r.missing.join(', ')) : '') : T('Not connected yet.'));
          a.innerHTML = !r.ready ? '' : (r.connected ? '<button type="button" class="btn ghost sm" data-gg="disconnect">' + T('Disconnect') + '</button>' : '<button type="button" class="btn primary sm" data-gg="connect">🔗 ' + T('Connect my Google account') + '</button>'); }); }
        a.addEventListener('click', function (e) { var x = e.target.closest('[data-gg]'); if (!x) return; if (x.dataset.gg === 'disconnect' && !confirm(T('Disconnect your Google account?'))) return;
          WI.post('my_google', { do: x.dataset.gg }).then(function (r) { if (!r.ok) { alert(r.error || T('Failed')); return; } if (r.url) location.href = r.url; else load(); }); });
        load(); });</script>
      <?php endif; ?>
    <?php elseif ($page === 'changes'): ?>
      <section class="hero"><div class="hero-t"><span class="hero-ic">🆕</span><div><h1>What's new</h1><p class="muted">Current version <b><?= wi_e($versions[0]['v'] ?? '') ?></b> · every update gets its own version.</p></div></div></section>
      <?php // Customers see the product's releases; the full development log only when Settings → System → changelog = full (super admin can switch here).
        $fullLog = wi_is_super() && (wi_sys('changelog') === 'full' ? ($_GET['view'] ?? '') !== 'product' : ($_GET['view'] ?? '') === 'full');
        if (!$fullLog) { $lg = wi_lang() === 'ar' ? 'ar' : 'en'; $versions = array_map(fn($x) => ['v' => $x['v'], 'date' => $x['date'], 'title' => $x[$lg][0], 'changes' => $x[$lg][1]], (array) require dirname(__DIR__) . '/version_product.php'); } ?>
      <?php if (wi_is_super()): ?><p class="muted small"><a href="?p=changes&amp;view=<?= $fullLog ? 'product' : 'full' ?>"><?= $fullLog ? 'Show the customers\' version' : 'Show the full development log' ?></a></p><?php endif; ?>
      <div class="timeline">
        <?php foreach ($versions as $i => $ver): ?>
          <article class="card tl-item<?= $i === 0 ? ' current' : '' ?>"><div class="tl-dot"></div>
            <div class="card-h"><h2><span class="tl-v"><?= wi_e($ver['v']) ?></span> <?= wi_e($ver['title']) ?></h2><small class="muted"><?= wi_e($ver['date']) ?><?= $i === 0 ? ' · ' . wi_e(wi_t('current')) : '' ?></small></div>
            <ul class="pad tl-list"><?php foreach ($ver['changes'] as $c): ?><li><?= wi_e($c) ?></li><?php endforeach; ?></ul>
          </article>
        <?php endforeach; ?>
      </div>

    <?php elseif (in_array($page, $fxPages, true)): ?>
      <?php if (!empty($_GET['drive'])): ?><div class="alert ok"><?= wi_e((string) $_GET['drive']) ?></div><?php endif; ?>
      <div id="fx-root" class="fx" data-fx="<?= wi_e($page) ?>" data-jid="<?= wi_e((string) ($_GET['jid'] ?? '')) ?>" data-phone="<?= wi_e((string) ($_GET['phone'] ?? '')) ?>" data-admin="<?= wi_is_admin() ? '1' : '' ?>"><div class="empty">Loading…</div></div>

    <?php elseif ($page === 'ai'): ?>
      <section class="hero"><div class="hero-t"><span class="hero-ic">🤖</span><div><h1>AI providers</h1><p class="muted">The AI services behind everything smart in the system: the bot's replies, learning, case analysis, summaries, the library and payment-receipt checks — tried in this order, the next one takes over when one is busy or out of quota.</p></div></div></section>
      <div class="card ai-mode"><b>How the providers work together</b>
        <div class="seg" id="ai-mode"><button data-m="sequential">🔁 One after another<small>In your order — the next one only if the first fails. Saves quota.</small></button><button data-m="parallel">⚡ All at once<small>Every active provider at the same moment — the fastest answer wins.</small></button></div>
        <span class="muted small" id="ai-mode-st"></span></div>
      <div id="ai-list" class="ai-list"><div class="empty">Loading…</div></div>

    <?php elseif ($page === 'media'): ?>
      <div id="ml-page" class="ml-page ml-v2">
        <div class="ml-shell">
          <nav class="ml-tree" id="ml-tree"></nav>
          <div class="ml-main">
            <header class="ml-top">
              <div class="ml-top-1">
                <div class="ml-title"><span class="ml-title-ic">🗂️</span><div><h1>Media library</h1><div class="ml-crumb" id="ml-crumb"></div></div></div>
                <span class="ml-tot" id="ml-tot"></span>
                <div class="ml-viewseg" role="group" aria-label="View">
                  <button type="button" data-mv="grid" title="Icons">▦</button><button type="button" data-mv="big" title="Large cards">🖼️</button><button type="button" data-mv="list" title="List">☰</button>
                </div>
                <button type="button" class="ml-tbtn" id="ml-det" title="Show / hide the details of each file">ℹ️ <span>Details</span></button>
                <select id="ml-view" hidden><option value="grid">Icons</option><option value="big">Large cards</option><option value="list">List</option></select>
              </div>
              <div class="ml-top-2">
                <div class="ml-search"><span>🔎</span><input type="search" id="ml-q" placeholder="Search title, caption, tags…" dir="auto"></div>
                <select id="ml-chat" title="Chat or group"><option value="">💬 All chats and groups</option></select>
                <label class="ml-date"><span>From</span><input type="date" id="ml-from"></label><label class="ml-date"><span>To</span><input type="date" id="ml-to"></label>
                <select id="ml-sort" title="Sort"><option value="new">🕐 Newest</option><option value="old">Oldest</option><option value="big">💾 Largest</option><option value="used">📤 Most sent</option></select>
              </div>
              <div class="ml-kinds" id="ml-k"></div>
            </header>
            <div class="ml-bulk" id="ml-bulk" hidden></div>
            <div class="ml-layout"><div id="ml-g"><div class="empty">Loading…</div></div><div class="ml-ed-bg" id="ml-ed-bg" hidden></div><aside class="ml-ed" id="ml-ed" hidden></aside></div>
          </div>
        </div>
      </div>
    <?php elseif ($page === 'files'): ?>
      <section class="hero"><div class="hero-t"><span class="hero-ic">📄</span><div><h1>Files</h1><p class="muted">PDFs, documents, photos and videos from the chats — each file once, with how many times it was shared.</p></div></div>
        <div class="hero-tools"><div class="search-xl"><span>🔎</span><input type="search" id="fl-q" placeholder="Search file names, captions, senders…" dir="auto"></div>
          <div class="seg" id="fl-kind"><button class="on" data-k="">All</button><button data-k="document">📄 Documents</button><button data-k="image">🖼️ Photos</button><button data-k="video">🎬 Videos</button></div></div></section>
      <div class="card"><div class="search-bar"><span class="muted small" id="fl-count"></span></div><div id="fl-grid" class="fl-grid"><div class="empty">Loading…</div></div></div>

    <?php elseif ($page === 'audio'): ?>
      <section class="hero">
        <div class="hero-t"><span class="hero-ic">🎧</span><div><h1>Audio library</h1><p class="muted">Every recording once — duplicates counted, not downloaded again. Rename, categorise and archive.</p></div></div>
        <div class="hero-tools">
          <div class="search-xl"><span>🔎</span><input type="search" id="au-q" placeholder="Search names, notes, senders…" dir="auto"></div>
          <select id="au-sort"><option value="copies">Most shared</option><option value="last">Newest</option><option value="long">Longest</option><option value="name">Name</option></select>
          <button type="button" class="chip-btn" id="au-cats"><span>🏷️</span> Manage categories</button>
        </div>
      </section>
      <section class="sec-tiles" id="au-tiles"></section>
      <div class="card">
        <div class="search-bar row wrap"><div class="seg" id="au-view"><button class="on" data-v="active">🎧 Library</button><button data-v="archived">🗄️ Archive</button></div>
          <span class="muted small" id="au-count"></span><span style="flex:1"></span>
          <span class="muted small" id="au-queue"></span>
          <div class="sel-bar inline" id="au-sel" hidden><b id="au-sel-n"></b><select id="au-sel-cat"><option value="">Set category…</option><?php foreach (wf_audio_cats() as $ac): ?><option value="<?= wi_e($ac['key']) ?>"><?= wi_e($ac['icon'] . ' ' . $ac['label']) ?></option><?php endforeach; ?></select><button class="btn ghost sm" id="au-sel-arch">🗄️ Archive</button><button class="btn ghost sm" id="au-sel-rest" hidden>↩️ Restore</button><button class="link-btn" id="au-sel-x">✕</button></div>
        </div>
        <div id="au-list" class="au-list"><div class="empty">Loading…</div></div>
      </div>

      <details class="au-statbox card pad"><summary><b><span>📊</span> Statistics</b> <span class="muted small">— space, listening time, duplicates, categories and months</span></summary>
      <section class="au-stats" id="au-stats"><div class="kpi skeleton"></div><div class="kpi skeleton"></div><div class="kpi skeleton"></div><div class="kpi skeleton"></div></section>
      <section class="grid2 au-break">
        <div class="card"><div class="card-h"><h2>📂 By category</h2></div><div class="pad" id="au-bycat"></div></div>
        <div class="card"><div class="card-h"><h2>📥 Download status</h2></div><div class="pad" id="au-bydl"></div></div>
      </section>
      <section class="grid2 au-break">
        <div class="card"><div class="card-h"><h2>🔁 Most repeated</h2></div><div id="au-top" class="people"></div></div>
        <div class="card"><div class="card-h"><h2>📅 By month</h2></div><div class="pad" id="au-month"></div></div>
      </section>
      </details>

    <?php elseif ($page === 'library'): ?>
      <section class="hero lib-hero">
        <div class="hero-t"><span class="hero-ic">📚</span><div><h1>Library</h1><p class="muted">Texts, audio and files collected from <b><?= wi_e(wi_t((string) ($cur['name'] ?? ''))) ?></b> — similar copies grouped, best version first.</p></div></div>
        <div class="hero-tools">
          <div class="search-xl"><span>🔎</span><input type="search" id="lib-q" placeholder="Search the library…" dir="auto"></div>
          <select id="lib-sort"><option value="shared">Most shared</option><option value="score">Best ranked</option><option value="recent">Newest</option><option value="versions">Most versions</option></select>
          <div class="seg" id="lib-view"><button class="on" data-v="cards" title="Cards">▦</button><button data-v="list" title="List">☰</button></div>
          <button type="button" class="btn primary" id="lib-add"><span>➕</span> Add text</button>
          <label class="btn ghost" id="lib-up-l"><span>⬆️</span> Upload files<input type="file" id="lib-up" multiple accept="image/jpeg,image/png,image/webp,audio/*,application/pdf,video/mp4" hidden></label>
          <a class="btn ghost" id="lib-export" href="#">⬇️ Export .txt</a>
        </div>
      </section>
      <section class="sec-tiles" id="sec-tiles"><div class="sec-tile skeleton"></div><div class="sec-tile skeleton"></div><div class="sec-tile skeleton"></div><div class="sec-tile skeleton"></div></section>
      <div class="lib-layout">
        <aside class="card lib-side"><div class="lib-side-h">Topics</div><div id="lib-topics" class="topic-list"></div>
          <div class="lib-side-h" style="margin-top:10px">Options</div>
          <label class="check small"><input type="checkbox" id="lib-short"> Show short messages</label>
          <label class="check small"><input type="checkbox" id="lib-archived"> 🗄️ Show archived only</label>
          <details class="lib-tools"><summary><span>🧰</span> Selection &amp; AI tools</summary><div class="lib-tools-b">
          <button type="button" class="chip-btn" id="lib-all">☑️ Select all</button><button type="button" class="chip-btn" id="lib-all-dupes">🧹 Select all duplicates</button><button type="button" class="chip-btn" id="lib-same">🟰 Select identical copies</button><button type="button" class="chip-btn" id="lib-best">🏆 Pick the best version (all groups)</button><span class="muted small" id="lib-best-st"></span><button type="button" class="chip-btn" id="lib-ai">🤖 Sort &amp; check with AI</button><span class="muted small" id="lib-ai-st"></span>
          </div></details>
          <div class="mq-box" id="mq-box"><div class="lib-side-h">🎧 Audio downloads</div><div id="mq-st" class="muted small">…</div><div class="up-bar"><span id="mq-bar" style="width:0%"></span></div><button class="chip-btn sm" id="mq-go">⬇️ Download all audio</button></div>
          <p class="muted small lib-note">Older voice notes were not kept as files. New audio is saved automatically (up to 64 MB); re-linking brings older chats back.</p></aside>
        <main><div class="sel-bar" id="sel-bar" hidden><b id="sel-n"></b><button class="btn ghost sm" id="sel-archive">🗄️ Archive</button><button class="btn danger sm" id="sel-delete">🗑️ Delete from library</button><button class="btn ghost sm" id="sel-restore" hidden>↩️ Restore</button><button class="link-btn" id="sel-clear">✕</button></div>
          <div class="lib-status muted small" id="lib-count"></div><div id="lib-grid" class="lib-grid"><div class="card empty">Loading…</div></div><div class="center" style="margin:14px 0"><button class="btn ghost" id="lib-more" hidden>Show more</button></div></main>
      </div>

    <?php elseif ($page === 'analytics'): ?>
      <section class="hero">
        <div class="hero-t"><span class="hero-ic">📈</span><div><h1>Analytics &amp; data</h1><p class="muted">Activity, reply speed and data found in the messages of <b><?= wi_e(wi_t((string) ($cur['name'] ?? ''))) ?></b>.</p></div></div>
        <div class="hero-tools"><div class="seg" id="an-days"><button data-d="7">7 days</button><button class="on" data-d="30">30 days</button><button data-d="90">90 days</button><button data-d="365">1 year</button></div></div>
      </section>
      <section class="kpis an-kpis" id="an-kpis"><div class="kpi skeleton"></div><div class="kpi skeleton"></div><div class="kpi skeleton"></div><div class="kpi skeleton"></div></section>
      <section class="an-row">
        <div class="card an-wide"><div class="card-h"><h2>📊 Daily activity</h2><div class="legend"><span class="dot in"></span>Received <span class="dot out"></span>Sent</div></div><div id="an-daily" class="an-daily"></div></div>
        <div class="card"><div class="card-h"><h2>⚡ Reply speed</h2></div><div id="an-speed" class="an-speed"></div></div>
      </section>
      <section class="an-row">
        <div class="card an-wide"><div class="card-h"><h2>🕐 Busy hours</h2><small class="muted"><?= wi_e(sprintf(wi_t('Local time (%s) · direct chats'), (string) wi_sys('timezone'))) ?></small></div><div class="pad" id="an-heat"></div></div>
        <div class="card"><div class="card-h"><h2>⏳ Waiting for your reply</h2><a class="link" id="an-wait-csv" href="#">⬇️ CSV</a></div><div id="an-wait" class="people"></div></div>
      </section>
      <section class="card an-data"><div class="card-h"><h2>🔍 Data found in messages</h2><div class="row"><div class="search-sm"><span>🔎</span><input type="search" id="an-q" placeholder="Filter…" dir="auto"></div><a class="btn ghost sm" id="an-csv" href="#">⬇️ Export CSV</a></div></div>
        <div class="tabs-line" id="an-ent"><button class="on" data-e="phones">📞 Numbers <i></i></button><button data-e="emails">✉️ Emails <i></i></button><button data-e="links">🔗 Links <i></i></button><button data-e="amounts">💰 Amounts <i></i></button><button data-e="refs">🧾 References <i></i></button></div>
        <div class="table-wrap"><table class="table zebra"><thead><tr><th>Value</th><th>Times</th><th>Chats</th><th>Last seen</th><th></th></tr></thead><tbody id="an-rows"></tbody></table></div>
      </section>
      <section class="an-row">
        <div class="card"><div class="card-h"><h2>👥 Most active</h2><a class="link" id="an-top-csv" href="#">⬇️ CSV</a></div><div id="an-top" class="people"></div></div>
        <div class="card an-wide"><div class="card-h"><h2>💬 Most used words (received)</h2></div><div class="pad words" id="an-words"></div></div>
      </section>

    <?php elseif ($page === 'search'): ?>
      <header class="head"><div><h1>Search messages</h1><p class="muted">Search all saved messages by text or number.</p></div></header>
      <div class="card"><div class="search-bar"><input type="search" id="sq" placeholder="Type a word or number…" autofocus dir="auto"></div><div id="results" class="results"><div class="empty">Type at least 2 characters.</div></div></div>

    <?php elseif ($page === 'link'): ?>
      <header class="head"><div><h1><?= wi_e(wi_t('Link / QR')) ?> — <?= wi_e(wi_t((string) ($cur['name'] ?? ''))) ?></h1><p class="muted">Connection status of this account, linking a new number, and pulling old chats.</p></div><a href="?p=accounts#add" class="btn primary">➕ Add another WhatsApp account</a></header>
      <div class="acc-tabs">
        <?php foreach (array_filter(wi_my_accounts(), 'wi_can_manage') as $a): ?>
          <form method="post" class="inline"><input type="hidden" name="_csrf" value="<?= wi_e(wi_csrf()) ?>"><input type="hidden" name="do" value="switch_account"><input type="hidden" name="acc" value="<?= wi_e($a['id']) ?>"><input type="hidden" name="back" value="?p=link">
            <button class="chip-btn <?= $cur && $cur['id'] === $a['id'] ? 'on' : '' ?>">📱 <?= wi_e($a['name']) ?></button></form>
        <?php endforeach; ?>
        <a href="?p=accounts#add" class="chip-btn add">➕ New account</a>
      </div>
      <section class="grid2">
        <div class="card"><div class="card-h"><h2>Status</h2><span class="badge" id="lk-badge">…</span></div>
          <div class="pad stack">
            <div id="lk-qr" class="qr-box"><div class="empty">Checking…</div></div>
            <div class="row" id="lk-actions"></div>
          </div>
        </div>
        <div class="card"><div class="card-h"><h2>Pull old chats</h2></div>
          <div class="pad stack">
            <p>WhatsApp sends old chats (archived ones included) to a linked device only once, when it is linked. So the gateway has to be linked again:</p>
            <ol class="steps">
              <li>Press <b>Re-link</b>. The bot stops until you scan the QR.</li>
              <li>On your phone: WhatsApp → Linked devices → remove the old «InvoiceTask Pro» device, then <b>Link a device</b>.</li>
              <li>Scan the QR shown here and keep WhatsApp open on the phone until syncing finishes.</li>
              <li>Chats appear under Conversations with their original dates. WhatsApp decides how much history it sends.</li>
            </ol>
            <p class="muted small">Changed your mind before scanning? «Restore previous session» brings the old connection back.</p>
          </div>
        </div>
      </section>

    <?php elseif ($page === 'accounts'): $accs = array_filter(wi_my_accounts(), 'wi_can_manage'); $owns = (bool) array_filter(wi_accounts(), fn($a) => !empty($a['owner']) && strtolower($a['owner']) === strtolower($me['email'])); ?>
      <header class="head"><div><h1>WhatsApp accounts</h1><p class="muted">Each account is its own WhatsApp number with its own gateway, archive and bot.</p></div><button class="btn ghost" data-reload-acc>Refresh ↻</button></header>
      <?php $mergeable = array_filter($accs, fn($x) => wi_is_super() || (!empty($x['owner']) && strtolower((string) $x['owner']) === strtolower((string) ($_SESSION['email'] ?? '')))); if (count($mergeable) > 1): ?>
      <details class="card pad" style="margin-bottom:12px"><summary><b>🔁 Move the chats of an old number to a new one</b> <small class="muted">— changed your number? the old chats, files, notes and cases come along (the old number keeps its own copy)</small></summary>
        <form method="post" class="row" style="margin-top:10px;flex-wrap:wrap;gap:8px" onsubmit="return confirm(this.dataset.q)" data-q="<?= wi_e(wi_t('Add the history of the old number to the new number? This is done once.')) ?>"><input type="hidden" name="_csrf" value="<?= wi_e(wi_csrf()) ?>"><input type="hidden" name="do" value="merge_numbers">
          <label>From (old number) <select name="from"><?php foreach ($mergeable as $x): ?><option value="<?= wi_e($x['id']) ?>"><?= wi_e((string) $x['name']) ?></option><?php endforeach; ?></select></label>
          <label>To (new number) <select name="to"><?php foreach ($mergeable as $x): ?><option value="<?= wi_e($x['id']) ?>"><?= wi_e((string) $x['name']) ?></option><?php endforeach; ?></select></label>
          <button class="btn primary">🔁 Move the chats</button></form></details>
      <?php endif; ?>
      <section class="acc-grid" id="acc-grid">
        <?php foreach ($accs as $a): if (!wf_fb_is($a)) continue; $fc = wf_fb_cfg((string) $a['id']); ?>
        <form method="post" class="card acc fb-card" autocomplete="off">
          <input type="hidden" name="_csrf" value="<?= wi_e(wi_csrf()) ?>"><input type="hidden" name="do" value="fb_connect"><input type="hidden" name="acc" value="<?= wi_e($a['id']) ?>">
          <div class="card-h"><h2>💬 <?= wi_e((string) $a['name']) ?> — Facebook <?= $fc['error'] === '' && $fc['ok_at'] !== '' ? '✅' : '⚠️' ?></h2></div>
          <div class="pad stack">
            <?php if ($fc['error'] !== ''): ?><p class="muted small"><?= wi_e($fc['error']) ?></p><?php endif; ?>
            <?php if ($fc['via'] === 'oauth'): ?><p class="muted small">Connected through the Facebook app — the app's webhook address (above) serves this page.</p><a class="btn ghost sm" href="?fb_connect=1">Reconnect with Facebook</a>
            <?php else: ?>
            <label>Webhook address<input readonly value="<?= wi_e(wf_fb_hook_url((string) $a['id'])) ?>" onclick="this.select()"></label>
            <label>Verify code<input readonly value="<?= wi_e($fc['verify']) ?>" onclick="this.select()"></label>
            <?php endif; ?>
            <p class="muted small">Page: <?= wi_e($fc['page_name'] !== '' ? $fc['page_name'] : $fc['page_id']) ?> · last message from Facebook: <?= wi_e($fc['hook_at'] !== '' ? $fc['hook_at'] : '—') ?></p>
            <label>New page access token (optional)<input name="token" type="password"></label>
            <label>New app secret (optional)<input name="secret" type="password"></label>
            <button class="btn primary sm">Check the connection</button>
          </div>
        </form>
        <?php endforeach; ?>
        <?php foreach ($accs as $a): ?>
        <form method="post" class="card acc" data-acc="<?= wi_e($a['id']) ?>">
          <input type="hidden" name="_csrf" value="<?= wi_e(wi_csrf()) ?>"><input type="hidden" name="do" value="update_account"><input type="hidden" name="acc" value="<?= wi_e($a['id']) ?>">
          <div class="card-h"><div class="acc-title"><span class="av" style="background:#10b981"><?= wi_e(mb_strtoupper(mb_substr($a['name'], 0, 1))) ?></span><div><input class="acc-name" name="name" value="<?= wi_e($a['name']) ?>" maxlength="60"><small class="muted" data-num>…</small></div></div><span class="badge" data-state>…</span></div>
          <div class="pad stack">
            <div class="acc-stats"><div><b data-msgs>–</b><small>messages</small></div><div><b data-chats>–</b><small>chats</small></div><div><b><?= (int) $a['port'] ?></b><small>port</small></div></div>
            <?php if (empty($a['main'])): ?>
              <label class="check"><input type="checkbox" name="enabled" value="1" <?= !empty($a['enabled']) ? 'checked' : '' ?>> Account running</label>
              <label class="check"><input type="checkbox" name="payments" value="1" <?= !empty($a['payments']) ? 'checked' : '' ?> <?= wi_is_super() ? '' : 'disabled' ?>> Payment bot (receipts + your approval)</label>
              <a class="chip-btn" href="?p=bot&amp;tab=run&amp;acc=<?= wi_e($a['id']) ?>">🤖 Bot: <?= wi_e(wi_t(['off' => 'OFF', 'draft' => 'Suggest only', 'auto' => 'Automatic'][(wi_read('bot.json', [])[$a['id']]['run']['mode'] ?? 'off')] ?? 'OFF')) ?></a>
            <?php else: ?>
              <input type="hidden" name="payments_form" value="1">
              <label class="check"><input type="checkbox" name="payments" value="1" <?= !empty($a['payments']) ? 'checked' : '' ?> <?= wi_is_super() ? '' : 'disabled' ?>> Payment bot (receipts + your approval)</label>
              <a class="chip-btn" href="?p=bot&amp;tab=run&amp;acc=main">🤖 Bot: <?= wi_e(wi_t(['off' => 'OFF', 'draft' => 'Suggest only', 'auto' => 'Automatic'][(wi_read('bot.json', [])['main']['run']['mode'] ?? 'off')] ?? 'OFF')) ?></a>
            <?php endif; ?>
            <div class="row wrap">
              <button class="btn primary sm">Save</button>
              <button class="btn ghost sm" type="submit" form="sw-<?= wi_e($a['id']) ?>">Open</button>
              <?php if (empty($a['main']) && (wi_is_super() || strtolower((string) ($a['owner'] ?? '')) !== strtolower((string) $_SESSION['email']))): // a user's own number is re-linked, not deleted ?><button class="btn danger sm" type="submit" form="del-<?= wi_e($a['id']) ?>" onclick="return confirm('Remove this account? Its gateway stops; its archive stays on the server.')">Remove</button><?php endif; ?>
            </div>
          </div>
        </form>
        <form method="post" id="sw-<?= wi_e($a['id']) ?>" hidden><input type="hidden" name="_csrf" value="<?= wi_e(wi_csrf()) ?>"><input type="hidden" name="do" value="switch_account"><input type="hidden" name="acc" value="<?= wi_e($a['id']) ?>"><input type="hidden" name="back" value="?p=link"></form>
        <form method="post" id="del-<?= wi_e($a['id']) ?>" hidden><input type="hidden" name="_csrf" value="<?= wi_e(wi_csrf()) ?>"><input type="hidden" name="do" value="delete_account"><input type="hidden" name="acc" value="<?= wi_e($a['id']) ?>"></form>
        <?php endforeach; ?>
        <?php if (wi_is_admin() || (wi_can_link_own() && !$owns)): ?>
        <form method="post" class="card acc add" id="add">
          <input type="hidden" name="_csrf" value="<?= wi_e(wi_csrf()) ?>"><input type="hidden" name="do" value="add_account">
          <div class="card-h"><h2>➕ Add WhatsApp account</h2></div>
          <div class="pad stack">
            <label>Name<input name="name" required maxlength="60" placeholder="e.g. Sales number"></label>
            <?php if (wi_is_super()): ?><label class="check"><input type="checkbox" name="payments" value="1"> Payment bot (receipts + your approval)</label><?php endif; ?>
            <p class="muted small">After adding, the QR appears on the Link page. Scan it from the new phone: WhatsApp → Linked devices → Link a device.</p>
            <button class="btn primary">Add account</button>
          </div>
        </form>
        <?php endif; ?>
        <?php if (wi_is_admin() || (wi_can_link_own() && !$owns)): ?>
        <?php if (wi_is_admin() || wi_can_link_own()):
          $fbScope = wi_is_super() ? '' : strtolower((string) $_SESSION['email']); $fbOwn = wf_fb_app($fbScope); $fbSys = $fbScope !== '' ? wf_fb_app('') : $fbOwn; $fbReady = ($fbOwn['app_id'] !== '' && $fbOwn['secret'] !== '') || ($fbScope !== '' && $fbSys['app_id'] !== '');
          $fbPk = (string) ($_GET['fb_pick'] ?? ''); $fbPend = $fbPk !== '' ? wf_fb_pending($fbPk) : null;
          $fbCopy = function (string $label, string $val) { return '<div class="fb-copy"><span class="fb-copy-l">' . wi_e($label) . '</span><div class="fb-copy-r"><input readonly dir="ltr" value="' . wi_e($val) . '" onclick="this.select()"><button type="button" class="btn ghost sm" onclick="var i=this.previousElementSibling;i.select();(navigator.clipboard?navigator.clipboard.writeText(i.value):Promise.reject()).catch(function(){document.execCommand(\'copy\')});this.textContent=\'✓\'">Copy</button></div></div>'; }; ?>
        <section class="card acc fb-hub" id="fb-hub">
          <div class="card-h"><h2><span>📘</span> <span>Facebook pages</span></h2><span class="fb-state <?= $fbReady ? 'on' : '' ?>"><?= $fbReady ? 'App ready' : 'App not set' ?></span></div>
          <?php if ($fbPend): ?>
          <form method="post" class="fb-pick" id="fb-pick">
            <input type="hidden" name="_csrf" value="<?= wi_e(wi_csrf()) ?>"><input type="hidden" name="do" value="fb_pick_add"><input type="hidden" name="pk" value="<?= wi_e($fbPk) ?>">
            <h3>Choose the Facebook pages to connect</h3>
            <div class="fb-pick-list">
            <?php foreach ($fbPend['pages'] as $fx): $fbHave = wf_fb_account_for_page($fx['id']); ?>
              <label class="fb-pick-i"><input type="checkbox" name="pages[]" value="<?= wi_e($fx['id']) ?>" checked><b dir="auto"><?= wi_e($fx['name']) ?></b><?php if ($fbHave): ?><small class="muted">already linked (its token is renewed)</small><?php endif; ?></label>
            <?php endforeach; ?>
            </div>
            <button class="btn primary">Connect the chosen pages</button>
          </form>
          <?php endif; ?>
          <?php $fbPages = array_filter(wi_my_accounts(), 'wf_fb_is'); ?>
          <div class="fb-main">
            <div class="fb-main-t"><b>Sign in with Facebook and choose your pages</b><p class="muted small">One button: Facebook asks you to sign in and to pick the pages; their messages, comments, posts and numbers come in by themselves. Nothing to copy or type.</p></div>
            <?php if ($fbReady): ?><a class="btn fb-btn" href="?fb_connect=1"><span>f</span> <span>Continue with Facebook</span></a>
            <?php else: ?><button class="btn fb-btn" disabled><span>f</span> <span>Continue with Facebook</span></button><?php endif; ?>
          </div>
          <?php if (!$fbReady): ?><p class="fb-note muted small"><?= wi_is_super() ? 'Set up the Facebook app once below; after that everyone connects with the button.' : 'The Facebook sign-in is not set up yet. Ask the administrator, or set up an app of your own below.' ?></p><?php endif; ?>
          <?php if ($fbPages): ?><div class="fb-linked"><?php foreach ($fbPages as $fpa): $fpc = wf_fb_cfg((string) $fpa['id']); ?><span class="fb-chip <?= $fpc['error'] === '' ? 'ok' : 'bad' ?>" title="<?= wi_e($fpc['error']) ?>"><?= $fpc['error'] === '' ? '●' : '!' ?> <?= wi_e((string) $fpa['name']) ?></span><?php endforeach; ?></div><?php endif; ?>
          <details class="fb-setup" <?= !$fbReady && (wi_is_super() || $fbOwn['app_id'] !== '') ? 'open' : '' ?>>
            <summary><?= wi_is_super() ? 'Facebook app settings (once, by the administrator)' : 'Use an app of your own (optional)' ?></summary>
          <div class="fb-steps">
            <form method="post" class="fb-step" autocomplete="off">
              <input type="hidden" name="_csrf" value="<?= wi_e(wi_csrf()) ?>"><input type="hidden" name="do" value="fb_app_save">
              <h3><span class="fb-n">1</span> <span>The Facebook app</span></h3>
              <p class="muted small"><?= $fbScope !== '' && $fbSys['app_id'] !== '' && $fbOwn['app_id'] === '' ? 'Optional: the system\'s app is used unless you save your own.' : 'From developers.facebook.com → your app → Settings → Basic.' ?></p>
              <label>App ID<input name="fb_appid" inputmode="numeric" pattern="[0-9]*" dir="ltr" autocomplete="off" data-lpignore="true" data-1p-ignore value="<?= wi_e($fbOwn['app_id']) ?>"></label>
              <label>App secret<input name="fb_appsecret" type="password" dir="ltr" autocomplete="new-password" data-lpignore="true" data-1p-ignore placeholder="<?= $fbOwn['secret'] !== '' ? '••••••••  saved — leave empty to keep it' : '' ?>"></label>
              <button class="btn ghost sm">Save the app</button>
            </form>
            <div class="fb-step">
              <h3><span class="fb-n">2</span> <span>Paste these in the app</span></h3>
              <?php if ($fbOwn['app_id'] !== ''): ?>
                <?= $fbCopy('Valid OAuth Redirect URI (Facebook Login → Settings)', wf_fb_redirect_uri()) ?>
                <?= $fbCopy('Webhooks → Page: callback URL', wf_fb_app_hook_url($fbOwn)) ?>
                <?= $fbCopy('Verify token', $fbOwn['verify']) ?>
                <?= $fbCopy('Deauthorize callback URL', wf_fb_base_url() . 'index.php?fb_deauth=1') ?>
                <?= $fbCopy('Data deletion request URL', wf_fb_base_url() . 'index.php?fb_delete=1') ?>
                <p class="muted small">Webhook fields: messages, messaging_postbacks, message_echoes, message_reads, message_reactions, feed</p>
              <?php else: ?><p class="muted small"><?= $fbScope !== '' && $fbSys['app_id'] !== '' ? 'Nothing to paste: the system\'s app is already set up.' : 'Save the app first: the addresses appear here.' ?></p><?php endif; ?>
            </div>
          </div>
          </details>
          <details class="fb-manual">
            <summary>Advanced: add a page by hand (page token and app secret)</summary>
            <form method="post" class="fb-manual-f" autocomplete="off">
              <input type="hidden" name="_csrf" value="<?= wi_e(wi_csrf()) ?>"><input type="hidden" name="do" value="add_fb">
              <label>Name<input name="name" required maxlength="60" autocomplete="off"></label>
              <label>Page access token<input name="token" type="password" dir="ltr" required autocomplete="new-password" data-lpignore="true" data-1p-ignore></label>
              <label>App secret<input name="secret" type="password" dir="ltr" required autocomplete="new-password" data-lpignore="true" data-1p-ignore></label>
              <label>Page ID (optional)<input name="fb_pageid" inputmode="numeric" pattern="[0-9]*" dir="ltr" autocomplete="off" data-lpignore="true" data-1p-ignore></label>
              <p class="muted small">After adding, the page's card shows its own webhook address and verify token to paste in the app.</p>
              <button class="btn primary sm">Add page</button>
            </form>
          </details>
        </section>
        <?php endif; ?>
        <?php endif; ?>
      </section>

    <?php elseif ($page === 'broadcast'): ?>
      <header class="head"><div><h1>Broadcast — <?= wi_e(wi_t((string) ($cur['name'] ?? ''))) ?></h1><p class="muted">Send one message to a list of people who already chat with this number. Sent slowly to protect the number.</p></div></header>
      <div class="safe-box">🛡️ Anti-ban rules: only numbers that already have a chat with this account · one message every 30–60 seconds (random) · max 60 broadcast messages per day per number · typing before each · stops automatically after 3 failures. Use <b>{name}</b> to insert each person's name.</div>
      <section class="grid2 bc">
        <div class="card"><div class="card-h"><h2>📋 Lists</h2><button class="btn ghost sm" id="bc-new">➕ New list</button></div><div id="bc-lists" class="pad stack"><div class="empty">Loading…</div></div></div>
        <div class="card"><div class="card-h"><h2>✉️ Send</h2></div>
          <div class="pad stack">
            <label>List<select id="bc-list"></select></label>
            <label>Message<textarea id="bc-text" rows="6" maxlength="2000" dir="auto" placeholder="Hello {name}, …"></textarea></label>
            <div class="muted small" id="bc-est"></div>
            <button class="btn primary" id="bc-send">📣 Start broadcast</button>
          </div>
        </div>
      </section>
      <section class="card"><div class="card-h"><h2>📊 Broadcasts</h2><span class="badge" id="bc-today">…</span></div><div id="bc-jobs" class="pad"><div class="empty">Loading…</div></div></section>
      <dialog id="bc-dlg" class="dlg"><form method="dialog" class="stack pad">
        <h2 id="bc-dlg-t">New list</h2>
        <label>List name<input id="bc-name" maxlength="80" required></label>
        <div class="row"><input type="search" id="bc-q" placeholder="Search name or number…" dir="auto"><div class="chips" id="bc-f"><button type="button" class="on" data-f="">All</button><button type="button" data-f="customers">Customers</button><button type="button" data-f="others">Others</button></div></div>
        <div class="row"><button type="button" class="btn ghost sm" id="bc-all">Select all shown</button><button type="button" class="btn ghost sm" id="bc-none">Clear</button><span class="muted small" id="bc-count"></span></div>
        <div class="bc-pick" id="bc-pick"></div>
        <div class="row"><button class="btn primary" value="save" id="bc-save">Save list</button><button class="btn ghost" value="cancel">Cancel</button></div>
      </form></dialog>

    <?php elseif ($page === 'gateway'): ?>
      <header class="head"><div><h1>Gateway &amp; Guard</h1><p class="muted">Live status of every number's gateway, restart controls, and the Guard that keeps them running.</p></div><button class="btn ghost" data-reload-gw>Refresh ↻</button></header>
      <section class="card guard-card"><div class="card-h"><h2>🛡️ <?= htmlspecialchars((string) wi_sys('device_name')) ?></h2><span class="badge ok" id="guard-state">…</span></div>
        <div class="pad guard-grid">
          <div><small>Auto-restart</small><b>Every minute</b></div>
          <div><small>Main number — last check</small><b id="g-main">…</b></div>
          <div><small>Other numbers — last check</small><b id="g-acc">…</b></div>
          <div><small>Auto-reconnect</small><b>On (backoff up to 60 s)</b></div>
        </div>
        <p class="pad muted small" style="padding-top:0">If a gateway stops, the Guard starts it again within a minute. If WhatsApp disconnects, the gateway reconnects by itself; you get a WhatsApp alert if it stays down.</p>
      </section>
      <section class="gw-grid" id="gw-grid"><div class="card empty">Loading…</div></section>

        <?php else: $users = array_values(array_filter(wi_users(), 'wi_user_in_scope'));
          $roleInfo = ['client' => ['🏪', 'Client', 'Their own number only, like a super admin of it. No users, no system settings.'], 'manager' => ['🧑‍💼', 'Manager', 'Works on all pages of the numbers you give them. No users, no technical pages.'],
            'viewer' => ['👁', 'Viewer', 'Only the pages you choose, view or edit.'], 'admin' => ['🛡️', 'Admin', 'Runs their numbers and adds their own staff.'], 'super' => ['👑', 'Super admin', 'Everything: all numbers, users and system settings.'],
            'lead' => ['🧭', 'Supervisor', 'Wide permissions on your panel: all chats, assigning, and deleting when you allow it.'], 'agent' => ['🎧', 'Employee', 'Their own chats and the ones nobody took yet. Never deletes chats or messages.']];
          $isClient = ($me['role'] ?? '') === 'client'; $teamDefs = $isClient ? wi_team_roles((string) $me['email']) : [];
          $roles = wi_is_super() ? ['client', 'manager', 'viewer', 'admin', 'super'] : ($isClient ? ['lead', 'agent'] : ['manager', 'viewer']);
          $chatInfo = ['reply' => ['💬', 'Reply in chats'], 'media' => ['📎', 'Send files, photos and voice'], 'notes' => ['📝', 'Notes and follow-up reminders'], 'labels' => ['🏷️', 'Labels'],
            'assign' => ['👤', 'Assign chats to others'], 'quick' => ['⚡', 'Edit quick replies'], 'export' => ['⬇️', 'Export chats'], 'groups' => ['👥', 'Add people to groups'], 'invoices' => ['🧾', 'Send invoices'], 'delete_msg' => ['🗑️', 'Delete messages'], 'delete_chat' => ['🧹', 'Delete chats']];
          unset($chatInfo['invoices']); // invoices belong to the operator's system, never to a client's team
          $pgAllowed = fn($k) => !$isClient || (!in_array($k, WI_TEAM_NEVER, true) && wi_client_page_perm($me, $k) !== '');
          $pgGroups = ['Chats' => ['inbox', 'search', 'customer', 'numbers'], 'Groups & sending' => ['groups', 'broadcast', 'status', 'schedule', 'programs', 'meetings'], 'Content' => ['library', 'audio', 'files', 'analytics', 'cases'],
            'Bot' => ['botrun', 'botgroup', 'bot', 'learning'], 'Admin pages' => ['accounts', 'health', 'report', 'backup', 'ai', 'settings']];
          $feat = ['can_bot' => ['🤖', 'Bot', 'The bot, learning and their own AI keys'], 'can_meetings' => ['🎥', 'Meetings', 'Meeting links for their customers'], 'can_cases' => ['🗂️', 'Cases', 'Case records of their number'], 'can_backup' => ['💾', 'Backup', 'Export their chats and data']]; ?>
      <section class="hero"><div class="hero-t"><span class="hero-ic">👥</span><div><h1><?= $isClient ? 'My team' : 'Users &amp; permissions' ?></h1><p class="muted"><?= $isClient ? 'Supervisors and employees of your panel. Each one signs in with their own password and a code sent to their own WhatsApp, and sees only what you allow.' : 'Each user signs in with their own password and a code sent to their own WhatsApp.' ?></p></div></div>
        <?php if ($isClient): $seats = wi_team_seats($me); $usedSeats = count($users); ?><span class="ux-chip"><?= wi_e(wi_t('Team users')) ?> <b><?= $usedSeats ?> / <?= $seats ?></b></span><?php endif; ?>
        <?php if (!$isClient || $usedSeats < $seats): ?><button type="button" class="btn primary" id="uf-new">➕ Add user</button><?php else: ?><span class="muted small">Your plan is full. Ask the administrator for more users.</span><?php endif; ?></section>
      <?php if ($isClient): $uTab = ($_GET['tab'] ?? '') === 'activity' ? 'activity' : 'team'; ?>
      <div class="page-tabs sub"><a href="?p=users" class="<?= $uTab === 'team' ? 'on' : '' ?>">👥 Team</a><a href="?p=users&amp;tab=activity" class="<?= $uTab === 'activity' ? 'on' : '' ?>">📜 Team activity</a></div>
      <?php if ($uTab === 'activity'): $who = strtolower(trim((string) ($_GET['who'] ?? ''))); $acts = wi_team_activity((string) $me['email'], 200, $who);
        $names = [strtolower((string) $me['email']) => (($me['name'] ?? '') ?: $me['email'])]; foreach ($users as $x) $names[strtolower((string) $x['email'])] = ($x['name'] ?? '') ?: $x['email'];
        $actInfo = ['chat_send' => ['💬', 'Replied'], 'chat_send_media' => ['📎', 'Sent a file'], 'msg_delete' => ['🗑️', 'Deleted a message'], 'chat_delete' => ['🧹', 'Deleted a chat'], 'assign' => ['👤', 'Assigned a chat'],
            'note' => ['📝', 'Wrote a note'], 'chat_export' => ['⬇️', 'Exported a chat'], 'login' => ['🔓', 'Signed in'], 'logout' => ['🔒', 'Signed out'], 'login_failed' => ['⚠️', 'Failed sign-in'], 'suggest_reply' => ['✨', 'Asked AI for a reply'],
            'summarize' => ['🧠', 'Summarised chats'], 'team_add' => ['➕', 'Added a team user'], 'team_update' => ['✏️', 'Changed a team user'], 'team_delete' => ['➖', 'Deleted a team user'], 'team_roles' => ['⭐', 'Changed role defaults'],
            'case_new' => ['🗂️', 'Opened a case'], 'case_set' => ['🗂️', 'Updated a case'], 'broadcast_start' => ['📣', 'Started a broadcast'], 'schedule_add' => ['⏰', 'Scheduled a message']]; ?>
      <section class="card"><div class="card-h"><h2>📜 Team activity</h2>
        <form method="get" class="row"><input type="hidden" name="p" value="users"><input type="hidden" name="tab" value="activity"><select name="who" onchange="this.form.submit()"><option value="">Everyone</option>
          <?php foreach ($names as $e => $n): ?><option value="<?= wi_e($e) ?>" <?= $who === $e ? 'selected' : '' ?>><?= wi_e($n) ?></option><?php endforeach; ?></select></form></div>
        <?php if (!$acts): ?><div class="empty">No activity yet.</div><?php else: ?><div class="table-wrap"><table class="tbl"><thead><tr><th>When</th><th>Who</th><th>What</th><th>Chat / user</th></tr></thead><tbody>
          <?php foreach ($acts as $x): $ai = $actInfo[$x['action']] ?? ['•', $x['action']]; ?><tr><td dir="ltr" class="muted small"><?= wi_e(substr((string) $x['at'], 0, 16)) ?></td><td><?= wi_e($names[strtolower($x['user'])] ?? $x['user']) ?></td><td><?= $ai[0] ?> <?= wi_e(wi_t($ai[1])) ?></td>
            <td dir="ltr" class="small"><?php if ($x['jid'] !== ''): ?><a href="?p=inbox&amp;acc=<?= wi_e($x['account']) ?>&amp;jid=<?= wi_e($x['jid']) ?>">+<?= wi_e(explode('@', $x['jid'])[0]) ?></a><?php else: ?><?= wi_e($names[strtolower($x['target'])] ?? $x['target']) ?><?php endif; ?></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?></section>
      <?php endif; endif; ?>
      <?php if (!$isClient || $uTab === 'team'): ?>
      <div class="ux-roles"><?php foreach ($roles as $r): $n = count(array_filter($users, fn($u) => ($u['role'] ?? 'viewer') === $r)); ?><span class="ux-chip r-<?= $r ?>"><?= $roleInfo[$r][0] ?> <?= wi_e($roleInfo[$r][1]) ?> <b><?= $n ?></b></span><?php endforeach; ?></div>
      <div class="ux-list">
      <?php foreach ($users as $u): $r = $u['role'] ?? 'viewer'; $accs = array_map(fn($id) => wi_accounts()[$id]['name'] ?? $id, (array) ($u['accounts'] ?? []));
            foreach (wi_accounts() as $a) if (strtolower((string) ($a['owner'] ?? '')) === strtolower((string) $u['email'])) $accs[] = '📱 ' . $a['name']; ?>
        <article class="card ux-card r-<?= wi_e($r) ?><?= empty($u['active']) ? ' off' : '' ?>">
          <div class="ux-top"><span class="avatar"><?= wi_e(mb_strtoupper(mb_substr(($u['name'] ?? '') ?: $u['email'], 0, 1))) ?></span>
            <div class="ux-who"><b dir="auto"><?= wi_e(($u['name'] ?? '') ?: $u['email']) ?></b><small dir="ltr"><?= wi_e($u['email']) ?> · +<?= wi_e($u['phone']) ?></small></div>
            <span class="ux-role"><?= $roleInfo[$r][0] ?? '' ?> <?= wi_e($roleInfo[$r][1] ?? $r) ?></span></div>
          <div class="ux-line"><span class="muted">Numbers</span> <?= $r === 'super' ? 'All' : (wi_e(implode(' · ', array_unique($accs))) ?: ($r === 'client' ? '<span class="muted">links their own after signing in</span>' : '—')) ?></div>
          <?php if ($r === 'client'): ?><div class="ux-tags"><?php foreach ($feat as $k => $fx): ?><span class="<?= !empty($u[$k]) ? 'on' : '' ?>"><?= $fx[0] ?> <?= wi_e($fx[1]) ?></span><?php endforeach; ?></div>
          <?php elseif (in_array($r, WI_TEAM_ROLES, true)): $pc = count(array_filter((array) ($u['pages'] ?? []))); $cp = (array) ($u['chat'] ?? []); ?>
            <?php if (!$isClient): ?><div class="ux-line"><span class="muted">Team of</span> <?= wi_e((string) ($u['tenant'] ?? '')) ?></div><?php endif; ?>
            <div class="ux-line"><span class="muted">Pages</span> <?= $pc ?></div>
            <div class="ux-tags"><?php foreach ($chatInfo as $k => $ci): ?><span class="<?= !empty($cp[$k]) ? 'on' : '' ?>"><?= $ci[0] ?> <?= wi_e($ci[1]) ?></span><?php endforeach; ?></div>
          <?php elseif ($r === 'viewer'): $pc = count(array_filter((array) ($u['pages'] ?? []))); ?><div class="ux-line"><span class="muted">Pages</span> <?= $pc ?><?= !empty($u['cases_only']) ? ' · <span class="muted">Cases only</span>' : '' ?></div><?php endif; ?>
          <div class="ux-act">
            <?php if (!$isClient && in_array($r, WI_TEAM_ROLES, true)): ?><span class="muted small">Managed by their client</span><?php else: ?><button class="btn ghost sm" data-edit="<?= wi_e($u['email']) ?>" data-name="<?= wi_e($u['name'] ?? '') ?>" data-phone="<?= wi_e($u['phone']) ?>" data-cname="<?= wi_e((string) ($u['chat_name'] ?? '')) ?>" data-cprefix="<?= wf_staff_cfg($u)['prefix'] ? '1' : '' ?>" data-cwelcome="<?= wf_staff_cfg($u)['welcome'] ? '1' : '' ?>" data-role="<?= wi_e($r) ?>" data-accounts="<?= wi_e(implode(',', (array) ($u['accounts'] ?? []))) ?>" data-flags="<?= wi_e(json_encode(['can_reply' => !empty($u['can_reply']), 'can_link' => !empty($u['can_link']), 'can_share' => !empty($u['can_share']), 'cases_only' => !empty($u['cases_only']), 'can_bot' => !empty($u['can_bot']), 'can_meetings' => !empty($u['can_meetings']), 'can_cases' => !empty($u['can_cases']), 'can_backup' => !empty($u['can_backup']), 'own' => !empty($u['own_number']), 'active' => !empty($u['active'])])) ?>" data-max-team="<?= (int) ($u['max_team'] ?? 0) ?>" data-chat="<?= wi_e(json_encode((object) ($u['chat'] ?? []))) ?>" data-pages="<?= wi_e(json_encode($u['pages'] ?? null)) ?>">✏️ Edit</button><?php endif; ?>
            <?php if (wi_is_super() && $r !== 'super' && empty($_SESSION['imp_from'])): ?><form method="post" class="inline"><input type="hidden" name="_csrf" value="<?= wi_e(wi_csrf()) ?>"><input type="hidden" name="do" value="impersonate"><input type="hidden" name="email" value="<?= wi_e($u['email']) ?>"><button class="btn ghost sm" title="See the panel as this user">👁 Sign in as</button></form><?php endif; ?>
            <?php if (strtolower($u['email']) !== strtolower($me['email'])): ?><form method="post" class="inline" onsubmit="return confirm('Delete this user?')"><input type="hidden" name="_csrf" value="<?= wi_e(wi_csrf()) ?>"><input type="hidden" name="do" value="delete_user"><input type="hidden" name="email" value="<?= wi_e($u['email']) ?>"><button class="btn ghost sm bad-t" title="Delete this user"><span>🗑</span> Delete</button></form><?php endif; ?>
            <?php if ($r === 'viewer' && in_array($me['role'] ?? '', ['super', 'admin'], true) && empty($_SESSION['imp_from'])): $own = []; foreach (wi_accounts() as $oa) { $oe = strtolower((string) ($oa['owner'] ?? '')); $ou = $oe !== '' ? wi_find_user($oe) : null; if ($ou && in_array($ou['role'] ?? '', ['client', 'super'], true)) $own[$oe] = ($ou['name'] ?? '') ?: $oe; } ?>
            <details class="ux-move"><summary class="btn ghost sm"><span>🔁</span> Move to a team</summary><form method="post" class="ux-move-f"><input type="hidden" name="_csrf" value="<?= wi_e(wi_csrf()) ?>"><input type="hidden" name="do" value="viewer_to_team"><input type="hidden" name="email" value="<?= wi_e($u['email']) ?>">
              <label>Number owner<select name="owner"><?php foreach ($own as $oe => $on): ?><option value="<?= wi_e($oe) ?>"><?= wi_e($on) ?> — <?= wi_e($oe) ?></option><?php endforeach; ?></select></label>
              <label>Role<select name="role"><option value="agent">Employee</option><option value="lead">Supervisor</option></select></label>
              <button class="btn primary sm">Move</button><small class="muted">Keeps their number, pages and phone; they then see the owner's numbers and data.</small></form></details><?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
      </div>

      <dialog id="uf-dlg" class="dlg ux-dlg" data-defaults="<?= wi_e(json_encode((object) $teamDefs)) ?>"><form method="post" id="uf">
        <input type="hidden" name="_csrf" value="<?= wi_e(wi_csrf()) ?>"><input type="hidden" name="do" value="add_user" id="uf-do"><input type="hidden" name="accounts_sent" value="1"><?php if ($isClient): ?><input type="hidden" name="active_sent" value="1"><?php endif; ?>
        <div class="ux-head"><h2 id="uf-title">Add user</h2><button type="button" class="btn ghost sm" data-close>✕</button></div>
        <div class="ux-body">
          <section class="ux-step"><h3><span>1</span> Who is it?</h3><div class="ux-g2">
            <label>Name<input name="name" id="uf-name"></label>
            <label>Email<input type="email" name="email" id="uf-email" required dir="ltr"></label>
            <label>WhatsApp number <small class="muted">(login codes)</small><input name="phone" id="uf-phone" inputmode="tel" placeholder="01xxxxxxxxx" required dir="ltr"></label>
            <label id="uf-pw-l"><span>Password (at least 10 characters)</span><input type="password" name="password" id="uf-pw" minlength="10" autocomplete="new-password" required dir="ltr"></label>
            <input type="hidden" name="chat_sent" value="1">
            <label class="ux-wide">Name shown in chats <small class="muted">(in front of their replies: «name: message»)</small><input name="chat_name" id="uf-chatname" maxlength="40" dir="auto" placeholder="Same as the name above"></label>
            <label class="check"><input type="checkbox" name="chat_prefix" value="1" id="uf-cprefix"> <span>Add their name before every reply</span></label>
            <label class="check"><input type="checkbox" name="chat_welcome" value="1" id="uf-cwelcome"> <span>A welcome message before their first reply in each chat</span></label></div></section>
          <section class="ux-step"><h3><span>2</span> What is their role?</h3><div class="ux-rolepick">
            <?php foreach ($roles as $i => $r): ?><label class="ux-rc r-<?= $r ?>"><input type="radio" name="role" value="<?= $r ?>" <?= $i === 0 ? 'checked' : '' ?>><span class="ux-ri"><?= $roleInfo[$r][0] ?></span><b><?= wi_e($roleInfo[$r][1]) ?></b><small><?= wi_e($roleInfo[$r][2]) ?></small></label><?php endforeach; ?>
          </div></section>
          <section class="ux-step" data-for="manager viewer admin lead agent"><h3><span>3</span> Which WhatsApp numbers?</h3>
            <?php if ($isClient): ?><p class="muted small">None ticked = all your numbers.</p><?php else: ?><div class="ux-seg"><label><input type="radio" name="num_src" value="pick" id="uf-pick" checked> 📋 From my numbers</label><label><input type="radio" name="num_src" value="own" id="uf-own"> 📱 Their own number (they link it after signing in)</label></div><?php endif; ?>
            <div class="ux-nums" id="uf-accs"><?php foreach (wi_my_accounts() as $a): ?><label class="ux-num"><input type="checkbox" name="accounts[]" value="<?= wi_e($a['id']) ?>"> <?= wi_e($a['name']) ?></label><?php endforeach; ?></div>
          </section>
          <section class="ux-step" data-for="client"><h3><span>3</span> Their number</h3><p class="ux-note">📱 They link one number of their own after signing in. Only they and the super admin see it.</p></section>
          <?php if (wi_is_super()): ?><section class="ux-step" data-for="client"><h3><span>4</span> What can they use?</h3><div class="ux-feats">
            <?php foreach ($feat as $k => $fx): ?><label class="ux-ft"><input type="checkbox" name="<?= $k ?>" value="1" data-flag="<?= $k ?>"><span class="ux-ri"><?= $fx[0] ?></span><b><?= wi_e($fx[1]) ?></b><small><?= wi_e($fx[2]) ?></small></label><?php endforeach; ?>
          </div><p class="muted small">Always included: chats, groups, broadcast, status, library, analytics, numbers, scheduling and number health of their own number.</p>
            <label class="ux-seats">👥 Team users (supervisors + employees) <input type="number" name="max_team" id="uf-max-team" min="0" max="500" value="0" style="width:90px"></label><p class="muted small">0 = no team. The client adds and manages them from their own Users page.</p></section><?php endif; ?>
          <section class="ux-step" data-for="viewer lead agent"><h3><span>4</span> Which pages? <small class="muted">(the menu)</small></h3>
            <div class="row wrap ux-quick"><span class="muted small">All pages:</span><button type="button" class="chip-btn sm" data-all="">— None</button><button type="button" class="chip-btn sm" data-all="view">👁 View</button><button type="button" class="chip-btn sm" data-all="edit">✏️ Edit</button></div>
            <?php foreach ($pgGroups as $gt => $keys): ?><div class="ux-pg"><b><?= wi_e($gt) ?></b>
              <?php foreach ($keys as $k): if (!isset(wi_page_names()[$k]) || !$pgAllowed($k)) continue; ?><div class="ux-pr"><span><?= wi_e(wi_page_names()[$k]) ?></span><span class="ux-tri">
                <label class="t-none"><input type="radio" name="pages[<?= $k ?>]" value="" data-pg="<?= $k ?>" checked><i>None</i></label><label class="t-view"><input type="radio" name="pages[<?= $k ?>]" value="view" data-pg="<?= $k ?>"><i>View</i></label><label class="t-edit"><input type="radio" name="pages[<?= $k ?>]" value="edit" data-pg="<?= $k ?>"><i>Edit</i></label></span></div><?php endforeach; ?></div><?php endforeach; ?>
          </section>
          <?php if ($isClient): ?><section class="ux-step" data-for="lead agent"><h3><span>5</span> Inside a conversation</h3><div class="ux-feats sm">
            <?php foreach ($chatInfo as $k => $ci): ?><label class="ux-ft"<?= in_array($k, WI_AGENT_NEVER, true) ? ' data-never="agent"' : '' ?>><input type="checkbox" name="chat[<?= $k ?>]" value="1" data-chat="<?= $k ?>"><b><?= $ci[0] ?> <?= wi_e($ci[1]) ?></b></label><?php endforeach; ?>
          </div><p class="muted small">An employee sees the chats given to them and the chats nobody has taken yet, and can never delete chats or messages.</p>
            <label class="ux-ft" data-edit-only><input type="checkbox" name="active" value="1" data-flag="active" checked><b>✅ Account active</b><small>Untick to stop this user signing in, without deleting them.</small></label></section><?php endif; ?>
          <section class="ux-step" data-for="manager viewer"><h3><span>5</span> Extras</h3><div class="ux-feats sm">
            <label class="ux-ft"><input type="checkbox" name="can_reply" value="1" data-flag="can_reply"><b>💬 Can reply in chats</b></label>
            <label class="ux-ft"><input type="checkbox" name="can_link" value="1" data-flag="can_link"><b>📱 May link one number of their own</b></label>
            <label class="ux-ft" data-only="viewer"><input type="checkbox" name="can_share" value="1" data-flag="can_share"><b>🔗 May share pages they can edit</b></label>
            <label class="ux-ft" data-only="viewer"><input type="checkbox" name="cases_only" value="1" data-flag="cases_only"><b>🗂️ Cases only</b><small>Works on the cases without seeing the number's chats</small></label>
          </div></section>
          <div class="ux-sum" id="uf-sum"></div>
        </div>
        <div class="ux-foot"><button type="button" class="btn ghost" data-close>Cancel</button><?php if ($isClient): ?><button type="submit" class="btn ghost" id="uf-def" formnovalidate title="New users of this role start with these pages and permissions">⭐ Save as the role default</button><?php endif; ?><button class="btn primary" id="uf-btn">💾 Save</button></div>
      </form></dialog>
      <?php endif; // team tab ?>
    <?php endif; ?>
  </main>
</div>
<script>(function(){var sent=0;function rep(m,i){if(sent++>8)return;try{fetch('?api=jslog',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({page:location.search,msg:m,info:i})});}catch(e){}}
window.addEventListener('error',function(e){if(/^chrome-extension:|^moz-extension:/.test(e.filename||''))return;rep(String(e.message),(e.filename||'')+':'+e.lineno+':'+e.colno+' '+(e.error&&e.error.stack||''));});
window.addEventListener('unhandledrejection',function(e){var st=String(e.reason&&e.reason.stack||'');if(/chrome-extension:|moz-extension:/.test(st))return;rep('promise: '+String(e.reason&&e.reason.message||e.reason),st);});
var reqs=[];var of=window.fetch;window.fetch=function(u,o){var t=Date.now(),rec={u:String(u).slice(0,80),s:'pending'};reqs.push(rec);return of.apply(this,arguments).then(function(r){rec.s=r.status;rec.ms=Date.now()-t;var c=r.clone();c.text().then(function(x){rec.len=x.length;if(x.length&&x.charAt(0)!=='{'&&x.charAt(0)!=='[')rec.bad=x.slice(0,160);});return r;},function(e){rec.s='failed:'+e;throw e;});};
setTimeout(function(){var fx=document.getElementById('fx-root');if(fx&&/Loading|جاري التحميل/.test(fx.innerText.slice(0,80))&&fx.innerText.length<200)rep('stuck loading',JSON.stringify(reqs).slice(0,1400));},10000);
})();</script>
<script>window.WI_PERM = <?= json_encode(['page' => $page, 'level' => wi_page_perm($page), 'share' => wi_can_share($page), 'pages' => (object) array_map(fn($k) => wi_page_perm($k), array_combine(array_keys(wi_page_names()), array_keys(wi_page_names()))), 'names' => (object) array_map('wi_t', wi_page_names())], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;</script>
<?php require_once dirname(__DIR__) . '/features.php'; ?><script>window.WI_EX = <?= json_encode(function_exists('wf_pack') && ($exAcc = wi_current()) ? array_combine(array_map(fn($k) => substr($k, 3), array_filter(array_keys(wf_pack((string) $exAcc['id'])), fn($k) => str_starts_with($k, 'ex_'))), array_values(array_filter(wf_pack((string) $exAcc['id']), fn($k) => str_starts_with($k, 'ex_'), ARRAY_FILTER_USE_KEY))) : new stdClass(), JSON_UNESCAPED_UNICODE) ?>;</script>
<script>window.WI_PACK = <?php $wpA = (string) (wi_current()['id'] ?? ''); $wpL = []; foreach (preg_split('/\R/u', (string) (wf_pack($wpA)['summary_labels'] ?? '')) as $wl) { $wx = array_map('trim', explode('|', $wl, 2)); if (count($wx) === 2 && $wx[0] !== '') $wpL[$wx[0]] = $wx[1]; } $wpP = wf_pack($wpA); $wpC = []; foreach (['case_ex_age', 'case_ex_kids', 'case_ex_country', 'case_ex_points', 'case_ex_assessment', 'case_marital', 'reception_pattern', 'placement_m', 'placement_f', 'female_hint'] as $wk) $wpC[substr($wk, 5)] = (string) ($wpP[$wk] ?? $wpP[$wk] ?? '');
  foreach (['reception_pattern', 'placement_m', 'placement_f', 'female_hint'] as $wk) $wpC[$wk] = (string) ($wpP[$wk] ?? '');
  $wpR = (json_decode((string) @file_get_contents(wf_acc_rules_file($wpA)), true) ?: [])['library'] ?? []; $wpS = [];
  foreach ((array) ($wpR['sections'] ?? []) as $sx) $wpS[] = ['name' => $sx['name'], 'icon' => $sx['icon'] ?? '']; if (!empty($wpR['tag_section']['name'])) $wpS[] = ['name' => $wpR['tag_section']['name'], 'icon' => $wpR['tag_section']['icon'] ?? ''];
  echo json_encode(['audio_cats' => wf_audio_cats($wpA), 'summary_labels' => (object) $wpL, 'case' => $wpC, 'case_cfg' => $wpA !== '' ? wf_case_cfg($wpA) : null, 'library' => ['sections' => $wpS, 'audio' => (string) ($wpR['audio_section'] ?? 'Audio'), 'other' => (string) ($wpR['other_section'] ?? 'Other')]], JSON_UNESCAPED_UNICODE); ?>;</script>
<script>window.WI_SYS = <?= json_encode(['currency' => (string) wi_sys('currency'), 'locale' => (string) (wi_sys('number_locale') ?: 'ar'), 'refresh_pct' => (int) wi_sys('refresh_pct')]) ?>;</script>
<script>window.WI_BRAND = <?= json_encode(['name' => wi_brand('brand_name'), 'logo' => wi_brand('brand_logo'), 'backup_folder' => wi_sys('backup_folder')], JSON_UNESCAPED_UNICODE) ?>;</script>
<script>window.WI_LANG = <?= json_encode(wi_lang()) ?>; window.WI_T = <?= json_encode((object) wi_dict(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="app.js?v=<?= (int) @filemtime(dirname(__DIR__) . '/app.js') ?>"></script>
<script src="features.js?v=<?= (int) @filemtime(dirname(__DIR__) . '/features.js') ?>"></script>
<?php endif; ?>
</body>
</html>
