#!/usr/bin/env bash
# Funciones y comprobaciones comunes a los scripts de despliegue.
# No se ejecuta directamente: los demás scripts lo cargan con `. lib.sh`.

set -euo pipefail

DEPLOY_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DEPLOY_DIR/.." && pwd)"

if [ ! -f "$DEPLOY_DIR/config.sh" ]; then
    printf '\nERROR: falta deploy/config.sh\n\n' >&2
    printf 'Ese archivo tiene los datos reales del servidor y no se versiona.\n' >&2
    printf 'Créalo a partir de la plantilla y ajusta los valores:\n\n' >&2
    printf '    cp deploy/config.example.sh deploy/config.sh\n\n' >&2
    exit 1
fi

# shellcheck source=/dev/null
. "$DEPLOY_DIR/config.sh"

: "${SSH_ALIAS:?falta SSH_ALIAS en deploy/config.sh}"
: "${REMOTE_APP:?falta REMOTE_APP en deploy/config.sh}"
: "${REMOTE_DOCROOT:?falta REMOTE_DOCROOT en deploy/config.sh}"
: "${REMOTE_PHP:?falta REMOTE_PHP en deploy/config.sh}"
: "${SITE_URL:?falta SITE_URL en deploy/config.sh}"

if [ -t 1 ]; then
    C_TITLE=$'\033[1;36m'; C_OK=$'\033[0;32m'; C_WARN=$'\033[0;33m'
    C_ERR=$'\033[0;31m';   C_OFF=$'\033[0m'
else
    C_TITLE=''; C_OK=''; C_WARN=''; C_ERR=''; C_OFF=''
fi

say()  { printf '\n%s==> %s%s\n' "$C_TITLE" "$*" "$C_OFF"; }
ok()   { printf '    %sOK%s   %s\n' "$C_OK" "$C_OFF" "$*"; }
warn() { printf '    %s!!%s   %s\n' "$C_WARN" "$C_OFF" "$*"; }
die()  { printf '\n%sERROR: %s%s\n\n' "$C_ERR" "$*" "$C_OFF" >&2; exit 1; }

# Ejecuta un comando en el servidor. BatchMode evita que el script se quede
# colgado esperando una contraseña que nadie va a escribir.
remote() { ssh -o BatchMode=yes "$SSH_ALIAS" "$@"; }

# Archivos temporales: se borran pase lo que pase.
TEMPORALES=()
limpiar_temporales() { [ ${#TEMPORALES[@]} -eq 0 ] || rm -f "${TEMPORALES[@]}"; }
temporal() {
    local t
    t="$(mktemp)"
    TEMPORALES+=("$t")
    printf '%s' "$t"
}

# Sube un directorio al servidor con tar sobre SSH.
#
# Se usa tar y no rsync porque Git Bash en Windows no trae rsync, y depender de
# instalarlo a mano en cada máquina es exactamente la clase de requisito que se
# olvida. tar viene con Git for Windows y con cualquier Linux.
#
# La lista de lo que se sube sale del propio paquete (`tar -t`), así que no hay
# forma de que diverja de lo que realmente viajó: un solo juego de exclusiones
# gobierna las dos cosas.
#
#   subir_paquete <dir_local> <dir_remoto> <exclusiones de tar...>
#
# Deja en la variable LISTA_SUBIDA la ruta de un archivo con los nombres
# subidos, para que quien llame pueda calcular qué sobra en el servidor.
subir_paquete() {
    local origen="$1" destino="$2"
    shift 2

    local paquete lista
    paquete="$(temporal)"
    lista="$(temporal)"

    tar -czf "$paquete" -C "$origen" "$@" . \
        || die "no se pudo empaquetar $origen"

    tar -tzf "$paquete" | grep -v '/$' | sed 's|^\./||' | LC_ALL=C sort > "$lista"
    ok "$(wc -l < "$lista" | tr -d ' ') archivos empaquetados ($(du -h "$paquete" | cut -f1 | tr -d ' '))"

    remote "mkdir -p '$destino' && tar -xzf - -C '$destino'" < "$paquete" \
        || die "falló la extracción en el servidor"

    LISTA_SUBIDA="$lista"
}

# Borra en el servidor los archivos que ya no existen en el repositorio, que es
# lo que daba `rsync --delete`. Sin esto, un archivo eliminado en local seguiría
# ejecutándose en producción indefinidamente.
#
# La expresión de `find` remota tiene que excluir EXACTAMENTE lo mismo que el
# paquete. Lo que no se sube y tampoco se excluye aquí, se borraría: por eso
# .env, storage/ y vendor/ aparecen en las dos listas.
#
# La expresión viaja por un archivo y no como variable de entorno del comando
# ssh. Metida en la línea de comandos, sus comillas simples cierran las del
# `PRUNE='...'` que las envuelve, y patrones como `-name '.env.*'` llegan al
# servidor SIN comillas: el `eval` los expande contra el directorio del
# proyecto y find recibe `-name .env.save .env.save.1` en vez de un patrón.
# Por archivo, el texto se interpreta una sola vez —en el `eval`— y las
# comillas hacen su trabajo.
#
#   borrar_sobrantes <dir_remoto> <expresión find de exclusión>
borrar_sobrantes() {
    local destino="$1" prune="$2"

    remote "cat > /tmp/deploy-esperado.txt" < "$LISTA_SUBIDA"
    printf '%s\n' "$prune" | remote "cat > /tmp/deploy-prune.txt"
    remote "DEST='$destino' bash -s" <<'FIN_BORRADO'
set -euo pipefail

# Orden de bytes en TODO el script, no solo en el sort. La lista de lo esperado se ordena con
# LC_ALL=C en la máquina de desarrollo, y sin esto `comm` la compara con la collation del servidor
# (en_US.UTF-8), donde el orden alfabético puede diferir del orden de bytes. `comm` concluye que la
# entrada está desordenada y sale con error, que con `set -e` mata el script justo antes del
# borrado: el despliegue termina en rojo y los archivos viejos se quedan. El orden de bytes es el
# único que no depende de cómo esté configurada una máquina que no controlamos.
export LC_ALL=C

cd "$DEST"

# shellcheck disable=SC2086
eval "find . $(cat /tmp/deploy-prune.txt) -type f -print" \
    | sed 's|^\./||' | sort > /tmp/deploy-actual.txt

comm -13 /tmp/deploy-esperado.txt /tmp/deploy-actual.txt > /tmp/deploy-sobran.txt

if [ -s /tmp/deploy-sobran.txt ]; then
    echo "    borrando $(wc -l < /tmp/deploy-sobran.txt | tr -d ' ') archivo(s) que ya no están en el repositorio:"
    sed 's/^/        /' /tmp/deploy-sobran.txt
    tr '\n' '\0' < /tmp/deploy-sobran.txt | xargs -0 --no-run-if-empty rm -f --
else
    echo "    nada que borrar"
fi

rm -f /tmp/deploy-esperado.txt /tmp/deploy-actual.txt /tmp/deploy-sobran.txt /tmp/deploy-prune.txt
FIN_BORRADO
}

# Comprueba la conexión antes de hacer cualquier cosa. Fallar aquí es barato;
# fallar a mitad de una subida deja el servidor en un estado intermedio.
require_connection() {
    say "Comprobando conexión con $SSH_ALIAS"
    remote true >/dev/null 2>&1 \
        || die "no se pudo conectar a '$SSH_ALIAS'. Prueba a mano: ssh $SSH_ALIAS"
    ok "conectado"
}

# El servidor tiene que estar instalado. Sin .env, artisan no arranca y el
# error que da no se parece en nada a la causa.
require_installed() {
    remote "[ -f '$REMOTE_APP/.env' ]" \
        || die "no existe $REMOTE_APP/.env
       El servidor todavía no está instalado. Sigue la sección
       'Instalación inicial' de deploy/README.md antes de desplegar."
}
