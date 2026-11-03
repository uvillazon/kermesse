---
name: project-frontend-estilo
description: Convenciones de estilo del frontend decididas el 2026-11-03 — textos para el usuario, formato boliviano, fechas UTC y encabezado compacto
metadata:
  type: project
---

Decidido con el usuario el 2026-11-03 (puntos 1, 2 y 4 de la revisión de estilo).

**Textos:** la app la usan personal del colegio y voluntarios. Nada de "MariaDB", "Symfony", "API", "HMAC",
"outbox", "transaccional", "slug" ni códigos de rol (`SALES_OPERATOR`) en pantalla. En los encabezados, la
etiqueta superior muestra el evento activo (o la organización en Eventos). Los mensajes de éxito no dicen
"en MariaDB". Lo que permite cada rol vive en `ROLE_DESCRIPTIONS` (`core/models/domain.ts`) y sigue a
`RolePermissionMap` del backend, no a la matriz v0.3 ([[project-matriz-roles-v03]]).

**Formato:** `LOCALE_ID = 'es-BO'` en `app.config.ts`: montos `Bs 1.600,00` con `| number:'1.2-2'` (también para
los totales que la API manda como texto). Fechas siempre con el pipe `fecha` (`core/ui/fecha.pipe.ts`), nunca
con `| date`.

**Why de `fecha`:** el backend guarda en UTC y devuelve muchas fechas sin zona (`2026-11-03 14:14:33`); `DatePipe`
las leía como hora local y en Bolivia mostraba 4 h de más. `fecha` las interpreta como UTC. El test fija
`TZ=America/La_Paz` dentro del spec; sin eso, en una máquina en UTC no detecta el error.

**Fechas nuevas del backend:** eventos, tienda pública e invitaciones devuelven `starts_at` / `expires_at` en ISO
8601 UTC. Los campos `date` / `expires` (texto con mes en inglés) quedan solo por compatibilidad. La lista de
eventos ya no inventa `'28 oct 2026'` cuando no hay fecha.

**Historial de cambios:** `features/reports/audit-description.ts` traduce cada acción del backend a una frase.
Al agregar una acción nueva en el backend, agregarla ahí (el caso por defecto dice "Registró una acción").
El endpoint devuelve `subject_name` (persona afectada) para no mostrar UUID.

**Celular (≤600/700 px):** la página nunca se desplaza de lado (se midió `scrollWidth` = 390 en todas las pantallas).
Barra superior: menú, evento, usuario; organización y tienda pública en el menú (`.group.mobile-only`). Cifras en
2 columnas, botones ≥44 px. Ventas: productos en 2 columnas y `.mobile-action-bar` (global en `styles.scss`) con
total y "Cobrar". Tickets: lector primero (`order`). Pendiente: tablas → tarjetas en celular.

**Encabezado de página:** `.page-header` en `styles.scss` sin tarjeta: etiqueta, título 1.4rem, una línea de
descripción y acciones a la derecha, separados del contenido por una línea.
