# Hito 0 — Matriz de roles y permisos

**Estado:** implementación temporal en memoria; decisiones adicionales pendientes
**Versión:** 0.2
**Fecha:** 2026-10-29
**Fuente funcional:** `docs/REGLAS_DE_NEGOCIO.md`

## 1. Criterios de autorización

1. El permiso efectivo siempre se evalúa con `organización + evento + usuario + acción`.
2. El alcance por defecto es denegado. Tener acceso a una organización no implica acceso a todos sus eventos.
3. Una membresía de evento puede ser operativa o histórica. La membresía histórica solo concede consulta y exportación explícita.
4. El backend es la autoridad. Angular, Flutter y el portal público solo ocultan o muestran acciones; nunca sustituyen la autorización de API.
5. Todas las operaciones de escritura requieren que el evento esté abierto. Un evento `closed` permite únicamente lecturas y exportaciones autorizadas.
6. Los permisos se asignan por capacidad, no por pantalla. Una pantalla puede requerir varios permisos y una misma acción debe comportarse igual en web, móvil y API.
7. Un usuario puede tener más de un rol en el mismo evento; la autorización efectiva es la unión de permisos, salvo que una regla específica exija aprobación de otro responsable.
8. Los intentos fuera de alcance se responden como no autorizados y se auditan cuando sean relevantes, sin revelar datos de otra organización.

## 2. Roles propuestos

| Código | Rol | Alcance normal | Propósito | ¿Puede escribir? |
|---|---|---|---|---|
| `ORG_OWNER` | Responsable principal | Una organización | Custodiar la organización, sus administradores y decisiones comerciales | Sí, solo en el alcance organizacional |
| `ORG_ADMIN` | Administrador de organización | Una organización | Gestionar colaboradores, invitaciones y asignaciones de eventos | Sí, solo en el alcance organizacional |
| `EVENT_MANAGER` | Responsable de evento | Un evento | Preparar y operar un evento: catálogo, disponibilidad, cursos, gastos y seguimiento | Sí, dentro del evento |
| `FINANCE_REVIEWER` | Revisor financiero | Un evento | Revisar pagos, gastos, reportes, balance y pendientes de cierre | Sí, solo aprobaciones financieras definidas |
| `SALES_OPERATOR` | Operador de ventas | Un evento y puestos autorizados | Registrar ventas rápidas y consultar catálogo/asignaciones necesarias | Sí, ventas permitidas |
| `PAYMENT_REVIEWER` | Verificador de pagos | Un evento | Revisar comprobantes y aprobar/rechazar pagos según el canal | Sí, solo estados de pago |
| `TICKET_OPERATOR` | Operador de tickets/canje | Un evento y puestos autorizados | Consultar tickets y validar/canjear tickets | Sí, solo canje |
| `REPORT_VIEWER` | Consultor de reportes | Un evento | Consultar reportes y exportaciones expresamente concedidas | No |
| `HISTORICAL_VIEWER` | Responsable histórico | Eventos históricos autorizados | Consultar y exportar información histórica | No |

`ORG_OWNER` y `ORG_ADMIN` no heredan permisos operativos sobre eventos. Si necesitan administrar, vender, revisar o consultar un evento, también deben recibir una asignación de evento con los roles correspondientes.

`PUBLIC_BUYER` no es una membresía interna: representa al comprador del portal público y solo puede interactuar con el catálogo y los comandos de preventa que el evento publique. No puede consultar datos internos, auditoría, reportes ni otros compradores.

No se define en esta matriz un rol de soporte global multi-organización. Si se requiere soporte técnico con acceso a datos, deberá aprobarse como decisión separada, con alcance temporal, justificación y auditoría reforzada.

## 3. Catálogo de permisos

| Permiso | Acción protegida | Alcance |
|---|---|---|
| `org.read` | Consultar organización y sus eventos visibles | Organización |
| `org.manage_members` | Crear, cambiar o retirar responsables y roles | Organización/evento |
| `event.read` | Consultar configuración y estado del evento | Evento |
| `event.manage` | Crear o modificar evento y configuración operativa | Evento abierto |
| `catalog.manage` | Crear, publicar, pausar o modificar productos, precio y disponibilidad | Evento abierto |
| `course.manage` | Crear cursos/grupos y asignar tickets o productos | Evento abierto |
| `expense.write` | Registrar, modificar o anular costos y gastos | Evento abierto |
| `sale.write` | Registrar venta rápida o venta autorizada | Evento abierto |
| `payment.review` | Verificar, confirmar, rechazar, anular o devolver pagos | Evento abierto |
| `ticket.read` | Consultar tickets y asignaciones permitidas | Evento |
| `ticket.redeem` | Validar y canjear ticket | Evento abierto |
| `report.read` | Consultar reportes operativos o financieros permitidos | Evento |
| `report.export` | Exportar PDF/Excel permitido | Evento |
| `balance.read` | Consultar balance preliminar o final | Evento |
| `event.close` | Revisar pendientes y confirmar cierre definitivo | Evento abierto |
| `audit.read` | Consultar auditoría autorizada | Organización/evento |

`PUBLIC_BUYER` usa endpoints públicos separados (`catalog.read.public`, `preorder.write`, `payment.status.read.own`, `ticket.resend.own`) y nunca recibe permisos internos de esta tabla.

## 4. Matriz de permisos

Leyenda: **✓** permitido; **—** denegado; **H** permitido solo en eventos históricos autorizados y en modo consulta/exportación; **A** permitido con alcance adicional indicado; **P** pendiente y denegado hasta decisión.

### 4.1 Roles de organización

| Permiso | `ORG_OWNER` | `ORG_ADMIN` | Colaborador sin rol administrativo |
|---|---:|---:|---:|
| `org.read` | ✓ | ✓ | — |
| `org.manage_members` | ✓ | ✓ | — |
| Cualquier permiso de evento | — | — | — |

### 4.2 Roles de evento

| Permiso | `EVENT_MANAGER` | `FINANCE_REVIEWER` | `SALES_OPERATOR` | `PAYMENT_REVIEWER` | `TICKET_OPERATOR` | `REPORT_VIEWER` | `HISTORICAL_VIEWER` |
|---|---:|---:|---:|---:|---:|---:|---:|
| `event.read` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | H |
| `event.manage` | ✓ | — | — | — | — | — | — |
| `catalog.manage` | ✓ | — | — | — | — | — | — |
| `course.manage` | ✓ | — | — | — | — | — | — |
| `expense.write` | A | A | — | — | — | — | — |
| `sale.write` | A | — | ✓ | — | — | — | — |
| `payment.review` | A | ✓ | — | ✓ | — | — | — |
| `ticket.read` | ✓ | A | A | A | ✓ | A | H |
| `ticket.redeem` | A | — | — | — | ✓ | — | — |
| `report.read` | ✓ | ✓ | A | A | A | ✓ | H |
| `report.export` | A | ✓ | — | — | — | ✓ | H |
| `balance.read` | A | ✓ | — | — | — | A | H |
| `event.close` | P | P | P | P | P | P | P |
| `audit.read` | A | A | — | — | — | — | H |

Interpretación de `A`: el permiso debe limitarse al evento, puesto, tipo de reporte o dato operativo que la organización asigne. Por ejemplo, un operador puede consultar el catálogo de su puesto y un responsable de evento puede aprobar el cierre solo si la organización lo delega.

La implementación temporal concede automáticamente solo los permisos `✓`. Los permisos `A` se representan como delegaciones adicionales explícitas y se deniegan si no fueron agregados. `H` exige evento cerrado, asignación histórica activa y rol `HISTORICAL_VIEWER`. `event.close` permanece bloqueado para todos, incluso como delegación adicional, hasta aprobar su regla.

## 5. Reglas especiales y estados

| Situación | Comportamiento obligatorio |
|---|---|
| Evento seleccionado distinto al autorizado | Rechazar sin filtrar datos del evento solicitado; registrar intento relevante. |
| Evento cerrado | Rechazar toda escritura, incluso para `ORG_ADMIN`; conservar `event.read`, reportes, balance, auditoría y exportaciones según permiso. |
| Responsable histórico | Aplicar `HISTORICAL_VIEWER`: consulta y exportación; nunca venta, pago, gasto, asignación, canje, edición o cierre. |
| Usuario con roles combinados | Unir permisos solo dentro de los eventos coincidentes; no ampliar organización ni permitir escritura en eventos cerrados. |
| Venta pública | Aislar por URL pública de organización/evento y devolver solo catálogo/publicación y estado de la propia compra. |
| Cambio de permisos | Requerir `org.manage_members`, registrar actor, fecha, evento, permisos anteriores y nuevos. |

## 6. Decisiones pendientes de validación

- Confirmar si `FINANCE_REVIEWER` y `PAYMENT_REVIEWER` serán roles separados.
- Confirmar si el responsable de evento puede cerrar por sí solo o requiere doble revisión financiera.
- Definir permisos exactos por puesto para operadores de venta y canje.
- Confirmar qué reportes puede exportar cada rol y si las exportaciones pesadas son asíncronas.
- Confirmar duración y revocación de una membresía histórica.
- Confirmar si `ORG_OWNER` y `ORG_ADMIN` tendrán exactamente los mismos permisos organizacionales o si ciertas decisiones comerciales quedarán reservadas al responsable principal.

## 7. Implementación temporal sin base de datos

- `Permission` contiene el catálogo técnico de permisos.
- `EventRole` contiene los roles de evento.
- `RolePermissionMap` define permisos base y unión de roles.
- `AuthorizationPolicy` primero valida alcance y luego permisos.
- `EventAssignment.additionalPermissions` representa delegaciones `A`.
- `InMemoryAccessDirectory` mantiene datos únicamente durante el proceso y no contiene usuarios precargados.

## 8. Criterios para aprobar la matriz

- Cada flujo de venta, pago, ticket, canje, reporte y cierre identifica un permiso y un alcance.
- Se puede demostrar que una organización o evento no es visible fuera del contexto autorizado.
- El cierre convierte todos los roles en consulta/exportación autorizada, sin excepciones implícitas.
- Los permisos pendientes tienen responsable y decisión registrada antes del contrato API definitivo.
