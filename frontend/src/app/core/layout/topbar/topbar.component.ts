import { Component, output } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule } from '@angular/router';

@Component({
  selector: 'app-topbar',
  standalone: true,
  imports: [CommonModule, RouterModule],
  template: `
    <header class="topbar">
      <div class="topbar-left">
        <button class="toggle-btn" (click)="toggleSidebar.emit()" aria-label="Alternar menú">
          <i class="pi pi-bars"></i>
        </button>

        <a routerLink="/" class="brand">
          <div class="brand-crest">SA</div>
          <div class="brand-info">
            <span class="brand-title">Kermesse</span>
            <small class="brand-subtitle">Saint Andrew's School</small>
          </div>
        </a>
      </div>

      <div class="topbar-center">
        <div class="event-badge">
          <span class="pulse-indicator"></span>
          <span>Kermesse Halloween 2026 &bull; Modo Activo</span>
        </div>
      </div>

      <div class="topbar-right">
        <div class="user-profile">
          <div class="avatar">AD</div>
          <div class="user-meta">
            <span class="user-name">Administrador</span>
            <span class="user-role">Comisión Central</span>
          </div>
        </div>
      </div>
    </header>
  `,
  styles: [`
    .topbar {
      height: 68px;
      background: #ffffff;
      border-bottom: 1px solid #e5e9f0;
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0 1.5rem;
      position: sticky;
      top: 0;
      z-index: 100;
    }

    .topbar-left {
      display: flex;
      align-items: center;
      gap: 1rem;
    }

    .toggle-btn {
      background: transparent;
      border: 1px solid #e2e8f0;
      border-radius: 8px;
      width: 38px;
      height: 38px;
      display: grid;
      place-items: center;
      cursor: pointer;
      color: #18243a;
      transition: background-color 0.15s ease;

      &:hover {
        background-color: #f1f5f9;
      }
    }

    .brand {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      text-decoration: none;
      color: inherit;
    }

    .brand-crest {
      width: 36px;
      height: 36px;
      border-radius: 10px;
      background: #18243a;
      color: #ffffff;
      display: grid;
      place-items: center;
      font-weight: 800;
      font-size: 0.95rem;
      letter-spacing: -0.5px;
    }

    .brand-info {
      display: flex;
      flex-direction: column;
    }

    .brand-title {
      font-weight: 700;
      font-size: 1rem;
      color: #18243a;
      line-height: 1.2;
    }

    .brand-subtitle {
      font-size: 0.75rem;
      color: #64748b;
      font-weight: 500;
    }

    .topbar-center {
      display: flex;
      align-items: center;
    }

    .event-badge {
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      background: #eff6ff;
      border: 1px solid #bfdbfe;
      color: #1e40af;
      padding: 0.35rem 0.85rem;
      border-radius: 9999px;
      font-size: 0.8rem;
      font-weight: 600;
    }

    .pulse-indicator {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: #22c55e;
      box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.7);
      animation: pulse 1.8s infinite;
    }

    @keyframes pulse {
      0% {
        box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.7);
      }
      70% {
        box-shadow: 0 0 0 8px rgba(34, 197, 94, 0);
      }
      100% {
        box-shadow: 0 0 0 0 rgba(34, 197, 94, 0);
      }
    }

    .topbar-right {
      display: flex;
      align-items: center;
      gap: 1rem;
    }

    .user-profile {
      display: flex;
      align-items: center;
      gap: 0.65rem;
    }

    .avatar {
      width: 36px;
      height: 36px;
      border-radius: 50%;
      background: #fde8df;
      color: #b8431e;
      display: grid;
      place-items: center;
      font-size: 0.85rem;
      font-weight: 700;
    }

    .user-meta {
      display: flex;
      flex-direction: column;
    }

    .user-name {
      font-size: 0.85rem;
      font-weight: 600;
      color: #18243a;
      line-height: 1.2;
    }

    .user-role {
      font-size: 0.72rem;
      color: #64748b;
    }

    @media (max-width: 768px) {
      .topbar-center, .user-meta {
        display: none;
      }
      .brand-subtitle {
        display: none;
      }
    }
  `]
})
export class TopbarComponent {
  readonly toggleSidebar = output<void>();
}
