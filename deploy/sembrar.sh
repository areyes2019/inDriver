#!/usr/bin/env bash
#
# Crea envíos de prueba en producción con lugares reales de Google Maps, para
# probar la cola de envíos de la app del conductor sin capturarlos en el Panel.
#
#     deploy/sembrar.sh                       <- 3 envíos, Celaya
#     deploy/sembrar.sh 5                     <- 5 envíos
#     deploy/sembrar.sh 3 --tenant=panda      <- si hay más de un tenant activo
#     deploy/sembrar.sh 3 --ambiente=test     <- forzar TEST/LIVE (por omisión, el interruptor del tenant)
#     deploy/sembrar.sh 3 --centro=21.12,-101.68 --radio=6   <- otra ciudad
#
# Es `pedidos:sembrar --google --force` vía deploy/artisan.sh: los envíos se
# publican de verdad y les llegan a los conductores en turno.

cantidad=3
if [ $# -gt 0 ] && [[ "$1" =~ ^[0-9]+$ ]]; then
    cantidad=$1
    shift
fi

exec "$(dirname "${BASH_SOURCE[0]}")/artisan.sh" pedidos:sembrar "$cantidad" --google --force "$@"
