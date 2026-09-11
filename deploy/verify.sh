#!/usr/bin/env bash
#
# Comprueba desde fuera que el sitio quedó bien publicado.
#
#     deploy/verify.sh
#
# Todas las comprobaciones se hacen con curl contra la URL pública: es lo que
# ve un usuario real, no lo que dice el servidor de sí mismo.

. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

FALLOS=0

# comprobar <descripción> <esperado> <obtenido>
comprobar() {
    if [ "$2" = "$3" ]; then
        ok "$1"
    else
        warn "$1  (esperaba '$2', obtuve '$3')"
        FALLOS=$((FALLOS + 1))
    fi
}

codigo()      { curl -s -o /dev/null -w '%{http_code}' "$@"; }
tipo()        { curl -s -o /dev/null -w '%{content_type}' "$@"; }

# El handshake de WebSocket deja la conexión abierta (es su naturaleza), así que
# --max-time la corta a los 3s — curl igual imprime el código de la respuesta
# recibida antes de cortar (exit 28 de curl, que se ignora a propósito aquí).
codigo_ws()   { curl -s --max-time 3 -o /dev/null -w '%{http_code}' \
                    -H 'Upgrade: websocket' -H 'Connection: Upgrade' \
                    -H 'Sec-WebSocket-Version: 13' \
                    -H 'Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==' \
                    "$@" 2>/dev/null || true; }

HOST="${SITE_URL#https://}"

say "Disponibilidad"
comprobar "GET /up responde 200"                  200 "$(codigo "$SITE_URL/up")"
comprobar "GET / responde 200"                    200 "$(codigo "$SITE_URL/")"

say "Host canónico"
comprobar "http:// redirige con 301"              301 "$(codigo "http://$HOST/")"

say "Separación entre SPA y API"
# Ruta profunda del SPA escrita a mano: no existe como archivo en el docroot,
# así que si el fallback de Vue Router funciona, el .htaccess responde
# index.html (200) en vez de un 404 del servidor.
comprobar "ruta profunda del SPA carga la app"    200 "$(codigo "$SITE_URL/cualquier-ruta-del-spa")"

# Una ruta de API que no existe tiene que responder 404 EN JSON —viene de
# Laravel, con shouldRenderJsonWhen('api/*')—, nunca el index.html del SPA. Es
# la comprobación central de esta spec: confirma que /api/v1/* de verdad llega
# al front controller y no lo atrapa el fallback del punto anterior.
API_CODIGO="$(codigo "$SITE_URL/api/v1/ping-inexistente")"
API_TIPO="$(tipo "$SITE_URL/api/v1/ping-inexistente")"
comprobar "ruta de API inexistente responde 404"  404 "$API_CODIGO"
case "$API_TIPO" in
    application/json*) ok "y responde JSON, no el SPA" ;;
    *) warn "respondió '$API_TIPO' en vez de JSON — revisa el .htaccess (¿capturó /api/* el fallback del SPA?)"
       FALLOS=$((FALLOS + 1)) ;;
esac

say "Sanctum"
comprobar "GET /sanctum/csrf-cookie responde 204" 204 "$(codigo "$SITE_URL/sanctum/csrf-cookie")"

say "Tiempo real (Reverb)"
# No es un chequeo cosmético: Reverb corre como servicio systemd aparte
# (reverb.service) y nada de lo anterior en este script lo toca ni lo arranca.
# Puede estar `enabled` (sobrevive reinicios) sin estar `active` — pasó de
# verdad en producción: Nginx y el Panel ya estaban listos y Reverb nunca se
# había iniciado, así que nadie recibía nada en tiempo real y no había forma
# de notarlo salvo probando el propio handshake (spec tenant/018, revisión
# posterior a la implementación, punto 21).
REVERB_KEY="$(sed -n 's/^VITE_REVERB_APP_KEY=//p' "$(dirname "${BASH_SOURCE[0]}")/../frontend/.env.production" | tr -d '\r')"
if [ -z "$REVERB_KEY" ]; then
    warn "no se encontró VITE_REVERB_APP_KEY en frontend/.env.production — comprobación de Reverb OMITIDA."
else
    comprobar "el handshake de WebSocket a Reverb responde 101" \
        101 "$(codigo_ws "$SITE_URL/app/$REVERB_KEY?protocol=7&client=js&version=8.4.0&flash=false")"
fi

say "Nada del proyecto es descargable"

# Aquí NO se comprueba el código de estado. Con el fallback del SPA, una ruta
# que no existe en el docroot devuelve 200 con index.html, que es correcto y no
# filtra nada. Lo que importa es el contenido: si la respuesta trae la cadena
# delatora del archivo real, entonces el archivo sí se está sirviendo.
#
#   no_filtra <ruta> <cadena que solo aparece en el archivo real>
no_filtra() {
    local cuerpo
    cuerpo="$(curl -s --max-time 20 "$SITE_URL/$1")"
    if printf '%s' "$cuerpo" | grep -qF -- "$2"; then
        warn "/$1 ESTÁ SIRVIENDO EL ARCHIVO REAL (contiene '$2')"
        FALLOS=$((FALLOS + 1))
    else
        ok "/$1 no expone el archivo"
    fi
}

no_filtra .env                     "APP_KEY="
no_filtra composer.json            "laravel/framework"
no_filtra composer.lock            "packages-dev"
no_filtra artisan                  "Illuminate\\Foundation\\Application"
no_filtra vendor/autoload.php      "ComposerAutoloaderInit"
no_filtra storage/logs/laravel.log "production.ERROR"
no_filtra .git/config              "[remote \"origin\"]"

say "Cabeceras de caché"
CACHE_INDEX="$(curl -s -o /dev/null -D - "$SITE_URL/" | tr -d '\r' | sed -n 's/^[Cc]ache-[Cc]ontrol: //p')"
case "$CACHE_INDEX" in
    *no-cache*) ok "index.html se revalida (no-cache)" ;;
    *) warn "index.html trae Cache-Control '$CACHE_INDEX' — debería incluir no-cache"
       FALLOS=$((FALLOS + 1)) ;;
esac

# Un asset con hash de contenido en el nombre (cualquier .js del build de Vite)
# tiene que llegar como inmutable: si no, se revalida en cada carga sin
# necesidad, porque un hash distinto ya identifica cualquier cambio.
JS="$(ls "$(dirname "${BASH_SOURCE[0]}")/../frontend/dist/assets"/*.js 2>/dev/null | head -1)"
if [ -z "$JS" ]; then
    warn "no hay ningún .js en frontend/dist/assets — corre 'npm run build' primero."
    warn "Comprobación de caché de assets OMITIDA."
else
    CACHE_ASSET="$(curl -s -o /dev/null -D - "$SITE_URL/assets/$(basename "$JS")" | tr -d '\r' | sed -n 's/^[Cc]ache-[Cc]ontrol: //p')"
    case "$CACHE_ASSET" in
        *immutable*) ok "assets con hash se cachean como inmutables" ;;
        *) warn "asset con hash trae Cache-Control '$CACHE_ASSET' — debería incluir immutable"
           FALLOS=$((FALLOS + 1)) ;;
    esac
fi

echo
if [ "$FALLOS" -eq 0 ]; then
    printf '%sTodo correcto.%s\n\n' "$C_OK" "$C_OFF"
else
    printf '%s%d comprobación(es) fallaron.%s\n\n' "$C_ERR" "$FALLOS" "$C_OFF"
    exit 1
fi
