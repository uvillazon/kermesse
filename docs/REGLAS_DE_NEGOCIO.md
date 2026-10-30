# Reglas de negocio

## Propósito

Estas reglas definen el comportamiento obligatorio de la primera versión de Kermesse. Funcionan como referencia única para backend, Angular, Flutter, pruebas y validación con la organización. Si una pantalla, API o proceso contradice una regla, prevalece este documento hasta que la organización apruebe un cambio.

## Convenciones

- **Organización:** institución que usa el sistema.
- **Evento:** una kermesse independiente de una organización.
- **Usuario autorizado:** persona con un permiso activo para la organización, evento y acción solicitada.
- **Operación de escritura:** acción que crea, modifica, aprueba, anula, canjea o cierra información.
- **Estado cerrado:** estado final de un evento que permite consulta y exportación, pero no cambios operativos.

## Organización, eventos y permisos

### RN-PLT-001 — Plataforma centralizada

Kermesse operará como una plataforma centralizada multi-organización. La infraestructura y la aplicación serán compartidas, pero cada organización conservará aislamiento lógico estricto de usuarios, eventos, datos y archivos.

**Criterio de aceptación:** una organización no puede consultar, inferir, modificar ni exportar información de otra organización, incluso usando identificadores conocidos.

### RN-COM-001 — Habilitación comercial por evento

La plataforma cobrará por evento habilitado y no por cantidad de usuarios. Un evento podrá prepararse como borrador, pero su operación requerirá una compra, exoneración o activación administrativa vigente.

**Criterio de aceptación:** un evento sin habilitación comercial no puede abrir ventas ni canjes, y un evento habilitado conserva la versión de precio y condición comercial aplicada.

### RN-USR-001 — Usuarios sin límite comercial

Una organización podrá registrar o invitar usuarios sin límite contractual ni cobro por asiento. Cada usuario seguirá sujeto a membresía, rol, estado y permisos por organización y evento.

**Criterio de aceptación:** agregar usuarios no modifica el precio del evento; suspender o revocar un usuario impide nuevos accesos sin borrar su auditoría histórica.

### RN-ORG-001 — Separación entre organizaciones

Cada dato operativo debe pertenecer a una organización y a un evento cuando corresponda. Un usuario no podrá consultar, exportar ni modificar datos de una organización distinta a la que tiene autorizada.

**Criterio de aceptación:** al intentar consultar un identificador de otra organización, la API responde sin datos autorizados y registra el intento cuando sea relevante.

### RN-EVT-001 — Independencia de eventos

Una organización puede crear varios eventos. Productos, responsables, ventas, gastos, tickets, reportes y cierre pertenecen a un evento específico y no se mezclan entre eventos.

**Criterio de aceptación:** una venta de un evento no aparece en los reportes, stock ni balance de otro evento.

### RN-PER-001 — Permisos por evento

Los permisos se asignan por organización y evento. Un mismo usuario puede administrar un evento, operar en otro y solo consultar un tercero.

**Criterio de aceptación:** el menú, las pantallas y la API muestran únicamente acciones permitidas para el evento seleccionado.

### RN-PER-002 — Acceso histórico

Los responsables de eventos anteriores conservarán acceso solo a los eventos autorizados. El administrador puede otorgar, cambiar o retirar ese acceso.

**Criterio de aceptación:** un responsable histórico con permiso de consulta puede revisar y exportar información autorizada, sin registrar ni editar datos.

### RN-HIS-001 — Permanencia del historial

Los eventos cerrados permanecerán disponibles para consulta y exportación autorizada aunque la organización no compre eventos nuevos. Una baja completa deberá aplicar una política explícita de exportación y retención.

**Criterio de aceptación:** una organización sin eventos activos puede consultar sus eventos cerrados mientras su cuenta conserve acceso histórico.

### RN-AUD-001 — Auditoría

El sistema registrará usuario, fecha, hora, evento y acción para operaciones relevantes: venta, gasto, aprobación, pago, ticket, canje, asignación de tickets, cierre y cambios de permisos.

**Criterio de aceptación:** el reporte de auditoría permite identificar quién realizó una operación y cuándo.

## Productos, disponibilidad y cursos

### RN-PRO-001 — Disponibilidad de producto

Cada producto tendrá precio, cantidad planificada, estado de publicación y disponibilidad calculada. La cantidad vendida o reservada no podrá superar la disponibilidad autorizada.

**Criterio de aceptación:** dos ventas simultáneas de la última unidad solo permiten confirmar una; la otra recibe un resultado de disponibilidad insuficiente.

### RN-PRO-002 — Cambios de catálogo

Solo usuarios autorizados pueden crear, publicar, pausar o modificar productos. Un cambio de precio no altera el precio histórico de ventas ya confirmadas.

**Criterio de aceptación:** una venta anterior conserva el importe confirmado aunque el producto cambie de precio después.

### RN-CUR-001 — Asignación de tickets por curso o grupo

El sistema permitirá asignar una cantidad de tickets o productos a un curso o grupo, indicando evento, producto, responsable y cantidad.

**Criterio de aceptación:** el reporte muestra asignados, vendidos, pendientes y canjeados por curso o grupo.

### RN-CUR-002 — Asignación no es venta

Asignar tickets a un curso o grupo no confirma una venta, no reconoce ingreso y no reduce automáticamente la disponibilidad comercial. La reserva de disponibilidad solo ocurrirá si la organización define explícitamente ese comportamiento para una asignación confirmada.

**Criterio de aceptación:** el balance no incluye una asignación como ingreso hasta que exista una venta o cobro confirmado.

## Ventas, pagos y tickets

### RN-VEN-001 — Registro rápido

Una venta podrá registrarse con productos, cantidades, medio de pago y solo correo electrónico del comprador. Nombre, teléfono u otros datos serán opcionales.

**Criterio de aceptación:** un operador puede completar una venta válida ingresando únicamente un correo electrónico cuando el canal requiera envío de ticket.

### RN-VEN-002 — Transacción completa

Toda operación de escritura se ejecutará dentro de una transacción. Para una venta confirmada, la operación incluirá los datos de venta, el estado de pago, la disponibilidad afectada, los tickets emitidos y la auditoría correspondiente.

**Criterio de aceptación:** si falla cualquier parte de una venta antes de su confirmación, no queda una venta parcial, un descuento de disponibilidad ni un ticket sin respaldo.

### RN-VEN-003 — Idempotencia

Las operaciones que puedan repetirse por mala conexión, reintento del usuario o reenvío de una solicitud deberán usar una clave de idempotencia. La misma solicitud no podrá crear dos ventas, pagos, tickets o canjes.

**Criterio de aceptación:** al enviar dos veces el mismo comando con la misma clave, se devuelve el resultado de la primera operación sin duplicar registros.

### RN-PAG-001 — Estado de pago

Los pagos tendrán un estado explícito: pendiente, por verificar, confirmado, rechazado, anulado o devuelto. Solo un pago confirmado permite emitir o habilitar el ticket cuando la regla del canal así lo requiera.

**Criterio de aceptación:** un comprobante enviado por QR de equipo permanece por verificar hasta que un responsable autorizado lo apruebe.

### RN-PAG-002 — Proveedores externos

La respuesta de un banco, correo o proveedor externo no puede dejar datos locales inconsistentes. Las operaciones de base de datos no permanecerán abiertas mientras se espera al proveedor; los envíos pendientes se procesarán de forma segura y auditable.

**Criterio de aceptación:** si falla el correo luego de confirmar una venta, la venta y el ticket siguen correctos y el sistema registra un reintento de envío.

### RN-TIC-001 — Ticket único

Cada ticket tendrá un identificador y código QR únicos, vinculados a una venta, evento, productos y cantidades autorizadas.

**Criterio de aceptación:** el QR muestra el detalle correcto de platos o productos que se pueden entregar.

### RN-CAN-001 — Canje único

Un ticket no podrá canjearse más de una vez. La validación y el cambio de estado deben ocurrir de forma atómica.

**Criterio de aceptación:** ante dos escaneos simultáneos del mismo QR, uno queda confirmado y el otro indica que el ticket ya fue utilizado.

## Gastos, reportes y balance

### RN-GAS-001 — Registro de inversión y gasto

Todo costo o gasto operativo deberá registrar evento, categoría, importe, fecha y responsable. El comprobante será opcional según la configuración de la organización.

**Criterio de aceptación:** el reporte de gastos puede filtrarse por evento, categoría, responsable y periodo.

### RN-REP-001 — Reportes exportables

Los usuarios autorizados podrán consultar y exportar reportes de ventas, cobros, gastos, inventario/disponibilidad, tickets por curso, canjes y auditoría. La exportación será PDF y Excel desde el panel web y la aplicación móvil, según los permisos del usuario.

**Criterio de aceptación:** un operador no puede exportar un reporte financiero si su rol no lo autoriza.

### RN-BAL-001 — Balance por evento

El balance mostrará ingresos netos reconocidos, costos reconocidos, gastos operativos, resultado contable y margen sobre ingresos. Su fórmula es: `resultado contable = ingresos netos - costos - gastos`; `margen = resultado contable / ingresos netos × 100` cuando existan ingresos netos.

**Criterio de aceptación:** un evento con Bs 6.000 de ingresos netos, Bs 3.500 de costos y Bs 500 de gastos muestra resultado de Bs 2.000 y margen de 33,33%.

### RN-BAL-002 — Aportes y resultado

Un aporte de fondos de la organización no se considera automáticamente ingreso por venta. Los criterios de reconocimiento de costos, sobrantes, mermas y saldos se definirán con el responsable contable de la organización.

**Criterio de aceptación:** el balance diferencia ingresos de ventas, fondos aportados y saldos por cobrar o pagar.

## Cierre del evento

### RN-CIE-001 — Revisión previa

Antes de cerrar un evento, un usuario autorizado debe revisar ventas, pagos, comprobantes, gastos, inventario, tickets, canjes y balance. Los pendientes deben resolverse o quedar identificados en el informe final.

**Criterio de aceptación:** el sistema muestra una lista de validaciones pendientes antes de habilitar la confirmación final.

### RN-CIE-002 — Cierre definitivo

Al confirmar el cierre, el sistema guardará una instantánea del balance final, usuario responsable y fecha/hora. El evento cambiará a estado cerrado y bloqueará ventas, pagos, gastos, canjes, asignaciones de tickets, ediciones y eliminaciones.

**Criterio de aceptación:** cualquier comando de modificación sobre un evento cerrado es rechazado de forma consistente desde web, móvil y API.

### RN-CIE-003 — Consulta posterior

Después del cierre, los usuarios que conserven permiso podrán consultar el evento, auditoría, comprobantes y reportes, y exportar la información permitida. No podrán modificar registros.

**Criterio de aceptación:** un administrador puede abrir un evento cerrado y descargar el balance, pero no ve acciones operativas de edición.

## Seguridad y capacidad

### RN-SEG-001 — Límites de solicitudes

La plataforma limitará peticiones por IP, usuario, correo, evento o acción según corresponda. Los límites cubrirán inicio de sesión, preventa pública, reenvío de tickets, exportaciones, operaciones QR y acciones de escritura. Una petición excedida devolverá HTTP 429 y, cuando aplique, indicará el tiempo de reintento.

**Criterio de aceptación:** una prueba que supere el límite recibe 429 y no altera datos ni bloquea usuarios dentro del límite.

### RN-SEG-002 — Dos capas de protección

El proxy o servidor web aplicará límites de bajo costo antes de PHP. Symfony aplicará límites funcionales por usuario o acción. Los valores finales se definirán con las pruebas de carga y la capacidad contratada.

**Criterio de aceptación:** la configuración de producción documenta ambos niveles y las pruebas de carga verifican su comportamiento.

### RN-CAR-001 — Pruebas de carga

Las pruebas de carga medirán catálogo público, login, venta rápida, confirmación de pago, lectura QR, exportaciones y cierre. Se ejecutarán primero en un ambiente de pruebas con datos sintéticos.

**Criterio de aceptación:** la carga acordada no produce ventas duplicadas, stock negativo, canjes repetidos ni errores no controlados.

## Facturación futura

### RN-FAC-001 — Entidad emisora por evento

En una segunda etapa, una organización podrá registrar empresas o entidades emisoras con razón social y NIT, y asociar opcionalmente una de ellas a un evento habilitado para facturación.

**Criterio de aceptación futuro:** un evento facturable identifica de forma inequívoca a su entidad emisora y no mezcla numeración ni documentos fiscales con otra empresa u organización.

### RN-FAC-002 — Facturación fuera de la etapa 1

La etapa 1 no emitirá facturas, notas de crédito ni documentos tributarios. El modelo reservará la frontera funcional necesaria sin simular validez fiscal.

**Criterio de aceptación:** ningún flujo de la etapa 1 presenta un ticket o comprobante de pago como factura fiscal.

## Cambios a estas reglas

Toda modificación deberá registrar fecha, motivo, responsable y reglas afectadas. Los cambios que afecten importes, balance, cierre, pagos, tickets, disponibilidad, permisos o datos históricos requieren aprobación de la organización antes de implementarse.
