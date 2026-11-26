/* Aviso Sul: permite instalar la pagina y abrirla sin conexion.
   Subir el numero de VERSION en cada publicacion para que los celulares tomen la pagina nueva. */
var VERSION='avisosul-20261127h';
var BASE=['/','/app/','/app.html','/assets/site.css','/assets/site.js','/manifest.webmanifest','/icon-192.png','/icon-512.png','/assets/logo-nube-96.png','/assets/logo-nube.png','/assets/cielo.svg','/assets/ic/granizo.png','/assets/ic/chuva-raio.png','/assets/ic/vendaval.png','/assets/ic/tempestade.png','/assets/ic/alerta.png','/assets/ic/ok.png'];
self.addEventListener('install',function(e){e.waitUntil(caches.open(VERSION).then(function(c){return c.addAll(BASE)}).then(function(){return self.skipWaiting()}))});
self.addEventListener('activate',function(e){e.waitUntil(caches.keys().then(function(k){return Promise.all(k.filter(function(n){return n!==VERSION}).map(function(n){return caches.delete(n)}))}).then(function(){return self.clients.claim()}))});
/* Primero la red, para mostrar siempre la version mas nueva; si no hay conexion, la copia guardada. */
self.addEventListener('fetch',function(e){
  if(e.request.method!=='GET'||new URL(e.request.url).origin!==location.origin)return;
  e.respondWith(fetch(e.request).then(function(r){var c=r.clone();caches.open(VERSION).then(function(k){k.put(e.request,c)});return r}).catch(function(){return caches.match(e.request).then(function(m){return m||caches.match('/app.html')})}));
});

/* notificaciones push: llegan aunque la app o la web estén cerradas */
self.addEventListener('push',function(e){var d={};try{d=e.data?e.data.json():{}}catch(_){d={body:e.data?e.data.text():''}}
  var o={body:d.body||'',icon:'/icon-192.png',badge:'/favicon-48.png',tag:d.tag||'aviso',renotify:true,vibrate:[200,100,200],data:{url:d.url||'/app/',premium:d.premium||null}};
  if(d.premium)o.actions=[{action:'premium',title:d.accion||'Ver Premium'}];
  var t=d.title||'Aviso Sul';e.waitUntil(self.registration.showNotification(t,o).catch(function(){return self.registration.showNotification(t,{body:o.body,icon:o.icon,tag:o.tag,data:o.data})}))});
self.addEventListener('notificationclick',function(e){e.notification.close();var dt=e.notification.data||{},u=(e.action==='premium'&&dt.premium)?dt.premium:(dt.url||'/app/');
  e.waitUntil(clients.matchAll({type:'window',includeUncontrolled:true}).then(function(l){for(var i=0;i<l.length;i++){var c=l[i];if(c.url.indexOf(location.origin)===0&&'focus' in c){try{c.navigate(u)}catch(_){}return c.focus()}}return clients.openWindow(u)}))});
