import { ApplicationConfig, LOCALE_ID, provideBrowserGlobalErrorListeners } from '@angular/core';
import { registerLocaleData } from '@angular/common';
import localeEsBo from '@angular/common/locales/es-BO';
import { provideRouter } from '@angular/router';
import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { providePrimeNG } from 'primeng/config';
import Aura from '@primeuix/themes/aura';

import { routes } from './app.routes';
import { authInterceptor } from './core/interceptors/auth.interceptor';
import { apiPrefixInterceptor } from './core/interceptors/api-prefix.interceptor';
import { errorHandlerInterceptor } from './core/interceptors/error-handler.interceptor';

// Números y fechas en formato boliviano: Bs 1.600,00 · 03/11/26 10:14 · 21 mar 2027.
registerLocaleData(localeEsBo);

export const appConfig: ApplicationConfig = {
  providers: [
    { provide: LOCALE_ID, useValue: 'es-BO' },
    provideBrowserGlobalErrorListeners(),
    provideRouter(routes),
    provideHttpClient(
      withInterceptors([authInterceptor, apiPrefixInterceptor, errorHandlerInterceptor])
    ),
    providePrimeNG({
      license: 'eyJpZCI6ImU2ZWY2YWUyLTg0YzAtNDI2My1iYzljLTY5NzU5OGUxZGU5ZSIsInByb2R1Y3QiOiJwcmltZXVpIiwidGllciI6ImNvbW11bml0eSIsInR5cGUiOiJkZXYiLCJpYXQiOjE3OTMzODcyOTMsImV4cCI6MTgyNDkyMzI5M30.-F7ybPO_JbeFs4IodVqY6HqtAoI9EpPpThph1oNM0yYJo2Xubt9AM5qzGCsT2-OQ43kg5SDyvm3oaMzLuCg8CQ',
      theme: {
        preset: Aura,
        options: {
          darkModeSelector: false
        }
      },
      ripple: true
    })
  ]
};
