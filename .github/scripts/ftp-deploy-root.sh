#!/usr/bin/env bash
# Despliega los archivos raiz (.htaccess, páginas de error y el APK de la app)
# a InfinityFree por FTP, con reintentos y modo binario explícito (misma
# robustez que ftp-deploy-wp-content.sh). No aborta en el primer fallo:
# reintenta y solo falla si un archivo persiste tras 5 intentos.
#
# En push se usa ROOT_FILELIST (incremental: solo los archivos del diff). Si
# no hay lista (deploy manual / rollback) se sincroniza todo lo existente.
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
  local detail
  for attempt in 1 2 3 4 5; do
    err="$(mktemp)"
    # STOR simple (camino feliz). NO se envia CHMOD: InfinityFree rechaza
    # 'SITE CHMOD' y con -Q abortaba la subida de TODOS los archivos.
    if curl -sS --connect-timeout 30 --max-time 240 \
        --user "${FTP_USER}:${FTP_PASS}" --ftp-create-dirs \
        -Q "TYPE I" --ftp-pasv \
        -T "$1" "ftp://${FTP_HOST}/$2" 2>"$err"; then
      rm -f "$err"
      return 0
    fi
    detail="$(tr '\n' ' ' < "$err" | sed 's/  */ /g' | tail -c 400)"
    rm -f "$err"
    echo "::warning::Intento ${attempt}/5 fallo para $2${detail:+ — $detail}"
    # Destino en solo lectura (550/553): DELE best-effort en conexion aparte
    # (resultado ignorado) y el siguiente intento reintenta el STOR.
    curl -sS --connect-timeout 20 --max-time 60 \
        --user "${FTP_USER}:${FTP_PASS}" \
        -Q "DELE $2" \
        "ftp://${FTP_HOST}/" >/dev/null 2>&1 || true
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

if [ -n "${ROOT_FILELIST:-}" ]; then
  if [ -s "$ROOT_FILELIST" ]; then
    echo "::notice::Deploy INCREMENTAL de archivos raiz"
    while IFS= read -r f; do
      case "$f" in
        htaccess.prod) deploy htaccess.prod htdocs/.htaccess ;;
        app/*)         deploy "$f"            "htdocs/$f" ;;
        errors/*)      deploy "$f"            "htdocs/$f" ;;
      esac
    done < "$ROOT_FILELIST"
  else
    echo "::notice::Sin archivos raiz cambiados"
  fi
else
  echo "::notice::Sincronizacion completa de archivos raiz"
  deploy htaccess.prod        htdocs/.htaccess
  for f in errors/*.html; do
    deploy "$f" "htdocs/errors/$(basename "$f")"
  done
  deploy app/shopup-panel.apk       htdocs/app/shopup-panel.apk
  deploy app/shopup-panel.apk.idsig htdocs/app/shopup-panel.apk.idsig
fi

# Limpieza best-effort del PWA obsoleto en el servidor (la web ya no es PWA):
# al devolver 404 el sw.js, el navegador desregistra el service worker viejo.
cleanup() {
  echo "  limpio PWA obsoleto: $1"
  curl -sS --connect-timeout 20 --max-time 60 \
      --user "${FTP_USER}:${FTP_PASS}" \
      -Q "DELE $1" \
      "ftp://${FTP_HOST}/" >/dev/null 2>&1 || true
}
cleanup htdocs/sw.js
cleanup htdocs/manifest.json

if [ "$failed" -ne 0 ]; then
  echo "::error::Hubo archivos raiz que no se pudieron subir"
  exit 1
fi
echo "::notice::Archivos raiz desplegados"