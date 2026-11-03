import { registerLocaleData } from '@angular/common';
import localeEsBo from '@angular/common/locales/es-BO';
import { AuditEntry, auditAreaLabel, describeAudit } from './audit-description';

registerLocaleData(localeEsBo);
const L = 'es-BO';

function entry(action: string, aggregate_type: string, metadata: Record<string, unknown>, subject_name: string | null = null): AuditEntry {
  return {
    id: 'a1', action, aggregate_type, aggregate_id: 'b2c3d4e5-0000-4000-8000-000000000001', request_id: 'r1',
    actor_name: 'Ana Torres', actor_email: 'ana@demo.test', subject_name, metadata, created_at: '2026-11-03 14:14:33',
  };
}

// Metadatos con la forma real que guarda el backend (copiados de audit_logs).
const SAMPLES: AuditEntry[] = [
  entry('sale.created', 'sale', { total_minor: 1800, currency: 'BOB', payment_method: 'cash', status: 'confirmed', ticket_number: 'TKT-DFBAC64E', reservation_expires_at: null }),
  entry('sale.reservation_expired', 'sale', { payment_id: 'bf51d752-7bd1-4b96-97ab-8684e479cba6', sale_status: 'cancelled', payment_status: 'cancelled' }),
  entry('payment.confirmed', 'sale', { payment_id: 'bf51d752-7bd1-4b96-97ab-8684e479cba6', decision: 'confirm', provider_reference: 'BNB-123' }),
  entry('payment.rejected', 'sale', { payment_id: 'bf51d752-7bd1-4b96-97ab-8684e479cba6', decision: 'reject' }),
  entry('ticket.redeemed', 'ticket', { ticket_number: 'TKT-7E2053F5', redeemed_quantity: 2, total_quantity: 3, status: 'partial' }),
  entry('catalog.product_created', 'product', { code: 'COM-03', name: 'Hamburguesa', stand_id: '110bb039-ffe8-403d-9082-bb993403eb69', unit_price_minor: 2000, stock: 80 }),
  entry('catalog.product_updated', 'product', { name: 'Hamburguesa', unit_price_minor: 2200, stock: 70, active: false, version: 3 }),
  entry('catalog.stand_created', 'stand', { name: 'Postres', manager: 'Luis Méndez' }),
  entry('course_allocation.created', 'course_allocation', { course: '5.º Secundaria B', delegate: 'Prof. Diego Arce', product: 'Cuñapé (3 u.)', assigned_quantity: 30 }),
  entry('course_allocation.deliver', 'course_allocation', { course: '5.º Secundaria B', action: 'deliver', quantity: 1, delivered: 5, returned: 0 }),
  entry('event.created', 'event', { name: 'Kermesse de Primavera 2027', slug: 'kermesse-primavera-2027', status: 'draft' }),
  entry('event.opened', 'event', { name: 'Feria del Libro 2027', previous_status: 'draft', status: 'open' }),
  entry('expense.created', 'expense', { concept: 'Alquiler de toldos', category: 'Logística', amount_minor: 25000 }),
  entry('identity.event_access_assigned', 'event_membership', { target_user_id: 'd1000000-0000-4000-8000-000000000002', previous: null, current: { access_type: 'operational', roles: ['SALES_OPERATOR', 'TICKET_OPERATOR'] } }, 'Luis Méndez'),
  entry('identity.event_roles_changed', 'event_membership', { target_user_id: 'd1000000-0000-4000-8000-000000000002', previous_roles: ['SALES_OPERATOR'], current_roles: ['PAYMENT_REVIEWER'] }, 'Luis Méndez'),
  entry('identity.event_access_revoked', 'event_membership', { target_user_id: 'd1000000-0000-4000-8000-000000000002' }, null),
  entry('identity.invitation_created', 'access_invitation', { target_access_type: 'operational', roles: ['FINANCE_REVIEWER'], expires_at: '2026-11-20T18:00:00+00:00' }),
  entry('identity.organization_ownership_transferred', 'organization_membership', { new_owner_user_id: 'd1000000-0000-4000-8000-000000000003' }, 'Carla Ríos'),
  entry('algo.desconocido', 'misterio', { foo: { bar: 1 } }),
];

const sample = (action: string): AuditEntry => SAMPLES.find((s) => s.action === action)!;

describe('Historial de cambios en lenguaje del usuario', () => {
  it.each(SAMPLES.map((s) => [s.action, s] as const))('%s no muestra UUID, JSON ni códigos', (_a, sample) => {
    const d = describeAudit(sample, L);
    const text = `${d.title} ${d.detail ?? ''}`;
    expect(d.title.length).toBeGreaterThan(5);
    expect(text).not.toMatch(/[0-9a-f]{8}-[0-9a-f]{4}-/i);
    expect(text).not.toMatch(/[{}[\]"]/);
    expect(text).not.toMatch(/[A-Z]+_[A-Z]+/);
    expect(text).not.toMatch(/undefined|null|NaN/);
  });

  it('describe una venta con monto boliviano, medio y ticket', () => {
    expect(describeAudit(sample('sale.created'), L)).toEqual({ title: 'Registró una venta de Bs 18,00 en efectivo', detail: 'Ticket TKT-DFBAC64E' });
  });

  it('nombra a la persona afectada y sus roles', () => {
    expect(describeAudit(sample('identity.event_access_assigned'), L)).toEqual({ title: 'Dio acceso al evento a Luis Méndez', detail: 'Roles: Operador de ventas, Operador de tickets' });
    expect(describeAudit(sample('identity.event_roles_changed'), L).detail).toBe('Antes: Operador de ventas · Ahora: Verificador de pagos');
  });

  it('si no conoce el nombre de la persona no muestra su id', () => {
    expect(describeAudit(sample('identity.event_access_revoked'), L).title).toBe('Quitó el acceso al evento a una persona');
  });

  it('gasto, entrega de ticket e invitación', () => {
    expect(describeAudit(sample('expense.created'), L).title).toBe('Registró el gasto Alquiler de toldos por Bs 250,00');
    expect(describeAudit(sample('ticket.redeemed'), L)).toEqual({ title: 'Entregó productos del ticket TKT-7E2053F5', detail: '2 de 3 productos entregados' });
    expect(describeAudit(sample('identity.invitation_created'), L).detail).toBe('Roles: Revisor financiero · vence el 20 de nov');
  });

  it('describe la apertura de un evento', () => {
    expect(describeAudit(sample('event.opened'), L)).toEqual({ title: 'Abrió el evento Feria del Libro 2027', detail: 'Desde ahora se puede vender y canjear' });
  });

  it('agrupa los tipos técnicos en áreas legibles', () => {
    expect(auditAreaLabel('event_membership')).toBe('Acceso');
    expect(auditAreaLabel('organization_membership')).toBe('Acceso');
    expect(auditAreaLabel('cualquier_cosa')).toBe('Otro');
  });
});
