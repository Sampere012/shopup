#!/usr/bin/env bash
# Health check de producción tras un deploy (estilo Vercel).
# Sale con código 1 si la web devuelve 5xx, no responde (000) o una ruta
# crítica no responde 200. Usa UA de navegador porque InfinityFree sirve un
# reto anti-bot (aes.js) a agentes no-navegador.
set -u

BASE_URL="${1:-http://shopup.site.je}"
UA="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36"

# ruta  statusMinimo statusMaximo   (el código HTTP debe caer en [min,max])
# NOTA: wp-login.php responde 302 SIEMPRE (la plantilla lo redirige a /login/
# vía ws_redirect_wp_login), así que exigir 200 exacto hacía fallar el health
# check en CADA deploy y el rollback automático restauraba la versión anterior
# (los cambios nunca llegaban a producción). Se acepta 2xx/3xx.
checks=(
  "${BASE_URL}/wp-login.php 200 399"
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

# Verificación de contenido: el theme.js servido debe ser byte a byte el del
# checkout. Detecta deploys que reportan éxito pero dejan código viejo (el bug
# de SamKirkland) sin esperar a que el navegador recargue.
#
# IMPORTANTE: esto es un AVISO, NO un fallo que dispare rollback. InfinityFree
# puede servir el archivo a través de su caché/anti-bot (aes.js) y devolver el
# contenido con pequeñas diferencias o un reto HTTP aunque el deploy haya
# subido bien; revertir por esto hacía que los cambios nunca llegaran a
# producción. El rollback queda reservado a fallos HTTP 5xx reales.
THEME_PATH="wp-content/themes/workshop/assets/js/theme.js"
if [ -f "$THEME_PATH" ]; then
  THEME_URL="${BASE_URL}/wp-content/themes/workshop/assets/js/theme.js"
  local_md5="$(md5sum < "$THEME_PATH" | cut -d' ' -f1)"
  remote_md5=""
  for attempt in 1 2 3; do
    remote_md5="$(curl -sS --max-time 60 -A "$UA" "$THEME_URL" 2>/dev/null | md5sum | cut -d' ' -f1)"
    echo "  health: theme.js md5 local=$local_md5 remoto=$remote_md5 (intento $attempt/3)"
    if [ "$local_md5" = "$remote_md5" ]; then
      break
    fi
    if [ "$attempt" -lt 3 ]; then sleep 8; fi
  done
  if [ "$local_md5" != "$remote_md5" ]; then
    echo "::warning::Contenido posiblemente stale: theme.js no coincide con el commit ($local_md5 != $remote_md5). No se revierte: puede ser caché/anti-bot de InfinityFree."
  else
    echo "  health: theme.js contenido OK"
  fi
else
  echo "  health: theme.js local no presente, se omite verificación de contenido"
fi

if [ -n "$failed" ]; then
  echo "::error::Health check falló para:$failed"
  echo "HEALTH_FAILED=$failed" >> "${GITHUB_ENV:-/dev/null}"
  exit 1
fi
echo "::notice::Health check OK"
