---
name: project-frontend-auditoria
description: Auditoría del frontend Angular del 2026-11-03 — qué está conectado, dónde se cuelan datos simulados y qué falta
metadata:
  type: project
---

Auditoría del 2026-11-03 sobre `frontend/` contra las rutas reales de Symfony. Build OK, 1 solo test (`app.spec.ts`).

**Problema principal — datos simulados que se hacen pasar por reales:** `core/mock/mock-data.ts` sigue siendo el
valor inicial de varias señales y queda en pantalla si la API falla (solo `console.warn`) **o si devuelve una lista
vacía** (`length > 0`). Casos: gastos en Inicio y Reportes (un evento sin gastos muestra gastos falsos y el balance
sale mal), miembros e invitaciones en Accesos, asignaciones por curso en Tickets, eventos en `EventContextService`,
puestos/productos iniciales en Catálogo, nombre de organización/evento en tienda pública y topbar.

**Otros hallazgos:**
- `CURRENT_ORG_ID` está fijo en `event-context.service.ts`; no sale del login/JWT.
- "Cerrar evento" en Reportes solo cambia el estado en memoria (`closeActive`), sin llamar a la API: al recargar el
  evento vuelve a abierto. Coherente con que `event.close` está bloqueado ([[project-matriz-roles-v03]]).
- No hay control de UI por rol: cualquier usuario autenticado ve todos los menús y botones.
- `ApiService` no lo usa nadie; cada componente llama a `HttpClient` a mano.
- `environment.prod.ts` apunta a `http://127.0.0.1:8000`.
- Endpoints del backend sin pantalla: `ownership-transfers`, `invitations/accept`, borrado de acceso a nivel
  organización. Los de OpenAPI `exports`, `reports/balance`, `closure`, `payments/.../confirmation` tampoco existen
  en el backend (solo contrato).

**Why:** el usuario puede tomar decisiones con números falsos sin enterarse; es lo primero a corregir.
**How to apply:** al tocar una pantalla, quitar el mock como valor inicial, mostrar estado vacío/error real y
añadir al menos un test del componente.
