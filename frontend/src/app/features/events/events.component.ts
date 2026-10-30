import { Component } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule } from '@angular/router';
import { TableModule } from 'primeng/table';
import { ButtonModule } from 'primeng/button';
import { TagModule } from 'primeng/tag';
import { CardModule } from 'primeng/card';

export interface EventItem {
  id: string;
  name: string;
  organization: string;
  date: string;
  standsCount: number;
  status: 'Activo' | 'Borrador' | 'Cerrado';
  severity: 'success' | 'warn' | 'secondary' | 'info';
}

@Component({
  selector: 'app-events',
  standalone: true,
  imports: [CommonModule, RouterModule, TableModule, ButtonModule, TagModule, CardModule],
  template: `
    <div class="events-container">
      <div class="header-section">
        <div>
          <span class="eyebrow">Gestión de Eventos</span>
          <h1 class="page-title">Eventos de la Organización</h1>
          <p class="subtitle">Historial y eventos vigentes bajo el aislamiento multi-evento</p>
        </div>
        <div>
          <button pButton label="Crear Nuevo Evento" icon="pi pi-plus" class="p-button-primary"></button>
        </div>
      </div>

      <p-card>
        <p-table [value]="events" [scrollable]="true" styleClass="p-datatable-striped">
          <ng-template pTemplate="header">
            <tr>
              <th>Nombre del Evento</th>
              <th>Organización</th>
              <th>Fecha de Realización</th>
              <th>Puestos</th>
              <th>Estado</th>
              <th style="width: 140px; text-align: center;">Acciones</th>
            </tr>
          </ng-template>
          <ng-template pTemplate="body" let-event>
            <tr>
              <td>
                <strong>{{ event.name }}</strong>
                <small class="id-tag">ID: {{ event.id }}</small>
              </td>
              <td>{{ event.organization }}</td>
              <td>{{ event.date }}</td>
              <td>{{ event.standsCount }} puestos</td>
              <td>
                <p-tag [severity]="event.severity" [value]="event.status"></p-tag>
              </td>
              <td style="text-align: center;">
                <button
                  pButton
                  icon="pi pi-external-link"
                  class="p-button-rounded p-button-text p-button-sm"
                  title="Abrir panel operativo"
                  routerLink="/home"
                ></button>
              </td>
            </tr>
          </ng-template>
        </p-table>
      </p-card>
    </div>
  `,
  styles: [`
    .events-container {
      display: flex;
      flex-direction: column;
      gap: 1.5rem;
    }

    .header-section {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 1.5rem;
      flex-wrap: wrap;
    }

    .eyebrow {
      color: #ea580c;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      font-size: 0.75rem;
      display: block;
      margin-bottom: 0.25rem;
    }

    .page-title {
      font-size: 1.75rem;
      font-weight: 800;
      color: #18243a;
      margin: 0 0 0.35rem 0;
      letter-spacing: -0.02em;
    }

    .subtitle {
      color: #64748b;
      margin: 0;
      font-size: 0.95rem;
    }

    strong {
      display: block;
      color: #18243a;
      font-size: 0.95rem;
    }

    .id-tag {
      color: #94a3b8;
      font-size: 0.75rem;
    }
  `]
})
export class EventsComponent {
  readonly events: EventItem[] = [
    {
      id: 'EVT-2026-HLLW',
      name: 'Kermesse Halloween 2026',
      organization: "Saint Andrew's School",
      date: '28 de Octubre, 2026',
      standsCount: 18,
      status: 'Activo',
      severity: 'success'
    },
    {
      id: 'EVT-2026-SPRG',
      name: 'Día de la Primavera 2026',
      organization: "Saint Andrew's School",
      date: '21 de Septiembre, 2026',
      standsCount: 14,
      status: 'Cerrado',
      severity: 'secondary'
    },
    {
      id: 'EVT-2026-XMAS',
      name: 'Bazar Navideño 2026',
      organization: "Saint Andrew's School",
      date: '12 de Diciembre, 2026',
      standsCount: 22,
      status: 'Borrador',
      severity: 'warn'
    }
  ];
}
