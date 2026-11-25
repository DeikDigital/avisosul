#!/usr/bin/env python3
"""Aviso Sul - agente 5: estaciones de servicio y cargadores electricos para el modo Car.

Los servidores publicos de Overpass (OpenStreetMap) suelen estar saturados y tardan o rechazan las consultas
en el momento. Este agente baja de a poco, por cuadros de 2 x 2 grados, todas las estaciones del sur de Brasil
y de Argentina y las guarda en datos/est/<lat>_<lon>.json. La app las lee de ahi al instante.

- Cada corrida procesa los cuadros que faltan o tienen mas de 14 dias, empezando por los de las ciudades.
- Usa un tiempo maximo por corrida (TOPE) y un candado, para no pisarse con la corrida siguiente.
- Datos: OpenStreetMap, licencia ODbL (se cita en la app).
"""
import csv, hashlib, io, json, os, sys, time, urllib.parse, urllib.request

BASE = os.path.dirname(os.path.abspath(__file__))
SALIDA = os.path.join("datos", "est")
ESTADO = os.path.join(SALIDA, "_estado.json")
CANDADO = os.path.join(SALIDA, "_candado")
TOPE = 260          # segundos por corrida
VIEJO = 14 * 86400  # se renueva cada 14 dias
PASO = 2            # grados por cuadro
SERVIDORES = ["https://overpass.private.coffee/api/interpreter", "https://overpass.kumi.systems/api/interpreter",
              "https://overpass-api.de/api/interpreter", "https://maps.mail.ru/osm/tools/overpass/api/interpreter"]
# Argentina: dataset oficial "Precios en surtidor" de la Secretaria de Energia (todas las estaciones con
# coordenadas y precios declarados). Se baja una vez por dia y llena los cuadros argentinos de una sola vez.
SE_URL = ("http://datos.energia.gob.ar/dataset/1c181390-5045-475e-94dc-410429be4b17/resource/"
          "80ac25de-a44a-4445-9215-090cf55cfda5/download/precios-en-surtidor-resolucin-3142016.csv")
SE_MARCA = os.path.join(SALIDA, "_se2.json")
# zonas cubiertas: (lat min, lat max, lon min, lon max)
ZONAS = [(-56, -20, -74, -52),   # Argentina
         (-34, -20, -58, -44)]   # Sur y Sudeste de Brasil (PR, SC, RS, SP)


def cuadros():
    vistos = []
    for la0, la1, lo0, lo1 in ZONAS:
        for la in range(la0, la1, PASO):
            for lo in range(lo0, lo1, PASO):
                k = "%d_%d" % (la, lo)
                if k not in vistos:
                    vistos.append(k)
    return vistos


def clave(lat, lon):
    return "%d_%d" % (int((lat // PASO) * PASO), int((lon // PASO) * PASO))


def consulta(k):
    la, lo = map(int, k.split("_"))
    bb = "%d,%d,%d,%d" % (la, lo, la + PASO, lo + PASO)
    return '[out:json][timeout:150];nwr["amenity"~"^(fuel|charging_station)$"](%s);out center tags;' % bb


def bajar(q):
    datos = urllib.parse.urlencode({"data": q}).encode()
    for u in SERVIDORES:
        try:
            req = urllib.request.Request(u, data=datos, headers={"User-Agent": "AvisoSul/1.0 (+https://avisosul.com)"})
            with urllib.request.urlopen(req, timeout=170) as r:
                j = json.loads(r.read().decode("utf-8"))
                if "elements" in j:
                    return j["elements"]
        except Exception as e:
            print("  falla", u.split("/")[2], str(e)[:80])
    return None


def compacto(el):
    t = el.get("tags", {})
    lat = el.get("lat") or el.get("center", {}).get("lat")
    lon = el.get("lon") or el.get("center", {}).get("lon")
    if lat is None or lon is None:
        return None
    x = {"i": el["type"][0] + str(el["id"]), "a": round(lat, 5), "o": round(lon, 5), "k": "c" if t.get("amenity") == "charging_station" else "f"}
    for corto, largo in (("b", "brand"), ("n", "name"), ("h", "opening_hours"), ("t", "phone")):
        v = t.get(largo) or (t.get("contact:phone") if largo == "phone" else None) or (t.get("operator") if largo == "brand" else None)
        if v:
            x[corto] = v[:80]
    if t.get("addr:street"):
        x["d"] = (t["addr:street"] + (" " + t["addr:housenumber"] if t.get("addr:housenumber") else "") + (", " + t["addr:city"] if t.get("addr:city") else ""))[:100]
    f = [k[5:] for k, v in t.items() if k.startswith("fuel:") and v == "yes"]
    if f:
        x["f"] = f[:8]
    return x


def urls_se():
    """Pide al catalogo de datos abiertos (CKAN) la direccion actual del CSV de precios vigentes; si no, usa la conocida."""
    out = []
    for api in ("http://datos.energia.gob.ar/api/3/action/package_show?id=precios-en-surtidor",
                "https://datos.gob.ar/api/3/action/package_show?id=energia-precios-surtidor---resolucion-3142016"):
        try:
            j = json.loads(urllib.request.urlopen(urllib.request.Request(api, headers={"User-Agent": "AvisoSul/1.0"}), timeout=40).read().decode("utf-8"))
            for r in j.get("result", {}).get("resources", []):
                u = r.get("url", ""); n = (r.get("name", "") + " " + u).lower()
                if u.lower().endswith(".csv") and "vigente" in n and u not in out:
                    out.insert(0, u)
                elif u.lower().endswith(".csv") and "surtidor" in n and u not in out:
                    out.append(u)
        except Exception as e:
            print("  SE: catalogo", api.split("/")[2], str(e)[:60])
    if SE_URL not in out:
        out.append(SE_URL)
    return out[:4]


def argentina():
    """Baja el CSV oficial y arma los cuadros de Argentina. Devuelve la cantidad de cuadros escritos (0 si falla)."""
    if os.path.exists(SE_MARCA):
        try:
            m = json.load(open(SE_MARCA))
        except Exception:
            m = {}
        if time.time() - m.get("t", 0) < (86400 if m.get("ok") else 7200):
            return 0
    raw, usada = None, None
    for u in urls_se():
        try:
            req = urllib.request.Request(u, headers={"User-Agent": "AvisoSul/1.0 (+https://avisosul.com)"})
            raw = urllib.request.urlopen(req, timeout=120).read().decode("utf-8", "replace"); usada = u
            if raw.count("\n") > 500:
                break
        except Exception as e:
            print("  SE: falla", u[:70], str(e)[:60])
    if not raw:
        json.dump({"t": int(time.time()), "ok": False}, open(SE_MARCA, "w")); return 0
    filas = csv.DictReader(io.StringIO(raw.lstrip("\ufeff")))
    est = {}
    for f in filas:
        g = {k.strip().lower(): (v or "").strip() for k, v in f.items() if k}
        try:
            lat, lon = float(g.get("latitud", "").replace(",", ".")), float(g.get("longitud", "").replace(",", "."))
        except ValueError:
            continue
        if not (-56 < lat < -21 and -74 < lon < -53):
            continue
        clave_e = g.get("cuit", "") + "|" + g.get("direccion", "") + "|" + str(round(lat, 4))
        x = est.setdefault(clave_e, {"i": "s" + hashlib.md5(clave_e.encode()).hexdigest()[:10], "a": round(lat, 5), "o": round(lon, 5), "k": "f",
                                      "b": (g.get("empresabandera") or g.get("empresa") or "")[:60], "n": g.get("empresa", "")[:60],
                                      "d": (g.get("direccion", "") + (", " + g.get("localidad", "").title() if g.get("localidad") else ""))[:100], "p": {}})
        prod, precio = g.get("producto", ""), g.get("precio", "").replace(",", ".")
        if prod and precio:
            try:
                pv = float(precio)
                if pv > 0 and (g.get("tipohorario", "").lower().startswith("diurno") or prod not in x["p"]):
                    x["p"][prod[:40]] = [round(pv, 2), g.get("fecha_vigencia", "")[:10]]
            except ValueError:
                pass
    if len(est) < 500:
        print("  SE: datos insuficientes", len(est)); json.dump({"t": int(time.time()), "ok": False}, open(SE_MARCA, "w")); return 0
    por = {}
    for x in est.values():
        por.setdefault(clave(x["a"], x["o"]), []).append(x)
    estado = json.load(open(ESTADO)) if os.path.exists(ESTADO) else {}
    for k, lista in por.items():
        # se suman los cargadores electricos de OpenStreetMap si el cuadro ya los tenia
        viejo = os.path.join(SALIDA, k + ".json")
        if os.path.exists(viejo):
            try:
                lista = lista + [e for e in json.load(open(viejo)).get("e", []) if e.get("k") == "c"]
            except Exception:
                pass
        json.dump({"t": int(time.time()), "fuente": "SE", "e": lista}, open(viejo, "w"), ensure_ascii=False, separators=(",", ":"))
        estado[k] = int(time.time())
    json.dump(estado, open(ESTADO, "w"))
    json.dump({"t": int(time.time()), "ok": True, "estaciones": len(est), "cuadros": len(por), "url": usada}, open(SE_MARCA, "w"))
    print("  SE: %d estaciones en %d cuadros" % (len(est), len(por)))
    return len(por)


def main():
    os.makedirs(SALIDA, exist_ok=True)
    if os.path.exists(CANDADO) and time.time() - os.path.getmtime(CANDADO) < TOPE + 120:
        print("estaciones: otra corrida en curso"); return
    open(CANDADO, "w").write(str(time.time()))
    try:
        argentina()
        estado = json.load(open(ESTADO)) if os.path.exists(ESTADO) else {}
        ciudades = json.load(open(os.path.join(BASE, "ciudades.json"), encoding="utf-8"))
        ciudades.sort(key=lambda c: 0 if c.get("slug") == "cordoba" else 1)
        prio = []
        for c in ciudades:
            for dla in (-PASO, 0, PASO):
                for dlo in (-PASO, 0, PASO):
                    k = clave(c["lat"] + dla, c["lon"] + dlo)
                    if k not in prio:
                        prio.append(k)
        todos = prio + [k for k in cuadros() if k not in prio]
        ahora = time.time(); inicio = ahora; hechos = 0
        pend = [k for k in todos if ahora - estado.get(k, 0) > VIEJO]
        for k in pend:
            if time.time() - inicio > TOPE:
                break
            els = bajar(consulta(k))
            if els is None:
                print("  sin respuesta para", k); break
            lista = [x for x in (compacto(e) for e in els) if x]
            json.dump({"t": int(time.time()), "e": lista}, open(os.path.join(SALIDA, k + ".json"), "w"), ensure_ascii=False, separators=(",", ":"))
            estado[k] = int(time.time()); hechos += 1
            json.dump(estado, open(ESTADO, "w"))
            print("  cuadro", k, len(lista), "estaciones")
            time.sleep(2)
        print("estaciones: %d cuadros nuevos, %d pendientes" % (hechos, len(pend) - hechos))
    finally:
        try:
            os.remove(CANDADO)
        except OSError:
            pass


if __name__ == "__main__":
    main()
