import type { DemoAccount } from '../app/core/services/auth.service';

// Solo desarrollo local: lo importa únicamente environment.development.ts.
// Las contraseñas se asignan en la base local con `php bin/console app:users:set-password <correo>`.
export const DEV_DEMO_ACCOUNTS: DemoAccount[] = [
  {
    user: {
      id: '018f3a12-1001-4000-8000-000000000001',
      name: 'Carmen Gutiérrez',
      email: 'direccion@saintandrews.edu.bo',
      orgAccess: 'owner',
      roles: ['EVENT_MANAGER', 'FINANCE_REVIEWER'],
      avatarInitials: 'CG',
    },
    password: 'password123',
    roleLabel: 'Propietaria / Responsable',
    description: 'Acceso total: gestión, finanzas, catálogo y cierre del evento.',
  },
  {
    user: {
      id: '018f3a12-1002-4000-8000-000000000002',
      name: 'María Rojas',
      email: 'maria.rojas@gmail.com',
      orgAccess: 'collaborator',
      roles: ['SALES_OPERATOR', 'TICKET_OPERATOR'],
      avatarInitials: 'MR',
    },
    password: 'password123',
    roleLabel: 'Operadora de Ventas y Tickets',
    description: 'Venta rápida en puesto y escaneo/canje de boletos QR.',
  },
  {
    user: {
      id: '018f3a12-1003-4000-8000-000000000003',
      name: 'Patricia Soliz',
      email: 'psoliz@saintandrews.edu.bo',
      orgAccess: 'collaborator',
      roles: ['PAYMENT_REVIEWER'],
      avatarInitials: 'PS',
    },
    password: 'password123',
    roleLabel: 'Verificadora de Pagos QR',
    description: 'Revisión y confirmación de transferencias bancarias y códigos QR.',
  },
];
