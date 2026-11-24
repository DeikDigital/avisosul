/* Aviso Sul — pagina principal y paginas de servicio.
   Idioma: el texto en portugues esta en el HTML; el espanol va en data-es. Se comparte la eleccion con la app.
   En vivo: lee los mismos datos que la app (/vivo/, con respaldo en GitHub). */
(function(){
  var root=document.documentElement;
  function LS(k,v){try{if(v===undefined)return localStorage.getItem('avisosul-'+k);localStorage.setItem('avisosul-'+k,v)}catch(e){return null}}
  function esc(t){return String(t).replace(/[&<>"]/g,function(m){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m]})}
  function safe(f){try{f()}catch(e){if(window.console)console.warn(e)}}

  /* ---- idioma ---- */
  var q=/[?&]lang=(es|pt)\b/.exec(location.search);if(q)LS('lang',q[1]);
  var lang=LS('lang')==='es'?'es':'pt';
  function T(pt,es){return lang==='es'?es:pt}
  function aplicarIdioma(){
    root.lang=lang==='es'?'es':'pt-BR';
    document.querySelectorAll('[data-es]').forEach(function(e){if(e.dataset.pt===undefined)e.dataset.pt=e.innerHTML;e.innerHTML=lang==='es'?e.dataset.es:e.dataset.pt});
    document.querySelectorAll('[data-es-aria]').forEach(function(e){if(e.dataset.ptAria===undefined)e.dataset.ptAria=e.getAttribute('aria-label')||'';e.setAttribute('aria-label',lang==='es'?e.dataset.esAria:e.dataset.ptAria)});
    document.querySelectorAll('.idi button').forEach(function(b){b.setAttribute('aria-pressed',b.dataset.l===lang?'true':'false')});
    if(document.body.dataset.titEs){if(!document.body.dataset.titPt)document.body.dataset.titPt=document.title;document.title=lang==='es'?document.body.dataset.titEs:document.body.dataset.titPt}
    safe(pintarVivo);
  }
  document.querySelectorAll('.idi button').forEach(function(b){b.addEventListener('click',function(){lang=b.dataset.l;LS('lang',lang);aplicarIdioma()})});

  /* ---- datos en vivo ---- */
  var WEB=location.protocol==='http:'||location.protocol==='https:',LOCAL=location.hostname==='localhost';
  function getJ(rel){var a=LOCAL?['/'+rel]:['/vivo/'+rel,'https://raw.githubusercontent.com/DeikDigital/avisosul/main/'+rel];
    function go(i){return fetch(a[i]+'?'+Date.now(),{cache:'no-store'}).then(function(r){if(!r.ok)throw r.status;return r.json()}).catch(function(e){if(i+1<a.length)return go(i+1);throw e})}
    return go(0)}
  function hhmm(iso){var d=new Date(iso);return isNaN(d)?'':('0'+d.getHours()).slice(-2)+':'+('0'+d.getMinutes()).slice(-2)}
  var CIDS=[['foz','Foz do Iguaçu',-25.5478,-54.5882],['chapeco','Chapecó',-27.1004,-52.6152],['florianopolis','Florianópolis',-27.5954,-48.548],['curitiba','Curitiba',-25.4284,-49.2733],['portoalegre','Porto Alegre',-30.0346,-51.2177]];
  var ORD={amarillo:1,naranja:2,rojo:3},COL={amarillo:'#f5d000',naranja:'#ff8a1f',rojo:'#ff3b30'};
  var NIV={amarillo:['Amarelo','Amarillo'],naranja:['Laranja','Naranja'],rojo:['Vermelho','Rojo']};
  var ZONAS=null,EST={};
  function pintarVivo(){
    var n=document.getElementById('vN');if(!n)return;
    if(!ZONAS){return}
    var av=ZONAS.avisos||[],c={amarillo:0,naranja:0,rojo:0};av.forEach(function(a){if(c[a.nivel]!==undefined)c[a.nivel]++});
    n.textContent=av.length;
    document.getElementById('vTxt').textContent=av.length===1?T('aviso oficial do INMET em vigor no Sul agora','aviso oficial del INMET vigente en el Sur ahora'):T('avisos oficiais do INMET em vigor no Sul agora','avisos oficiales del INMET vigentes en el Sur ahora');
    document.getElementById('vNiv').innerHTML=['rojo','naranja','amarillo'].map(function(k){return '<span><i style="background:'+COL[k]+'"></i>'+c[k]+' '+NIV[k][lang==='es'?1:0].toLowerCase()+'</span>'}).join('');
    var box=document.getElementById('vCid');box.innerHTML='';
    CIDS.forEach(function(x){var e=EST[x[0]],a=e&&e.avisos&&e.avisos.length?e.avisos.slice().sort(function(p,q){return(ORD[q.nivel]||0)-(ORD[p.nivel]||0)})[0]:null;
      var el=document.createElement('a');el.className='cid '+(a?a.nivel:'');el.href='/'+x[0]+'/';
      el.innerHTML='<i></i><b>'+esc(x[1])+'</b><span>'+(e?(a?esc(T(a.titulo_pt,a.titulo_es).replace(/ — .*/,''))+' · '+NIV[a.nivel][lang==='es'?1:0]:T('Sem aviso','Sin aviso')):'…')+'</span>';box.appendChild(el)});
    var u=document.getElementById('vAt');if(u&&ZONAS.actualizado)u.textContent=T('Atualizado às ','Actualizado a las ')+hhmm(ZONAS.actualizado)+T(' · Fonte: INMET',' · Fuente: INMET');
    var m=document.getElementById('mNota');if(m)m.textContent=av.length?T('Toque numa área para ver o aviso oficial.','Tocá una zona para ver el aviso oficial.'):T('Nenhum aviso oficial em vigor no Sul agora.','Ningún aviso oficial vigente en el Sur ahora.');
    safe(mapaZonas);
  }
  function cargar(){
    if(!WEB||!document.getElementById('vN'))return;
    getJ('datos/zonas.json').then(function(j){ZONAS=j;pintarVivo()}).catch(function(){var t=document.getElementById('vTxt');if(t)t.textContent=T('Não foi possível carregar os avisos agora.','No se pudieron cargar los avisos ahora.')});
    CIDS.forEach(function(x){getJ('datos/'+x[0]+'.json').then(function(j){EST[x[0]]=j;pintarVivo()}).catch(function(){})});
  }

  /* ---- mapa en vivo (Leaflet servido desde el propio sitio) ---- */
  var MAPA=null;
  function mapaZonas(){if(!MAPA||!ZONAS||!window.L)return;if(MAPA.capa)MAPA.m.removeLayer(MAPA.capa);var g=L.featureGroup();
    (ZONAS.avisos||[]).slice().sort(function(a,b){return(ORD[a.nivel]||0)-(ORD[b.nivel]||0)}).forEach(function(a){var c=COL[a.nivel]||COL.amarillo;
      (a.poligonos||[]).forEach(function(p){L.polygon(p,{color:c,weight:2,fillColor:c,fillOpacity:.22}).bindPopup('<b>'+esc(T(a.titulo_pt,a.titulo_es))+'</b><br>'+T('Até as ','Hasta las ')+hhmm(a.hasta)+(a.descripcion?'<br><small>'+esc(a.descripcion.slice(0,180))+(a.descripcion.length>180?'…':'')+'</small>':'')+'<br><a href="'+esc(a.url)+'" target="_blank" rel="noopener">'+T('Ver no INMET','Ver en INMET')+'</a>').addTo(g)})});
    g.addTo(MAPA.m);MAPA.capa=g}
  function mapaIniciar(){if(MAPA||!WEB)return;MAPA={};
    var c=document.createElement('link');c.rel='stylesheet';c.href='/lib/leaflet/leaflet.css';document.head.appendChild(c);
    var s=document.createElement('script');s.src='/lib/leaflet/leaflet.js';s.onload=function(){safe(function(){
      MAPA.m=L.map('mapa',{scrollWheelZoom:false}).setView([-27.3,-51.6],6);
      L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:10,attribution:'&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>'}).addTo(MAPA.m);
      fetch('https://api.rainviewer.com/public/weather-maps.json').then(function(r){return r.json()}).then(function(j){var p=j.radar&&j.radar.past||[];if(!p.length)return;var f=p[p.length-1];
        L.tileLayer(j.host+f.path+'/256/{z}/{x}/{y}/2/1_1.png',{opacity:.7,maxNativeZoom:7,maxZoom:10,attribution:'Radar: <a href="https://www.rainviewer.com/" target="_blank" rel="noopener">RainViewer</a>'}).addTo(MAPA.m)}).catch(function(){});
      CIDS.forEach(function(x){L.circleMarker([x[2],x[3]],{radius:6,color:'#fff',weight:2,fillColor:'#f5b301',fillOpacity:1}).bindTooltip(x[1]).addTo(MAPA.m)});
      mapaZonas()})};document.head.appendChild(s)}
  safe(function(){var m=document.getElementById('mapa');if(!m)return;
    if(!('IntersectionObserver' in window)){mapaIniciar();return}
    var io=new IntersectionObserver(function(e){if(e[0].isIntersecting){io.disconnect();mapaIniciar()}},{rootMargin:'300px'});io.observe(m)});

  aplicarIdioma();cargar();setInterval(cargar,10*60000);
})();
