import { Component, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterModule } from '@angular/router';
import { ButtonModule } from 'primeng/button';
import { InputTextModule } from 'primeng/inputtext';
import { PasswordModule } from 'primeng/password';
import { CheckboxModule } from 'primeng/checkbox';
import { AuthService, DEMO_ACCOUNTS, DemoAccount } from '../../core/services/auth.service';

@Component({
  selector: 'app-login',
  standalone: true,
  imports: [
    CommonModule,
    FormsModule,
    RouterModule,
    ButtonModule,
    InputTextModule,
    PasswordModule,
    CheckboxModule,
  ],
  template: `
    <div class="auth-page">
      <div class="auth-card">
        <!-- Encabezado con marca Kermesfera -->
        <header class="auth-header">
          <div class="brand">
            <span class="logo"><i class="pi pi-globe"></i></span>
            <span class="brand-name">Kermes<b>fera</b></span>
          </div>
          <h1>Iniciar sesión</h1>
          <p class="subtitle">Ingresa a la plataforma de gestión y operación de eventos</p>
        </header>

        <!-- Mensaje de error si falla -->
        @if (errorMessage()) {
          <div class="auth-alert error">
            <i class="pi pi-exclamation-circle"></i>
            <span>{{ errorMessage() }}</span>
          </div>
        }

        <!-- Formulario principal -->
        <form class="auth-form" (ngSubmit)="submitLogin()">
          <div class="form-row">
            <label for="email">Correo electrónico</label>
            <input
              pInputText
              id="email"
              type="email"
              name="email"
              [(ngModel)]="email"
              placeholder="tu.correo@colegio.edu.bo"
              required
              class="w-full"
            />
          </div>

          <div class="form-row">
            <div class="password-label-row">
              <label for="password">Contraseña</label>
              <a href="javascript:void(0)" class="forgot-link" (click)="forgotPassword()">¿Olvidaste tu contraseña?</a>
            </div>
            <input
              pInputText
              id="password"
              type="password"
              name="password"
              [(ngModel)]="password"
              placeholder="••••••••"
              required
              class="w-full"
            />
          </div>

          <div class="remember-row">
            <div class="checkbox-wrap">
              <input type="checkbox" id="remember" [(ngModel)]="rememberMe" name="rememberMe" />
              <label for="remember">Recordar mi sesión</label>
            </div>
          </div>

          <p-button
            [fluid]="true"
            label="Ingresar al Panel"
            icon="pi pi-sign-in"
            type="submit"
            [loading]="loading()"
          />
        </form>

        <div class="divider">
          <span>O ingresa con un rol de demostración</span>
        </div>

        <!-- Cuentas de demostración de un solo clic -->
        <div class="demo-accounts">
          @for (acc of demoList; track acc.user.id) {
            <button
              type="button"
              class="demo-card"
              (click)="selectDemo(acc)"
              [disabled]="loading()"
            >
              <div class="demo-av">{{ acc.user.avatarInitials }}</div>
              <div class="demo-info">
                <span class="demo-name">{{ acc.user.name }}</span>
                <span class="demo-role">{{ acc.roleLabel }}</span>
                <small class="demo-desc">{{ acc.description }}</small>
              </div>
              <i class="pi pi-chevron-right demo-arrow"></i>
            </button>
          }
        </div>

        <!-- Pie con enlace a tienda pública -->
        <footer class="auth-footer">
          <p>
            ¿Eres comprador o padre de familia?
            <a routerLink="/tienda/halloween-2026">Ir a la tienda pública de compra →</a>
          </p>
          <div class="security-badge" [class.backend-ok]="authService.isBackendConnected()">
            <i class="pi" [class.pi-check-circle]="authService.isBackendConnected()" [class.pi-shield]="!authService.isBackendConnected()"></i>
            <span>{{ authService.isBackendConnected() ? 'Conexión segura' : 'Sin conexión con el servidor' }}</span>
          </div>
        </footer>
      </div>
    </div>
  `,
  styles: [`
    .auth-page {
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      background: linear-gradient(135deg, #063b73 0%, #102a56 100%);
      padding: 1.5rem;
    }

    .auth-card {
      width: 100%;
      max-width: 480px;
      background: #ffffff;
      border-radius: 18px;
      box-shadow: 0 16px 40px rgba(0, 0, 0, 0.2);
      padding: 2.25rem 2rem;
      display: flex;
      flex-direction: column;
      gap: 1.25rem;
    }

    .auth-header {
      text-align: center;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 0.35rem;

      h1 {
        font-size: 1.5rem;
        font-weight: 800;
        color: #063b73;
        margin: 0.5rem 0 0.15rem;
      }

      .subtitle {
        color: #64748b;
        font-size: 0.88rem;
        margin: 0;
      }
    }

    .brand {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      font-size: 1.3rem;
      font-weight: 800;
      color: #063b73;

      b {
        color: #ffc400;
      }
    }

    .logo {
      width: 38px;
      height: 38px;
      border-radius: 50%;
      background: #ffc400;
      color: #063b73;
      display: grid;
      place-items: center;
      font-size: 1.15rem;
    }

    .auth-alert {
      display: flex;
      align-items: center;
      gap: 0.65rem;
      padding: 0.75rem 1rem;
      border-radius: 10px;
      font-size: 0.84rem;

      &.error {
        background: #fdecea;
        color: #b42318;
        border: 1px solid #f6c6c0;
      }
    }

    .auth-form {
      display: flex;
      flex-direction: column;
      gap: 1rem;
    }

    .form-row {
      display: flex;
      flex-direction: column;
      gap: 0.35rem;

      label {
        font-size: 0.82rem;
        font-weight: 700;
        color: #334155;
      }
    }

    .password-label-row {
      display: flex;
      justify-content: space-between;
      align-items: center;

      .forgot-link {
        font-size: 0.75rem;
        color: #063b73;
        text-decoration: none;
        font-weight: 600;

        &:hover {
          text-decoration: underline;
        }
      }
    }

    .remember-row {
      display: flex;
      align-items: center;
      font-size: 0.82rem;
      color: #475569;

      .checkbox-wrap {
        display: flex;
        align-items: center;
        gap: 0.45rem;
        cursor: pointer;

        input {
          cursor: pointer;
        }
      }
    }

    .divider {
      position: relative;
      text-align: center;
      margin: 0.5rem 0;

      &::before {
        content: '';
        position: absolute;
        left: 0;
        top: 50%;
        width: 100%;
        height: 1px;
        background: #e2e8f0;
      }

      span {
        position: relative;
        background: #ffffff;
        padding: 0 0.75rem;
        font-size: 0.74rem;
        color: #94a3b8;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.05em;
      }
    }

    .demo-accounts {
      display: flex;
      flex-direction: column;
      gap: 0.6rem;
    }

    .demo-card {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      padding: 0.65rem 0.85rem;
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 12px;
      cursor: pointer;
      text-align: left;
      font: inherit;
      transition: all 0.15s ease;

      &:hover:not(:disabled) {
        background: #eef4fb;
        border-color: #bfdbfe;
        transform: translateY(-1px);
      }

      &:disabled {
        opacity: 0.6;
        cursor: not-allowed;
      }
    }

    .demo-av {
      width: 36px;
      height: 36px;
      border-radius: 50%;
      background: #063b73;
      color: #ffffff;
      font-weight: 800;
      font-size: 0.8rem;
      display: grid;
      place-items: center;
      flex-shrink: 0;
    }

    .demo-info {
      display: flex;
      flex-direction: column;
      flex: 1;
      min-width: 0;
    }

    .demo-name {
      font-size: 0.85rem;
      font-weight: 700;
      color: #1e293b;
      line-height: 1.2;
    }

    .demo-role {
      font-size: 0.75rem;
      color: #063b73;
      font-weight: 600;
    }

    .demo-desc {
      font-size: 0.7rem;
      color: #64748b;
      margin-top: 0.1rem;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .demo-arrow {
      color: #94a3b8;
      font-size: 0.8rem;
    }

    .auth-footer {
      text-align: center;
      font-size: 0.82rem;
      color: #64748b;
      display: flex;
      flex-direction: column;
      gap: 0.75rem;
      margin-top: 0.25rem;

      p {
        margin: 0;
      }

      a {
        color: #063b73;
        font-weight: 700;
        text-decoration: none;

        &:hover {
          text-decoration: underline;
        }
      }
    }

    .security-badge {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 0.4rem;
      font-size: 0.72rem;
      color: #64748b;
      background: #f1f5f9;
      padding: 0.35rem 0.75rem;
      border-radius: 999px;

      i {
        color: #15803d;
      }
    }
  `]
})
export class LoginComponent {
  protected readonly authService = inject(AuthService);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);

  protected readonly demoList = DEMO_ACCOUNTS;
  protected email = 'direccion@saintandrews.edu.bo';
  protected password = 'password123';
  protected rememberMe = true;

  protected readonly loading = signal(false);
  protected readonly errorMessage = signal<string | null>(null);

  async submitLogin(): Promise<void> {
    if (!this.email || !this.password) {
      this.errorMessage.set('Por favor completa tu correo y contraseña.');
      return;
    }

    this.loading.set(true);
    this.errorMessage.set(null);

    try {
      await this.authService.login(this.email, this.password);
      this.redirectToDestination();
    } catch (err: unknown) {
      const anyErr = err as { message?: string; originalError?: { error?: { message?: string } } };
      const msg = anyErr?.originalError?.error?.message || anyErr?.message || (err instanceof Error ? err.message : 'No pudimos iniciar sesión. Revisa tu correo y contraseña.');
      this.errorMessage.set(msg);
    } finally {
      this.loading.set(false);
    }
  }

  async selectDemo(account: DemoAccount): Promise<void> {
    this.email = account.user.email;
    this.password = account.password;
    await this.submitLogin();
  }

  forgotPassword(): void {
    alert('En el prototipo de Kermesfera, utiliza una de las cuentas demo de acceso rápido.');
  }

  private redirectToDestination(): void {
    const returnUrl = this.route.snapshot.queryParams['returnUrl'] || '/home';
    this.router.navigateByUrl(returnUrl);
  }
}
