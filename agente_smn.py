#!/usr/bin/env python3
"""Aviso Sul - agente 2 (Argentina): lee los Avisos a muy Corto Plazo del SMN y redacta el mensaje.

Solo biblioteca estandar y reglas fijas (sin IA, sin costo). Un unico modo: avisos nuevos
para las zonas configuradas (para correr cada 10 minutos).

Por defecto NO publica nada: imprime los borradores. Con TELEGRAM_TOKEN y TELEGRAM_OWNER
los envia al chat privado del titular. Solo publica en el canal si ademas PUBLICAR=1 y
TELEGRAM_CANAL_AR estan definidos.

[M] Estructura del feed leida el 24/11/2026: item con title, link, description, category, guid;
    sin pubDate; link = .../AAAA_MM_DD_HHMM_cap_es.xml; description =
    "Afectando parcialmente los siguientes Partidos y Departamentos: PROVINCIA: Depto - Depto.PROVINCIA: ..."
[I] La hora del nombre del archivo se toma como hora argentina. Confirmar con un aviso real.
[I] No se conoce la vigencia de cada aviso: se descartan los emitidos hace mas de VIGENCIA_H horas.
[I] Este feed trae solo avisos a muy corto plazo. Las alertas amarilla/naranja/roja del SMN
    salen por otra via que todavia no esta verificada.
"""
import argparse, datetime as dt, json, os, re, sys, unicodedata, urllib.parse, urllib.request
import xml.etree.ElementTree as ET

FEED = "https://ssl.smn.gob.ar/feeds/CAP/avisocortoplazo/rss_acpCAP.xml"
# "PROVINCIA:Depto,Depto;PROVINCIA:Depto"
ZONAS = os.environ.get("AVISOSUL_AR_ZONAS", "MISIONES:Iguazú")
NOMBRE = os.environ.get("AVISOSUL_AR_NOMBRE", "Puerto Iguazú")
ESTADO = os.environ.get("AVISOSUL_AR_ESTADO", "estado_ar.json")
VIGENCIA_H = float(os.environ.get("AVISOSUL_AR_VIGENCIA_H", "3"))
ART = dt.timezone(dt.timedelta(hours=-3))
PIE = ("Fuente: SMN (aviso oficial a muy corto plazo). Aviso Sul es un servicio independiente y no oficial. "
       "Emergencias: 911 · Bomberos 100 · Defensa Civil 103.")


def norm(s):
    s = unicodedata.normalize("NFD", s or "")
    return re.sub(r"\s+", " ", "".join(c for c in s if unicodedata.category(c) != "Mn")).strip().lower()


def zonas_config():
    z = {}
    for parte in ZONAS.split(";"):
        if ":" in parte:
            prov, deps = parte.split(":", 1)
            z[norm(prov)] = {norm(d) for d in deps.split(",") if d.strip()}
    return z


def leer_feed(origen):
    if os.path.exists(origen):
        return open(origen, "rb").read()
    req = urllib.request.Request(origen, headers={"User-Agent": "AvisoSul/0.1"})
    with urllib.request.urlopen(req, timeout=30) as r:
        return r.read()


def areas(desc):
    """Devuelve {provincia_normalizada: [departamentos tal como vienen]}."""
    txt = re.sub(r"^.*?Departamentos:\s*", "", desc or "", flags=re.S)
    out = {}
    patron = r"([A-ZÁÉÍÓÚÜÑ][A-ZÁÉÍÓÚÜÑ ]{2,}):\s*(.*?)(?=[A-ZÁÉÍÓÚÜÑ][A-ZÁÉÍÓÚÜÑ ]{2,}:|$)"
    for prov, deps in re.findall(patron, txt, flags=re.S):
        lista = [d.strip(" .\n\t") for d in deps.split(" - ")]
        out[norm(prov)] = [d for d in lista if d]
    return out


def parsear(xml_bytes):
    avisos = []
    for it in ET.fromstring(xml_bytes).iter("item"):
        link = (it.findtext("link") or it.findtext("guid") or "").strip()
        m = re.search(r"(\d{4})_(\d\d)_(\d\d)_(\d\d)(\d\d)_cap", link)
        avisos.append({
            "id": link,
            "titulo": (it.findtext("title") or "").strip(),
            "areas": areas(it.findtext("description") or ""),
            "emitido": dt.datetime(*map(int, m.groups()), tzinfo=ART) if m else None,
            "url": link,
        })
    return avisos


def de_la_zona(avisos):
    conf, out = zonas_config(), []
    for a in avisos:
        tocados = [d for prov, deps in a["areas"].items() for d in deps if norm(d) in conf.get(prov, ())]
        if tocados and a["emitido"]:
            out.append(dict(a, tocados=tocados))
    return out


def mensaje(a):
    titulo = a["titulo"].capitalize()
    deps = ", ".join(a["tocados"])
    return "\n".join([
        f"AVISO OFICIAL A MUY CORTO PLAZO · {NOMBRE}", "",
        f"⚠️ {titulo}", "",
        f"SMN, emitido el {a['emitido']:%d/%m} a las {a['emitido']:%H:%M}. Afecta parcialmente: {deps}.", "",
        f"Texto original: {a['url']}", "", PIE,
    ])


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


def main():
    p = argparse.ArgumentParser()
    p.add_argument("modo", choices=["nuevos"], nargs="?", default="nuevos")
    p.add_argument("--feed", default=FEED)
    p.add_argument("--ahora", help="AAAA-MM-DD HH:MM, para pruebas")
    a = p.parse_args()
    ahora = dt.datetime.strptime(a.ahora, "%Y-%m-%d %H:%M").replace(tzinfo=ART) if a.ahora else dt.datetime.now(ART)
    global ZONAS, NOMBRE
    todos = parsear(leer_feed(a.feed))
    ruta = os.path.join(os.path.dirname(os.path.abspath(__file__)), "ciudades.json")
    lista = [c for c in json.load(open(ruta, encoding="utf-8")) if c["pais"] == "ar"]
    vistos = json.load(open(ESTADO)) if os.path.exists(ESTADO) else {}
    if not isinstance(vistos, dict):
        vistos = {}
    limite = dt.timedelta(hours=VIGENCIA_H)
    owner = os.environ.get("TELEGRAM_OWNER")
    hubo = False
    os.makedirs("datos", exist_ok=True)
    for c in lista:
        slug, ZONAS, NOMBRE = c["slug"], c["smn"], c["nombre"]
        avisos = de_la_zona(todos)
        vig = [x for x in avisos if dt.timedelta(0) <= ahora - x["emitido"] <= limite]
        pub = [{
            "titulo_es": x["titulo"].capitalize(), "titulo_pt": x["titulo"].capitalize(), "nivel": "",
            "desde": x["emitido"].isoformat(timespec="seconds"), "hasta": (x["emitido"] + limite).isoformat(timespec="seconds"),
            "url": x["url"]} for x in vig]
        json.dump({"ciudad": slug, "fuente": "SMN", "actualizado": ahora.isoformat(timespec="seconds"), "avisos": pub},
                  open(os.path.join("datos", slug + ".json"), "w", encoding="utf-8"), ensure_ascii=False)
        historial(slug, "SMN", pub, ahora)
        ya = set(vistos.get(slug, []))
        nuevos = [x for x in vig if x["id"] not in ya]
        vistos[slug] = sorted(ya | {x["id"] for x in avisos})
        canal = os.environ.get("TELEGRAM_CANAL_" + slug.upper())
        publicar = os.environ.get("PUBLICAR") == "1" and canal
        for x in nuevos:
            txt = mensaje(x)
            print(f"\n===== {slug} aviso {x['emitido']:%Y-%m-%d %H:%M} [es] =====\n{txt}")
            enviar(f"[BORRADOR {slug}] Para pegar en el canal de WhatsApp:\n\n{txt}", owner)
            if publicar:
                enviar(txt, canal)
        hubo = hubo or bool(nuevos)
    json.dump(vistos, open(ESTADO, "w"))
    if not hubo:
        print("Sin mensajes para este modo.")


if __name__ == "__main__":
    sys.exit(main())
