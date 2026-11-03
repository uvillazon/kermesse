---
name: reference-sesion-nube-github
description: Gotchas de las sesiones en la nube (permisos de GitHub, push 403, cómo levantar MariaDB y correr integración)
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
- La nube no trae MariaDB, `vendor/` ni `.env.local`, pero **sí se pueden instalar** (probado 2026-11-03):
  `composer install` (tarda, clona desde git), `apt-get install -y mariadb-server`, `service mariadb start`,
  crear una base y usuario desechables, exportar en la shell las variables de `phpunit.xml.dist` con ese
  `DATABASE_URL`, correr `php bin/console doctrine:migrations:migrate -n` y luego
  `KERMESFERA_INTEGRATION_DATABASE_URL=... vendor/bin/phpunit`. La versión instalada es 10.11 (no 10.4.18).
  Correr la suite regenera `backend/config/reference.php`: revertirlo antes de commitear.
  Las credenciales nunca se versionan.
- Al 2026-11-03 fallan 9 pruebas preexistentes con BD (TransactionalExecutorTest sin driver, invitaciones con
  `expires_at` fijo ya vencido, CreateSale con 500); no las causó el cambio de autorización de listados.

**Why:** perdimos varios intentos por tratar el 403 como error de red o de cuenta. **How to apply:** ante un 403,
no reintentar; revisar la instalación de la app y probar `create_branch`. Ver [[project-protocolo-memoria]].
