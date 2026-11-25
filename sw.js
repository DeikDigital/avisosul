/* Aviso Sul: permite instalar la pagina y abrirla sin conexion.
   Subir el numero de VERSION en cada publicacion para que los celulares tomen la pagina nueva. */
var VERSION='avisosul-20261125a';
var BASE=['/','/app/','/app.html','/assets/site.css','/assets/site.js','/manifest.webmanifest','/icon-192.png','/icon-512.png','/assets/logo-96.png','/assets/cielo.svg'];
self.addEventListener('install',function(e){e.waitUntil(caches.open(VERSION).then(function(c){return c.addAll(BASE)}).then(function(){return self.skipWaiting()}))});
self.addEventListener('activate',function(e){e.waitUntil(caches.keys().then(function(k){return Promise.all(k.filter(function(n){return n!==VERSION}).map(function(n){return caches.delete(n)}))}).then(function(){return self.clients.claim()}))});
/* Primero la red, para mostrar siempre la version mas nueva; si no hay conexion, la copia guardada. */
self.addEventListener('fetch',function(e){
  if(e.request.method!=='GET'||new URL(e.request.url).origin!==location.origin)return;
  e.respondWith(fetch(e.request).then(function(r){var c=r.clone();caches.open(VERSION).then(function(k){k.put(e.request,c)});return r}).catch(function(){return caches.match(e.request).then(function(m){return m||caches.match('/app.html')})}));
});
