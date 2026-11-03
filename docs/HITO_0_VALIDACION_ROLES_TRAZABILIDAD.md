# Hito 0 — Validación de matriz de roles y trazabilidad

> **Validación histórica:** cubre la versión 0.1 con 26 reglas y 19 historias. Desde el 2026-10-29 existen nuevas reglas de plataforma, comercialización, usuarios, historial y facturación futura; se requiere una nueva validación de la versión 0.2.

**Fecha:** 2026-10-27 · **Revisor:** Claude · **Alcance:** `HITO_0_MATRIZ_ROLES_PERMISOS.md` v0.1 y `HITO_0_TRAZABILIDAD_REGLAS.md` v0.1 contra `REGLAS_DE_NEGOCIO.md`.

## Resultado

Revisión documental. No sustituye la aprobación de la organización. Las dos propuestas son coherentes en estructura, con los huecos de abajo por cerrar antes del contrato OpenAPI.

## Verificado

- Las 26 reglas `RN-*` de `REGLAS_DE_NEGOCIO.md` existen y las 26 aparecen al menos una vez en la trazabilidad; no hay reglas huérfanas ni IDs inventados.
- Las 19 historias tienen prioridad, criterio verificable y contrato/prueba futura.
- Los permisos de la matriz cubren venta, pago, canje, gasto, reporte, balance, cierre y auditoría.
- Cierre de evento: escritura denegada incluso a `ORG_ADMIN` (sección 5), alineado con `RN-CIE-003` y `US-H0-016/017`.

## Huecos y contradicciones a resolver

**Actualización 2026-10-30:** el hallazgo 1 quedó resuelto con `catalog.read` para `EVENT_MANAGER` y `SALES_OPERATOR`, tanto en la matriz como en el catálogo persistido. Los demás hallazgos siguen abiertos.

| # | Hallazgo | Impacto | Propuesta |
|---|---|---|---|
| 1 | No existe `catalog.read` interno: `SALES_OPERATOR` vende (`sale.write`) pero ningún permiso le da ver el catálogo ni la disponibilidad (`US-H0-005`). | Bloquea API de venta rápida. | Añadir `catalog.read` (evento/puesto) a operadores de venta y gestión. |
| 2 | Reenvío de ticket/correo: `US-H0-009` exige reintento auditable, pero no hay permiso interno (`ticket.resend`). Solo existe `ticket.resend.own` público. | Reintentos sin autorización definida. | Añadir `ticket.resend` o incluirlo en `ticket.read`+`sale.write`. |
| 3 | Comprador público sin historia: `preorder.write`, `payment.status.read.own`, `catalog.read.public` no tienen `US-H0-*`. | Endpoints públicos sin criterio ni prueba. | Añadir historias para preventa pública y consulta de su compra. |
| 4 | `FINANCE_REVIEWER` tiene `event.close` ✓ y `payment.review` ✓ aunque la sección 6 deja abierto si cierra solo y si se separa de `PAYMENT_REVIEWER`. | Decisión pendiente ya codificada como ✓. | Marcar ambos `A` hasta decidir; documentar doble revisión. |
| 5 | `RN-AUD-001` sin catálogo de eventos auditables (ya marcado parcial). Cambios de permisos, intentos fuera de alcance y cierre deben listarse. | Sin criterio de aceptación de auditoría. | Crear lista de eventos auditables antes de `API-*`. |
| 6 | `org.manage_members` solo `ORG_ADMIN`; no queda claro quién asigna miembros a nivel evento (`US-H0-002`). | Ambigüedad de delegación. | Confirmar si es solo organización o delegable por evento. |
| 7 | Permisos `A` (11 celdas) sin regla de alcance concreta por puesto/reporte. | Imposible traducir a políticas. | Tabla de alcance por rol (puesto, tipo de reporte). |
| 8 | Criterio 1 usa `organización + evento + usuario + acción`; la matriz no define ámbito de `HISTORICAL_VIEWER` ni duración/revocación (pendiente en sección 6). | Acceso histórico sin caducidad. | Definir vigencia y revocación. |

## Revalidación v0.2 → v0.3 (2026-11-03)

Revisión documental contra las reglas `RN-PLT`, `RN-COM`, `RN-USR`, `RN-HIS` y `RN-FAC` (2026-10-29). No sustituye la aprobación de la organización.

| # | Estado | Detalle |
|---|---|---|
| 1 | Resuelto (2026-10-30) | `catalog.read` en matriz y catálogo persistido. |
| 2 | Aplicado en v0.3 | Nuevo `ticket.resend`; `EVENT_MANAGER` ✓, `SALES_OPERATOR` `A`. Falta código y OpenAPI. |
| 3 | Aplicado en v0.3 | Historias `US-H0-024` a `US-H0-026` para catálogo público, estado de compra y reenvío propio. |
| 4 | Parcial | `event.close` ya estaba en `P`; `payment.review` pasó a `A` hasta decidir si se separan los roles. Falta decidir quién cierra. |
| 5–8 | Abiertos | Sin cambio; 6 y 8 requieren decisión de la organización. |

Hallazgos nuevos de esta revalidación:

| # | Hallazgo | Impacto | Propuesta |
|---|---|---|---|
| 9 | `RN-COM-001` exige habilitación comercial para vender y canjear, pero ningún permiso ni rol la gestiona. | Bloquea el flujo de alta de eventos. | Decidir quién la compra o solicita y crear su permiso. |
| 10 | `RN-USR-001` pide suspender usuarios sin perder auditoría; la matriz solo define revocar. | Estado de usuario sin regla de autorización. | Documentar suspensión como cambio auditado bajo `org.manage_members`. |
| 11 | `RN-HIS-001`: la política de baja y retención sigue abierta y cruza con el hallazgo 8. | Acceso histórico sin caducidad. | Resolver 8 junto con la política de retención. |
| 12 | Cambio de comportamiento: `RolePermissionMap` concede `payment.review` de base a `FINANCE_REVIEWER` y `PAYMENT_REVIEWER`, pero la matriz v0.3 lo marca `A`. | Documento y código divergen. | Alinear el código tras la aprobación; hasta entonces el código manda. |

`RN-PLT-001` (sin rol de soporte global) y `RN-FAC-*` (sin permisos en la etapa 1) son coherentes con la matriz sin cambios.

## Bloqueos que persisten (de la trazabilidad §4)

Criterios contables, canal de QR bancario, política de correos/tickets y cifras de capacidad siguen sin resolver; ninguno depende de los hallazgos de arriba.

## Siguiente paso

Obtener decisión de la organización sobre 4, 6, 8, 9 y quién cierra el evento; luego alinear `RolePermissionMap`, `Permission` y OpenAPI con la matriz v0.3.
