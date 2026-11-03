# 2026-11-03 — Suite de integración del backend en verde

## Qué se hizo
- La suite con MariaDB daba 2 errores y 7 fallos; se corrigieron las tres causas (PR uvillazon/kermesse#5, squash):
  - `SALE_RESERVATION_TTL_SECONDS=900` agregada en `backend/phpunit.xml.dist` y documentada en `backend/.env.example`.
  - `AssignEventAccessControllerIntegrationTest`: `expires_at` ahora relativo (+2 días, 18:00 UTC) vía helper.
  - `TransactionalExecutorTest`: DBAL 4 no acepta `url`; se usa `['driver' => 'pdo_sqlite', 'memory' => true]`.
- Se montó MariaDB desechable en la sesión en la nube (apt, `mysqld_safe`, migraciones) y se verificó:
  84/84 con base, 43 omitidos y 0 fallos sin base.

## Qué se decidió y por qué
- TTL de 900 s porque `docs/openapi.yaml` dice "reserva inventario por 15 minutos".
- Variable en `.env.example` y no en un `.env`: el repo solo versiona el ejemplo.
- Solo se tocaron fechas en tests HTTP: los demás tests inyectan `now` y sus fechas fijas no caducan.
- Memoria: [[reference-sesion-nube-github]] (cómo montar MariaDB en la nube) y [[feedback-fechas-en-tests]].

## Pendiente
- El reporte original hablaba de 86 tests y aquí había 84; tras PRs posteriores `master` cuenta 88 en verde,
  así que no requiere acción salvo que la cuenta local vuelva a discrepar.
- El repo no tiene CI: siguiente paso concreto, agregar un workflow que corra `php vendor/bin/phpunit`
  con un servicio MariaDB 10.4 para que estas regresiones se detecten en cada PR.

## Descartado
- `composer test` como root (falla); se usa `php vendor/bin/phpunit`.
- Crear `backend/.env` con valores por defecto: rompe la convención de solo versionar `.env.example`.
