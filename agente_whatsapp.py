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


def toca(lat, lon, aviso):
    return any(dentro(lat, lon, p) or cerca(lat, lon, p) for p in aviso["poligonos"])


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
        vigentes = {a["id"] for a in avisos}
        for tel, x in ins.items():
            if x.get("estado") != "ativo":
                continue
            x["enviados"] = [i for i in x.get("enviados", []) if i in vigentes]   # olvida los avisos que ya vencieron
            for a in avisos:
                if a["id"] in x["enviados"] or not toca(x["lat"], x["lon"], a) or enviados >= MAX_POR_CORRIDA:
                    continue
                es = x.get("lang") == "es"
                hasta = datetime.fromisoformat(a["hasta"]).strftime("%d/%m %H:%M")
                params = [a["titulo_es" if es else "titulo_pt"], hasta, "https://avisosul.com/" + (x.get("cidade") or "app") + "/"]
                if PRUEBA:
                    print("PRUEBA ->", tel[:4] + "…" + tel[-2:], params)
                else:
                    try:
                        enviar(cfg, tel, params, x.get("lang"))
                    except Exception as e:                               # un envio fallido no frena al resto
                        print("whatsapp: error con", tel[-2:], type(e).__name__); continue
                x["enviados"].append(a["id"]); enviados += 1
        if not PRUEBA:
            h.seek(0); h.truncate(); h.write(json.dumps(ins, ensure_ascii=False))
        fcntl.flock(h, fcntl.LOCK_UN)
    print(f"whatsapp: {enviados} avisos {'simulados' if PRUEBA else 'enviados'}")


if __name__ == "__main__":
    main()
