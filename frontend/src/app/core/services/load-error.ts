/**
 * Convierte un error de carga en un mensaje visible para el usuario.
 * Las pantallas lo muestran en lugar de caer en datos de ejemplo.
 * Acepta el objeto que lanza `errorHandlerInterceptor` ({ status, message }).
 */
export function describeLoadError(err: unknown, fallback: string): string {
  const e = err as { status?: number; message?: string } | null;
  if (e?.status === 0) {
    return `${fallback} No hay conexión con la API.`;
  }
  return e?.message ? `${fallback} ${e.message}` : fallback;
}
