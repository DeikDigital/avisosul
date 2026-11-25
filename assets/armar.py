"""Arma las paginas del sitio (portada, planos, instalar, privacidade) a partir de assets/*.src.html.
Uso: python3 assets/armar.py  (desde la raiz del repositorio)"""
import os, re
R = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
app = open(os.path.join(R, "app.html"), encoding="utf-8").read()
LOGO = '<img src="/assets/logo-96.png" alt="" width="36" height="36">'
SYM = "".join(re.findall(r'<symbol id="i-(?:riesgo|granizo|chuva|vendaval|raios)".*?</symbol>', app, re.S))
PIE = open(os.path.join(R, "assets", "pie.src.html"), encoding="utf-8").read().replace("{{LOGO}}", LOGO)
CAB = open(os.path.join(R, "assets", "cab.src.html"), encoding="utf-8").read().replace("{{LOGO}}", LOGO)
for src, dst in [("landing", "index.html"), ("planos", "planos/index.html"), ("instalar", "instalar/index.html"), ("privacidade", "privacidade/index.html")]:
    t = open(os.path.join(R, "assets", src + ".src.html"), encoding="utf-8").read()
    t = t.replace("{{CAB}}", CAB).replace("{{PIE}}", PIE).replace("{{LOGO}}", LOGO).replace("{{SYM}}", SYM)
    os.makedirs(os.path.dirname(os.path.join(R, dst)) or R, exist_ok=True)
    open(os.path.join(R, dst), "w", encoding="utf-8").write(t)
    print("ok", dst, len(t))
