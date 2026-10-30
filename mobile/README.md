# Kermesse Mobile

Proyecto base Flutter del operador móvil (prueba del Hito 0). Solo esqueleto: sin reglas de negocio, autenticación real ni modo offline.

## Estructura

```
lib/
  core/        config, theme, router, network (ApiClient), errors, utils
  features/
    home/      domain/ data/ presentation/   (ejemplo: comprobación de conexión)
```

Cada funcionalidad nueva va en `features/<nombre>/` con las capas `domain`, `data` y `presentation`.

## Configuración

La URL del backend se pasa al compilar; no hay secretos en el código:

```bash
flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8000/api/v1
```

Por defecto apunta a `http://10.0.2.2:8000/api/v1` (host de la máquina desde el emulador Android).

## Comandos

```bash
flutter pub get
flutter analyze
flutter test
```

## Pendiente (contratos del Hito 0)

- `IdempotencyKey.generate()` es una utilidad vacía hasta definir `API-IDEMP-01`.
- La pantalla de inicio llama a `GET /health`; el contrato real está por definir.
- Autenticación, permisos por evento y operación sin conexión quedan fuera de esta base.
