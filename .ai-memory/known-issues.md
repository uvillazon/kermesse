# Problemas conocidos

- Falta definir precio, momento de cobro, cancelación, devolución y reprogramación de eventos en la plataforma centralizada.
- Falta definir retención/exportación cuando una organización solicita baja, sin perder historial financiero.
- La facturación por empresa emisora/NIT es etapa 2; proveedor y modalidad tributaria aún no están elegidos.
- El motor productivo sigue pendiente. El entorno local es MariaDB 10.4.18; si producción usa MySQL deben validarse versión, InnoDB, colación, aislamiento, bloqueos y compatibilidad de las migraciones.
- Falta definir quién puede cerrar un evento y si requiere doble aprobación. `event.close` permanece denegado y las tablas de cierre se excluyen de la primera migración.
- El outbox y trabajador reintentable de invitaciones están implementados. Se eligió Hostinger SMTP (`smtp.hostinger.com:465`, TLS implícito), pero el adaptador continúa devolviendo las entregas a `pending`; faltan configuración final, una credencial completa y rotada fuera de Git, y pruebas reales de entrega.
- La rotación multiclave de invitaciones está implementada. Operaciones debe retirar una versión heredada solo después de comprobar que no existen entregas `pending` o `processing` con esa `token_key_version`; aún falta automatizar esa comprobación/alerta.
- El backend valida JWT Bearer HS256, pero todavía no existe flujo de login/emisión ni proveedor de identidad aprobado. El secreto HMAC debe sustituirse por uno real fuera de Git y reconsiderarse firma asimétrica si el proveedor elegido la soporta.
- El límite API usa una cuota general por minuto. Aún deben calibrarse cuotas específicas para aceptación de invitaciones, venta pública, QR, exportaciones y autenticación con las pruebas de carga; también debe programarse la limpieza periódica de contadores expirados.
- La instalación todavía no fue reproducida en un segundo equipo. Deben verificarse PHP 8.2, extensiones, Composer, MariaDB, nueve migraciones, pruebas de integración y comportamiento HTTP sin trasladar `.env.local` ni credenciales por Git.

