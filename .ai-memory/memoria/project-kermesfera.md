---
name: project-kermesfera
description: Objetivo, stack y estado de Kermesfera (plataforma multi-organización de kermeses)
metadata:
  type: project
---

Kermesfera (repo `kermesse`) es una plataforma centralizada multi-organización para gestionar ventas,
tickets, catálogo, gastos y balance de kermeses/eventos. Backend Symfony 7.4 (`backend/`), Angular
(`frontend/`), Flutter (`mobile/`), MariaDB 10.4.18 local (base `kermesfera`).

**Why:** el detalle vigente de decisiones, riesgos e hitos ya está en `.ai-memory/` (decisions,
known-issues, hitos); esta memoria solo apunta ahí para no duplicar. `event.close` sigue bloqueado
hasta definir quién autoriza el cierre.

**How to apply:** al retomar, leer primero `.ai-memory/current-task.md` y el hito activo en
`hitos.md`; no leer `docs/` completo. Relacionado: [[feedback-sin-claude-mem]].
Estado al 2026-11-03 (tras PR #4 y #5): ventas efectivo/QR, tickets, catálogo, gastos y tienda pública
implementados y conectados al frontend sin datos de ejemplo. Suite backend 88/88 en verde contra MariaDB;
frontend 65 tests. Ver [[project-frontend-auditoria]], [[project-frontend-estilo]] [[project-contrasenas-login]] y [[project-autorizacion-listados-get]].
