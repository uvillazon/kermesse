---
name: reference-sesion-nube-github
description: Gotchas de las sesiones en la nube (permisos de GitHub, push 403, sin base de datos local)
metadata:
  type: reference
---

Aprendido el 2026-11-03 trabajando desde Claude Code en la nube (`/home/user/kermesse`).

- **`git push` da 403 aunque el fetch funcione** si la app de Claude de GitHub no está instalada en el repo con
  permiso de escritura. Reconectar la cuenta en claude.ai no basta: hay que instalar la app en
  `uvillazon/kermesse`. Aun instalada, el push solo pasó después de **crear la rama remota con la herramienta
  MCP `create_branch`**; luego `git push` normal funcionó.
- Tras fusionar un PR, la rama de trabajo se reinicia desde `master` con el mismo nombre y se sube con
  `--force-with-lease` (solo tenía historia ya fusionada).
- Los PR se fusionan con squash salvo que se pida otro método.
- La nube no tiene la base MariaDB `kermesfera` ni `.env.local`: no se pueden correr migraciones ni pruebas de
  integración allí. Las credenciales nunca se versionan.

**Why:** perdimos varios intentos por tratar el 403 como error de red o de cuenta. **How to apply:** ante un 403,
no reintentar; revisar la instalación de la app y probar `create_branch`. Ver [[project-protocolo-memoria]].
