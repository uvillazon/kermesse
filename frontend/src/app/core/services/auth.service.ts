import { Injectable, computed, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Router } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { EventRole } from '../models/domain';

export type OrgAccess = 'owner' | 'administrator' | 'collaborator';

/** Organización vigente del usuario, tal como la devuelve `POST /auth/login`. */
export interface AuthOrganization {
  id: string;
  name: string;
  slug: string;
  access: OrgAccess;
}

export interface AuthUser {
  id: string; // UUID v4
  name: string;
  email: string;
  orgAccess: OrgAccess;
  roles: EventRole[];
  organizations: AuthOrganization[];
  avatarInitials: string;
}

export interface DemoAccount {
  user: AuthUser;
  password: string;
  roleLabel: string;
  description: string;
}

interface BackendLoginResponse {
  token: string;
  user: {
    id: string;
    email: string;
    name: string;
    orgAccess: OrgAccess;
    roles: EventRole[];
    organizations: AuthOrganization[];
  };
}

const STORAGE_KEY_TOKEN = 'kermesfera_auth_token';
const STORAGE_KEY_USER = 'kermesfera_auth_user';
/** Organización elegida por este navegador; solo una preferencia, se valida contra el login. */
const STORAGE_KEY_ORG = 'kermesfera_active_org';

export const DEMO_ACCOUNTS: DemoAccount[] = [
  {
    user: {
      id: '018f3a12-1001-4000-8000-000000000001',
      name: 'Carmen Gutiérrez',
      email: 'direccion@saintandrews.edu.bo',
      orgAccess: 'owner',
      roles: ['EVENT_MANAGER', 'FINANCE_REVIEWER'],
      organizations: [],
      avatarInitials: 'CG',
    },
    password: 'password123',
    roleLabel: 'Propietaria / Responsable',
    description: 'Acceso total: gestión, finanzas, catálogo y cierre del evento.',
  },
  {
    user: {
      id: '018f3a12-1002-4000-8000-000000000002',
      name: 'María Rojas',
      email: 'maria.rojas@gmail.com',
      orgAccess: 'collaborator',
      roles: ['SALES_OPERATOR', 'TICKET_OPERATOR'],
      organizations: [],
      avatarInitials: 'MR',
    },
    password: 'password123',
    roleLabel: 'Operadora de Ventas y Tickets',
    description: 'Venta rápida en puesto y escaneo/canje de boletos QR.',
  },
  {
    user: {
      id: '018f3a12-1003-4000-8000-000000000003',
      name: 'Patricia Soliz',
      email: 'psoliz@saintandrews.edu.bo',
      orgAccess: 'collaborator',
      roles: ['PAYMENT_REVIEWER'],
      organizations: [],
      avatarInitials: 'PS',
    },
    password: 'password123',
    roleLabel: 'Verificadora de Pagos QR',
    description: 'Revisión y confirmación de transferencias bancarias y códigos QR.',
  },
];

@Injectable({
  providedIn: 'root',
})
export class AuthService {
  private readonly http = inject(HttpClient);
  private readonly router = inject(Router);

  private readonly userSignal = signal<AuthUser | null>(this.loadStoredUser());
  private readonly tokenSignal = signal<string | null>(this.loadStoredToken());
  private readonly backendConnectedSignal = signal<boolean>(false);
  private readonly preferredOrgId = signal<string | null>(this.readStorage(STORAGE_KEY_ORG));

  readonly currentUser = this.userSignal.asReadonly();
  readonly organizations = computed(() => this.userSignal()?.organizations ?? []);
  /**
   * Organización activa: la preferida si el usuario sigue perteneciendo a ella, si no la primera.
   * `null` si el usuario no pertenece a ninguna organización vigente.
   */
  readonly activeOrganization = computed<AuthOrganization | null>(() => {
    const list = this.organizations();
    return list.find((o) => o.id === this.preferredOrgId()) ?? list[0] ?? null;
  });
  readonly token = this.tokenSignal.asReadonly();
  readonly isAuthenticated = computed(() => !!this.userSignal() && !!this.tokenSignal());
  readonly isBackendConnected = this.backendConnectedSignal.asReadonly();

  constructor() {
    this.checkBackendHealth();
  }

  async checkBackendHealth(): Promise<boolean> {
    try {
      const res = await firstValueFrom(
        this.http.get<{ status: string; service: string }>('/api/v1/health')
      );
      const ok = res?.status === 'ok';
      this.backendConnectedSignal.set(ok);
      return ok;
    } catch {
      this.backendConnectedSignal.set(false);
      return false;
    }
  }

  async login(email: string, password: string): Promise<boolean> {
    try {
      // 1. Autenticación real contra Symfony backend (POST /api/v1/auth/login)
      const res = await firstValueFrom(
        this.http.post<BackendLoginResponse>('/api/v1/auth/login', {
          email: email.trim(),
          password,
        })
      );

      if (res && res.token && res.user) {
        const authUser: AuthUser = {
          id: res.user.id,
          name: res.user.name,
          email: res.user.email,
          orgAccess: res.user.orgAccess,
          roles: res.user.roles,
          organizations: Array.isArray(res.user.organizations) ? res.user.organizations : [],
          avatarInitials: this.extractInitials(res.user.name),
        };
        this.setSession(authUser, res.token);
        this.backendConnectedSignal.set(true);
        return true;
      }
      throw new Error('Respuesta inválida del servidor de autenticación.');
    } catch (err: unknown) {
      console.error('[AuthService] Error de autenticación contra backend:', err);
      // Re-lanzar error para que la UI informe adecuadamente al usuario
      throw err;
    }
  }

  async loginWithDemo(account: DemoAccount): Promise<void> {
    await this.login(account.user.email, account.password);
  }

  selectOrganization(id: string): void {
    if (!this.organizations().some((o) => o.id === id)) {
      return;
    }
    this.preferredOrgId.set(id);
    this.writeStorage(STORAGE_KEY_ORG, id);
  }

  logout(): void {
    localStorage.removeItem(STORAGE_KEY_TOKEN);
    localStorage.removeItem(STORAGE_KEY_USER);
    this.tokenSignal.set(null);
    this.userSignal.set(null);
    this.router.navigate(['/login']);
  }

  private setSession(user: AuthUser, token: string): void {
    localStorage.setItem(STORAGE_KEY_TOKEN, token);
    localStorage.setItem(STORAGE_KEY_USER, JSON.stringify(user));
    this.tokenSignal.set(token);
    this.userSignal.set(user);
  }

  private loadStoredToken(): string | null {
    const token = localStorage.getItem(STORAGE_KEY_TOKEN);
    // Si contiene firma simulada antigua de pruebas previas, descartarlo para exigir token real de Symfony
    if (token && token.includes('eyJzaWciOiJtb2NrIn0')) {
      localStorage.removeItem(STORAGE_KEY_TOKEN);
      localStorage.removeItem(STORAGE_KEY_USER);
      return null;
    }
    return token;
  }

  private loadStoredUser(): AuthUser | null {
    const token = localStorage.getItem(STORAGE_KEY_TOKEN);
    if (!token || token.includes('eyJzaWciOiJtb2NrIn0')) {
      return null;
    }
    const raw = localStorage.getItem(STORAGE_KEY_USER);
    if (!raw) return null;
    try {
      const user = JSON.parse(raw) as AuthUser;
      // Sesiones guardadas antes de que el login devolviera organizaciones: pedir login de nuevo.
      if (!Array.isArray(user?.organizations)) {
        localStorage.removeItem(STORAGE_KEY_TOKEN);
        localStorage.removeItem(STORAGE_KEY_USER);
        return null;
      }
      return user;
    } catch {
      return null;
    }
  }

  private readStorage(key: string): string | null {
    try {
      return localStorage.getItem(key);
    } catch {
      return null;
    }
  }

  private writeStorage(key: string, value: string): void {
    try {
      localStorage.setItem(key, value);
    } catch {
      // Sin almacenamiento disponible: la preferencia dura solo esta sesión.
    }
  }

  private extractInitials(name: string): string {
    if (!name) return 'US';
    return name
      .split(' ')
      .map((p) => p[0])
      .slice(0, 2)
      .join('')
      .toUpperCase();
  }
}
