#!/usr/bin/env python3
"""Aviso Sul - agente 1: lee los avisos oficiales del INMET y redacta los mensajes.

Solo usa la biblioteca estandar de Python y reglas fijas (sin IA, sin costo).
Modos:
  nuevos   avisos oficiales nuevos para la region (para correr cada 10 minutos)
  manana   resumen de la manana: avisos vigentes hoy
  vispera  avisos que empiezan manana

Por defecto NO publica nada: imprime los borradores. Con TELEGRAM_TOKEN y
TELEGRAM_OWNER envia los borradores al chat privado del titular. Solo publica
en el canal si ademas PUBLICAR=1 y TELEGRAM_CANAL estan definidos.
"""
import argparse, datetime as dt, html, json, os, re, sys, urllib.parse, urllib.request
import xml.etree.ElementTree as ET

FEED = "https://apiprevmet3.inmet.gov.br/avisos/rss"
REGION = os.environ.get("AVISOSUL_REGION") or "Oeste Catarinense"
NOMBRE_REGION = {"pt": os.environ.get("AVISOSUL_NOMBRE_PT", "Oeste catarinense"), "es": os.environ.get("AVISOSUL_NOMBRE_ES", "Oeste de Santa Catarina")}
ESTADO = os.environ.get("AVISOSUL_ESTADO", "estado.json")
# [I] Se asume que Inicio/Fim vienen en hora de Brasilia. Confirmar con un aviso real.
BRT = dt.timezone(dt.timedelta(hours=-3))

NIVEL = {  # Severidade del INMET -> (color pt, color es, emoji)
    "Perigo Potencial": ("amarelo", "amarillo", "\U0001F7E1"),
    "Perigo": ("laranja", "naranja", "\U0001F7E0"),
    "Grande Perigo": ("vermelho", "rojo", "\U0001F534"),
}
EVENTO_ES = {
    "Tempestade": "Tormenta", "Chuvas Intensas": "Lluvias intensas", "Vendaval": "Vientos fuertes",
    "Granizo": "Granizo", "Geada": "Helada", "Onda de Calor": "Ola de calor", "Onda de Frio": "Ola de frío",
    "Declínio de Temperatura": "Descenso de temperatura", "Acumulado de Chuva": "Lluvia acumulada",
    "Baixa Umidade": "Baja humedad", "Ventos Costeiros": "Vientos costeros", "Tempestade de Raios": "Tormenta eléctrica",
}
PIE = {
    "pt": "Fonte: INMET (aviso oficial). Aviso Sul é um serviço independente e não oficial. Emergência: 199 ou 193.",
    "es": "Fuente: INMET (aviso oficial). Aviso Sul es un servicio independiente y no oficial. Emergencia: 199 o 193.",
}


def leer_feed(origen):
    if os.path.exists(origen):
        return open(origen, "rb").read()
    req = urllib.request.Request(origen, headers={"User-Agent": "AvisoSul/0.1"})
    with urllib.request.urlopen(req, timeout=30) as r:
        return r.read()


def _fecha(txt):
    m = re.match(r"(\d{4})-(\d\d)-(\d\d) (\d\d):(\d\d)", txt or "")
    if not m:
        return None
    y, mo, d, h, mi = map(int, m.groups())
    try:  # [M] el feed real trae a veces "24:00": es la medianoche del dia siguiente
        base = dt.datetime(y, mo, d, tzinfo=BRT)
    except ValueError:
        return None
    return base + dt.timedelta(hours=h, minutes=mi)


def parsear(xml_bytes):
    avisos = []
    for it in ET.fromstring(xml_bytes).iter("item"):
        link = (it.findtext("link") or "").strip()
        desc = it.findtext("description") or ""
        campos = {}
        for th, td in re.findall(r"<th[^>]*>(.*?)</th>\s*<td[^>]*>(.*?)</td>", desc, flags=re.S | re.I):
            campos[html.unescape(re.sub(r"<[^>]+>", "", th)).strip()] = td.strip()
        limpio = lambda k: html.unescape(re.sub(r"<[^>]+>", "", campos.get(k, ""))).strip()
        area = re.sub(r"^Aviso para as [ÁA]reas:\s*", "", limpio("Área"))
        graf = re.search(r'href="([^"]+)"', campos.get("Link Gráfico", ""))
        m = re.search(r"(\d+)\s*$", link)
        avisos.append({
            "id": m.group(1) if m else link,
            "evento": limpio("Evento"), "severidad": limpio("Severidade"), "status": limpio("Status"),
            "inicio": _fecha(limpio("Início")), "fin": _fecha(limpio("Fim")),
            "descripcion": limpio("Descrição"),
            "areas": [a.strip() for a in area.split(",") if a.strip()],
            # [M] en el feed real el enlace legible es https://avisos.inmet.gov.br/<numero>
            "url": graf.group(1) if graf else (f"https://avisos.inmet.gov.br/{m.group(1)}" if m else link),
        })
    return avisos


def de_la_region(avisos):
    return [a for a in avisos if any(x.lower() == REGION.lower() for x in a["areas"]) and a["inicio"] and a["fin"]]


def _cuando(a, lang):
    i, f = a["inicio"], a["fin"]
    if i.date() == f.date():
        de, ate = ("das", "às") if lang == "pt" else ("de", "a")
        return f"{i:%d/%m} {de} {i:%H:%M} {ate} {f:%H:%M}"
    de, ate = ("de", "até") if lang == "pt" else ("del", "hasta el")
    return f"{de} {i:%d/%m %H:%M} {ate} {f:%d/%m %H:%M}"


def linea(a, lang):
    cp, ce, emo = NIVEL.get(a["severidad"], ("", "", "⚠️"))
    if lang == "pt":
        return f"{emo} {a['evento']} — aviso {cp or a['severidad']} ({a['severidad']}), {_cuando(a, 'pt')}"
    ev = EVENTO_ES.get(a["evento"], a["evento"])
    return f"{emo} {ev} — aviso {ce or a['severidad']}, {_cuando(a, 'es')}"


def msg_nuevo(a, lang):
    t = {"pt": f"AVISO OFICIAL · {NOMBRE_REGION['pt']}", "es": f"AVISO OFICIAL · {NOMBRE_REGION['es']}"}[lang]
    cuerpo = [t, "", linea(a, lang)]
    if lang == "pt" and a["descripcion"]:
        cuerpo += ["", f"INMET: {a['descripcion']}"]
    cuerpo += ["", ("Texto original: " if lang == "pt" else "Texto original (en portugués): ") + a["url"], "", PIE[lang]]
    return "\n".join(cuerpo)


def msg_lista(avisos, lang, modo, dia):
    tit = {
        ("manana", "pt"): f"RESUMO DA MANHÃ · {dia:%d/%m} · {NOMBRE_REGION['pt']}",
        ("manana", "es"): f"RESUMEN DE LA MAÑANA · {dia:%d/%m} · {NOMBRE_REGION['es']}",
        ("vispera", "pt"): f"PARA AMANHÃ · {dia:%d/%m} · {NOMBRE_REGION['pt']}",
        ("vispera", "es"): f"PARA MAÑANA · {dia:%d/%m} · {NOMBRE_REGION['es']}",
    }[(modo, lang)]
    if not avisos:
        vacio = {
            ("manana", "pt"): "Sem avisos oficiais do INMET em vigor para a região neste momento. Isso não significa que não há risco.",
            ("manana", "es"): "Sin avisos oficiales del INMET vigentes para la región en este momento. Eso no significa que no haya riesgo.",
        }.get((modo, lang))
        return "\n".join([tit, "", vacio, "", PIE[lang]]) if vacio else None  # vispera sin avisos: no se publica
    cab = {"pt": "Avisos oficiais do INMET:", "es": "Avisos oficiales del INMET:"}[lang]
    cuerpo = [tit, "", cab] + [linea(a, lang) + "\n" + a["url"] for a in avisos] + ["", PIE[lang]]
    return "\n".join(cuerpo)


NIVEL_ES = {"Perigo Potencial": "amarillo", "Perigo": "naranja", "Grande Perigo": "rojo"}


def ciudades(pais):
    """Lee ciudades.json (misma lista que usa la pagina)."""
    ruta = os.path.join(os.path.dirname(os.path.abspath(__file__)), "ciudades.json")
    return [c for c in json.load(open(ruta, encoding="utf-8")) if c["pais"] == pais]


def estado_publico(avisos, ahora, slug):
    """Escribe datos/<slug>.json con los avisos vigentes; la pagina lo lee para el estado en vivo."""
    vig = [a for a in avisos if a["inicio"] <= ahora < a["fin"]]
    # [M] el INMET emite varios avisos del mismo fenomeno con areas que se superponen:
    # para la pagina queda uno por fenomeno, el de mayor nivel.
    orden = {"Perigo Potencial": 1, "Perigo": 2, "Grande Perigo": 3}
    mejor = {}
    for a in vig:
        k = a["evento"]
        if k not in mejor or orden.get(a["severidad"], 0) > orden.get(mejor[k]["severidad"], 0):
            mejor[k] = a
    vig = sorted(mejor.values(), key=lambda a: -orden.get(a["severidad"], 0))
    os.makedirs("datos", exist_ok=True)
    pub = [{
        "titulo_pt": f"{a['evento']} — aviso {NIVEL.get(a['severidad'], ('',))[0] or a['severidad']}",
        "titulo_es": f"{EVENTO_ES.get(a['evento'], a['evento'])} — aviso {NIVEL_ES.get(a['severidad'], a['severidad'])}",
        "nivel": NIVEL_ES.get(a["severidad"], ""), "desde": a["inicio"].isoformat(timespec="seconds"),
        "hasta": a["fin"].isoformat(timespec="seconds"), "url": a["url"]} for a in vig]
    json.dump({"ciudad": slug, "fuente": "INMET", "actualizado": ahora.isoformat(timespec="seconds"), "avisos": pub},
              open(os.path.join("datos", slug + ".json"), "w", encoding="utf-8"), ensure_ascii=False)
    historial(slug, "INMET", pub, ahora)


def poligonos(xml_bytes):
    """Contornos de un aviso en formato CAP: <polygon>lat,lon lat,lon ...</polygon>."""
    out = []
    for el in ET.fromstring(xml_bytes).iter():
        if el.tag.split("}")[-1] != "polygon" or not (el.text or "").strip():
            continue
        pts = []
        for par in el.text.split():
            v = par.split(",")
            if len(v) >= 2:
                try:
                    pts.append((float(v[0]), float(v[1])))
                except ValueError:
                    pass
        if len(pts) < 3:
            continue
        # [I] el estandar pide lat,lon; si vienen invertidos (lon < -36 en promedio) se dan vuelta
        if sum(p[0] for p in pts) / len(pts) < -36:
            pts = [(b, a) for a, b in pts]
        paso = max(1, len(pts) // 300)  # se aliviana para el celular
        out.append([[round(a, 3), round(b, 3)] for a, b in pts[::paso]])
    return out


def zonas(todos, ahora, regiones):
    """Escribe datos/zonas.json con el contorno de cada aviso vigente que toca alguna ciudad; la pagina lo pinta en el mapa."""
    # todos los avisos vigentes; despues quedan los que tocan una ciudad o caen en el sur (PR, SC, RS)
    reg = {r.lower() for r in regiones}
    vig = [a for a in todos if a["inicio"] and a["fin"] and a["inicio"] <= ahora < a["fin"]]
    sur = lambda ps: any(-34.5 <= y <= -22.0 and -58.5 <= x <= -47.5 for p in ps for y, x in p)
    os.makedirs("cap", exist_ok=True)
    orden = {"Perigo Potencial": 1, "Perigo": 2, "Grande Perigo": 3}
    out = []
    for a in sorted(vig, key=lambda x: orden.get(x["severidad"], 0)):
        cache = os.path.join("cap", f"{a['id']}.json")
        polys = json.load(open(cache)) if os.path.exists(cache) else None
        if polys is None:
            try:
                polys = poligonos(leer_feed(f"https://apiprevmet3.inmet.gov.br/avisos/rss/{a['id']}"))
                json.dump(polys, open(cache, "w"))
            except Exception as e:  # un aviso sin contorno no frena al resto
                print("zonas: sin contorno para", a["id"], type(e).__name__)
                continue
        if polys and (any(x.lower() in reg for x in a["areas"]) or sur(polys)):
            out.append({"id": a["id"], "nivel": NIVEL_ES.get(a["severidad"], ""),
                        "titulo_pt": f"{a['evento']} — aviso {NIVEL.get(a['severidad'], ('',))[0] or a['severidad']}",
                        "titulo_es": f"{EVENTO_ES.get(a['evento'], a['evento'])} — aviso {NIVEL_ES.get(a['severidad'], a['severidad'])}",
                        "desde": a["inicio"].isoformat(timespec="seconds"), "hasta": a["fin"].isoformat(timespec="seconds"),
                        "url": a["url"], "poligonos": polys})
    os.makedirs("datos", exist_ok=True)
    json.dump({"fuente": "INMET", "actualizado": ahora.isoformat(timespec="seconds"), "avisos": out},
              open(os.path.join("datos", "zonas.json"), "w", encoding="utf-8"), ensure_ascii=False)
    print(f"zonas: {len(out)} de {len(vig)} avisos con contorno, {sum(len(x['poligonos']) for x in out)} poligonos")


def historial(slug, fuente, vigentes, ahora):
    """Deja constancia diaria de los avisos oficiales vistos en cada ciudad: historial/<ciudad>/<AAAA-MM-DD>.json.
    Sirve de base para el informe del seguro: el historial publico del repositorio fecha cada registro."""
    carpeta = os.path.join("historial", slug)
    os.makedirs(carpeta, exist_ok=True)
    ruta = os.path.join(carpeta, f"{ahora:%Y-%m-%d}.json")
    reg = json.load(open(ruta, encoding="utf-8")) if os.path.exists(ruta) else {"ciudad": slug, "fuente": fuente, "avisos": {}}
    t = ahora.isoformat(timespec="seconds")
    for a in vigentes:
        e = reg["avisos"].setdefault(a["url"], dict(a, visto_desde=t))
        e["visto_hasta"] = t
    json.dump(reg, open(ruta, "w", encoding="utf-8"), ensure_ascii=False)


def enviar(texto, chat):
    tok = os.environ.get("TELEGRAM_TOKEN")
    if not (tok and chat):
        return False
    datos = urllib.parse.urlencode({"chat_id": chat, "text": texto, "disable_web_page_preview": "true"}).encode()
    urllib.request.urlopen(f"https://api.telegram.org/bot{tok}/sendMessage", datos, timeout=30).read()
    return True


def salida(borradores, slug):
    """borradores: lista de (etiqueta, texto_pt, texto_es). El canal de cada ciudad es TELEGRAM_CANAL_<SLUG>."""
    owner, canal = os.environ.get("TELEGRAM_OWNER"), os.environ.get("TELEGRAM_CANAL_" + slug.upper())
    publicar = os.environ.get("PUBLICAR") == "1" and canal
    for etiqueta, pt, es in borradores:
        for lang, txt in (("pt", pt), ("es", es)):
            if not txt:
                continue
            print(f"\n===== {etiqueta} [{lang}] =====\n{txt}")
            enviar(f"[BORRADOR {etiqueta} {lang}] Para pegar en el canal de WhatsApp:\n\n{txt}", owner)
            if publicar and lang == "pt":
                enviar(txt, canal)


def main():
    p = argparse.ArgumentParser()
    p.add_argument("modo", choices=["nuevos", "manana", "vispera"])
    p.add_argument("--feed", default=FEED)
    p.add_argument("--ahora", help="AAAA-MM-DD HH:MM, para pruebas")
    a = p.parse_args()
    global REGION, NOMBRE_REGION
    ahora = _fecha(a.ahora) if a.ahora else dt.datetime.now(BRT)
    todos = parsear(leer_feed(a.feed))
    vistos = json.load(open(ESTADO)) if os.path.exists(ESTADO) else {}
    if not isinstance(vistos, dict):
        vistos = {}
    hubo = False
    for c in ciudades("br"):
        slug, REGION = c["slug"], c["inmet"]
        NOMBRE_REGION = {"pt": c["nombre"], "es": c["nombre"]}
        avisos = de_la_region(todos)
        estado_publico(avisos, ahora, slug)
        borr = []
        if a.modo == "nuevos":
            ya = set(vistos.get(slug, []))
            nuevos = [x for x in avisos if x["id"] not in ya and x["fin"] > ahora]
            borr = [(f"{slug} aviso {x['id']}", msg_nuevo(x, "pt"), msg_nuevo(x, "es")) for x in nuevos]
            vistos[slug] = sorted(ya | {x["id"] for x in avisos})
        elif a.modo == "manana":
            hoy = [x for x in avisos if x["inicio"].date() <= ahora.date() <= x["fin"].date() and x["fin"] > ahora]
            borr = [(f"{slug} resumen", msg_lista(hoy, "pt", "manana", ahora), msg_lista(hoy, "es", "manana", ahora))]
        else:
            man = ahora + dt.timedelta(days=1)
            prox = [x for x in avisos if x["inicio"].date() <= man.date() <= x["fin"].date()]
            borr = [(f"{slug} vispera", msg_lista(prox, "pt", "vispera", man), msg_lista(prox, "es", "vispera", man))]
        salida(borr, slug)
        hubo = hubo or any(pt for _, pt, _ in borr)
    if a.modo == "nuevos":
        json.dump(vistos, open(ESTADO, "w"))
        zonas(todos, ahora, [c["inmet"] for c in ciudades("br")])
    if not hubo:
        print("Sin mensajes para este modo.")


if __name__ == "__main__":
    sys.exit(main())
