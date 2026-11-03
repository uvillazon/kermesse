# 2026-11-03 — Autorización de listados GET

## Problema
`GET /organizations/{id}/events` y otros cinco listados solo validaban UUID: cualquier token válido leía
eventos, tickets, miembros (con correos), invitaciones, auditoría y puestos de cualquier organización.

## Decisiones (del usuario; la matriz v0.3 no las resolvía)
- Eventos: propietario/admin ven todos; colaborador ve solo eventos con `event.read` vigente; sin membresía 403.
- Miembros e invitaciones: `org.manage_members`. Miembros da 404 si el evento no es de la organización.
- Auditoría: `audit.read` del evento y solo registros de ese evento (fuera los de nivel organización).
- Tickets: `ticket.read`. Puestos: `catalog.read`.

## Cambios
- Controladores en `backend/src/{Event,Ticket,Identity,Audit,Sale}/Infrastructure/Http/`.
- Frontend Reportes: `Promise.allSettled` para que un 403 de auditoría no vacíe productos y gastos.
- Prueba nueva `backend/tests/Event/Infrastructure/Http/ListOrganizationEventsControllerIntegrationTest.php`
  (19 casos; 12 fallan sin el arreglo).

## Verificación
MariaDB 10.11 levantada en la nube con base desechable: 15 migraciones OK, prueba nueva 19/19.
Tras fusionar `master` (PR #5 arregló la suite): 107 pruebas en verde. La prueba de auditoría de `master`
usaba un actor sin membresía; ahora el fixture le da membresía, `EVENT_MANAGER` y `audit.read` delegado.
Conflictos resueltos: Reportes combina `allSettled` con `isStale`/`loadError`; la prueba de eventos absorbe el
caso de fecha ISO de `master`.

## Pendiente
- Endpoint de auditoría organizacional (registros con `event_id` NULL) con permiso de organización.
- Documentar los GET en `docs/openapi.yaml` (hoy no figuran).
- `ApiErrorSubscriber` devuelve `request_error` en 403 de `AccessDeniedHttpException` (no `forbidden`).
