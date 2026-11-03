import { LOCALE_ID } from '@angular/core';
import { DecimalPipe } from '@angular/common';
import { TestBed } from '@angular/core/testing';
import { appConfig } from '../../app.config';
import { FechaPipe, parseApiDate } from './fecha.pipe';

describe('Fechas y números en formato boliviano', () => {
  // Fija la zona de Bolivia (UTC-4): en una máquina en UTC, leer como hora local o como UTC daría
  // el mismo resultado y el test no detectaría el desfase de 4 horas.
  const env = (globalThis as { process?: { env: Record<string, string | undefined> } }).process?.env;
  let previousTz: string | undefined;
  beforeAll(() => {
    previousTz = env?.['TZ'];
    if (env) env['TZ'] = 'America/La_Paz';
  });
  afterAll(() => {
    if (env) env['TZ'] = previousTz;
  });

  it('lee las fechas sin zona de la API como UTC (no como hora local)', () => {
    expect(new Date(2026, 10, 3, 12).getTimezoneOffset()).toBe(240); // la zona de prueba está activa
    expect(parseApiDate('2026-11-03 14:14:33')?.getTime()).toBe(Date.UTC(2026, 10, 3, 14, 14, 33));
  });

  it('una venta de las 14:14 UTC se muestra a las 10:14 en Bolivia', () => {
    const d = parseApiDate('2026-11-03 14:14:33');
    expect(d?.getHours()).toBe(10);
  });

  it('respeta las fechas ISO que ya traen zona', () => {
    expect(parseApiDate('2027-03-21T14:00:00+00:00')?.getTime()).toBe(Date.UTC(2027, 2, 21, 14, 0, 0));
    expect(parseApiDate('2027-03-21T10:00:00-04:00')?.getTime()).toBe(Date.UTC(2027, 2, 21, 14, 0, 0));
  });

  it('devuelve vacío para valores ausentes o inválidos', () => {
    expect(parseApiDate(null)).toBeNull();
    expect(parseApiDate('')).toBeNull();
    expect(parseApiDate('no es fecha')).toBeNull();
  });

  describe('con la configuración de la app', () => {
    beforeEach(() => {
      TestBed.configureTestingModule({ providers: [...appConfig.providers, FechaPipe, DecimalPipe] });
    });

    it('usa el idioma es-BO', () => {
      expect(TestBed.inject(LOCALE_ID)).toBe('es-BO');
    });

    it('formatea montos como en Bolivia: 1.600,00', () => {
      expect(TestBed.inject(DecimalPipe).transform(1600, '1.2-2')).toBe('1.600,00');
    });

    it('muestra el mes en español', () => {
      const pipe = TestBed.inject(FechaPipe);
      expect(pipe.transform('2027-03-21T14:00:00+00:00', 'MMMM y')).toBe('marzo 2027');
    });
  });
});
