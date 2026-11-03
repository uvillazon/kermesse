import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { HttpTestingController } from '@angular/common/http/testing';
import { apiTestProviders, settle } from '../../../testing/api-test-helpers';
import { AuthOrganization, AuthService } from './auth.service';
import { EventContextService } from './event-context.service';

const ORG_A: AuthOrganization = { id: 'org-a', name: 'Org A', slug: 'org-a', access: 'owner' };
const ORG_B: AuthOrganization = { id: 'org-b', name: 'Org B', slug: 'org-b', access: 'collaborator' };
const EVENT = (id: string) => ({ id, slug: id, name: `Evento ${id}`, starts_at: '2027-01-01T14:00:00+00:00', date: '01 Jan 2027', status: 'open', stands: 0 });

describe('EventContextService', () => {
  let http: HttpTestingController;
  let activeOrganization: ReturnType<typeof signal<AuthOrganization | null>>;

  async function setup(org: AuthOrganization | null = ORG_A): Promise<EventContextService> {
    activeOrganization = signal<AuthOrganization | null>(org);
    TestBed.configureTestingModule({
      providers: [...apiTestProviders(null), { provide: AuthService, useValue: { activeOrganization } }],
    });
    http = TestBed.inject(HttpTestingController);
    const ctx = TestBed.inject(EventContextService);
    TestBed.tick();
    await settle();
    return ctx;
  }

  function eventsRequest(orgId: string) {
    return http.expectOne(`/api/v1/organizations/${orgId}/events`);
  }

  afterEach(() => http.verify());

  it('no tiene evento activo mientras carga', async () => {
    const ctx = await setup();
    expect(ctx.state()).toBe('loading');
    expect(ctx.active()).toBeNull();
    eventsRequest(ORG_A.id).flush({ data: [] });
  });

  it('pide los eventos de la organización que vino en el login, no de un id fijo', async () => {
    const ctx = await setup();
    expect(ctx.orgId).toBe(ORG_A.id);
    eventsRequest(ORG_A.id).flush({ data: [EVENT('e1')] });
    await settle();
    expect(ctx.active()?.id).toBe('e1');
    expect(ctx.requireActive().name).toBe('Evento e1');
  });

  it('con una organización sin eventos queda lista y sin evento activo (sin eventos de ejemplo)', async () => {
    const ctx = await setup();
    eventsRequest(ORG_A.id).flush({ data: [] });
    await settle();
    expect(ctx.state()).toBe('ready');
    expect(ctx.all()).toEqual([]);
    expect(ctx.active()).toBeNull();
    expect(() => ctx.requireActive()).toThrow();
  });

  it('si la API falla expone el error y no inventa eventos', async () => {
    const ctx = await setup();
    eventsRequest(ORG_A.id).flush({ message: 'x' }, { status: 500, statusText: 'Error' });
    await settle();
    expect(ctx.state()).toBe('error');
    expect(ctx.all()).toEqual([]);
    expect(ctx.error()).toContain('No se pudieron cargar los eventos');
  });

  it('sin organización vigente no pide eventos y orgId no se inventa', async () => {
    const ctx = await setup(null);
    expect(ctx.organization()).toBeNull();
    expect(ctx.state()).toBe('ready');
    expect(ctx.active()).toBeNull();
    expect(() => ctx.orgId).toThrow();
  });

  it('al cambiar de organización recarga sus eventos y descarta la respuesta tardía de la anterior', async () => {
    const ctx = await setup();
    const lateA = eventsRequest(ORG_A.id);

    activeOrganization.set(ORG_B);
    TestBed.tick();
    await settle();
    eventsRequest(ORG_B.id).flush({ data: [EVENT('b1')] });
    await settle();
    lateA.flush({ data: [EVENT('a1')] });
    await settle();

    expect(ctx.orgId).toBe(ORG_B.id);
    expect(ctx.all().map((e) => e.id)).toEqual(['b1']);
    expect(ctx.active()?.id).toBe('b1');
  });
});
