import { Component } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule } from '@angular/router';
import { CardModule } from 'primeng/card';
import { ButtonModule } from 'primeng/button';
import { TagModule } from 'primeng/tag';

interface MetricCard {
  label: string;
  value: string;
  trend: string;
  icon: string;
  color: string;
}

@Component({
  selector: 'app-home',
  standalone: true,
  imports: [CommonModule, RouterModule, CardModule, ButtonModule, TagModule],
  template: `
    <div class="home-container">
      <div class="header-section">
        <div>
          <span class="eyebrow">Saint Andrew's School &bull; Evento Activo</span>
          <h1 class="page-title">Panel de Control &mdash; Kermesse 2026</h1>
          <p class="subtitle">Resumen ejecutivo y monitoreo en tiempo real de operaciones</p>
        </div>
        <div class="header-actions">
          <button pButton label="Ver Eventos" icon="pi pi-calendar" routerLink="/events" class="p-button-outlined"></button>
          <button pButton label="Ventas & Operaciones" icon="pi pi-plus" class="p-button-primary"></button>
        </div>
      </div>

      <!-- Métricas Principales -->
      <div class="metrics-grid">
        @for (metric of metrics; track metric.label) {
          <div class="metric-card">
            <div class="metric-header">
              <span class="metric-label">{{ metric.label }}</span>
              <div class="metric-icon" [style.background-color]="metric.color">
                <i [class]="'pi ' + metric.icon"></i>
              </div>
            </div>
            <div class="metric-value">{{ metric.value }}</div>
            <div class="metric-trend">
              <i class="pi pi-arrow-up-right"></i>
              <span>{{ metric.trend }}</span>
            </div>
          </div>
        }
      </div>

      <!-- Grilla secundaria -->
      <div class="grid-secondary">
        <p-card header="Estado del Evento Actual" subheader="Kermesse Halloween 2026">
          <div class="event-details">
            <div class="detail-row">
              <span class="label">Organización:</span>
              <span class="val">Saint Andrew's School</span>
            </div>
            <div class="detail-row">
              <span class="label">Fecha:</span>
              <span class="val">Miércoles 28 de octubre de 2026</span>
            </div>
            <div class="detail-row">
              <span class="label">Puestos Activos:</span>
              <span class="val">18 puestos de gastronomía y juegos</span>
            </div>
            <div class="detail-row">
              <span class="label">Estado Contable:</span>
              <p-tag severity="success" value="Abierto"></p-tag>
            </div>
          </div>
        </p-card>

        <p-card header="Arquitectura Frontend (Hito 0)" subheader="Verificación de Base Tecnológica">
          <ul class="tech-list">
            <li><i class="pi pi-check-circle success"></i> Angular 22 Standalone Components</li>
            <li><i class="pi pi-check-circle success"></i> PrimeNG con tema Aura & PrimeIcons</li>
            <li><i class="pi pi-check-circle success"></i> Interceptor HTTP con prefijo <code>/api/v1</code></li>
            <li><i class="pi pi-check-circle success"></i> Manejador centralizado de errores HTTP</li>
            <li><i class="pi pi-check-circle success"></i> Layout responsive (móvil, tableta, escritorio)</li>
          </ul>
        </p-card>
      </div>
    </div>
  `,
  styles: [`
    .home-container {
      display: flex;
      flex-direction: column;
      gap: 1.75rem;
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

    .header-actions {
      display: flex;
      gap: 0.75rem;
      align-items: center;
    }

    .metrics-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
      gap: 1.25rem;
    }

    .metric-card {
      background: #ffffff;
      border: 1px solid #e2e8f0;
      border-radius: 14px;
      padding: 1.25rem;
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
      display: flex;
      flex-direction: column;
    }

    .metric-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 0.75rem;
    }

    .metric-label {
      font-size: 0.85rem;
      color: #64748b;
      font-weight: 600;
    }

    .metric-icon {
      width: 36px;
      height: 36px;
      border-radius: 10px;
      display: grid;
      place-items: center;
      color: #ffffff;
      font-size: 0.95rem;
    }

    .metric-value {
      font-size: 1.75rem;
      font-weight: 800;
      color: #18243a;
      margin-bottom: 0.5rem;
    }

    .metric-trend {
      font-size: 0.75rem;
      color: #16a34a;
      display: flex;
      align-items: center;
      gap: 0.25rem;
      font-weight: 600;
    }

    .grid-secondary {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(360px, 1fr));
      gap: 1.5rem;
    }

    .event-details {
      display: flex;
      flex-direction: column;
      gap: 0.85rem;
    }

    .detail-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding-bottom: 0.65rem;
      border-bottom: 1px solid #f1f5f9;

      .label {
        font-size: 0.85rem;
        color: #64748b;
      }

      .val {
        font-size: 0.9rem;
        font-weight: 600;
        color: #18243a;
      }
    }

    .tech-list {
      list-style: none;
      padding: 0;
      margin: 0;
      display: flex;
      flex-direction: column;
      gap: 0.75rem;

      li {
        display: flex;
        align-items: center;
        gap: 0.65rem;
        font-size: 0.88rem;
        color: #334155;

        i.success {
          color: #16a34a;
          font-size: 1rem;
        }

        code {
          background: #f1f5f9;
          padding: 0.15rem 0.35rem;
          border-radius: 4px;
          color: #0f172a;
          font-size: 0.82rem;
        }
      }
    }
  `]
})
export class HomeComponent {
  readonly metrics: MetricCard[] = [
    { label: 'Ingresos Totales (Est.)', value: 'Bs 38.450', trend: '+14% vs estimado', icon: 'pi-wallet', color: '#18243a' },
    { label: 'Tickets Emitidos', value: '1.420', trend: '92% canjeados', icon: 'pi-ticket', color: '#ea580c' },
    { label: 'Puestos en Operación', value: '18 / 18', trend: '100% operativos', icon: 'pi-shop', color: '#0284c7' },
    { label: 'Margen Proyectado', value: '62.4%', trend: 'Balance saludable', icon: 'pi-chart-pie', color: '#16a34a' }
  ];
}
