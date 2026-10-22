#!/bin/sh
# Purga de respaldos del servidor de producción (~/backups).
#
# Regla: se borra lo que tenga más de DAYS días (60) y NO esté entre los
# KEEP (10) archivos más recientes. El segundo límite es el seguro: si pasan
# más de 60 días sin deploys, una regla solo por antigüedad borraría todos
# los respaldos.
#
# Uso:
#   sh scripts/server/purga_respaldos.sh --listar   # solo muestra qué borraría
#   sh scripts/server/purga_respaldos.sh            # borra
# Crontab (día 1 de cada mes, 4:00, después de la limpieza diaria de las 3:30):
#   0 4 1 * * /bin/sh /home/cloudjg/jgmylard.work/scripts/server/purga_respaldos.sh >> /home/cloudjg/tmp/purga-respaldos.log 2>&1

DIR="${BACKUP_DIR:-$HOME/backups}"
DAYS="${DAYS:-60}"
KEEP="${KEEP:-10}"
MODE="${1:-}"

[ -d "$DIR" ] || { echo "No existe $DIR"; exit 1; }
echo "== $(date '+%Y-%m-%d %H:%M') purga de $DIR (más de $DAYS días, se conservan los $KEEP más recientes)${MODE:+ [solo listar]}"

newest=$(ls -1t "$DIR" | head -n "$KEEP")
count=0
for f in $(find "$DIR" -maxdepth 1 -type f -mtime +"$DAYS"); do
  name=$(basename "$f")
  if printf '%s\n' "$newest" | grep -qxF "$name"; then
    continue
  fi
  count=$((count + 1))
  if [ "$MODE" = "--listar" ]; then
    echo "  se borraría: $name"
  else
    rm -f -- "$f" && echo "  borrado: $name"
  fi
done
echo "  Total: $count. Quedan $(ls -1 "$DIR" | wc -l | tr -d ' ') archivos ($(du -sh "$DIR" | cut -f1))."
