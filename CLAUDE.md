# Instrucciones para Claude Code

Lee `AGENTS.md` y la carpeta `.ai-memory/` antes de comenzar una tarea.

Cuando una sesión produzca una decisión importante, un problema conocido o una convención reutilizable, actualiza `.ai-memory/` en lugar de guardar el transcript.

No incluyas secretos, tokens, credenciales, datos personales ni logs completos.


## Stack y comandos

- Backend: Symfony 7.4 / PHP en `backend/` — tests: `composer test` (phpunit); servidor local en `127.0.0.1:8000`.
- Frontend: Angular en `frontend/` — `npm start`, `npm run build`, `npm test`.
- Móvil: Flutter en `mobile/`. Base local: MariaDB 10.4.18, base `kermesfera` (migraciones versionadas).
- Nunca commitear directo en `master`: crear rama primero.

## Protocolo de memoria — LEER SIEMPRE

Toda la memoria vive DENTRO de este proyecto. No se usa Claude-Mem ni servicios externos.

- **Nivel 0 — este `CLAUDE.md`** (se carga solo): stack, comandos y reglas permanentes.
- **Nivel 1 — memoria local**: `D:\ProyectosPhp\kermesse\.ai-memory\memoria\`. `MEMORY.md` es el índice
  (una línea por archivo); un hecho por archivo con frontmatter `name`, `description`, `metadata.type`
  (`user|feedback|project|reference`). Guardar el **porqué**, enlazar con `[[nombre]]`.
- **Nivel 2 — bitácoras**: `docs/bitacoras/YYYY-MM-DD-<tema>.md`, una por sesión, versionadas. No se
  cargan; se consultan con Grep/Read cuando el nivel 1 no alcanza.
- Los archivos previos de `.ai-memory/` (architecture, decisions, hitos, known-issues, current-task,
  bitacora) siguen vigentes como memoria compartida del proyecto.

Reglas:
- Abrir el archivo del índice relevante antes de preguntar o suponer.
- Durante el trabajo: escribir en memoria en el momento en que se toma una decisión que costó pensar,
  no al cierre.
- No guardar: estructura de código, lo que dice el git log, credenciales, ni detalles que solo importan
  en esa conversación.
- Al cerrar: correr `/cierre`. Nunca volcar el chat entero. Para retomar: `/contexto <tema>`.
- Abrir Claude siempre desde `D:\ProyectosPhp\kermesse`.
- Las credenciales viven en un archivo no versionado (`.env.local`) y nunca se copian a memoria ni a bitácoras.
