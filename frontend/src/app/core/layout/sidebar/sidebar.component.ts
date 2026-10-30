import { Component, input } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule } from '@angular/router';

interface NavItem {
  label: string;
  icon: string;
  route: string;
  badge?: string;
}

@Component({
  selector: 'app-sidebar',
  standalone: true,
  imports: [CommonModule, RouterModule],
  template: `
    <aside class="sidebar" [class.collapsed]="collapsed()">
      <div class="nav-section">
        <span class="nav-label">OPERACIÓN DEL EVENTO</span>
        <nav class="nav-links">
          @for (item of mainNavItems; track item.route) {
            <a
              [routerLink]="item.route"
              routerLinkActive="active"
              [routerLinkActiveOptions]="{ exact: item.route === '/home' }"
              class="nav-link"
            >
              <i [class]="'pi ' + item.icon"></i>
              <span class="link-label">{{ item.label }}</span>
              @if (item.badge) {
                <span class="badge">{{ item.badge }}</span>
              }
            </a>
          }
        </nav>
      </div>

      <div class="nav-section">
        <span class="nav-label">GESTIÓN & REPORTES</span>
        <nav class="nav-links">
          @for (item of managementNavItems; track item.route) {
            <a
              [routerLink]="item.route"
              routerLinkActive="active"
              class="nav-link"
            >
              <i [class]="'pi ' + item.icon"></i>
              <span class="link-label">{{ item.label }}</span>
            </a>
          }
        </nav>
      </div>

      <div class="sidebar-footer">
        <div class="status-box">
          <small>Plataforma Kermesse</small>
          <strong>Hito 0 &bull; Base Front</strong>
        </div>
      </div>
    </aside>
  `,
  styles: [`
    .sidebar {
      width: 250px;
      background: #fbfcfe;
      border-right: 1px solid #e5e9f0;
      min-height: calc(100vh - 68px);
      padding: 1.5rem 1rem;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      transition: all 0.2s ease-in-out;
      box-sizing: border-box;
    }

    .nav-section {
      margin-bottom: 2rem;
    }

    .nav-label {
      display: block;
      font-size: 0.68rem;
      font-weight: 700;
      letter-spacing: 0.08em;
      color: #94a3b8;
      margin-bottom: 0.75rem;
      padding-left: 0.75rem;
    }

    .nav-links {
      display: flex;
      flex-direction: column;
      gap: 0.35rem;
    }

    .nav-link {
      display: flex;
      align-items: center;
      gap: 0.85rem;
      padding: 0.75rem 0.85rem;
      border-radius: 10px;
      color: #475569;
      text-decoration: none;
      font-size: 0.9rem;
      font-weight: 500;
      transition: all 0.15s ease;

      i {
        font-size: 1.05rem;
        color: #64748b;
        transition: color 0.15s ease;
      }

      &:hover {
        background: #f1f5f9;
        color: #18243a;

        i {
          color: #18243a;
        }
      }

      &.active {
        background: #eaf0f8;
        color: #18243a;
        font-weight: 700;

        i {
          color: #18243a;
        }
      }
    }

    .badge {
      margin-left: auto;
      background: #e2e8f0;
      color: #334155;
      font-size: 0.7rem;
      font-weight: 700;
      padding: 0.15rem 0.5rem;
      border-radius: 9999px;
    }

    .sidebar-footer {
      border-top: 1px solid #e5e9f0;
      padding-top: 1rem;
    }

    .status-box {
      background: #f1f5f9;
      padding: 0.75rem;
      border-radius: 8px;
      display: flex;
      flex-direction: column;
      gap: 0.2rem;

      small {
        font-size: 0.7rem;
        color: #64748b;
      }

      strong {
        font-size: 0.8rem;
        color: #18243a;
      }
    }

    @media (max-width: 900px) {
      .sidebar {
        position: fixed;
        top: 68px;
        left: -260px;
        z-index: 99;
        background: #ffffff;
        box-shadow: 4px 0 16px rgba(0, 0, 0, 0.05);
        height: calc(100vh - 68px);

        &:not(.collapsed) {
          left: 0;
        }
      }
    }
  `]
})
export class SidebarComponent {
  readonly collapsed = input<boolean>(false);

  readonly mainNavItems: NavItem[] = [
    { label: 'Inicio', icon: 'pi-home', route: '/home' },
    { label: 'Eventos', icon: 'pi-calendar', route: '/events', badge: '1 activo' },
    { label: 'Puestos y Productos', icon: 'pi-shopping-bag', route: '/events/demo/stands' }
  ];

  readonly managementNavItems: NavItem[] = [
    { label: 'Ventas y Tickets', icon: 'pi-ticket', route: '/events/demo/sales' },
    { label: 'Reportes y Cierre', icon: 'pi-chart-line', route: '/events/demo/reports' }
  ];
}
