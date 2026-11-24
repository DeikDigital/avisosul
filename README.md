# Aviso Sul - agente de avisos del INMET

Lee el feed oficial del INMET, se queda con los avisos del Oeste catarinense y redacta
los mensajes en portugués y español. Reglas fijas, sin IA y sin costo.

## Probar en tu computadora
    python agente_inmet.py nuevos
    python agente_inmet.py manana
    python agente_inmet.py vispera
Imprime los borradores. No publica nada.

## Dejarlo corriendo gratis (GitHub Actions)
1. Crear un repositorio privado en GitHub y subir esta carpeta.
2. Crear un bot en Telegram con @BotFather y copiar su token.
3. En el repositorio: Settings > Secrets and variables > Actions, cargar:
   - TELEGRAM_TOKEN: el token del bot.
   - TELEGRAM_OWNER: tu id de chat con el bot (ahí llegan los borradores para pegar en WhatsApp).
   - TELEGRAM_CANAL: @avisosul (solo cuando exista el canal y el bot sea administrador).
4. Para que publique solo en el canal de Telegram: crear la variable PUBLICAR con valor 1.
   Sin esa variable solo te manda borradores a vos.

## Pendiente de confirmar con un aviso real
- [I] Las horas de Início y Fim se toman como hora de Brasilia.
- [I] El nombre de zona usado es "Oeste Catarinense", tal como figura en el campo Área.

## Argentina (SMN) — agregado el 24/11/2026

`agente_smn.py` lee los Avisos a muy Corto Plazo del SMN y redacta el borrador en español.

- Probar sin red: `python3 agente_smn.py --feed pruebas/feed_smn_ejemplo.xml --ahora "2026-11-24 15:30"`
- Probar contra el feed real: `python3 agente_smn.py`
- Zona por defecto: departamento Iguazú, Misiones. Para sumar Posadas:
  `AVISOSUL_AR_ZONAS="MISIONES:Iguazú,Capital"` y `AVISOSUL_AR_NOMBRE="Misiones"`.
- Canal propio: secreto `TELEGRAM_CANAL_AR`. Sin `PUBLICAR=1` solo manda borradores.

Sin verificar: [I] hora del archivo tomada como hora argentina; [I] vigencia de 3 horas;
[I] las alertas amarilla/naranja/roja del SMN no vienen en este feed.

Para Foz do Iguaçu el agente del INMET se reutiliza con `AVISOSUL_REGION` puesto en el
nombre de zona que use el INMET para el oeste de Paraná ([I] falta confirmarlo con un aviso real).

## Varias ciudades — 24/11/2026

La lista de ciudades está en `ciudades.json` (la leen los tres agentes) y en `LISTA` dentro de `index.html`.
Para sumar una ciudad: una línea en cada lugar y una carpeta con su `index.html`.

- `agente_inmet.py`: ciudades de Brasil. [I] Los nombres de zona (`inmet`) siguen las mesorregiones del IBGE; falta confirmarlos con avisos reales.
- `agente_smn.py`: ciudades de Argentina. [I] Provincia y departamento tal como aparecen en el feed.
- `agente_tiempo.py`: condiciones (MET Norway) y próximas 12 horas (Open-Meteo).
- Canal de Telegram por ciudad: secreto `TELEGRAM_CANAL_<CIUDAD>` en mayúsculas.

Licencias a resolver antes de cobrar o pautar: Open-Meteo (plan gratuito solo no comercial) y RainViewer (radar, uso personal o educativo).
