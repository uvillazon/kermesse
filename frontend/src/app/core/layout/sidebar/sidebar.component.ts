import { Component, input, output } from '@angular/core';
import { RouterModule } from '@angular/router';

interface NavItem { label: string; icon: string; route: string; }
interface NavGroup { title: string; items: NavItem[]; }

@Component({
  selector: 'app-sidebar',
  imports: [RouterModule],
  template: `
    <aside class="sidebar" [class.open]="open()">
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
      <div class="foot">
        <small>Prototipo navegable</small>
        <b>Datos simulados · sin API</b>
      </div>
    </aside>
  `,
  styles: [`
    .sidebar { width: 240px; flex-shrink: 0; background: #fff; border-right: 1px solid var(--kf-line);
      min-height: calc(100vh - 64px); padding: 1.25rem .85rem; display: flex; flex-direction: column; gap: 1.25rem; }
    .group { display: flex; flex-direction: column; gap: .2rem; }
    .group-title { font-size: .66rem; font-weight: 800; letter-spacing: .09em; color: #98a2b3; padding: 0 .7rem .4rem; text-transform: uppercase; }
    .link { display: flex; align-items: center; gap: .75rem; padding: .6rem .7rem; border-radius: 9px; color: #475467; font-size: .88rem; }
    .link i { color: #98a2b3; }
    .link:hover { background: #f4f6f9; }
    .link.active { background: var(--kf-primary-soft); color: var(--kf-primary); font-weight: 700; }
    .link.active i { color: var(--kf-primary); }
    .foot { margin-top: auto; background: var(--kf-accent-soft); border-radius: 10px; padding: .7rem; display: flex;
      flex-direction: column; font-size: .75rem; color: #6b4d00; }
    @media (max-width: 900px) {
      .sidebar { position: fixed; top: 64px; left: -260px; z-index: 99; height: calc(100vh - 64px);
        transition: left .2s ease; box-shadow: 4px 0 16px rgba(0,0,0,.06); }
      .sidebar.open { left: 0; }
    }
  `],
})
export class SidebarComponent {
  readonly open = input<boolean>(false);
  readonly navigate = output<void>();

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
