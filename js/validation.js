/* Validation page: hover tooltips and crosshair for the server-rendered SVG charts. */
(function () {
  'use strict';
  var tip = document.getElementById('vz-tip');
  function show(text, e) {
    tip.textContent = text; tip.hidden = false;
    var x = e.clientX + 14, y = e.clientY + 14, r = tip.getBoundingClientRect();
    if (x + r.width > window.innerWidth - 8) x = e.clientX - r.width - 14;
    if (y + r.height > window.innerHeight - 8) y = e.clientY - r.height - 14;
    tip.style.left = x + 'px'; tip.style.top = y + 'px';
  }
  document.querySelectorAll('svg.vz').forEach(function (svg) {
    var cross = svg.querySelector('.crosshair');
    svg.addEventListener('pointermove', function (e) {
      var t = e.target.getAttribute && e.target.getAttribute('data-tip');
      if (!t) { tip.hidden = true; if (cross) cross.style.opacity = 0; return; }
      show(t, e);
      var x = e.target.getAttribute('data-x');
      if (cross && x) { cross.setAttribute('x1', x); cross.setAttribute('x2', x); cross.style.opacity = 0.35; }
    });
    svg.addEventListener('pointerleave', function () { tip.hidden = true; if (cross) cross.style.opacity = 0; });
  });
})();
