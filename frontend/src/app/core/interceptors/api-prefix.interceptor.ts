import { HttpInterceptorFn } from '@angular/common/http';
import { environment } from '../../../environments/environment';

export const apiPrefixInterceptor: HttpInterceptorFn = (req, next) => {
  // Ignorar URLs absolutas o archivos estáticos/activos
  if (req.url.startsWith('http://') || req.url.startsWith('https://') || req.url.startsWith('./') || req.url.startsWith('assets/')) {
    return next(req);
  }

  // Normalizar el prefijo /api/v1
  const baseUrl = environment.apiUrl.replace(/\/+$/, '');
  const path = req.url.replace(/^\/+/, '');
  const url = `${baseUrl}/${path}`;

  const cloned = req.clone({ url });
  return next(cloned);
};
