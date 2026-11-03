import { Component, computed, inject, signal } from '@angular/core';
import { CommonModule, DecimalPipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HttpClient } from '@angular/common/http';
import { Router, RouterModule } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { ButtonModule } from 'primeng/button';
import { TableModule } from 'primeng/table';
import { EventContextService, onActiveEventChange } from '../../core/services/event-context.service';
import { describeLoadError } from '../../core/services/load-error';
import { LoadErrorBannerComponent } from '../../core/ui/load-error-banner.component';

interface HomeProduct {
  id: string;
  stand_id: string;
  stand_name: string;
  code: string;
  name: string;
  unit_price: number;
  stock: number;
  sold: number;
  available_quantity: number;
}

interface HomeSale {
  id: string;
  operator_name: string;
  channel: string;
  status: 'pending_payment' | 'confirmed' | 'cancelled' | 'refunded';
  buyer_email: string;
  buyer_name: string | null;
  total: string;
  payment: {
    method: 'cash' | 'qr_transfer' | 'card';
    status: string;
  };
  ticket: {
    ticket_number: string;
    status: string;
  } | null;
  created_at: string;
}

interface HomeExpense {
  id?: string;
  concept: string;
  category: string;
  amount: number;
}

@Component({
  selector: 'app-home',
  standalone: true,
  imports: [CommonModule, FormsModule, DecimalPipe, RouterModule, ButtonModule, TableModule, LoadErrorBannerComponent],
  template: `
    <div class="page">
      <app-load-error-banner [message]="loadError()" (retry)="loadData()" />
      <div class="page-header">
        <div>
          <span class="eyebrow">Panel del evento &bull; {{ ctx.active()?.date }}</span>
          <h1>{{ ctx.active()?.name }}</h1>
          <p>Resumen operativo en tiempo real conectado a MariaDB: ventas, pagos QR, tickets y recaudación por puesto.</p>
        </div>
        <div class="page-actions">
          <p-button (onClick)="go('/sales')" label="Nueva venta" icon="pi pi-plus" [disabled]="ctx.readOnly()" />
          <p-button (onClick)="go('/tickets')" label="Validar QR" icon="pi pi-qrcode" severity="secondary" [outlined]="true" />
          <p-button (onClick)="go('/reports')" label="Ver balance" icon="pi pi-chart-line" severity="secondary" [outlined]="true" />
        </div>
      </div>

      <div class="kpi-grid">
        <div class="kpi">
          <div class="kpi-label">Recaudación acumulada</div>
          <div class="kpi-value">Bs {{ confirmedIncome() | number:'1.2-2' }}</div>
          <div class="kpi-foot">Ventas del catálogo en MariaDB</div>
        </div>
        <div class="kpi">
          <div class="kpi-label">Pagos por verificar</div>
          <div class="kpi-value">{{ pendingVerifyCount() }}</div>
          <div class="kpi-foot">Transferencias / QR pendientes</div>
        </div>
        <div class="kpi">
          <div class="kpi-label">Ventas en caja POS</div>
          <div class="kpi-value">{{ sales().length }}</div>
          <div class="kpi-foot">{{ pendingTicketsCount() }} tickets con canje pendiente</div>
        </div>
        <div class="kpi">
          <div class="kpi-label">Gastos registrados</div>
          <div class="kpi-value">Bs {{ expensesTotal() | number:'1.2-2' }}</div>
          <div class="kpi-foot">{{ expenses().length }} conceptos en MariaDB</div>
        </div>
      </div>

      <div class="grid-2">
        <section class="panel">
          <div class="panel-head">
            <div>
              <h2><i class="pi pi-shopping-bag"></i> Ventas registradas en vivo</h2>
              <small class="muted">Transacciones reales desde MariaDB</small>
            </div>
            <a routerLink="/sales" class="link-more">Ir al POS →</a>
          </div>

          <!-- Buscador rápido y espacio de filtros para el grid de inicio -->
          <div class="grid-toolbar">
            <div class="grid-search">
              <i class="pi pi-search"></i>
              <input
                type="text"
                placeholder="Búsqueda rápida por ticket o comprador..."
                [ngModel]="saleSearch()"
                (ngModelChange)="saleSearch.set($event)"
              />
              @if (saleSearch()) {
                <button class="clear-btn" (click)="saleSearch.set('')" title="Limpiar">
                  <i class="pi pi-times"></i>
                </button>
              }
            </div>
            <div class="grid-filters">
              <span class="filter-label"><i class="pi pi-filter"></i> Filtros:</span>
              <select class="filter-select" [ngModel]="saleMethodFilter()" (ngModelChange)="saleMethodFilter.set($event)">
                <option value="">Todos los medios</option>
                <option value="cash">Efectivo</option>
                <option value="qr_transfer">QR / Transf.</option>
              </select>
              <select class="filter-select" [ngModel]="saleStatusFilter()" (ngModelChange)="saleStatusFilter.set($event)">
                <option value="">Todos los estados</option>
                <option value="confirmed">Confirmado</option>
                <option value="pending_payment">Por verificar</option>
              </select>
              @if (saleSearch() || saleMethodFilter() || saleStatusFilter()) {
                <button class="filter-reset" (click)="saleSearch.set(''); saleMethodFilter.set(''); saleStatusFilter.set('')">
                  <i class="pi pi-filter-slash"></i> Limpiar
                </button>
              }
            </div>
          </div>

          <p-table
            [value]="filteredSales()"
            [paginator]="true"
            [rows]="5"
            [rowsPerPageOptions]="[5, 10]"
            [showCurrentPageReport]="true"
            currentPageReportTemplate="{first}-{last} de {totalRecords} ventas"
            styleClass="p-datatable-sm"
          >
            <ng-template #header>
              <tr>
                <th>Ticket</th>
                <th>Comprador</th>
                <th>Medio</th>
                <th style="text-align: right">Total</th>
                <th>Estado</th>
              </tr>
            </ng-template>
            <ng-template #body let-s>
              <tr>
                <td>
                  <b class="mono">{{ s.ticket?.ticket_number || (s.id | slice:0:8) }}</b><br />
                  <small class="muted">{{ s.created_at | date:'shortTime' }} &bull; {{ s.operator_name }}</small>
                </td>
                <td>
                  <b>{{ s.buyer_name || 'Comprador' }}</b><br />
                  <small class="muted">{{ s.buyer_email }}</small>
                </td>
                <td>
                  <span class="pill" [class.blue]="s.payment.method === 'cash'" [class.cyan]="s.payment.method !== 'cash'">
                    {{ s.payment.method === 'cash' ? 'Efectivo' : 'QR Banco' }}
                  </span>
                </td>
                <td style="text-align: right" class="money"><b>Bs {{ s.total }}</b></td>
                <td>
                  <span class="pill" [class.green]="s.status === 'confirmed'" [class.yellow]="s.status === 'pending_payment'" [class.red]="s.status === 'cancelled'">
                    {{ s.status === 'confirmed' ? 'Confirmado' : (s.status === 'pending_payment' ? 'Por verificar' : 'Anulado') }}
                  </span>
                </td>
              </tr>
            </ng-template>
            <ng-template #emptymessage>
              <tr>
                <td colspan="5">
                  <div class="empty-grid-state">
                    <i class="pi pi-inbox"></i>
                    <p>No se encontraron ventas con los filtros seleccionados.</p>
                  </div>
                </td>
              </tr>
            </ng-template>
          </p-table>
        </section>

        <section class="panel">
          <div class="panel-head">
            <div>
              <h2><i class="pi pi-shop"></i> Recaudación por puesto</h2>
              <small class="muted">Progreso en bolivianos según unidades vendidas</small>
            </div>
            <span class="pill cyan">Bs vendidos</span>
          </div>
          <div class="panel-body">
            @for (st of byStand(); track st.name) {
              <div class="row">
                <div class="row-top">
                  <span><i class="pi pi-shop"></i> <b>{{ st.name }}</b> <small class="muted">({{ st.units }} uds.)</small></span>
                  <b class="money">Bs {{ st.amount | number:'1.2-2' }}</b>
                </div>
                <div class="bar"><i [style.width.%]="st.pct"></i></div>
              </div>
            } @empty {
              <p class="muted">Todavía no hay ventas registradas por puesto.</p>
            }
          </div>
        </section>
      </div>
    </div>
  `,
  styles: [`
    .row { margin-bottom: 1.15rem; }
    .row-top { display: flex; justify-content: space-between; font-size: .88rem; margin-bottom: .25rem; }
    .row-top i { color: var(--kf-primary); margin-right: .3rem; }
    .link-more { font-size: .82rem; font-weight: 700; color: var(--kf-primary); text-decoration: none; }
    .link-more:hover { text-decoration: underline; }
  `],
})
export class HomeComponent {
  private readonly router = inject(Router);
  private readonly http = inject(HttpClient);
  protected readonly ctx = inject(EventContextService);

  protected readonly products = signal<HomeProduct[]>([]);
  protected readonly sales = signal<HomeSale[]>([]);
  protected readonly expenses = signal<HomeExpense[]>([]);
  protected readonly loadError = signal<string | null>(null);

  // Filtros del grid de ventas en Home
  protected readonly saleSearch = signal('');
  protected readonly saleMethodFilter = signal('');
  protected readonly saleStatusFilter = signal('');

  protected readonly expensesTotal = computed(() =>
    this.expenses().reduce((t, e) => t + e.amount, 0)
  );

  protected readonly confirmedIncome = computed(() =>
    this.products().reduce((t, p) => t + p.unit_price * p.sold, 0)
  );

  protected readonly pendingVerifyCount = computed(() =>
    this.sales().filter((s) => s.status === 'pending_payment').length
  );

  protected readonly pendingTicketsCount = computed(() =>
    this.sales().filter((s) => s.ticket && s.ticket.status !== 'redeemed' && s.ticket.status !== 'cancelled').length
  );

  protected readonly filteredSales = computed(() => {
    const q = this.saleSearch().trim().toLowerCase();
    const method = this.saleMethodFilter();
    const status = this.saleStatusFilter();

    return this.sales().filter((s) => {
      if (method && s.payment.method !== method) return false;
      if (status && s.status !== status) return false;
      if (q) {
        const hay = `${s.ticket?.ticket_number ?? ''} ${s.buyer_name ?? ''} ${s.buyer_email} ${s.operator_name}`.toLowerCase();
        if (!hay.includes(q)) return false;
      }
      return true;
    });
  });

  protected readonly byStand = computed(() => {
    const map = new Map<string, { name: string; amount: number; units: number }>();
    for (const p of this.products()) {
      const curr = map.get(p.stand_name) ?? { name: p.stand_name, amount: 0, units: 0 };
      curr.amount += p.unit_price * p.sold;
      curr.units += p.sold;
      map.set(p.stand_name, curr);
    }
    const rows = Array.from(map.values()).sort((a, b) => b.amount - a.amount);
    const max = Math.max(1, ...rows.map((r) => r.amount));
    return rows.map((r) => ({ ...r, pct: Math.round((r.amount / max) * 100) }));
  });

  constructor() {
    onActiveEventChange(() => {
      // Al cambiar de evento se vacía primero: no mostrar cifras del evento anterior bajo el nuevo título.
      this.products.set([]);
      this.sales.set([]);
      this.expenses.set([]);
      this.loadData();
    });
  }

  async loadData(): Promise<void> {
    const orgId = this.ctx.orgId;
    const eventId = this.ctx.requireActive().id;
    this.loadError.set(null);
    try {
      const [prodRes, salesRes, expRes] = await Promise.all([
        firstValueFrom(this.http.get<{ data: HomeProduct[] }>(`/organizations/${orgId}/events/${eventId}/catalog/products`)),
        firstValueFrom(this.http.get<{ data: HomeSale[] }>(`/organizations/${orgId}/events/${eventId}/sales`)),
        firstValueFrom(this.http.get<{ data: HomeExpense[] }>(`/organizations/${orgId}/events/${eventId}/expenses`)),
      ]);
      if (this.ctx.isStale(eventId)) return;
      this.products.set(prodRes?.data ?? []);
      this.sales.set(salesRes?.data ?? []);
      this.expenses.set(expRes?.data ?? []);
    } catch (err) {
      if (this.ctx.isStale(eventId)) return;
      this.products.set([]);
      this.sales.set([]);
      this.expenses.set([]);
      this.loadError.set(describeLoadError(err, 'No se pudo cargar el panel del evento.'));
    }
  }

  go(url: string): void {
    this.router.navigateByUrl(url);
  }
}
