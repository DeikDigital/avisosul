#!/usr/bin/env python3
"""Aviso Sul - agente 3: condiciones de ahora y de la proxima hora para la pagina.

Lee el pronostico por coordenadas de MET Norway (Locationforecast 2.0, licencia CC BY 4.0,
uso comercial permitido con atribucion) y escribe datos/<ciudad>_tiempo.json.
Es un MODELO de pronostico, no una medicion de estacion: la pagina lo dice.

[M] Estructura leida el 24/11/2026: properties.timeseries[].time (UTC), .data.instant.details
    (air_temperature, relative_humidity, wind_speed en m/s), .data.next_1_hours.summary.symbol_code
    y .details.precipitation_amount.
[M] MET Norway exige un User-Agent con datos de contacto; sin eso responde 403.
    PENDIENTE: poner el correo real de Aviso Sul en AVISOSUL_CONTACTO.
"""
import datetime as dt, json, os, sys, urllib.request

API = "https://api.met.no/weatherapi/locationforecast/2.0/compact?lat={lat}&lon={lon}"
CONTACTO = os.environ.get("AVISOSUL_CONTACTO") or "https://avisosul.com"
_RUTA = os.path.join(os.path.dirname(os.path.abspath(__file__)), "ciudades.json")
CIUDADES = {c["slug"]: (c["lat"], c["lon"]) for c in json.load(open(_RUTA, encoding="utf-8"))}
# Proximas 12 horas con probabilidad de lluvia y rafagas. [M] El plan gratuito de Open-Meteo es solo para uso
# no comercial: hay que contratar su licencia antes de cobrar o pautar. Si falla, la pagina oculta la tira.
OM = ("https://api.open-meteo.com/v1/forecast?latitude={lat}&longitude={lon}&hourly=temperature_2m,"
      "precipitation_probability,wind_gusts_10m,weather_code&forecast_hours=12&timezone=America%2FSao_Paulo")
WMO = [((95, 96, 99), "⛈️"), ((80, 81, 82, 61, 63, 65, 66, 67), "🌧️"), ((51, 53, 55, 56, 57), "🌦️"), ((71, 73, 75, 77, 85, 86), "🌨️"),
       ((45, 48), "🌫️"), ((3,), "☁️"), ((2,), "⛅"), ((1,), "🌤️"), ((0,), "☀️")]


def horas(doc):
    h = doc["hourly"]
    ico = lambda c: next((i for cs, i in WMO if c in cs), "")
    return [{"h": t[11:13] + "h", "temp": round(h["temperature_2m"][n]), "prob": h["precipitation_probability"][n],
             "racha": round(h["wind_gusts_10m"][n]), "ico": ico(h["weather_code"][n])} for n, t in enumerate(h["time"])]
LOCAL = dt.timezone(dt.timedelta(hours=-3))

# (clave dentro del symbol_code, texto pt, texto es, icono) - se evalua en orden
TABLA = [
    ("thunder", "Trovoadas", "Tormenta eléctrica", "⛈️"), ("heavyrain", "Chuva forte", "Lluvia fuerte", "🌧️"),
    ("lightrain", "Chuva fraca", "Lluvia débil", "🌦️"), ("rain", "Chuva", "Lluvia", "🌧️"),
    ("sleet", "Chuva com neve", "Aguanieve", "🌨️"), ("snow", "Neve", "Nieve", "🌨️"), ("fog", "Nevoeiro", "Niebla", "🌫️"),
    ("partlycloudy", "Parcialmente nublado", "Parcialmente nublado", "⛅"), ("cloudy", "Nublado", "Nublado", "☁️"),
    ("fair", "Poucas nuvens", "Algo nublado", "🌤️"), ("clearsky", "Céu limpo", "Despejado", "☀️"),
]


def describir(code):
    noche = (code or "").endswith("_night")
    for clave, pt, es, ico in TABLA:
        if clave in (code or ""):
            if noche and ico in ("☀️", "🌤️", "⛅"):
                ico = "🌙"
            return {"code": code, "pt": pt, "es": es, "ico": ico}
    return {"code": code or "", "pt": "", "es": "", "ico": ""}


def leer(origen):
    if os.path.exists(origen):
        return json.load(open(origen, encoding="utf-8"))
    req = urllib.request.Request(origen, headers={"User-Agent": f"AvisoSul/0.1 {CONTACTO}"})
    with urllib.request.urlopen(req, timeout=30) as r:
        return json.load(r)


def resumen(doc, ahora):
    serie = doc["properties"]["timeseries"]
    hora = ahora.astimezone(dt.timezone.utc).replace(minute=0, second=0, microsecond=0)
    i = next((n for n, p in enumerate(serie) if dt.datetime.fromisoformat(p["time"].replace("Z", "+00:00")) >= hora), None)
    if i is None or i + 1 >= len(serie):
        return None
    act, sig = serie[i]["data"], serie[i + 1]["data"]
    d = act["instant"]["details"]
    n1 = lambda x: x.get("next_1_hours") or {}
    return {
        "actualizado": ahora.isoformat(timespec="seconds"), "fuente": "MET Norway",
        "temp": round(d["air_temperature"]), "humedad": round(d.get("relative_humidity", 0)),
        "viento_kmh": round(d.get("wind_speed", 0) * 3.6),
        "ahora": describir(n1(act).get("summary", {}).get("symbol_code")),
        "prox": dict(describir(n1(sig).get("summary", {}).get("symbol_code")),
                     mm=n1(sig).get("details", {}).get("precipitation_amount", 0),
                     temp=round(sig["instant"]["details"]["air_temperature"])),
    }


def main():
    prueba = sys.argv[1] if len(sys.argv) > 1 else None  # archivo JSON de ejemplo, para probar sin red
    ahora = dt.datetime.now(LOCAL)
    os.makedirs("datos", exist_ok=True)
    for slug, (lat, lon) in CIUDADES.items():
        try:
            doc = leer(prueba or API.format(lat=lat, lon=lon))
            if prueba:
                ahora = dt.datetime.fromisoformat(doc["properties"]["timeseries"][0]["time"].replace("Z", "+00:00")).astimezone(LOCAL)
            r = resumen(doc, ahora)
        except Exception as e:  # si falla una ciudad no se escribe nada: la pagina oculta el bloque
            print(f"{slug}: sin datos ({e})")
            continue
        if r and not prueba:
            try:
                r["horas"] = horas(leer(OM.format(lat=lat, lon=lon)))
            except Exception as e:
                print(f"{slug}: sin proximas horas ({e})")
        if r:
            json.dump(r, open(os.path.join("datos", slug + "_tiempo.json"), "w", encoding="utf-8"), ensure_ascii=False)
            print(slug, r)


if __name__ == "__main__":
    main()
