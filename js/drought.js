/* Drought Predictor page: map, form, prediction API calls and history. */
(function () {
  'use strict';
  var DP = window.DP, $ = function (id) { return document.getElementById(id); };
  var COLORS = { extreme: '#7f1d1d', severe: '#dc2626', moderate: '#f97316', mild: '#facc15', no: '#16a34a' };
  var ADVICE = {
    extreme: 'Extreme vegetation stress expected. Secure water sources, prioritise high-value crops and plan emergency irrigation.',
    severe: 'Severe drought conditions expected. Schedule irrigation, reduce water losses and monitor crop stress daily.',
    moderate: 'Moderate drought likely. Increase soil-moisture monitoring and prepare an irrigation plan.',
    mild: 'Mild drought signals. Watch vegetation and soil moisture closely over the coming weeks.',
    no: 'No drought expected from satellite indicators. Continue routine monitoring.'
  };
  var state = { observed: null, inside: false, history: [], coverage: {}, lookupSeq: 0 };

  function classOf(vhi) {
    for (var i = 0; i < DP.classes.length; i++) if (vhi < DP.classes[i].below) return DP.classes[i].label.split(' ')[0].toLowerCase();
    return 'no';
  }
  function severityText(label) { return label === 'No drought' ? 'No Drought' : label + ' Drought'; }
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
  function fmt(v, d) { return Number(v).toFixed(d); }

  function api(action, params, body) {
    var url = 'api/drought.php?action=' + action + (params ? '&' + new URLSearchParams(params) : '');
    var opts = body === undefined ? {} : { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': DP.csrf }, body: JSON.stringify(body) };
    return fetch(url, opts).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) {
        if (r.status === 401) location.href = 'index.php';
        if (!r.ok) throw new Error(j.error || 'Request failed (' + r.status + ')');
        return j;
      });
    });
  }

  /* ---------- Map ---------- */
  var map = null, coverageLayer = null, predLayer = null, pin = null;
  if (window.L) {
    map = L.map('map', { scrollWheelZoom: false, zoomControl: true }).setView([26.0, 68.6], 6);
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 12, attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
    }).addTo(map);
    coverageLayer = L.layerGroup().addTo(map);
    predLayer = L.layerGroup().addTo(map);
    map.on('click', function (e) {
      $('region').value = 'custom';
      setLocation(e.latlng.lat, e.latlng.lng);
    });
  } else {
    $('map').innerHTML = '<p class="dp-map-offline">Map tiles need an internet connection. You can still pick a region or type coordinates.</p>';
  }

  function loadCoverage() {
    if (!map) return;
    coverageLayer.clearLayers();
    if (!$('coverage-toggle').checked) return;
    var month = $('month').value;
    $('coverage-month').textContent = '(' + month + ')';
    var draw = function (cells) {
      if ($('month').value !== month) return;
      var renderer = L.canvas({ padding: 0.3 }), half = 0.045;
      cells.forEach(function (c) {
        L.rectangle([[c[1] - half, c[2] - half], [c[1] + half, c[2] + half]], {
          renderer: renderer, stroke: false, fillColor: COLORS[classOf(c[3])], fillOpacity: 0.5, interactive: false
        }).addTo(coverageLayer);
      });
    };
    if (state.coverage[month]) return draw(state.coverage[month]);
    api('coverage', { month: month }).then(function (j) { state.coverage[month] = j.cells; draw(j.cells); }).catch(function () {});
  }
  $('coverage-toggle').addEventListener('change', loadCoverage);

  function drawPredictionMarkers() {
    if (!map) return;
    predLayer.clearLayers();
    state.history.forEach(function (p) {
      L.circleMarker([p.lat, p.lon], { radius: 8, color: '#fff', weight: 2, fillColor: COLORS[p.severity_class] || '#888', fillOpacity: 1 })
        .bindPopup('<strong>' + esc(p.place) + '</strong><br>' + esc(severityText(p.severity)) + ' · risk ' + fmt(p.risk, 1) + '<br><small>' + esc(p.origin) + ' → ' + esc(p.target) + '</small>')
        .addTo(predLayer);
    });
  }

  /* ---------- Location + satellite lookup ---------- */
  function setLocation(lat, lon, pan) {
    $('lat').value = fmt(lat, 4); $('lon').value = fmt(lon, 4);
    if (map) {
      if (!pin) pin = L.marker([lat, lon]).addTo(map); else pin.setLatLng([lat, lon]);
      if (pan) map.panTo([lat, lon]);
    }
    lookup();
  }
  function setStatus(text, kind) { var s = $('lookup-status'); s.textContent = text; s.className = 'dp-status dp-full ' + (kind || ''); }
  function lookup() {
    var lat = parseFloat($('lat').value), lon = parseFloat($('lon').value), seq = ++state.lookupSeq;
    if (isNaN(lat) || isNaN(lon)) return;
    setStatus('Looking up satellite values…');
    $('predict-btn').disabled = true;
    api('lookup', { lat: lat, lon: lon, month: $('month').value }).then(function (j) {
      if (seq !== state.lookupSeq) return;
      state.inside = j.inside; state.observed = j.observed;
      if (!j.inside) {
        setStatus('Outside the trained region: ' + Math.round(j.distance_km) + ' km from the nearest Sindh grid cell. Choose a location inside the shaded grid.', 'warn');
        return;
      }
      fillObserved();
      setStatus('Grid cell #' + j.cell.id + ' · ' + j.distance_km + ' km away · satellite values for ' + j.month + '.', 'ok');
      $('predict-btn').disabled = false;
    }).catch(function (e) { if (seq === state.lookupSeq) setStatus(e.message, 'warn'); });
  }
  function fillObserved() {
    var o = state.observed; if (!o) return;
    $('ndvi').value = fmt(o.ndvi, 4); $('lst').value = fmt(o.lst, 2); $('precip').value = fmt(o.precip, 2);
  }

  $('region').addEventListener('change', function () {
    var v = this.value; if (v === 'custom') return;
    var p = DP.places[+v]; setLocation(p[1], p[2], true);
  });
  var debounce;
  ['lat', 'lon'].forEach(function (id) {
    $(id).addEventListener('input', function () {
      $('region').value = 'custom'; clearTimeout(debounce);
      debounce = setTimeout(function () { setLocation(parseFloat($('lat').value), parseFloat($('lon').value), true); }, 500);
    });
  });
  $('month').addEventListener('change', function () { lookup(); loadCoverage(); });
  $('reset-values').addEventListener('click', fillObserved);

  /* ---------- Predict ---------- */
  $('predict-form').addEventListener('submit', function (e) {
    e.preventDefault();
    var form = this;
    if (!form.reportValidity() || !state.inside) return;
    var btn = $('predict-btn'); btn.disabled = true; btn.textContent = 'Running PSO-LightGBM…';
    var region = $('region').value;
    api('predict', null, {
      lat: +$('lat').value, lon: +$('lon').value, month: $('month').value,
      place: region === 'custom' ? '' : DP.places[+region][0],
      ndvi: +$('ndvi').value, lst: +$('lst').value, precip: +$('precip').value
    }).then(function (j) {
      renderResult(j.result); renderHistory(j.history);
    }).catch(function (err) { setStatus(err.message, 'warn'); })
      .then(function () { btn.disabled = false; btn.textContent = 'Predict drought severity'; });
  });

  function renderResult(r) {
    var next = r.rows[0], cls = next.drought['class'], color = COLORS[cls];
    $('result').hidden = false;
    var arc = $('gauge-arc'), len = arc.getTotalLength();
    arc.style.strokeDasharray = len; arc.style.strokeDashoffset = len * (1 - r.risk / 100); arc.style.stroke = color;
    $('res-risk').textContent = fmt(r.risk, 1);
    var sev = $('res-severity'); sev.textContent = severityText(next.drought.label); sev.className = 'dp-severity d-' + cls;
    $('res-where').textContent = r.place + ' · grid cell #' + r.cell.id + ' · forecast from ' + r.origin;
    $('res-title').textContent = severityText(next.drought.label) + ' expected in ' + next.month;
    $('res-advice').textContent = ADVICE[cls];
    $('res-outlook').innerHTML = r.rows.map(function (row) {
      var c = row.drought['class'], a = row.actual;
      return '<div class="dp-month"><span class="m">' + esc(row.month) + ' <small>+' + row.h + '</small></span>' +
        '<span class="drought-badge d-' + c + '">' + esc(row.drought.label) + '</span>' +
        '<dl><dt>VHI</dt><dd>' + fmt(row.vhi, 1) + '</dd><dt>Risk</dt><dd>' + fmt(100 - row.vhi, 1) + '</dd><dt>NDVI</dt><dd>' + fmt(row.ndvi, 3) + '</dd><dt>LST</dt><dd>' + fmt(row.lst, 1) + ' °C</dd></dl>' +
        (a ? '<p class="actual">Actual: VHI ' + fmt(a.vhi, 1) + ' · ' + esc(a.drought.label) + '</p>' : '') + '</div>';
    }).join('');
    var o = r.observed, i = r.inputs;
    $('res-note').textContent = r.edited
      ? 'What-if scenario: you changed the satellite values for ' + r.origin + ' (observed NDVI ' + fmt(o.ndvi, 3) + ', LST ' + fmt(o.lst, 1) + ' °C, rain ' + fmt(o.precip, 1) + ' mm → entered ' + fmt(i.ndvi, 3) + ', ' + fmt(i.lst, 1) + ' °C, ' + fmt(i.precip, 1) + ' mm). Earlier months use the real record.'
      : 'Based on the observed satellite record up to ' + r.origin + '.' + (r.rows[0].actual ? ' Past month selected, so actual outcomes are shown for comparison.' : '');
    $('result').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  /* ---------- History + KPIs ---------- */
  function renderHistory(h) {
    state.history = h.items;
    var s = h.stats;
    $('kpi-count').textContent = s.predictions;
    $('kpi-high').textContent = s.high_risk;
    $('kpi-avg').textContent = s.average_risk == null ? '–' : fmt(s.average_risk, 1);
    $('kpi-latest').textContent = s.latest ? severityText(s.latest.severity) : '–';
    $('kpi-latest').className = s.latest ? 'sev-' + s.latest.severity_class : '';
    $('latest-banner').hidden = !s.latest;
    if (s.latest) $('latest-text').textContent = s.latest.place + ': ' + severityText(s.latest.severity) + ' · ' + fmt(s.latest.risk, 1) + ' risk (' + s.latest.target + ')';
    $('history-body').innerHTML = h.items.length ? h.items.map(function (p) {
      return '<tr><td>' + esc(p.created_at.slice(5, 16)) + '</td><td>' + esc(p.place) + (p.edited ? ' <span class="tag">what-if</span>' : '') + '</td><td>' + esc(p.origin) + '</td><td>' + esc(p.target) +
        '</td><td>' + fmt(p.vhi, 1) + '</td><td>' + fmt(p.risk, 1) + '</td><td><span class="drought-badge d-' + esc(p.severity_class) + '">' + esc(p.severity) + '</span></td></tr>';
    }).join('') : '<tr><td colspan="7" class="empty">No predictions yet.</td></tr>';
    drawPredictionMarkers();
  }
  $('clear-history').addEventListener('click', function () {
    if (!state.history.length || !confirm('Delete all saved predictions?')) return;
    api('clear', null, {}).then(function (j) { renderHistory(j.history); $('result').hidden = true; });
  });
  $('export-csv').addEventListener('click', function () {
    if (!state.history.length) return;
    var cols = ['created_at', 'place', 'lat', 'lon', 'origin', 'target', 'vhi', 'risk', 'severity', 'edited'];
    var csv = [cols.join(',')].concat(state.history.map(function (p) {
      return cols.map(function (c) { return '"' + String(p[c]).replace(/"/g, '""') + '"'; }).join(',');
    })).join('\n');
    var a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
    a.download = 'drought-predictions.csv'; a.click();
  });

  /* ---------- Start ---------- */
  api('history').then(renderHistory).catch(function () {});
  var first = DP.places[0];
  setLocation(first[1], first[2], false);
  loadCoverage();
})();
