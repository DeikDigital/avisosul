/* Aviso Sul — logo 3D low-poly (nube, rayo y señal de peligro, como en el video) que sigue al cursor o al dedo.
   Usa Three.js local (/lib/three, licencia MIT). Si no hay WebGL queda la imagen fija de la portada. */
import * as THREE from '/lib/three/three.module.min.js';

const caja = document.getElementById('logo3d');
if (caja) iniciar(caja);

function iniciar(caja) {
  let renderer;
  try { renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true, powerPreference: 'low-power', preserveDrawingBuffer: !!window.LOGO3D_FOTO }); }
  catch (e) { return; }
  renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
  renderer.outputColorSpace = THREE.SRGBColorSpace;
  renderer.domElement.setAttribute('aria-hidden', 'true');
  caja.appendChild(renderer.domElement);

  const escena = new THREE.Scene();
  const cam = new THREE.PerspectiveCamera(34, 1, 0.1, 50);
  cam.position.set(0, 0.1, 11);

  escena.add(new THREE.HemisphereLight(0xe4eefb, 0x5a6478, 1.35));
  const sol = new THREE.DirectionalLight(0xfff1d6, 2.3); sol.position.set(4, 7, 7); escena.add(sol);
  const relleno = new THREE.DirectionalLight(0xa9c6ff, 0.7); relleno.position.set(-6, -1, 5); escena.add(relleno);
  const destello = new THREE.PointLight(0xffd75a, 0, 9, 1.6); destello.position.set(0, -0.2, 2.6); escena.add(destello);

  const base = new THREE.Group(); base.position.y = 0.1; escena.add(base);
  const logo = new THREE.Group(); base.add(logo);

  /* --- nube compacta: esferas facetadas gris azuladas, claras arriba --- */
  const nube = new THREE.Group(); logo.add(nube);
  const claro = new THREE.Color(0xdde5f0), oscuro = new THREE.Color(0x52668a), tmp = new THREE.Color();
  const bolas = [[0, 0.55, 0, 1.3], [-1.3, 0.05, 0, 1.0], [1.3, 0.15, -0.05, 1.08], [-2.15, -0.45, 0, 0.72], [2.2, -0.4, 0, 0.75],
    [0, -0.45, 0.25, 1.1], [-1.15, -0.65, 0.2, 0.85], [1.2, -0.62, 0.2, 0.88]];
  let semilla = 11; const azar = () => { semilla = (semilla * 9301 + 49297) % 233280; return semilla / 233280; };
  const matNube = new THREE.MeshStandardMaterial({ vertexColors: true, flatShading: true, roughness: 0.85, metalness: 0 });
  for (const [x, y, z, r] of bolas) {
    const g = new THREE.IcosahedronGeometry(r, 1).toNonIndexed();
    const pos = g.attributes.position, col = [];
    const mapa = {};                                  // mismo desplazamiento por vértice compartido: facetas sin grietas
    for (let i = 0; i < pos.count; i++) {
      const k = pos.getX(i).toFixed(3) + ',' + pos.getY(i).toFixed(3) + ',' + pos.getZ(i).toFixed(3);
      const j = mapa[k] ?? (mapa[k] = 1 + (azar() - 0.5) * 0.14);
      pos.setXYZ(i, pos.getX(i) * j, Math.max(pos.getY(i) * j, -r * 0.62), pos.getZ(i) * j);   // base algo plana
    }
    for (let i = 0; i < pos.count; i++) {
      const yy = (pos.getY(i) + y + 1.4) / 3.4;
      tmp.copy(oscuro).lerp(claro, Math.min(1, Math.max(0, yy * 1.2)));
      col.push(tmp.r, tmp.g, tmp.b);
    }
    g.setAttribute('color', new THREE.Float32BufferAttribute(col, 3));
    g.computeVertexNormals();
    const m = new THREE.Mesh(g, matNube); m.position.set(x, y, z); nube.add(m);
  }

  /* --- rayo grande al frente --- */
  const fr = new THREE.Shape();
  [[0.1, 1.45], [1.05, 1.45], [0.45, 0.15], [1.15, 0.15], [-0.55, -1.75], [-0.05, -0.35], [-0.75, -0.35], [0.1, 1.45]]
    .forEach(([x, y], i) => i ? fr.lineTo(x, y) : fr.moveTo(x, y));
  const matRayo = new THREE.MeshStandardMaterial({ color: 0xffb81c, emissive: 0x8a5200, emissiveIntensity: 0.3, flatShading: true, roughness: 0.4, metalness: 0.1 });
  const rayo = new THREE.Mesh(new THREE.ExtrudeGeometry(fr, { depth: 0.4, bevelEnabled: true, bevelThickness: 0.1, bevelSize: 0.08, bevelSegments: 1 }), matRayo);
  rayo.position.set(-0.1, 0.12, 1.25); rayo.rotation.z = -0.1; rayo.scale.setScalar(0.86); logo.add(rayo);

  /* --- señal de peligro naranja con "!" blanco --- */
  const senal = new THREE.Group(); senal.position.set(1.45, -0.95, 1.35); senal.rotation.z = 0.04; logo.add(senal);
  const tri = new THREE.Shape(); const s = 0.62;
  tri.moveTo(0, s); tri.lineTo(-s * 1.0, -s * 0.62); tri.lineTo(s * 1.0, -s * 0.62); tri.lineTo(0, s);
  senal.add(new THREE.Mesh(new THREE.ExtrudeGeometry(tri, { depth: 0.22, bevelEnabled: true, bevelThickness: 0.07, bevelSize: 0.09, bevelSegments: 2 }),
    new THREE.MeshStandardMaterial({ color: 0xf2661c, flatShading: true, roughness: 0.5 })));
  const blanco = new THREE.MeshStandardMaterial({ color: 0xffffff, roughness: 0.4 });
  const palo = new THREE.Mesh(new THREE.BoxGeometry(0.14, 0.4, 0.08), blanco); palo.position.set(0, 0.06, 0.32); senal.add(palo);
  const punto = new THREE.Mesh(new THREE.BoxGeometry(0.14, 0.13, 0.08), blanco); punto.position.set(0, -0.26, 0.32); senal.add(punto);

  /* --- interacción: cursor en la página o dedo sobre el logo --- */
  let tx = 0, ty = 0, ultimo = 0;
  const apuntar = (x, y) => { tx = (x / window.innerWidth - 0.5) * 2; ty = (y / window.innerHeight - 0.5) * 2; ultimo = performance.now(); };
  window.addEventListener('pointermove', (e) => { if (e.pointerType === 'mouse') apuntar(e.clientX, e.clientY); }, { passive: true });
  caja.addEventListener('touchmove', (e) => { const t = e.touches[0]; if (t) apuntar(t.clientX, t.clientY); }, { passive: true });
  caja.addEventListener('pointerdown', (e) => { apuntar(e.clientX, e.clientY); proximo = 0; });

  function medir() {
    const w = caja.clientWidth || 1, h = caja.clientHeight || 1;
    renderer.setSize(w, h, false); cam.aspect = w / h;
    cam.position.z = w / h < 0.95 ? 12.5 : 11;      // en pantallas angostas se aleja un poco
    cam.updateProjectionMatrix();
  }
  medir();
  if ('ResizeObserver' in window) new ResizeObserver(medir).observe(caja); else window.addEventListener('resize', medir);

  let visible = true, corriendo = false, proximo = 2.5;
  if ('IntersectionObserver' in window) new IntersectionObserver((e) => { visible = e[0].isIntersecting; if (visible && !corriendo) bucle(); }).observe(caja);
  const reloj = new THREE.Clock();
  let rx = 0, ry = 0;

  function bucle() {
    if (!visible) { corriendo = false; return; }
    corriendo = true; requestAnimationFrame(bucle);
    const dt = Math.min(reloj.getDelta(), 0.05), t = reloj.elapsedTime;
    const quieto = performance.now() - ultimo > 2500;
    if (window.LOGO3D_FOTO) { logo.rotation.set(0.06, -0.22, 0); logo.position.y = 0; renderer.render(escena, cam); return; }
    const gx = quieto ? Math.sin(t * 0.45) * 0.35 : tx, gy = quieto ? Math.sin(t * 0.3) * 0.12 : ty;
    ry += (gx * 0.55 - ry) * 0.06; rx += (gy * 0.28 - rx) * 0.06;
    logo.rotation.y = ry; logo.rotation.x = rx;
    logo.position.y = Math.sin(t * 1.1) * 0.08;
    senal.rotation.z = 0.04 + Math.sin(t * 1.7) * 0.04; senal.position.y = -0.95 + Math.sin(t * 1.3 + 1) * 0.04;
    nube.children.forEach((m, i) => { m.scale.setScalar(1 + Math.sin(t * 0.9 + i) * 0.012); });
    proximo -= dt;                                       // destello del rayo cada tanto
    if (proximo < 0) { proximo = 3.5 + Math.random() * 3; destello.userData.t = 0.35; }
    const d = destello.userData.t || 0;
    if (d > 0) { destello.userData.t = d - dt; const k = Math.sin((d / 0.35) * Math.PI); destello.intensity = k * 9; matRayo.emissiveIntensity = 0.25 + k * 1.4; }
    else { destello.intensity = 0; matRayo.emissiveIntensity = 0.25; }
    renderer.render(escena, cam);
  }
  bucle();
  caja.classList.add('ok3d');
}
