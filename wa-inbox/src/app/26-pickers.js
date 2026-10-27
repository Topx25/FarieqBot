/* Every date, date-and-time and time field opens its calendar / clock on a click anywhere in it (not only on the small icon) */
(function () {
  var SEL = 'input[type=date], input[type=datetime-local], input[type=time], input[type=month]';
  document.addEventListener('click', function (e) { var i = e.target.closest && e.target.closest(SEL); if (!i || i.disabled || i.readOnly || typeof i.showPicker !== 'function') return; try { i.showPicker(); } catch (x) {} });
  document.addEventListener('keydown', function (e) { var i = e.target; if ((e.key === 'Enter' || e.key === ' ') && i.matches && i.matches(SEL) && typeof i.showPicker === 'function') { e.preventDefault(); try { i.showPicker(); } catch (x) {} } });
})();
