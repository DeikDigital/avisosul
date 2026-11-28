/* Aviso Sul — medición: embudo propio (sin datos personales) + Píxel de Meta si está cargado en /admin/.
   window.medir(paso, eventoMeta, datos): cada paso del embudo cuenta una vez por día por dispositivo. */
(function(){
  function LS(k,v){try{if(v===undefined)return localStorage.getItem('avisosul-'+k);localStorage.setItem('avisosul-'+k,v)}catch(e){return null}}
  var pais=function(){return document.documentElement.getAttribute('data-pais')==='ar'||document.documentElement.getAttribute('data-pago')==='ar'?'ar':'br'};
  var cola=[],px=null;
  function fb(){if(!window.fbq||!px)return;try{window.fbq.apply(null,arguments)}catch(e){}}
  window.medir=function(paso,ev,datos,em){
    var dia=new Date().toISOString().slice(0,10),k='emb-'+paso;
    if(paso&&LS(k)!==dia){LS(k,dia);try{var b=new Blob([JSON.stringify({e:paso,pais:pais()})],{type:'application/json'});if(!(navigator.sendBeacon&&navigator.sendBeacon('/api/evento.php',b)))fetch('/api/evento.php',{method:'POST',body:b,keepalive:true})}catch(e){}}
    if(ev){var x=[ev,datos||{},'e'+Date.now().toString(36)+Math.random().toString(36).slice(2,10),em||LS('cuentaEmail')||''];if(window.fbq&&px)enviar(x);else cola.push(x)}};
  /* mismo evento por el Píxel y por el servidor (API de conversiones), con el mismo id: Meta no lo cuenta doble */
  function ck(n){var m=document.cookie.match(new RegExp('(?:^|; )'+n+'=([^;]*)'));return m?decodeURIComponent(m[1]):''}
  function enviar(x){if(LS('pxNo'))return;fb('track',x[0],x[1],{eventID:x[2]});
    setTimeout(function(){try{var d=x[1]||{},b=JSON.stringify({ev:x[0],eid:x[2],url:location.href,fbp:ck('_fbp'),fbc:ck('_fbc'),em:x[3],pais:pais(),value:d.value,currency:d.currency,content_name:d.content_name});
      if(!(navigator.sendBeacon&&navigator.sendBeacon('/api/capi.php',new Blob([b],{type:'application/json'}))))fetch('/api/capi.php',{method:'POST',body:b,keepalive:true})}catch(e){}},300)}
  /* aviso de cookies de Meta: una sola vez, sin tapar la pantalla; "No, gracias" no carga el Píxel */
  function aviso(cargar){if(LS('pxOk'))return cargar();if(LS('pxNo'))return;
    var es=(document.documentElement.getAttribute('data-lang')||document.documentElement.lang||'').indexOf('es')===0,d=document.createElement('div');
    d.setAttribute('role','region');d.style.cssText='position:fixed;left:10px;right:10px;bottom:10px;z-index:70;max-width:560px;margin:0 auto;background:#14202f;color:#e8eef6;border:1px solid #2c3d52;border-radius:12px;padding:10px 12px;font:13px/1.35 system-ui,sans-serif;display:flex;gap:10px;align-items:center;box-shadow:0 8px 24px rgba(0,0,0,.4)';
    d.innerHTML='<span style="flex:1">'+(es?'Usamos cookies de Meta para medir nuestros anuncios. <a href="/privacidade/" style="color:#ffbe2e">Más info</a>':'Usamos cookies da Meta para medir nossos anúncios. <a href="/privacidade/" style="color:#ffbe2e">Saiba mais</a>')+'</span><button type="button" data-r="1" style="background:none;border:0;color:#9fb3c8;font:inherit;cursor:pointer">'+(es?'No, gracias':'Não, obrigado')+'</button><button type="button" data-r="0" style="background:#ffbe2e;color:#1b1300;border:0;border-radius:8px;padding:7px 12px;font:inherit;font-weight:800;cursor:pointer">OK</button>';
    d.addEventListener('click',function(e){var r=e.target.getAttribute('data-r');if(r===null)return;d.remove();if(r==='1'){LS('pxNo','1');cola=[];fb('consent','revoke')}else{LS('pxOk','1')}});
    document.body.appendChild(d);cargar()}
  function cargarPixel(id){px=id;
    !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');
    fb('init',id);fb('track','PageView');cola.forEach(enviar);cola=[]}
  function iniciar(){fetch('/api/evento.php?conf=1').then(function(r){return r.json()}).then(function(j){if(j&&j.pixel&&!LS('pxNo'))aviso(function(){if(!LS('pxNo'))cargarPixel(j.pixel)})}).catch(function(){});window.medir('visita')}
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',iniciar);else iniciar();
})();
