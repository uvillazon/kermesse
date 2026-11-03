# 2026-11-03 — Protocolo de memoria de dos niveles

## Qué se hizo
- Creados `.claude/commands/cierre.md` y `contexto.md` (basados en la plantilla de otro proyecto, sin
  ningún servicio externo).
- Nivel 1 en `.ai-memory/memoria/` (`MEMORY.md` + 3 memorias); nivel 2 en `docs/bitacoras/`.
- `CLAUDE.md`: añadidos stack/comandos y la sección "Protocolo de memoria — LEER SIEMPRE".
- `AGENTS.md`: reemplazada la mención de Claude-Mem por el puntero a la nueva memoria.

## Qué se decidió y por qué
- La memoria vive dentro del repo (pedido del usuario): versionada y compartible entre agentes.
- Se conserva `.ai-memory/` previo como memoria compartida; el nivel 1 solo añade el índice por hechos.
- Claude-Mem queda descartado (consumía la cuota del plan); ya estaba desactivado.

## Pendiente
- Probar el flujo en una sesión nueva: decisión de prueba → `/cierre` → `/contexto <tema>` en otra sesión.
- Siguiente paso de producto sin cambios: ver `.ai-memory/current-task.md` (matriz de roles v0.2).

## Descartado
- Usar `~/.claude/projects/.../memory/`: fuera del repo, no versionada.
- `/cierre` no estaba registrado en la sesión que lo creó (se carga al arrancar); se siguió a mano.
