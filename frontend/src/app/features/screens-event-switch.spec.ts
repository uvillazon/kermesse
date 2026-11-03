import { Type, isSignal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { HttpTestingController } from '@angular/common/http/testing';
import { KEvent } from '../core/models/domain';
import { TEST_EVENT, apiTestProviders, fakeEventContext, settle } from '../../testing/api-test-helpers';
import { HomeComponent } from './home/home.component';
import { ReportsComponent } from './reports/reports.component';
import { AccessComponent } from './access/access.component';
import { CatalogComponent } from './catalog/catalog.component';
import { TicketsComponent } from './tickets/tickets.component';
import { SalesComponent } from './sales/sales.component';

/**
 * Al cambiar de evento en la barra superior, cada pantalla debe pedir los datos del evento nuevo
 * y descartar las respuestas que lleguen tarde del evento anterior.
 */
const EVENT_B: KEvent = { ...TEST_EVENT, id: 'evt-b', slug: 'evento-b', name: 'Evento B' };
const STALE_MARKER = 'DATO-DEL-EVENTO-ANTERIOR';

const SCREENS: Array<[string, Type<unknown>]> = [
  ['Inicio', HomeComponent],
  ['Reportes', ReportsComponent],
  ['Accesos', AccessComponent],
  ['Catálogo', CatalogComponent],
  ['Tickets', TicketsComponent],
  ['Ventas', SalesComponent],
];

function signalsSnapshot(instance: unknown): string {
  return JSON.stringify(
    Object.values(instance as Record<string, unknown>)
      .filter((v) => isSignal(v))
      .map((v) => (v as () => unknown)())
  );
}

describe.each(SCREENS)('Pantalla %s al cambiar de evento', (_name, component) => {
  it('pide los datos del evento nuevo y descarta la respuesta tardía del anterior', async () => {
    const ctx = fakeEventContext();
    TestBed.configureTestingModule({ imports: [component], providers: apiTestProviders(ctx) });
    const http = TestBed.inject(HttpTestingController);
    const fixture = TestBed.createComponent(component);
    fixture.detectChanges();
    await settle();

    // Peticiones del evento A, todavía sin responder.
    const pendingA = http.match((r) => r.url.includes(`/events/${TEST_EVENT.id}/`));
    expect(pendingA.length).toBeGreaterThan(0);

    // El usuario cambia al evento B antes de que A responda.
    ctx.active.set(EVENT_B);
    fixture.detectChanges();
    await settle();
    const pendingB = http.match((r) => r.url.includes(`/events/${EVENT_B.id}/`));
    expect(pendingB.length).toBe(pendingA.length);

    // B responde primero (vacío) y A llega después con datos propios: es el caso que pisaba la pantalla.
    pendingB.forEach((r) => r.flush({ data: [] }));
    // Peticiones a nivel organización (p. ej. invitaciones).
    http.match(() => true).forEach((r) => r.flush({ data: [] }));
    await settle();
    const stale = [{ id: 'stale-1', name: STALE_MARKER, concept: STALE_MARKER, email: STALE_MARKER }];
    pendingA.forEach((r) => r.flush({ data: stale }));
    await settle();
    fixture.detectChanges();

    expect(signalsSnapshot(fixture.componentInstance)).not.toContain(STALE_MARKER);
    expect((fixture.nativeElement as HTMLElement).textContent).not.toContain(STALE_MARKER);
    http.verify();
  });
});
