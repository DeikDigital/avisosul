#!/usr/bin/env python3
"""Aviso Sul - agente 5: estaciones de servicio y cargadores electricos para el modo Car.

Los servidores publicos de Overpass (OpenStreetMap) suelen estar saturados y tardan o rechazan las consultas
en el momento. Este agente baja de a poco, por cuadros de 2 x 2 grados, todas las estaciones del sur de Brasil
y de Argentina y las guarda en datos/est/<lat>_<lon>.json. La app las lee de ahi al instante.

- Cada corrida procesa los cuadros que faltan o tienen mas de 14 dias, empezando por los de las ciudades.
- Usa un tiempo maximo por corrida (TOPE) y un candado, para no pisarse con la corrida siguiente.
- Datos: OpenStreetMap, licencia ODbL (se cita en la app).
"""
import json, os, sys, time, urllib.parse, urllib.request

BASE = os.path.dirname(os.path.abspath(__file__))
SALIDA = os.path.join("datos", "est")
ESTADO = os.path.join(SALIDA, "_estado.json")
CANDADO = os.path.join(SALIDA, "_candado")
TOPE = 260          # segundos por corrida
VIEJO = 14 * 86400  # se renueva cada 14 dias
PASO = 2            # grados por cuadro
SERVIDORES = ["https://overpass.private.coffee/api/interpreter", "https://overpass.kumi.systems/api/interpreter",
              "https://overpass-api.de/api/interpreter", "https://maps.mail.ru/osm/tools/overpass/api/interpreter"]
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


def main():
    os.makedirs(SALIDA, exist_ok=True)
    if os.path.exists(CANDADO) and time.time() - os.path.getmtime(CANDADO) < TOPE + 120:
        print("estaciones: otra corrida en curso"); return
    open(CANDADO, "w").write(str(time.time()))
    try:
        estado = json.load(open(ESTADO)) if os.path.exists(ESTADO) else {}
        ciudades = json.load(open(os.path.join(BASE, "ciudades.json"), encoding="utf-8"))
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
