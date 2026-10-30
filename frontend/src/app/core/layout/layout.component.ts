import { Component, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterOutlet } from '@angular/router';
import { TopbarComponent } from './topbar/topbar.component';
import { SidebarComponent } from './sidebar/sidebar.component';

@Component({
  selector: 'app-layout',
  standalone: true,
  imports: [CommonModule, RouterOutlet, TopbarComponent, SidebarComponent],
  template: `
    <div class="layout-wrapper">
      <app-topbar (toggleSidebar)="toggleSidebar()" />
      <div class="layout-main-container">
        <app-sidebar [collapsed]="sidebarCollapsed()" />
        <main class="layout-content" (click)="onContentClick()">
          <div class="content-viewport">
            <router-outlet></router-outlet>
          </div>
        </main>
      </div>
    </div>
  `,
  styles: [`
    .layout-wrapper {
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      background-color: #f8fafc;
      color: #0f172a;
    }

    .layout-main-container {
      display: flex;
      flex: 1;
      width: 100%;
    }

    .layout-content {
      flex: 1;
      padding: 2rem 2.5rem;
      min-width: 0;
      overflow-y: auto;
    }

    .content-viewport {
      max-width: 1380px;
      margin: 0 auto;
    }

    @media (max-width: 900px) {
      .layout-content {
        padding: 1.25rem 1rem;
      }
    }
  `]
})
export class LayoutComponent {
  readonly sidebarCollapsed = signal<boolean>(true);

  toggleSidebar(): void {
    this.sidebarCollapsed.update((v) => !v);
  }

  onContentClick(): void {
    if (window.innerWidth <= 900 && !this.sidebarCollapsed()) {
      this.sidebarCollapsed.set(true);
    }
  }
}
