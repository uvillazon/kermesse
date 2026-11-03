import { HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { AuthService } from '../services/auth.service';

/**
 * Interceptor HTTP de autenticación:
 * Inyecta el encabezado 'Authorization: Bearer <token>' cuando el usuario tiene
 * una sesión activa en la aplicación.
 */
export const authInterceptor: HttpInterceptorFn = (req, next) => {
  const authService = inject(AuthService);
  const token = authService.token();

  // No adjuntar autorización si ya viene especificada o si no hay token
  if (!token || req.headers.has('Authorization')) {
    return next(req);
  }

  const authorizedReq = req.clone({
    setHeaders: {
      Authorization: `Bearer ${token}`,
    },
  });

  return next(authorizedReq);
};
