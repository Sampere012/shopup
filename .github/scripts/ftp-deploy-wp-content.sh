#!/usr/bin/env bash
# Despliega wp-content (theme workshop + mu-plugins) a InfinityFree por FTP.
# Usa curl (mismo metodo que los archivos raiz, que si funciona) en vez de
# SamKirkland/FTP-Deploy-Action, que reportaba exito sin subir nada.
#
# Dos modos:
#  - INCREMENTAL (por defecto): si la env DEPLOY_FILELIST apunta a un archivo
#    con rutas relativas a la raiz del repo (una por linea, generadas por
#    deploy.yml con `git diff`), se suben SOLO esos archivos (los cambiados o
#    nuevos del push). Mucho mas rapido: un commit toca 5-20 archivos.
#  - COMPLETA (FULL_SYNC=1 o sin DEPLOY_FILELIST): sube el arbol entero;
#    se usa como auto-cura cuando el incremental falla o en el primer deploy.
#
# Tolerancia a fallos: InfinityFree limita conexiones FTP por minuto y a veces
# responde con rechazos puntuales (rate-limit). Cada archivo se sube con su
# propia conexion, asi que se reintenta con backoff, se espera un poco entre
# archivos, y si alguno falla NO se aborta el deploy: se anota, se hace una
# segunda pasada solo con los fallidos, y solo se falla si persisten.
set -uo pipefail
shopt -s nullglob

if [ -z "${FTP_HOST:-}" ] || [ -z "${FTP_USER:-}" ] || [ -z "${FTP_PASS:-}" ]; then
  echo "::error::Faltan FTP_HOST / FTP_USER / FTP_PASS"
  exit 1
fi

FULL_SYNC="${FULL_SYNC:-0}"
DEPLOY_FILELIST="${DEPLOY_FILELIST:-}"

# Sube un archivo con reintentos (backoff) y modo binario explicito.
# Los PNG/JS/PHP deben subirse como TYPE I; algunos proxies FTP negocian
# ASCII por defecto y corrompen binarios.
#
# Camino feliz: STOR simple (asi funcionaba). NO se envia CHMOD antes del STOR:
# InfinityFree rechaza 'SITE CHMOD' y con -Q eso abortaba la subida de TODOS
# los archivos (curl 21 QUOT string not accepted). Si un STOR falla (p. ej.
# 550/553 por destino remoto en solo lectura de un deploy anterior), se borra
# el remoto con un DELE best-effort en conexion aparte (resultado ignorado) y
# el siguiente intento reintenta el STOR. El error real se muestra en el warning.
upload() {
  local src="$1"
  local dst="$2"
  local attempt
  local err
  local detail
  for attempt in 1 2 3 4 5; do
    err="$(mktemp)"
    if curl -sS --connect-timeout 30 --max-time 180 \
        --user "${FTP_USER}:${FTP_PASS}" --ftp-create-dirs \
        -Q "TYPE I" --ftp-pasv \
        -T "${src}" "ftp://${FTP_HOST}/${dst}" 2>"$err"; then
      rm -f "$err"
      return 0
    fi
    detail="$(tr '\n' ' ' < "$err" | sed 's/  */ /g' | tail -c 400)"
    rm -f "$err"
    echo "::warning::Intento ${attempt}/5 fallo para ${dst}${detail:+ — $detail}"
    curl -sS --connect-timeout 20 --max-time 60 \
        --user "${FTP_USER}:${FTP_PASS}" \
        -Q "DELE ${dst}" \
        "ftp://${FTP_HOST}/" >/dev/null 2>&1 || true
    sleep $(( attempt * 2 ))
  done
  echo "::error::Fallo definitivo subiendo ${dst}"
  return 1
}

count=0
failed_files=()
critical_failed=0

# Un archivo es CRITICO si su extension es codigo (php/js/css/json/html/txt/xml
# o sin extension); los assets (imagenes, fuentes, zip) son cosmeticos y un
# fallo persistente no debe tumbar el deploy (el sitio sigue con el anterior).
is_critical() {
  local f="$1"
  local base ext
  base="${f##*/}"
  ext="${base##*.}"
  if [ "$base" = "$ext" ]; then
    return 0
  fi
  case "$ext" in
    php|js|css|json|html|htm|txt|xml|map|htaccess) return 0 ;;
    *) return 1 ;;
  esac
}

# Sube un arbol local a un directorio remoto. Los fallos se acumulan en
# failed_files (no aborta: un PNG bloqueado no debe tumbar el deploy entero).
deploy_dir() {
  local localdir="$1"
  local remotedir="$2"
  pushd "${localdir}" >/dev/null
  while IFS= read -r f; do
    rel="${f#./}"
    if ! upload "${rel}" "${remotedir}/${rel}"; then
      failed_files+=( "${remotedir}/${rel}" )
      if is_critical "$rel"; then
        critical_failed=1
      fi
    fi
    count=$((count+1))
    # Pequena pausa para no saturar el rate-limit de conexiones de InfinityFree.
    sleep 0.3
  done < <(find . -type f | sort)
  popd >/dev/null
}

if [ "$FULL_SYNC" = "1" ]; then
  echo "::notice::Modo COMPLETO (todos los archivos)"
  deploy_dir "wp-content/themes/workshop" "htdocs/wp-content/themes/workshop"
  deploy_dir "wp-content/mu-plugins"      "htdocs/wp-content/mu-plugins"
elif [ -n "${DEPLOY_FILELIST}" ] && [ -s "${DEPLOY_FILELIST}" ]; then
  echo "::notice::Modo INCREMENTAL (solo archivos cambiados/nuevos del push)"
  # El listado trae rutas relativas a la raiz del repo, p. ej.
  # wp-content/themes/workshop/inc/stock.php -> htdocs/wp-content/...
  while IFS= read -r f; do
    case "$f" in
      wp-content/*)
        if [ -f "$f" ]; then
          if ! upload "$f" "htdocs/$f"; then
            failed_files+=( "htdocs/$f" )
            if is_critical "${f#wp-content/}"; then
              critical_failed=1
            fi
          fi
          count=$((count+1))
          sleep 0.3
        fi
        ;;
    esac
  done < "$DEPLOY_FILELIST"
elif [ -n "${DEPLOY_FILELIST}" ]; then
  # El listado existe pero esta vacio: este push no toco wp-content.
  echo "::notice::Sin cambios que subir (incremental, listado vacio)"
  exit 0
else
  # Ni FULL_SYNC ni DEPLOY_FILELIST: respaldo defensivo con el comportamiento
  # historico (sync completo) para que el deploy nunca quede en silencio.
  echo "::notice::Modo COMPLETO (sin DEPLOY_FILELIST, respaldo)"
  deploy_dir "wp-content/themes/workshop" "htdocs/wp-content/themes/workshop"
  deploy_dir "wp-content/mu-plugins"      "htdocs/wp-content/mu-plugins"
fi

# Segunda pasada SOLO con los que fallaron (la conexion/rate-limit puede haberse
# recuperado entre tanto).
if [ "${#failed_files[@]}" -gt 0 ]; then
  echo "::notice::Segunda pasada con ${#failed_files[@]} archivos fallidos..."
  retry=()
  for dst in "${failed_files[@]}"; do
    rel="${dst#htdocs/wp-content/}"
    if [ -f "wp-content/${rel}" ]; then
      if upload "wp-content/${rel}" "${dst}"; then
        continue
      fi
    fi
    retry+=( "${dst}" )
    sleep 0.5
  done
  failed_files=( "${retry[@]}" )
  # Recalcular: la segunda pasada pudo subir un archivo critico que fallo antes.
  critical_failed=0
  for dst in "${failed_files[@]}"; do
    rel="${dst#htdocs/wp-content/}"
    if is_critical "$rel"; then
      critical_failed=1
    fi
  done
fi

if [ "${#failed_files[@]}" -gt 0 ]; then
  if [ "$critical_failed" -ne 0 ]; then
    echo "::error::No se pudieron subir archivos de CODIGO:"
    printf '  - %s\n' "${failed_files[@]}"
    exit 1
  fi
  # Solo assets cosmeticos: el deploy es valido, se avisa.
  echo "::warning::No se pudieron subir ${#failed_files[@]} assets (imagenes/fuentes) — el sitio usa la version anterior de esos archivos:"
  printf '  - %s\n' "${failed_files[@]}"
fi

echo "::notice::wp-content desplegado: ${count} archivos"
