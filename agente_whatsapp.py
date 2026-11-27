"""Aviso Sul — agente de WhatsApp.

Lo corre la tarea programada (agentes.sh) dentro de vivo/, despues de los agentes de avisos.
Para cada persona inscripta y activa, busca los avisos oficiales vigentes (datos/zonas.json) cuyo
contorno toca su punto (con 5 km de tolerancia) y le manda una plantilla de WhatsApp, una sola vez por aviso.

Configuracion en datos_privados/whatsapp.json (fuera de public_html; la completa Deik, nunca va al repositorio):
  {"activo": true, "token": "...", "phone_number_id": "...", "numero_publico": "55...",
   "plantilla": "aviso_clima", "verify_token": "...", "app_secret": "..."}
Sin esa configuracion, o con "activo": false, no envia nada.  --prueba muestra lo que mandaria sin enviar.
"""
import json, math, os, sys, time, urllib.request, fcntl
from datetime import datetime

RAIZ = os.path.dirname(os.path.abspath(__file__))                       # public_html
PRIV = os.environ.get("AVISOSUL_DATOS") or os.path.join(os.path.dirname(RAIZ), "datos_privados")
PRUEBA = "--prueba" in sys.argv
MAX_POR_CORRIDA = 200                                                   # tope de seguridad de gasto por corrida


def dentro(lat, lon, poly):
    """Punto dentro de poligono; poly = [[lat, lon], ...]."""
    c = False
    j = len(poly) - 1
    for i in range(len(poly)):
        yi, xi = poly[i]; yj, xj = poly[j]
        if (yi > lat) != (yj > lat) and lon < (xj - xi) * (lat - yi) / ((yj - yi) or 1e-12) + xi:
            c = not c
        j = i
    return c


def cerca(lat, lon, poly, km=5.0):
    k = math.cos(math.radians(lat))
    for i in range(len(poly)):
        (y1, x1), (y2, x2) = poly[i], poly[(i + 1) % len(poly)]
        ax, ay, bx, by = (x1 - lon) * k * 111, (y1 - lat) * 111, (x2 - lon) * k * 111, (y2 - lat) * 111
        dx, dy = bx - ax, by - ay
        t = max(0, min(1, -(ax * dx + ay * dy) / ((dx * dx + dy * dy) or 1e-12)))
        if math.hypot(ax + t * dx, ay + t * dy) <= km:
            return True
    return False


def toca(lat, lon, aviso, km=5.0):
    return any(dentro(lat, lon, p) or cerca(lat, lon, p, km) for p in aviso["poligonos"])


def dist_km(a, b, c, d):
    k = math.cos(math.radians((a + c) / 2))
    return math.hypot((a - c) * 111, (b - d) * 111 * k)


MAX_DIA = 3          # tope por persona por dia (los rojos no cuentan)
MAX_DIA_LUGAR = 1    # Argentina: un aviso por dia por cada lugar de familia (los rojos no cuentan)


from datetime import timezone as _tz, timedelta as _td
LOCAL = _tz(_td(hours=-3))


def fenomenos(a):
    t = (a.get("titulo_pt", "") + " " + a.get("titulo_es", "") + " " + a.get("evento", "")).lower()
    f = []
    if "granizo" in t: f.append("granizo")
    if any(k in t for k in ("chuva", "lluvia", "alag", "inunda", "acumulado")): f.append("chuva")
    if any(k in t for k in ("vento", "viento", "vendaval", "ventania", "rafaga", "ráfaga")): f.append("vento")
    if any(k in t for k in ("tempestade", "tormenta", "raio", "rayo", "eletric", "eléctric", "elétric")): f.append("raios")
    return f


def pasa(a, pr):
    """Preferencias de Ajustes: nivel minimo, fenomenos y silencio de noche (salvo nivel extremo).
    Si un aviso cae en el horario de silencio no se marca como enviado: sale a la mañana si sigue vigente."""
    pr = pr or {}
    n = {"rojo": 3, "naranja": 2}.get(a.get("nivel"), 1)
    if n < pr.get("nivel", 2):                                           # por defecto: naranja y rojo
        return False
    f = fenomenos(a)
    if f and not any(x in pr.get("fen", ["granizo", "chuva", "vento", "raios"]) for x in f):
        return False
    if pr.get("noche") and n < 3:
        h = datetime.now(LOCAL).hour if LOCAL else datetime.now().hour
        d, hs = pr.get("desde", 23), pr.get("hasta", 7)
        if (h >= d or h < hs) if d > hs else (d <= h < hs):
            return False
    return True


def enviar(cfg, para, params, lang):
    cuerpo = {"messaging_product": "whatsapp", "to": para, "type": "template",
              "template": {"name": cfg.get("plantilla", "aviso_clima"), "language": {"code": "es" if lang == "es" else "pt_BR"},
                           "components": [{"type": "body", "parameters": [{"type": "text", "text": p[:200]} for p in params]}]}}
    req = urllib.request.Request(f"https://graph.facebook.com/v21.0/{cfg['phone_number_id']}/messages",
                                 data=json.dumps(cuerpo).encode(), method="POST",
                                 headers={"Authorization": "Bearer " + cfg["token"], "Content-Type": "application/json"})
    with urllib.request.urlopen(req, timeout=15) as r:
        return r.status


def main():
    cfg_f = os.path.join(PRIV, "whatsapp.json")
    cfg = json.load(open(cfg_f)) if os.path.exists(cfg_f) else {}
    if not PRUEBA and not (cfg.get("activo") and cfg.get("token") and cfg.get("phone_number_id")):
        print("whatsapp: apagado (falta configuracion)"); return
    zf = os.path.join("datos", "zonas.json")
    if not os.path.exists(zf):
        print("whatsapp: sin zonas"); return
    avisos = json.load(open(zf, encoding="utf-8")).get("avisos", [])
    ins_f = os.path.join(PRIV, "whatsapp_inscritos.json")
    if not os.path.exists(ins_f):
        print("whatsapp: sin inscritos"); return
    enviados = 0
    with open(ins_f, "r+") as h:                                         # mismo bloqueo que usa la API en PHP
        fcntl.flock(h, fcntl.LOCK_EX)
        txt = h.read()
        ins = json.loads(txt) if txt.strip() else {}
        prem = {}
        try:
            prem = json.load(open(os.path.join(PRIV, "premium.json")))
        except Exception:
            pass
        ahora_ts = time.time()
        ciudad_av = {}                                                    # avisos por ciudad (SMN y los que no traen contorno)
        def de_ciudad(slug):
            if slug not in ciudad_av:
                try:
                    d = json.load(open(os.path.join("datos", slug + ".json"), encoding="utf-8"))
                except Exception:
                    d = {}
                ciudad_av[slug] = [dict(a, id="c:" + slug + ":" + (a.get("url") or "") + ":" + (a.get("desde") or ""))
                                   for a in d.get("avisos", []) if a.get("hasta") and datetime.fromisoformat(a["hasta"]).timestamp() > ahora_ts]
            return ciudad_av[slug]
        vigentes = {a["id"] for a in avisos}
        try:
            CIUD = [c for c in json.load(open(os.path.join(RAIZ, "ciudades.json"), encoding="utf-8")) if c.get("pais") == "ar"]
        except Exception:
            CIUD = []
        def ciudades_cerca(la, lo, km):
            """Argentina: el SMN avisa por ciudad/zona; tomamos las ciudades cuyo centro esta a menos de km + 15 del punto."""
            return [c["slug"] for c in CIUD if dist_km(la, lo, c["lat"], c["lon"]) <= km + 15]
        hoy = datetime.now(LOCAL).strftime("%Y-%m-%d")
        for tel, x in ins.items():
            if x.get("estado") != "ativo":
                continue
            if int((prem.get(x.get("email") or "") or {}).get("hasta", 0)) < ahora_ts:   # solo Premium vigente (cada mensaje cuesta)
                continue
            pr = x.get("prefs") or {}
            km = float(pr.get("km", 5))
            ar = tel.startswith("54")
            if (x.get("dia") or {}).get("f") != hoy:
                x["dia"] = {"f": hoy, "n": 0, "lug": {}}
            dia = x["dia"]
            # puntos: el principal (se actualiza solo al abrir la app y cada hora) y los lugares de familia activos
            puntos = [("", x["lat"], x["lon"], x.get("cidade") or "")] + \
                     [(k, p["lat"], p["lon"], p.get("nome", "")) for k, p in (x.get("extras") or {}).items() if not p.get("mute")]
            cand = []                                                    # (aviso, clave del punto) en orden: principal primero
            for clave, la, lo, _ in puntos:
                if ar:
                    slugs = ciudades_cerca(la, lo, km) + ([x.get("cidade")] if clave == "" and x.get("cidade") else [])
                    for s in dict.fromkeys(slugs):
                        cand += [(a, clave) for a in de_ciudad(s)]
                cand += [(a, clave) for a in avisos if a.get("poligonos") and toca(la, lo, a, km)]
            ids_vivos = {a["id"] for a, _ in cand}
            x["enviados"] = [i for i in x.get("enviados", []) if i in vigentes or i in ids_vivos]   # olvida los vencidos
            for a, clave in cand:
                if a["id"] in x["enviados"] or enviados >= MAX_POR_CORRIDA:
                    continue
                if not pasa(a, pr):
                    continue
                rojo = a.get("nivel") == "rojo"
                if not rojo and dia["n"] >= MAX_DIA:
                    continue
                if not rojo and ar and clave and dia["lug"].get(clave, 0) >= MAX_DIA_LUGAR:
                    continue
                es = x.get("lang") == "es"
                hasta = datetime.fromisoformat(a["hasta"]).strftime("%d/%m %H:%M")
                lugar = next((n for k, _, _, n in puntos if k == clave), "")
                titulo = a["titulo_es" if es else "titulo_pt"] + ((" — " + lugar) if clave and lugar else "")
                dest = a["id"].split(":")[1] if a["id"].startswith("c:") else (x.get("cidade") or "app")
                params = [titulo, hasta, "https://avisosul.com/" + dest + "/"]
                if PRUEBA:
                    print("PRUEBA ->", tel[:4] + "…" + tel[-2:], params)
                else:
                    try:
                        enviar(cfg, tel, params, x.get("lang"))
                    except Exception as e:                               # un envio fallido no frena al resto
                        print("whatsapp: error con", tel[-2:], type(e).__name__); continue
                x["enviados"].append(a["id"]); enviados += 1
                if not rojo:
                    dia["n"] += 1
                    if clave:
                        dia["lug"][clave] = dia["lug"].get(clave, 0) + 1
        if not PRUEBA:
            h.seek(0); h.truncate(); h.write(json.dumps(ins, ensure_ascii=False))
        fcntl.flock(h, fcntl.LOCK_UN)
    print(f"whatsapp: {enviados} avisos {'simulados' if PRUEBA else 'enviados'}")


if __name__ == "__main__":
    main()
