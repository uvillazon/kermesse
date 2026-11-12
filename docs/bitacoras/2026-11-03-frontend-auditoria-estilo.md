# 2026-11-03 — Auditoría del frontend, organización desde el login, estilo y celular

## Qué se hizo
- Auditoría de `frontend/` contra las rutas de Symfony. Se eliminaron los datos de ejemplo (`core/mock/mock-data.ts`):
  vacío o aviso `app-load-error-banner` en su lugar. Recarga al cambiar de evento (`onActiveEventChange` + `isStale`).
- Organización activa desde `POST /auth/login` (`user.organizations`) en vez de `CURRENT_ORG_ID` fijo.
- Estilo: textos sin jerga, `LOCALE_ID es-BO`, pipe `fecha` (UTC), encabezado compacto, historial en frases
  (`audit-description.ts` + `subject_name` en el backend), celular (barra superior, "Cobrar", 12 tablas como tarjetas).
- Capturas reales de todas las pantallas publicadas en un artifact, con base `kermesfera_demo` cargada por la API.
- PR #4 fusionado (merge commit `e8b64cf`). El PR #5 (otra sesión) reparó los 9 tests rotos del backend; hubo que
  resolver un conflicto solo en memoria. Suite backend 88/88 y frontend 65 tests.

## Qué se decidió y por qué
- Nunca mostrar datos inventados como fallback: el usuario podía decidir con números falsos.
- Fechas de la API leídas como UTC: el navegador las corría 4 h en Bolivia. El test fija `TZ=America/La_Paz`.
- "Cerrar evento" desactivado: solo cambiaba la pantalla y `event.close` sigue sin responsable.
- PrimeNG 22 ignora `pTemplate` y `styleClass`: usar `#body` y `class` (las tablas salían vacías).
- Descripciones de roles según `RolePermissionMap`, no según la matriz v0.3 (no aprobada).

## Pendiente
- (Resuelto después en el mismo día, ver la sección de abajo: contraseñas reales PR #6, autorización de listados
  PR #7, abrir eventos y "Canjear" PR #10.)
- Control de la UI por rol. Siguiente paso: mapear menús y botones a los permisos de `RolePermissionMap`.

## Descartado
- Subir el límite de 4 kB de CSS por componente: se movió la barra de cobro a `styles.scss` (`.mobile-action-bar`).
- Mostrar la etiqueta "API 8000 OK/Offline": dio un falso "Offline" y cada pantalla ya avisa sus errores.
- Reescribir tablas como componentes de tarjeta: bastó CSS con `data-label` en cada celda.

## Continuación: PR #4 → #10 y paso a trabajo local
- Se fusionaron todos los PR del día: #4 (frontend), #5 (tests), #6 (contraseñas), #7 (autorización de listados),
  #8 y #9 (cierres de sesión). El #6 chocó en 6 archivos con lo anterior; se resolvió a mano (login y su test
  partido en dos: contraseñas y organizaciones). Backend 133 tests y frontend 65 antes del #10.
- PR #10: `POST .../events/{id}/opening` (borrador → abierto, idempotente, auditado), botón "Abrir evento" con
  confirmación, aviso de borrador en el layout y "Canjear" solo en tickets canjeables. Probado de punta a punta en
  la base de demostración (en borrador la venta da 422; tras abrir, se confirma). Backend 139, frontend 74.
- Decisión sin respuesta del usuario: abre propietario o administrador (`org.manage_members`). Provisional hasta
  decidir quién gestiona la habilitación comercial (`RN-COM-001`). Ver memoria `project-abrir-evento`.
- Fallo propio corregido: refrescar eventos volvía a "cargando", el layout desmontaba la pantalla y se perdían los
  mensajes de éxito. Ahora solo la primera carga o el cambio de organización muestran "cargando".
- Borrar las ramas viejas desde la nube dio 403 (no reintentar). Quedan 5 en GitHub sin nada propio.
- El usuario sigue en local: se acabó el crédito de la nube.

## Pendiente (vigente al 2026-11-12)
- **Siguiente paso:** pantalla de Angular para aceptar invitaciones con contraseña (`POST /invitations/accept`).
- Borrar a mano las ramas `claude/*` y `docs/protocolo-memoria*` en GitHub → Branches; ya no tienen nada propio.
- Control de la UI por rol, habilitación comercial y quién autoriza `event.close` (decisiones de la organización).
- Mensaje en inglés "Sales require an open event." y enlace fijo a `/tienda/halloween-2026` en el login.
