/* Modo Car (Premium) de Aviso Sul / Alerta Sur.
   Mapa a pantalla completa con un marco de datos: velocidad y rumbo por GPS, clima y avisos oficiales del punto,
   autonomía del auto (calculada con los datos que carga la persona) y estaciones de servicio cercanas (OpenStreetMap),
   con favoritas y "dejé el auto aquí". Se carga recién cuando se abre el modo. */
(function () {
  var A = window.__AS; if (!A) return;
  var LS = A.LS, L2 = A.L2, esc = A.esc;
  var CAR = null;

  function cfg() { try { return JSON.parse(LS('carCfg') || 'null') } catch (e) { return null } }
  function guardarCfg(c) { LS('carCfg', JSON.stringify(c)) }
  function favs() { try { return JSON.parse(LS('carFav') || '{}') } catch (e) { return {} } }
  function guardarFavs(f) { LS('carFav', JSON.stringify(f)) }
  function km(a, b) { var R = 6371, r = Math.PI / 180, dl = (b[0] - a[0]) * r, dn = (b[1] - a[1]) * r, x = Math.sin(dl / 2) * Math.sin(dl / 2) + Math.cos(a[0] * r) * Math.cos(b[0] * r) * Math.sin(dn / 2) * Math.sin(dn / 2); return 2 * R * Math.asin(Math.sqrt(x)) }
  function autonomia(c) { if (!c || !c.cap || !c.cons) return null; return Math.max(0, c.cap * (c.pct / 100) / c.cons * 100) }
  function ev(c) { return c && c.tipo === 'ev' }
  function rumbo(h) { if (h == null || isNaN(h)) return ''; var d = L2(['N', 'NE', 'E', 'SE', 'S', 'SO', 'O', 'NO'], ['N', 'NE', 'L', 'SE', 'S', 'SO', 'O', 'NO']); return d[Math.round(h / 45) % 8] }
  function irA(p, modo) { return 'https://www.google.com/maps/dir/?api=1&destination=' + p[0].toFixed(5) + ',' + p[1].toFixed(5) + (modo ? '&travelmode=' + modo : '') }
  var WMO = { 0: ['☀️', 'Despejado', 'Céu limpo'], 1: ['🌤️', 'Poco nublado', 'Pouco nublado'], 2: ['⛅', 'Parcial', 'Parcial'], 3: ['☁️', 'Nublado', 'Nublado'], 45: ['🌫️', 'Niebla', 'Neblina'], 48: ['🌫️', 'Niebla', 'Neblina'], 51: ['🌦️', 'Llovizna', 'Garoa'], 53: ['🌦️', 'Llovizna', 'Garoa'], 55: ['🌧️', 'Llovizna', 'Garoa'], 61: ['🌧️', 'Lluvia', 'Chuva'], 63: ['🌧️', 'Lluvia', 'Chuva'], 65: ['🌧️', 'Lluvia fuerte', 'Chuva forte'], 80: ['🌦️', 'Chaparrón', 'Pancada'], 81: ['🌧️', 'Chaparrón', 'Pancada'], 82: ['⛈️', 'Chaparrón fuerte', 'Pancada forte'], 95: ['⛈️', 'Tormenta', 'Tempestade'], 96: ['⛈️', 'Tormenta con granizo', 'Tempestade com granizo'], 99: ['⛈️', 'Tormenta con granizo', 'Tempestade com granizo'] };

  var CSS = '#car{position:fixed;inset:0;z-index:9000;background:#0b1118;color:#fff;font:15px/1.3 "IBM Plex Sans",system-ui,sans-serif}' +
    '#carMap{position:absolute;inset:0;z-index:0;isolation:isolate}' +
    '#car .top,#car .av,#car .side,#car .bot{z-index:1100}#car .cf{z-index:1050}#car .dlg{z-index:1200}' +
    '#car .cf{position:absolute;inset:0;pointer-events:none;box-shadow:inset 0 0 0 3px rgba(255,178,56,.55),inset 0 0 60px rgba(0,0,0,.55);border-radius:0}' +
    '#car .gls{background:rgba(14,24,38,.72);-webkit-backdrop-filter:blur(12px);backdrop-filter:blur(12px);border:1px solid rgba(255,255,255,.16);border-radius:18px;pointer-events:auto}' +
    '#car .top{position:absolute;top:max(10px,env(safe-area-inset-top));left:10px;right:10px;display:flex;gap:8px;align-items:stretch}' +
    '#car .vel{padding:8px 14px;text-align:center;min-width:92px}#car .vel b{display:block;font-size:34px;line-height:1;font-variant-numeric:tabular-nums}#car .vel small{font-size:11px;opacity:.75}' +
    '#car .wx{flex:1;padding:8px 12px;display:flex;gap:10px;align-items:center;min-width:0}#car .wx i{font-style:normal;font-size:28px}#car .wx b{font-size:22px}#car .wx div{min-width:0}#car .wx small{display:block;font-size:12px;opacity:.8;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}' +
    '#car .x{width:58px;display:grid;place-items:center;font:inherit;font-size:22px;line-height:1;font-weight:800;color:#fff;cursor:pointer}' +
    '#car .av{position:absolute;top:calc(max(10px,env(safe-area-inset-top)) + 70px);left:10px;right:10px;padding:8px 12px;font-weight:700;font-size:14px;border-left:6px solid #f5d000}' +
    '#car .av.naranja{border-left-color:#ff8a1f}#car .av.rojo{border-left-color:#ff3b30;animation:carRojo 1.4s infinite}#car .av.ok{border-left-color:#3ddc84;font-weight:600}' +
    '@keyframes carRojo{50%{box-shadow:0 0 0 4px rgba(255,59,48,.45)}}' +
    '#car .x small{font-size:11px;font-weight:700}#car .osmb{filter:invert(1) hue-rotate(180deg) brightness(.95) contrast(.9) saturate(.6)}' +
    '#car .side{position:absolute;right:10px;top:50%;transform:translateY(-50%);display:flex;flex-direction:column;gap:8px}' +
    '#car .side button{width:52px;height:52px;font-size:22px;color:#fff;cursor:pointer}' +
    '#car .bot{position:absolute;left:10px;right:10px;bottom:max(10px,env(safe-area-inset-bottom));padding:10px 12px}' +
    '#car .au{display:flex;align-items:center;gap:10px}#car .au b{font-size:22px;white-space:nowrap}#car .bar{flex:1;height:10px;border-radius:6px;background:rgba(255,255,255,.15);overflow:hidden}#car .bar i{display:block;height:100%;background:linear-gradient(90deg,#ff3b30,#f5b301 30%,#3ddc84 60%)}' +
    '#car .au button,#car .est button,#car .est a{font:inherit;font-size:13px;font-weight:700;color:#0b1118;background:#f5b301;border:0;border-radius:10px;padding:6px 10px;cursor:pointer;text-decoration:none;white-space:nowrap}' +
    '#car .est{margin-top:8px;display:grid;gap:6px;max-height:30vh;overflow:auto}#car .est div{display:flex;align-items:center;gap:8px;font-size:14px}#car .est span{flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}#car .est small{opacity:.75}' +
    '#car .est .fv{background:transparent;color:#ffc61a;font-size:18px;padding:0 4px}#car .est .fv.no{color:rgba(255,255,255,.4)}' +
    '#car .nt{font-size:12px;opacity:.7;margin-top:6px}' +
    '#car .dlg{position:absolute;inset:0;background:rgba(0,0,0,.55);display:flex;align-items:flex-end;justify-content:center;z-index:5}#car .dlg[hidden]{display:none}' +
    '#car .dlg form{width:100%;max-width:480px;margin:10px;padding:18px;display:grid;gap:10px}#car .dlg h3{margin:0;font-size:19px}' +
    '#car .dlg label{display:grid;gap:4px;font-size:13px;font-weight:700}#car .dlg input,#car .dlg select{font:inherit;padding:10px;border-radius:10px;border:1px solid rgba(255,255,255,.25);background:rgba(0,0,0,.35);color:#fff}' +
    '#car .dlg .r2{display:grid;grid-template-columns:1fr 1fr;gap:10px}#car .dlg .ok{font:inherit;font-weight:800;padding:12px;border:0;border-radius:12px;background:#f5b301;color:#2e2000;cursor:pointer}#car .dlg .cn{font:inherit;padding:10px;border:0;background:transparent;color:#fff;opacity:.8;cursor:pointer}' +
    '#car .pin{font-size:22px;line-height:1;filter:drop-shadow(0 2px 2px rgba(0,0,0,.6))}#car .pin.f{font-size:26px}' +
    '#car .yo{width:22px;height:22px;border-radius:50%;background:#f5b301;border:3px solid #fff;box-shadow:0 0 0 6px rgba(245,179,1,.3)}' +
    '#car .leaflet-control-attribution{font-size:9px;background:rgba(0,0,0,.5);color:#ccc}#car .leaflet-control-attribution a{color:#ddd}';

  function html() {
    return '<div id="carMap"></div><div class="cf"></div>' +
      '<div class="top"><div class="gls vel"><b id="cVel">–</b><small>km/h <span id="cRum"></span></small></div>' +
      '<div class="gls wx"><i id="cWi">⛅</i><div><b id="cWt">–°</b><small id="cWd">' + L2('Buscando el clima…', 'Buscando o tempo…') + '</small></div></div>' +
      '<button type="button" class="gls x" id="cX" aria-label="' + L2('Volver a Casa', 'Voltar para Casa') + '">🏠<small>' + L2('Casa', 'Casa') + '</small></button></div>' +
      '<div class="gls av ok" id="cAv">' + L2('Buscando avisos oficiales…', 'Buscando avisos oficiais…') + '</div>' +
      '<div class="side"><button type="button" class="gls" id="cCen" title="' + L2('Centrar', 'Centralizar') + '">◎</button>' +
      '<button type="button" class="gls" id="cAuto" title="' + L2('Mi auto', 'Meu carro') + '">🚗</button>' +
      '<button type="button" class="gls" id="cPark" title="' + L2('Dejé el auto aquí', 'Deixei o carro aqui') + '">🅿️</button>' +
      '<button type="button" class="gls" id="cEst" title="' + L2('Buscar estaciones', 'Buscar postos') + '">⛽</button></div>' +
      '<div class="gls bot"><div class="au"><span>🛢️</span><b id="cAut">– km</b><div class="bar"><i id="cBar" style="width:0"></i></div><button type="button" id="cCfg">' + L2('Mi auto', 'Meu carro') + '</button></div>' +
      '<div class="est" id="cList"></div><div class="nt" id="cNt">' + L2('Configurá antes de salir. No uses el celular mientras manejás.', 'Configure antes de sair. Não use o celular enquanto dirige.') + '</div></div>' +
      '<div class="dlg" id="cDlg" hidden><form class="gls" id="cForm"><h3>🚗 ' + L2('Mi auto', 'Meu carro') + '</h3>' +
      '<label>' + L2('Modelo', 'Modelo') + '<input name="mod" maxlength="40" placeholder="' + L2('Ej.: Fiat Cronos 1.3', 'Ex.: Fiat Argo 1.0') + '"></label>' +
      '<label>' + L2('Combustible', 'Combustível') + '<select name="tipo"><option value="nafta">' + L2('Nafta', 'Gasolina') + '</option><option value="diesel">Diésel</option><option value="gnc">' + L2('GNC', 'GNV') + '</option><option value="etanol">' + L2('Etanol', 'Etanol') + '</option><option value="ev">' + L2('Eléctrico', 'Elétrico') + '</option></select></label>' +
      '<div class="r2"><label id="cCapL">' + L2('Tanque (litros)', 'Tanque (litros)') + '<input name="cap" type="number" min="1" max="300" step="0.1" required></label>' +
      '<label id="cConL">' + L2('Consumo (L/100 km)', 'Consumo (L/100 km)') + '<input name="cons" type="number" min="1" max="60" step="0.1" required></label></div>' +
      '<label><span>' + L2('Nivel actual', 'Nível atual') + ': <span id="cPctV">50</span> %</span><input name="pct" type="range" min="0" max="100" value="50"></label>' +
      '<button class="ok">' + L2('Guardar', 'Salvar') + '</button><button type="button" class="cn" id="cCn">' + L2('Cancelar', 'Cancelar') + '</button>' +
      '<div class="nt">' + L2('La autonomía es una estimación con estos datos y los km que marca el GPS. Corregí el nivel cuando cargues.', 'A autonomia é uma estimativa com esses dados e os km do GPS. Corrija o nível quando abastecer.') + '</div></form></div>';
  }

  function abrir() {
    if (CAR) { CAR.el.hidden = false; setTimeout(function () { CAR.map.invalidateSize() }, 50); vigilar(); return }
    if (!document.getElementById('carCss')) { var s = document.createElement('style'); s.id = 'carCss'; s.textContent = CSS; document.head.appendChild(s) }
    var el = document.createElement('div'); el.id = 'car'; el.innerHTML = html(); document.body.appendChild(el);
    CAR = { el: el, pos: null, est: [], ult: null, wxEn: null, estEn: null };
    var g = A.ref();
    CAR.map = L.map('carMap', { zoomControl: false, attributionControl: true }).setView(g, 13);
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, className: 'osmb', attribution: '&copy; OpenStreetMap' }).addTo(CAR.map);
    CAR.capaEst = L.layerGroup().addTo(CAR.map);
    CAR.yo = L.marker(g, { icon: L.divIcon({ className: '', html: '<div class="yo"></div>', iconSize: [22, 22], iconAnchor: [11, 11] }) }).addTo(CAR.map);
    eventos(); pintarAuto(); pintarPark(); vigilar(); clima(g); avisos(g); estaciones(g);
  }
  function cerrar() { if (!CAR) return; CAR.el.hidden = true; if (CAR.w != null) navigator.geolocation.clearWatch(CAR.w); CAR.w = null; if (CAR.lock) { try { CAR.lock.release() } catch (e) { } CAR.lock = null } }

  function eventos() {
    var $ = function (id) { return document.getElementById(id) };
    $('cX').onclick = cerrar;
    $('cCen').onclick = function () { CAR.map.setView(CAR.pos || A.ref(), Math.max(CAR.map.getZoom(), 14)) };
    $('cAuto').onclick = $('cCfg').onclick = dlg;
    $('cCn').onclick = function () { $('cDlg').hidden = true };
    $('cEst').onclick = function () { estaciones(CAR.pos || A.ref(), true) };
    $('cPark').onclick = function () {
      var p = LS('carPark') ? JSON.parse(LS('carPark')) : null;
      if (p && confirmarQuitar()) { LS('carPark', ''); pintarPark(); return }
      var g = CAR.pos || A.ref(); LS('carPark', JSON.stringify([g[0], g[1], Date.now()])); pintarPark();
    };
    var f = $('cForm');
    f.tipo.onchange = function () { var e = f.tipo.value === 'ev'; $('cCapL').firstChild.nodeValue = e ? L2('Batería (kWh)', 'Bateria (kWh)') : L2('Tanque (litros)', 'Tanque (litros)'); $('cConL').firstChild.nodeValue = e ? L2('Consumo (kWh/100 km)', 'Consumo (kWh/100 km)') : L2('Consumo (L/100 km)', 'Consumo (L/100 km)') };
    f.pct.oninput = function () { $('cPctV').textContent = f.pct.value };
    f.onsubmit = function (e) {
      e.preventDefault(); var c = { mod: f.mod.value.trim(), tipo: f.tipo.value, cap: +f.cap.value, cons: +f.cons.value, pct: +f.pct.value };
      var antes = cfg(); guardarCfg(c); $('cDlg').hidden = true; pintarAuto();
      if (!antes || ev(antes) !== ev(c)) estaciones(CAR.pos || A.ref(), true);
    };
  }
  /* "dejé el auto aquí": si ya hay uno, tocar de nuevo lo quita (sin dialogos del navegador) */
  function confirmarQuitar() { return true }

  function dlg() {
    var c = cfg() || { mod: '', tipo: 'nafta', cap: '', cons: '', pct: 50 }, f = document.getElementById('cForm');
    f.mod.value = c.mod || ''; f.tipo.value = c.tipo || 'nafta'; f.cap.value = c.cap || ''; f.cons.value = c.cons || ''; f.pct.value = c.pct == null ? 50 : Math.round(c.pct);
    document.getElementById('cPctV').textContent = f.pct.value; f.tipo.onchange(); document.getElementById('cDlg').hidden = false;
  }

  function pintarAuto() {
    var c = cfg(), a = autonomia(c), b = document.getElementById('cAut'), bar = document.getElementById('cBar');
    if (a == null) { b.textContent = L2('Cargá tu auto', 'Cadastre seu carro'); bar.style.width = '0'; if (CAR.circ) { CAR.map.removeLayer(CAR.circ); CAR.circ = null } return }
    b.textContent = Math.round(a) + ' km'; bar.style.width = Math.max(2, Math.min(100, c.pct)) + '%';
    b.title = (c.mod ? c.mod + ' · ' : '') + Math.round(c.pct) + ' %';
    var g = CAR.pos || A.ref();
    if (CAR.circ) CAR.circ.setLatLng(g).setRadius(a * 1000); else CAR.circ = L.circle(g, { radius: a * 1000, color: '#f5b301', weight: 2, dashArray: '6 8', fill: false, interactive: false }).addTo(CAR.map);
    if (c.pct <= 15) document.getElementById('cNt').textContent = L2('Te queda poco: cargá en la estación más cercana.', 'Está acabando: abasteça no posto mais próximo.');
  }

  function pintarPark() {
    if (CAR.park) { CAR.map.removeLayer(CAR.park); CAR.park = null }
    var p = LS('carPark') ? JSON.parse(LS('carPark')) : null; if (!p) return;
    var d = new Date(p[2]);
    CAR.park = L.marker([p[0], p[1]], { icon: L.divIcon({ className: '', html: '<div class="pin f">🚗</div>', iconSize: [28, 28], iconAnchor: [14, 14] }) }).addTo(CAR.map)
      .bindPopup('<b>' + L2('Tu auto', 'Seu carro') + '</b><br>' + ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2) + '<br><a href="' + irA(p, 'walking') + '" target="_blank" rel="noopener">' + L2('Cómo volver a pie', 'Como voltar a pé') + ' ↗</a>');
  }

  function vigilar() {
    if (!navigator.geolocation || CAR.w != null) return;
    if ('wakeLock' in navigator) navigator.wakeLock.request('screen').then(function (l) { CAR.lock = l }).catch(function () { });
    CAR.w = navigator.geolocation.watchPosition(function (q) {
      var g = [q.coords.latitude, q.coords.longitude], ac = q.coords.accuracy || 999;
      var v = q.coords.speed; document.getElementById('cVel').textContent = v == null || isNaN(v) ? '–' : Math.round(v * 3.6);
      document.getElementById('cRum').textContent = rumbo(q.coords.heading);
      if (CAR.pos && ac < 60) {
        var d = km(CAR.pos, g);
        if (d > 0.02 && d < 5) { var c = cfg(); if (c && c.cap && c.cons) { c.pct = Math.max(0, c.pct - d * c.cons / 100 / c.cap * 100); guardarCfg(c) } }
      }
      CAR.pos = g; CAR.yo.setLatLng(g); pintarAuto();
      if (!CAR.seguido) { CAR.seguido = 1; CAR.map.setView(g, 14) } else if (CAR.map.getBounds().pad(-0.25).contains(g) === false) CAR.map.panTo(g);
      if (!CAR.wxEn || km(CAR.wxEn, g) > 10) clima(g);
      avisos(g);
      if (!CAR.estEn || km(CAR.estEn, g) > 5) estaciones(g); else pintarLista();
    }, function () { document.getElementById('cNt').textContent = L2('Activá la ubicación para ver velocidad, autonomía y estaciones cerca.', 'Ative a localização para ver velocidade, autonomia e postos perto.') }, { enableHighAccuracy: true, maximumAge: 5000, timeout: 20000 });
  }

  function clima(g) {
    CAR.wxEn = g;
    fetch('https://api.open-meteo.com/v1/forecast?latitude=' + g[0].toFixed(3) + '&longitude=' + g[1].toFixed(3) + '&current=temperature_2m,precipitation,wind_gusts_10m,weather_code&timezone=auto')
      .then(function (r) { return r.json() }).then(function (j) {
        var c = j.current || {}, w = WMO[c.weather_code] || ['🌡️', '', ''], es = L2(1, 0);
        document.getElementById('cWi').textContent = w[0];
        document.getElementById('cWt').textContent = Math.round(c.temperature_2m) + '°';
        document.getElementById('cWd').textContent = (es ? w[1] : w[2]) + ' · ' + L2('ráfagas ', 'rajadas ') + Math.round(c.wind_gusts_10m || 0) + ' km/h' + (c.precipitation ? ' · ' + c.precipitation + ' mm' : '');
      }).catch(function () { document.getElementById('cWd').textContent = L2('Clima no disponible', 'Tempo indisponível') });
  }

  function avisos(g) {
    var a = A.avisos(g), e = document.getElementById('cAv');
    if (!a.length) { e.className = 'gls av ok'; e.textContent = '✓ ' + L2('Sin avisos oficiales en este punto', 'Sem avisos oficiais neste ponto'); return }
    var x = a[0]; e.className = 'gls av ' + (x.nivel || '');
    e.textContent = '⚠️ ' + (L2(x.titulo_es || x.titulo_pt, x.titulo_pt || x.titulo_es) || '') + (x.hasta ? ' · ' + L2('hasta las ', 'até as ') + A.hhmm(x.hasta) : '') + (a.length > 1 ? ' (+' + (a.length - 1) + ')' : '');
  }

  function estaciones(g, forzar) {
    var c = cfg(), a = autonomia(c), r = Math.round(Math.min(30, Math.max(5, a == null ? 15 : a)) * 1000), tag = ev(c) ? 'charging_station' : 'fuel';
    if (CAR.cargando && !forzar) return; CAR.cargando = 1; CAR.estEn = g;
    var q = '[out:json][timeout:20];(node["amenity"="' + tag + '"](around:' + r + ',' + g[0].toFixed(4) + ',' + g[1].toFixed(4) + ');way["amenity"="' + tag + '"](around:' + r + ',' + g[0].toFixed(4) + ',' + g[1].toFixed(4) + '););out center 80;';
    document.getElementById('cList').innerHTML = '<small>' + L2('Buscando estaciones…', 'Buscando postos…') + '</small>';
    fetch('https://overpass-api.de/api/interpreter', { method: 'POST', body: 'data=' + encodeURIComponent(q), headers: { 'Content-Type': 'application/x-www-form-urlencoded' } })
      .then(function (r) { return r.json() }).then(function (j) {
        CAR.est = (j.elements || []).map(function (x) {
          var t = x.tags || {}, p = x.center ? [x.center.lat, x.center.lon] : [x.lat, x.lon];
          return { id: x.type[0] + x.id, n: t.brand || t.name || t.operator || L2('Estación', 'Posto'), nom: t.name && t.brand && t.name !== t.brand ? t.name : '', p: p };
        });
        pintarEst(); CAR.cargando = 0;
      }).catch(function () { CAR.cargando = 0; CAR.est = CAR.est || []; pintarEst(); if (Object.keys(favs()).length) return; document.getElementById('cList').innerHTML = '<small>' + L2('No se pudieron buscar estaciones. Probá de nuevo con ⛽.', 'Não foi possível buscar postos. Tente de novo com ⛽.') + '</small>' });
  }

  function pintarEst() {
    var F = favs(); CAR.capaEst.clearLayers();
    var todas = CAR.est.slice(); for (var id in F) if (!todas.some(function (x) { return x.id === id })) todas.push({ id: id, n: F[id].n, nom: '', p: F[id].p });
    todas.forEach(function (x) {
      var f = !!F[x.id];
      L.marker(x.p, { icon: L.divIcon({ className: '', html: '<div class="pin' + (f ? ' f' : '') + '">' + (f ? '⭐' : (ev(cfg()) ? '🔌' : '⛽')) + '</div>', iconSize: [26, 26], iconAnchor: [13, 13] }) })
        .bindPopup('<b>' + esc(x.n) + '</b>' + (x.nom ? '<br>' + esc(x.nom) : '') + '<br><a href="' + irA(x.p) + '" target="_blank" rel="noopener">' + L2('Ir con Google Maps', 'Ir com o Google Maps') + ' ↗</a>')
        .addTo(CAR.capaEst);
    });
    CAR.todas = todas; pintarLista();
  }

  function pintarLista() {
    if (!CAR.todas) return;
    var F = favs(), g = CAR.pos || A.ref(), a = autonomia(cfg()), box = document.getElementById('cList');
    var l = CAR.todas.map(function (x) { return { x: x, d: km(g, x.p), f: !!F[x.id] } }).sort(function (u, v) { return (v.f - u.f) || (u.d - v.d) }).slice(0, 5);
    if (!l.length) { box.innerHTML = '<small>' + L2('No hay estaciones cargadas en OpenStreetMap cerca de acá.', 'Não há postos cadastrados no OpenStreetMap perto daqui.') + '</small>'; return }
    box.innerHTML = l.map(function (o) {
      var lejos = a != null && o.d > a;
      return '<div><button type="button" class="fv' + (o.f ? '' : ' no') + '" data-f="' + esc(o.x.id) + '" aria-label="' + L2('Favorita', 'Favorito') + '">' + (o.f ? '★' : '☆') + '</button><span><b>' + esc(o.x.n) + '</b> <small>' + o.d.toFixed(1) + ' km' + (lejos ? ' · ' + L2('fuera de tu autonomía', 'fora da sua autonomia') : '') + '</small></span><a href="' + irA(o.x.p) + '" target="_blank" rel="noopener">' + L2('Ir', 'Ir') + ' ↗</a></div>';
    }).join('');
    box.querySelectorAll('[data-f]').forEach(function (b) {
      b.onclick = function () {
        var id = b.getAttribute('data-f'), F2 = favs(), x = CAR.todas.filter(function (y) { return y.id === id })[0];
        if (F2[id]) delete F2[id]; else if (x) F2[id] = { n: x.n, p: x.p };
        guardarFavs(F2); pintarEst();
      };
    });
  }

  window.__carAbrir = abrir;
})();
