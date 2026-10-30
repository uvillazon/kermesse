import { Routes } from '@angular/router';
import { LayoutComponent } from './core/layout/layout.component';

export const routes: Routes = [
  {
    path: '',
    component: LayoutComponent,
    children: [
      {
        path: '',
        redirectTo: 'home',
        pathMatch: 'full'
      },
      {
        path: 'home',
        loadComponent: () => import('./features/home/home.component').then((m) => m.HomeComponent)
      },
      {
        path: 'events',
        loadComponent: () => import('./features/events/events.component').then((m) => m.EventsComponent)
      }
    ]
  },
  {
    path: '**',
    redirectTo: 'home'
  }
];
