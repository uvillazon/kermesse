import { Injectable, computed, effect, inject, signal, untracked } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { firstValueFrom } from 'rxjs';
import { KEvent } from '../models/domain';
import { describeLoadError } from './load-error';

export const CURRENT_ORG_ID = '11111111-1111-4111-8111-111111111111';

export type LoadState = 'loading' | 'ready' | 'error';

@Injectable({ providedIn: 'root' })
export class EventContextService {
  private readonly http = inject(HttpClient);
  readonly orgId = CURRENT_ORG_ID;

  private readonly events = signal<KEvent[]>([]);
  private readonly activeId = signal<string | null>(null);
  private readonly stateSignal = signal<LoadState>('loading');
  private readonly errorSignal = signal<string | null>(null);

  readonly all = this.events.asReadonly();
  readonly state = this.stateSignal.asReadonly();
  readonly error = this.errorSignal.asReadonly();
  /** Evento activo, o `null` mientras carga, si falló la carga o si la organización no tiene eventos. */
  readonly active = computed<KEvent | null>(
    () => this.events().find((e) => e.id === this.activeId()) ?? this.events()[0] ?? null
  );
  /** Id del evento activo; solo emite cuando cambia el id, no cuando se refresca la lista. */
  readonly activeEventId = computed(() => this.active()?.id ?? null);
  readonly readOnly = computed(() => this.active()?.status === 'closed');

  constructor() {
    this.refreshEvents();
  }

  async refreshEvents(): Promise<void> {
    this.stateSignal.set('loading');
    this.errorSignal.set(null);
    try {
      const res = await firstValueFrom(
        this.http.get<{ data: KEvent[] }>(`/api/v1/organizations/${this.orgId}/events`)
      );
      const list = res?.data ?? [];
      this.events.set(list);
      if (!list.some((e) => e.id === this.activeId())) {
        this.activeId.set(list[0]?.id ?? null);
      }
      this.stateSignal.set('ready');
    } catch (err) {
      this.events.set([]);
      this.activeId.set(null);
      this.errorSignal.set(describeLoadError(err, 'No se pudieron cargar los eventos de la organización.'));
      this.stateSignal.set('error');
    }
  }

  select(id: string): void {
    this.activeId.set(id);
  }

  /** `true` si una respuesta pedida para `eventId` llega cuando el usuario ya cambió de evento. */
  isStale(eventId: string): boolean {
    return this.activeEventId() !== eventId;
  }

  /**
   * Evento activo para las pantallas que solo se muestran con un evento cargado
   * (el layout no las renderiza sin él). Lanza si se llama sin evento.
   */
  requireActive(): KEvent {
    const ev = this.active();
    if (!ev) {
      throw new Error('No hay un evento activo cargado desde la API.');
    }
    return ev;
  }
}

/**
 * Ejecuta `load(eventId)` al crear la pantalla y cada vez que cambia el evento activo.
 * Debe llamarse en un contexto de inyección (constructor o inicializador de campo).
 * `load` corre fuera del seguimiento de señales: leer señales dentro no provoca recargas.
 */
export function onActiveEventChange(load: (eventId: string) => void): void {
  const ctx = inject(EventContextService);
  effect(() => {
    const eventId = ctx.activeEventId();
    if (eventId) {
      untracked(() => load(eventId));
    }
  });
}
