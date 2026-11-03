# Tarea actual

Preparar el proyecto Kermesse para trabajar con Codex, Claude y Antigravity compartiendo memoria importante mediante GitHub.

El nombre comercial aprobado para la plataforma es **Kermesfera**. El repositorio y las referencias técnicas internas pueden conservar temporalmente el nombre `kermesse` hasta planificar una migración sin romper rutas ni herramientas.

## Próximo paso

Implementación en curso: persistencia Doctrine DBAL, comandos transaccionales, idempotencia y endpoints de identidad/acceso añadidos sobre `kermesfera`; `event.close` continúa bloqueado.

El usuario priorizó ventas. La base local `kermesfera` usa MariaDB 10.4.18 y tiene catorce migraciones ejecutadas (`Version20261030011300`). `CreateSale` acepta efectivo y QR/transferencia, calcula importes en servidor y bloquea evento/inventario confirmando venta, líneas, pago, stock, ticket y auditoría en una transacción idempotente. Además, se implementaron y conectaron con Angular: revisión transaccional de pagos QR (`POST .../sales/{saleId}/payment/review`), validación y canje parcial/total de tickets (`GET /tickets`, `POST .../tickets/{ticketId}/redemptions`), asignaciones por curso (`GET/POST .../course-allocations`), alta/edición de catálogo (`POST/PATCH .../catalog/products`), gastos y balance financiero (`GET/POST .../expenses` con exportación CSV), creación de eventos (`POST .../events`) y tienda pública en línea (`/api/v1/public/events/{slug}/catalog` y `/orders`). Todos los grids del frontend cumplen la regla estándar (paginador `p-table` + buscador rápido + espacio de filtros). `event.close` continúa bloqueado.

Todo cambio de esquema debe entrar por migraciones versionadas. La configuración local está ignorada por Git y no deben versionarse credenciales. Toda escritura crítica deberá ejecutarse dentro de una transacción explícita.

## Prototipo

Existe un prototipo navegable en `prototype/index.html` con vistas de gestión,
operador móvil y página pública para Saint Andrew's / Kermesse Halloween 2026.

## Plan aprobado para preparar

El plan contempla backend Symfony modular y transaccional, Angular responsive, Flutter por funcionalidades, límites de peticiones, pruebas de carga, reportes, balance y cierre de eventos.

