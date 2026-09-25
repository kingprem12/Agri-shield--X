/* India Drought Predictor: district map, prediction form, API calls and history. */
(function () {
  'use strict';
  var DP = window.DP, $ = function (id) { return document.getElementById(id); };
  var FIELDS = [['t2m', 2], ['rh', 2], ['precip', 2], ['wind', 2], ['sm_root', 4], ['sm_top', 4]];
  var COLORS = { exceptional: '#730000', extreme: '#e60000', severe: '#ff8c00', moderate: '#fcc55c', dry: '#fff176', none: '#cfe8d5' };
  var ADVICE = {
    exceptional: 'Exceptional drought expected: widespread crop failure risk. Activate contingency crop plans, protect drinking water and use only critical irrigation.',
    extreme: 'Extreme drought expected. Prioritise water for high-value crops, use mulching and micro-irrigation, and delay new sowing.',
    severe: 'Severe drought expected. Schedule irrigation, reduce evaporation losses and choose drought-tolerant varieties.',
    moderate: 'Moderate drought likely. Monitor soil moisture closely and prepare an irrigation plan.',
    dry: 'Abnormally dry conditions. Watch for early crop stress and conserve soil moisture.',
    none: 'No drought expected. Soil moisture is near or above normal for this time of year.'
  };
  var state = { observed: null, inside: false, history: [], lookupSeq: 0, cells: null, values: {}, districtPct: {} };

  function classOf(pct) {
    for (var i = 0; i < DP.classes.length; i++) if (pct <= DP.classes[i].max_pct) return DP.classes[i];
    return DP.classes[DP.classes.length - 1];
  }
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

  /* ---------- Geometry helpers ---------- */
  function inRing(x, y, ring) {
    var inside = false;
    for (var i = 0, j = ring.length - 1; i < ring.length; j = i++) {
      var xi = ring[i][0], yi = ring[i][1], xj = ring[j][0], yj = ring[j][1];
      if ((yi > y) !== (yj > y) && x < (xj - xi) * (y - yi) / (yj - yi) + xi) inside = !inside;
    }
    return inside;
  }
  function inGeometry(lon, lat, g) {
    var polys = g.type === 'Polygon' ? [g.coordinates] : g.coordinates;
    return polys.some(function (p) { return inRing(lon, lat, p[0]) && !p.slice(1).some(function (h) { return inRing(lon, lat, h); }); });
  }
  function districtAt(lat, lon) {
    if (!state.districts) return null;
    for (var i = 0; i < state.districts.features.length; i++) {
      var f = state.districts.features[i];
      if (inGeometry(lon, lat, f.geometry)) return f.properties;
    }
    return null;
  }
  function key(st, d) { return st + '|' + d; }

  /* ---------- Map ---------- */
  var map = null, districtLayer = null, pin = null;
  function initMap() {
    if (!window.L) { $('map').innerHTML = '<p class="dp-map-loading">The map library could not load (no internet). Pick a region or type coordinates instead.</p>'; return; }
    Promise.all([
      fetch('assets/india-districts.geojson').then(function (r) { return r.json(); }),
      fetch('assets/india-states.geojson').then(function (r) { return r.json(); }),
      api('cells')
    ]).then(function (res) {
      state.districts = res[0]; state.cells = res[2].cells;
      $('map').innerHTML = '';
      map = L.map('map', { zoomSnap: 0.25, scrollWheelZoom: false, attributionControl: false, minZoom: 4, maxZoom: 9 });
      districtLayer = L.geoJSON(state.districts, {
        style: function (f) { return { fillColor: '#e5e7eb', fillOpacity: 0.92, color: '#ffffff', weight: 0.5 }; },
        onEachFeature: function (f, layer) {
          layer.on('mouseover', function () { layer.setStyle({ weight: 2, color: '#111827' }); layer.bringToFront(); });
          layer.on('mouseout', function () { districtLayer.resetStyle(layer); styleDistrict(layer); });
          layer.on('click', function (e) { $('region').value = 'custom'; setLocation(e.latlng.lat, e.latlng.lng, false); });
          layer.bindTooltip(function () {
            var p = f.properties, v = state.districtPct[key(p.st_nm, p.district)];
            return '<strong>' + esc(p.district) + '</strong>, ' + esc(p.st_nm) + (v == null ? '' : '<br>' + esc(classOf(v).label) + ' · ' + fmt(v, 0) + 'th percentile');
          }, { sticky: true });
        }
      }).addTo(map);
      L.geoJSON(res[1], { style: { fill: false, color: '#334155', weight: 1.1 }, interactive: false }).addTo(map);
      map.fitBounds(districtLayer.getBounds(), { padding: [4, 4] });
      assignDistrictCells();
      loadCoverage();
      if (state.pending) { placePin(state.pending[0], state.pending[1]); state.pending = null; }
      refreshPlace();
    }).catch(function () { $('map').innerHTML = '<p class="dp-map-loading">Could not load the India map.</p>'; });
  }
  /* Each district is coloured by the mean percentile of the grid points inside it (nearest point if none). */
  function assignDistrictCells() {
    var byDistrict = {};
    state.cells.forEach(function (c) { (byDistrict[key(c[3], c[4])] = byDistrict[key(c[3], c[4])] || []).push(c[0]); });
    state.districtCells = {};
    districtLayer.eachLayer(function (layer) {
      var p = layer.feature.properties, k = key(p.st_nm, p.district);
      if (byDistrict[k]) { state.districtCells[k] = byDistrict[k]; return; }
      var c = layer.getBounds().getCenter(), best = null, bestD = Infinity;
      state.cells.forEach(function (cell) { var d = Math.pow(cell[1] - c.lat, 2) + Math.pow(cell[2] - c.lng, 2); if (d < bestD) { bestD = d; best = cell[0]; } });
      state.districtCells[k] = [best];
    });
  }
  function styleDistrict(layer) {
    var p = layer.feature.properties, v = state.districtPct[key(p.st_nm, p.district)];
    layer.setStyle({ fillColor: v == null ? '#e5e7eb' : COLORS[classOf(v).key] });
  }
  function loadCoverage() {
    if (!districtLayer) return;
    var month = $('month').value, h = $('map-mode').value;
    $('map-caption').textContent = 'Loading…';
    api('coverage', { month: month, h: h }).then(function (j) {
      if ($('month').value !== month || $('map-mode').value !== h) return;
      state.districtPct = {};
      Object.keys(state.districtCells).forEach(function (k) {
        var ids = state.districtCells[k], sum = 0;
        ids.forEach(function (id) { sum += j.values[id]; });
        state.districtPct[k] = sum / ids.length;
      });
      districtLayer.eachLayer(styleDistrict);
      var dry = Object.keys(state.districtPct).filter(function (k) { return state.districtPct[k] <= 20; }).length;
      $('map-caption').textContent = (j.forecast ? 'Forecast for ' : 'Actual, ') + j.month + ' · ' + dry + ' of ' + Object.keys(state.districtPct).length + ' districts in drought (D1+)';
    }).catch(function (e) { $('map-caption').textContent = e.message; });
  }
  $('map-mode').addEventListener('change', loadCoverage);

  /* ---------- Location + climate lookup ---------- */
  function placePin(lat, lon) {
    if (!map) { state.pending = [lat, lon]; return; }
    if (!pin) pin = L.circleMarker([lat, lon], { radius: 7, color: '#fff', weight: 3, fillColor: '#1d4ed8', fillOpacity: 1 }).addTo(map);
    else pin.setLatLng([lat, lon]);
    pin.bringToFront();
  }
  function refreshPlace() {
    var p = districtAt(parseFloat($('lat').value), parseFloat($('lon').value));
    if (p) { $('state').value = p.st_nm; $('district').value = p.district; }
  }
  function setLocation(lat, lon) {
    $('lat').value = fmt(lat, 4); $('lon').value = fmt(lon, 4);
    placePin(lat, lon); refreshPlace(); lookup();
  }
  function setStatus(text, kind) { var s = $('lookup-status'); s.textContent = text; s.className = 'dp-status dp-full ' + (kind || ''); }
  function lookup() {
    var lat = parseFloat($('lat').value), lon = parseFloat($('lon').value), seq = ++state.lookupSeq;
    if (isNaN(lat) || isNaN(lon)) return;
    setStatus('Looking up climate record…');
    $('predict-btn').disabled = true;
    api('lookup', { lat: lat, lon: lon, month: $('month').value }).then(function (j) {
      if (seq !== state.lookupSeq) return;
      state.inside = j.inside; state.observed = j.observed;
      if (!j.inside) { setStatus('Outside India: ' + Math.round(j.distance_km) + ' km from the nearest grid point. Choose a location on the India map.', 'warn'); return; }
      if (!$('state').value || !districtAt(lat, lon)) { $('state').value = j.cell.state; $('district').value = j.cell.district; }
      fillObserved();
      setStatus('Grid point ' + fmt(j.cell.lat, 2) + '°N ' + fmt(j.cell.lon, 2) + '°E · ' + j.distance_km + ' km away · NASA POWER values for ' + j.month + '.', 'ok');
      $('predict-btn').disabled = false;
    }).catch(function (e) { if (seq === state.lookupSeq) setStatus(e.message, 'warn'); });
  }
  function fillObserved() {
    var o = state.observed; if (!o) return;
    FIELDS.forEach(function (f) { $(f[0]).value = fmt(o[f[0]], f[1]); });
  }
  $('region').addEventListener('change', function () {
    if (this.value === 'custom') return;
    var p = DP.places[+this.value];
    $('state').value = ''; $('district').value = '';
    setLocation(p[1], p[2]);
    if (map) map.flyTo([p[1], p[2]], Math.max(map.getZoom(), 6), { duration: 0.6 });
  });
  var debounce;
  ['lat', 'lon'].forEach(function (id) {
    $(id).addEventListener('input', function () {
      $('region').value = 'custom'; clearTimeout(debounce);
      debounce = setTimeout(function () { $('state').value = ''; $('district').value = ''; setLocation(parseFloat($('lat').value), parseFloat($('lon').value)); }, 500);
    });
  });
  $('month').addEventListener('change', function () { lookup(); loadCoverage(); });
  $('reset-values').addEventListener('click', fillObserved);

  /* ---------- Predict ---------- */
  $('predict-form').addEventListener('submit', function (e) {
    e.preventDefault();
    if (!this.reportValidity() || !state.inside) return;
    var btn = $('predict-btn'); btn.disabled = true; btn.textContent = 'Running PSO-LightGBM…';
    var region = $('region').value, body = { lat: +$('lat').value, lon: +$('lon').value, month: $('month').value,
      place: region === 'custom' ? ($('district').value ? $('district').value + ', ' + $('state').value : '') : DP.places[+region][0] };
    FIELDS.forEach(function (f) { body[f[0]] = +$(f[0]).value; });
    api('predict', null, body).then(function (j) { renderResult(j.result); renderHistory(j.history); })
      .catch(function (err) { setStatus(err.message, 'warn'); })
      .then(function () { btn.disabled = false; btn.textContent = 'Predict drought severity'; });
  });

  function renderResult(r) {
    var next = r.rows[0], cls = next.drought['class'], color = COLORS[cls];
    $('result').hidden = false;
    var arc = $('gauge-arc'), len = arc.getTotalLength();
    arc.style.strokeDasharray = len; arc.style.strokeDashoffset = len * (1 - next.risk / 100); arc.style.stroke = color === COLORS.none ? '#22a05e' : color;
    $('res-risk').textContent = fmt(next.risk, 1);
    var sev = $('res-severity'); sev.textContent = (next.drought.code !== '-' ? next.drought.code + ' · ' : '') + next.drought.label; sev.className = 'dp-severity d-' + cls;
    $('res-where').textContent = r.place + ' · forecast from ' + r.origin + ' · now ' + fmt(r.current.percentile, 0) + 'th percentile (' + r.current.drought.label + ')';
    $('res-title').textContent = next.drought.label + ' expected in ' + next.month;
    $('res-advice').textContent = ADVICE[cls];
    $('res-outlook').innerHTML = r.rows.map(function (row) {
      var c = row.drought['class'], a = row.actual;
      return '<div class="dp-month"><span class="m">' + esc(row.month) + ' <small>+' + row.h + ' month' + (row.h > 1 ? 's' : '') + '</small></span>' +
        '<span class="drought-badge d-' + c + '">' + esc(row.drought.label) + '</span>' +
        '<dl><dt>Percentile</dt><dd>' + fmt(row.percentile, 0) + '</dd><dt>Risk</dt><dd>' + fmt(row.risk, 1) + '</dd><dt>Soil moisture</dt><dd>' + fmt(row.sm_root, 3) + '</dd></dl>' +
        (a ? '<p class="actual">Actual: ' + fmt(a.percentile, 0) + 'th percentile · ' + esc(a.drought.label) + '</p>' : '') + '</div>';
    }).join('');
    var changed = FIELDS.filter(function (f) { return Math.abs(r.inputs[f[0]] - r.observed[f[0]]) > 0; }).map(function (f) {
      return $(f[0]).parentNode.firstChild.textContent.trim() + ' ' + fmt(r.observed[f[0]], f[1]) + ' → ' + fmt(r.inputs[f[0]], f[1]);
    });
    $('res-note').textContent = r.edited
      ? 'What-if scenario for ' + r.origin + ': ' + changed.join('; ') + '. Earlier months use the recorded data.'
      : 'Based on the recorded NASA POWER data up to ' + r.origin + '.' + (next.actual ? ' A past month was selected, so actual outcomes are shown for comparison.' : '');
    $('result').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  /* ---------- History + KPIs ---------- */
  function renderHistory(h) {
    state.history = h.items;
    var s = h.stats;
    $('kpi-count').textContent = s.predictions;
    $('kpi-high').textContent = s.high_risk;
    $('kpi-avg').textContent = s.average_risk == null ? '–' : fmt(s.average_risk, 1);
    $('kpi-latest').textContent = s.latest ? s.latest.severity : '–';
    $('kpi-latest').className = s.latest ? 'sev-' + s.latest.severity_class : '';
    $('latest-banner').hidden = !s.latest;
    if (s.latest) $('latest-text').textContent = s.latest.place + ': ' + s.latest.severity + ' · ' + fmt(s.latest.risk, 1) + ' risk (' + s.latest.target + ')';
    $('history-body').innerHTML = h.items.length ? h.items.map(function (p) {
      return '<tr><td>' + esc(p.created_at.slice(5, 16)) + '</td><td>' + esc(p.place) + (p.edited ? ' <span class="tag">what-if</span>' : '') + '</td><td>' + esc(p.origin) + '</td><td>' + esc(p.target) +
        '</td><td>' + fmt(p.percentile, 0) + '</td><td>' + fmt(p.risk, 1) + '</td><td><span class="drought-badge d-' + esc(p.severity_class) + '">' + esc(p.severity) + '</span></td></tr>';
    }).join('') : '<tr><td colspan="7" class="empty">No predictions yet.</td></tr>';
  }
  $('clear-history').addEventListener('click', function () {
    if (!state.history.length || !confirm('Delete all saved predictions?')) return;
    api('clear', null, {}).then(function (j) { renderHistory(j.history); $('result').hidden = true; });
  });
  $('export-csv').addEventListener('click', function () {
    if (!state.history.length) return;
    var cols = ['created_at', 'place', 'state', 'district', 'lat', 'lon', 'origin', 'target', 'percentile', 'risk', 'severity', 'edited'];
    var csv = [cols.join(',')].concat(state.history.map(function (p) {
      return cols.map(function (c) { return '"' + String(p[c]).replace(/"/g, '""') + '"'; }).join(',');
    })).join('\n');
    var a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
    a.download = 'india-drought-predictions.csv'; a.click();
  });

  /* ---------- Start ---------- */
  api('history').then(renderHistory).catch(function () {});
  initMap();
  var first = DP.places[0];
  setLocation(first[1], first[2]);
})();
