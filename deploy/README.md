# Despliegue en VPS propio

Ver [specs/002-despliegue-hostinger.md](../specs/002-despliegue-hostinger.md) para el porqué de
cada decisión — incluida la migración desde el hosting compartido de Hostinger, donde vivió
originalmente el sistema, a un VPS propio. Esto es solo el procedimiento operativo.

**Por qué ya no es hosting compartido**: el hosting compartido no permitía que la app hiciera
`CREATE DATABASE` / `DROP DATABASE` en caliente — necesario porque `stancl/tenancy` crea una base de
datos física por cada tenant nuevo. El sistema vive ahora en un VPS (Ubuntu 24.04, Nginx + PHP-FPM,
MySQL con privilegios reales) donde eso sí es posible.

## Requisitos del servidor

- Ubuntu 24.04 LTS (o similar), acceso `root` por SSH.
- PHP 8.3 + extensiones: `fpm`, `cli`, `mysql`, `mbstring`, `xml`, `curl`, `zip`, `bcmath`.
- Nginx, MySQL 8, Composer, `certbot` + `python3-certbot-nginx`.
- `ufw` como firewall (22, 80, 443).
- Certificado SSL vía Let's Encrypt (`certbot --nginx`), con renovación automática ya configurada
  por el paquete `certbot` (systemd timer).

A diferencia del hosting compartido de antes, aquí **sí** hay `proc_open`/`exec` disponibles (así
que `composer install` podría correr sin `--no-scripts` si se quisiera simplificar el script más
adelante) y **sí** hay `crontab` real — útil el día que `routes/console.php` declare alguna tarea
programada (hoy no declara ninguna).

## Instalación inicial en un VPS nuevo

1. Confirmar acceso SSH (`ssh <alias>`) con la clave ya cargada en `~/.ssh/config`.
2. Aprovisionar el servidor (idempotente, se puede volver a correr):
   ```bash
   bash deploy/vps/provision.sh
   ```
   Instala Nginx, PHP-FPM, MySQL, Composer, certbot y ufw; asegura MySQL (equivalente a
   `mysql_secure_installation`); crea la base central y un usuario de aplicación con privilegios
   reales de `CREATE`/`DROP DATABASE` sobre el prefijo `delivery_tenant_%` (el que usa
   `config/tenancy.php`); abre el firewall; crea `/var/www/<dominio>/{backend,public_html}`. Al
   final imprime la contraseña generada del usuario de base de datos — anótala, no queda guardada
   en ningún archivo.
3. Copia `deploy/config.example.sh` a `deploy/config.sh` y pon los valores reales del servidor.
4. Crear `$REMOTE_APP/.env` a mano desde `deploy/hostinger/env.production.example`, con las
   credenciales de base de datos del paso 2 y el resto de secretos reales (SMTP, etc.):
   ```bash
   bash -c '. deploy/config.sh && ssh "$SSH_ALIAS" -t "nano \"$REMOTE_APP/.env\""'
   ```
5. Subir el backend:
   ```bash
   bash deploy/deploy-backend.sh
   ```
   Genera `APP_KEY` aparte (no lo hace el script):
   `bash deploy/artisan.sh key:generate --force`, seguido de `bash deploy/artisan.sh config:cache`.
6. Compilar y subir el frontend:
   ```bash
   bash deploy/deploy-frontend.sh
   ```
7. Subir el front controller de producción y activar el sitio de Nginx:
   ```bash
   bash -c '. deploy/config.sh && \
       scp deploy/hostinger/index.php "$SSH_ALIAS:$REMOTE_DOCROOT/index.php" && \
       scp deploy/hostinger/robots.txt "$SSH_ALIAS:$REMOTE_DOCROOT/robots.txt" && \
       scp deploy/vps/nginx-delivery.prosello.com.mx.conf "$SSH_ALIAS:/etc/nginx/sites-available/$(basename "$SITE_URL")"'
   ```
   (Para un dominio distinto, copia y adapta `deploy/vps/nginx-delivery.prosello.com.mx.conf` con
   el nombre de host correcto antes de subirlo.) Luego, por SSH:
   ```bash
   ln -sf /etc/nginx/sites-available/<dominio> /etc/nginx/sites-enabled/<dominio>
   rm -f /etc/nginx/sites-enabled/default   # solo la primera vez
   nginx -t && systemctl reload nginx
   ```
8. Con el DNS del subdominio ya apuntando a la IP del VPS, emitir el certificado real:
   ```bash
   ssh <alias> "certbot --nginx -d <dominio> --non-interactive --agree-tos -m <email> --redirect"
   ```
9. Verificar:
   ```bash
   bash deploy/verify.sh
   ```

## Despliegue de un cambio posterior

- **Solo frontend**: `bash deploy/deploy-frontend.sh`.
- **Solo backend**: `bash deploy/deploy-backend.sh` (o `--sin-migrar` si no hay migraciones
  nuevas).
- Verificar con `bash deploy/verify.sh`, o usar el comando `/deploy` (`.claude/commands/deploy.md`),
  que detecta qué cambió, despliega y verifica en un solo paso.

`deploy-backend.sh` se corre por SSH como `root` (no hay un usuario de despliegue separado del
`root` del VPS todavía — ver "Fuera de alcance" en la spec de migración) y por eso, al final, ajusta
el dueño de todo `$REMOTE_APP` a `www-data:www-data` — sin eso, PHP-FPM (que sí corre como
`www-data`) no puede escribir `storage/logs` ni las sesiones. Ese paso es automático, no hace falta
correrlo a mano.

`bash deploy/artisan.sh config:clear` antes de recachear cuando algo no toma efecto: la
configuración cacheada es la causa habitual de "cambié el `.env` y no pasó nada".

## Tarea programada

Ninguna todavía. `routes/console.php` no declara ningún comando propio. El día que se agregue una,
a diferencia del hosting compartido de antes, aquí sí hay `crontab` real:
`crontab -e` (como `root`, o el usuario que corra PHP-FPM) con una línea por minuto llamando a
`php8.3 /var/www/<dominio>/backend/artisan schedule:run`.

## Cuando algo no funciona

- **`deploy/verify.sh` dice que una ruta de API responde HTML en vez de JSON**: revisa el server
  block de Nginx (`deploy/vps/nginx-delivery.prosello.com.mx.conf`) — probablemente el bloque
  `location ~ ^/(api|sanctum|up)` no está activo o `nginx -t` falló silenciosamente en el último
  reload.
- **500 con `storage/logs/laravel.log` vacío o inexistente**: casi seguro es el dueño de archivos.
  PHP-FPM corre como `www-data`; si algo dejó `storage/` como `root:root` (por ejemplo, tocar
  archivos a mano por SSH sin querer), no puede ni escribir su propio log del error. Arreglo:
  `ssh <alias> "chown -R www-data:www-data $REMOTE_APP"`. `deploy-backend.sh` ya hace esto al final
  de cada despliegue normal.
- **"cambié el `.env` y no pasó nada"**: `bash deploy/artisan.sh config:clear`, luego
  `bash deploy/artisan.sh config:cache`.
- **El sitio se quedó en mantenimiento**: `bash deploy/artisan.sh up`.
- **`deploy/deploy-backend.sh` falla en "no existe `$REMOTE_APP/.env`"**: falta el paso 4 de la
  instalación inicial.
- **El certificado no renueva solo**: el paquete `certbot` instala un timer de systemd
  (`certbot.timer`) que corre dos veces al día; comprobar con
  `ssh <alias> "systemctl status certbot.timer"`.
