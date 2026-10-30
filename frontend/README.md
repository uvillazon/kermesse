# Kermesse Frontend &mdash; Hito 0

Base del frontend web de la plataforma Kermesse para **Saint Andrew's School**, construida con **Angular** y **PrimeNG**.

---

## 🏛 Arquitectura del Proyecto

El proyecto sigue una organización modular por capas y funcionalidades (*feature-based architecture*) con **Standalone Components**:

```text
frontend/
├── src/
│   ├── app/
│   │   ├── core/
│   │   │   ├── interceptors/
│   │   │   │   ├── api-prefix.interceptor.ts      # Prefijo automático /api/v1 para peticiones relativas
│   │   │   │   └── error-handler.interceptor.ts   # Captura y normalización centralizada de errores HTTP
│   │   │   ├── layout/
│   │   │   │   ├── layout.component.ts            # Shell general responsive (sidebar + topbar + router-outlet)
│   │   │   │   ├── sidebar/                       # Menú lateral con navegación por roles/eventos
│   │   │   │   └── topbar/                        # Cabecera con selector de evento activo y perfil
│   │   │   └── services/
│   │   │       └── api.service.ts                 # Servicio genérico tipado sobre HttpClient
│   │   ├── features/
│   │   │   ├── home/                              # Vista principal / dashboard operativo del evento
│   │   │   └── events/                            # Listado y gestión multi-evento de la organización
│   │   ├── app.config.ts                          # Proveedores globales (Router, PrimeNG Aura, HTTP interceptors)
│   │   ├── app.routes.ts                          # Definición de rutas principales y lazy loading
│   │   └── app.ts                                 # Componente raíz
│   ├── environments/
│   │   ├── environment.ts                         # Configuración producción (sin secretos)
│   │   └── environment.development.ts             # Configuración desarrollo (sin secretos)
│   ├── styles.scss                                # Estilos globales y temas PrimeIcons
│   └── index.html                                 # Shell HTML con tipografía Inter
└── package.json
```

---

## 🚀 Requisitos Previos

* **Node.js**: v20+ o v24+ (LTS recomendado)
* **npm**: v10+

---

## 🛠 Instalación y Ejecución

### 1. Instalar dependencias
```bash
npm install
```

### 2. Arrancar servidor de desarrollo
```bash
npm start
```
El servidor quedará disponible en `http://localhost:4200/`.

### 3. Compilar para producción (Build)
```bash
npm run build
```
Los artefactos compilados y optimizados se generarán en la carpeta `dist/frontend`.

---

## 🔒 Manejo de Entornos y Seguridad

* **Sin secretos en el cliente**: Las variables de entorno solo definen flags operativas y prefijos de endpoint (`apiUrl: '/api/v1'`).
* **Seguridad y tokens**: La autenticación y autorización real se integrarán en hitos posteriores mediante cabeceras seguras / cookies HTTP-only sin exponer credenciales en el código fuente.
