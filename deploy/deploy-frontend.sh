#!/usr/bin/env bash
#
# Compila el SPA y lo publica en el docroot del servidor.
#
#     deploy/deploy-frontend.sh
#     deploy/deploy-frontend.sh --sin-compilar   (sube el dist/ que ya existe)
#
# El build se hace SIEMPRE en la máquina de desarrollo: el plan compartido de
# Hostinger no tiene Node.

. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

COMPILAR=1
for arg in "$@"; do
    case "$arg" in
        --sin-compilar) COMPILAR=0 ;;
        *) die "argumento desconocido: $arg" ;;
    esac
done

trap limpiar_temporales EXIT

require_connection

# --- Build -------------------------------------------------------------------
if [ "$COMPILAR" = "1" ]; then
    say "Compilando el frontend"
    ( cd "$REPO_ROOT/frontend" && npm run build ) \
        || die "el build falló; no se subió nada"
    ok "build terminado"
fi

DIST="$REPO_ROOT/frontend/dist"
[ -f "$DIST/index.html" ] || die "no existe frontend/dist/index.html — corre el build primero"

# --- Publicación -------------------------------------------------------------
# Las tres exclusiones son la parte importante de este script, y aparecen dos
# veces: una para no subirlas, otra para no borrarlas.
#
#   .htaccess   Inerte en producción, que corre nginx (deploy/vps/): el
#               enrutado vive en el vhost. Se sigue excluyendo porque el
#               montaje Apache de deploy/hostinger/ continúa documentado, y
#               allá un .htaccess que Vite copiara a dist/ sí pisaría al de
#               producción.
#   index.php   Front controller de Laravel. No viene del build y no se toca.
#   robots.txt  Se sube una sola vez en la instalación inicial.
say "Publicando dist/ en el docroot"
subir_paquete "$DIST" "$REMOTE_DOCROOT" \
    --exclude='./.htaccess' \
    --exclude='./index.php' \
    --exclude='./robots.txt'
ok "SPA publicado"

# Los chunks de Vite llevan hash en el nombre, así que cada build genera
# archivos nuevos sin reemplazar a los viejos. Sin este borrado, assets/ crece
# indefinidamente con las versiones de todos los despliegues anteriores.
borrar_sobrantes "$REMOTE_DOCROOT" "\
    -name .htaccess -prune -o \
    -name index.php -prune -o \
    -name robots.txt -prune -o"

# El index.php de producción tiene que seguir ahí: sin él el sitio responde 404.
# No se comprueba el .htaccess porque producción corre nginx (deploy/vps/), que
# lo ignora — el enrutado vive en el vhost, no en el docroot.
say "Comprobando los archivos de producción del docroot"
if remote "[ -f '$REMOTE_DOCROOT/index.php' ]"; then
    ok "index.php presente"
else
    warn "FALTA $REMOTE_DOCROOT/index.php — súbelo desde deploy/hostinger/ (ver README)"
fi

say "Frontend desplegado"
printf '    Verifica con:  deploy/verify.sh\n\n'
