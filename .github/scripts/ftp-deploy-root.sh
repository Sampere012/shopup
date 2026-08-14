#!/usr/bin/env bash
# Despliega los archivos raiz (sw.js, manifest, .htaccess, errors) a InfinityFree
# por FTP, con reintentos y modo binario explicito (misma robustez que
# ftp-deploy-wp-content.sh). No aborta en el primer fallo: reintenta y solo
# falla si un archivo persiste tras 5 intentos.
set -uo pipefail
shopt -s nullglob

if [ -z "${FTP_HOST:-}" ] || [ -z "${FTP_USER:-}" ] || [ -z "${FTP_PASS:-}" ]; then
  echo "::error::Faltan FTP_HOST / FTP_USER / FTP_PASS"
  exit 1
fi

upload() {
  local src="$1"
  local dst="$2"
  local attempt
  local err
  for attempt in 1 2 3 4 5; do
    err="$(mktemp)"
    # CHMOD 644 + DELE best-effort antes del STOR: si el destino quedo de solo
    # lectura (550 Permission denied), hacerlo escribible/borrarlo lo resuelve.
    if curl -sS --connect-timeout 30 --max-time 180 \
        --user "${FTP_USER}:${FTP_PASS}" --ftp-create-dirs \
        -Q "-SITE CHMOD 644 $2" \
        -Q "-DELE $2" \
        -Q "TYPE I" --ftp-pasv \
        -T "$1" "ftp://${FTP_HOST}/$2" 2>"$err"; then
      rm -f "$err"
      return 0
    fi
    local detail
    detail="$(tr '\n' ' ' < "$err" | sed 's/  */ /g' | tail -c 400)"
    rm -f "$err"
    echo "::warning::Intento ${attempt}/5 fallo para $2${detail:+ — $detail}"
    sleep $(( attempt * 2 ))
  done
  echo "::error::Fallo definitivo subiendo $2"
  return 1
}

failed=0
deploy() {
  local src="$1"
  local dst="$2"
  if [ -f "$src" ]; then
    if upload "$src" "$dst"; then
      echo "  subido $dst"
    else
      failed=1
    fi
    sleep 0.3
  fi
}

deploy sw.prod.js        htdocs/sw.js
deploy manifest.json     htdocs/manifest.json
deploy htaccess.prod     htdocs/.htaccess
for f in errors/*.html; do
  deploy "$f" "htdocs/errors/$(basename "$f")"
done

if [ "$failed" -ne 0 ]; then
  echo "::error::Hubo archivos raiz que no se pudieron subir"
  exit 1
fi
echo "::notice::Archivos raiz desplegados"
