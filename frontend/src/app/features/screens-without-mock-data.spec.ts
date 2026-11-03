import { Type, isSignal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { HttpTestingController } from '@angular/common/http/testing';
import {
  FORMER_MOCK_TEXTS,
  apiTestProviders,
  fakeEventContext,
  respondAll,
} from '../../testing/api-test-helpers';
import { HomeComponent } from './home/home.component';
import { ReportsComponent } from './reports/reports.component';
import { AccessComponent } from './access/access.component';
import { CatalogComponent } from './catalog/catalog.component';
import { TicketsComponent } from './tickets/tickets.component';
import { SalesComponent } from './sales/sales.component';
import { PublicStoreComponent } from './public-store/public-store.component';
import { LayoutComponent } from '../core/layout/layout.component';

/**
 * Regresión de la auditoría del 2026-11-03: las pantallas mostraban datos de ejemplo
 * cuando la API fallaba o devolvía listas vacías. Ahora deben mostrar vacío o un error visible.
 */
const SCREENS: Array<[string, Type<unknown>]> = [
  ['Inicio', HomeComponent],
  ['Reportes', ReportsComponent],
  ['Accesos', AccessComponent],
  ['Catálogo', CatalogComponent],
  ['Tickets', TicketsComponent],
  ['Ventas', SalesComponent],
  ['Tienda pública', PublicStoreComponent],
];

function signalsSnapshot(instance: unknown): string {
  const values: unknown[] = [];
  for (const value of Object.values(instance as Record<string, unknown>)) {
    if (isSignal(value)) {
      values.push(value());
    }
  }
  return JSON.stringify(values);
}

describe.each(SCREENS)('Pantalla %s sin datos de ejemplo', (_name, component) => {
  let http: HttpTestingController;

  async function render(reply: { body?: unknown; status?: number }) {
    TestBed.configureTestingModule({ imports: [component], providers: apiTestProviders() });
    http = TestBed.inject(HttpTestingController);
    const fixture = TestBed.createComponent(component);
    fixture.detectChanges();
    const requests = await respondAll(http, reply);
    fixture.detectChanges();
    return {
      // Texto visible + estado de todas las señales: las tablas de PrimeNG no pintan filas en jsdom,
      // así que revisar solo el DOM dejaría pasar datos de ejemplo en una tabla.
      text: `${(fixture.nativeElement as HTMLElement).textContent}\n${signalsSnapshot(fixture.componentInstance)}`,
      el: fixture.nativeElement as HTMLElement,
      requests,
    };
  }

  afterEach(() => http.verify());

  it('con respuestas vacías de la API no muestra datos de ejemplo ni error', async () => {
    const { el, text, requests } = await render({ body: { data: [] } });
    expect(requests).toBeGreaterThan(0);
    for (const mock of FORMER_MOCK_TEXTS) {
      expect(text).not.toContain(mock);
    }
    expect(el.querySelector('app-load-error-banner [role="alert"]')).toBeNull();
  });

  it('si la API falla muestra un aviso de error visible', async () => {
    const { el, text } = await render({ status: 500 });
    const alert = el.querySelector('app-load-error-banner [role="alert"]');
    expect(alert).not.toBeNull();
    expect(alert?.textContent).toMatch(/No se pud(o|ieron) cargar/);
    for (const mock of FORMER_MOCK_TEXTS) {
      expect(text).not.toContain(mock);
    }
  });
});

describe('Layout sin eventos', () => {
  it('con una organización sin eventos invita a crear uno en vez de mostrar eventos de ejemplo', async () => {
    TestBed.configureTestingModule({
      imports: [LayoutComponent],
      providers: apiTestProviders(fakeEventContext(null)),
    });
    const http = TestBed.inject(HttpTestingController);
    const fixture = TestBed.createComponent(LayoutComponent);
    fixture.detectChanges();
    await respondAll(http);
    fixture.detectChanges();
    const text = (fixture.nativeElement as HTMLElement).textContent ?? '';
    expect(text).toContain('todavía no tiene eventos');
    for (const t of FORMER_MOCK_TEXTS) {
      expect(text).not.toContain(t);
    }
  });
});
