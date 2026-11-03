import { Component, computed, inject, signal } from '@angular/core';
import { CommonModule, DatePipe, DecimalPipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HttpClient } from '@angular/common/http';
import { firstValueFrom } from 'rxjs';
import { ButtonModule } from 'primeng/button';
import { DialogModule } from 'primeng/dialog';
import { InputTextModule } from 'primeng/inputtext';
import { InputNumberModule } from 'primeng/inputnumber';
import { TableModule } from 'primeng/table';
import { EventContextService, onActiveEventChange } from '../../core/services/event-context.service';
import { describeLoadError } from '../../core/services/load-error';
import { LoadErrorBannerComponent } from '../../core/ui/load-error-banner.component';

interface ApiProductReport {
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

interface ApiExpense {
  id?: string;
  concept: string;
  category: string;
  amount: number;
  recorded_by_name?: string;
  occurred_at?: string;
}

interface ApiAuditLog {
  id: string;
  action: string;
  aggregate_type: string;
  aggregate_id: string;
  request_id: string;
  actor_name: string;
  actor_email: string;
  metadata: Record<string, any>;
  created_at: string;
}

@Component({
  selector: 'app-reports',
  standalone: true,
  imports: [
    CommonModule,
    FormsModule,
    DecimalPipe,
    DatePipe,
    ButtonModule,
    DialogModule,
    InputTextModule,
    InputNumberModule,
    TableModule,
    LoadErrorBannerComponent,
  ],
  template: `
    <div class="page">
      <app-load-error-banner [message]="loadError()" (retry)="loadData()" />
      <div class="page-header">
        <div>
          <span class="eyebrow">Administración &bull; Finanzas y Auditoría en MariaDB</span>
          <h1>Reportes, balance y cierre</h1>
          <p>Resultado consolidado del evento con ingresos, gastos y bitácora de auditoría en tiempo real.</p>
        </div>
        <div class="page-actions">
          <p-button label="Actualizar" icon="pi pi-refresh" severity="secondary" [outlined]="true" (onClick)="loadData()" />
          <p-button label="PDF" icon="pi pi-file-pdf" severity="secondary" [outlined]="true" (onClick)="exportPdf()" />
          <p-button label="Excel (CSV)" icon="pi pi-file-excel" severity="secondary" [outlined]="true" (onClick)="exportCsv()" />
        </div>
      </div>

      @if (exported()) {
        <div class="notice info">
          <i class="pi pi-check-circle"></i>
          <span>{{ exported() }}</span>
        </div>
      }

      @if (errorMessage()) {
        <div class="notice warn">
          <i class="pi pi-exclamation-triangle"></i>
          <span>{{ errorMessage() }}</span>
        </div>
      }

      <div class="kpi-grid">
        <div class="kpi">
          <div class="kpi-label">Ingresos por catálogo</div>
          <div class="kpi-value">Bs {{ income() | number:'1.2-2' }}</div>
          <div class="kpi-foot">{{ totalUnitsSold() }} unidades vendidas</div>
        </div>
        <div class="kpi">
          <div class="kpi-label">Gastos operativos</div>
          <div class="kpi-value">Bs {{ expensesTotal() | number:'1.2-2' }}</div>
          <div class="kpi-foot">{{ expenseRows().length }} conceptos en MariaDB</div>
        </div>
        <div class="kpi">
          <div class="kpi-label">Resultado neto</div>
          <div class="kpi-value" [class.neg]="result() < 0">Bs {{ result() | number:'1.2-2' }}</div>
          <div class="kpi-foot">Ingresos menos gastos</div>
        </div>
        <div class="kpi">
          <div class="kpi-label">Margen sobre ingresos</div>
          <div class="kpi-value">{{ margin() | number:'1.1-1' }} %</div>
          <div class="kpi-foot">{{ auditLogs().length }} eventos auditados</div>
        </div>
      </div>

      <div class="grid-2">
        <!-- Grid 1: Ingresos por puesto y producto -->
        <section class="panel">
          <div class="panel-head">
            <div>
              <h2><i class="pi pi-chart-bar"></i> Ingresos por puesto y producto</h2>
              <small class="muted">Sincronizado con el inventario de MariaDB</small>
            </div>
            <span class="grid-meta">{{ filteredProducts().length }} de {{ products().length }}</span>
          </div>

          <div class="grid-toolbar">
            <div class="grid-search">
              <i class="pi pi-search"></i>
              <input
                type="text"
                placeholder="Búsqueda rápida por producto o código..."
                [ngModel]="prodSearch()"
                (ngModelChange)="prodSearch.set($event)"
              />
              @if (prodSearch()) {
                <button class="clear-btn" (click)="prodSearch.set('')" title="Limpiar">
                  <i class="pi pi-times"></i>
                </button>
              }
            </div>
            <div class="grid-filters">
              <span class="filter-label"><i class="pi pi-filter"></i> Filtros:</span>
              <select class="filter-select" [ngModel]="prodStandFilter()" (ngModelChange)="prodStandFilter.set($event)">
                <option value="">Todos los puestos</option>
                @for (st of standNames(); track st) {
                  <option [value]="st">{{ st }}</option>
                }
              </select>
              @if (prodSearch() || prodStandFilter()) {
                <button class="filter-reset" (click)="prodSearch.set(''); prodStandFilter.set('')">
                  <i class="pi pi-filter-slash"></i> Limpiar
                </button>
              }
            </div>
          </div>

          <p-table
            [value]="filteredProducts()"
            [paginator]="true"
            [rows]="5"
            [rowsPerPageOptions]="[5, 10, 20]"
            [showCurrentPageReport]="true"
            currentPageReportTemplate="{first}-{last} de {totalRecords} productos"
            styleClass="p-datatable-sm"
          >
            <ng-template #header>
              <tr>
                <th>Producto</th>
                <th>Puesto</th>
                <th style="text-align: right">Unidades</th>
                <th style="text-align: right">Ingreso</th>
              </tr>
            </ng-template>
            <ng-template #body let-p>
              <tr>
                <td>
                  <b>{{ p.name }}</b><br />
                  <small class="mono muted">{{ p.code }} &bull; Bs {{ p.unit_price | number:'1.2-2' }}</small>
                </td>
                <td><span class="pill blue">{{ p.stand_name }}</span></td>
                <td style="text-align: right" class="mono">{{ p.sold }}</td>
                <td style="text-align: right" class="money"><b>Bs {{ p.unit_price * p.sold | number:'1.2-2' }}</b></td>
              </tr>
            </ng-template>
            <ng-template #emptymessage>
              <tr>
                <td colspan="4">
                  <div class="empty-grid-state">
                    <i class="pi pi-inbox"></i>
                    <p>No se encontraron productos para el filtro seleccionado.</p>
                  </div>
                </td>
              </tr>
            </ng-template>
          </p-table>
        </section>

        <!-- Grid 2: Gastos -->
        <section class="panel">
          <div class="panel-head">
            <div>
              <h2><i class="pi pi-wallet"></i> Gastos del evento</h2>
              <small class="muted">Egresos persistidos en MariaDB</small>
            </div>
            <p-button
              size="small"
              label="Registrar gasto"
              icon="pi pi-plus"
              (onClick)="openExpenseDialog()"
              [disabled]="ctx.readOnly()"
            />
          </div>

          <div class="grid-toolbar">
            <div class="grid-search">
              <i class="pi pi-search"></i>
              <input
                type="text"
                placeholder="Búsqueda rápida por concepto..."
                [ngModel]="expSearch()"
                (ngModelChange)="expSearch.set($event)"
              />
              @if (expSearch()) {
                <button class="clear-btn" (click)="expSearch.set('')" title="Limpiar">
                  <i class="pi pi-times"></i>
                </button>
              }
            </div>
            <div class="grid-filters">
              <span class="filter-label"><i class="pi pi-filter"></i> Filtros:</span>
              <select class="filter-select" [ngModel]="expCategoryFilter()" (ngModelChange)="expCategoryFilter.set($event)">
                <option value="">Todas las categorías</option>
                @for (cat of expenseCategories(); track cat) {
                  <option [value]="cat">{{ cat }}</option>
                }
              </select>
              @if (expSearch() || expCategoryFilter()) {
                <button class="filter-reset" (click)="expSearch.set(''); expCategoryFilter.set('')">
                  <i class="pi pi-filter-slash"></i> Limpiar
                </button>
              }
            </div>
          </div>

          <p-table
            [value]="filteredExpenses()"
            [paginator]="true"
            [rows]="5"
            [rowsPerPageOptions]="[5, 10]"
            [showCurrentPageReport]="true"
            currentPageReportTemplate="{first}-{last} de {totalRecords} gastos"
            styleClass="p-datatable-sm"
          >
            <ng-template #header>
              <tr>
                <th>Concepto</th>
                <th>Categoría</th>
                <th style="text-align: right">Monto</th>
              </tr>
            </ng-template>
            <ng-template #body let-e>
              <tr>
                <td>
                  <b>{{ e.concept }}</b>
                  @if (e.recorded_by_name) {
                    <br /><small class="muted">Reg. por {{ e.recorded_by_name }}</small>
                  }
                </td>
                <td><span class="pill gray">{{ e.category }}</span></td>
                <td style="text-align: right" class="money"><b>Bs {{ e.amount | number:'1.2-2' }}</b></td>
              </tr>
            </ng-template>
            <ng-template #emptymessage>
              <tr>
                <td colspan="3">
                  <div class="empty-grid-state">
                    <i class="pi pi-wallet"></i>
                    <p>No hay gastos que coincidan con el filtro aplicado.</p>
                  </div>
                </td>
              </tr>
            </ng-template>
          </p-table>
        </section>
      </div>

      <!-- Grid 3: Bitácora de Auditoría Transaccional (audit_logs) -->
      <section class="panel">
        <div class="panel-head">
          <div>
            <h2><i class="pi pi-history"></i> Bitácora de auditoría transaccional (<code>audit_logs</code>)</h2>
            <small class="muted">Trazabilidad inmutable de ventas, canjes de tickets, revisiones de pagos, catálogo e invitaciones.</small>
          </div>
          <span class="grid-meta">{{ filteredAuditLogs().length }} de {{ auditLogs().length }} registros</span>
        </div>

        <div class="grid-toolbar">
          <div class="grid-search">
            <i class="pi pi-search"></i>
            <input
              type="text"
              placeholder="Búsqueda rápida por acción, usuario, entidad o ID..."
              [ngModel]="auditSearch()"
              (ngModelChange)="auditSearch.set($event)"
            />
            @if (auditSearch()) {
              <button class="clear-btn" (click)="auditSearch.set('')" title="Limpiar">
                <i class="pi pi-times"></i>
              </button>
            }
          </div>
          <div class="grid-filters">
            <span class="filter-label"><i class="pi pi-filter"></i> Filtros:</span>
            <select class="filter-select" [ngModel]="auditTypeFilter()" (ngModelChange)="auditTypeFilter.set($event)">
              <option value="">Todas las entidades</option>
              @for (tp of auditAggregateTypes(); track tp) {
                <option [value]="tp">{{ tp }}</option>
              }
            </select>
            @if (auditSearch() || auditTypeFilter()) {
              <button class="filter-reset" (click)="auditSearch.set(''); auditTypeFilter.set('')">
                <i class="pi pi-filter-slash"></i> Limpiar
              </button>
            }
          </div>
        </div>

        <p-table
          [value]="filteredAuditLogs()"
          [paginator]="true"
          [rows]="5"
          [rowsPerPageOptions]="[5, 10, 25]"
          [showCurrentPageReport]="true"
          currentPageReportTemplate="Mostrando {first} a {last} de {totalRecords} eventos de auditoría"
          styleClass="p-datatable-sm"
        >
          <ng-template #header>
            <tr>
              <th>Fecha / Hora</th>
              <th>Acción</th>
              <th>Entidad</th>
              <th>Actor</th>
              <th>Detalle de la operación</th>
            </tr>
          </ng-template>
          <ng-template #body let-a>
            <tr>
              <td><small class="mono">{{ a.created_at | date:'short' }}</small></td>
              <td><span class="pill cyan mono">{{ a.action }}</span></td>
              <td><span class="pill gray">{{ a.aggregate_type }}</span></td>
              <td>
                <b>{{ a.actor_name }}</b>
                @if (a.actor_email) {
                  <br /><small class="muted">{{ a.actor_email }}</small>
                }
              </td>
              <td><small class="mono muted">{{ formatAuditMetadata(a) }}</small></td>
            </tr>
          </ng-template>
          <ng-template #emptymessage>
            <tr>
              <td colspan="5">
                <div class="empty-grid-state">
                  <i class="pi pi-history"></i>
                  <p>No se encontraron registros de auditoría para los filtros seleccionados.</p>
                </div>
              </td>
            </tr>
          </ng-template>
        </p-table>
      </section>

      <section class="panel close">
        <div class="panel-body">
          <div>
            <h2><i class="pi pi-lock"></i> Cierre definitivo del evento</h2>
            <p class="muted">
              Al cerrar, el evento queda en <b>modo consulta</b> para todos los usuarios, incluidos administradores: no se permiten ventas, canjes, gastos ni cambios en web y móvil. Detalle y exportaciones se conservan.
            </p>
            <div class="notice warn">
              <i class="pi pi-exclamation-triangle"></i>
              <span>Todavía no disponible: falta definir qué rol autoriza el cierre y la API aún no lo implementa. Hasta entonces el evento no se puede cerrar desde aquí.</span>
            </div>
          </div>
          <p-button
            [label]="ctx.readOnly() ? 'Evento cerrado' : 'Cerrar evento'"
            icon="pi pi-lock"
            severity="danger"
            [disabled]="true"
          />
        </div>
      </section>
    </div>

    <!-- Modal: Registrar nuevo gasto -->
    <p-dialog
      header="Registrar gasto operativo"
      [visible]="expenseDialog()"
      (visibleChange)="expenseDialog.set($event)"
      [modal]="true"
      [style]="{ width: '440px' }"
      [draggable]="false"
    >
      <div class="form-row">
        <label>Concepto del gasto</label>
        <input pInputText class="w-full" [(ngModel)]="newConcept" placeholder="Ej. Compra adicional de servilletas y vasos" />
      </div>
      <div class="form-row">
        <label>Categoría</label>
        <select class="native" [(ngModel)]="newCategory">
          <option value="Insumos">Insumos</option>
          <option value="Logística">Logística</option>
          <option value="Producción">Producción</option>
          <option value="Papelería">Papelería</option>
          <option value="Ambientación">Ambientación</option>
          <option value="Otros">Otros</option>
        </select>
      </div>
      <div class="form-row">
        <label>Monto (Bs)</label>
        <p-inputnumber [(ngModel)]="newAmount" [min]="1" mode="decimal" [minFractionDigits]="2" styleClass="w-full" />
      </div>
      <ng-template #footer>
        <p-button label="Cancelar" severity="secondary" [text]="true" (onClick)="expenseDialog.set(false)" />
        <p-button label="Guardar gasto" icon="pi pi-check" [loading]="savingExpense()" (onClick)="saveExpense()" />
      </ng-template>
    </p-dialog>
  `,
  styles: [`
    .neg { color: var(--kf-red) !important; }
    .close .panel-body { display: flex; gap: 1.5rem; align-items: center; justify-content: space-between; flex-wrap: wrap; }
    .close .panel-body > div { flex: 1; min-width: 280px; }
    .close h2 { color: var(--kf-primary); font-size: 1.05rem; margin: 0 0 .4rem; display: flex; align-items: center; gap: .4rem; }
    .native { padding: 0.6rem; border: 1px solid var(--kf-line); border-radius: 8px; font: inherit; width: 100%; }
  `],
})
export class ReportsComponent {
  private readonly http = inject(HttpClient);
  protected readonly ctx = inject(EventContextService);

  protected readonly products = signal<ApiProductReport[]>([]);
  protected readonly expenseRows = signal<ApiExpense[]>([]);
  protected readonly loadError = signal<string | null>(null);
  protected readonly auditLogs = signal<ApiAuditLog[]>([]);

  // Filtros de ingresos por producto
  protected readonly prodSearch = signal('');
  protected readonly prodStandFilter = signal('');

  // Filtros de gastos
  protected readonly expSearch = signal('');
  protected readonly expCategoryFilter = signal('');

  // Filtros de auditoría
  protected readonly auditSearch = signal('');
  protected readonly auditTypeFilter = signal('');

  // Modal de nuevo gasto
  protected readonly expenseDialog = signal(false);
  protected readonly savingExpense = signal(false);
  protected newConcept = '';
  protected newCategory = 'Insumos';
  protected newAmount = 150;

  protected readonly standNames = computed(() =>
    Array.from(new Set(this.products().map((p) => p.stand_name))).sort()
  );

  protected readonly expenseCategories = computed(() =>
    Array.from(new Set(this.expenseRows().map((e) => e.category))).sort()
  );

  protected readonly auditAggregateTypes = computed(() =>
    Array.from(new Set(this.auditLogs().map((a) => a.aggregate_type))).sort()
  );

  protected readonly filteredProducts = computed(() => {
    const q = this.prodSearch().trim().toLowerCase();
    const stand = this.prodStandFilter();
    return this.products().filter((p) => {
      if (stand && p.stand_name !== stand) return false;
      if (q && !`${p.name} ${p.code} ${p.stand_name}`.toLowerCase().includes(q)) return false;
      return true;
    });
  });

  protected readonly filteredExpenses = computed(() => {
    const q = this.expSearch().trim().toLowerCase();
    const cat = this.expCategoryFilter();
    return this.expenseRows().filter((e) => {
      if (cat && e.category !== cat) return false;
      if (q && !`${e.concept} ${e.category}`.toLowerCase().includes(q)) return false;
      return true;
    });
  });

  protected readonly filteredAuditLogs = computed(() => {
    const q = this.auditSearch().trim().toLowerCase();
    const tp = this.auditTypeFilter();
    return this.auditLogs().filter((a) => {
      if (tp && a.aggregate_type !== tp) return false;
      if (q) {
        const hay = `${a.action} ${a.aggregate_type} ${a.actor_name} ${a.actor_email} ${JSON.stringify(a.metadata)}`.toLowerCase();
        if (!hay.includes(q)) return false;
      }
      return true;
    });
  });

  protected readonly totalUnitsSold = computed(() =>
    this.products().reduce((t, p) => t + p.sold, 0)
  );

  protected readonly income = computed(() =>
    this.products().reduce((t, p) => t + p.unit_price * p.sold, 0)
  );

  protected readonly expensesTotal = computed(() =>
    this.expenseRows().reduce((t, e) => t + e.amount, 0)
  );

  protected readonly result = computed(() => this.income() - this.expensesTotal());

  protected readonly margin = computed(() => {
    const inc = this.income();
    return inc > 0 ? (this.result() / inc) * 100 : 0;
  });

  protected readonly exported = signal<string | null>(null);
  protected readonly errorMessage = signal<string | null>(null);

  constructor() {
    onActiveEventChange(() => {
      // Al cambiar de evento se vacía primero: no mostrar cifras del evento anterior bajo el nuevo título.
      this.products.set([]);
      this.expenseRows.set([]);
      this.auditLogs.set([]);
      this.exported.set(null);
      this.errorMessage.set(null);
      this.loadData();
    });
  }

  async loadData(): Promise<void> {
    const orgId = this.ctx.orgId;
    const eventId = this.ctx.requireActive().id;
    this.loadError.set(null);
    try {
      const [prodRes, expRes, auditRes] = await Promise.all([
        firstValueFrom(this.http.get<{ data: ApiProductReport[] }>(`/organizations/${orgId}/events/${eventId}/catalog/products`)),
        firstValueFrom(this.http.get<{ data: ApiExpense[] }>(`/organizations/${orgId}/events/${eventId}/expenses`)),
        firstValueFrom(this.http.get<{ data: ApiAuditLog[] }>(`/organizations/${orgId}/events/${eventId}/audit-logs`)),
      ]);
      if (this.ctx.isStale(eventId)) return;
      this.products.set(prodRes?.data ?? []);
      this.expenseRows.set(expRes?.data ?? []);
      this.auditLogs.set(auditRes?.data ?? []);
    } catch (err) {
      if (this.ctx.isStale(eventId)) return;
      this.products.set([]);
      this.expenseRows.set([]);
      this.auditLogs.set([]);
      this.loadError.set(describeLoadError(err, 'No se pudieron cargar los datos financieros del evento.'));
    }
  }

  formatAuditMetadata(a: ApiAuditLog): string {
    const entries = Object.entries(a.metadata || {});
    if (entries.length === 0) return a.aggregate_id.slice(0, 8);
    return entries
      .map(([k, v]) => `${k}: ${typeof v === 'object' ? JSON.stringify(v) : v}`)
      .join(' · ')
      .slice(0, 120);
  }

  openExpenseDialog(): void {
    this.newConcept = '';
    this.newCategory = 'Insumos';
    this.newAmount = 150;
    this.errorMessage.set(null);
    this.expenseDialog.set(true);
  }

  async saveExpense(): Promise<void> {
    if (!this.newConcept.trim()) {
      this.errorMessage.set('Ingresa el concepto del gasto.');
      return;
    }

    this.savingExpense.set(true);
    this.errorMessage.set(null);
    const orgId = this.ctx.orgId;
    const eventId = this.ctx.requireActive().id;

    try {
      await firstValueFrom(
        this.http.post(
          `/organizations/${orgId}/events/${eventId}/expenses`,
          {
            concept: this.newConcept.trim(),
            category: this.newCategory,
            amount_minor: Math.round((this.newAmount || 1) * 100),
          },
          {
            headers: {
              'Idempotency-Key': `exp-${Date.now()}`,
            },
          }
        )
      );
      this.expenseDialog.set(false);
      this.exported.set(`Gasto "${this.newConcept}" registrado en MariaDB.`);
      await this.loadData();
    } catch (err: unknown) {
      const anyErr = err as { message?: string; originalError?: { error?: { message?: string } } };
      this.errorMessage.set(
        anyErr?.originalError?.error?.message || anyErr?.message || 'Error al registrar el gasto.'
      );
    } finally {
      this.savingExpense.set(false);
    }
  }

  exportCsv(): void {
    const lines = [
      'Seccion,Codigo/Categoria,Concepto/Producto,Puesto,Unidades,Monto_BOB',
      ...this.products().map(
        (p) => `INGRESO,${p.code},"${p.name}","${p.stand_name}",${p.sold},${(p.unit_price * p.sold).toFixed(2)}`
      ),
      ...this.expenseRows().map(
        (e) => `GASTO,${e.category},"${e.concept}",-,1,-${e.amount.toFixed(2)}`
      ),
      `RESUMEN,BALANCE,Resultado Neto,-,-,${this.result().toFixed(2)}`,
    ];

    const blob = new Blob(['\uFEFF' + lines.join('\n')], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `balance-${this.ctx.requireActive().id.slice(0, 8)}.csv`;
    a.click();
    URL.revokeObjectURL(url);
    this.exported.set('Archivo Excel/CSV descargado con los datos reales de MariaDB.');
  }

  exportPdf(): void {
    window.print();
    this.exported.set('Vista de impresión / PDF generada.');
  }
}
