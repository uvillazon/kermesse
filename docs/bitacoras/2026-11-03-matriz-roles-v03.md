# 2026-11-03 — Matriz de roles v0.3 y prueba de /cierre

## Qué se hizo
- Rutas del protocolo de memoria pasadas a relativas (`CLAUDE.md`, `.claude/commands/`); PR #1 fusionado.
- Matriz de roles v0.3: `ticket.resend` nuevo, `payment.review` pasa a delegación `A`, casos de habilitación
  comercial, suspensión, historial y facturación. Historias `US-H0-024` a `US-H0-026`. Hallazgos 9-12 en
  `docs/HITO_0_VALIDACION_ROLES_TRAZABILIDAD.md`. PR #2 fusionado (squash, `19d8af8`).
- Esta sesión terminó ejecutando `/cierre` como primera mitad de la prueba de punta a punta.

## Qué se decidió y por qué
- La matriz sube a v0.3 (ya figuraba v0.2 desde el 29-oct): los hallazgos 2-4 y las reglas nuevas justifican versión.
- `payment.review` queda como `A` en el documento aunque el código lo concede de base: mientras la organización
  no apruebe la v0.3, manda el código (ver [[project-matriz-roles-v03]]).
- Los gotchas de GitHub en la nube se guardan en [[reference-sesion-nube-github]].

## Pendiente
- Ejecutar `/contexto matriz de roles` en la sesión siguiente y comprobar que recupera: v0.3 fusionada, las 4
  decisiones abiertas y la divergencia con `RolePermissionMap`.
- Tras la aprobación de la organización: alinear `Permission.php` (añadir `ticket.resend`) y `RolePermissionMap`.
- Decisiones de la organización: quién autoriza `event.close`, quién gestiona la habilitación comercial,
  separar o no `FINANCE_REVIEWER` y `PAYMENT_REVIEWER`, vigencia del acceso histórico.

## Descartado
- Cambiar `RolePermissionMap` en esta sesión: altera comportamiento y requiere aprobación (`decisions.md`).
- Commitear el patch de rutas dentro del repo: se movió fuera; el push se resolvió instalando la app de GitHub.
