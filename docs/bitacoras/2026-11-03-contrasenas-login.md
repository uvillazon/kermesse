# 2026-11-03 — Contraseñas reales en el login

## Qué se hizo
- Migración `Version20261103010000`: `users.password_hash` y `password_updated_at`, ambas nullable.
- `LoginController` verifica el hash (`NativePasswordHasher`: argon2id o bcrypt, rehash al entrar) y
  responde siempre `401 Credenciales inválidas.`; ya no acepta `password123` ni cualquier clave de 6+.
- Aceptación de invitación recibe `password` (8 caracteres a 72 bytes, sin recortar espacios).
- Comando `app:users:set-password <correo>` (oculta o `--from-env`) para usuarios de desarrollo.
- Cuota de login por IP y por cuenta: `RATE_LIMIT_LOGIN_PER_MINUTE` (10 por defecto).
- Frontend: cuentas demo movidas a `environment.development.ts`; el paquete de producción no las trae.
- Pruebas: `LoginControllerIntegrationTest`, casos nuevos en aceptación, unitarias de política, hasher y
  comando. Verificadas en la nube con MariaDB 10.11 temporal (no 10.4.18).

## Qué se decidió y por qué
- Ver [[project-contrasenas-login]]. Clave: una invitación nunca reemplaza una contraseña existente.
- La fecha fija de vencimiento en `AssignEventAccessControllerIntegrationTest` ya había pasado; ahora es
  relativa (`+1 day`).

## Pendiente
- En local: `php bin/console doctrine:migrations:migrate` y asignar contraseña a cada cuenta demo con el
  comando (hasta entonces el login de esas cuentas responde 401).
- Pantalla Angular de aceptación de invitación con contraseña; recuperación/cambio de contraseña.
- Fallas previas no tocadas: `TransactionalExecutorTest` (sin driver) y 5 de `CreateSaleControllerIntegrationTest` (500).
