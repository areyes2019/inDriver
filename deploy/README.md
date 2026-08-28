# Despliegue en Hostinger

Ver [specs/002-despliegue-hostinger.md](../specs/002-despliegue-hostinger.md) para el porqué de
cada decisión. Esto es solo el procedimiento operativo.

## Requisitos del servidor

- PHP 8.3 o superior.
- Extensiones: `pdo_mysql`, `mbstring`, `openssl`, `curl`, `dom`, `fileinfo`, `iconv`, `zip`.
- Acceso SSH.
- Certificado SSL activo para `delivery.prosello.com.mx` (Hostinger lo emite gratis).
- El hosting deshabilita `proc_open`, `exec`, `shell_exec`, `system`, `popen` y `symlink`. Por eso
  `composer install` corre con `--no-scripts` y el descubrimiento de paquetes se hace llamando
  `php artisan package:discover` directamente.

## Instalación inicial (una sola vez)

1. En hPanel: PHP 8.3, SSL activo, base de datos MySQL creada (anotar nombre, usuario y
   contraseña).
2. Copia `deploy/config.example.sh` a `deploy/config.sh` y pon los valores reales del servidor.
3. Subir el backend:
   ```bash
   bash deploy/deploy-backend.sh
   ```
   La primera vez fallará en el paso que requiere `.env` en el servidor — es esperado. Sigue con
   el paso 4 y vuelve a correrlo.
4. Crear `$REMOTE_APP/.env` desde `deploy/hostinger/env.production.example`, con las credenciales
   reales:
   ```bash
   bash -c '. deploy/config.sh && ssh "$SSH_ALIAS" -t "nano \"$REMOTE_APP/.env\""'
   ```
   Luego `php artisan key:generate` en el servidor (o `deploy/artisan.sh key:generate`).
5. Vuelve a correr `bash deploy/deploy-backend.sh`. Esta vez completa: dependencias, migraciones,
   caché.
6. Compilar y subir el frontend:
   ```bash
   bash deploy/deploy-frontend.sh
   ```
7. Subir los archivos de producción del docroot, **después** del paso 6 (el build de Vite no trae
   `.htaccess` propio, pero por si acaso, este paso siempre va al final):
   ```bash
   bash -c '. deploy/config.sh && \
       scp deploy/hostinger/index.php "$SSH_ALIAS:$REMOTE_DOCROOT/index.php" && \
       scp deploy/hostinger/htaccess-public_html "$SSH_ALIAS:$REMOTE_DOCROOT/.htaccess" && \
       scp deploy/hostinger/robots.txt "$SSH_ALIAS:$REMOTE_DOCROOT/robots.txt"'
   ```
8. Verificar:
   ```bash
   bash deploy/verify.sh
   ```

## Despliegue de un cambio posterior

- **Solo frontend**: `bash deploy/deploy-frontend.sh`.
- **Solo backend**: `bash deploy/deploy-backend.sh` (o `--sin-migrar` si no hay migraciones
  nuevas).
- Verificar con `bash deploy/verify.sh`, o usar el comando `/deploy` (`.claude/commands/deploy.md`),
  que detecta qué cambió, despliega y verifica en un solo paso.

`bash deploy/artisan.sh config:clear` antes de recachear cuando algo no toma efecto: la
configuración cacheada es la causa habitual de "cambié el `.env` y no pasó nada".

## Tarea programada

Ninguna todavía. `routes/console.php` no declara ningún comando propio. El día que se agregue una,
recuerda que el hosting **no tiene `crontab` por línea de comandos** — la línea se agrega a mano en
hPanel → *Avanzado → Trabajos Cron*, invocando el comando directamente (no `schedule:run`, que
depende de `proc_open`, deshabilitado aquí).

## Cuando algo no funciona

- **`deploy/verify.sh` dice que una ruta de API responde HTML en vez de JSON**: el `.htaccess` del
  servidor está desactualizado o no se subió — repite el paso 7 de la instalación inicial.
- **"cambié el `.env` y no pasó nada"**: `bash deploy/artisan.sh config:clear`, luego
  `bash deploy/artisan.sh config:cache`.
- **El sitio se quedó en mantenimiento**: `bash deploy/artisan.sh up`.
- **`deploy/deploy-backend.sh` falla en "no existe `$REMOTE_APP/.env`"**: falta el paso 4 de la
  instalación inicial.
