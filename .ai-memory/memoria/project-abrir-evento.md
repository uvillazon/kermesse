---
name: project-abrir-evento
description: Cómo se abre un evento (borrador → abierto), quién puede hacerlo y por qué es provisional
metadata:
  type: project
---

Implementado el 2026-11-03: `POST /organizations/{org}/events/{event}/opening` (sin cuerpo, con `Idempotency-Key`).
Solo pasa de `draft` a `open`; si ya está abierto o está cerrado responde 409 (un evento cerrado no se reabre).
Audita `event.opened`. En Angular: botón "Abrir evento" con confirmación en Eventos y aviso en el layout
mientras el evento activo está en borrador (en borrador se prepara el catálogo, pero las ventas dan 422).

**Quién:** propietario o administrador de la organización (`org.manage_members`, el mismo permiso que crear
eventos). Lo eligió Claude sin respuesta explícita del usuario, como opción conservadora.
**Why:** la matriz (`RN-COM-001`) exige habilitación comercial para vender, y quién la gestiona es una decisión
abierta de la organización ([[project-matriz-roles-v03]]); mientras tanto, abrir queda en manos de quien ya
controla la organización. **How to apply:** si se decide que el responsable del evento también abre, cambiar
el permiso a `event.manage` con el `eventId` en `OpenOrganizationEventController` y `canOpenEvents()` en
`events.component.ts`. La habilitación comercial sigue sin implementarse: hoy un evento abierto vende sin ella.
