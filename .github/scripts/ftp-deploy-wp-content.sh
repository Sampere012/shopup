#!/usr/bin/env bash
# Despliega wp-content (theme workshop + mu-plugins) a InfinityFree por FTP.
# Usa curl (mismo metodo que los archivos raiz, que si funciona) en vez de
# SamKirkland/FTP-Deploy-Action, que reportaba exito sin subir nada.
# Sube SIEMPRE el arbol completo: auto-cura la deriva de commits previos.
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

# Sube un archivo con reintentos (backoff) y modo binario explicito.
# Los PNG/JS/PHP deben subirse como TYPE I; algunos proxies FTP negocian
# ASCII por defecto y corrompen binarios.
upload() {
  local src="$1"
  local dst="$2"
  local attempt
  for attempt in 1 2 3 4 5; do
    if curl -sS --connect-timeout 30 --max-time 180 \
        --user "${FTP_USER}:${FTP_PASS}" --ftp-create-dirs \
        -Q "TYPE I" --ftp-pasv \
        -T "${src}" "ftp://${FTP_HOST}/${dst}" >/dev/null 2>&1; then
      return 0
    fi
    echo "::warning::Intento ${attempt}/5 fallo para ${dst}"
    sleep $(( attempt * 2 ))
  done
  echo "::error::Fallo definitivo subiendo ${dst}"
  return 1
}

count=0
failed_files=()

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
    fi
    count=$((count+1))
    # Pequena pausa para no saturar el rate-limit de conexiones de InfinityFree.
    sleep 0.3
  done < <(find . -type f | sort)
  popd >/dev/null
}

deploy_dir "wp-content/themes/workshop" "htdocs/wp-content/themes/workshop"
deploy_dir "wp-content/mu-plugins"      "htdocs/wp-content/mu-plugins"

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
fi

if [ "${#failed_files[@]}" -gt 0 ]; then
  echo "::error::No se pudieron subir ${#failed_files[@]} archivos:"
  printf '  - %s\n' "${failed_files[@]}"
  exit 1
fi

echo "::notice::wp-content desplegado: ${count} archivos"
