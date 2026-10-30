# Identity context

Implementa el alcance de identidad y acceso con contratos de aplicación, adaptadores en memoria y persistencia Doctrine DBAL:

- una identidad global por persona;
- vinculación activa con cada organización;
- asignaciones operativas o históricas por evento;
- participación del mismo usuario en varios eventos y organizaciones;
- denegación por defecto ante identidad, membresía, asignación o vigencia inválida.

`AccessScopePolicy` valida el alcance. `AuthorizationPolicy` aplica después el catálogo de permisos y la unión de roles definida por `RolePermissionMap`. Los permisos condicionados de la matriz no se heredan: requieren una delegación adicional explícita en la asignación del evento.

`InMemoryAccessDirectory` se conserva para pruebas unitarias puras. `DoctrineAccessDirectory` es el adaptador persistente actual: reconstruye el contrato de acceso desde las tablas de identidad, organización, evento, roles y permisos mediante Doctrine DBAL. No implementa autenticación ni asigna `event.close`, que continúa denegado.

`AssignEventAccessHandler` implementa la primera escritura crítica: bloquea usuario, membresía y evento; exige `org.manage_members`; crea o actualiza la asignación y sus roles; y escribe auditoría en la misma transacción. Las integraciones externas quedan fuera de ese límite.

`AssignEventAccessController` expone esa operación por HTTP con actor tomado del contexto autenticado, UUID generados en servidor e idempotencia persistente. `BearerAuthenticationSubscriber` valida JWT HS256, exige firma, emisor, audiencia y vigencia, y coloca el `sub` UUID en el contexto. Las pruebas HTTP utilizan el mismo flujo Bearer; ya no existe un encabezado especial para simular actores.

`ChangeEventRolesHandler` sustituye roles conservando una instantánea auditada del estado anterior. `RevokeEventAccessHandler` revoca la asignación y todas sus delegaciones activas de forma atómica. Los roles históricos solo pueden utilizarse con acceso histórico y los operativos no pueden incluir `HISTORICAL_VIEWER`.

`ChangeEventRolesController` y `RevokeEventAccessController` exponen esos comandos con el mismo contrato autenticado e idempotente. Sus respuestas pueden reproducirse sin ejecutar nuevamente el cambio o la revocación.

`AcceptInvitationHandler` bloquea la invitación mediante el hash SHA-256 del token, valida correo y expiración, crea o reutiliza la identidad global, activa la membresía organizacional y, si corresponde, asigna evento y roles explícitos. `AcceptInvitationController` expone el flujo como ruta pública: el token es una capacidad aleatoria de un solo uso, no se copia a idempotencia ni auditoría y un replay devuelve 422 sin repetir efectos.

`CreateInvitationHandler` deriva un token HMAC versionado a partir del identificador de invitación, persiste únicamente su hash, evita invitaciones pendientes duplicadas por correo y alcance, y encola la entrega dentro de la misma transacción. La respuesta idempotente y el outbox nunca contienen el token plano.

`InvitationDeliveryProcessor` reclama una entrega con lease corto, reconstruye el token después del commit, llama al puerto `InvitationEmailSender` sin mantener una transacción de negocio abierta y marca envío o reintento con espera incremental. `app:invitations:dispatch` permite ejecutarlo desde un scheduler. El adaptador actual de correo es intencionalmente no disponible hasta elegir y configurar proveedor.

`SecureInvitationTokenGenerator` mantiene una versión activa para nuevas invitaciones y un llavero temporal de secretos heredados. El worker usa `token_key_version` del outbox, de modo que una rotación no invalida entregas pendientes creadas con una versión anterior.

`RevokeOrganizationAccessHandler` revoca la vinculación con una organización, sus asignaciones de evento y delegaciones activas, manteniendo intacta la identidad global. No permite autorrevocación ni revocar un propietario sin una transferencia previa.

`RevokeOrganizationAccessController` expone esa operación con JWT Bearer e idempotencia persistente. Un replay confirmado no intenta revocar nuevamente y devuelve la respuesta original.

`TransferOrganizationOwnershipHandler` promueve una membresía activa a propietaria y degrada al propietario anterior a administrador o colaborador dentro de la misma transacción. Solo el propietario activo puede ejecutar el cambio.

`TransferOrganizationOwnershipController` exige JWT Bearer, nuevo miembro activo, acceso residual explícito e idempotencia. La respuesta reproducida no vuelve a intercambiar propietarios.
