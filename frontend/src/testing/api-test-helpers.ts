import { computed, signal } from '@angular/core';
import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter } from '@angular/router';
import { errorHandlerInterceptor } from '../app/core/interceptors/error-handler.interceptor';
import { EventContextService } from '../app/core/services/event-context.service';
import { KEvent } from '../app/core/models/domain';

export const TEST_EVENT: KEvent = {
  id: 'evt-test',
  slug: 'evento-prueba',
  name: 'Evento de prueba',
  starts_at: '2027-01-01T14:00:00+00:00',
  date: '01 Jan 2027',
  status: 'open',
  stands: 0,
};

/** Textos de los antiguos datos de ejemplo: no deben volver a aparecer en pantalla. */
export const FORMER_MOCK_TEXTS = [
  "Saint Andrew's",
  'Kermesse Halloween 2026',
  'Insumos de cocina',
  'Carmen Gutiérrez',
  'diego.flores@gmail.com',
  '1.º Primaria A',
  'Pique macho',
  'Gastronomía',
];

/** Contexto de evento falso con un evento activo, para probar pantallas aisladas. */
export function fakeEventContext(event: KEvent | null = TEST_EVENT) {
  const active = signal<KEvent | null>(event);
  const activeEventId = computed(() => active()?.id ?? null);
  return {
    orgId: 'org-test',
    organization: signal<{ id: string; name: string; slug: string; access: string } | null>({
      id: 'org-test',
      name: 'Organización de prueba',
      slug: 'org-prueba',
      access: 'owner',
    }),
    active,
    activeEventId,
    isStale: (eventId: string) => activeEventId() !== eventId,
    all: computed(() => (active() ? [active() as KEvent] : [])),
    state: signal('ready'),
    error: signal<string | null>(null),
    readOnly: computed(() => active()?.status === 'closed'),
    requireActive: () => {
      const ev = active();
      if (!ev) throw new Error('sin evento');
      return ev;
    },
    refreshEvents: async () => {},
    select: () => {},
  };
}

/** Proveedores HTTP de prueba. Con `ctx = null` se usa el EventContextService real. */
export function apiTestProviders(ctx: unknown | null = fakeEventContext()) {
  const base = [
    provideRouter([]),
    provideHttpClient(withInterceptors([errorHandlerInterceptor])),
    provideHttpClientTesting(),
  ];
  return ctx === null ? base : [...base, { provide: EventContextService, useValue: ctx }];
}

/** Deja correr promesas pendientes (await de firstValueFrom, Promise.all, etc.). */
export async function settle(): Promise<void> {
  for (let i = 0; i < 5; i++) {
    await new Promise((r) => setTimeout(r, 0));
  }
}

/**
 * Responde todas las peticiones abiertas (y las que se abran como consecuencia)
 * con el mismo cuerpo, o con un error HTTP si se pasa `status`.
 */
export async function respondAll(
  http: HttpTestingController,
  reply: { body?: unknown; status?: number } = { body: { data: [] } }
): Promise<number> {
  let count = 0;
  for (let round = 0; round < 5; round++) {
    await settle();
    const open = http.match(() => true);
    if (open.length === 0) break;
    for (const req of open) {
      count++;
      if (reply.status) {
        req.flush({ message: 'fallo simulado' }, { status: reply.status, statusText: 'Error' });
      } else {
        req.flush(reply.body ?? { data: [] });
      }
    }
  }
  await settle();
  return count;
}
