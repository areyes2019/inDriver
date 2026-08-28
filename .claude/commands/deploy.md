---
description: Despliega a produccion (delivery.prosello.com.mx) lo que haya cambiado desde el ultimo despliegue
argument-hint: "[backend | frontend | todo]  (por defecto: detectar solo)"
---

Despliega el sistema a producción siguiendo este procedimiento. Los scripts hacen
el trabajo; tú decides qué correr, verificas el resultado y reportas.

Alcance pedido por el usuario: **$ARGUMENTS**
(vacío = detectar automáticamente qué cambió)

## 1. El repositorio tiene que estar limpio

Corre `git status --porcelain`.

- Si hay cambios sin commitear, **detente** y dile al usuario qué archivos son.
  No despliegues trabajo a medias: lo que llega al servidor debe corresponder a
  un commit, o el día que algo falle no habrá forma de saber qué está corriendo.
- Si hay commits locales sin pushear, haz `git push` primero. Producción no
  debe adelantarse a GitHub.

## 2. Averigua qué cambió

El servidor guarda el commit desplegado en `$REMOTE_APP/.desplegado`:

```bash
. deploy/config.sh
ssh "$SSH_ALIAS" "cat '$REMOTE_APP/.desplegado' 2>/dev/null || echo ninguno"
```

- Si devuelve un SHA: `git diff --name-only <SHA> HEAD` te dice qué se tocó.
- Si devuelve `ninguno` (primer despliegue con este comando): trata todo como
  cambiado.

Reglas para decidir:

| Cambió | Corre |
|---|---|
| algo bajo `backend/` | `bash deploy/deploy-backend.sh` |
| algo bajo `frontend/` | `bash deploy/deploy-frontend.sh` |
| solo `specs/`, `deploy/README.md`, `.gitignore` | nada — dilo y termina |
| `deploy/hostinger/htaccess-public_html` o `index.php` | avisa: hay que resubirlos a mano (paso 7 de la instalación inicial en `deploy/README.md`) |

Si el usuario pidió un alcance explícito en `$ARGUMENTS`, respétalo por encima
de la detección.

Cuando `backend/` no trae migraciones nuevas (`git diff --name-only ... |
grep database/migrations` vacío), usa `bash deploy/deploy-backend.sh --sin-migrar`:
se salta el respaldo y el `migrate`, y el despliegue tarda la mitad.

## 3. Revisa si hay variables de entorno nuevas

Si el diff toca `backend/.env.example` o `deploy/hostinger/env.production.example`,
**detente antes de desplegar** y avisa al usuario: el `.env` del servidor no tiene
las variables nuevas y hay que agregarlas a mano primero. Dale el comando:

```bash
bash -c '. deploy/config.sh && ssh "$SSH_ALIAS" -t "nano \"$REMOTE_APP/.env\""'
```

Dile exactamente qué líneas agregar, tomadas del diff. No las inventes ni pongas
valores de ejemplo en producción.

## 4. Despliega

Corre los scripts que correspondan. Si uno falla:

- **No sigas con el siguiente.**
- Muestra la salida real del error, sin resumirla.
- Comprueba que el sitio no quedó en mantenimiento:
  `bash deploy/artisan.sh up`

## 5. Verifica

```bash
bash deploy/verify.sh
```

Reporta el resultado tal cual. Si alguna comprobación falla, consulta la sección
"Cuando algo no funciona" de `deploy/README.md` antes de improvisar un
diagnóstico.

## 6. Anota qué quedó desplegado

Solo si todo lo anterior salió bien:

```bash
. deploy/config.sh
ssh "$SSH_ALIAS" "echo '$(git rev-parse HEAD)' > '$REMOTE_APP/.desplegado'"
```

## 7. Reporta

Una tabla corta: qué se desplegó, si hubo migraciones, el resultado de
`verify.sh`, y el SHA que quedó en producción. Si algo quedó pendiente o
sospechoso, dilo explícitamente en vez de cerrar con un "listo".

## Nunca, en este comando

- `migrate:fresh`, `migrate:reset`, `db:wipe` — `deploy/artisan.sh` ya los
  bloquea; no los rodees por SSH.
- Editar `.env`, `storage/` o `bootstrap/cache/` del servidor.
- Subir `frontend/dist/.htaccess` al docroot si algún día existe: pisaría el
  `.htaccess` de producción y tumbaría el enrutado hacia Laravel. Los scripts
  ya lo excluyen.
- Desplegar con el árbol sucio.
