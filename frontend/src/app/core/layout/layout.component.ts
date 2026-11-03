import { Component, inject, signal } from '@angular/core';
import { NavigationEnd, Router, RouterLink, RouterOutlet } from '@angular/router';
import { toSignal } from '@angular/core/rxjs-interop';
import { filter, map } from 'rxjs';
import { TopbarComponent } from './topbar/topbar.component';
import { SidebarComponent } from './sidebar/sidebar.component';
import { EventContextService } from '../services/event-context.service';

@Component({
  selector: 'app-layout',
  imports: [RouterOutlet, RouterLink, TopbarComponent, SidebarComponent],
  template: `
    <app-topbar (toggleSidebar)="sidebarOpen.set(!sidebarOpen())" />
    <div class="shell">
      <app-sidebar [open]="sidebarOpen()" (navigate)="sidebarOpen.set(false)" />
      <main class="content">
        @if (ctx.readOnly()) {
          <div class="notice info readonly">
            <i class="pi pi-lock"></i>
            <span><b>{{ ctx.active()?.name }}</b> está cerrado: todo queda en modo consulta para todos los usuarios, incluidos administradores. Se conservan detalle y exportación según permisos.</span>
          </div>
        }

        @switch (ctx.state()) {
          @case ('loading') {
            <div class="notice info" role="status">
              <i class="pi pi-spin pi-spinner"></i>
              <span>Cargando eventos de la organización…</span>
            </div>
          }
          @case ('error') {
            <div class="notice danger" role="alert">
              <i class="pi pi-exclamation-triangle"></i>
              <span>{{ ctx.error() }}</span>
              <button type="button" class="retry" (click)="ctx.refreshEvents()">Reintentar</button>
            </div>
          }
          @default {
            @if (ctx.active() || onEventsPage()) {
              <router-outlet />
            } @else {
              <div class="notice info empty" role="status">
                <i class="pi pi-calendar-plus"></i>
                <span>La organización todavía no tiene eventos. Crea el primero para empezar.</span>
                <a routerLink="/events">Ir a Eventos</a>
              </div>
            }
          }
        }
      </main>
    </div>
  `,
  styles: [`
    .shell { display: flex; min-height: calc(100vh - 64px); }
    .content { flex: 1; min-width: 0; padding: 1.75rem 2rem; max-width: 1400px; margin: 0 auto; }
    .readonly { margin-bottom: 1.25rem; }
    .retry, .empty a { margin-left: auto; font-weight: 600; background: none; border: 0; cursor: pointer; color: inherit; text-decoration: underline; }
    @media (max-width: 900px) { .content { padding: 1.1rem .9rem; } }
  `],
})
export class LayoutComponent {
  protected readonly ctx = inject(EventContextService);
  private readonly router = inject(Router);
  protected readonly sidebarOpen = signal(false);

  /** La pantalla de Eventos debe abrirse aun sin eventos, para poder crear el primero. */
  protected readonly onEventsPage = toSignal(
    this.router.events.pipe(
      filter((e): e is NavigationEnd => e instanceof NavigationEnd),
      map((e) => e.urlAfterRedirects.startsWith('/events'))
    ),
    { initialValue: this.router.url.startsWith('/events') }
  );
}
