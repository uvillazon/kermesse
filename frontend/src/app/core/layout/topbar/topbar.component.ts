import { Component, inject, output } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterModule } from '@angular/router';
import { EventContextService } from '../../services/event-context.service';
import { AuthService } from '../../services/auth.service';
import { EVENT_STATUS } from '../../models/domain';

@Component({
  selector: 'app-topbar',
  standalone: true,
  imports: [FormsModule, RouterModule],
  template: `
    <header class="topbar">
      <div class="left">
        <button class="icon-btn" (click)="toggleSidebar.emit()" aria-label="Alternar menú">
          <i class="pi pi-bars"></i>
        </button>
        <a routerLink="/home" class="brand">
          <span class="logo"><i class="pi pi-globe"></i></span>
          <span class="brand-name">Kermes<b>fera</b></span>
        </a>
        @if (ctx.organization(); as org) {
          <span class="divider"></span>
          <span class="org">
            <span class="org-badge">{{ initials(org.name) }}</span>
            @if (auth.organizations().length > 1) {
              <select
                class="org-select"
                [ngModel]="org.id"
                (ngModelChange)="auth.selectOrganization($event)"
                aria-label="Organización activa"
              >
                @for (o of auth.organizations(); track o.id) {
                  <option [value]="o.id">{{ o.name }}</option>
                }
              </select>
            } @else {
              <span class="org-name">{{ org.name }}</span>
            }
          </span>
        }
      </div>

      <div class="center">
        @if (ctx.active(); as ev) {
          <label class="event-select">
            <i class="pi pi-calendar"></i>
            <select [ngModel]="ev.id" (ngModelChange)="ctx.select($event)" aria-label="Evento activo">
              @for (e of ctx.all(); track e.id) {
                <option [value]="e.id">{{ e.name }}</option>
              }
            </select>
          </label>
          <span [class]="'pill ' + (status[ev.status]?.css ?? 'gray')">
            {{ status[ev.status]?.label ?? ev.status }}
          </span>
        }
      </div>

      <div class="right">
        @if (ctx.active(); as ev) {
          <a
            class="icon-btn open-store"
            [routerLink]="['/tienda', ev.slug]"
            target="_blank"
            title="Ver página pública de compra"
          >
            <i class="pi pi-external-link"></i>
          </a>
        }

        @if (auth.currentUser(); as user) {
          <div class="user-block">
            <span class="avatar">{{ user.avatarInitials }}</span>
            <span class="user">
              <b>{{ user.name }}</b>
              <small>{{ getOrgAccessLabel(ctx.organization()?.access ?? user.orgAccess) }}</small>
            </span>
            <button
              class="icon-btn logout-btn"
              (click)="auth.logout()"
              title="Cerrar sesión"
              aria-label="Cerrar sesión"
            >
              <i class="pi pi-sign-out"></i>
            </button>
          </div>
        } @else {
          <a routerLink="/login" class="login-link">Iniciar sesión</a>
        }
      </div>
    </header>
  `,
  styles: [`
    .topbar {
      height: 64px;
      background: var(--kf-primary);
      color: #fff;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
      padding: 0 1.25rem;
      position: sticky;
      top: 0;
      z-index: 100;
    }

    .left, .center, .right {
      display: flex;
      align-items: center;
      gap: 0.75rem;
    }

    .icon-btn {
      width: 36px;
      height: 36px;
      border-radius: 9px;
      border: 1px solid rgba(255, 255, 255, 0.2);
      background: transparent;
      color: #fff;
      display: grid;
      place-items: center;
      cursor: pointer;
      transition: background 0.15s ease;

      &:hover {
        background: rgba(255, 255, 255, 0.12);
      }
    }

    .brand {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      font-size: 1.15rem;

      b {
        color: var(--kf-accent);
      }
    }

    .logo {
      width: 32px;
      height: 32px;
      border-radius: 50%;
      background: var(--kf-accent);
      color: var(--kf-primary);
      display: grid;
      place-items: center;
    }

    .divider {
      width: 1px;
      height: 26px;
      background: rgba(255, 255, 255, 0.25);
    }

    .org {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      font-size: 0.85rem;
    }
    .org-select {
      font: inherit;
      font-weight: 600;
      border: 1px solid var(--kf-line);
      border-radius: 8px;
      padding: 0.25rem 0.5rem;
      background: transparent;
      color: inherit;
      max-width: 220px;
    }

    .org-badge {
      background: #fff;
      color: var(--kf-primary);
      border-radius: 7px;
      padding: 0.15rem 0.4rem;
      font-weight: 800;
      font-size: 0.72rem;
    }

    .event-select {
      display: flex;
      align-items: center;
      gap: 0.4rem;
      background: rgba(255, 255, 255, 0.1);
      border: 1px solid rgba(255, 255, 255, 0.2);
      border-radius: 9px;
      padding: 0.35rem 0.6rem;

      select {
        background: transparent;
        color: #fff;
        border: 0;
        font: inherit;
        font-size: 0.85rem;
        outline: none;
      }

      option {
        color: var(--kf-ink);
      }
    }

    .user-block {
      display: flex;
      align-items: center;
      gap: 0.55rem;
      background: rgba(255, 255, 255, 0.08);
      padding: 0.25rem 0.45rem 0.25rem 0.65rem;
      border-radius: 30px;
      border: 1px solid rgba(255, 255, 255, 0.15);
    }

    .avatar {
      width: 30px;
      height: 30px;
      border-radius: 50%;
      background: var(--kf-accent);
      color: var(--kf-primary);
      display: grid;
      place-items: center;
      font-weight: 800;
      font-size: 0.75rem;
    }

    .user {
      display: flex;
      flex-direction: column;
      font-size: 0.78rem;
      line-height: 1.2;

      small {
        opacity: 0.75;
      }
    }

    .logout-btn {
      width: 28px;
      height: 28px;
      border-radius: 50%;
      border: 0;
      background: rgba(255, 255, 255, 0.15);
      font-size: 0.8rem;

      &:hover {
        background: rgba(239, 68, 68, 0.8);
      }
    }

    .login-link {
      background: var(--kf-accent);
      color: var(--kf-primary);
      padding: 0.4rem 0.85rem;
      border-radius: 8px;
      font-weight: 700;
      font-size: 0.82rem;
    }

    @media (max-width: 900px) {
      .org, .divider, .user, .center .pill {
        display: none;
      }
    }

    @media (max-width: 600px) {
      /* Celular: menú, evento (ocupa el espacio libre) y usuario. La organización se cambia desde el menú. */
      .topbar { gap: 0.5rem; padding: 0 0.75rem; }
      .brand, .open-store { display: none; }
      .left { flex: none; }
      .center { flex: 1; min-width: 0; }
      .right { flex: none; gap: 0.4rem; }
      .event-select { flex: 1; min-width: 0; }
      .event-select select { width: 100%; min-width: 0; text-overflow: ellipsis; }
      .user-block { padding: 0.2rem; background: transparent; }
    }
  `],
})
export class TopbarComponent {
  readonly toggleSidebar = output<void>();
  protected readonly ctx = inject(EventContextService);
  protected readonly auth = inject(AuthService);
  protected readonly status: Record<string, { label: string; css: string } | undefined> = EVENT_STATUS;

  initials(name: string): string {
    return name
      .split(/\s+/)
      .filter((w) => /^[\p{L}\p{N}]/u.test(w))
      .map((w) => w[0])
      .slice(0, 2)
      .join('')
      .toUpperCase();
  }

  getOrgAccessLabel(type: 'owner' | 'administrator' | 'collaborator'): string {
    switch (type) {
      case 'owner': return 'Propietaria';
      case 'administrator': return 'Administrador';
      case 'collaborator': return 'Colaborador';
    }
  }
}
