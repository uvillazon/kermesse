---
name: feedback-sin-claude-mem
description: El usuario decidió no usar más Claude-Mem ni servicios externos de memoria
metadata:
  type: feedback
---

No usar Claude-Mem ni ningún servicio externo para la memoria de este proyecto.

**Why:** el 2026-11-03 el worker de Claude-Mem gastaba la cuota del plan con cientos de resúmenes
diarios; el usuario pidió abandonarlo. El plugin ya fue desactivado en `~/.claude/settings.json`.

**How to apply:** toda la memoria queda en este repo: `.ai-memory/memoria/` (nivel 1) y
`docs/bitacoras/` (nivel 2). Ver [[project-kermesfera]].
