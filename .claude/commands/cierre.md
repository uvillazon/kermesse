---
description: Cierra la sesión KERMESEFERA — vuelca decisiones a memoria y escribe la bitácora
---

Cerrá la sesión de trabajo KERMESEFERA. Ejecutá los pasos en orden y no te saltes ninguno.

Memoria local (nivel 1) = `D:\ProyectosPhp\kermesse\.ai-memory\memoria\`
Bitácoras (nivel 2) = `D:\ProyectosPhp\kermesse\docs\bitacoras\`

## 1. Repasar la sesión

Releé esta conversación y separá dos cosas:

- **Decisiones duraderas** — algo que la próxima sesión necesita y no puede deducir del código ni del
  git log: por qué se eligió X sobre Y, un gotcha del entorno, una regla del usuario, el estado de un
  bloque de trabajo.
- **Ruido** — búsquedas, intentos fallidos, código que ya está en el repo. Esto **no** se guarda.

Si no hubo decisiones duraderas, decilo y saltá al paso 3.

## 2. Escribir la memoria local

Por cada decisión duradera:

- Si ya existe una memoria que la cubre → **editá ese archivo**, no crees otro.
- Si es nueva → archivo nuevo, un hecho por archivo, kebab-case, con frontmatter (`name`,
  `description`, `metadata.type` = `user|feedback|project|reference`).
- Escribí el **porqué**, no solo el qué. Fechas absolutas, nunca "ayer".
- Enlazá memorias relacionadas con `[[nombre]]`.
- Si algo quedó obsoleto por lo de hoy, **corregilo o borralo**.

Actualizá `MEMORY.md`: exactamente una línea por cada `.md` del directorio (salvo el propio
`MEMORY.md`), con el formato `- [Título](archivo.md) — gancho`.

## 3. Bitácora de la sesión

Escribí `docs/bitacoras/YYYY-MM-DD-<tema>.md` de 15 a 30 líneas:

- **Qué se hizo** — cambios concretos, archivos y comandos que importan.
- **Qué se decidió y por qué.**
- **Qué quedó pendiente** — con el siguiente paso concreto, no "seguir con X".
- **Qué se descartó y por qué.**

Si ya existe una del mismo día y tema, agregale una sección en vez de crear otra. Sin credenciales,
sin transcript, sin bloques largos de código.

## 4. Commitear

Si la rama actual es la principal (`master`), **creá una rama antes**. Commits separados por tema
(docs/memoria aparte del código). El mensaje explica el **porqué**, no qué archivos cambiaron.
No hagas push salvo que te lo pidan.

## 5. Cerrar

Reportá en no más de 10 líneas: memorias creadas/editadas/borradas, bitácora escrita y pendientes.

Terminá con la línea exacta:

`Listo para /clear. Para retomar: /contexto <tema>`
