#!/usr/bin/env bash
#
# Despliega el backend Laravel al servidor.
#
#     deploy/deploy-backend.sh
#     deploy/deploy-backend.sh --sin-migrar    (sube código, no toca la base)
#
# Qué hace, en orden:
#   1. Comprueba conexión e instalación
#   2. Pone el sitio en mantenimiento
#   3. Sube el código con tar sobre SSH (sin .env, storage/, vendor/ ni public/)
#   4. composer install --no-scripts + package:discover
#   5. Respalda la base de datos (central y la de cada tenant)
#   6. php artisan migrate --force && php artisan tenants:migrate --force
#   7. Recachea configuración, rutas y eventos
#   8. Levanta el sitio
#
# Nunca toca: .env, bootstrap/cache/, ni nada dentro de public_html.

. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

MIGRAR=1
for arg in "$@"; do
    case "$arg" in
        --sin-migrar) MIGRAR=0 ;;
        *) die "argumento desconocido: $arg" ;;
    esac
done

require_connection
require_installed

# --- Mantenimiento -----------------------------------------------------------
# El trap garantiza que el sitio vuelva a levantarse aunque el script muera a
# la mitad. Un despliegue fallido que además deja el sitio caído es dos
# problemas en vez de uno.
EN_MANTENIMIENTO=0
levantar() {
    [ "$EN_MANTENIMIENTO" = "1" ] || return 0
    remote "cd '$REMOTE_APP' && $REMOTE_PHP artisan up" >/dev/null 2>&1 \
        && ok "sitio levantado" \
        || warn "NO se pudo levantar el sitio. Hazlo a mano: deploy/artisan.sh up"
}
trap 'levantar; limpiar_temporales' EXIT

if remote "[ -f '$REMOTE_APP/vendor/autoload.php' ]"; then
    say "Poniendo el sitio en mantenimiento"
    # Sin --render: esa opción exige una vista Blade errors/503, y este proyecto
    # es API pura. Con `down` a secas, Laravel responde 503 con su JSON por
    # defecto, que es justo lo que el SPA sabe interpretar.
    if remote "cd '$REMOTE_APP' && $REMOTE_PHP artisan down --retry=60" >/dev/null 2>&1; then
        EN_MANTENIMIENTO=1
        ok "en mantenimiento"
    else
        warn "no se pudo activar el mantenimiento; el despliegue sigue"
    fi
else
    warn "no hay vendor/ en el servidor: se salta el mantenimiento (primer despliegue)"
fi

# --- Código ------------------------------------------------------------------
# Estas exclusiones se repiten abajo en la expresión de find, y las dos listas
# tienen que decir lo mismo: lo que no se sube y tampoco se excluye del borrado,
# se borraría del servidor. Por eso .env, storage/ y vendor/ están en ambas.
say "Subiendo el código de backend/"
subir_paquete "$REPO_ROOT/backend" "$REMOTE_APP" \
    --exclude='./.env' \
    --exclude='./.env.*' \
    --exclude='./vendor' \
    --exclude='./node_modules' \
    --exclude='./storage' \
    --exclude='./bootstrap/cache' \
    --exclude='./public' \
    --exclude='./tests' \
    --exclude='./.git' \
    --exclude='./.gitignore' \
    --exclude='./.gitattributes' \
    --exclude='./.phpunit.result.cache' \
    --exclude='*.log'
ok "código extraído en el servidor"

borrar_sobrantes "$REMOTE_APP" "\
    -path ./vendor -prune -o \
    -path ./node_modules -prune -o \
    -path ./storage -prune -o \
    -path ./bootstrap/cache -prune -o \
    -path ./public -prune -o \
    -path ./tests -prune -o \
    -name .env -prune -o \
    -name '.env.*' -prune -o \
    -name '*.log' -prune -o \
    -name .desplegado -prune -o"

# --- Directorios de runtime --------------------------------------------------
# storage/ y bootstrap/cache/ quedan fuera de la subida porque su contenido es
# del servidor: logs, sesiones y caché compilada. Pero entonces nadie crea los
# directorios en el primer despliegue, y Laravel falla con "The bootstrap/cache
# directory must be present and writable" desde package:discover, mucho antes
# de llegar a nada que se parezca a la causa.
#
# mkdir -p es idempotente: en los despliegues siguientes no hace nada, y en
# ningún caso toca lo que ya haya dentro.
say "Asegurando los directorios de runtime"
remote "cd '$REMOTE_APP' && mkdir -p \
    bootstrap/cache \
    storage/app/private \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/testing \
    storage/framework/views \
    storage/logs && \
    chmod -R 775 bootstrap/cache storage"
ok "directorios listos"

# --- Dependencias ------------------------------------------------------------
# --no-scripts es obligatorio: Hostinger deshabilita proc_open, y el script
# post-autoload-dump de Laravel lanza `artisan package:discover` a través de
# Symfony\Process, que sin proc_open ni siquiera se construye. El descubrimiento
# se hace en el paso siguiente, invocando artisan directamente.
say "Instalando dependencias de Composer"
remote "cd '$REMOTE_APP' && composer install --no-dev --optimize-autoloader --no-interaction --no-progress --no-scripts"
remote "cd '$REMOTE_APP' && $REMOTE_PHP artisan package:discover --no-ansi"
ok "dependencias listas"

# --- Caché vieja -------------------------------------------------------------
# Se limpia ANTES de migrar: una configuración cacheada de la versión anterior
# puede apuntar a cosas que el código nuevo ya no tiene.
say "Limpiando cachés"
remote "cd '$REMOTE_APP' && $REMOTE_PHP artisan config:clear && $REMOTE_PHP artisan route:clear && $REMOTE_PHP artisan event:clear && $REMOTE_PHP artisan view:clear"
ok "cachés limpias"

# --- Base de datos -----------------------------------------------------------
if [ "$MIGRAR" = "1" ]; then
    say "Migraciones pendientes"
    remote "cd '$REMOTE_APP' && $REMOTE_PHP artisan migrate:status --pending" || true

    say "Respaldando la base de datos"
    remote "APP_DIR='$REMOTE_APP' bash -s" <<'FIN_RESPALDO'
set -euo pipefail
cd "$APP_DIR"

# Lee un valor del .env quitando comillas si las tiene.
leer_env() {
    sed -n "s/^$1=//p" .env | head -1 \
        | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"
}

DB_DATABASE="$(leer_env DB_DATABASE)"
DB_USERNAME="$(leer_env DB_USERNAME)"
DB_PASSWORD="$(leer_env DB_PASSWORD)"

[ -n "$DB_DATABASE" ] || { echo "no pude leer DB_DATABASE de .env" >&2; exit 1; }

mkdir -p ~/backups
DESTINO=~/backups/indriver-$(date +%Y%m%d-%H%M%S).sql.gz

# MYSQL_PWD en vez de -p en la línea de comandos: así la contraseña no queda
# visible en la lista de procesos del servidor compartido.
MYSQL_PWD="$DB_PASSWORD" mysqldump \
    --single-transaction --quick --no-tablespaces \
    -u"$DB_USERNAME" "$DB_DATABASE" | gzip > "$DESTINO"

echo "respaldo: $DESTINO ($(du -h "$DESTINO" | cut -f1))"

# Conserva los 10 más recientes.
ls -1t ~/backups/indriver-*.sql.gz 2>/dev/null | tail -n +11 | xargs -r rm --
FIN_RESPALDO
    ok "respaldo hecho"

    say "Aplicando migraciones"
    remote "cd '$REMOTE_APP' && $REMOTE_PHP artisan migrate --force"
    ok "migraciones aplicadas"

    # --- Bases de tenants ------------------------------------------------
    # Cada tenant vive en su propia base física (delivery_tenant_<id>, spec
    # 005) y las migraciones de database/migrations/tenant/ no las toca el
    # `artisan migrate` de arriba — hace falta tenants:migrate aparte. Si no
    # hay tenants todavía (primer despliegue), ambos pasos no hacen nada.
    say "Respaldando las bases de tenants"
    remote "APP_DIR='$REMOTE_APP' bash -s" <<'FIN_RESPALDO_TENANTS'
set -euo pipefail
cd "$APP_DIR"

leer_env() {
    sed -n "s/^$1=//p" .env | head -1 \
        | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"
}

DB_USERNAME="$(leer_env DB_USERNAME)"
DB_PASSWORD="$(leer_env DB_PASSWORD)"

[ -n "$DB_USERNAME" ] || { echo "no pude leer DB_USERNAME de .env" >&2; exit 1; }

mkdir -p ~/backups

# El prefijo delivery_tenant_ es el que usa config/tenancy.php (spec 005). Se
# consulta information_schema en vez de `artisan tenants:list` para no
# depender de que artisan ya tenga la caché de config reconstruida en este
# punto del despliegue.
BASES_TENANT="$(MYSQL_PWD="$DB_PASSWORD" mysql -N -u"$DB_USERNAME" -e \
    "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE 'delivery_tenant_%'")"

if [ -z "$BASES_TENANT" ]; then
    echo "sin tenants todavía, nada que respaldar"
fi

for BASE in $BASES_TENANT; do
    DESTINO=~/backups/indriver-${BASE}-$(date +%Y%m%d-%H%M%S).sql.gz
    MYSQL_PWD="$DB_PASSWORD" mysqldump \
        --single-transaction --quick --no-tablespaces \
        -u"$DB_USERNAME" "$BASE" | gzip > "$DESTINO"
    echo "respaldo: $DESTINO ($(du -h "$DESTINO" | cut -f1))"

    # Conserva los 10 más recientes de este tenant.
    ls -1t ~/backups/indriver-${BASE}-*.sql.gz 2>/dev/null | tail -n +11 | xargs -r rm --
done
FIN_RESPALDO_TENANTS
    ok "respaldo de tenants hecho"

    say "Aplicando migraciones de tenants"
    remote "cd '$REMOTE_APP' && $REMOTE_PHP artisan tenants:migrate --force"
    ok "migraciones de tenants aplicadas"
else
    warn "--sin-migrar: no se tocó la base de datos"
fi

# --- Caché nueva -------------------------------------------------------------
# Seguro porque no hay ninguna llamada a env() fuera de config/. Si eso cambia,
# config:cache empieza a devolver null donde antes había un valor.
say "Recacheando configuración, rutas y eventos"
remote "cd '$REMOTE_APP' && $REMOTE_PHP artisan config:cache && $REMOTE_PHP artisan route:cache && $REMOTE_PHP artisan event:cache"
ok "cachés reconstruidas"

# --- Dueño de los archivos ----------------------------------------------------
# Este script se corre por SSH como root (VPS propio, no el hosting compartido
# de antes): el código subido por tar y todo lo que generan composer/artisan
# arriba quedan root:root. PHP-FPM corre como www-data, así que sin este chown
# no puede escribir storage/logs/laravel.log ni las sesiones en cada request —
# el síntoma es un 500 con storage/logs/ vacío (el propio intento de loguear
# el error falla por permisos). Va AL FINAL, después de todos los pasos que
# corren como root y podrían recrear archivos dentro de storage/ (p. ej. si
# algún artisan de arriba llega a loguear algo), para que el dueño final sea
# siempre el correcto sin importar qué haya tocado el despliegue.
say "Ajustando el dueño de los archivos (www-data)"
remote "chown -R www-data:www-data '$REMOTE_APP'"
ok "dueño ajustado"

say "Levantando el sitio"
levantar
EN_MANTENIMIENTO=0

say "Backend desplegado"
printf '    Verifica con:  deploy/verify.sh\n\n'
