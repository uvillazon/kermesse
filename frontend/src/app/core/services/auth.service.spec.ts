import { TestBed } from '@angular/core/testing';
import { HttpTestingController } from '@angular/common/http/testing';
import { apiTestProviders, settle } from '../../../testing/api-test-helpers';
import { AuthOrganization, AuthService } from './auth.service';

const ORG_A: AuthOrganization = { id: 'org-a', name: 'Alfa', slug: 'alfa', access: 'owner' };
const ORG_B: AuthOrganization = { id: 'org-b', name: 'Beta', slug: 'beta', access: 'collaborator' };

describe('AuthService — organización del login', () => {
  let http: HttpTestingController;

  function setup(): AuthService {
    TestBed.configureTestingModule({ providers: apiTestProviders(null) });
    http = TestBed.inject(HttpTestingController);
    const auth = TestBed.inject(AuthService);
    http.match('/api/v1/health').forEach((r) => r.flush({ status: 'ok' }));
    return auth;
  }

  async function login(auth: AuthService, organizations: AuthOrganization[]): Promise<void> {
    const done = auth.login('ana@example.test', 'password123');
    await settle();
    http.expectOne('/api/v1/auth/login').flush({
      token: 'header.payload.signature',
      user: { id: 'u1', email: 'ana@example.test', name: 'Ana Pérez', orgAccess: organizations[0]?.access ?? 'collaborator', roles: [], organizations },
    });
    await done;
  }

  beforeEach(() => localStorage.clear());
  afterEach(() => {
    http.verify();
    localStorage.clear();
  });

  it('toma la primera organización del login como activa', async () => {
    const auth = setup();
    await login(auth, [ORG_A, ORG_B]);
    expect(auth.organizations()).toEqual([ORG_A, ORG_B]);
    expect(auth.activeOrganization()).toEqual(ORG_A);
  });

  it('recuerda la organización elegida y la ignora si el usuario ya no pertenece a ella', async () => {
    const auth = setup();
    await login(auth, [ORG_A, ORG_B]);
    auth.selectOrganization(ORG_B.id);
    expect(auth.activeOrganization()).toEqual(ORG_B);
    expect(localStorage.getItem('kermesfera_active_org')).toBe(ORG_B.id);

    // Una organización ajena no se puede seleccionar.
    auth.selectOrganization('org-ajena');
    expect(auth.activeOrganization()).toEqual(ORG_B);

    // Nuevo login donde ya no pertenece a B: vuelve a la primera.
    await login(auth, [ORG_A]);
    expect(auth.activeOrganization()).toEqual(ORG_A);
  });

  it('sin organizaciones vigentes no inventa una', async () => {
    const auth = setup();
    await login(auth, []);
    expect(auth.activeOrganization()).toBeNull();
  });

  it('descarta una sesión guardada antes de que el login devolviera organizaciones', () => {
    localStorage.setItem('kermesfera_auth_token', 'header.payload.signature');
    localStorage.setItem('kermesfera_auth_user', JSON.stringify({ id: 'u1', name: 'Ana', email: 'a@e.t', orgAccess: 'owner', roles: [] }));
    const auth = setup();
    expect(auth.currentUser()).toBeNull();
    expect(auth.isAuthenticated()).toBe(false);
    expect(localStorage.getItem('kermesfera_auth_token')).toBeNull();
  });
});
