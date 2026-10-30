# Kermesse backend

Esqueleto inicial del backend Symfony 7.4 LTS / PHP 8.2. Está preparado como monolito modular por contextos, con capas `Domain`, `Application`, `Infrastructure` y `Api` para cada contexto.

## Contextos previstos

`Organization`, `Identity`, `Event`, `Catalog`, `Cost`, `Sale`, `Ticket`, `Course`, `Report`, `Closure` y `Audit`.

Además de la infraestructura compartida y el endpoint técnico de salud, `Identity` implementa alcance y permisos: identidad activa, vinculación por organización, asignación operativa o histórica por evento, combinación de roles y delegaciones adicionales explícitas. Doctrine DBAL/Migrations respaldan repositorios y comandos transaccionales de identidad; el adaptador en memoria se conserva para pruebas unitarias. La infraestructura compartida incluye idempotencia persistente y segura ante concurrencia. La API permite asignar acceso, reemplazar roles, revocar acceso/delegaciones y crear invitaciones con entrega encolada; sus rutas protegidas validan JWT Bearer firmados.

## Arranque local

Requiere PHP 8.2 en `/c/php82/php.exe` y Composer disponible en el PATH de Git Bash.

```bash
cp .env.example .env
composer install
/c/php82/php.exe -S 127.0.0.1:8000 -t public public/index.php
```

Health: `GET http://127.0.0.1:8000/api/v1/health`

Escrituras protegidas disponibles: asignar, cambiar roles y revocar acceso de evento; revocar acceso organizacional; transferir propietario mediante `POST /organizations/{organizationId}/ownership-transfers`; y crear invitaciones. Requieren identidad autenticada en el contexto interno, las reglas de autorización del comando, contrato `docs/openapi.yaml` e `Idempotency-Key`. `POST /invitations/accept` es público y consume un token de invitación de un solo uso sin almacenarlo en texto plano.

La autenticación usa JWT HS256 con `sub` UUID, `iss`, `aud`, `iat` y `exp`. Firma, emisor, audiencia, tolerancia de reloj y duración máxima se configuran mediante `AUTH_TOKEN_*`; el secreto real debe vivir en `.env.local` o un gestor de secretos. El backend valida tokens, pero la emisión/login quedará en el proveedor de identidad que se elija.

## Base de datos local

El servicio local detectado en `127.0.0.1:3306` es **MariaDB 10.4.18**, aunque inicialmente se identificó como MySQL. Se usa para desarrollo local; el motor definitivo de producción sigue pendiente.

La conexión se configura en `.env.local`, que está ignorado por Git. Nunca guardar credenciales reales en `.env.example`.

```bash
/c/php82/php.exe bin/console doctrine:migrations:status
/c/php82/php.exe bin/console doctrine:migrations:migrate --no-interaction
```

Las migraciones actuales crean identidad, organizaciones, eventos, membresías, roles, permisos, invitaciones, auditoría, idempotencia y el outbox de notificaciones. `event.close` existe en el catálogo pero no está asociado a ningún rol.

Las invitaciones derivan su token mediante HMAC y solo persisten su hash. El consumidor reintentable se ejecuta con `bin/console app:invitations:dispatch --limit=50`; hasta configurar un adaptador real de `InvitationEmailSender`, el proveedor deliberadamente no envía correo y la entrega vuelve a `pending`.

La rotación usa `INVITATION_TOKEN_KEY_VERSION` y `INVITATION_TOKEN_SECRET` para nuevas invitaciones. Las claves anteriores necesarias para entregas pendientes se conservan temporalmente en `INVITATION_TOKEN_LEGACY_SECRETS`, un objeto JSON por versión, por ejemplo `{"v1":"secreto-anterior-de-al-menos-32-caracteres"}`. Una versión heredada solo puede retirarse cuando no queden entregas `pending` o `processing` asociadas; los secretos reales permanecen fuera de Git.

## Tests

```bash
composer test
```

La clave `Idempotency-Key`, el hash canónico y `IdempotentExecutor` están disponibles como servicios compartidos. La reserva y el efecto se ejecutan en una sola transacción; la misma clave/carga recupera la respuesta original, una carga distinta se rechaza y las solicitudes concurrentes no duplican el efecto. `AssignEventAccessController` demuestra el patrón HTTP completo y devuelve `Idempotency-Replayed`.
