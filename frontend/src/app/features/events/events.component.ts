import { Component, computed, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HttpClient } from '@angular/common/http';
import { Router } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { ButtonModule } from 'primeng/button';
import { DialogModule } from 'primeng/dialog';
import { InputTextModule } from 'primeng/inputtext';
import { TableModule } from 'primeng/table';
import { EventContextService } from '../../core/services/event-context.service';
import { EVENT_STATUS } from '../../core/models/domain';
import { FechaPipe } from '../../core/ui/fecha.pipe';

@Component({
  selector: 'app-events',
  standalone: true,
  imports: [FechaPipe, CommonModule, FormsModule, ButtonModule, DialogModule, InputTextModule, TableModule],
  template: `
    <div class="page">
      <div class="page-header">
        <div>
          <span class="eyebrow">{{ ctx.organization()?.name }}</span>
          <h1>Eventos</h1>
          <p>Cada evento tiene sus propias ventas, tickets y reportes, y conserva su historial.</p>
        </div>
        <p-button label="Crear evento" icon="pi pi-plus" (onClick)="openNewDialog()" />
      </div>

      @if (feedbackMessage()) {
        <div class="notice info">
          <i class="pi pi-check-circle"></i>
          <span>{{ feedbackMessage() }}</span>
        </div>
      }

      @if (errorMessage()) {
        <div class="notice warn">
          <i class="pi pi-exclamation-triangle"></i>
          <span>{{ errorMessage() }}</span>
        </div>
      }

      <!-- Tarjetas destacadas de eventos -->
      <div class="cards">
        @for (e of filteredEvents(); track e.id) {
          <article class="panel ev" [class.current]="e.id === ctx.active()?.id">
            <div class="panel-body">
              <div class="top">
                <span [class]="'pill ' + (status[e.status]?.css || 'gray')">{{ status[e.status]?.label || e.status }}</span>
                @if (e.id === ctx.active()?.id) {
                  <span class="pill cyan">Activo en sesión</span>
                }
              </div>
              <h3>{{ e.name }}</h3>
              <p class="muted"><i class="pi pi-calendar"></i> {{ (e.starts_at | fecha:'d MMM y') || 'Sin fecha' }} &bull; {{ e.stands }} puestos</p>
              <p class="muted mono">/tienda/{{ e.slug }}</p>
              <div class="actions">
                <p-button size="small" label="Abrir panel" icon="pi pi-arrow-right" iconPos="right" (onClick)="open(e.id)" />
                <p-button size="small" label="Catálogo" severity="secondary" [outlined]="true" (onClick)="open(e.id, '/catalog')" [disabled]="e.status === 'closed'" />
              </div>
            </div>
          </article>
        }
      </div>

      <!-- Grid estándar con paginador, buscador rápido y espacio de filtros -->
      <section class="panel">
        <div class="panel-head">
          <div>
            <h2><i class="pi pi-calendar"></i> Padrón histórico de eventos</h2>
            <small class="muted">Todos los eventos de la organización, incluidos los pasados</small>
          </div>
          <span class="grid-meta">{{ filteredEvents().length }} de {{ ctx.all().length }} eventos</span>
        </div>

        <div class="grid-toolbar">
          <div class="grid-search">
            <i class="pi pi-search"></i>
            <input
              type="text"
              placeholder="Búsqueda rápida por nombre o enlace..."
              [ngModel]="search()"
              (ngModelChange)="search.set($event)"
            />
            @if (search()) {
              <button class="clear-btn" (click)="search.set('')" title="Limpiar">
                <i class="pi pi-times"></i>
              </button>
            }
          </div>
          <div class="grid-filters">
            <span class="filter-label"><i class="pi pi-filter"></i> Filtros:</span>
            <select class="filter-select" [ngModel]="statusFilter()" (ngModelChange)="statusFilter.set($event)">
              <option value="">Todos los estados</option>
              <option value="open">En curso</option>
              <option value="draft">Borrador</option>
              <option value="closed">Cerrado (Histórico)</option>
            </select>
            @if (search() || statusFilter()) {
              <button class="filter-reset" (click)="search.set(''); statusFilter.set('')">
                <i class="pi pi-filter-slash"></i> Limpiar
              </button>
            }
          </div>
        </div>

        <p-table
          [value]="filteredEvents()"
          [paginator]="true"
          [rows]="5"
          [rowsPerPageOptions]="[5, 10, 20]"
          [showCurrentPageReport]="true"
          currentPageReportTemplate="Mostrando {first} a {last} de {totalRecords} eventos"
          styleClass="p-datatable-sm"
        >
          <ng-template #header>
            <tr>
              <th>Evento</th>
              <th>Enlace de la tienda</th>
              <th>Fecha</th>
              <th>Puestos</th>
              <th>Estado</th>
              <th style="text-align: right">Acción</th>
            </tr>
          </ng-template>
          <ng-template #body let-e>
            <tr>
              <td>
                <b>{{ e.name }}</b>
                @if (e.id === ctx.active()?.id) {
                  <span class="pill cyan" style="margin-left: .4rem">Actual</span>
                }
              </td>
              <td class="mono muted">/tienda/{{ e.slug }}</td>
              <td>{{ (e.starts_at | fecha:'d MMM y') || 'Sin fecha' }}</td>
              <td>{{ e.stands }} puestos</td>
              <td>
                <span [class]="'pill ' + (status[e.status]?.css || 'gray')">
                  {{ status[e.status]?.label || e.status }}
                </span>
              </td>
              <td style="text-align: right">
                <p-button size="small" label="Seleccionar" icon="pi pi-check" [outlined]="e.id !== ctx.active()?.id" (onClick)="open(e.id)" />
              </td>
            </tr>
          </ng-template>
        </p-table>
      </section>
    </div>

    <p-dialog header="Nuevo evento" [visible]="showNew()" (visibleChange)="showNew.set($event)" [modal]="true" [style]="{ width: '440px' }" [draggable]="false">
      <div class="form-row">
        <label>Nombre del evento</label>
        <input pInputText class="w-full" [(ngModel)]="newName" (ngModelChange)="autoSlug($event)" placeholder="Ej. Kermesse Día de la Familia 2027" />
      </div>
      <div class="form-row">
        <label>Fecha programada</label>
        <input pInputText type="date" class="w-full" [(ngModel)]="newDate" />
      </div>
      <div class="form-row">
        <label>Enlace de la tienda pública</label>
        <input pInputText class="w-full mono" [(ngModel)]="newSlug" placeholder="dia-familia-2027" />
      </div>
      <div class="notice warn">
        <i class="pi pi-info-circle"></i>
        <span>El evento se crea como <b>borrador</b> y quedas como responsable del evento.</span>
      </div>
      <ng-template #footer>
        <p-button label="Cancelar" severity="secondary" [text]="true" (onClick)="showNew.set(false)" />
        <p-button label="Crear evento" icon="pi pi-check" [loading]="saving()" (onClick)="createEvent()" />
      </ng-template>
    </p-dialog>
  `,
  styles: [`
    .cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1rem; }
    .ev.current { border-color: var(--kf-cyan); box-shadow: 0 0 0 3px var(--kf-cyan-soft); }
    .top { display: flex; gap: .4rem; }
    h3 { margin: .8rem 0 .3rem; color: var(--kf-primary); }
    p { margin: .2rem 0; font-size: .85rem; }
    .actions { display: flex; gap: .5rem; margin-top: 1rem; }
  `],
})
export class EventsComponent {
  private readonly http = inject(HttpClient);
  protected readonly ctx = inject(EventContextService);
  private readonly router = inject(Router);
  protected readonly status: Record<string, { label: string; css: string }> = EVENT_STATUS;

  protected readonly showNew = signal(false);
  protected readonly saving = signal(false);
  protected readonly feedbackMessage = signal<string | null>(null);
  protected readonly errorMessage = signal<string | null>(null);

  // Filtros del Grid de Eventos
  protected readonly search = signal('');
  protected readonly statusFilter = signal('');

  protected newName = '';
  protected newDate = '2027-05-15';
  protected newSlug = '';

  protected readonly filteredEvents = computed(() => {
    const q = this.search().trim().toLowerCase();
    const st = this.statusFilter();
    return this.ctx.all().filter((e) => {
      if (st && e.status !== st) return false;
      if (q && !`${e.name} ${e.slug}`.toLowerCase().includes(q)) return false;
      return true;
    });
  });

  openNewDialog(): void {
    this.newName = '';
    this.newSlug = '';
    this.newDate = '2027-05-15';
    this.errorMessage.set(null);
    this.showNew.set(true);
  }

  autoSlug(name: string): void {
    this.newSlug = name
      .toLowerCase()
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/(^-|-$)/g, '');
  }

  async createEvent(): Promise<void> {
    if (!this.newName.trim() || !this.newSlug.trim()) {
      this.errorMessage.set('Completa el nombre y el slug del evento.');
      return;
    }

    this.saving.set(true);
    this.errorMessage.set(null);

    try {
      await firstValueFrom(
        this.http.post(
          `/organizations/${this.ctx.orgId}/events`,
          {
            name: this.newName.trim(),
            slug: this.newSlug.trim(),
            starts_at: this.newDate ? `${this.newDate}T09:00:00Z` : null,
          },
          {
            headers: {
              'Idempotency-Key': `evt-new-${Date.now()}`,
            },
          }
        )
      );
      this.showNew.set(false);
      this.feedbackMessage.set(`Evento "${this.newName}" creado.`);
      await this.ctx.refreshEvents();
    } catch (err: unknown) {
      const anyErr = err as { message?: string; originalError?: { error?: { message?: string } } };
      this.errorMessage.set(
        anyErr?.originalError?.error?.message || anyErr?.message || 'No se pudo crear el evento.'
      );
    } finally {
      this.saving.set(false);
    }
  }

  open(id: string, route = '/home'): void {
    this.ctx.select(id);
    this.router.navigateByUrl(route);
  }
}
