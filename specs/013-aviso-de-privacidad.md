# SPEC-013 — Aviso de privacidad

**Metadatos**

| Campo | Valor |
|---|---|
| ID | SPEC-013 |
| Módulo | Sitio público |
| Versión | 1.0 |
| Estado | Aprobada el 2026-11-08 |
| Rama | `pedidos-whatsapp` |
| Depende de | SPEC-031 (pedidos por WhatsApp) |

---

## 1. Objetivo

Publicar un aviso de privacidad en `https://prosello.com.mx/privacidad`. Meta lo exige para publicar la app **Panda Connect**: sin publicarla, el webhook solo recibe eventos de prueba y los mensajes reales de WhatsApp nunca llegan (SPEC-031, etapa 4).

El aviso también cumple con la Ley Federal de Protección de Datos Personales en Posesión de los Particulares, publicada en el DOF el 20 de marzo de 2025.

## 2. Alcance

**Incluye:**

- Una página pública en `/privacidad`, sin inicio de sesión.
- El texto del aviso integral en español.
- Un enlace al aviso desde el login del Panel.

**No incluye:**

- Términos y condiciones.
- Banner de cookies.
- Un aviso distinto para cada flotilla.
- Un formulario para ejercer derechos ARCO. Las solicitudes llegan por correo.

## 3. Asunciones

Aprobadas todas por el usuario el 2026-11-08.

| # | Asunción |
|---|---|
| A1 | Hay un solo aviso para toda la plataforma, no uno por flotilla |
| A2 | El responsable de los datos es el operador de la plataforma. Cada flotilla usa los datos solo para dar su servicio |
| A3 | El aviso cubre a los cuatro tipos de personas: clientes, destinatarios, repartidores y personal de las flotillas |
| A4 | Las solicitudes de acceso, corrección, cancelación u oposición (ARCO) se envían por correo y se responden en máximo 20 días hábiles |
| A5 | No hay finalidades secundarias: no se usan los datos para publicidad ni se venden |
| A6 | El texto de los mensajes de WhatsApp se borra a los 90 días (decisión de SPEC-031) |
| A7 | El aviso nombra a los proveedores que reciben datos: Meta, Anthropic, Google, DigitalOcean y Resend |
| A8 | La página dice la fecha de la última actualización, y los cambios se avisan publicando la versión nueva en la misma dirección |
| A9 | Solo en español |
| A10 | La ubicación del repartidor se recaba solo mientras está en turno, y se informa como dato que permite localizarlo |
| A11 | No se recaban datos sensibles ni de menores de edad |

## 4. Datos del responsable

| # | Dato | Por qué |
|---|---|---|
| F1 | Nombre del responsable | **Resuelto:** Abdias Reyes Reyna, persona física |
| F2 | Domicilio del responsable | **Resuelto:** Real del Seminario 122, Col. Valle del Real, C.P. 38024, Celaya, Guanajuato |
| F3 | Correo para solicitudes de privacidad | **Resuelto:** abdiasreyesreyna@gmail.com. No es de `prosello.com.mx` porque ese dominio no recibe correo |

## 5. Contenido del aviso

Cada apartado de la página:

1. **Responsable.** Abdias Reyes Reyna, con domicilio en Real del Seminario 122, Col. Valle del Real, C.P. 38024, Celaya, Guanajuato, México.
2. **Datos que se recaban.**
   - Clientes y destinatarios: nombre, teléfono, correo (opcional), direcciones y sus coordenadas.
   - WhatsApp: número, identificador de usuario de WhatsApp, nombre de perfil, texto de los mensajes y ubicaciones compartidas.
   - Repartidores: nombre, teléfono, vehículo, ubicación durante el turno e identificador del teléfono para avisos.
   - Personal de las flotillas: nombre, correo y contraseña cifrada.
   - Pedidos: importes del envío, del producto y del regreso.
3. **Finalidades.** Recibir y confirmar pedidos, calcular tarifas, asignar repartidores, seguir la entrega, avisar el estado, cobrar comisiones, dar soporte y cumplir obligaciones legales.
4. **Proveedores con acceso a datos**, uno por línea y con el motivo:
   - Meta: mensajes de WhatsApp.
   - Anthropic: interpretar los mensajes del bot.
   - Google: mapas, rutas y avisos al teléfono del repartidor.
   - DigitalOcean: servidores.
   - Resend: correos.

   Algunos guardan datos fuera de México.
5. **Conservación.** Los mensajes de WhatsApp se borran a los 90 días. El resto, mientras la cuenta esté activa y por el tiempo que pidan las leyes.
6. **Derechos ARCO y revocación del consentimiento.** Se piden por correo a abdiasreyesreyna@gmail.com. La solicitud incluye nombre, teléfono o correo registrado, el derecho que se ejerce y los datos a los que se refiere. Respuesta en máximo 20 días hábiles (A4).
7. **Cómo dejar de recibir mensajes.** Escribir "BAJA" al número de WhatsApp de la flotilla o enviar un correo a abdiasreyesreyna@gmail.com.
8. **Cookies.** El Panel solo guarda la sesión en el navegador; no hay rastreo publicitario.
9. **Cambios al aviso** y fecha de la última actualización (A8).
10. **Autoridad.** La Secretaría Anticorrupción y Buen Gobierno, en lugar del antiguo INAI.

## 6. Reglas

- **RN-1.** La página carga sin inicio de sesión y sin depender de JavaScript, para que el revisor automático de Meta la pueda leer.
- **RN-2.** `/privacidad` nunca se interpreta como el nombre de una flotilla.
- **RN-3.** No se puede crear una flotilla con el slug `privacidad`.
- **RN-4.** El texto lo revisa un abogado antes de atender clientes reales. Para publicar la app de Meta basta este borrador completo.

## 7. Adiciones técnicas

1. **Página fija en lugar de pantalla del Panel.** *Aprobada el 2026-11-08, con el mismo estilo visual que el Panel.* El Panel es una aplicación que se arma en el navegador; el revisor de Meta podría ver una página vacía. Una página HTML sencilla en `frontend/public/privacidad/index.html` se ve igual para Meta y para las personas, y el servidor ya la entrega antes que el Panel.
2. **Lista de slugs reservados.** *Aprobada el 2026-11-08.* Hoy cualquier dirección `/<algo>` se toma como una flotilla. Al crear o editar flotillas se rechazan las palabras que el sistema ya usa como dirección: `privacidad`, `admin`, `api`, `sanctum`, `up`, `gps`, `gps-health`, `app`, `apps`, `t` y `about`, sin importar mayúsculas. La lista queda en un solo lugar para agregar `terminos` más adelante. Se revisa antes de programar que ninguna flotilla existente use una de esas palabras.
3. **Sin página de borrado de datos.** *Aprobada el 2026-11-08.* El usuario quita el caso de uso "Inicio de sesión con Facebook" de la app de Meta, porque Panda Connect no lo usa. Si Meta pidiera la dirección de todos modos, se usa `/privacidad`, cuyo apartado 6 explica cómo pedir el borrado. Esto se hace en el panel de Meta; no hay que programar nada.

## 8. Cómo se prueba

1. Abrir `https://prosello.com.mx/privacidad` en una ventana privada: se ve el aviso completo.
2. Ver el código fuente de la página: el texto está ahí, sin depender de JavaScript.
3. Intentar crear una flotilla con slug `privacidad`: se rechaza.
4. Pegar la URL en **Configuración de la app > Básica > URL de la política de privacidad** en Meta y publicar la app.
