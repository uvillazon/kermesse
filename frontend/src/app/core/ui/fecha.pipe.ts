import { LOCALE_ID, Pipe, PipeTransform, inject } from '@angular/core';
import { formatDate } from '@angular/common';

/** Formato por defecto: 03/11/26 10:14 */
export const FECHA_CORTA = 'dd/MM/yy HH:mm';

/**
 * Convierte una fecha de la API al formato local y a la zona horaria del navegador.
 *
 * El backend guarda en UTC y devuelve muchas fechas sin zona (`2026-11-03 14:14:33`).
 * `DatePipe` las leería como hora local y las mostraría corridas (4 h en Bolivia),
 * así que aquí se interpretan como UTC. Las fechas ISO con zona (`...+00:00`, `...Z`)
 * se respetan tal cual.
 */
export function parseApiDate(value: string | null | undefined): Date | null {
  if (!value) {
    return null;
  }
  const naive = /^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2}(\.\d+)?)?$/;
  const iso = naive.test(value) ? `${value.replace(' ', 'T')}Z` : value;
  const date = new Date(iso);
  return Number.isNaN(date.getTime()) ? null : date;
}

@Pipe({ name: 'fecha', standalone: true })
export class FechaPipe implements PipeTransform {
  private readonly locale = inject(LOCALE_ID);

  transform(value: string | null | undefined, format: string = FECHA_CORTA): string {
    const date = parseApiDate(value);
    return date ? formatDate(date, format, this.locale) : '';
  }
}
