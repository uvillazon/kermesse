import { TestBed } from '@angular/core/testing';
import { HttpTestingController } from '@angular/common/http/testing';
import { TEST_EVENT, apiTestProviders, fakeEventContext, respondAll, settle } from '../../testing/api-test-helpers';
import { EventsComponent } from './events/events.component';
import { TicketsComponent, ApiTicket } from './tickets/tickets.component';
import { LayoutComponent } from '../core/layout/layout.component';

const DRAFT_EVENT = { ...TEST_EVENT, status: 'draft' as const, name: 'Evento borrador' };

describe('Abrir un evento en borrador', () => {
  function setup(access: string) {
    const ctx = fakeEventContext(DRAFT_EVENT);
    ctx.organization.set({ id: 'org-test', name: 'Org', slug: 'org', access });
    let refreshed = 0;
    ctx.refreshEvents = async () => {
      refreshed++;
    };
    TestBed.configureTestingModule({ imports: [EventsComponent], providers: apiTestProviders(ctx) });
    const http = TestBed.inject(HttpTestingController);
    const fixture = TestBed.createComponent(EventsComponent);
    fixture.detectChanges();
    return { fixture, http, el: fixture.nativeElement as HTMLElement, refreshed: () => refreshed };
  }

  const openButton = (el: HTMLElement) =>
    [...el.querySelectorAll('button')].find((b) => b.textContent?.includes('Abrir evento'));

  it('un propietario ve "Abrir evento" y, al confirmar, llama al backend con clave de idempotencia', async () => {
    const { fixture, http, el, refreshed } = setup('owner');
    const button = openButton(el);
    expect(button).toBeTruthy();

    (fixture.componentInstance as unknown as { askOpen: (e: unknown) => void }).askOpen(DRAFT_EVENT);
    fixture.detectChanges();
    const done = (fixture.componentInstance as unknown as { openEvent: () => Promise<void> }).openEvent();
    await settle();

    const req = http.expectOne(`/organizations/org-test/events/${TEST_EVENT.id}/opening`);
    expect(req.request.method).toBe('POST');
    expect(req.request.headers.get('Idempotency-Key')).toMatch(/^evt-open-/);
    req.flush({ data: { ...DRAFT_EVENT, status: 'open' } });
    await done;
    fixture.detectChanges();

    expect(refreshed()).toBe(1);
    expect(el.textContent).toContain('ya se puede vender y canjear');
    http.verify();
  });

  it('un colaborador no ve el botón', () => {
    const { el, http } = setup('collaborator');
    expect(openButton(el)).toBeUndefined();
    http.verify();
  });

  it('si el backend rechaza, muestra el motivo', async () => {
    const { fixture, http, el } = setup('administrator');
    const cmp = fixture.componentInstance as unknown as { askOpen: (e: unknown) => void; openEvent: () => Promise<void> };
    cmp.askOpen(DRAFT_EVENT);
    const done = cmp.openEvent();
    await settle();
    http.expectOne(() => true).flush({ error: { message: 'x' } }, { status: 409, statusText: 'Conflict' });
    await done;
    fixture.detectChanges();
    expect(el.textContent).toContain('No se pudo abrir "Evento borrador"');
    http.verify();
  });
});

describe('Aviso de evento en borrador', () => {
  it('el layout explica que todavía no se puede vender', async () => {
    TestBed.configureTestingModule({ imports: [LayoutComponent], providers: apiTestProviders(fakeEventContext(DRAFT_EVENT)) });
    const http = TestBed.inject(HttpTestingController);
    const fixture = TestBed.createComponent(LayoutComponent);
    fixture.detectChanges();
    await respondAll(http);
    fixture.detectChanges();
    expect((fixture.nativeElement as HTMLElement).textContent).toContain('está en borrador');
  });
});

describe('Botón de canje en la lista de tickets', () => {
  function ticket(id: string, status: ApiTicket['status']): ApiTicket {
    return {
      id, organization_id: 'org-test', event_id: TEST_EVENT.id, sale_id: `s-${id}`, ticket_number: `TKT-${id}`,
      qr_code: 'qr', status, buyer_email: `${id}@demo.test`, buyer_name: id, total: '10.00', currency: 'BOB',
      payment_method: 'cash', payment_status: 'confirmed', total_quantity: 1, redeemed_quantity: 0,
      remaining_quantity: 1, items_summary: '1 × Salteña', items: [], redemptions: [], issued_at: '2026-11-03 14:00:00',
    };
  }

  it('solo ofrece "Canjear" en tickets activos o con canje parcial', async () => {
    TestBed.configureTestingModule({ imports: [TicketsComponent], providers: apiTestProviders() });
    const http = TestBed.inject(HttpTestingController);
    const fixture = TestBed.createComponent(TicketsComponent);
    fixture.detectChanges();
    await settle();
    http.expectOne((r) => r.url.endsWith('/tickets')).flush({
      data: [ticket('activo', 'active'), ticket('parcial', 'partially_redeemed'), ticket('anulado', 'cancelled'), ticket('pendiente', 'pending'), ticket('canjeado', 'redeemed')],
    });
    await respondAll(http);
    const cmp = fixture.componentInstance as unknown as { tab: { set: (t: string) => void } };
    cmp.tab.set('list');
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();

    const rows = [...(fixture.nativeElement as HTMLElement).querySelectorAll('p-table.kf-stack tbody tr')];
    const actionFor = (id: string) =>
      rows.find((r) => r.textContent?.includes(`TKT-${id}`))?.querySelector('td[data-label=""] button')?.textContent?.trim();
    expect(actionFor('activo')).toBe('Canjear');
    expect(actionFor('parcial')).toBe('Canjear');
    expect(actionFor('anulado')).toBe('Ver detalle');
    expect(actionFor('pendiente')).toBe('Ver detalle');
    expect(actionFor('canjeado')).toBe('Ver detalle');
  });
});
