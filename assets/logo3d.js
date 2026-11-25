/* Aviso Sul — logo 3D low-poly (nube, rayo, señal de peligro y granizo) que sigue al cursor o al dedo.
   Usa Three.js local (/lib/three, licencia MIT). Si no hay WebGL queda la imagen fija de la portada. */
import * as THREE from '/lib/three/three.module.min.js';

const caja = document.getElementById('logo3d');
if (caja) iniciar(caja);

function iniciar(caja) {
  let renderer;
  try { renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true, powerPreference: 'low-power' }); }
  catch (e) { return; }
  renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
  renderer.outputColorSpace = THREE.SRGBColorSpace;
  renderer.domElement.setAttribute('aria-hidden', 'true');
  caja.appendChild(renderer.domElement);

  const escena = new THREE.Scene();
  const cam = new THREE.PerspectiveCamera(34, 1, 0.1, 50);
  cam.position.set(0, 0.1, 10.5);

  escena.add(new THREE.HemisphereLight(0xcfe3f7, 0x4d5d3c, 1.25));
  const sol = new THREE.DirectionalLight(0xfff0d2, 2.4); sol.position.set(5, 7, 6); escena.add(sol);
  const relleno = new THREE.DirectionalLight(0x9cc3ff, 0.6); relleno.position.set(-6, -2, 4); escena.add(relleno);
  const destello = new THREE.PointLight(0xffd75a, 0, 9, 1.6); destello.position.set(0.3, -1.3, 2); escena.add(destello);

  const base = new THREE.Group(); base.position.y = 0.55; escena.add(base);
  const logo = new THREE.Group(); base.add(logo);

  /* --- nube: esferas facetadas, claras arriba y oscuras abajo --- */
  const nube = new THREE.Group(); logo.add(nube);
  const claro = new THREE.Color(0xece6d6), oscuro = new THREE.Color(0x3a414b), tmp = new THREE.Color();
  const bolas = [[0, 0.45, 0, 1.25], [-1.25, 0.05, 0.1, 0.98], [1.25, 0.1, 0, 1.02], [-0.55, 0.95, -0.25, 0.9], [0.65, 0.95, -0.15, 0.95],
    [-2.05, -0.35, 0, 0.66], [2.05, -0.3, 0, 0.72], [0, -0.3, 0.35, 1.02], [-1.0, -0.45, 0.3, 0.8], [1.05, -0.45, 0.3, 0.82]];
  let semilla = 7; const azar = () => { semilla = (semilla * 9301 + 49297) % 233280; return semilla / 233280; };
  const matNube = new THREE.MeshStandardMaterial({ vertexColors: true, flatShading: true, roughness: 0.92, metalness: 0 });
  for (const [x, y, z, r] of bolas) {
    const g = new THREE.IcosahedronGeometry(r, 1).toNonIndexed();
    const pos = g.attributes.position, col = [];
    const mapa = {};                                  // el mismo desplazamiento para cada vértice compartido: facetas sin grietas
    for (let i = 0; i < pos.count; i++) {
      const k = pos.getX(i).toFixed(3) + ',' + pos.getY(i).toFixed(3) + ',' + pos.getZ(i).toFixed(3);
      const j = mapa[k] ?? (mapa[k] = 1 + (azar() - 0.5) * 0.16);
      pos.setXYZ(i, pos.getX(i) * j, pos.getY(i) * j, pos.getZ(i) * j);
    }
    for (let i = 0; i < pos.count; i++) {
      const yy = (pos.getY(i) + y + 1.3) / 3.2;       // altura relativa en la nube
      tmp.copy(oscuro).lerp(claro, Math.min(1, Math.max(0, yy * 1.15)));
      col.push(tmp.r, tmp.g, tmp.b);
    }
    g.setAttribute('color', new THREE.Float32BufferAttribute(col, 3));
    g.computeVertexNormals();
    const m = new THREE.Mesh(g, matNube); m.position.set(x, y, z); nube.add(m);
  }

  /* --- rayo --- */
  const fr = new THREE.Shape();
  [[0.15, 0], [0.95, 0], [0.45, -0.95], [0.95, -0.95], [-0.25, -2.55], [0.12, -1.35], [-0.38, -1.35], [0.15, 0]]
    .forEach(([x, y], i) => i ? fr.lineTo(x, y) : fr.moveTo(x, y));
  const matRayo = new THREE.MeshStandardMaterial({ color: 0xf2c14e, emissive: 0x7a5200, emissiveIntensity: 0.25, flatShading: true, roughness: 0.55 });
  const rayo = new THREE.Mesh(new THREE.ExtrudeGeometry(fr, { depth: 0.32, bevelEnabled: true, bevelThickness: 0.06, bevelSize: 0.05, bevelSegments: 1 }), matRayo);
  rayo.position.set(-0.05, -0.55, 0.7); rayo.rotation.z = -0.12; logo.add(rayo);

  /* --- señal de peligro --- */
  const senal = new THREE.Group(); senal.position.set(2.3, 1.6, 0.35); senal.rotation.z = -0.08; logo.add(senal);
  const tri = (s) => { const t = new THREE.Shape(); t.moveTo(0, s); t.lineTo(-s * 0.866 * 1.15, -s * 0.5); t.lineTo(s * 0.866 * 1.15, -s * 0.5); t.lineTo(0, s); return t; };
  const marco = tri(0.95); marco.holes.push(tri(0.62));
  senal.add(new THREE.Mesh(new THREE.ExtrudeGeometry(marco, { depth: 0.26, bevelEnabled: true, bevelThickness: 0.04, bevelSize: 0.04, bevelSegments: 1 }),
    new THREE.MeshStandardMaterial({ color: 0xd8461b, flatShading: true, roughness: 0.6 })));
  const cara = new THREE.Mesh(new THREE.ExtrudeGeometry(tri(0.64), { depth: 0.12, bevelEnabled: false }),
    new THREE.MeshStandardMaterial({ color: 0xe6b44a, roughness: 0.7 }));
  cara.position.z = 0.04; senal.add(cara);
  const negro = new THREE.MeshStandardMaterial({ color: 0x1b1b1b, roughness: 0.5 });
  const palo = new THREE.Mesh(new THREE.BoxGeometry(0.13, 0.42, 0.06), negro); palo.position.set(0, 0.12, 0.2); senal.add(palo);
  const punto = new THREE.Mesh(new THREE.BoxGeometry(0.13, 0.13, 0.06), negro); punto.position.set(0, -0.2, 0.2); senal.add(punto);

  /* --- granizo --- */
  const matHielo = new THREE.MeshStandardMaterial({ color: 0xdbe6f1, flatShading: true, roughness: 0.35, metalness: 0.15 });
  const geoHielo = new THREE.OctahedronGeometry(0.1, 0);
  const hielos = [];
  for (let i = 0; i < 26; i++) {
    const h = new THREE.Mesh(geoHielo, matHielo);
    h.userData = { x: (azar() - 0.5) * 3.8, v: 0.9 + azar() * 0.9, f: azar() };
    h.scale.setScalar(0.7 + azar() * 0.8); logo.add(h); hielos.push(h);
  }

  /* --- interacción: cursor en la página o dedo sobre el logo --- */
  let tx = 0, ty = 0, ultimo = 0;
  const apuntar = (x, y) => { tx = (x / window.innerWidth - 0.5) * 2; ty = (y / window.innerHeight - 0.5) * 2; ultimo = performance.now(); };
  window.addEventListener('pointermove', (e) => { if (e.pointerType === 'mouse') apuntar(e.clientX, e.clientY); }, { passive: true });
  caja.addEventListener('touchmove', (e) => { const t = e.touches[0]; if (t) apuntar(t.clientX, t.clientY); }, { passive: true });
  caja.addEventListener('pointerdown', (e) => { apuntar(e.clientX, e.clientY); proximo = 0; });

  function medir() {
    const w = caja.clientWidth || 1, h = caja.clientHeight || 1;
    renderer.setSize(w, h, false); cam.aspect = w / h;
    cam.position.z = w / h < 0.95 ? 12.5 : 10.5;      // en pantallas angostas se aleja un poco
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
    const gx = quieto ? Math.sin(t * 0.45) * 0.35 : tx, gy = quieto ? Math.sin(t * 0.3) * 0.12 : ty;
    ry += (gx * 0.55 - ry) * 0.06; rx += (gy * 0.28 - rx) * 0.06;
    logo.rotation.y = ry; logo.rotation.x = rx;
    logo.position.y = Math.sin(t * 1.1) * 0.08;
    senal.rotation.z = -0.08 + Math.sin(t * 1.7) * 0.05; senal.position.y = 1.6 + Math.sin(t * 1.3 + 1) * 0.06;
    nube.children.forEach((m, i) => { m.scale.setScalar(1 + Math.sin(t * 0.9 + i) * 0.012); });
    for (const h of hielos) {
      const u = h.userData; u.f = (u.f + dt * u.v * 0.35) % 1;
      h.position.set(u.x + Math.sin(u.f * 6 + u.x) * 0.05, -0.75 - u.f * 2.9, 0.2 + (u.x % 0.7));
      h.rotation.x += dt * 2; h.rotation.y += dt * 1.4;
      h.visible = Math.abs(u.x) < 2.2 && !(Math.abs(u.x - 0.2) < 0.5 && u.f < 0.6);   // no tapa el rayo
    }
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
