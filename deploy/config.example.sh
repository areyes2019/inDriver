#!/usr/bin/env bash
# Plantilla de configuración del despliegue.
#
# Copia este archivo a deploy/config.sh y pon los valores reales:
#
#     cp deploy/config.example.sh deploy/config.sh
#
# deploy/config.sh está en .gitignore y NUNCA se versiona: contiene la ruta real
# del servidor, que incluye el identificador de la cuenta de hosting.

# Alias de ~/.ssh/config que apunta al servidor. Define ahí HostName, Port,
# User e IdentityFile en vez de repetirlos en cada script.
SSH_ALIAS="mi-servidor"

# Carpeta del backend Laravel, FUERA del docroot.
REMOTE_APP="/home/uXXXXXXXX/domains/delivery.ejemplo.com/backend"

# Docroot del subdominio: aquí conviven el SPA compilado y el front controller.
REMOTE_DOCROOT="/home/uXXXXXXXX/domains/delivery.ejemplo.com/public_html"

# Ruta del binario de PHP en el servidor (`which php` por SSH).
REMOTE_PHP="/usr/bin/php"

# URL pública del sistema, sin barra final. La usa deploy/verify.sh.
SITE_URL="https://delivery.ejemplo.com"
