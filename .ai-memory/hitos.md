# Hoja de ruta por hitos

## Estado global

La dirección de producto es una plataforma centralizada multi-organización con cobro por evento, usuarios sin cobro por asiento e historial permanente autorizado. Las propuestas de instalación individual quedaron sustituidas. No se debe iniciar una pantalla conectada, una integración de pago ni una migración de base de datos hasta completar el Hito 0.

Documentos de detalle:

- `docs/PLAN_DE_TRABAJO_KERMESSE.md`: plan técnico completo y criterios de verificación.
- `docs/REGLAS_DE_NEGOCIO.md`: reglas funcionales obligatorias y criterios de aceptación.
- `docs/MODELO_COMERCIAL_PLATAFORMA.md`: modelo comercial vigente; las propuestas de Saint Andrew's son antecedentes históricos.
- `prototype/index.html`: prototipo navegable de referencia; no es todavía la aplicación.

## Hito 0 — Descubrimiento, contratos y capacidad

**Estado:** en ejecución documental; modelo conceptual de identidad/acceso, matriz de roles/permisos y trazabilidad inicial preparados, pendientes de validación.

**Objetivo:** convertir el alcance aprobado en contratos verificables antes de crear los proyectos Symfony, Angular y Flutter.

**Entregables obligatorios:**

1. Historias priorizadas y trazabilidad con las reglas `RN-*`.
2. Matriz de roles y permisos por organización y evento, incluyendo consulta histórica.
3. Contrato OpenAPI inicial: autenticación, contexto de organización/evento, errores, paginación, filtros, comandos de escritura, idempotencia y auditoría.
4. Matriz de operaciones de escritura: comando, autorización, transacción, efectos, clave de idempotencia, concurrencia y auditoría.
5. Perfil de capacidad: organizaciones, eventos simultáneos, operadores y compradores concurrentes, ventas/minuto, escaneos/minuto, exportaciones y tiempos esperados.
6. Decisión documentada de motor de base de datos, hosting, correo y tipo de QR bancario.
7. Contrato comercial por evento: orden, habilitación, cancelación, devolución, reprogramación e historial.

**Criterio de cierre:** ningún flujo de venta, ticket, pago, canje, reporte o cierre necesita reglas o endpoints sin definir. Las decisiones externas pendientes se registran con responsable y fecha de resolución.

**Riesgos a resolver:** reglas contables específicas, API real del banco para QR, capacidad de la plataforma centralizada, límites de solicitudes y política comercial por evento.

**Avance 2026-10-27:** se crearon `docs/HITO_0_MATRIZ_ROLES_PERMISOS.md` y `docs/HITO_0_TRAZABILIDAD_REGLAS.md`. Son propuestas v0.1 y no sustituyen la aprobación de la organización ni completan todavía el contrato OpenAPI, la matriz de escrituras, capacidad o servicios externos.

**Avance 2026-10-29:** se creó `docs/HITO_0_MODELO_IDENTIDAD_ACCESO.md` para separar identidad, vinculación organizacional y asignación por evento. No incluye persistencia; la base de datos queda para el paso 3.

**Implementación temporal 2026-10-29:** `backend/src/Identity` incorpora objetos de dominio, `AccessScopePolicy` y `InMemoryAccessDirectory` sin datos precargados ni conexión de base de datos. Las pruebas cubren multi-organización, multi-evento, revocación y acceso histórico; los permisos finos quedan para el paso 2.

**Paso 2 temporal 2026-10-29:** se incorporaron catálogo de permisos, roles de evento, unión de roles, delegaciones explícitas y `AuthorizationPolicy`. La matriz v0.2 separa gobierno de organización y operación de eventos. Continúa sin base de datos ni autenticación real.

**Paso 3 diseñado 2026-10-30:** `docs/HITO_0_DISENO_PERSISTENCIA_IDENTIDAD.md` y `docs/MODELO_DATOS_ER.md` v0.3 definen membresías, roles, delegaciones, invitaciones y transacciones. El motor productivo sigue pendiente y el cierre se difiere.

**Paso 3 local ejecutado 2026-10-30:** se detectó MariaDB 10.4.18, se creó `kermesfera` y se ejecutaron ocho migraciones. Repositorios Doctrine y comandos transaccionales cubren lectura, asignación, cambio de roles, revocaciones, invitaciones y transferencia de propietario. La idempotencia persistente rechaza cargas divergentes y una prueba concurrente demuestra un solo efecto ante dos solicitudes simultáneas; autenticación real y motor productivo continúan pendientes.

**Contrato/API inicial 2026-10-30:** `docs/openapi.yaml` v0.2 y `docs/HITO_0_MATRIZ_OPERACIONES_ESCRITURA.md` definen autenticación Bearer, contexto, errores, paginación, filtros, idempotencia, concurrencia y auditoría. Asignación, cambio de roles, revocación de acceso de evento/organización, transferencia de propietario, creación y aceptación pública de invitaciones están implementados de extremo a extremo. JWT Bearer HS256 valida firma y claims estrictos; la emisión se delega al proveedor futuro. La API limita solicitudes mediante contadores persistentes por usuario/IP y acción. Gastos y trabajos de exportación PDF/XLSX ya tienen contratos precisos en estado `planned`. La entrega usa outbox, token HMAC con rotación multiclave, lease y reintentos; Hostinger SMTP 465/TLS quedó elegido y se configurará al final sin secretos en Git. Las demás rutas operativas siguen `planned` y `event.close` permanece `blocked`.

## Hito 1 — Fundación y arquitectura compartida

**Estado:** bloqueado hasta cerrar el Hito 0.

**Objetivo:** crear los tres proyectos con una base coherente, segura y comprobable.

**Entregables obligatorios:**

1. Backend Symfony 7.4 LTS / PHP 8.2.35 como monolito modular por capas `Domain`, `Application`, `Infrastructure` y `Api`.
2. Angular con rutas por funcionalidad, cliente generado desde OpenAPI, diseño responsive y manejo centralizado de autenticación/errores.
3. Flutter por funcionalidades, sesión segura, cliente API, conectividad y diseño de teléfono/tableta.
4. Módulos base: Organización, Identidad y permisos, Evento y Auditoría.
5. Patrón de comandos transaccionales probado. Cada escritura confirma todo o revierte todo; no hay ventas, tickets, pagos, canjes ni cierres parciales.

**Criterio de cierre:** los tres proyectos ejecutan pruebas básicas, consumen un endpoint de salud y demuestran separación entre organizaciones y permisos por evento.

## Hito 2 — Configuración operativa del evento

**Estado:** pendiente; depende del Hito 1.

**Objetivo:** permitir preparar un evento antes de vender.

**Entregables obligatorios:** responsables y roles por evento; productos, precios y disponibilidad; costos, gastos; cursos/grupos y asignaciones; auditoría; panel Angular responsive y consulta operativa desde Flutter.

**Criterio de cierre:** una organización configura un evento completo sin mezclar datos con otro. Las asignaciones por curso no son venta, ingreso ni descuento automático de disponibilidad salvo configuración explícita aprobada.

## Hito 3 — Ventas, pagos, tickets y canje

**Estado:** pendiente; depende de los Hitos 1 y 2.

**Objetivo:** operar una kermesse de forma rápida y segura durante la venta y entrega.

**Entregables obligatorios:** venta rápida con correo como dato mínimo; preventa pública por URL de organización/evento; pagos pendientes/verificados/confirmados; QR bancario o comprobante manual según integración; ticket QR por correo; lectura y canje desde Flutter; reintentos seguros, idempotencia y concurrencia.

**Criterio de cierre:** no se duplican ventas, pagos, tickets ni canjes ante reintentos o simultaneidad, y la disponibilidad no queda negativa.

## Hito 4 — Reportes, balance y cierre

**Estado:** pendiente; depende del Hito 3.

**Objetivo:** entregar transparencia financiera y conservar el historial final de cada evento.

**Entregables obligatorios:** reportes de ventas, cobros, gastos, disponibilidad, asignaciones por curso, tickets/canjes y auditoría; exportación PDF/Excel según permiso; balance por evento; cierre transaccional con instantánea, responsable y fecha.

**Criterio de cierre:** un evento cerrado queda disponible para consulta y exportación autorizada, pero bloquea ventas, pagos, gastos, canjes, asignaciones y toda edición desde API, web y móvil.

## Hito 5 — Seguridad, carga y producción

**Estado:** pendiente; depende de los Hitos 1 a 4 y de la infraestructura central de la plataforma.

**Objetivo:** validar que el sistema permanece consistente y disponible bajo uso real.

**Entregables obligatorios:** ambientes, respaldos, monitoreo, límites en proxy y Symfony, pruebas unitarias/integración/contrato/interfaz, pruebas de carga, piloto, capacitación y procedimiento de despliegue/reversión.

**Criterio de cierre:** la carga acordada no produce duplicados, stock negativo ni canjes repetidos; hay restauración de respaldo probada y piloto aprobado.

## Reglas transversales que no se pueden omitir

- La etapa 1 no emite facturas fiscales. La etapa 2 permitirá asociar una entidad emisora con NIT a eventos habilitados para facturación.
- PostgreSQL es la recomendación; con ORM puede usarse MySQL u otro motor compatible si el hosting lo requiere. El motor se elige antes del esquema productivo.
- Las llamadas a banco, correo u otros proveedores no mantienen transacciones de base de datos abiertas.
- Las credenciales, comprobantes sensibles y bases automáticas de agentes no se guardan en Git. La memoria compartida es Markdown dentro de `.ai-memory/`.
- Todo cambio a reglas de importes, pagos, tickets, disponibilidad, permisos, balance, cierre o datos históricos necesita aprobación de la organización y actualización de `docs/REGLAS_DE_NEGOCIO.md` y `decisions.md`.

## Etapa 2 — Facturación por empresa emisora

**Estado:** considerada y diferida; no implementar en la etapa 1.

**Objetivo:** permitir que una organización registre empresas emisoras con NIT y asocie una a cada evento que requiera facturación, manteniendo aislamiento de numeración, documentos e integración tributaria.
