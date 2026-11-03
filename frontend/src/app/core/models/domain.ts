/**
 * Tipos y etiquetas de presentación del dominio. Aquí NO hay datos de ejemplo:
 * todo lo que se muestra en pantalla debe venir de la API.
 * Los nombres de roles/permisos siguen el backend (Identity/Domain).
 */

export type EventStatus = 'draft' | 'open' | 'closed';
export type PaymentMethod = 'cash' | 'qr' | 'transfer';
export type SaleStatus = 'confirmed' | 'to_verify' | 'rejected';
export type TicketStatus = 'pending' | 'partial' | 'redeemed' | 'cancelled';
export type EventRole =
  | 'EVENT_MANAGER' | 'FINANCE_REVIEWER' | 'SALES_OPERATOR' | 'PAYMENT_REVIEWER'
  | 'TICKET_OPERATOR' | 'REPORT_VIEWER' | 'HISTORICAL_VIEWER';

/** `starts_at` es ISO 8601 (UTC) o null. `date` es el texto antiguo del backend (mes en inglés); no usar para mostrar. */
export interface KEvent { id: string; slug: string; name: string; starts_at: string | null; date: string | null; status: EventStatus; stands: number; }
export interface Stand { id: string; name: string; manager: string; icon: string; }
export interface Product { id: string; standId: string; name: string; price: number; stock: number; sold: number; active: boolean; emoji: string; }
export interface SaleLine { productId: string; qty: number; }
export interface Sale { id: string; time: string; buyer: string; channel: 'interno' | 'público'; method: PaymentMethod; total: number; status: SaleStatus; operator: string; lines: SaleLine[]; }
export interface Ticket { code: string; buyer: string; items: string; total: number; status: TicketStatus; redeemed: number; quantity: number; }
export interface Member { id: string; name: string; email: string; orgAccess: 'owner' | 'administrator' | 'collaborator'; roles: EventRole[]; status: 'active' | 'pending' | 'suspended'; }
export interface Invitation { email: string; roles: EventRole[]; expires: string; status: 'pending' | 'expired' | 'accepted'; }
export interface Expense { concept: string; category: string; amount: number; }
export interface CourseAssignment { course: string; tickets: number; delivered: number; }

export const ROLE_LABELS: Record<EventRole, string> = {
  EVENT_MANAGER: 'Responsable del evento',
  FINANCE_REVIEWER: 'Revisor financiero',
  SALES_OPERATOR: 'Operador de ventas',
  PAYMENT_REVIEWER: 'Verificador de pagos',
  TICKET_OPERATOR: 'Operador de tickets',
  REPORT_VIEWER: 'Consulta de reportes',
  HISTORICAL_VIEWER: 'Consulta histórica',
};

/**
 * Qué permite cada rol, en palabras del usuario. Sigue a `RolePermissionMap` del backend
 * (no a la matriz v0.3, que aún no está aprobada). Actualizar si cambia ese mapa.
 */
export const ROLE_DESCRIPTIONS: Record<EventRole, string> = {
  EVENT_MANAGER: 'Configura el evento y el catálogo, vende, revisa pagos, canjea tickets y ve reportes.',
  FINANCE_REVIEWER: 'Revisa pagos, ve reportes y el balance, y exporta.',
  SALES_OPERATOR: 'Ve el catálogo y registra ventas.',
  PAYMENT_REVIEWER: 'Confirma o rechaza pagos por QR o transferencia.',
  TICKET_OPERATOR: 'Busca tickets y entrega productos.',
  REPORT_VIEWER: 'Ve y exporta reportes.',
  HISTORICAL_VIEWER: 'Consulta tickets, reportes, balance e historial de eventos pasados.',
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
