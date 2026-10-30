# Tarea actual

Preparar el proyecto Kermesse para trabajar con Codex, Claude y Antigravity compartiendo memoria importante mediante GitHub.

El nombre comercial aprobado para la plataforma es **Kermesfera**. El repositorio y las referencias técnicas internas pueden conservar temporalmente el nombre `kermesse` hasta planificar una migración sin romper rutas ni herramientas.

## Próximo paso

Implementación en curso: persistencia Doctrine DBAL, comandos transaccionales, idempotencia y endpoints de identidad/acceso añadidos sobre `kermesfera`; `event.close` continúa bloqueado.

Validar los pasos 1 y 2 y la primera persistencia del paso 3. La base local `kermesfera` usa MariaDB 10.4.18 y tiene nueve migraciones ejecutadas. Asignación, cambio de roles, revocación de acceso de evento/organización, transferencia de propietario, creación y aceptación pública de invitaciones están expuestos con autorización, transacciones Doctrine y auditoría. Las rutas protegidas validan JWT Bearer. La transferencia y revocaciones son atómicas e idempotentes; la aceptación consume el token por hash sin persistirlo en texto plano. La creación confirma invitación, hash, outbox y auditoría juntos. El worker usa lease, reintentos y un llavero por `token_key_version`: nuevas invitaciones usan la clave activa y entregas antiguas pueden terminar con secretos heredados. La API aplica límites persistentes por minuto y acción, identificando usuario o IP y respondiendo 429 antes del controlador. OpenAPI v0.2 y la matriz están sincronizados; gastos y exportaciones asíncronas ya tienen contrato HTTP `planned`. La suite tiene 71 pruebas, 640 aserciones y 2 omisiones por SQLite no disponible. La emisión/login se delegará al proveedor de identidad que se elija. **Próxima validación acordada:** probar el proyecto desde cero en un segundo equipo antes de continuar el dominio operativo; sincronizar por GitHub, instalar dependencias, crear una base vacía, configurar `.env.local` manualmente, ejecutar las nueve migraciones, la suite completa y una comprobación de salud/JWT/429. Ninguna contraseña ni `.env.local` se copiará mediante Git. Superada esa prueba, se precisarán ventas, pagos, tickets, balance y asignaciones sin implementar reglas todavía ambiguas. Hostinger SMTP sobre puerto 465/TLS quedó elegido y su adaptador se configurará al final, siempre con secretos fuera de Git. `event.close` y el motor productivo siguen pendientes.

Todo cambio de esquema debe entrar por migraciones versionadas. La configuración local está ignorada por Git y no deben versionarse credenciales. Toda escritura crítica deberá ejecutarse dentro de una transacción explícita.

## Prototipo

Existe un prototipo navegable en `prototype/index.html` con vistas de gestión,
operador móvil y página pública para Saint Andrew's / Kermesse Halloween 2026.

## Plan aprobado para preparar

El plan contempla backend Symfony modular y transaccional, Angular responsive, Flutter por funcionalidades, límites de peticiones, pruebas de carga, reportes, balance y cierre de eventos.

