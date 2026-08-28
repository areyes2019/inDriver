#!/usr/bin/env bash
#
# Ejecuta un comando de Laravel en el servidor de producción.
#
#     deploy/artisan.sh migrate --force
#     deploy/artisan.sh migrate:status
#     deploy/artisan.sh tinker            <- no: tinker necesita terminal interactiva
#
# Es la vía normal para correr migraciones en producción desde la máquina de
# desarrollo, sin abrir una sesión SSH a mano.

. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

[ $# -gt 0 ] || die "uso: deploy/artisan.sh <comando> [argumentos]
       ejemplo: deploy/artisan.sh migrate --force"

# Bloqueo deliberado: estos comandos vacían la base entera. En producción no se
# corren nunca por accidente; si de verdad hacen falta, se hacen a mano por SSH
# y con un respaldo reciente delante.
case "$1" in
    migrate:fresh|migrate:reset|db:wipe)
        die "'$1' destruye todos los datos de producción y este script no lo permite.
       Si es realmente lo que quieres, hazlo por SSH a mano y con respaldo."
        ;;
esac

require_connection
require_installed

say "artisan $*"
remote "cd '$REMOTE_APP' && $REMOTE_PHP artisan $(printf '%q ' "$@")"
