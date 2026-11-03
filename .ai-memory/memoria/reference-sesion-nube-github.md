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
- Los PR #1–#3 y #5 se fusionaron con squash; el #4 con merge commit (lo eligió el usuario en GitHub).
- La nube no trae MariaDB ni `.env.local`, pero se puede montar una base desechable para la suite de
  integración (verificado el 2026-11-03, se instala MariaDB 10.11):
  1. `apt-get install -y mariadb-server` (si falla, `apt-get update` antes) y `composer install` en `backend/`.
  2. `mkdir -p /run/mysqld && chown mysql:mysql /run/mysqld`, luego `mysqld_safe --user=mysql &` y esperar con
     `mysqladmin ping`; crear base `kermesfera_test` y un usuario
     local desechable con `mysql -e "..."`.
  3. Migrar: `APP_ENV=test DATABASE_URL=<url> php bin/console doctrine:migrations:migrate -n`.
  4. `KERMESFERA_INTEGRATION_DATABASE_URL=<url> DATABASE_SERVER_VERSION=10.11.14-MariaDB php vendor/bin/phpunit`
     (no `composer test`: falla como root). Para levantar la app (capturas), `COMPOSER_ALLOW_SUPERUSER=1
     composer install` genera `vendor/autoload_runtime.php`, y un `backend/.env` local (ignorado por git).
  5. Arrancar el kernel regenera `backend/config/reference.php`: `git checkout` antes de commitear.
  Las credenciales reales nunca se versionan; las de la base desechable no salen del contenedor.
  Si de golpe todas las pruebas de integración dan `Connection refused`, MariaDB se detuvo (pasó el
  2026-11-03): repetir el paso 2 antes de buscar el error en el código.

**Why:** perdimos varios intentos por tratar el 403 como error de red o de cuenta. **How to apply:** ante un 403,
no reintentar; revisar la instalación de la app y probar `create_branch`. Ver [[project-protocolo-memoria]].
