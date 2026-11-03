---
name: feedback-fechas-en-tests
description: Los tests HTTP validan contra el reloj real; no usar fechas futuras fijas en sus payloads
metadata:
  type: feedback
---

Los handlers reciben `now` inyectado y sus tests pueden usar fechas fijas, pero los controladores HTTP usan el
reloj real. Una fecha "futura" fija en el payload de un test HTTP caduca sola (pasó el 2026-11-03 con
`expires_at` de invitaciones: "Invitation expiry must be in the future").

**Why:** la suite se rompió sin cambios de código. **How to apply:** en tests HTTP calcular fechas relativas
(`new \DateTimeImmutable('+2 days')`). Las fechas fijas siguen bien cuando el test inyecta `now`.
Toda variable `%env(...)%` nueva va también en `backend/phpunit.xml.dist` y `backend/.env.example`.
