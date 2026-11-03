---
name: project-frontend-auditoria
description: Auditoría del frontend Angular del 2026-11-03 — datos de ejemplo ya eliminados; qué queda pendiente
metadata:
  type: project
---

Auditoría del 2026-11-03 sobre `frontend/` contra las rutas reales de Symfony.

**Corregido el mismo día — datos de ejemplo:** se borró `core/mock/mock-data.ts`. Los tipos y etiquetas viven en
`core/models/domain.ts`. Las pantallas muestran lista vacía o un aviso `app-load-error-banner` con "Reintentar";
`EventContextService` expone `state` (`loading|ready|error`), `active()` puede ser `null` y el layout no
renderiza pantallas sin evento (salvo `/events`, para crear el primero). El botón "Cerrar evento" quedó
desactivado con explicación: antes solo cambiaba el estado en memoria.

**Regla (decidida con el usuario):** nunca mostrar datos inventados como fallback. Ante error, aviso visible;
ante lista vacía, vacío. Test de regresión en `features/screens-without-mock-data.spec.ts`.

**Corregido — recarga al cambiar de evento:** las 6 pantallas usan `onActiveEventChange()` (en
`event-context.service.ts`): reacciona solo al cambio de *id* (`activeEventId`), vacía los datos del evento
anterior y recarga. Cada carga descarta respuestas tardías con `ctx.isStale(eventId)`; sin eso, una respuesta
lenta del evento anterior pisaba la del nuevo. Ventas descarta el carrito al cambiar. Test:
`features/screens-event-switch.spec.ts` (el evento viejo responde *después* del nuevo; es el caso que fallaba).
Convención: toda pantalla nueva con datos por evento debe cargar con `onActiveEventChange` + `isStale`.

**Corregido — organización desde el login:** `POST /auth/login` devuelve `user.organizations`
(`id, name, slug, access`): membresías `active`, organización `active`, vigencia como
`OrganizationMembership::isActiveAt()`, ordenadas por nombre. `orgAccess` se mantiene (acceso en la primera).
En Angular, `AuthService.activeOrganization()` = la elegida (guardada en `localStorage`, solo como preferencia)
si sigue en la lista, si no la primera. `EventContextService.orgId` sale de ahí y recarga eventos al cambiar de
organización (descarta respuestas tardías). Sin organización: aviso en el layout, sin pantallas. Selector en la
barra superior solo si hay más de una. Las sesiones guardadas sin `organizations` obligan a volver a entrar.

**Pendiente:**
- Sin control de UI por rol: cualquier usuario autenticado ve todos los menús y botones.
- Backend: `ListOrganizationEventsController` devuelve `'28 oct 2026'` si el evento no tiene `starts_at`.
- `ApiService` sin uso; `environment.prod.ts` apunta a `127.0.0.1:8000`; el login enlaza a `/tienda/halloween-2026`.
- Endpoints sin pantalla: `ownership-transfers`, `invitations/accept`. En OpenAPI pero no en el backend:
  `exports`, `reports/balance`, `closure`, `payments/.../confirmation`.

**Ojo en tests:** las tablas `p-table` de PrimeNG no pintan filas en jsdom (ni la cabecera). Revisar solo el DOM
deja pasar datos falsos; el spec revisa también el valor de todas las señales del componente. Se comprobó
reinsertando un gasto falso: el test falló como debía.

**Why:** el usuario podía decidir con números falsos sin enterarse (un evento sin gastos mostraba gastos inventados).
Ver [[project-matriz-roles-v03]] para el bloqueo de `event.close`.
