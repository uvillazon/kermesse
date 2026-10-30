import { HttpErrorResponse, HttpInterceptorFn } from '@angular/common/http';
import { catchError, throwError } from 'rxjs';

export const errorHandlerInterceptor: HttpInterceptorFn = (req, next) => {
  return next(req).pipe(
    catchError((error: HttpErrorResponse) => {
      let errorMessage = 'Ha ocurrido un error inesperado.';

      if (error.error instanceof ErrorEvent) {
        // Error de red o del lado del cliente
        errorMessage = `Error del cliente: ${error.error.message}`;
      } else {
        // Error devuelto por la API / servidor
        switch (error.status) {
          case 400:
            errorMessage = error.error?.message || 'Solicitud incorrecta (400).';
            break;
          case 401:
            errorMessage = 'Sesión no autorizada o expirada (401).';
            break;
          case 403:
            errorMessage = 'No tienes permisos para realizar esta acción (403).';
            break;
          case 404:
            errorMessage = error.error?.message || 'Recurso no encontrado (404).';
            break;
          case 409:
            errorMessage = error.error?.message || 'Conflicto en la operación (409).';
            break;
          case 422:
            errorMessage = error.error?.message || 'Validación fallida en los datos enviados (422).';
            break;
          case 429:
            errorMessage = 'Demasiadas solicitudes. Por favor espera unos momentos (429).';
            break;
          case 500:
            errorMessage = 'Error interno del servidor (500).';
            break;
          default:
            errorMessage = `Error ${error.status}: ${error.statusText || error.message}`;
        }
      }

      console.error(`[HTTP Error Interceptor] [${req.method} ${req.url}]:`, {
        status: error.status,
        message: errorMessage,
        raw: error
      });

      return throwError(() => ({
        status: error.status,
        message: errorMessage,
        originalError: error
      }));
    })
  );
};
