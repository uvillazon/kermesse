import { HttpInterceptorFn } from '@angular/common/http';
import { environment } from '../../../environments/environment';

export const apiPrefixInterceptor: HttpInterceptorFn = (req, next) => {
  // Ignorar URLs ya absolutas o archivos estáticos
  if (
    req.url.startsWith('http://') ||
    req.url.startsWith('https://') ||
    req.url.startsWith('./') ||
    req.url.startsWith('assets/')
  ) {
    return next(req);
  }

  const baseUrl = environment.apiUrl.replace(/\/+$/, '');

  // Si la ruta solicitada ya incluye /api/v1 o api/v1 al inicio, removerlo antes de concatenar a baseUrl
  let cleanPath = req.url.replace(/^\/+/, '');
  if (cleanPath.startsWith('api/v1/')) {
    cleanPath = cleanPath.substring('api/v1/'.length);
  } else if (cleanPath === 'api/v1') {
    cleanPath = '';
  }

  const finalUrl = cleanPath ? `${baseUrl}/${cleanPath}` : baseUrl;

  const cloned = req.clone({ url: finalUrl });
  return next(cloned);
};
