# Hito 0 — Paso 3: diseño de persistencia de identidad y acceso

**Estado:** ejecutado en entorno local MariaDB; motor productivo pendiente
**Versión:** 0.4
**Fecha:** 2026-10-30
**Alcance:** persistencia lógica, migraciones, repositorios Doctrine y comandos transaccionales de identidad/acceso; motor productivo no aprobado

**Resultado local 2026-10-30:** base `kermesfera` creada en MariaDB 10.4.18, nueve migraciones ejecutadas, 16 tablas funcionales más el historial de Doctrine, 9 roles, 16 permisos y ninguna asignación de `event.close`. Lectura, comandos críticos de acceso, idempotencia persistente, límites de solicitudes y entrega reintentable de invitaciones están implementados y probados contra MariaDB, incluida una carrera real entre procesos.

## 1. Objetivo

Preparar la persistencia de identidades, organizaciones, eventos, roles, invitaciones y auditoría sin comprometer el proyecto con un motor todavía no aprobado. MySQL es el candidato principal, pero el diseño debe poder mapearse mediante ORM y conservar límites transaccionales explícitos.

Este paso no habilita `event.close`. La decisión sobre quién puede cerrar un evento se resolverá después; mientras tanto, ese permiso permanece denegado y las tablas de cierre quedan fuera de la primera migración.

## 2. Componentes persistentes

| Grupo | Componentes | Responsabilidad |
|---|---|---|
| Identidad | `users` | Una identidad global por persona y estado de acceso |
| Organización | `organizations`, `organization_memberships`, `organization_membership_roles` | Aislamiento del tenant y gobierno organizacional |
| Evento | `events`, `event_memberships`, `event_membership_roles` | Asignación operativa o histórica independiente por evento |
| Autorización | `roles`, `permissions`, `role_permissions`, `event_permission_grants` | Permisos base y delegaciones condicionadas trazables |
| Invitaciones | `access_invitations`, `notification_deliveries` | Alta por correo, expiración, aceptación y entrega reintentable sin almacenar tokens en texto plano |
| Auditoría | `audit_logs` | Registro inmutable de invitaciones, asignaciones, cambios y revocaciones |

La definición detallada de campos, relaciones e índices está en `docs/MODELO_DATOS_ER.md` versión 0.3.

## 3. Orden propuesto de migraciones

Las ocho migraciones actuales ya fueron creadas y ejecutadas en el entorno local. Las siguientes etapas continúan sujetas a la aprobación del motor productivo.

1. **Identidad y catálogos:** `users`, `roles`, `permissions`, `role_permissions`.
2. **Organizaciones:** `organizations`, `organization_memberships`, `organization_membership_roles`.
3. **Eventos:** `events`, `event_memberships`, `event_membership_roles`.
4. **Delegaciones:** `event_permission_grants` con restricciones y vigencia.
5. **Invitaciones:** `access_invitations` y sus índices de expiración/estado.
6. **Auditoría:** `audit_logs` con referencias lógicas al actor y contexto.
7. **Datos iniciales:** catálogo versionado de roles y permisos, separado de la estructura cuando la herramienta lo permita.

No se incluirán todavía ventas, pagos, tickets, inventario, cierres ni facturación. Cada módulo operativo tendrá migraciones propias cuando sus reglas estén aprobadas.

## 4. Operaciones transaccionales

| Operación | Cambios atómicos | Resultado ante fallo |
|---|---|---|
| Crear invitación | Validar actor y alcance, derivar token seguro, guardar hash, roles y vencimiento, encolar entrega y auditar | No queda invitación sin auditoría/outbox ni se persiste el token en texto plano |
| Aceptar invitación | Resolver identidad, activar membresía organizacional, crear asignación de evento, aplicar roles, consumir invitación y auditar | No queda membresía ni invitación parcialmente aceptada |
| Asignar evento | Validar organización, crear/activar membresía de evento, aplicar roles y auditar | No queda acceso sin roles o auditoría |
| Cambiar roles | Sustituir o ajustar roles, registrar delegaciones y auditar estado anterior/nuevo | Se conserva la configuración anterior completa |
| Revocar evento | Revocar membresía y delegaciones activas, invalidar acceso y auditar | No permanece una delegación utilizable |
| Revocar organización | Revocar membresía organizacional, bloquear lógicamente todos sus eventos y auditar | Ningún evento de esa organización sigue autorizado |
| Transferir responsable principal | Crear/confirmar nuevo responsable y retirar el anterior | La organización nunca queda sin responsable activo |

Las notificaciones por correo se registrarán para procesamiento posterior y nunca mantendrán abierta la transacción principal.

## 5. Reglas de migración

1. Todo cambio de esquema se entrega mediante una migración versionada; no se permiten cambios manuales en ambientes compartidos.
2. Una migración estructural no debe depender de datos externos ni enviar correos.
3. Los cambios de datos masivos se separan de los cambios estructurales cuando puedan bloquear tablas.
4. Las migraciones deben ejecutarse desde cero y desde la versión inmediatamente anterior en integración continua.
5. Las restricciones, índices y claves foráneas se verifican después de migrar.
6. Una reversión no debe borrar información financiera o histórica; si no es segura, se prepara una migración correctiva hacia adelante.
7. Los catálogos de roles y permisos deben ser idempotentes y versionados.
8. Ninguna migración contendrá secretos, contraseñas, tokens de invitación ni credenciales de proveedores.

## 6. Portabilidad que debe resolverse al elegir motor

| Tema | Contrato lógico | Decisión física pendiente |
|---|---|---|
| UUID | Identificador estable expuesto por dominio/API | Binario de 16 bytes o texto; conversión del ORM |
| Fechas | Instantes UTC e inmutables | Tipo, precisión y conversión de zona horaria |
| Correo | Unicidad por valor normalizado | Colación, índice funcional o columna normalizada |
| JSON | Restricciones adicionales y snapshots | Tipo JSON nativo, validación e índices |
| Estados | Valores cerrados validados | `CHECK`, texto o enum físico según portabilidad |
| Vigencia | Máximo una asignación activa por alcance | Índice filtrado o estrategia equivalente |
| Bloqueos | Escrituras críticas serializables por agregado | Aislamiento, bloqueo pesimista u optimista |
| Índices | Consultas por organización, evento, usuario y estado | Longitud, orden y capacidades específicas del motor |

Usar ORM no elimina estas diferencias. Cada adaptador deberá probar concurrencia, bloqueos, restricciones y migraciones contra el motor realmente soportado.

## 7. Contratos de repositorio

La aplicación continúa dependiendo de contratos, no del ORM. Los adaptadores persistentes actuales cubren:

- localizar identidad por identificador y correo normalizado;
- localizar membresía por usuario y organización;
- localizar asignación por usuario, organización y evento;
- recuperar roles y delegaciones vigentes;
- bloquear la invitación durante su aceptación;
- guardar cambios y auditoría dentro de la transacción activa.

`InMemoryAccessDirectory` se conserva para pruebas unitarias. `DoctrineAccessDirectory`, `DoctrineEventAssignmentWriter` y `DoctrineAuditLogWriter` son los adaptadores persistentes actuales. Los comandos de asignación, cambio de roles y revocación bloquean el contexto, autorizan, persisten el cambio y registran auditoría dentro de una sola transacción. La revocación invalida también las delegaciones adicionales activas.

`DoctrineInvitationAcceptanceRepository` bloquea la invitación por hash, resuelve una identidad única por correo, activa la membresía organizacional y consume la invitación. Las invitaciones de evento almacenan roles explícitos en `roles_json`; la aceptación crea la asignación y auditoría en la misma transacción y rechaza reintentos de un token ya consumido.

`CreateInvitationHandler` exige `org.manage_members`, valida alcance y vigencia, evita duplicados pendientes y deriva un token HMAC versionado desde el identificador. Solo el hash SHA-256 se persiste; invitación, outbox y auditoría se confirman juntos. `InvitationDeliveryProcessor` reclama filas con lease, reconstruye el token después del commit, entrega mediante un puerto externo y marca envío o reintento sin mantener abierta la transacción de negocio.

`RevokeOrganizationAccessHandler` conserva la identidad global, pero revoca atómicamente la membresía organizacional, todas las asignaciones de sus eventos y sus delegaciones activas. La revocación genérica no permite retirar al propietario ni autorrevocarse; esos casos requieren transferencia de responsabilidad.

`TransferOrganizationOwnershipHandler` solo admite como actor al propietario activo, exige que el nuevo propietario ya tenga membresía activa y promueve al nuevo antes de degradar al anterior dentro de la misma transacción. El acceso residual del propietario anterior se declara explícitamente como administrador o colaborador, y ambos roles persistentes se sincronizan con la membresía.

`IdempotentExecutor` reserva `idempotency_keys` dentro de la misma transacción del comando. El alcance único combina organización, usuario, clave, método y ruta; un hash SHA-256 canónico rechaza reutilizaciones con otra carga. La respuesta confirmada se conserva para reintentos y la restricción única hace que solicitudes concurrentes esperen el resultado sin repetir el efecto. Si el comando falla, la reserva se revierte con toda la transacción.

## 8. Decisiones pendientes antes de generar migraciones

1. Motor y versión definitiva; MySQL continúa como candidato.
2. Si MySQL es elegido: confirmar InnoDB, juego de caracteres, colación y nivel de aislamiento.
3. Representación física de UUID y fechas UTC.
4. Herramienta ORM/migraciones y convenciones de nombres.
5. Política de retención de invitaciones y auditoría.
6. Duración predeterminada de asignaciones históricas.
7. Diferencias de atribuciones entre `ORG_OWNER` y `ORG_ADMIN`.

La definición de quién puede cerrar eventos es deliberadamente posterior y no bloquea estas tablas iniciales porque `event.close` permanece deshabilitado.

## 9. Criterio de cierre del paso 3

El diseño queda listo para implementación cuando se aprueban motor, versión y decisiones físicas de la sección 8; se demuestra que las operaciones de la sección 4 son atómicas; y la primera migración puede ejecutarse desde una base vacía sin incluir módulos todavía no aprobados.
