---
name: project-protocolo-memoria
description: Dónde vive la memoria de dos niveles y por qué dentro del repo y no en ~/.claude
metadata:
  type: project
---

Instalado el 2026-11-03: nivel 1 en `.ai-memory/memoria/` (índice `MEMORY.md`), nivel 2 en
`docs/bitacoras/`, comandos `/cierre` y `/contexto` en `.claude/commands/`.

**Why:** el usuario pidió que la memoria esté dentro del proyecto (versionada, compartida entre
equipos y agentes). La carpeta `~/.claude/projects/D--ProyectosPhp-kermesse/memory/` quedó vacía y sin
uso. `.ai-memory/` ya existía con architecture/decisions/hitos/known-issues y sigue vigente.

**How to apply:** los comandos nuevos solo se cargan al iniciar sesión; si `/cierre` no existe,
reiniciar la sesión o seguir `.claude/commands/cierre.md` a mano. Ver [[feedback-sin-claude-mem]].
