# Hito 0 — Historias y trazabilidad de reglas

**Estado:** propuesta base para revisión
**Versión:** 0.2
**Fecha:** 2026-10-29
**Fuentes:** `docs/REGLAS_DE_NEGOCIO.md`, `docs/PLAN_DE_TRABAJO_KERMESSE.md`

## 1. Convenciones

- Las historias usan el formato `US-H0-###` y son contratos funcionales, no tareas de implementación.
- Prioridad: **P0** bloquea la operación segura; **P1** es necesaria para completar la primera versión.
- La columna “contrato/prueba” identifica el artefacto que deberá comprobar la regla en el siguiente entregable: endpoint/comando (`API-*`), autorización (`AUTH-*`), dominio/transacción (`DOM-*`), reporte (`REP-*`) o carga (`LOAD-*`).

## 2. Matriz de trazabilidad

| Historia | Actor y necesidad | Prioridad | Reglas `RN-*` | Criterio verificable | Contrato/prueba futura |
|---|---|---:|---|---|---|
| `US-H0-001` | Como administrador, quiero separar organizaciones y eventos para que ningún dato se mezcle. | P0 | `RN-ORG-001`, `RN-EVT-001` | Consultas fuera del contexto autorizado no devuelven datos; una venta solo impacta su evento. | `AUTH-ORG-01`, `API-CONTEXT-01`, `DOM-SCOPE-01` |
| `US-H0-002` | Como administrador, quiero asignar permisos por evento y retirar accesos históricos. | P0 | `RN-PER-001`, `RN-PER-002`, `RN-AUD-001` | Un usuario puede administrar, operar o consultar eventos distintos; todo cambio queda auditado. | `AUTH-EVENT-01`, `API-MEMBERSHIP-01` |
| `US-H0-003` | Como responsable, quiero administrar productos y disponibilidad sin alterar ventas pasadas. | P0 | `RN-PRO-001`, `RN-PRO-002` | La última unidad no se vende dos veces y el precio histórico permanece intacto. | `API-CATALOG-01`, `DOM-STOCK-01`, `DOM-PRICE-01` |
| `US-H0-004` | Como responsable, quiero asignar tickets a cursos/grupos sin reconocer una venta automáticamente. | P1 | `RN-CUR-001`, `RN-CUR-002` | El reporte separa asignados, vendidos, pendientes y canjeados; la asignación no suma ingresos ni reduce stock por defecto. | `API-COURSE-01`, `REP-COURSE-01` |
| `US-H0-005` | Como operador, quiero registrar una venta rápida usando solo el correo cuando sea necesario enviar el ticket. | P0 | `RN-VEN-001` | Una venta válida se completa sin nombre ni teléfono; el correo se valida cuando se usa para envío. | `API-SALE-01`, `DOM-SALE-01` |
| `US-H0-006` | Como sistema, quiero confirmar una venta completa o revertirla completamente. | P0 | `RN-VEN-002`, `RN-AUD-001` | No queda venta, pago, disponibilidad, ticket o auditoría parcial si falla la operación. | `DOM-TX-01`, `API-SALE-02` |
| `US-H0-007` | Como operador móvil o web, quiero reintentar sin duplicar la operación. | P0 | `RN-VEN-003`, `RN-AUD-001` | Repetir la misma clave devuelve el resultado inicial y no crea duplicados. | `API-IDEMP-01`, `DOM-IDEMP-01` |
| `US-H0-008` | Como verificador, quiero gestionar estados de pago y comprobantes con trazabilidad. | P0 | `RN-PAG-001`, `RN-AUD-001` | Un comprobante manual permanece por verificar hasta aprobación; los cambios identifican actor y momento. | `API-PAYMENT-01`, `AUTH-PAYMENT-01` |
| `US-H0-009` | Como sistema, quiero coordinar banco/correo sin dejar transacciones abiertas ni datos inconsistentes. | P0 | `RN-PAG-002`, `RN-VEN-002`, `RN-AUD-001` | Si falla el correo, venta y ticket permanecen correctos y queda reintento auditable. | `DOM-EXT-01`, `API-DELIVERY-01` |
| `US-H0-010` | Como comprador/operador, quiero un ticket único con el detalle autorizado. | P0 | `RN-TIC-001` | El identificador y QR son únicos y apuntan a una venta/evento/productos válidos. | `API-TICKET-01`, `DOM-TICKET-01` |
| `US-H0-011` | Como operador de canje, quiero validar un QR una sola vez incluso con escaneos simultáneos. | P0 | `RN-CAN-001`, `RN-VEN-003`, `RN-AUD-001` | Un escaneo confirma el canje y el concurrente informa ticket usado, sin doble entrega. | `API-REDEEM-01`, `DOM-CONCURRENCY-01` |
| `US-H0-012` | Como responsable, quiero registrar inversión y gastos filtrables por evento. | P1 | `RN-GAS-001`, `RN-AUD-001` | Cada registro tiene categoría, importe, fecha y responsable; el reporte filtra por periodo y categoría. | `API-EXPENSE-01`, `REP-EXPENSE-01` |
| `US-H0-013` | Como usuario autorizado, quiero consultar y exportar reportes según mi rol. | P0 | `RN-REP-001`, `RN-PER-001`, `RN-PER-002` | Un operador sin permiso financiero no puede exportar balance/gastos; un histórico solo exporta lo concedido. | `API-REPORT-01`, `AUTH-REPORT-01` |
| `US-H0-014` | Como responsable financiero, quiero ver el balance con fórmula y conceptos separados. | P0 | `RN-BAL-001`, `RN-BAL-002`, `RN-GAS-001` | Se calcula `ingresos netos - costos - gastos`; los aportes no se suman automáticamente como ventas. | `API-BALANCE-01`, `REP-BALANCE-01` |
| `US-H0-015` | Como responsable, quiero revisar pendientes antes de cerrar. | P0 | `RN-CIE-001`, `RN-PAG-001`, `RN-BAL-001` | El sistema presenta validaciones de ventas, pagos, gastos, inventario, tickets, canjes y balance. | `API-CLOSURE-CHECK-01`, `DOM-CLOSURE-01` |
| `US-H0-016` | Como responsable autorizado, quiero cerrar definitivamente un evento y conservar su instantánea. | P0 | `RN-CIE-002`, `RN-AUD-001` | El cierre guarda balance, responsable y fecha; todo comando posterior de modificación es rechazado. | `API-CLOSE-01`, `DOM-CLOSED-01` |
| `US-H0-017` | Como responsable histórico, quiero consultar y exportar un evento cerrado sin modificarlo. | P0 | `RN-CIE-003`, `RN-PER-002`, `RN-REP-001` | El evento cerrado sigue visible según permisos, pero no acepta acciones operativas. | `AUTH-HISTORY-01`, `API-READONLY-01` |
| `US-H0-018` | Como plataforma, quiero limitar solicitudes abusivas sin afectar operaciones dentro del límite. | P0 | `RN-SEG-001`, `RN-SEG-002` | Se aplican límites por capa y contexto; el exceso devuelve `429` con reintento cuando corresponda. | `API-RATE-01`, `LOAD-RATE-01` |
| `US-H0-019` | Como equipo, quiero medir carga sintética en los flujos críticos antes de producción. | P1 | `RN-CAR-001`, `RN-SEG-001`, `RN-SEG-002` | Login, catálogo, ventas, pagos, QR, exportaciones y cierre se prueban sin duplicados, stock negativo ni canjes repetidos. | `LOAD-SCENARIO-01`, `LOAD-INVARIANTS-01` |
| `US-H0-020` | Como operador de plataforma, quiero alojar varias organizaciones con aislamiento estricto. | P0 | `RN-PLT-001`, `RN-ORG-001` | Conocer un identificador de otra organización no permite consultar ni inferir sus datos o archivos. | `AUTH-TENANT-01`, `DOM-TENANT-01` |
| `US-H0-021` | Como organización, quiero comprar o recibir habilitación para operar un evento sin pagar por usuario. | P0 | `RN-COM-001`, `RN-USR-001` | Solo eventos habilitados operan; agregar usuarios no altera el precio y todos conservan permisos por evento. | `API-ENTITLEMENT-01`, `DOM-ORDER-01` |
| `US-H0-022` | Como organización sin eventos activos, quiero consultar y exportar mi historial autorizado. | P0 | `RN-HIS-001`, `RN-PER-002`, `RN-CIE-003` | No comprar otro evento no elimina ni bloquea el acceso histórico permitido. | `AUTH-HISTORY-02`, `API-HISTORY-01` |
| `US-H0-023` | Como organización, quiero dejar preparada una empresa emisora por evento para una futura etapa fiscal. | P1 | `RN-FAC-001`, `RN-FAC-002` | La etapa 1 puede registrar la frontera futura, pero no emite ni presenta documentos como facturas fiscales. | `DOM-TAX-BOUNDARY-01`, `API-TAX-CONFIG-FUTURE` |

## 3. Cobertura por regla

| Regla | Historias | Cobertura inicial |
|---|---|---|
| `RN-ORG-001` / `RN-EVT-001` | `US-H0-001` | Contexto compuesto definido en OpenAPI; primer endpoint lo verifica en ruta, autorización y persistencia. |
| `RN-PER-001` / `RN-PER-002` | `US-H0-002`, `US-H0-013`, `US-H0-017` | Roles y autorización histórica implementados temporalmente en memoria; faltan validar delegaciones, cierre y vigencias. |
| `RN-AUD-001` | `US-H0-002`, `US-H0-006` a `US-H0-009`, `US-H0-011` a `US-H0-013`, `US-H0-016` | Parcial: falta catálogo final de eventos auditables. |
| `RN-PRO-001` / `RN-PRO-002` | `US-H0-003` | Completa para disponibilidad y precio histórico. |
| `RN-CUR-001` / `RN-CUR-002` | `US-H0-004` | Completa para semántica de asignación; falta decidir reserva opcional. |
| `RN-VEN-001` a `RN-VEN-003` | `US-H0-005` a `US-H0-007` | Contrato de venta e idempotencia definido; infraestructura idempotente probada, módulo de venta aún planificado. |
| `RN-PAG-001` / `RN-PAG-002` | `US-H0-008`, `US-H0-009` | Parcial: falta proveedor y flujo definitivo de QR bancario. |
| `RN-TIC-001` / `RN-CAN-001` | `US-H0-010`, `US-H0-011` | Completa para unicidad y concurrencia propuesta. |
| `RN-GAS-001` | `US-H0-012`, `US-H0-014` | Completa para registro y balance, pendiente criterio contable. |
| `RN-REP-001` | `US-H0-013`, `US-H0-017` | Parcial: falta catálogo final de reportes por rol. |
| `RN-BAL-001` / `RN-BAL-002` | `US-H0-014`, `US-H0-015` | Parcial: fórmula aprobada; reconocimiento contable pendiente. |
| `RN-CIE-001` a `RN-CIE-003` | `US-H0-015` a `US-H0-017` | Completa como flujo; falta aprobar quién puede cerrar. |
| `RN-SEG-001` / `RN-SEG-002` | `US-H0-018`, `US-H0-019` | Parcial: valores de límites dependen de capacidad. |
| `RN-CAR-001` | `US-H0-019` | Completa como escenario; cifras objetivo pendientes. |
| `RN-PLT-001` | `US-H0-020` | Completa como requisito de aislamiento; falta contrato técnico del tenant. |
| `RN-COM-001` / `RN-USR-001` | `US-H0-021` | Parcial: faltan precio, activación, cancelación y devolución. |
| `RN-HIS-001` | `US-H0-022` | Parcial: acceso aprobado; falta política de baja y retención. |
| `RN-FAC-001` / `RN-FAC-002` | `US-H0-023` | Diferida a etapa 2; la etapa 1 solo conserva la frontera del módulo. |

## 4. Vacíos que bloquean los siguientes contratos

1. Aprobar la matriz de roles y los permisos con alcance adicional.
2. Confirmar criterios contables para costos, mermas, sobrantes y saldos.
3. Elegir el canal de QR bancario y definir qué estados llegan automáticamente.
4. Definir destinatario, reintentos y retención de correos/tickets.
5. Acordar cifras de capacidad para convertir `LOAD-*` en objetivos medibles.
6. Definir compra, activación, cancelación, devolución y reprogramación por evento.
7. Definir baja, exportación y retención del historial de una organización.

Hasta resolver estos puntos, la trazabilidad sirve como base de descubrimiento y no debe usarse para generar todavía los proyectos Symfony, Angular o Flutter.
