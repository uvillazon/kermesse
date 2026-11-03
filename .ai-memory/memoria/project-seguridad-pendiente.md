---
name: project-seguridad-pendiente
description: Huecos de seguridad del backend encontrados el 2026-11-03 — contraseñas (PR #6 abierto) y listados (resuelto en PR #7)
metadata:
  type: project
---

Encontrados el 2026-11-03 durante la auditoría del frontend; no se corrigieron porque quedaban fuera del alcance.
Se dejaron como tareas sugeridas en esa sesión.

- **Login sin contraseñas reales:** `LoginController` acepta `password123` o cualquier clave de 6+ caracteres para
  cualquier correo activo. La tabla `users` no tiene columna de contraseña. Hace falta migración `password_hash`,
  verificación con `password_verify` y definir cómo se asigna la clave (al aceptar invitación).
  Lo resuelve el PR #6 (`claude/festive-meitner-abe1vl`), abierto al 2026-11-03.
- **Listados sin autorización por organización:** resuelto el 2026-11-03 por el PR #7; ver
  [[project-autorizacion-listados-get]].

**Why:** ambos impiden usar la app fuera de desarrollo. **How to apply:** priorizarlos antes de cualquier despliegue;
la decisión de qué ve un colaborador con acceso solo a algunos eventos es de la organización
(ver [[project-matriz-roles-v03]]).
