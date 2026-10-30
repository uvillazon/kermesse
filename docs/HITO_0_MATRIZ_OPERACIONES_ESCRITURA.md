# Hito 0 — Matriz de operaciones de escritura

**Versión:** 0.1
**Fecha:** 2026-10-30
**Estado:** contrato inicial; identidad parcialmente implementada, módulos operativos planificados y cierre bloqueado

## Convenciones

- Toda operación toma la identidad del contexto autenticado; nunca confía en un `actor_user_id` enviado por el cliente.
- Las rutas protegidas validan JWT HS256 con `sub` UUID, emisor, audiencia, emisión, expiración y duración máxima; salud permanece pública.
- Las rutas autenticadas incluyen `organizationId` y, cuando corresponde, `eventId`; ambos se verifican nuevamente en persistencia.
- `Idempotency-Key` es obligatoria en comandos reintentables. La reserva, el efecto y la auditoría se confirman o revierten juntos.
- Llamadas a correo, banco, almacenamiento y exportación se programan después del commit; no mantienen abierta la transacción. El worker de invitaciones usa lease recuperable y reintentos incrementales.
- Las rutas API, excepto salud, consumen un contador persistente por minuto y acción. Se identifica por usuario autenticado o, para rutas públicas, por IP; al excederlo se responde `429` con `Retry-After` sin ejecutar el controlador.
- Los estados `planned` y `blocked` son contrato, no autorización para implementar reglas todavía pendientes.

## Identidad y acceso

| Operación / estado | Autorización | Bloqueos y transacción | Idempotencia y concurrencia | Auditoría |
|---|---|---|---|---|
| `AssignEventAccess` / **implemented** | `org.manage_members`; actor autenticado y membresía activa | Bloquea evento, identidades y membresías; guarda asignación y roles en una transacción | Clave por organización, actor, método y ruta; hash canónico; restricción única | `identity.event_access_assigned`, estado anterior y actual |
| `ChangeEventRoles` / **implemented** | `org.manage_members` | Bloquea asignación; reemplaza todos los roles | Misma política persistente; control por fila de asignación | `identity.event_roles_changed` |
| `RevokeEventAccess` / **implemented** | `org.manage_members` | Revoca asignación y delegaciones activas | Misma política persistente; bloqueo de asignación | `identity.event_access_revoked` |
| `CreateInvitation` / **implemented** | `org.manage_members` | Invitación, hash, outbox y auditoría se confirman juntos | Clave persistente; el token HMAC se deriva por `invitation_id`/versión y no aparece en respuesta ni outbox | `identity.invitation_created` sin token plano |
| `AcceptInvitation` / **implemented** | Ruta pública; token vigente y correo coincidente | Bloquea invitación; crea/reutiliza identidad, membresías y roles | El token aleatorio es capacidad de un solo uso; replay devuelve 422 sin repetir efectos ni persistir token plano | `identity.invitation_accepted` |
| `RevokeOrganizationAccess` / **implemented** | `org.manage_members`; no propietario ni autorrevocación | Revoca membresía, accesos de eventos y delegaciones; conserva identidad global | Clave persistente por actor/ruta y bloqueos por organización/usuario | `identity.organization_access_revoked` |
| `TransferOrganizationOwnership` / **implemented** | Propietario activo autenticado | Promueve nuevo propietario antes de degradar al anterior; sincroniza roles | Clave persistente, hash de carga y bloqueo ordenado de ambas membresías | `identity.organization_ownership_transferred` |

## Operación del evento

| Operación / estado | Autorización | Bloqueos y transacción | Idempotencia y concurrencia | Auditoría |
|---|---|---|---|---|
| `CreateSale` / planned | `sale.write`; evento abierto | Venta, líneas, snapshots de precio, disponibilidad y ticket cuando aplique | Clave obligatoria; bloqueo/versionado de inventario; nunca disponibilidad negativa | `sale.created` con importes y referencias, sin datos sensibles |
| `ConfirmPayment` / planned | `payment.review`; evento abierto | Pago, venta y habilitación/emisión de ticket | Clave obligatoria; bloqueo de pago; confirmación única | `payment.confirmed` o `payment.rejected` |
| `RedeemTicket` / planned | `ticket.redeem`; evento abierto | Ticket, líneas y canje en la misma transacción | Clave obligatoria; bloqueo de ticket; cantidad canjeada no supera emitida | `ticket.redeemed` con cantidades |
| `AssignTickets` / planned | `course.manage`; evento abierto | Asignaciones a curso/grupo; no crea venta ni ingreso | Clave obligatoria; unicidad por ticket/destino definida en esquema | `ticket.allocated` |
| `RecordExpense` / planned, contrato HTTP v0.2 | `expense.write`; evento abierto | Gasto; cualquier evidencia se vincula fuera de esta transacción mediante almacenamiento controlado | Clave obligatoria; importe decimal textual y actor tomado del JWT | `expense.recorded` |
| `RequestExport` / planned, contrato HTTP v0.2 | `report.export`; lectura vigente o histórica | Crea trabajo `pending`; no genera ni almacena el archivo dentro de la petición | Clave obligatoria por tipo/formato/filtros; ejecución asíncrona única; descarga temporal | `report.export_requested` y resultado posterior |
| `CloseEvent` / **blocked** | `event.close` sin asignar; responsable y doble aprobación pendientes | Validaciones, snapshot y estado cerrado deberán ser atómicos | Clave obligatoria y bloqueo exclusivo del evento | Evento de cierre por definir; ninguna implementación habilitada |

## Respuestas y fallos

| Situación | HTTP | Código estable |
|---|---:|---|
| JSON o encabezado requerido inválido | 400 | `request_error` |
| Sin identidad autenticada | 401 | `request_error` |
| Identidad autenticada sin permiso | 403 | `forbidden` |
| Clave idempotente reutilizada con otra carga | 409 | `idempotency_conflict` |
| Regla de dominio incumplida | 422 | `access_mutation_failed` o código específico del módulo |
| Límite de solicitudes excedido | 429 | `request_error`; incluye `Retry-After` |
| Fallo no controlado | 500 | `internal_error`; no exponer detalles internos |

El contrato HTTP inicial está en `docs/openapi.yaml`. Toda nueva escritura deberá añadirse a ambos archivos antes de exponer su controlador.
