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
- Seguridad: la autorización de listados ya entró con el PR #7; las contraseñas reales están en el PR #6 (abierto,
  con conflictos contra `master`). Ver memoria `project-seguridad-pendiente`.
- Control de la UI por rol. Siguiente paso: mapear menús y botones a los permisos de `RolePermissionMap`.
- "Canjear" visible en tickets anulados; login con cuentas demo; endpoint para abrir eventos en borrador.

## Descartado
- Subir el límite de 4 kB de CSS por componente: se movió la barra de cobro a `styles.scss` (`.mobile-action-bar`).
- Mostrar la etiqueta "API 8000 OK/Offline": dio un falso "Offline" y cada pantalla ya avisa sus errores.
- Reescribir tablas como componentes de tarjeta: bastó CSS con `data-label` en cada celda.
