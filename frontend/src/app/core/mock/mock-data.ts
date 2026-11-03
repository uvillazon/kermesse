/**
 * Datos SIMULADOS del prototipo. No representan reglas de negocio aprobadas
 * ni respuestas reales de la API. Se reemplazarán por el cliente OpenAPI.
 * Los nombres de roles/permisos siguen el backend (Identity/Domain).
 */

export type EventStatus = 'draft' | 'open' | 'closed';
export type PaymentMethod = 'cash' | 'qr' | 'transfer';
export type SaleStatus = 'confirmed' | 'to_verify' | 'rejected';
export type TicketStatus = 'pending' | 'partial' | 'redeemed' | 'cancelled';
export type EventRole =
  | 'EVENT_MANAGER' | 'FINANCE_REVIEWER' | 'SALES_OPERATOR' | 'PAYMENT_REVIEWER'
  | 'TICKET_OPERATOR' | 'REPORT_VIEWER' | 'HISTORICAL_VIEWER';

export interface KEvent { id: string; slug: string; name: string; date: string; status: EventStatus; stands: number; }
export interface Stand { id: string; name: string; manager: string; icon: string; }
export interface Product { id: string; standId: string; name: string; price: number; stock: number; sold: number; active: boolean; emoji: string; }
export interface SaleLine { productId: string; qty: number; }
export interface Sale { id: string; time: string; buyer: string; channel: 'interno' | 'público'; method: PaymentMethod; total: number; status: SaleStatus; operator: string; lines: SaleLine[]; }
export interface Ticket { code: string; buyer: string; items: string; total: number; status: TicketStatus; redeemed: number; quantity: number; }
export interface Member { id: string; name: string; email: string; orgAccess: 'owner' | 'administrator' | 'collaborator'; roles: EventRole[]; status: 'active' | 'pending' | 'suspended'; }
export interface Invitation { email: string; roles: EventRole[]; expires: string; status: 'pending' | 'expired' | 'accepted'; }
export interface Expense { concept: string; category: string; amount: number; }
export interface CourseAssignment { course: string; tickets: number; delivered: number; }

export const ORGANIZATION = { name: "Saint Andrew's School", initials: 'SA', plan: 'Cobro por evento' };

export const EVENTS: KEvent[] = [
  { id: 'evt-hllw-2026', slug: 'halloween-2026', name: 'Kermesse Halloween 2026', date: '28 oct 2026', status: 'open', stands: 4 },
  { id: 'evt-xmas-2026', slug: 'bazar-navideno-2026', name: 'Bazar Navideño 2026', date: '12 dic 2026', status: 'draft', stands: 0 },
  { id: 'evt-sprg-2026', slug: 'primavera-2026', name: 'Día de la Primavera 2026', date: '21 sep 2026', status: 'closed', stands: 3 },
];

export const STANDS: Stand[] = [
  { id: 'st-gastro', name: 'Gastronomía', manager: 'María Rojas', icon: 'pi-shop' },
  { id: 'st-bebidas', name: 'Bebidas', manager: 'Luis Vargas', icon: 'pi-filter' },
  { id: 'st-reposteria', name: 'Repostería', manager: 'Ana Quiroga', icon: 'pi-star' },
  { id: 'st-juegos', name: 'Juegos', manager: 'Diego Flores', icon: 'pi-th-large' },
];

export const PRODUCTS: Product[] = [
  { id: 'p1', standId: 'st-gastro', name: 'Pique macho', price: 35, stock: 120, sold: 86, active: true, emoji: '🍖' },
  { id: 'p2', standId: 'st-gastro', name: 'Hamburguesa', price: 25, stock: 150, sold: 132, active: true, emoji: '🍔' },
  { id: 'p3', standId: 'st-gastro', name: 'Salchipapa', price: 18, stock: 100, sold: 41, active: true, emoji: '🍟' },
  { id: 'p4', standId: 'st-bebidas', name: 'Refresco 500 ml', price: 8, stock: 300, sold: 214, active: true, emoji: '🥤' },
  { id: 'p5', standId: 'st-bebidas', name: 'Api con pastel', price: 12, stock: 80, sold: 80, active: true, emoji: '☕' },
  { id: 'p6', standId: 'st-reposteria', name: 'Torta de chocolate (porción)', price: 10, stock: 90, sold: 57, active: true, emoji: '🍰' },
  { id: 'p7', standId: 'st-reposteria', name: 'Galletas de Halloween', price: 6, stock: 200, sold: 120, active: false, emoji: '🎃' },
  { id: 'p8', standId: 'st-juegos', name: 'Ficha de juego', price: 5, stock: 500, sold: 310, active: true, emoji: '🎯' },
];

export const SALES: Sale[] = [
  { id: 'V-000318', time: '18:42', buyer: 'juan.perez@gmail.com', channel: 'público', method: 'qr', total: 95, status: 'to_verify', operator: 'Web pública', lines: [{ productId: 'p1', qty: 2 }, { productId: 'p2', qty: 1 }] },
  { id: 'V-000317', time: '18:40', buyer: 'Venta en puesto', channel: 'interno', method: 'cash', total: 33, status: 'confirmed', operator: 'María Rojas', lines: [{ productId: 'p2', qty: 1 }, { productId: 'p4', qty: 1 }] },
  { id: 'V-000316', time: '18:37', buyer: 'carla.m@hotmail.com', channel: 'público', method: 'transfer', total: 70, status: 'to_verify', operator: 'Web pública', lines: [{ productId: 'p1', qty: 2 }] },
  { id: 'V-000315', time: '18:31', buyer: 'Venta en puesto', channel: 'interno', method: 'qr', total: 24, status: 'confirmed', operator: 'Luis Vargas', lines: [{ productId: 'p4', qty: 3 }] },
  { id: 'V-000314', time: '18:25', buyer: 'pedro.s@gmail.com', channel: 'público', method: 'qr', total: 50, status: 'rejected', operator: 'Web pública', lines: [{ productId: 'p2', qty: 2 }] },
  { id: 'V-000313', time: '18:20', buyer: 'Venta en puesto', channel: 'interno', method: 'cash', total: 20, status: 'confirmed', operator: 'Diego Flores', lines: [{ productId: 'p8', qty: 4 }] },
  { id: 'V-000312', time: '18:12', buyer: 'lucia.c@gmail.com', channel: 'público', method: 'qr', total: 36, status: 'confirmed', operator: 'Web pública', lines: [{ productId: 'p3', qty: 2 }] },
];

export const TICKETS: Ticket[] = [
  { code: 'KRM-000245', buyer: 'juan.perez@gmail.com', items: '2 pique macho · 1 hamburguesa', total: 95, status: 'pending', redeemed: 0, quantity: 3 },
  { code: 'KRM-000244', buyer: 'lucia.c@gmail.com', items: '2 salchipapa', total: 36, status: 'redeemed', redeemed: 2, quantity: 2 },
  { code: 'KRM-000243', buyer: 'roberto.a@gmail.com', items: '3 hamburguesa · 3 refresco', total: 99, status: 'partial', redeemed: 3, quantity: 6 },
  { code: 'KRM-000242', buyer: 'sofia.t@gmail.com', items: '1 torta · 1 api', total: 22, status: 'pending', redeemed: 0, quantity: 2 },
  { code: 'KRM-000241', buyer: 'pedro.s@gmail.com', items: '2 hamburguesa', total: 50, status: 'cancelled', redeemed: 0, quantity: 2 },
];

export const COURSE_ASSIGNMENTS: CourseAssignment[] = [
  { course: '1.º Primaria A', tickets: 40, delivered: 32 },
  { course: '3.º Primaria B', tickets: 35, delivered: 35 },
  { course: '5.º Secundaria A', tickets: 50, delivered: 21 },
  { course: '6.º Secundaria B', tickets: 45, delivered: 40 },
];

export const MEMBERS: Member[] = [
  { id: 'u1', name: 'Carmen Gutiérrez', email: 'direccion@saintandrews.edu.bo', orgAccess: 'owner', roles: ['EVENT_MANAGER', 'FINANCE_REVIEWER'], status: 'active' },
  { id: 'u2', name: 'Jorge Méndez', email: 'jmendez@saintandrews.edu.bo', orgAccess: 'administrator', roles: ['EVENT_MANAGER', 'REPORT_VIEWER'], status: 'active' },
  { id: 'u3', name: 'María Rojas', email: 'maria.rojas@gmail.com', orgAccess: 'collaborator', roles: ['SALES_OPERATOR', 'TICKET_OPERATOR'], status: 'active' },
  { id: 'u4', name: 'Luis Vargas', email: 'lvargas@gmail.com', orgAccess: 'collaborator', roles: ['SALES_OPERATOR'], status: 'active' },
  { id: 'u5', name: 'Patricia Soliz', email: 'psoliz@saintandrews.edu.bo', orgAccess: 'collaborator', roles: ['PAYMENT_REVIEWER'], status: 'active' },
  { id: 'u6', name: 'Raúl Céspedes', email: 'rcespedes@gmail.com', orgAccess: 'collaborator', roles: ['HISTORICAL_VIEWER'], status: 'suspended' },
];

export const INVITATIONS: Invitation[] = [
  { email: 'diego.flores@gmail.com', roles: ['SALES_OPERATOR', 'TICKET_OPERATOR'], expires: '02 nov 2026', status: 'pending' },
  { email: 'tesoreria@saintandrews.edu.bo', roles: ['FINANCE_REVIEWER'], expires: '25 oct 2026', status: 'expired' },
];

export const EXPENSES: Expense[] = [
  { concept: 'Insumos de cocina', category: 'Insumos', amount: 4200 },
  { concept: 'Bebidas (proveedor)', category: 'Insumos', amount: 1650 },
  { concept: 'Decoración Halloween', category: 'Logística', amount: 900 },
  { concept: 'Alquiler de sonido', category: 'Servicios', amount: 1200 },
  { concept: 'Premios para juegos', category: 'Juegos', amount: 650 },
];

export const ROLE_LABELS: Record<EventRole, string> = {
  EVENT_MANAGER: 'Responsable del evento',
  FINANCE_REVIEWER: 'Revisor financiero',
  SALES_OPERATOR: 'Operador de ventas',
  PAYMENT_REVIEWER: 'Verificador de pagos',
  TICKET_OPERATOR: 'Operador de tickets',
  REPORT_VIEWER: 'Consulta de reportes',
  HISTORICAL_VIEWER: 'Consulta histórica',
};

export const METHOD_LABELS: Record<PaymentMethod, string> = { cash: 'Efectivo', qr: 'QR bancario', transfer: 'Transferencia' };

export const SALE_STATUS: Record<SaleStatus, { label: string; css: string }> = {
  confirmed: { label: 'Confirmada', css: 'green' },
  to_verify: { label: 'Por verificar', css: 'yellow' },
  rejected: { label: 'Rechazada', css: 'red' },
};

export const TICKET_STATUS: Record<TicketStatus, { label: string; css: string }> = {
  pending: { label: 'Pendiente', css: 'yellow' },
  partial: { label: 'Canje parcial', css: 'cyan' },
  redeemed: { label: 'Canjeado', css: 'green' },
  cancelled: { label: 'Anulado', css: 'red' },
};

export const EVENT_STATUS: Record<EventStatus, { label: string; css: string }> = {
  draft: { label: 'Borrador', css: 'gray' },
  open: { label: 'Abierto', css: 'green' },
  closed: { label: 'Cerrado (solo consulta)', css: 'blue' },
};

export function productById(id: string): Product | undefined {
  return PRODUCTS.find((p) => p.id === id);
}
