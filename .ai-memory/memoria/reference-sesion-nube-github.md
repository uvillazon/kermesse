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
- La nube no trae la base MariaDB `kermesfera` ni `.env.local`, pero se puede instalar una base de pruebas
  (ver abajo). Las credenciales nunca se versionan.

**Why:** perdimos varios intentos por tratar el 403 como error de red o de cuenta. **How to apply:** ante un 403,
no reintentar; revisar la instalación de la app y probar `create_branch`. Ver [[project-protocolo-memoria]].

**MariaDB en la nube (2026-11-03):** se puede instalar con `apt-get install -y mariadb-server` (sale 10.11, local
es 10.4.18), arrancar con `mysqld_safe --user=mysql &` (antes `mkdir -p /run/mysqld && chown mysql:mysql
/run/mysqld`), crear `kermesfera_test` y migrar con `DATABASE_URL=... php bin/console doctrine:migrations:migrate`.
Las pruebas de integración corren con `KERMESFERA_INTEGRATION_DATABASE_URL` y `DATABASE_SERVER_VERSION`.
`composer test` falla como root: usar `php vendor/bin/phpunit`. Al arrancar el kernel se regenera
`config/reference.php`: descartarlo con `git checkout`.
