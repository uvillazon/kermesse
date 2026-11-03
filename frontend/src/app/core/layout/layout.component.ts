import { Component, inject, signal } from '@angular/core';
import { RouterOutlet } from '@angular/router';
import { TopbarComponent } from './topbar/topbar.component';
import { SidebarComponent } from './sidebar/sidebar.component';
import { EventContextService } from '../services/event-context.service';

@Component({
  selector: 'app-layout',
  imports: [RouterOutlet, TopbarComponent, SidebarComponent],
  template: `
    <app-topbar (toggleSidebar)="sidebarOpen.set(!sidebarOpen())" />
    <div class="shell">
      <app-sidebar [open]="sidebarOpen()" (navigate)="sidebarOpen.set(false)" />
      <main class="content">
        @if (ctx.readOnly()) {
          <div class="notice info readonly">
            <i class="pi pi-lock"></i>
            <span><b>{{ ctx.active().name }}</b> está cerrado: todo queda en modo consulta para todos los usuarios, incluidos administradores. Se conservan detalle y exportación según permisos.</span>
          </div>
        }
        <router-outlet />
      </main>
    </div>
    <span class="proto-badge">Prototipo · datos simulados</span>
  `,
  styles: [`
    .shell { display: flex; min-height: calc(100vh - 64px); }
    .content { flex: 1; min-width: 0; padding: 1.75rem 2rem; max-width: 1400px; margin: 0 auto; }
    .readonly { margin-bottom: 1.25rem; }
    @media (max-width: 900px) { .content { padding: 1.1rem .9rem; } }
  `],
})
export class LayoutComponent {
  protected readonly ctx = inject(EventContextService);
  protected readonly sidebarOpen = signal(false);
}
