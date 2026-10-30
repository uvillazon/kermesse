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

## Bloqueos que persisten (de la trazabilidad §4)

Criterios contables, canal de QR bancario, política de correos/tickets y cifras de capacidad siguen sin resolver; ninguno depende de los hallazgos de arriba.

## Siguiente paso

Aplicar los hallazgos 1–4 a la matriz (v0.2), obtener decisión de la organización sobre 4, 6 y 8, y luego abordar OpenAPI.
