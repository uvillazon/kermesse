import { TestBed } from '@angular/core/testing';
import { HttpTestingController } from '@angular/common/http/testing';
import { apiTestProviders, respondAll, settle } from '../../../testing/api-test-helpers';
import { EventContextService } from './event-context.service';

describe('EventContextService', () => {
  let http: HttpTestingController;

  function setup(): EventContextService {
    TestBed.configureTestingModule({ providers: apiTestProviders(null) });
    http = TestBed.inject(HttpTestingController);
    return TestBed.inject(EventContextService);
  }

  afterEach(() => http.verify());

  it('no tiene evento activo mientras carga', () => {
    const ctx = setup();
    expect(ctx.state()).toBe('loading');
    expect(ctx.active()).toBeNull();
    http.match(() => true).forEach((r) => r.flush({ data: [] }));
  });

  it('con una organización sin eventos queda lista y sin evento activo (sin eventos de ejemplo)', async () => {
    const ctx = setup();
    await respondAll(http, { body: { data: [] } });
    expect(ctx.state()).toBe('ready');
    expect(ctx.all()).toEqual([]);
    expect(ctx.active()).toBeNull();
    expect(() => ctx.requireActive()).toThrow();
  });

  it('usa los eventos de la API y selecciona el primero', async () => {
    const ctx = setup();
    await respondAll(http, {
      body: { data: [{ id: 'e1', slug: 's1', name: 'Real', date: '01 ene 2027', status: 'open', stands: 1 }] },
    });
    expect(ctx.active()?.id).toBe('e1');
    expect(ctx.requireActive().name).toBe('Real');
  });

  it('si la API falla expone el error y no inventa eventos', async () => {
    const ctx = setup();
    await respondAll(http, { status: 500 });
    await settle();
    expect(ctx.state()).toBe('error');
    expect(ctx.all()).toEqual([]);
    expect(ctx.error()).toContain('No se pudieron cargar los eventos');
  });
});
