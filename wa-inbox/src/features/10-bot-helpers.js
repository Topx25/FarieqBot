
  /* ===================== 🤖 Bot page: general settings + groups table + team + log ===================== */
  function gCfgForm(g, pre) { g = g || {}; pre = pre || '';
    var o = function (v, cur, l) { return '<option value="' + v + '" ' + (String(cur) === String(v) ? 'selected' : '') + '>' + l + '</option>'; };
    return '<div class="gcf">'
      + '<label>' + T('Reply type') + '<select data-f="mode">' + o('ai', g.mode || 'ai', '✨ ' + T('Interactive (AI answers the message)')) + o('fixed', g.mode, '📌 ' + T('Fixed message')) + '</select></label>'
      + '<label>' + T('When') + '<select data-f="trigger">' + o('mention', g.trigger || 'mention', '@ ' + T('Only when someone mentions or replies to the number')) + o('questions', g.trigger, '❓ ' + T('Questions only')) + o('all', g.trigger, '💬 ' + T('Every message')) + '</select></label>'
      + '<label>' + T('Learning') + '<select data-f="learn">' + o('0', g.learn ? 1 : 0, T('Do not learn from this group')) + o('1', g.learn ? 1 : 0, '📚 ' + T('Learn from this group (questions + answers of the team / admins)')) + '</select></label>'
      + '<label>' + T('Welcome') + '<select data-f="welcome">' + o('0', g.welcome ? 1 : 0, T('No welcome')) + o('1', g.welcome ? 1 : 0, '👋 ' + T('Welcome new members with a mention')) + '</select></label>'
      + '<label class="gcf-wide" data-show="fixed" ' + (g.mode === 'fixed' ? '' : 'hidden') + '>' + T('Fixed message ({name} = member name, {group} = group name)') + '<textarea data-f="text" rows="3" dir="auto">' + esc(g.text || '') + '</textarea></label>'
      + '<label data-show="fixed" ' + (g.mode === 'fixed' ? '' : 'hidden') + '>' + T('Same member at most once every (hours)') + '<input type="number" data-f="cooldown" min="1" max="720" value="' + (g.cooldown || 24) + '"></label>'
      + '<label class="gcf-wide" data-show="welcome" ' + (g.welcome ? '' : 'hidden') + '>' + T('Welcome message — {mention} = the new member (tagged), {group} = group name') + '<textarea data-f="welcome_text" rows="3" dir="auto">' + esc(g.welcome_text || '') + '</textarea></label>'
      + '<label>' + T('Reception gate') + '<select data-f="gate">' + o('0', g.gate ? 1 : 0, T('Off')) + o('1', g.gate ? 1 : 0, '🎙️ ' + T('Woman / man → ask for a recording → tag the reviewers')) + '</select></label>'
      + '<label class="gcf-wide" data-show="gate" ' + (g.gate ? '' : 'hidden') + '>' + T('Reviewers tagged when a recording arrives (phone numbers, comma separated)') + '<input data-f="gate_review" dir="ltr" value="' + esc(g.gate_review || '') + '" placeholder="2010…, 2011…"></label>'
      + '<label class="gcf-wide" data-show="gate" ' + (g.gate ? '' : 'hidden') + '>' + T('After the move, ask the healer to open the case in this group (group ID, e.g. 1203…@g.us)') + '<input data-f="gate_notify" dir="ltr" value="' + esc(g.gate_notify || '') + '" placeholder="1203…@g.us"></label>'
      + '<label data-show="gate" ' + (g.gate ? '' : 'hidden') + '>' + T('New members who do not answer') + '<select data-f="gate_follow">' + o('1', g.gate_follow === false ? 0 : 1, '⏰ ' + T('Reminder, last notice, then removal')) + o('0', g.gate_follow === false ? 0 : 1, T('Leave them')) + '</select></label>'
      + '<label data-show="gate" ' + (g.gate ? '' : 'hidden') + '>' + T('Reminder after (hours)') + '<input type="number" data-f="gate_remind_h" min="1" max="72" value="' + (g.gate_remind_h || 3) + '"></label>'
      + '<label data-show="gate" ' + (g.gate ? '' : 'hidden') + '>' + T('Last notice after another (hours)') + '<input type="number" data-f="gate_final_h" min="1" max="72" value="' + (g.gate_final_h || 3) + '"></label>'
      + '<label data-show="gate" ' + (g.gate ? '' : 'hidden') + '>' + T('Removed after the last notice (minutes, 0 = never)') + '<input type="number" data-f="gate_kick_m" min="0" max="1440" value="' + (g.gate_kick_m === undefined ? 30 : g.gate_kick_m) + '"></label>'
      + '<label data-show="gate" ' + (g.gate ? '' : 'hidden') + '>' + T('Words that mean a woman (empty = the defaults)') + '<input data-f="gate_f" dir="auto" value="' + esc(g.gate_f || '') + '"></label>'
      + '<label data-show="gate" ' + (g.gate ? '' : 'hidden') + '>' + T('Words that mean a man (empty = the defaults)') + '<input data-f="gate_m" dir="auto" value="' + esc(g.gate_m || '') + '"></label></div>'; }
  function gCfgRead(box) { var o = {}; [].forEach.call(box.querySelectorAll('[data-f]'), function (i) { o[i.dataset.f] = i.value; }); return o; }
  function gCfgWire(box, onChange) {
    var t = null, sync = function () { var m = box.querySelector('[data-f=mode]').value, w = box.querySelector('[data-f=welcome]').value; [].forEach.call(box.querySelectorAll('[data-show=fixed]'), function (x) { x.hidden = m !== 'fixed'; }); var gt = box.querySelector('[data-f=gate]'); [].forEach.call(box.querySelectorAll('[data-show=gate]'), function (x) { x.hidden = !gt || gt.value !== '1'; }); [].forEach.call(box.querySelectorAll('[data-show=welcome]'), function (x) { x.hidden = w !== '1'; }); };
    box.addEventListener('change', function () { sync(); clearTimeout(t); t = setTimeout(onChange, 700); });
    box.addEventListener('input', function (e) { if (e.target.matches('textarea, input')) { clearTimeout(t); t = setTimeout(onChange, 1200); } });
  }
  var GMODE = { ai: '✨ ' + T('Interactive'), fixed: '📌 ' + T('Fixed message') }, GTRIG = { mention: '@ ' + T('Mentions'), questions: '❓ ' + T('Questions'), all: '💬 ' + T('All') };

