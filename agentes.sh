#!/bin/bash
# Tarea programada de Hostinger: corre los agentes cada 10 minutos.
# Escribe en vivo/ (fuera de git), que es de donde lee la pagina.
cd "$(dirname "$0")" || exit 1
echo "carpeta: $(pwd)"; python3 --version 2>&1
mkdir -p vivo && cd vivo || exit 1
python3 ../agente_inmet.py nuevos 2>&1 | tail -2
python3 ../agente_smn.py nuevos 2>&1 | tail -2
python3 ../agente_tiempo.py 2>&1 | tail -2
ls datos | wc -l; date
