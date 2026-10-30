# Instrucciones para agentes

Antes de modificar el proyecto:

1. Lee `.ai-memory/README.md`.
2. Lee `.ai-memory/architecture.md`, `.ai-memory/decisions.md` y `.ai-memory/known-issues.md`.
3. Conserva los cambios existentes y evita introducir secretos.

## Ahorro de tokens (Claude, Codex y Antigravity)

Para retomar el trabajo lee solo, en este orden: `.ai-memory/bitacora.md`,
`.ai-memory/current-task.md` y la sección del hito activo en `.ai-memory/hitos.md`.
No leas `docs/`, `HANDOFF.md` ni `prototype/` completos: busca la regla `RN-*` o la sección
concreta que necesites. Al terminar una sesión añade una línea al final de `bitacora.md`.

Después de un cambio significativo:

- Actualiza el archivo de memoria correspondiente.
- Describe decisiones y riesgos de forma breve.
- Mantén la memoria en Markdown legible y versionable.

La base automática de Claude-Mem es local y no debe copiarse al repositorio.

