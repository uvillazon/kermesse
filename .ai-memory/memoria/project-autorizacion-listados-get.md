---
name: project-autorizacion-listados-get
description: Qué permiso exige cada listado GET y por qué el de eventos filtra para colaboradores en vez de dar 403
metadata:
  type: project
---

El 2026-11-03 se cerró un hueco: varios `GET` solo validaban UUID y cualquier token válido leía datos de
cualquier organización. Decisiones del usuario (la matriz v0.3 no las resolvía):

- `GET /organizations/{id}/events`: propietario/admin (`org.read`) ven todos; un colaborador activo ve **solo**
  los eventos donde la política le da `event.read` (vigencia, roles e histórico incluidos); sin membresía → 403.
  **Why:** el selector de eventos del frontend (`event-context.service.ts`) usa este listado para todos los
  usuarios; un 403 a colaboradores dejaba sin eventos a operadores y cajeros.
- `.../events/{eventId}/members` e `.../invitations`: `org.manage_members` (exponen correos de toda la
  organización). `members` además responde 404 si el evento no es de la organización.
- `.../audit-logs`: `audit.read` del evento y **solo** registros con ese `event_id`; los de nivel organización
  (event_id NULL) quedan para un endpoint futuro con permiso organizacional. `audit.read` es `A` para
  `EVENT_MANAGER`: sin delegación explícita recibe 403 (Reportes usa `Promise.allSettled` para no vaciar el resto).
- `.../tickets`: `ticket.read`. `.../catalog/stands`: `catalog.read`.

**How to apply:** todo `GET` nuevo debe llamar a `AuthorizationPolicy::decide` antes de consultar; la prueba
`tests/Event/Infrastructure/Http/ListOrganizationEventsControllerIntegrationTest.php` cubre miembro → 200 y
ajeno → 403 para todos los listados. Ver [[project-matriz-roles-v03]].
