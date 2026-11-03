---
name: project-matriz-roles-v03
description: Estado de la matriz de roles v0.3 (fusionada el 2026-11-03) y qué decisiones de la organización la bloquean
metadata:
  type: project
---

Matriz v0.3 en `docs/HITO_0_MATRIZ_ROLES_PERMISOS.md`, revalidada el 2026-11-03 contra las reglas del 29-oct
(detalle en `docs/HITO_0_VALIDACION_ROLES_TRAZABILIDAD.md`, hallazgos 9-12). Fusionada en `master` por el PR #2.
Solo documentación: el código no cambió.

**Why:** la v0.2 se escribió antes de validar y las reglas comerciales del 29-oct (habilitación por evento,
usuarios sin cobro, historial permanente) abrieron huecos que ningún permiso cubre.

**Pendiente (decide la organización, no el código):** quién autoriza `event.close`; quién gestiona la habilitación
comercial; si `FINANCE_REVIEWER` y `PAYMENT_REVIEWER` se separan; vigencia del acceso histórico.

**Ojo:** la matriz marca `payment.review` como `A` pero `RolePermissionMap` lo concede de base. Mientras no se
apruebe, manda el código. `ticket.resend` está solo en el documento. Ver [[project-kermesfera]].
