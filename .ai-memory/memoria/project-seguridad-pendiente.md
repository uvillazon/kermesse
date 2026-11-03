---
name: project-seguridad-pendiente
description: Huecos de seguridad del backend encontrados el 2026-11-03 y aún sin corregir (contraseñas y autorización de listados)
metadata:
  type: project
---

Encontrados el 2026-11-03 durante la auditoría del frontend; no se corrigieron porque quedaban fuera del alcance.
Se dejaron como tareas sugeridas en esa sesión.

- **Login sin contraseñas reales:** `LoginController` acepta `password123` o cualquier clave de 6+ caracteres para
  cualquier correo activo. La tabla `users` no tiene columna de contraseña. Hace falta migración `password_hash`,
  verificación con `password_verify` y definir cómo se asigna la clave (al aceptar invitación).
- **Listados sin autorización por organización:** `ListOrganizationEventsController` y `ListAuditLogsController`
  no llaman a la política de autorización; cualquier usuario autenticado lee eventos e historial de otra
  organización conociendo su id. Revisar el resto de los `GET` (invitaciones, miembros, catálogo, ventas, gastos).

**Why:** ambos impiden usar la app fuera de desarrollo. **How to apply:** priorizarlos antes de cualquier despliegue;
la decisión de qué ve un colaborador con acceso solo a algunos eventos es de la organización
(ver [[project-matriz-roles-v03]]).
