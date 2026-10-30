# Hito 0 — Modelo conceptual de identidad y acceso

**Estado:** propuesta para validación
**Versión:** 0.1
**Fecha:** 2026-10-29
**Alcance:** definición funcional; sin base de datos, entidades, migraciones ni autenticación real

**Implementación temporal:** el alcance conceptual está representado en `backend/src/Identity` mediante una política y un directorio en memoria sin datos precargados. No expone autenticación ni sustituye las decisiones pendientes de los pasos 2 y 3.

## 1. Objetivo

Definir quién puede ingresar a Kermesfera y cómo se relaciona con organizaciones y eventos. Este documento fija conceptos y reglas de alcance; la matriz detallada de roles corresponde al paso 2 y el diseño de persistencia al paso 3.

## 2. Principio central

Cada persona utiliza una sola identidad en toda la plataforma. Esa identidad no concede acceso por sí misma: el acceso nace de una vinculación activa con una organización y, para operar o consultar datos de un evento, de una asignación explícita a ese evento.

Una persona puede participar en varias organizaciones y eventos sin crear cuentas duplicadas. Cada vinculación se evalúa de forma independiente.

## 3. Tipos de acceso

| Tipo | Alcance | Propósito | Acceso automático a eventos |
|---|---|---|---|
| Identidad de usuario | Plataforma | Representar a una persona y permitirle aceptar invitaciones | Ninguno |
| Responsable principal de organización | Una organización | Custodiar la cuenta de la organización, sus administradores y decisiones comerciales | No; debe recibir una asignación de evento para consultar u operar sus datos |
| Administrador de organización | Una organización | Gestionar información general, colaboradores, invitaciones y asignaciones | No; puede administrar asignaciones sin obtener automáticamente datos operativos o financieros |
| Colaborador de organización | Una organización | Mantener la vinculación mínima necesaria para participar en eventos | Ninguno |
| Miembro de evento | Uno o varios eventos | Administrar, vender, revisar pagos, canjear tickets o consultar reportes según los roles concedidos | Solo los eventos asignados |
| Responsable histórico | Eventos cerrados específicos | Consultar o exportar información expresamente autorizada | Ninguno fuera de los eventos indicados |
| Operador interno de plataforma | Plataforma comercial | Dar de alta organizaciones y habilitar eventos comprados o exonerados | No accede por defecto a datos operativos de clientes |
| Comprador público | Compra propia en un evento | Consultar catálogo público, comprar y recuperar su propio ticket | No es miembro interno de organización ni de evento |

El soporte técnico con acceso excepcional a datos de clientes no queda aprobado en esta etapa. Si se requiere, deberá ser temporal, justificado y completamente auditado.

## 4. Relaciones permitidas

- Un usuario puede ser responsable o administrador en más de una organización.
- Un usuario puede ser administrador de una organización y operador de uno o varios de sus eventos.
- Un usuario puede cumplir roles diferentes en eventos distintos.
- Un operador puede trabajar en eventos de organizaciones diferentes, siempre que cada organización lo invite y autorice por separado.
- Una organización puede tener cualquier cantidad de colaboradores; el precio no depende del número de usuarios.
- Un mismo evento puede tener varios responsables y operadores, sujetos a los roles que se definan en el paso 2.

Ejemplo: una misma persona puede administrar la Organización A, gestionar el Evento A-2026, vender en el Evento A-2027 y canjear tickets en un evento de la Organización B. Ninguna asignación amplía las demás.

## 5. Ciclo conceptual de acceso

1. Una persona recibe una invitación de organización o de evento.
2. Si todavía no posee identidad, crea una sola cuenta; si ya existe, reutiliza la misma.
3. Al aceptar, queda vinculada a la organización. Si fue invitada para un evento, además recibe la asignación específica de ese evento.
4. En cada solicitud se comprueba identidad activa, organización activa, vinculación activa, evento asignado, permiso requerido y estado del evento.
5. Retirar una asignación de evento elimina únicamente el acceso a ese evento.
6. Suspender o retirar la vinculación con la organización bloquea todas sus asignaciones de eventos dentro de esa organización, sin borrar auditoría ni operaciones históricas.
7. Cerrar un evento bloquea toda escritura. El acceso histórico posterior no se concede automáticamente y debe autorizarse de forma explícita.

## 6. Reglas obligatorias

1. **Denegación por defecto:** lo no concedido explícitamente se rechaza.
2. **Sin cuentas duplicadas:** no se crea un usuario diferente por organización, evento o función.
3. **Sin herencia implícita:** administrar una organización no equivale a operar o consultar todos sus eventos.
4. **Aislamiento:** una asignación nunca permite inferir información de otra organización o evento.
5. **Revocación inmediata:** una suspensión impide nuevas acciones, pero conserva la identidad del actor en la auditoría.
6. **Vigencia:** las invitaciones y asignaciones pueden estar pendientes, activas, vencidas, suspendidas o revocadas.
7. **Acceso histórico explícito:** antiguos operadores no conservan consulta por el solo hecho de haber trabajado en el evento.
8. **Auditoría:** invitaciones, aceptaciones, cambios de alcance, suspensiones y revocaciones identifican actor y momento.

## 7. Evaluación conceptual de una solicitud

Para autorizar una acción interna deben cumplirse todas estas condiciones:

`usuario activo` + `organización autorizada` + `vinculación activa` + `evento asignado` + `permiso` + `estado compatible`

Las acciones exclusivamente organizacionales no requieren evento, pero sí una vinculación organizacional con la capacidad correspondiente. El comprador público utiliza un flujo separado y solo puede acceder a su propia compra.

## 8. Decisiones propuestas para validar

1. Mantener al menos un responsable principal activo por organización antes de retirar al anterior.
2. Permitir varios responsables principales para evitar que la organización quede sin administración.
3. No otorgar a responsables ni administradores acceso automático al detalle operativo o financiero de todos los eventos.
4. Permitir operadores en varias organizaciones mediante invitaciones independientes.
5. No conservar acceso histórico para operadores salvo concesión explícita.

## 9. Fuera de alcance de este paso

- Nombres finales y permisos de cada rol.
- Proceso técnico de inicio de sesión, contraseñas, segundo factor o proveedor de identidad.
- Tablas, claves, índices, relaciones físicas o migraciones.
- Elección o conexión de MySQL u otro motor.
- Implementación en Symfony, Angular o Flutter.

## 10. Criterio de cierre del paso 1

El modelo conceptual queda aprobado cuando se confirman los tipos de acceso, la ausencia de herencia automática entre organización y evento, la participación multi-evento/multi-organización y las cinco decisiones de la sección 8. Después se puede avanzar al paso 2: matriz definitiva de roles y permisos.
