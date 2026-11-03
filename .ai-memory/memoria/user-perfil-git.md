---
name: user-perfil-git
description: El usuario no está familiarizado con Git/GitHub (PR, merge); explicar en lenguaje simple y verificar el resultado
metadata:
  type: user
---

El 2026-11-03 el usuario preguntó "¿qué es PR?" y luego dijo haber fusionado el PR #4 cuando en realidad había
fusionado el #5 y, después, el #4 seguía abierto (faltaba confirmar el merge).

**Why:** cree completar pasos de GitHub que no se completaron; si se le cree sin verificar, el trabajo queda fuera
de `master`. **How to apply:** explicar los términos (rama, PR, merge) en una o dos frases cuando aparezcan, dar los
pasos exactos de GitHub (incluido el segundo clic "Confirm merge") y comprobar siempre en GitHub o con
`git fetch` antes de dar algo por fusionado. Escribe en español; prefiere respuestas directas.
