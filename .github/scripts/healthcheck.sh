#!/usr/bin/env bash
# Health check de producción tras un deploy (estilo Vercel).
# Sale con código 1 si la web devuelve 5xx, no responde (000) o una ruta
# crítica no responde 200. Usa UA de navegador porque InfinityFree sirve un
# reto anti-bot (aes.js) a agentes no-navegador.
set -u

BASE_URL="${1:-http://shopup.site.je}"
UA="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36"

# ruta  statusMinimo statusMaximo   (el código HTTP debe caer en [min,max])
checks=(
  "${BASE_URL}/manifest.json 200 200"
  "${BASE_URL}/wp-login.php 200 200"
  "${BASE_URL}/favicon.ico 100 499"
  "${BASE_URL}/ 100 499"
)

failed=""
for check in "${checks[@]}"; do
  set -- $check
  url="$1"
  min="$2"
  max="$3"
  ok=""
  for attempt in 1 2 3; do
    if ! code=$(curl -sS -o /dev/null -w "%{http_code}" --max-time 60 -A "$UA" "$url" 2>/dev/null); then
      code="000"
    fi
    echo "  health: $url -> $code (intento $attempt/3)"
    if [ "$code" = "000" ] || [ "$code" -lt "$min" ] || [ "$code" -gt "$max" ]; then
      if [ "$attempt" -lt 3 ]; then sleep 5; fi
      continue
    fi
    ok="1"
    break
  done
  if [ -z "$ok" ]; then
    failed="${failed} ${url}"
  fi
done

if [ -n "$failed" ]; then
  echo "::error::Health check falló para:$failed"
  echo "HEALTH_FAILED=$failed" >> "${GITHUB_ENV:-/dev/null}"
  exit 1
fi
echo "::notice::Health check OK"
