import { formatDate, formatNumber } from '@angular/common';
import { EventRole, ROLE_LABELS } from '../../core/models/domain';
import { parseApiDate } from '../../core/ui/fecha.pipe';

/** Registro del historial tal como lo devuelve `GET .../audit-logs`. */
export interface AuditEntry {
  id: string;
  action: string;
  aggregate_type: string;
  aggregate_id: string;
  request_id: string;
  actor_name: string;
  actor_email: string;
  subject_name?: string | null;
  metadata: Record<string, unknown>;
  created_at: string;
}

/** Frase principal ("Registró una venta de Bs 45,00") y un detalle opcional en una segunda línea. */
export interface AuditDescription {
  title: string;
  detail: string | null;
}

/** Tipo de registro en palabras del usuario, para el filtro y la etiqueta. */
export const AUDIT_AREA_LABELS: Record<string, string> = {
  sale: 'Venta',
  product: 'Producto',
  stand: 'Puesto',
  event: 'Evento',
  expense: 'Gasto',
  ticket: 'Ticket',
  course_allocation: 'Curso',
  event_membership: 'Acceso',
  organization_membership: 'Acceso',
  access_invitation: 'Invitación',
  payment: 'Pago',
};

export function auditAreaLabel(aggregateType: string): string {
  return AUDIT_AREA_LABELS[aggregateType] ?? 'Otro';
}

const PAYMENT_METHODS: Record<string, string> = {
  cash: 'efectivo',
  qr: 'QR',
  qr_transfer: 'QR o transferencia',
  transfer: 'transferencia',
};

/**
 * Traduce un registro del historial a una frase legible. Nunca muestra UUID ni JSON: si una
 * acción no está contemplada, dice que se registró una acción y nombra el tipo de registro.
 */
export function describeAudit(entry: AuditEntry, locale: string): AuditDescription {
  const m = entry.metadata ?? {};
  const str = (key: string): string => (typeof m[key] === 'string' ? (m[key] as string) : '');
  const num = (key: string): number | null => (typeof m[key] === 'number' ? (m[key] as number) : null);
  const money = (minorKey: string): string => {
    const minor = num(minorKey);
    return minor === null ? '' : `Bs ${formatNumber(minor / 100, locale, '1.2-2')}`;
  };
  const roles = (value: unknown): string =>
    Array.isArray(value) && value.length
      ? value.map((r) => ROLE_LABELS[r as EventRole] ?? 'otro rol').join(', ')
      : 'sin roles';
  const who = entry.subject_name || 'una persona';

  switch (entry.action) {
    case 'sale.created': {
      const method = PAYMENT_METHODS[str('payment_method')] ?? 'otro medio';
      const pending = str('status') && str('status') !== 'confirmed';
      return {
        title: `Registró una venta de ${money('total_minor')} en ${method}`,
        detail: [str('ticket_number') && `Ticket ${str('ticket_number')}`, pending && 'Pago por verificar']
          .filter(Boolean)
          .join(' · ') || null,
      };
    }
    case 'sale.reservation_expired':
      return { title: 'Se anuló un pedido por QR sin pagar', detail: 'Venció el plazo de la reserva y el stock volvió al catálogo' };
    case 'payment.confirmed':
      return { title: 'Confirmó un pago por QR o transferencia', detail: str('provider_reference') ? `Referencia ${str('provider_reference')}` : null };
    case 'payment.rejected':
      return { title: 'Rechazó un pago por QR o transferencia', detail: 'La venta se anuló y el stock volvió al catálogo' };
    case 'ticket.redeemed': {
      const done = num('redeemed_quantity');
      const total = num('total_quantity');
      return {
        title: `Entregó productos del ticket ${str('ticket_number') || ''}`.trim(),
        detail: done !== null && total !== null ? `${done} de ${total} productos entregados` : null,
      };
    }
    case 'catalog.product_created':
      return {
        title: `Creó el producto ${str('name')}`,
        detail: [money('unit_price_minor'), num('stock') !== null && `stock ${num('stock')}`].filter(Boolean).join(' · ') || null,
      };
    case 'catalog.product_updated': {
      const parts = [
        money('unit_price_minor') && `precio ${money('unit_price_minor')}`,
        num('stock') !== null && `stock ${num('stock')}`,
        typeof m['active'] === 'boolean' && (m['active'] ? 'activo' : 'desactivado'),
      ].filter(Boolean);
      return { title: `Modificó el producto ${str('name')}`.trim(), detail: parts.join(' · ') || null };
    }
    case 'catalog.stand_created':
      return { title: `Creó el puesto ${str('name')}`, detail: str('manager') ? `Responsable: ${str('manager')}` : null };
    case 'course_allocation.created':
      return {
        title: `Asignó ${num('assigned_quantity') ?? ''} × ${str('product')} a ${str('course')}`.replace(/\s+/g, ' '),
        detail: str('delegate') ? `Encargado: ${str('delegate')}` : null,
      };
    case 'course_allocation.deliver':
      return { title: `Registró una entrega a ${str('course')}`, detail: courseProgress(num('quantity'), num('delivered'), num('returned')) };
    case 'course_allocation.return':
      return { title: `Registró una devolución de ${str('course')}`, detail: courseProgress(num('quantity'), num('delivered'), num('returned')) };
    case 'event.created':
      return { title: `Creó el evento ${str('name')}`, detail: null };
    case 'event.opened':
      return { title: `Abrió el evento ${str('name')}`, detail: 'Desde ahora se puede vender y canjear' };
    case 'expense.created':
      return { title: `Registró el gasto ${str('concept')} por ${money('amount_minor')}`, detail: str('category') || null };
    case 'identity.event_access_assigned': {
      const prev = m['previous'] as { roles?: unknown } | null | undefined;
      const curr = m['current'] as { roles?: unknown } | null | undefined;
      return prev
        ? { title: `Actualizó el acceso de ${who}`, detail: `Antes: ${roles(prev.roles)} · Ahora: ${roles(curr?.roles)}` }
        : { title: `Dio acceso al evento a ${who}`, detail: `Roles: ${roles(curr?.roles)}` };
    }
    case 'identity.event_roles_changed':
      return { title: `Cambió los roles de ${who}`, detail: `Antes: ${roles(m['previous_roles'])} · Ahora: ${roles(m['current_roles'])}` };
    case 'identity.event_access_revoked':
      return { title: `Quitó el acceso al evento a ${who}`, detail: null };
    case 'identity.invitation_created': {
      const expires = parseApiDate(str('expires_at'));
      return {
        title: 'Envió una invitación',
        detail: `Roles: ${roles(m['roles'])}${expires ? ` · vence el ${formatDate(expires, "d 'de' MMM", locale)}` : ''}`,
      };
    }
    case 'identity.invitation_accepted':
      return { title: 'Aceptó una invitación', detail: `Roles: ${roles(m['roles'])}` };
    case 'identity.organization_access_revoked':
      return { title: `Quitó el acceso a la organización a ${who}`, detail: null };
    case 'identity.organization_ownership_transferred':
      return { title: `Transfirió la propiedad de la organización a ${who}`, detail: null };
    default:
      return { title: 'Registró una acción', detail: auditAreaLabel(entry.aggregate_type) };
  }
}

function courseProgress(quantity: number | null, delivered: number | null, returned: number | null): string | null {
  const parts = [
    quantity !== null && `${quantity} u.`,
    delivered !== null && `${delivered} entregadas en total`,
    returned ? `${returned} devueltas` : null,
  ].filter(Boolean);
  return parts.length ? parts.join(' · ') : null;
}
