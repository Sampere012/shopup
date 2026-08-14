#!/usr/bin/env bash
# Despliega wp-content (theme workshop + mu-plugins) a InfinityFree por FTP.
# Usa curl (mismo metodo que los archivos raiz, que si funciona) en vez de
# SamKirkland/FTP-Deploy-Action, que reportaba exito sin subir nada.
# Sube SIEMPRE el arbol completo: auto-cura la deriva de commits previos.
set -euo pipefail
shopt -s nullglob

if [ -z "${FTP_HOST:-}" ] || [ -z "${FTP_USER:-}" ] || [ -z "${FTP_PASS:-}" ]; then
  echo "::error::Faltan FTP_HOST / FTP_USER / FTP_PASS"
  exit 1
fi

upload() {
  local src="$1"
  local dst="$2"
  if ! curl -sS --connect-timeout 30 --max-time 120 \
      --user "${FTP_USER}:${FTP_PASS}" --ftp-create-dirs \
      -T "${src}" "ftp://${FTP_HOST}/${dst}" >/dev/null 2>&1; then
    echo "::error::Fallo subiendo ${dst}"
    return 1
  fi
}

count=0
deploy_dir() {
  local localdir="$1"
  local remotedir="$2"
  pushd "${localdir}" >/dev/null
  while IFS= read -r f; do
    rel="${f#./}"
    upload "${rel}" "${remotedir}/${rel}"
    count=$((count+1))
  done < <(find . -type f | sort)
  popd >/dev/null
}

deploy_dir "wp-content/themes/workshop" "htdocs/wp-content/themes/workshop"
deploy_dir "wp-content/mu-plugins"      "htdocs/wp-content/mu-plugins"

echo "::notice::wp-content desplegado: ${count} archivos"
