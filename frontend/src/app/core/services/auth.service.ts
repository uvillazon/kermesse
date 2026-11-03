import { Injectable, computed, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Router } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { EventRole } from '../mock/mock-data';
import { environment } from '../../../environments/environment';

export interface AuthUser {
  id: string; // UUID v4
  name: string;
  email: string;
  orgAccess: 'owner' | 'administrator' | 'collaborator';
  roles: EventRole[];
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
    orgAccess: 'owner' | 'administrator' | 'collaborator';
    roles: EventRole[];
  };
}

const STORAGE_KEY_TOKEN = 'kermesfera_auth_token';
const STORAGE_KEY_USER = 'kermesfera_auth_user';

/**
 * Cuentas de acceso rápido SOLO para desarrollo local. Viven en `environment.development.ts`,
 * así que no existen en el paquete de producción. Cada cuenta necesita su contraseña asignada
 * localmente con `php bin/console app:users:set-password <correo>`.
 */
export const DEMO_ACCOUNTS: DemoAccount[] = environment.demoAccounts;

@Injectable({
  providedIn: 'root',
})
export class AuthService {
  private readonly http = inject(HttpClient);
  private readonly router = inject(Router);

  private readonly userSignal = signal<AuthUser | null>(this.loadStoredUser());
  private readonly tokenSignal = signal<string | null>(this.loadStoredToken());
  private readonly backendConnectedSignal = signal<boolean>(false);

  readonly currentUser = this.userSignal.asReadonly();
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
      return JSON.parse(raw) as AuthUser;
    } catch {
      return null;
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
