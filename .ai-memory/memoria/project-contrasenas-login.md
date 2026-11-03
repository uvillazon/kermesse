---
name: project-contrasenas-login
description: Cómo se guardan, asignan y verifican las contraseñas del login propio y por qué
metadata:
  type: project
---

Decidido el 2026-11-03 al quitar el marcador de prototipo del `LoginController` (aceptaba cualquier
contraseña de 6+ caracteres para cualquier correo activo).

- Columna `users.password_hash` (nullable) + `password_updated_at`, migración `Version20261103010000`.
  Sin hash no se puede entrar.
- `NativePasswordHasher`: `password_hash()` con argon2id si existe, si no bcrypt; al entrar se rehashea
  si el algoritmo quedó viejo. Política: 8 caracteres mínimo, 72 bytes máximo (límite de bcrypt), sin
  recortar espacios.
- Login: el mismo 401 `Credenciales inválidas.` para correo inexistente, sin contraseña, contraseña mala o
  cuenta suspendida; se hace una verificación ficticia para igualar tiempos.
- Límite: cuota por IP (subscriber) y por cuenta (hash del correo) con `RATE_LIMIT_LOGIN_PER_MINUTE`.
  Cuenta intentos, no solo fallos, y bloquea un minuto.
- Asignación: al aceptar invitación (`password` en el cuerpo). Obligatoria si la cuenta es nueva o no tenía
  contraseña. **Nunca reemplaza una contraseña existente.**
- Desarrollo: `bin/console app:users:set-password <correo>` (oculta o `--from-env`). Las cuentas demo del
  frontend solo aparecen si `environment.production` es falso.

**Why:** el token de invitación llega al correo, así que prueba control del buzón para crear una cuenta,
pero dejar que reemplace una contraseña existente abriría toma de cuentas si un token se filtra.
**How to apply:** la recuperación de contraseña, cuando se haga, debe ser un flujo propio con token de un
solo uso, no reutilizar invitaciones. Ver [[project-kermesfera]].
