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
Estado al 2026-11-03 (master en el PR #10): ventas efectivo/QR, tickets, catálogo, gastos y tienda pública
implementados y conectados al frontend sin datos de ejemplo; eventos se abren desde la app; login con contraseña
real; listados GET con autorización. Suite backend 139/139 contra MariaDB; frontend 74 tests. Ver
[[project-frontend-auditoria]], [[project-frontend-estilo]], [[project-contrasenas-login]],
[[project-autorizacion-listados-get]] y [[project-abrir-evento]].

**Retomar en local** (el usuario pasó a trabajar en `D:\ProyectosPhp\kermesse` el 2026-11-03 porque se acabó el
crédito de la nube): `git pull` en `master`; en `backend/` `composer install`, `php bin/console
doctrine:migrations:migrate` y `php bin/console app:users:set-password <correo>` por cada usuario (sin contraseña
guardada nadie entra desde el PR #6); `SALE_RESERVATION_TTL_SECONDS=900` en `.env.local` (está en `.env.example`);
en `frontend/` `npm install` y `npm start`.

**Siguiente tarea acordada:** pantalla de Angular para aceptar invitaciones con contraseña. Sin ella nadie nuevo
puede sumarse al equipo desde la app (el backend ya la exige, ver [[project-contrasenas-login]]).
