#!/usr/bin/env bash
#
# Aprovisiona el VPS nuevo (Ubuntu 24.04) para delivery.prosello.com.mx desde cero.
# Idempotente en la parte de paquetes/directorios: se puede volver a correr sin
# romper lo que ya está instalado. La creación de la base y el usuario de
# aplicación NO es idempotente en la contraseña (si el usuario ya existe, se
# omite su creación y no se cambia la contraseña).
#
#     bash deploy/vps/provision.sh
#
# Requiere el alias SSH "prosello-vps" ya configurado en ~/.ssh/config, con
# acceso root (ver plan de migración).
#
# Qué hace, en orden:
#   1. Instala nginx, PHP 8.3-FPM + extensiones, MySQL, Composer, certbot, ufw
#   2. Asegura MySQL (equivalente no interactivo a mysql_secure_installation)
#   3. Crea la base central y el usuario de aplicación con privilegios reales
#      de CREATE/DROP DATABASE sobre el prefijo delivery_tenant_ — el fix de
#      raíz del incidente de hosting compartido (ver
#      specs/002-despliegue-hostinger.md y el historial de la migración)
#   4. Abre el firewall (SSH, HTTP, HTTPS) y lo activa
#   5. Crea la estructura de directorios de la app
#
# NO hace (pasos manuales, fuera de este script — ver el plan de migración):
#   - Subir el código (deploy/deploy-backend.sh, deploy/deploy-frontend.sh)
#   - Activar el vhost de Nginx (deploy/vps/nginx-delivery.prosello.com.mx.conf)
#   - Emitir el certificado real (certbot, requiere DNS ya apuntando aquí)

set -euo pipefail

SSH_ALIAS="prosello-vps"
APP_DIR="/var/www/delivery.prosello.com.mx"
DB_NAME="delivery_central"
DB_USER="delivery_app"

say()  { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
ok()   { printf '    \033[0;32mOK\033[0m   %s\n' "$*"; }
die()  { printf '\n\033[0;31mERROR: %s\033[0m\n\n' "$*" >&2; exit 1; }

remote() { ssh -o BatchMode=yes "$SSH_ALIAS" "$@"; }

say "Comprobando conexión con $SSH_ALIAS"
remote true >/dev/null 2>&1 || die "no se pudo conectar a '$SSH_ALIAS'. Prueba a mano: ssh $SSH_ALIAS"
ok "conectado"

say "Instalando paquetes (nginx, PHP 8.3-FPM, MySQL, certbot, herramientas)"
remote "DEBIAN_FRONTEND=noninteractive bash -s" <<'FIN_PAQUETES'
set -euo pipefail
apt-get update -qq
apt-get -y -qq upgrade
apt-get -y -qq install \
    nginx \
    php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml \
    php8.3-curl php8.3-zip php8.3-bcmath \
    mysql-server \
    certbot python3-certbot-nginx \
    unzip curl ufw
FIN_PAQUETES
ok "paquetes instalados"

say "Instalando Composer"
remote "bash -s" <<'FIN_COMPOSER'
set -euo pipefail
if command -v composer >/dev/null 2>&1; then
    echo "composer ya está instalado, se omite"
    exit 0
fi
php -r "copy('https://getcomposer.org/installer', '/tmp/composer-setup.php');"
php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
rm -f /tmp/composer-setup.php
FIN_COMPOSER
ok "composer listo"

# Ubuntu deja root@localhost autenticado por auth_socket (peer auth del SO), así
# que "mysql -uroot" funciona sin contraseña porque este script corre como root
# por SSH. Nada de esto queda expuesto por red: MySQL solo escucha en
# 127.0.0.1/socket.
say "Asegurando MySQL (equivalente no interactivo a mysql_secure_installation)"
remote "mysql -uroot" <<'FIN_MYSQL_SECURE'
DELETE FROM mysql.user WHERE User='';
DELETE FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost', '127.0.0.1', '::1');
DROP DATABASE IF EXISTS test;
DELETE FROM mysql.db WHERE Db='test' OR Db LIKE 'test\_%';
FLUSH PRIVILEGES;
FIN_MYSQL_SECURE
ok "MySQL asegurado"

say "Creando base central y usuario de aplicación"
if remote "mysql -uroot -N -e \"SELECT 1 FROM mysql.user WHERE User='$DB_USER'\"" | grep -q 1; then
    ok "el usuario '$DB_USER' ya existe, se omite la creación (contraseña sin cambios)"
else
    DB_PASSWORD="$(openssl rand -base64 24)"
    remote "mysql -uroot" <<FIN_MYSQL_APP
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
GRANT ALL PRIVILEGES ON \`delivery_tenant_%\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
FIN_MYSQL_APP
    ok "base '$DB_NAME' y usuario '$DB_USER' creados"
    printf '\n    Contraseña de %s (guárdala para el .env del VPS — no queda escrita en ningún archivo):\n\n        %s\n\n' "$DB_USER" "$DB_PASSWORD"
fi

say "Configurando el firewall (ufw)"
remote "ufw allow OpenSSH >/dev/null && ufw allow 80/tcp >/dev/null && ufw allow 443/tcp >/dev/null && ufw --force enable >/dev/null"
ok "ufw activo (22, 80, 443)"

say "Creando estructura de directorios de la app"
remote "mkdir -p '$APP_DIR/backend' '$APP_DIR/public_html' && chown -R www-data:www-data '$APP_DIR'"
ok "$APP_DIR listo"

say "Aprovisionamiento terminado"
printf '    Revisa los servicios:  ssh %s "systemctl is-active nginx php8.3-fpm mysql"\n' "$SSH_ALIAS"
printf '    Siguiente paso: Fase 2 del plan de migración (subir el código).\n\n'
