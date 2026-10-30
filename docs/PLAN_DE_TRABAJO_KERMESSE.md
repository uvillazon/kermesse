# Plan de trabajo Kermesse

## Objetivo principal

Entregar una plataforma centralizada multi-organización, comercializada por evento y sin cobro por cantidad de usuarios, para administrar kermesses desde el registro rápido de ventas y el canje de tickets hasta el balance contable, historial y cierre de cada evento.

## Alcance y orden de ejecución

El trabajo se divide en cuatro proyectos que comparten contratos de API y un modelo de datos: backend Symfony, administración y portal web Angular, aplicación Flutter y plataforma de calidad/operación. El orden obligatorio es: fundamento y contratos, backend de núcleo, frontend web, aplicación móvil, integraciones y finalmente pruebas de carga y salida a producción. Un proyecto puede avanzar en maquetas y contratos mientras el anterior termina, pero no se implementará una pantalla conectada antes de que su API y sus reglas de negocio estén aprobadas.

## Reglas técnicas transversales

- El backend será un monolito modular en Symfony 7.4 LTS con PHP 8.2.35. Los módulos iniciales son Organización, Identidad y permisos, Evento, Catálogo, Costos y gastos, Venta y pago, Tickets y canje, Cursos y grupos, Reportes, Cierre y Auditoría.
- Cada módulo seguirá capas `Domain`, `Application`, `Infrastructure` y `Api`. Los controladores solo validan la petición, autorizan y delegan a un comando o consulta; no contendrán reglas de negocio ni llamadas directas a `flush()`.
- Toda petición que cree o modifique datos usará un comando de aplicación y una única transacción que delimite la operación completa. Se utilizará `EntityManager::wrapInTransaction()` o `Connection::transactional()` cuando existan operaciones DBAL. Si ocurre una excepción, se revierte todo; si termina correctamente, se confirma todo. No se permitirá persistir una parte de una venta, ticket, pago, canje o cierre.
- Los comandos que puedan repetirse por una mala conexión o reintento móvil usarán una clave de idempotencia. Ventas, pagos, canjes, reservas de stock y asignaciones de tickets incluirán además una estrategia de concurrencia y bloqueo para evitar duplicados o sobreventa.
- La organización y el evento activo serán obligatorios en cada consulta o comando. Ninguna consulta devolverá datos de otra organización o de un evento sin permiso.
- El panel Angular será responsive desde el inicio: escritorio, tableta y celular. Flutter se enfocará en operación de campo, lectura QR, ventas rápidas y consulta/exportación permitida.
- PostgreSQL es la base recomendada. El ORM permitirá ejecutar el sistema sobre MySQL u otro motor compatible si el servidor lo requiere; el motor se elegirá antes de crear el esquema productivo. Un cambio posterior requerirá una migración, revisión de tipos e índices y pruebas completas.
- Los límites de peticiones tendrán dos capas: límite de bajo costo en el proxy o servidor web para proteger el proceso PHP y Symfony Rate Limiter para límites por IP, usuario, correo, evento o acción sensible. Una petición rechazada devolverá HTTP 429 con información de reintento cuando corresponda.
- Las cifras finales de capacidad se acordarán en el Hito 0 según cantidad máxima de organizaciones, eventos simultáneos, operadores, compradores web y escaneos por minuto. No se fijarán valores de producción sin esta estimación.
- Los cobros de la plataforma por habilitar eventos estarán separados de los pagos y balances internos de una kermesse.
- La facturación fiscal de ventas se reserva para una segunda etapa mediante una entidad emisora con NIT asociable al evento; no se implementará en la etapa 1.

## Fase 0 — Descubrimiento, contratos y capacidad

**Cómo sirve al objetivo principal:** define las reglas de negocio y los límites que mantienen las ventas, tickets y cierres correctos aun cuando muchos usuarios trabajen al mismo tiempo.

### Qué implementar

1. Convertir el alcance aprobado en historias priorizadas: organización, eventos, responsables, permisos, productos, inversión, gasto, venta rápida, preventa, pago, ticket, curso, canje, reporte, balance y cierre.
2. Acordar el mapa de roles y permisos por organización y evento, incluidos usuarios históricos en modo consulta.
3. Definir el contrato OpenAPI inicial: autenticación, respuesta de error, paginación, filtros, idempotencia de comandos y eventos de auditoría.
4. Definir el perfil de carga: número de operadores concurrentes, compradores concurrentes, ventas/minuto, escaneos/minuto, tamaño esperado de reportes y tiempo máximo aceptable de respuesta.
5. Elegir motor de base de datos, proveedor de hosting, correo y mecanismo bancario de QR; registrar credenciales como variables de entorno, nunca en Git.

### Referencias documentales

- [Doctrine ORM — Transactions and Concurrency](https://www.doctrine-project.org/projects/doctrine-orm/en/current/reference/transactions-and-concurrency.html): límites de transacción, `wrapInTransaction()` y `transactional()`.
- [Symfony Rate Limiter](https://symfony.com/doc/current/rate_limiter.html): políticas `fixed_window`, `sliding_window`, `token_bucket`, respuesta 429 y almacenamiento de límites.
- [Angular Style Guide](https://angular.dev/style-guide): convenciones de nombres y estructura consistente.
- [Flutter App Architecture Guide](https://docs.flutter.dev/app-architecture/guide): separación UI, datos y casos de uso complejos.

### Verificación

- El OpenAPI contiene todos los comandos de escritura y ninguna pantalla requiere un endpoint no definido.
- Existe una matriz de roles por evento y una tabla de capacidad acordada.
- Cada operación de escritura tiene definido propietario, efectos, clave de idempotencia, transacción y auditoría.

### Guardas contra errores

- No iniciar desarrollo de pagos ni cierre contable con reglas de negocio ambiguas.
- No usar límite de Symfony como única defensa frente a tráfico abusivo; la documentación advierte que el proceso PHP ya debe estar en ejecución.
- No guardar datos de cuenta, contraseñas, tokens o comprobantes sensibles en el repositorio.

## Hito 1 — Fundación del repositorio y arquitectura compartida

**Cómo sirve al objetivo principal:** establece una base mantenible para que backend, web y móvil evolucionen sin duplicar reglas ni romper integraciones.

### Proyecto backend Symfony

1. Crear el proyecto Symfony, configuración por entorno, pruebas, análisis estático, migraciones y contenedor local de desarrollo.
2. Crear módulos y capas; registrar convenciones de comandos, consultas, DTO, validadores, excepciones de dominio y respuestas API.
3. Crear entidades base: `Organization`, `Event`, `User`, `EventMembership`, `Role`, `AuditLog` y sus restricciones de organización/evento.
4. Implementar autenticación, autorización por evento, trazabilidad y el contexto de organización/evento obligatorio.
5. Crear una abstracción `TransactionalCommandHandler` o equivalente y pruebas que fallen si un comando de escritura ejecuta un `flush()` fuera del límite transaccional.

### Proyecto web Angular

1. Crear aplicación Angular con rutas lazy por dominio, biblioteca de UI compartida, cliente API tipado generado desde OpenAPI, interceptores de autenticación y errores.
2. Definir capas por funcionalidad: `core`, `shared` y `features/<dominio>`, con componentes presentacionales y servicios de estado por funcionalidad.
3. Implementar sistema de diseño responsive, puntos de corte y accesibilidad base.

### Proyecto Flutter

1. Crear aplicación por funcionalidades con capas `presentation`, `data` y `domain` cuando la complejidad lo justifique.
2. Configurar cliente API, almacenamiento seguro de sesión, manejo centralizado de errores, conectividad y reintentos seguros.
3. Crear diseño adaptable de teléfono y tableta, sin duplicar reglas del backend.

### Verificación

- Los tres proyectos compilan, ejecutan pruebas básicas y consumen un endpoint de salud versionado.
- Un usuario de una organización no puede consultar ni modificar datos de otra organización.
- Un comando de prueba que falle a mitad de una operación revierte todas sus inserciones.

### Guardas contra errores

- No poner reglas de precio, disponibilidad, pago, rentabilidad o cierre solo en Angular o Flutter.
- No permitir que controladores o widgets escriban directamente en la base de datos o construyan SQL.
- No crear rutas globales que omitan el evento activo o la autorización.

## Hito 2 — Núcleo operativo del evento

**Cómo sirve al objetivo principal:** permite preparar un evento completo con responsables, productos, costos, cursos y permisos antes de vender.

### Proyecto backend Symfony

1. Implementar comandos transaccionales para crear/editar organización, evento, responsables, roles, productos, cantidades, precios, costos, gastos, cursos y asignaciones de tickets.
2. Crear índices y restricciones únicas para organización, evento, códigos QR, tickets y claves de idempotencia.
3. Aplicar bloqueo o control de versión donde varias operaciones puedan competir por el mismo inventario o asignación.
4. Registrar auditoría de creación, modificación, aprobación y cancelación.

### Proyecto web Angular

1. Construir el panel responsive de organización, selector de evento, responsables y permisos.
2. Construir formularios de productos, costos, gastos, cursos y asignación de tickets con validación de negocio recibida desde API.
3. Mostrar claramente el evento seleccionado y el alcance del usuario; adaptar tablas a tarjetas y filtros en celular/tableta.

### Proyecto Flutter

1. Mostrar eventos y puestos permitidos al operador.
2. Permitir consulta de catálogo y asignaciones de tickets aprobadas; no habilitar cambios fuera de los permisos.

### Verificación

- Dos responsables pueden tener permisos diferentes sobre el mismo evento.
- Un usuario histórico solo puede consultar el evento autorizado.
- Una falla durante la creación de un producto con sus costos asociados deja la base en el estado anterior.
- La interfaz se valida en anchos de escritorio, tableta y celular.

### Guardas contra errores

- No descontar stock cuando solo se asignan tickets a un curso; asignar no equivale a vender ni cobrar.
- No borrar auditorías ni permitir modificaciones de otra organización.

## Hito 3 — Ventas, pagos, tickets y canje

**Cómo sirve al objetivo principal:** convierte la preparación del evento en una operación de venta rápida, confiable y verificable el día de la kermesse.

### Proyecto backend Symfony

1. Implementar `CreateSale`, `ConfirmPayment`, `IssueTicket`, `ValidateTicket` y `RedeemTicket` como comandos transaccionales e idempotentes.
2. Registrar comprador opcional: nombre e información adicional serán opcionales; el correo puede ser el único dato de contacto. Validar correo solo cuando se use para envío de ticket.
3. Mantener la venta, pago, ticket, reserva/descuento de disponibilidad y auditoría dentro de límites transaccionales definidos. Si falla el correo, la venta no se revierte: se registra el envío pendiente para reintento seguro.
4. Incorporar QR de pago y QR de canje como conceptos distintos; validar comprobantes manuales cuando el proveedor no entregue confirmación automática.
5. Proteger disponibilidad y canje contra carreras: el mismo ticket no podrá canjearse dos veces y la cantidad vendida no podrá superar la disponible.
6. Configurar límites por IP para web pública, por correo para solicitudes sensibles, por usuario para operadores y por acción para exportaciones, QR y autenticación. Definir límites definitivos con resultados de carga.

### Proyecto web Angular

1. Crear flujo de venta rápida con teclado, búsqueda y pocos campos; permitir correo solamente o datos adicionales del comprador.
2. Crear preventa pública por URL de organización/evento, carrito, estado de pago y reenvío autorizado de ticket.
3. Crear gestión de comprobantes, ventas pendientes, tickets por curso y estados de canje.

### Proyecto Flutter

1. Crear venta rápida para operadores autorizados.
2. Integrar lectura QR, vista de productos a entregar, confirmación de canje y feedback claro cuando el ticket ya fue usado o no es válido.
3. Guardar únicamente borradores de interfaz cuando haya pérdida de conexión; confirmar la operación en servidor antes de marcarla como exitosa.

### Verificación

- Repetir el mismo comando con la misma clave de idempotencia no duplica una venta ni un ticket.
- Simular dos canjes simultáneos del mismo QR: uno se confirma y el otro falla sin entregar dos veces.
- Simular dos ventas del último producto: solo una se confirma.
- Los límites devuelven 429 y no bloquean operaciones válidas dentro del umbral acordado.

### Guardas contra errores

- No descontar stock en el navegador ni confiar en el estado local del móvil como fuente final.
- No usar una transacción larga mientras se espera respuesta del banco, correo o proveedor externo.
- No bloquear el proceso PHP esperando tokens del rate limiter para operaciones de usuario.

## Hito 4 — Reportes, balance y cierre

**Cómo sirve al objetivo principal:** permite a la organización medir el resultado del evento, conservar el historial y cerrar su operación sin que cambie después.

### Proyecto backend Symfony

1. Implementar consultas de ventas, cobros, conciliación, inversión, gastos, disponibilidad, tickets por curso, canjes, auditoría y balance por evento.
2. Implementar balance: ingresos netos reconocidos, costos reconocidos, gastos operativos, resultado contable y margen sobre ingresos. Los criterios contables se configurarán y aprobarán con la organización.
3. Generar exportaciones en segundo plano cuando el tamaño lo requiera; no mantener una petición HTTP abierta para reportes pesados.
4. Implementar `CloseEvent` como comando transaccional: verificar pendientes, guardar una instantánea del balance, registrar responsable y fecha, cambiar estado a `closed` y bloquear comandos de modificación.
5. Mantener lecturas y exportaciones autorizadas para eventos cerrados; toda modificación deberá fallar de forma consistente en web, móvil y API.

### Proyecto web Angular

1. Crear reportes con filtros, totales, descarga PDF/Excel y estados de generación.
2. Crear pantalla de balance con separación de ingresos, costos, gastos, resultado y margen; indicar si es preliminar o final.
3. Crear asistente de cierre con lista de pendientes, confirmación explícita y pantalla de consulta posterior.

### Proyecto Flutter

1. Permitir consulta y exportación autorizada de reportes resumidos.
2. Mostrar estado de evento cerrado y deshabilitar toda acción de registro, pago, canje o edición.

### Verificación

- Un evento cerrado conserva reportes y auditoría, pero rechaza toda operación de escritura.
- El balance concilia con el detalle de ventas, costos y gastos de prueba.
- Las exportaciones respetan organización, evento y permisos del usuario.

### Guardas contra errores

- No calcular rentabilidad sumando fondos aportados como si fueran ingresos.
- No permitir reapertura informal desde una actualización directa de base de datos; si se requiere, deberá existir un proceso de corrección auditado definido por negocio.

## Hito 5 — Seguridad, pruebas de carga y salida a producción

**Cómo sirve al objetivo principal:** prueba que la plataforma seguirá disponible y consistente cuando muchos usuarios compren, vendan y escaneen a la vez.

### Proyecto de plataforma y calidad

1. Preparar ambientes local, pruebas y producción con variables de entorno, migraciones controladas, respaldos, registros estructurados, monitoreo y alertas básicas.
2. Configurar proxy/servidor web con límites de bajo costo y cache estático; mantener los límites funcionales en Symfony con almacenamiento compartido si se usan varias instancias.
3. Construir suite de pruebas: unitarias de dominio, integración de repositorios y transacciones, contrato API, componentes Angular, widget/unitarias Flutter y recorridos críticos de venta/canje.
4. Preparar pruebas de carga reproducibles para login, catálogo público, venta rápida, confirmación de pago, lectura QR, exportaciones y cierre. Ejecutar primero en pruebas, nunca contra producción.
5. Medir tiempo de respuesta, tasa de errores, saturación de CPU/memoria, conexiones de base de datos, bloqueos, uso de cache y rechazos 429. Ajustar índices, pool, límites y capacidad antes de aprobar producción.
6. Ejecutar piloto con responsables, capacitar usuarios, cargar datos iniciales, hacer respaldo previo y definir reversión de despliegue.

### Referencias documentales

- [Symfony Rate Limiter](https://symfony.com/doc/current/rate_limiter.html): los límites de Symfony no sustituyen la protección del proxy frente a denegación de servicio.
- [Angular Testing Overview](https://angular.dev/guide/testing): pruebas de componentes, servicios, rutas y cobertura.
- [Flutter Testing Overview](https://docs.flutter.dev/testing/overview): pruebas unitarias, de widgets e integración.

### Verificación

- La prueba de carga cumple el objetivo acordado en Fase 0 sin ventas duplicadas, stock negativo ni canjes repetidos.
- Existen métricas y alertas para errores, saturación y límites de solicitudes.
- Se realiza una restauración de respaldo en un ambiente de prueba y un simulacro de despliegue/reversión.
- El piloto reproduce los flujos de un evento real y deja una lista priorizada de ajustes antes de producción.

### Guardas contra errores

- No ejecutar pruebas de carga contra producción ni usar compradores reales en datos de prueba.
- No escalar servidores antes de corregir consultas lentas, falta de índices o transacciones demasiado largas.

## Hitos de aceptación y dependencias

| Hito | Resultado aceptable | Depende de |
|---|---|---|
| 0 | Reglas, OpenAPI, roles, capacidad y servicios externos definidos | Alcance aprobado |
| 1 | Tres proyectos creados, autenticación/permiso y patrón transaccional probado | Hito 0 |
| 2 | Un evento se puede configurar con responsables, productos, costos, cursos y permisos | Hito 1 |
| 3 | Venta, pago, ticket y canje funcionan sin duplicados bajo concurrencia | Hitos 1 y 2; acceso al proveedor de pago si aplica |
| 4 | Reportes, balance y cierre dejan el evento consultable y sin edición | Hito 3 |
| 5 | Prueba de carga, piloto, monitoreo y producción aprobados | Hitos 1 a 4; infraestructura y dominio de la plataforma |

## Trabajo diferido

Facturación fiscal por entidad emisora/NIT, integración bancaria específica sin API disponible, reportes contables normativos personalizados, operación sin conexión completa, reapertura de eventos cerrados y automatizaciones de marketing se evaluarán después de la primera etapa productiva.
