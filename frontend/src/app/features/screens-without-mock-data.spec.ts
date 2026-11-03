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

describe('Layout sin organización', () => {
  it('si el usuario no pertenece a ninguna organización lo dice en vez de mostrar pantallas', async () => {
    const ctx = fakeEventContext(null);
    ctx.organization.set(null);
    TestBed.configureTestingModule({ imports: [LayoutComponent], providers: apiTestProviders(ctx) });
    const http = TestBed.inject(HttpTestingController);
    const fixture = TestBed.createComponent(LayoutComponent);
    fixture.detectChanges();
    await respondAll(http);
    fixture.detectChanges();
    const el = fixture.nativeElement as HTMLElement;
    expect(el.textContent).toContain('no pertenece a ninguna organización activa');
    expect(el.querySelector('router-outlet')).toBeNull();
  });
});

describe('Tablas de PrimeNG', () => {
  // Con la sintaxis vieja `pTemplate="body"`, PrimeNG 22 no pintaba filas ni cabeceras (ni en el navegador).
  it('Reportes pinta las filas de gastos que devuelve la API', async () => {
    TestBed.configureTestingModule({ imports: [ReportsComponent], providers: apiTestProviders() });
    const http = TestBed.inject(HttpTestingController);
    const fixture = TestBed.createComponent(ReportsComponent);
    fixture.detectChanges();
    await respondAll(http, { body: { data: [] } }).catch(() => 0);
    fixture.componentInstance['expenseRows'].set([{ id: 'g1', concept: 'Gasto de prueba visible', category: 'Insumos', amount: 10 }]);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
    const el = fixture.nativeElement as HTMLElement;
    expect(el.textContent).toContain('Gasto de prueba visible');
    expect(el.textContent).toContain('Concepto');

    // Modo tarjeta en celular: la tabla lleva la clase en el host (PrimeNG 22 ignora styleClass)
    // y cada celda su etiqueta.
    const row = [...el.querySelectorAll('p-table.kf-stack tbody tr')].find((tr) => tr.textContent?.includes('Gasto de prueba visible'));
    expect(row).toBeTruthy();
    const labels = [...(row as HTMLElement).querySelectorAll('td')].map((td) => td.getAttribute('data-label'));
    expect(labels).toEqual(['Concepto', 'Categoría', 'Monto']);
  });
});

describe('Ventas en celular', () => {
  it('la barra de cobro aparece solo con productos en el pedido y muestra el total', async () => {
    TestBed.configureTestingModule({ imports: [SalesComponent], providers: apiTestProviders() });
    const http = TestBed.inject(HttpTestingController);
    const fixture = TestBed.createComponent(SalesComponent);
    fixture.detectChanges();
    await respondAll(http);
    const cmp = fixture.componentInstance as unknown as {
      products: { set: (v: unknown[]) => void };
      add: (id: string) => void;
    };
    cmp.products.set([
      { id: 'p1', code: 'BEB-01', name: 'Refresco', stand_id: 's1', stand_name: 'Bebidas', unit_price: 1250.5, stock: 10, sold: 0, available_quantity: 10, active: true },
    ]);
    fixture.detectChanges();
    const el = fixture.nativeElement as HTMLElement;
    expect(el.querySelector('.mobile-action-bar')).toBeNull();

    cmp.add('p1');
    cmp.add('p1');
    fixture.detectChanges();
    const bar = el.querySelector('.mobile-action-bar');
    expect(bar).not.toBeNull();
    expect(bar?.textContent).toContain('2 ítems');
    expect(bar?.textContent).toContain('Bs 2.501,00');
    expect(el.querySelector('.in-cart')?.textContent?.trim()).toBe('2');
  });
});
