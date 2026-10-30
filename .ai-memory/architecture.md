# Arquitectura

## Estado

Plataforma centralizada multi-organización aprobada como dirección. El backend Symfony se mantiene como monolito modular inicialmente y debe poder escalar horizontalmente sin mezclar datos entre organizaciones.

## Límites principales

- `organization_id` delimita cada tenant y `event_id` delimita la operación y la habilitación comercial.
- Los cobros de Kermesse por evento pertenecen al contexto Plataforma/Comercial; los pagos de compradores pertenecen a Venta/Pago.
- La facturación fiscal será un módulo de etapa 2 y dependerá de una entidad emisora con NIT asociada al evento.
- Usuarios ilimitados es una regla comercial; concurrencia, almacenamiento y protección contra abuso siguen sujetos a capacidad y seguridad.

## Persistencia y transacciones

- El entorno local autorizado usa MariaDB 10.4.18 en el puerto 3306 y la base `kermesfera`; esto no define todavía el motor productivo.
- MySQL continúa como alternativa probable para producción; si se adopta, las tablas transaccionales deberán usar InnoDB y las migraciones deberán validarse también contra ese motor.
- Todo cambio del esquema se realizará mediante migraciones versionadas. No se permiten cambios manuales directos en ambientes compartidos.
- El acceso se mantendrá detrás del ORM y los casos de uso de escritura definirán límites transaccionales explícitos.
- La portabilidad no se presume solo por usar ORM: migraciones, bloqueos, índices, aislamiento y consultas específicas deberán probarse contra cada motor soportado.
- El paso 3 de identidad está documentado en `docs/HITO_0_DISENO_PERSISTENCIA_IDENTIDAD.md`; ocho migraciones se ejecutaron localmente, incluidas la persistencia de roles explícitos, idempotencia, outbox de invitaciones y leases del worker. Nuevas decisiones físicas deben conservar compatibilidad y validarse contra el motor productivo elegido.
- Las escrituras reintentables reservan una clave única por organización, usuario, método y ruta dentro de la misma transacción del efecto. El hash canónico impide reutilizarla con otra carga y una solicitud concurrente recupera la respuesta confirmada sin repetir el efecto.
- Los controladores nunca reciben `actor_user_id` del cuerpo: consumen `authenticated_user_id` del contexto preparado por `BearerAuthenticationSubscriber`. El middleware valida JWT HS256 con `sub` UUID, firma HMAC, emisor, audiencia, emisión, expiración, tolerancia de reloj y duración máxima; salud permanece pública. La emisión/login corresponde al futuro proveedor de identidad.
- El contrato HTTP inicial vive en `docs/openapi.yaml` y la matriz transaccional en `docs/HITO_0_MATRIZ_OPERACIONES_ESCRITURA.md`; cada operación declara `implemented`, `planned` o `blocked`.
- Los tokens de invitación se derivan mediante HMAC de `invitation_id` y una versión de clave. Solo el hash se persiste; respuesta idempotente y outbox guardan identificadores, nunca el token plano. Una clave activa genera nuevas invitaciones y un llavero de secretos heredados permite completar entregas pendientes después de rotar. El worker selecciona por `token_key_version`, reclama con lease, llama al proveedor fuera de la transacción de negocio y reintenta con espera incremental.
- La aceptación de invitaciones es pública y usa el token aleatorio como capacidad de un solo uso. Se bloquea por hash y se consumen identidad, membresías, roles y auditoría atómicamente; no se crea registro idempotente con el token y un replay responde 422 sin repetir efectos.
- `event.close` permanece bloqueado y las tablas de cierre quedan fuera de la primera migración hasta definir quién autoriza el cierre.

## Reglas

- Registrar aquí decisiones que afecten a varios módulos o equipos.
- Mantener ejemplos concretos y evitar copiar transcripts completos de agentes.

