  if (page === 'users') {
    // Users & permissions: one dialog, steps shown for the chosen role only, and a plain summary of what the user will be able to do.
    var f = function (id) { return document.getElementById(id); }, dlg = f('uf-dlg'), form = f('uf');
    var role = function () { var r = form.querySelector('input[name="role"]:checked'); return r ? r.value : 'viewer'; };
    function sync() { var r = role();
      [].forEach.call(form.querySelectorAll('.ux-step[data-for]'), function (s) { s.hidden = s.dataset.for.split(' ').indexOf(r) === -1; });
      [].forEach.call(form.querySelectorAll('[data-only]'), function (x) { x.hidden = x.dataset.only !== r; });
      // an employee never deletes chats or messages
      [].forEach.call(form.querySelectorAll('[data-never]'), function (x) { var no = x.dataset.never === r, i = x.querySelector('input'); x.classList.toggle('dim', no); i.disabled = no; if (no) i.checked = false; });
      [].forEach.call(form.querySelectorAll('[data-edit-only]'), function (x) { x.hidden = f('uf-do').value !== 'update_user'; });
      var own = f('uf-own') && f('uf-own').checked; if (f('uf-accs')) f('uf-accs').hidden = own;
      [].forEach.call(form.querySelectorAll('.ux-rc'), function (c) { c.classList.toggle('on', c.querySelector('input').checked); });
      [].forEach.call(form.querySelectorAll('.ux-ft'), function (c) { var i = c.querySelector('input'); c.classList.toggle('on', i && i.checked); });
      summary(); }
    function summary() { var r = role(), L = [], flag = function (k) { var i = form.querySelector('[data-flag="' + k + '"]'); return i && i.checked; };
      var nums = [].filter.call(form.querySelectorAll('input[name="accounts[]"]'), function (x) { return x.checked; }).length;
      if (r === 'super') L.push(T('Sees and manages everything: all numbers, users and system settings.'));
      else if (r === 'client') { L.push(T('Links their own number and runs everything of it.')); var on = ['can_bot', 'can_meetings', 'can_cases', 'can_backup'].filter(flag).map(function (k) { return form.querySelector('[data-flag="' + k + '"]').closest('.ux-ft').querySelector('b').textContent; });
        L.push(T('Extra features') + ': ' + (on.length ? on.join('، ') : T('none'))); L.push(T('Cannot add users, change their permissions or see system settings.')); }
      else { L.push((f('uf-own') && f('uf-own').checked) ? T('Links their own number after signing in.') : T('Numbers') + ': ' + nums);
        if (r === 'viewer') { var v = 0, e = 0; [].forEach.call(form.querySelectorAll('input[data-pg]:checked'), function (x) { if (x.value === 'view') v++; if (x.value === 'edit') e++; }); L.push('👁 ' + v + ' · ✏️ ' + e + ' ' + T('pages')); }
        else if (r === 'manager') L.push(T('All pages of their numbers, except users and technical pages.'));
        else if (r === 'admin') L.push(T('Runs their numbers and adds their own staff.'));
        if (r === 'lead' || r === 'agent') { var v2 = 0, e2 = 0; [].forEach.call(form.querySelectorAll('input[data-pg]:checked'), function (x) { if (x.value === 'view') v2++; if (x.value === 'edit') e2++; }); L.push('👁 ' + v2 + ' · ✏️ ' + e2 + ' ' + T('pages'));
          L.push(r === 'agent' ? T('Sees the chats given to them and the chats nobody has taken yet.') : T('Sees all chats of the chosen numbers.'));
          var cp = [].filter.call(form.querySelectorAll('input[data-chat]'), function (x) { return x.checked; }).map(function (x) { return x.closest('.ux-ft').querySelector('b').textContent; });
          L.push(T('In a conversation') + ': ' + (cp.length ? cp.join('، ') : T('read only'))); }
        if (flag('can_reply') && r !== 'lead' && r !== 'agent') L.push(T('Can reply in chats')); }
      f('uf-sum').innerHTML = '<b>✅ ' + T('This user will be able to') + '</b><ul>' + L.map(function (x) { return '<li>' + esc(x) + '</li>'; }).join('') + '</ul>'; }
    function open(edit) { form.reset(); f('uf-do').value = edit ? 'update_user' : 'add_user'; f('uf-email').readOnly = !!edit;
      f('uf-pw').required = !edit; f('uf-pw-l').querySelector('span').textContent = edit ? T('New password (optional)') : T('Password (at least 10 characters)');
      f('uf-title').textContent = edit ? T('Edit') + ' — ' + edit : T('Add user'); if (!edit) { applyDefaults(); f('uf-cprefix').checked = true; f('uf-cwelcome').checked = true; } sync(); dlg.showModal(); }
    // A client's panel: a new supervisor / employee starts with the saved defaults of that role.
    var DEF = JSON.parse(dlg.getAttribute('data-defaults') || '{}');
    function fillPerms(pgs, chat) { [].forEach.call(form.querySelectorAll('input[data-pg]'), function (x) { x.checked = ((pgs || {})[x.dataset.pg] || '') === x.value; });
      [].forEach.call(form.querySelectorAll('input[data-chat]'), function (x) { x.checked = !!(chat || {})[x.dataset.chat]; }); }
    function applyDefaults() { var d = DEF[role()]; if (d) fillPerms(d.pages, d.chat); }
    form.addEventListener('change', function (e) { if (e.target.name === 'role' && f('uf-do').value === 'add_user') { applyDefaults(); sync(); } });
    if (f('uf-def')) f('uf-def').addEventListener('click', function () { f('uf-do').value = 'team_roles'; });
    f('uf-new').addEventListener('click', function () { open(null); });
    form.addEventListener('change', sync);
    form.addEventListener('click', function (e) { var b = e.target.closest('[data-all]'); if (!b) return;
      [].forEach.call(form.querySelectorAll('input[data-pg][value="' + b.dataset.all + '"]'), function (x) { x.checked = true; }); summary(); });
    [].forEach.call(dlg.querySelectorAll('[data-close]'), function (b) { b.addEventListener('click', function () { dlg.close(); }); });
    document.addEventListener('click', function (e) { var b = e.target.closest('[data-edit]'); if (!b) return; open(b.getAttribute('data-edit'));
      f('uf-email').value = b.getAttribute('data-edit'); f('uf-name').value = b.getAttribute('data-name') || ''; f('uf-phone').value = b.getAttribute('data-phone'); f('uf-chatname').value = b.getAttribute('data-cname') || ''; f('uf-cprefix').checked = !!b.getAttribute('data-cprefix'); f('uf-cwelcome').checked = !!b.getAttribute('data-cwelcome');
      var rr = form.querySelector('input[name="role"][value="' + b.getAttribute('data-role') + '"]'); if (rr) rr.checked = true;
      var fl = JSON.parse(b.getAttribute('data-flags') || '{}'); [].forEach.call(form.querySelectorAll('[data-flag]'), function (i) { i.checked = !!fl[i.dataset.flag]; });
      if (fl.own) { f('uf-own').checked = true; } else { f('uf-pick').checked = true; }
      var accs = (b.getAttribute('data-accounts') || '').split(','); [].forEach.call(form.querySelectorAll('input[name="accounts[]"]'), function (x) { x.checked = accs.indexOf(x.value) !== -1; });
      var pgs = JSON.parse(b.getAttribute('data-pages') || 'null') || {}; [].forEach.call(form.querySelectorAll('input[data-pg]'), function (x) { x.checked = (pgs[x.dataset.pg] || '') === x.value; });
      var ch = JSON.parse(b.getAttribute('data-chat') || '{}'); [].forEach.call(form.querySelectorAll('input[data-chat]'), function (x) { x.checked = !!ch[x.dataset.chat]; });
      if (f('uf-max-team')) f('uf-max-team').value = b.getAttribute('data-max-team') || 0;
      sync(); });
    sync();
    // From the Clients page: "New client" opens the dialog with the Client role chosen.
    if (new URLSearchParams(location.search).get('new') === 'client' && f('uf-new')) { open(null); var rc = form.querySelector('input[name="role"][value="client"]'); if (rc) { rc.checked = true; sync(); } }
  }
