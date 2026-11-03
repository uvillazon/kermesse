import { Routes } from '@angular/router';
import { LayoutComponent } from './core/layout/layout.component';
import { authGuard, guestGuard } from './core/guards/auth.guard';

export const routes: Routes = [
  {
    // Ruta pública de autenticación / inicio de sesión
    path: 'login',
    loadComponent: () => import('./features/auth/login.component').then((m) => m.LoginComponent),
  },
  {
    // Página pública de compra para padres y familias (solo requiere correo al comprar)
    path: 'tienda/:slug',
    loadComponent: () => import('./features/public-store/public-store.component').then((m) => m.PublicStoreComponent),
  },
  {
    // Rutas protegidas del panel administrativo y operativo
    path: '',
    component: LayoutComponent,
    canActivate: [authGuard],
    children: [
      { path: '', redirectTo: 'home', pathMatch: 'full' },
      { path: 'home', loadComponent: () => import('./features/home/home.component').then((m) => m.HomeComponent) },
      { path: 'events', loadComponent: () => import('./features/events/events.component').then((m) => m.EventsComponent) },
      { path: 'catalog', loadComponent: () => import('./features/catalog/catalog.component').then((m) => m.CatalogComponent) },
      { path: 'sales', loadComponent: () => import('./features/sales/sales.component').then((m) => m.SalesComponent) },
      { path: 'tickets', loadComponent: () => import('./features/tickets/tickets.component').then((m) => m.TicketsComponent) },
      { path: 'access', loadComponent: () => import('./features/access/access.component').then((m) => m.AccessComponent) },
      { path: 'reports', loadComponent: () => import('./features/reports/reports.component').then((m) => m.ReportsComponent) },
    ],
  },
  { path: '**', redirectTo: 'home' },
];
