#!/usr/bin/env bash
# Plantilla de configuración del despliegue.
#
# Copia este archivo a deploy/config.sh y pon los valores reales:
#
#     cp deploy/config.example.sh deploy/config.sh
#
# deploy/config.sh está en .gitignore y NUNCA se versiona: contiene la ruta real
# del servidor, que incluye la IP/identificador de la cuenta.

# Alias de ~/.ssh/config que apunta al servidor. Define ahí HostName, Port,
# User e IdentityFile en vez de repetirlos en cada script.
SSH_ALIAS="mi-servidor"

# Carpeta del backend Laravel, FUERA del docroot. Convención de VPS propio
# (ver deploy/vps/provision.sh): /var/www/<dominio>/backend. Si en algún
# momento se despliega de nuevo a hosting compartido tipo Hostinger, la
# convención cambia a /home/<cuenta>/domains/<dominio>/backend.
REMOTE_APP="/var/www/delivery.ejemplo.com/backend"

# Docroot del subdominio: aquí conviven el SPA compilado y el front controller.
REMOTE_DOCROOT="/var/www/delivery.ejemplo.com/public_html"

# Ruta del binario de PHP en el servidor (`which php` o `which php8.3` por SSH).
REMOTE_PHP="/usr/bin/php8.3"

# URL pública del sistema, sin barra final. La usa deploy/verify.sh.
SITE_URL="https://delivery.ejemplo.com"
