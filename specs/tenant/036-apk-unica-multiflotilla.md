# SPEC-036 — Un solo APK de Panda Express para todas las flotillas

**Metadatos**

| Campo | Valor |
|---|---|
| ID | SPEC-036 |
| Módulo | App de conductor (panda_express) — acceso |
| Versión | 1.2 |
| Estado | Borrador del 2026-11-27 (1.1: distribución por Google Play; sin flotilla por defecto para sesiones viejas). 1.2 del 2026-11-28: diseño del correo de bienvenida del conductor (RN-11 a RN-15) |
| Depende de | SPEC tenant/013 (Login de conductor), tenant/018 (Push FCM), tenant/019 (Disponibilidad), tenant/029 (Recuperar contraseña), tenant/001 (Correo de credenciales), tenant/035 (Logo de la flotilla, RN-60) |

---

## 1. Historia de usuario

Como repartidor de cualquier flotilla quiero instalar la misma app Panda Express que los demás y
entrar con el código de mi flotilla, mi correo y mi contraseña, para no depender de un APK hecho
solo para mi flotilla.

Como dueño de la plataforma quiero publicar una sola app en Google Play, para no compilar y
distribuir un APK por cada flotilla nueva.

## 2. Objetivo

Hoy el APK trae la flotilla fija desde que se compila: `VITE_API_BASE_URL` ya incluye
`/t/moto-celaya`, y `VITE_TENANT_SLUG` arma el canal de Reverb. Un repartidor de otra flotilla no
puede entrar con ese APK, porque su correo se busca en la base de `moto-celaya` y le aparece
"credenciales incorrectas".

Con esta spec, el repartidor escribe el **código de flotilla** en el login. La app lo guarda junto
con la sesión y arma todas las peticiones con ese código. **El backend no cambia de modelo:** cada
flotilla sigue teniendo su propia base y todas las rutas siguen siendo `/t/{slug}/...`. Lo único
que cambia es de dónde sale el slug.

## 3. Alcance

**Incluye:**

- Un campo "Código de flotilla" en el login y en "¿Olvidaste tu contraseña?" de panda_express.
- Guardar el slug en el teléfono y usarlo en la API, en Reverb y en la autorización del canal.
- Quitar `VITE_TENANT_SLUG` y el slug de `VITE_API_BASE_URL` del build.
- Borrar el token de push del teléfono al cerrar sesión (RN-09), para que un teléfono que cambia de
  flotilla no siga recibiendo avisos de la anterior.
- Un correo de bienvenida propio para conductores, que diga cuál es su código de flotilla y dónde
  descargar la app en Google Play.
- Qué pasa con los repartidores que ya tienen sesión iniciada con un APK anterior (RN-07).

**No incluye:**

- Buscar la flotilla a partir del correo (opción descartada, D2).
- Un código corto distinto del slug (D1).
- Que un mismo repartidor tenga sesión abierta en dos flotillas a la vez.
- Cambios en el Panel web, en la app de clientes ni en el microservicio GPS.
- La ficha de Google Play y el proceso de publicación (firma, capturas, políticas). Esta spec solo
  deja la app lista para ser una sola para todas las flotillas.

## 4. Decisiones

| # | Decisión | Por qué |
|---|---|---|
| D1 | El código de flotilla **es el slug** (`moto-celaya`). No se crea una columna nueva. | Ya existe, ya es único y ya es lo que usan las rutas. El repartidor lo escribe una sola vez. Si después resulta incómodo, se puede agregar un código corto sin cambiar el resto de esta spec. |
| D2 | La flotilla la dice el repartidor; el sistema no la adivina por el correo. | El correo solo es único dentro de cada flotilla. Un índice central correo → flotilla agrega una tabla, sincronización y el caso del mismo correo en dos flotillas. |
| D3 | Al cerrar sesión, el slug **se conserva** en el teléfono y el campo aparece ya lleno. | Lo normal es que el mismo repartidor vuelva a entrar a la misma flotilla. El campo sigue siendo editable. |
| D4 | Un teléfono con sesión de un APK anterior (token guardado, sin slug) **cierra la sesión** y pide iniciarla otra vez con el código. No se adivina la flotilla. | Ya hay repartidores de varias flotillas con APKs anteriores, y la app nueva no tiene forma de saber contra cuál se compiló el que tenían. Tomar una por defecto mandaría a repartidores de otras flotillas a la base equivocada. Volver a iniciar sesión una vez es el costo más bajo. |
| D6 | La app se distribuye por **Google Play** (`com.pandaconnect.delivery`). La liga de la ficha va en la configuración del backend, no fija en el código. | La ficha todavía no existe; cuando se publique, basta con llenar la variable. |
| D5 | El servidor de la API y el de Reverb siguen fijos en el build (`https://motopanda.mx/api/v1`). Solo el slug es dinámico. | Todas las flotillas viven en el mismo servidor. |
| D7 | El correo del conductor usa una **plantilla Blade propia** (`mail.credenciales-conductor`), no el tema por defecto de `MailMessage`. | El tema por defecto pone `APP_NAME` ("inDriver") en el encabezado y "Regards" al final, en inglés, y no deja poner logo ni colores sin publicar y modificar el tema de Laravel, lo que cambiaría también los correos de los demás roles. Ya hay un precedente igual: `mail.codigo-acceso` (spec 035). |
| D8 | La marca que firma la plataforma es **Moto Panda**; la app se sigue llamando **Panda Express**. En el correo, la flotilla es la protagonista (logo, despedida y remitente) y Moto Panda solo aparece en el pie. | El repartidor trabaja para su flotilla; Moto Panda es quien da el servicio. "Panda Express" es el nombre que va a buscar en Google Play. |
| D9 | El logo es el mismo que la flotilla sube para su app de clientes (`MarcaFlotilla::logoUrl()`, spec 035, RN-60). No se agrega otro campo de logo. | Ya existe, ya es cuadrado (512×512) y ya tiene una URL pública que cambia con cada versión. |
| D10 | `APP_NAME` y `MAIL_FROM_NAME` no se cambian en esta spec. | Afectan a todos los correos y a otras partes del sistema (sesiones, Vite, títulos). El remitente de este correo se pone por código (RN-14). |

## 5. Actores y permisos

| Actor | Qué hace |
|---|---|
| Conductor | Escribe el código de flotilla al iniciar sesión o al pedir el enlace de recuperación. |
| AdminCliente / Admin central | Dan de alta conductores. El correo que reciben los conductores ahora incluye el código. |

## 6. Modelo de datos

**Backend:** sin cambios de esquema.

**panda_express (`localStorage`):**

| Clave | Valor | Se borra |
|---|---|---|
| `token` | Token de Sanctum (igual que hoy) | Al cerrar sesión o con un 401 |
| `user` | Usuario (igual que hoy) | Al cerrar sesión o con un 401 |
| `tenant_slug` | **Nueva.** Slug de la flotilla | Nunca de forma automática (D3); se reemplaza al entrar con otro código |

## 7. Endpoints

No hay endpoints nuevos. La app sigue usando los que ya existen, con el slug guardado:

| Endpoint | Uso |
|---|---|
| `POST /t/{slug}/conductor/login` | Login. Si el slug no existe, el middleware `tenant.slug` responde **404**. |
| `POST /t/{slug}/forgot-password` | Recuperar contraseña (spec 029). |
| `POST /t/{slug}/conductor/logout` | **Cambia:** además de lo que hace hoy, borra el `ConductorDispositivo` del conductor (RN-09). |
| `/t/{slug}/conductor/*` | Todo lo demás, sin cambios. |

## 8. Reglas de negocio

### Login

- **RN-01.** La pantalla de login pide tres campos: código de flotilla, correo y contraseña. Si hay
  un `tenant_slug` guardado, el campo de código viene lleno.
- **RN-02.** Antes de enviar, la app normaliza el código: quita espacios al inicio y al final y lo
  pasa a minúsculas. Si no cumple `^[a-z0-9-]+$`, muestra "Revisa el código de flotilla" y no
  manda nada.
- **RN-03.** Si el login responde **404**, la app muestra "No encontramos esa flotilla. Revisa el
  código". Si responde 401 o 422, muestra el mensaje de credenciales incorrectas de hoy.
- **RN-04.** Solo con un login exitoso se guarda `tenant_slug`. Un código equivocado nunca
  reemplaza al que ya estaba guardado.

### Uso del slug

- **RN-05.** El `baseURL` de la API se arma en cada petición como
  `${VITE_API_BASE_URL}/t/${tenant_slug}`. `VITE_API_BASE_URL` termina en `/api/v1` y ya no trae
  el slug.
- **RN-06.** El canal de Reverb es `private-tenant.${tenant_slug}.conductores` y su autorización va
  a `${VITE_API_BASE_URL}/t/${tenant_slug}/conductor/broadcasting/auth`. Ambos se arman al
  conectar, no al cargar el módulo.
- **RN-07.** Si al arrancar hay `token` pero no hay `tenant_slug` (sesión de un APK anterior), la
  app borra `token` y `user` y manda al login, con el campo de código vacío (D4). No llama al
  logout del backend porque no sabe a qué flotilla mandarlo; el token viejo se queda sin uso en la
  base de su flotilla y el registro de push se reemplaza en el siguiente login (RN-10).

### Recuperar contraseña

- **RN-08.** La pantalla "¿Olvidaste tu contraseña?" también pide el código de flotilla, lleno si
  hay uno guardado, con la misma normalización de RN-02. Un 404 muestra el mismo mensaje genérico
  de siempre (spec 029, RN-2): esa pantalla no revela si existe la flotilla ni el correo.

### Push al cambiar de flotilla

- **RN-09.** Al cerrar sesión, el backend borra el registro de `conductor_dispositivos` del
  conductor. Sin esto, un teléfono que pasa de la flotilla A a la B sigue registrado en la base de
  A y recibe sus avisos de cancelación, de incidencia y de saldo (`EnviarPushSiEsCritico`). Al
  volver a entrar, la app registra el token otra vez, como ya lo hace hoy.
- **RN-10.** Cuando la app recibe un 401 (token vencido o borrado), limpia la sesión local como hoy.
  No llama al logout porque el token ya no sirve; el registro de push viejo se reemplaza en el
  siguiente login a esa flotilla.

### Correo de bienvenida del conductor

- **RN-11.** Cuando se da de alta un usuario con rol `Conductor` (desde el Panel o desde el Admin
  central), o se le reenvía el acceso desde la lista de conductores, el correo es uno propio para
  conductores (`CredencialesConductor`), armado con la plantilla `mail.credenciales-conductor`
  (D7). De arriba hacia abajo lleva:
  1. **Encabezado** morado (`#5b21b6`) con el **logo de la flotilla** centrado (RN-13). Debajo
     del logo, el nombre comercial de la flotilla en blanco.
  2. **Saludo:** "¡Bienvenido, {nombre del conductor}!", o "¡Bienvenido!" si no hay nombre.
  3. "Ya se creó tu acceso como repartidor de **{flotilla}**."
  4. **Descarga:** "Descarga la app **Panda Express** en Google Play" y un botón amarillo
     (`#facc15`, texto `#1e0b4b`) "Descargar en Google Play" hacia
     `services.panda_express.play_store_url` (variable `PANDA_EXPRESS_PLAY_STORE_URL`). Si la
     variable está vacía (la ficha todavía no se publica), el texto dice "Busca la app **Panda
     Express** en Google Play y descárgala" y no hay botón.
  5. **Datos de acceso**, en un recuadro lila (`#f5f3ff`) con borde izquierdo amarillo:
     - Código de flotilla: el slug, en letra monoespaciada y morada.
     - Correo: en texto plano, sin liga `mailto:` propia. Gmail y otros clientes pueden
       convertirlo en liga por su cuenta; no se evita con caracteres invisibles, porque se
       copiarían al pegar el correo en la app.
     - Contraseña: en letra monoespaciada.
  6. Una línea: "Te recomendamos cambiar tu contraseña después de entrar."
  7. **Despedida:** "Saludos," y en la línea siguiente "El equipo de **{flotilla}**".
  8. **Pie** en gris: "Enviado por Moto Panda · motopanda.mx". Sin ligas de baja (es un correo
     transaccional).
  - No lleva el botón "Ir al panel", que no le sirve a un conductor.
  - Todo el texto va en español; no queda ninguna frase del tema de Laravel ("Regards", "If
    you're having trouble clicking…").
- **RN-12.** Los demás roles siguen recibiendo `CredencialesUsuarioTenant` sin cambios.
- **RN-13. Logo.** Se usa `MarcaFlotilla::logoUrl()`, resuelto por quien manda el correo con la
  tenencia ya inicializada y pasado a la notificación (la notificación no consulta la base del
  tenant). Se pinta en un círculo blanco de 96 px con el logo dentro, para que un logo sin fondo o
  de color oscuro se vea sobre el morado. Si la flotilla no ha subido logo, en su lugar va un
  círculo amarillo con la inicial del nombre comercial en morado. El texto alternativo del logo es
  el nombre comercial, para los clientes de correo que bloquean imágenes.
- **RN-14. Remitente y asunto.** El remitente se muestra con el nombre de la flotilla
  ("Moto Celaya") y la dirección de `MAIL_FROM_ADDRESS`, igual que `CodigoAccesoMail`. El asunto
  sigue siendo "Tu acceso como repartidor de {flotilla}".
- **RN-15. Compatibilidad.** El HTML usa tablas y estilos en línea (sin `<style>` ni CSS externo),
  con ancho máximo de 480 px, como `mail.codigo-acceso`. Se manda también una versión de texto
  plano (`mail.credenciales-conductor-texto`) con los mismos datos y la liga de Google Play
  escrita completa. Los valores (nombre, slug, correo, contraseña) se escapan con `{{ }}`; la
  contraseña puede traer `<`, `&` o `)`.

## 9. Proceso

```
Repartidor abre la app
  ├─ ¿hay token?
  │    ├─ sí, sin tenant_slug → borra la sesión (RN-07) → Login con el código vacío
  │    └─ sí, con tenant_slug → entra
  └─ no → Login (código lleno si hay tenant_slug guardado)
            └─ POST /t/{código}/conductor/login
                 ├─ 404 → "No encontramos esa flotilla"
                 ├─ 401/422 → "Credenciales incorrectas"
                 └─ 200 → guarda token, user y tenant_slug → registra push → entra

Cerrar sesión → POST /t/{slug}/conductor/logout (desconecta y borra el push, RN-09)
             → borra token y user; conserva tenant_slug (D3)
```

## 10. Cambios en archivos existentes

**panda_express**

| Archivo | Cambio |
|---|---|
| `src/stores/auth.js` | `login(slug, email, password)`; guarda y lee `tenant_slug`; la migración de RN-07. `forgotPassword(slug, email)`. |
| `src/services/api.js` | `baseURL` armado por petición con el slug guardado (RN-05). |
| `src/services/realtime.js` | Nombre del canal y endpoint de autorización armados al conectar (RN-06). |
| `src/views/Login.vue` | Campo "Código de flotilla", normalización y mensaje de 404 (RN-01 a RN-04). |
| `src/views/ForgotPassword.vue` | Campo "Código de flotilla" (RN-08). |
| `.env.production`, `.env.local`, `README.md` | Quitar `VITE_TENANT_SLUG`. `VITE_API_BASE_URL` termina en `/api/v1`. |
| `src/stores/auth.js` (`logout`) | **Hallado al implementar:** la app nunca llamaba a `POST /conductor/logout`; solo borraba la sesión local. Ahora lo llama antes de borrarla (sin bloquear si falla), para que RN-09 ocurra y para que el conductor quede desconectado como pide la spec 019, RN-05. |
| `src/services/push.js` | **Hallado al implementar:** el token FCM solo se mandaba la primera vez que el sistema lo entregaba en esa ejecución de la app. Tras un logout (que ahora lo borra en el backend) y un nuevo login sin reiniciar la app, no se volvía a registrar. Ahora se guarda el último token y se reenvía en cada login. |

**Backend**

| Archivo | Cambio |
|---|---|
| `app/Http/Controllers/Tenant/Conductor/AuthController.php` | `logout` borra el `ConductorDispositivo` del conductor (RN-09). |
| `app/Notifications/CredencialesConductor.php` | **Nuevo.** Correo de bienvenida del conductor (RN-11). **1.2:** recibe además el nombre del conductor y la URL del logo (o `null`); deja las líneas de `MailMessage` y usa `->view([html, texto])` con el remitente de la flotilla (RN-13, RN-14). |
| `resources/views/mail/credenciales-conductor.blade.php`, `credenciales-conductor-texto.blade.php` | **Nuevos (1.2).** Plantilla HTML y de texto plano (RN-11, RN-15). |
| `config/services.php`, `.env.example` | `panda_express.play_store_url` desde `PANDA_EXPRESS_PLAY_STORE_URL` (D6). |
| `Tenant/UsuarioController@store`, `Tenant/ConductorController@reenviarCredenciales`, `Admin/ConductorTenantController@store` | Usan `CredencialesConductor` cuando el rol es `Conductor` (RN-11, RN-12). **1.2:** le pasan el nombre del conductor y `MarcaFlotilla::logoUrl()`, resuelto dentro de la tenencia (RN-13). |

## 11. Criterios de aceptación

1. Con el mismo APK, un repartidor de `moto-celaya` y uno de otra flotilla de prueba entran cada uno
   con su código, y cada uno ve solo los pedidos de su flotilla.
2. Un pedido publicado en la flotilla A no llega (ni por socket ni por push) al repartidor de la
   flotilla B.
3. Un código que no existe muestra "No encontramos esa flotilla" y no borra el código guardado.
4. Después de cerrar sesión, el login aparece con el código lleno.
5. Un teléfono que entra a la flotilla A, cierra sesión y entra a la flotilla B ya no recibe push de
   la flotilla A.
6. Un teléfono con sesión iniciada en un APK anterior, al instalar la versión nueva, cae en el login
   con el código vacío; después de escribir su código entra a su propia flotilla.
7. El correo de alta de un conductor trae el código de flotilla y el botón a Google Play, y no
   trae "Ir al panel". Sin `PANDA_EXPRESS_PLAY_STORE_URL`, trae el texto sin botón. El de un
   AdminCliente o Despachador queda igual.
9. El correo del conductor no dice "inDriver" ni "Regards" en ninguna parte; lleva el logo de la
   flotilla centrado sobre fondo morado, el botón de Google Play en amarillo y termina con
   "Saludos, El equipo de {flotilla}". El remitente aparece con el nombre de la flotilla.
10. Con una flotilla sin logo, el encabezado muestra la inicial de la flotilla en un círculo
    amarillo, sin imagen rota.
11. Se ve bien en Gmail (web y Android) y en Outlook, y con las imágenes bloqueadas se lee el
    nombre de la flotilla en lugar del logo.
8. "¿Olvidaste tu contraseña?" funciona sin sesión, escribiendo el código.

## 12. Pruebas automáticas

**Backend (Pest)**

- El logout del conductor borra su `ConductorDispositivo`.
- Alta de un usuario `Conductor` → se envía `CredencialesConductor` con el slug en el HTML, y con
  la liga de Google Play solo si está configurada. Alta de un `Despachador` →
  `CredencialesUsuarioTenant`.
- El reenvío de acceso desde la lista de conductores usa `CredencialesConductor`.
- El HTML de `CredencialesConductor` no contiene "inDriver" ni "Regards", y contiene "Saludos",
  el nombre de la flotilla y "Moto Panda".
- Con logo, el HTML trae su URL en un `<img>`; sin logo, trae la inicial y ningún `<img>`.
- Una contraseña con `<` y `&` sale escapada en el HTML y completa en el texto plano.
- El remitente lleva el nombre de la flotilla.
- `POST /t/no-existe/conductor/login` responde 404 (ya cubierto por el middleware; se agrega si no
  hay prueba).

**panda_express (Vitest)**

- Normalización del código (RN-02).
- `api.js` arma la URL con el slug guardado.
- RN-07: token sin slug → se borra la sesión y se manda al login.
- Un login con 404 no reemplaza `tenant_slug`.

## 13. Pendientes para la implementación

- **Liga de Google Play:** llenar `PANDA_EXPRESS_PLAY_STORE_URL` en producción cuando la ficha
  esté publicada (será `https://play.google.com/store/apps/details?id=com.pandaconnect.delivery`).
- **Firma de la app:** si los APKs que ya tienen los repartidores se instalaron a mano con otra
  llave de firma, Android no deja instalar encima la versión de Google Play; hay que desinstalar
  la vieja primero. En ese caso RN-07 no llega a aplicar (al desinstalar se pierde la sesión), pero
  hay que avisarles a las flotillas antes de publicar.
- **Logo en producción:** las flotillas que no han subido logo para su app de clientes verán la
  inicial (RN-13). Conviene pedirles que lo suban antes de usar "Reenviar acceso".
- **`APP_NAME=inDriver`:** sigue saliendo en el encabezado de los correos de los demás roles
  (`CredencialesUsuarioTenant`, `CredencialesAdminCliente`, recuperar contraseña). Queda fuera de
  esta spec (D10).
- **Nombre:** `capacitor.config` dice `appName: 'panda connect'`. Definir el nombre que se verá en
  Google Play y en el teléfono, y que coincida con el que dice el correo.
- **Orden del despliegue:** primero el backend (el cambio del logout y el correo no rompen los APKs
  actuales), después la app en Google Play.
- **Aviso a las flotillas:** cada dueño tiene que pasarles el código a sus repartidores actuales, o
  usar "Reenviar acceso" en la lista de conductores, que ya manda el código (RN-11).
