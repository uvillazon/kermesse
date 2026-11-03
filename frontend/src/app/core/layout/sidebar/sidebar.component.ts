import { Component, inject, input, output } from '@angular/core';
import { RouterModule } from '@angular/router';
import { AuthService } from '../../services/auth.service';
import { EventContextService } from '../../services/event-context.service';

interface NavItem { label: string; icon: string; route: string; }
interface NavGroup { title: string; items: NavItem[]; }

@Component({
  selector: 'app-sidebar',
  imports: [RouterModule],
  template: `
    <aside class="sidebar" [class.open]="open()">
      <!-- Solo en pantallas chicas: lo que la barra superior no tiene espacio para mostrar. -->
      <div class="group mobile-only">
        @if (auth.organizations().length > 1) {
          <label class="group-title" for="sidebar-org">Organización</label>
          <select id="sidebar-org" class="org-select" [value]="ctx.organization()?.id" (change)="selectOrg($event)">
            @for (o of auth.organizations(); track o.id) {
              <option [value]="o.id" [selected]="o.id === ctx.organization()?.id">{{ o.name }}</option>
            }
          </select>
        } @else if (ctx.organization(); as org) {
          <span class="group-title">Organización</span>
          <span class="org-name">{{ org.name }}</span>
        }
        @if (ctx.active(); as ev) {
          <a class="link" [routerLink]="['/tienda', ev.slug]" target="_blank" (click)="navigate.emit()">
            <i class="pi pi-external-link"></i><span>Ver tienda pública</span>
          </a>
        }
      </div>
      @for (group of groups; track group.title) {
        <div class="group">
          <span class="group-title">{{ group.title }}</span>
          @for (item of group.items; track item.route) {
            <a [routerLink]="item.route" routerLinkActive="active" class="link" (click)="navigate.emit()">
              <i [class]="'pi ' + item.icon"></i><span>{{ item.label }}</span>
            </a>
          }
        </div>
      }
    </aside>
  `,
  styles: [`
    /* Fija al desplazar: en páginas largas el fondo blanco ya no se corta a la altura de la pantalla. */
    .sidebar { width: 240px; flex-shrink: 0; background: #fff; border-right: 1px solid var(--kf-line);
      position: sticky; top: 64px; align-self: flex-start; height: calc(100vh - 64px); overflow-y: auto;
      padding: 1.25rem .85rem; display: flex; flex-direction: column; gap: 1.25rem; }
    .group.mobile-only { display: none; }
    .org-select { margin: 0 .7rem; padding: .55rem .6rem; border: 1px solid var(--kf-line); border-radius: 9px; font: inherit; font-size: .88rem; background: #fff; color: var(--kf-ink); }
    .org-name { padding: 0 .7rem; font-weight: 700; font-size: .88rem; color: var(--kf-ink); }
    .group { display: flex; flex-direction: column; gap: .2rem; }
    .group-title { font-size: .66rem; font-weight: 800; letter-spacing: .09em; color: #98a2b3; padding: 0 .7rem .4rem; text-transform: uppercase; }
    .link { display: flex; align-items: center; gap: .75rem; padding: .6rem .7rem; border-radius: 9px; color: #475467; font-size: .88rem; }
    .link i { color: #98a2b3; }
    .link:hover { background: #f4f6f9; }
    .link.active { background: var(--kf-primary-soft); color: var(--kf-primary); font-weight: 700; }
    .link.active i { color: var(--kf-primary); }
    @media (max-width: 900px) {
      .sidebar { position: fixed; top: 64px; left: -260px; z-index: 99; height: calc(100vh - 64px);
        transition: left .2s ease; box-shadow: 4px 0 16px rgba(0,0,0,.06); }
      .sidebar.open { left: 0; }
      .group.mobile-only { display: flex; padding-bottom: 1rem; border-bottom: 1px solid var(--kf-line); }
      .link { min-height: 44px; }
    }
  `],
})
export class SidebarComponent {
  readonly open = input<boolean>(false);
  readonly navigate = output<void>();
  protected readonly auth = inject(AuthService);
  protected readonly ctx = inject(EventContextService);

  protected selectOrg(event: Event): void {
    this.auth.selectOrganization((event.target as HTMLSelectElement).value);
    this.navigate.emit();
  }

  protected readonly groups: NavGroup[] = [
    { title: 'Evento', items: [
      { label: 'Panel del evento', icon: 'pi-chart-bar', route: '/home' },
      { label: 'Eventos', icon: 'pi-calendar', route: '/events' },
      { label: 'Puestos y productos', icon: 'pi-shop', route: '/catalog' },
    ] },
    { title: 'Operación', items: [
      { label: 'Ventas y pagos QR', icon: 'pi-wallet', route: '/sales' },
      { label: 'Tickets y canjes', icon: 'pi-ticket', route: '/tickets' },
    ] },
    { title: 'Administración', items: [
      { label: 'Usuarios y roles', icon: 'pi-users', route: '/access' },
      { label: 'Reportes y cierre', icon: 'pi-file-export', route: '/reports' },
    ] },
    { title: 'Sesión', items: [
      { label: 'Cambiar usuario / Login', icon: 'pi-sign-in', route: '/login' },
    ] },
  ];
}
