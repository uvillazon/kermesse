import { Injectable, computed, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { firstValueFrom } from 'rxjs';
import { EVENTS, KEvent } from '../mock/mock-data';

export const CURRENT_ORG_ID = '11111111-1111-4111-8111-111111111111';

@Injectable({ providedIn: 'root' })
export class EventContextService {
  private readonly http = inject(HttpClient);
  readonly orgId = CURRENT_ORG_ID;

  private readonly events = signal<KEvent[]>(structuredClone(EVENTS));
  private readonly activeId = signal<string>(EVENTS[0].id);

  readonly all = this.events.asReadonly();
  readonly active = computed(() => this.events().find((e) => e.id === this.activeId()) ?? this.events()[0]);
  readonly readOnly = computed(() => this.active()?.status === 'closed');

  constructor() {
    this.refreshEvents();
  }

  async refreshEvents(): Promise<void> {
    try {
      const res = await firstValueFrom(
        this.http.get<{ data: KEvent[] }>(`/api/v1/organizations/${this.orgId}/events`)
      );
      if (res?.data && res.data.length > 0) {
        this.events.set(res.data);
        if (!res.data.some((e) => e.id === this.activeId())) {
          this.activeId.set(res.data[0].id);
        }
      }
    } catch (err) {
      console.warn('[EventContextService] No se pudieron cargar eventos de la API, usando datos iniciales:', err);
    }
  }

  select(id: string): void {
    this.activeId.set(id);
  }

  closeActive(): void {
    const id = this.activeId();
    this.events.update((list) => list.map((e) => (e.id === id ? { ...e, status: 'closed' } : e)));
  }
}
