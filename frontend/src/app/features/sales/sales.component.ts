import { Component, computed, inject, signal, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HttpClient } from '@angular/common/http';
import { ButtonModule } from 'primeng/button';
import { InputTextModule } from 'primeng/inputtext';
import { TableModule } from 'primeng/table';
import { firstValueFrom } from 'rxjs';
import { EventContextService } from '../../core/services/event-context.service';
import { AuthService } from '../../core/services/auth.service';

type Tab = 'pos' | 'verify' | 'history';

interface ApiProduct {
  id: string;
  stand_id: string;
  stand_name: string;
  code: string;
  name: string;
  description: string | null;
  unit_price: number;
  currency: string;
  stock: number;
  sold: number;
  available_quantity: number;
  status: 'active' | 'paused';
  active: boolean;
}

interface ApiSale {
  id: string;
  organization_id: string;
  event_id: string;
  operator_user_id: string;
  operator_name: string;
  channel: string;
  status: 'pending_payment' | 'confirmed' | 'cancelled' | 'refunded';
  buyer_email: string;
  buyer_name: string | null;
  buyer_phone: string | null;
  subtotal: string;
  discount_total: string;
  total: string;
  currency: string;
  payment: {
    id: string;
    method: 'cash' | 'qr_transfer' | 'card';
    status: 'pending' | 'to_verify' | 'confirmed' | 'rejected';
    amount: string;
    currency: string;
  };
  items: Array<{
    id: string;
    event_product_id: string;
    product_code: string;
    product_name: string;
    unit_price: string;
    quantity: number;
    line_total: string;
  }>;
  ticket: {
    id: string;
    ticket_number: string;
    qr_code: string;
    status: string;
  } | null;
  created_at: string;
}

@Component({
  selector: 'app-sales',
  standalone: true,
  imports: [CommonModule, FormsModule, ButtonModule, InputTextModule, TableModule],
  template: `
    <div class="page">
      <div class="page-header">
        <div>
          <span class="eyebrow">Operación en vivo &bull; MariaDB</span>
          <h1>Ventas y pagos</h1>
          <p>Registro de ventas en tiempo real con bloqueo pesimista de inventario y emisión inmediata de tickets QR.</p>
        </div>
        <div class="segmented">
          <button [class.active]="tab() === 'pos'" (click)="tab.set('pos')">
            <i class="pi pi-shopping-cart"></i> Punto de venta
          </button>
          <button [class.active]="tab() === 'verify'" (click)="tab.set('verify')">
            <i class="pi pi-qrcode"></i> Verificar pagos ({{ pending().length }})
          </button>
          <button [class.active]="tab() === 'history'" (click)="tab.set('history')">
            <i class="pi pi-history"></i> Historial ({{ sales().length }})
          </button>
        </div>
      </div>

      @if (errorMessage()) {
        <div class="notice warn">
          <i class="pi pi-exclamation-triangle"></i>
          <span>{{ errorMessage() }}</span>
        </div>
      }

      @if (reviewMessage()) {
        <div class="notice info">
          <i class="pi pi-check-circle"></i>
          <span>{{ reviewMessage() }}</span>
        </div>
      }

      @switch (tab()) {
        @case ('pos') {
          <div class="pos">
            <div class="pos-catalog panel">
              <div class="panel-head">
                <h2><i class="pi pi-th-large"></i> Catálogo disponible en caja</h2>
                <span class="grid-meta">{{ filteredPosProducts().length }} de {{ products().length }} productos</span>
              </div>

              <!-- Barra de búsqueda rápida y filtros del POS -->
              <div class="grid-toolbar">
                <div class="grid-search">
                  <i class="pi pi-search"></i>
                  <input
                    type="text"
                    placeholder="Búsqueda rápida por nombre o código..."
                    [ngModel]="posSearch()"
                    (ngModelChange)="posSearch.set($event)"
                  />
                  @if (posSearch()) {
                    <button class="clear-btn" (click)="posSearch.set('')" title="Limpiar">
                      <i class="pi pi-times"></i>
                    </button>
                  }
                </div>
                <div class="grid-filters">
                  <span class="filter-label"><i class="pi pi-filter"></i> Filtros:</span>
                  <select class="filter-select" [ngModel]="posStandFilter()" (ngModelChange)="posStandFilter.set($event)">
                    <option value="">Todos los puestos</option>
                    @for (st of standNames(); track st) {
                      <option [value]="st">{{ st }}</option>
                    }
                  </select>
                  <select class="filter-select" [ngModel]="posStockFilter()" (ngModelChange)="posStockFilter.set($event)">
                    <option value="">Toda disponibilidad</option>
                    <option value="available">Con stock disponible</option>
                    <option value="low">Stock bajo (&le; 15)</option>
                  </select>
                  @if (posSearch() || posStandFilter() || posStockFilter()) {
                    <button class="filter-reset" (click)="resetPosFilters()">
                      <i class="pi pi-filter-slash"></i> Limpiar
                    </button>
                  }
                </div>
              </div>

              <section class="products">
                @for (p of filteredPosProducts(); track p.id) {
                  <button
                    class="prod"
                    (click)="add(p.id)"
                    [disabled]="ctx.readOnly() || p.available_quantity <= 0"
                  >
                    <div class="prod-top">
                      <span class="stand-tag">{{ p.stand_name }}</span>
                      <span class="code-tag mono">{{ p.code }}</span>
                    </div>
                    <b>{{ p.name }}</b>
                    <div class="prod-foot">
                      <span class="money price">Bs {{ p.unit_price | number:'1.2-2' }}</span>
                      <small class="stock-badge" [class.danger]="p.available_quantity <= 0" [class.warn]="p.available_quantity > 0 && p.available_quantity <= 15">
                        {{ p.available_quantity <= 0 ? 'Agotado' : p.available_quantity + ' disp.' }}
                      </small>
                    </div>
                  </button>
                } @empty {
                  <div class="empty-grid-state" style="grid-column: 1 / -1">
                    <i class="pi pi-inbox"></i>
                    <p>No se encontraron productos con los filtros seleccionados.</p>
                  </div>
                }
              </section>
            </div>

            <aside class="panel cart">
              <div class="panel-head">
                <h2><i class="pi pi-receipt"></i> Orden actual</h2>
                <span class="pill blue">{{ itemCount() }} ítems</span>
              </div>
              <div class="panel-body">
                @for (l of cartLines(); track l.id) {
                  <div class="line">
                    <div class="line-info">
                      <b>{{ l.name }}</b>
                      <small class="muted">Bs {{ l.unitPrice | number:'1.2-2' }} c/u</small>
                    </div>
                    <span class="qty">
                      <button (click)="remove(l.id)">−</button>
                      <b>{{ l.qty }}</b>
                      <button (click)="add(l.id)" [disabled]="l.qty >= l.available">+</button>
                    </span>
                    <b class="money">Bs {{ l.subtotal | number:'1.2-2' }}</b>
                  </div>
                } @empty {
                  <div class="empty-cart">
                    <i class="pi pi-shopping-bag"></i>
                    <p>Selecciona productos del catálogo para armar el pedido.</p>
                  </div>
                }

                <div class="form-row mt">
                  <label for="buyer_email">Correo del comprador (para el ticket QR)</label>
                  <input
                    pInputText
                    id="buyer_email"
                    [(ngModel)]="buyerEmail"
                    placeholder="padre.familia@saintandrews.edu.bo"
                    class="w-full"
                    required
                  />
                </div>

                <div class="form-row">
                  <label for="buyer_name">Nombre del comprador</label>
                  <input
                    pInputText
                    id="buyer_name"
                    [(ngModel)]="buyerName"
                    placeholder="Ej. Familia Villazón"
                    class="w-full"
                  />
                </div>

                <label class="lbl">Medio de pago</label>
                <div class="methods">
                  <button
                    type="button"
                    [class.active]="paymentMethod() === 'cash'"
                    (click)="paymentMethod.set('cash')"
                  >
                    <i class="pi pi-money-bill"></i> Efectivo
                  </button>
                  <button
                    type="button"
                    [class.active]="paymentMethod() === 'qr_transfer'"
                    (click)="paymentMethod.set('qr_transfer')"
                  >
                    <i class="pi pi-qrcode"></i> QR Banco
                  </button>
                </div>

                @if (paymentMethod() === 'qr_transfer') {
                  <div class="notice warn small">
                    <i class="pi pi-info-circle"></i>
                    <span>La venta se registrará como <b>Por verificar</b> hasta confirmación bancaria.</span>
                  </div>
                }

                <div class="total">
                  <span>Total a cobrar</span>
                  <b class="money">Bs {{ total() | number:'1.2-2' }}</b>
                </div>

                <p-button
                  [fluid]="true"
                  label="Registrar venta y emitir QR"
                  icon="pi pi-check"
                  [loading]="submitting()"
                  [disabled]="!itemCount() || ctx.readOnly() || submitting()"
                  (onClick)="checkout()"
                />

                @if (lastSale()) {
                  <div class="sale-success">
                    <i class="pi pi-check-circle"></i>
                    <div>
                      <b>Venta registrada con éxito</b>
                      <small>Ticket emitido: <span class="mono">{{ lastSale()?.ticket?.ticket_number || lastSale()?.id }}</span></small>
                    </div>
                  </div>
                }
              </div>
            </aside>
          </div>
        }

        @case ('verify') {
          <section class="panel">
            <div class="panel-head">
              <div>
                <h2><i class="pi pi-qrcode"></i> Pagos QR y transferencias pendientes de verificación</h2>
                <small class="muted">Verifica el abono bancario antes de habilitar el canje del ticket.</small>
              </div>
              <span class="grid-meta">{{ filteredPending().length }} de {{ pending().length }} pendientes</span>
            </div>

            <!-- Barra de búsqueda rápida y espacio de filtros -->
            <div class="grid-toolbar">
              <div class="grid-search">
                <i class="pi pi-search"></i>
                <input
                  type="text"
                  placeholder="Búsqueda rápida por ticket, comprador o correo..."
                  [ngModel]="verifySearch()"
                  (ngModelChange)="verifySearch.set($event)"
                />
                @if (verifySearch()) {
                  <button class="clear-btn" (click)="verifySearch.set('')" title="Limpiar">
                    <i class="pi pi-times"></i>
                  </button>
                }
              </div>
              <div class="grid-filters">
                <span class="filter-label"><i class="pi pi-filter"></i> Filtros:</span>
                <select class="filter-select" [ngModel]="verifyOperatorFilter()" (ngModelChange)="verifyOperatorFilter.set($event)">
                  <option value="">Todos los operadores</option>
                  @for (op of operatorNames(); track op) {
                    <option [value]="op">{{ op }}</option>
                  }
                </select>
                @if (verifySearch() || verifyOperatorFilter()) {
                  <button class="filter-reset" (click)="verifySearch.set(''); verifyOperatorFilter.set('')">
                    <i class="pi pi-filter-slash"></i> Limpiar
                  </button>
                }
              </div>
            </div>

            <p-table
              [value]="filteredPending()"
              [paginator]="true"
              [rows]="5"
              [rowsPerPageOptions]="[5, 10, 20]"
              [showCurrentPageReport]="true"
              currentPageReportTemplate="Mostrando {first} a {last} de {totalRecords} pagos por verificar"
              styleClass="p-datatable-sm"
            >
              <ng-template pTemplate="header">
                <tr>
                  <th>Ticket</th>
                  <th>Fecha / Hora</th>
                  <th>Comprador</th>
                  <th>Detalle de productos</th>
                  <th>Operador</th>
                  <th style="text-align: right">Monto QR</th>
                  <th>Estado</th>
                  <th style="text-align: right">Acciones</th>
                </tr>
              </ng-template>
              <ng-template pTemplate="body" let-s>
                <tr>
                  <td><b class="mono">{{ s.ticket?.ticket_number || (s.id | slice:0:8) }}</b></td>
                  <td>{{ s.created_at | date:'short' }}</td>
                  <td>
                    <b>{{ s.buyer_name || 'Comprador' }}</b><br />
                    <small class="muted">{{ s.buyer_email }}</small>
                  </td>
                  <td>{{ describeItems(s) }}</td>
                  <td>{{ s.operator_name }}</td>
                  <td style="text-align: right" class="money"><b>Bs {{ s.total }}</b></td>
                  <td><span class="pill yellow">Por verificar</span></td>
                  <td style="text-align: right">
                    <div class="row-actions">
                      <p-button
                        size="small"
                        label="Confirmar"
                        icon="pi pi-check"
                        severity="success"
                        [loading]="reviewingSaleId() === s.id"
                        [disabled]="ctx.readOnly() || reviewingSaleId() !== null"
                        (onClick)="reviewPayment(s, 'confirm')"
                      />
                      <p-button
                        size="small"
                        label="Rechazar"
                        icon="pi pi-times"
                        severity="danger"
                        [outlined]="true"
                        [disabled]="ctx.readOnly() || reviewingSaleId() !== null"
                        (onClick)="reviewPayment(s, 'reject')"
                      />
                    </div>
                  </td>
                </tr>
              </ng-template>
              <ng-template pTemplate="emptymessage">
                <tr>
                  <td colspan="8">
                    <div class="empty-grid-state">
                      <i class="pi pi-check-circle"></i>
                      <p>No hay pagos pendientes de verificación con los criterios actuales.</p>
                    </div>
                  </td>
                </tr>
              </ng-template>
            </p-table>
          </section>
        }

        @case ('history') {
          <section class="panel">
            <div class="panel-head">
              <div>
                <h2><i class="pi pi-list"></i> Historial general de ventas</h2>
                <small class="muted">Registro transaccional completo en MariaDB con trazabilidad por cajero y ticket.</small>
              </div>
              <span class="grid-meta">{{ filteredSales().length }} de {{ sales().length }} ventas</span>
            </div>

            <!-- Barra de búsqueda rápida y espacio de filtros -->
            <div class="grid-toolbar">
              <div class="grid-search">
                <i class="pi pi-search"></i>
                <input
                  type="text"
                  placeholder="Búsqueda rápida por N° ticket, comprador, correo o producto..."
                  [ngModel]="historySearch()"
                  (ngModelChange)="historySearch.set($event)"
                />
                @if (historySearch()) {
                  <button class="clear-btn" (click)="historySearch.set('')" title="Limpiar">
                    <i class="pi pi-times"></i>
                  </button>
                }
              </div>

              <div class="grid-filters">
                <span class="filter-label"><i class="pi pi-filter"></i> Filtros:</span>
                <select class="filter-select" [ngModel]="historyStatusFilter()" (ngModelChange)="historyStatusFilter.set($event)">
                  <option value="">Todos los estados</option>
                  <option value="confirmed">Confirmado</option>
                  <option value="pending_payment">Pendiente de pago</option>
                </select>

                <select class="filter-select" [ngModel]="historyMethodFilter()" (ngModelChange)="historyMethodFilter.set($event)">
                  <option value="">Todos los medios</option>
                  <option value="cash">Efectivo</option>
                  <option value="qr_transfer">QR / Transferencia</option>
                </select>

                <select class="filter-select" [ngModel]="historyOperatorFilter()" (ngModelChange)="historyOperatorFilter.set($event)">
                  <option value="">Todos los operadores</option>
                  @for (op of operatorNames(); track op) {
                    <option [value]="op">{{ op }}</option>
                  }
                </select>

                @if (historySearch() || historyStatusFilter() || historyMethodFilter() || historyOperatorFilter()) {
                  <button class="filter-reset" (click)="resetHistoryFilters()">
                    <i class="pi pi-filter-slash"></i> Limpiar
                  </button>
                }
              </div>
            </div>

            <p-table
              [value]="filteredSales()"
              [paginator]="true"
              [rows]="5"
              [rowsPerPageOptions]="[5, 10, 25, 50]"
              [showCurrentPageReport]="true"
              currentPageReportTemplate="Mostrando {first} a {last} de {totalRecords} ventas"
              styleClass="p-datatable-sm"
            >
              <ng-template pTemplate="header">
                <tr>
                  <th>Ticket</th>
                  <th>Fecha / Hora</th>
                  <th>Comprador</th>
                  <th>Productos</th>
                  <th>Operador</th>
                  <th>Medio</th>
                  <th style="text-align: right">Total</th>
                  <th>Estado</th>
                </tr>
              </ng-template>
              <ng-template pTemplate="body" let-s>
                <tr>
                  <td><b class="mono">{{ s.ticket?.ticket_number || (s.id | slice:0:8) }}</b></td>
                  <td>{{ s.created_at | date:'short' }}</td>
                  <td>
                    <b>{{ s.buyer_name || 'Comprador' }}</b><br />
                    <small class="muted">{{ s.buyer_email }}</small>
                  </td>
                  <td><small>{{ describeItems(s) }}</small></td>
                  <td>{{ s.operator_name }}</td>
                  <td>
                    <span class="pill" [class.blue]="s.payment.method === 'cash'" [class.cyan]="s.payment.method !== 'cash'">
                      {{ s.payment.method === 'cash' ? 'Efectivo' : 'QR / Transf.' }}
                    </span>
                  </td>
                  <td style="text-align: right" class="money"><b>Bs {{ s.total }}</b></td>
                  <td>
                    <span class="pill" [class.green]="s.status === 'confirmed'" [class.yellow]="s.status === 'pending_payment'">
                      {{ s.status === 'confirmed' ? 'Confirmado' : 'Pendiente' }}
                    </span>
                  </td>
                </tr>
              </ng-template>
              <ng-template pTemplate="emptymessage">
                <tr>
                  <td colspan="8">
                    <div class="empty-grid-state">
                      <i class="pi pi-inbox"></i>
                      <p>No se encontraron ventas para los filtros aplicados.</p>
                    </div>
                  </td>
                </tr>
              </ng-template>
            </p-table>
          </section>
        }
      }
    </div>
  `,
  styles: [`
    .pos { display: grid; grid-template-columns: 1fr 390px; gap: 1.25rem; align-items: start; }
    .pos-catalog { overflow: hidden; }
    .products { display: grid; grid-template-columns: repeat(auto-fill, minmax(185px, 1fr)); gap: .85rem; padding: 1.1rem 1.25rem; }
    .prod {
      background: #fff; border: 1px solid var(--kf-line); border-radius: 12px; padding: .9rem;
      display: flex; flex-direction: column; justify-content: space-between; gap: .45rem;
      text-align: left; cursor: pointer; font: inherit; color: var(--kf-ink); transition: all .16s ease;
    }
    .prod:hover:not(:disabled) { border-color: var(--kf-cyan); transform: translateY(-2px); box-shadow: 0 8px 18px rgba(6, 59, 115, 0.08); }
    .prod:disabled { opacity: .5; cursor: not-allowed; background: #f8fafc; }
    .prod-top { display: flex; justify-content: space-between; align-items: center; gap: .4rem; }
    .stand-tag { font-size: 0.68rem; color: var(--kf-primary); font-weight: 800; text-transform: uppercase; letter-spacing: .04em; }
    .code-tag { font-size: 0.68rem; color: var(--kf-muted); background: #f1f5f9; padding: .1rem .35rem; border-radius: 4px; }
    .prod-foot { display: flex; justify-content: space-between; align-items: center; margin-top: .25rem; padding-top: .45rem; border-top: 1px dashed #e2e8f0; }
    .price { font-weight: 800; color: var(--kf-primary); font-size: .95rem; }
    .stock-badge { font-size: .74rem; color: #15803d; font-weight: 700; }
    .stock-badge.warn { color: #b45309; }
    .stock-badge.danger { color: #dc2626; }
    .cart { position: sticky; top: 80px; }
    .line { display: grid; grid-template-columns: 1fr auto 82px; gap: .5rem; align-items: center; font-size: .86rem; padding: .55rem 0; border-bottom: 1px solid #f1f5f9; text-align: right; }
    .line-info { display: flex; flex-direction: column; text-align: left; }
    .qty { display: inline-flex; align-items: center; gap: .35rem; }
    .qty button { border: 1px solid var(--kf-line); background: #fff; border-radius: 6px; width: 26px; height: 26px; cursor: pointer; font-weight: 700; }
    .qty button:hover:not(:disabled) { border-color: var(--kf-primary); color: var(--kf-primary); }
    .empty-cart { text-align: center; padding: 1.5rem .75rem; color: var(--kf-muted); background: #f8fafc; border-radius: 10px; border: 1px dashed #cbd5e1; }
    .empty-cart i { font-size: 1.6rem; color: #94a3b8; display: block; margin-bottom: .35rem; }
    .empty-cart p { margin: 0; font-size: .84rem; }
    .mt { margin-top: 1rem; }
    .lbl { font-size: .78rem; font-weight: 700; color: #344054; margin-top: 0.5rem; display: block; }
    .methods { display: grid; grid-template-columns: repeat(2, 1fr); gap: .45rem; margin: .4rem 0 .8rem; }
    .methods button { border: 1px solid var(--kf-line); background: #fff; border-radius: 9px; padding: .6rem; cursor: pointer; font: inherit; font-size: .82rem; font-weight: 600; display: flex; align-items: center; justify-content: center; gap: 0.4rem; transition: all .15s; }
    .methods button.active { border-color: var(--kf-primary); background: #eef4fb; color: var(--kf-primary); font-weight: 700; }
    .small { font-size: .78rem; margin-bottom: .8rem; }
    .total { display: flex; justify-content: space-between; align-items: baseline; font-size: 1.05rem; margin: .6rem 0 1rem; border-top: 1px dashed #cbd5e1; padding-top: 0.75rem; }
    .total b { color: var(--kf-primary); font-size: 1.45rem; }
    .sale-success { display: flex; align-items: center; gap: 0.65rem; color: #15803d; background: #f0fdf4; border: 1px solid #bbf7d0; padding: 0.75rem; border-radius: 10px; margin-top: 0.75rem; font-size: 0.84rem; }
    .sale-success i { font-size: 1.3rem; }
    .sale-success div { display: flex; flex-direction: column; }
    .row-actions { display: inline-flex; gap: .4rem; justify-content: flex-end; }
    @media (max-width: 1000px) { .pos { grid-template-columns: 1fr; } .cart { position: static; } }
  `],
})
export class SalesComponent implements OnInit {
  protected readonly ctx = inject(EventContextService);
  protected readonly auth = inject(AuthService);
  private readonly http = inject(HttpClient);

  protected readonly tab = signal<Tab>('pos');
  protected readonly products = signal<ApiProduct[]>([]);
  protected readonly sales = signal<ApiSale[]>([]);
  protected readonly cart = signal<Record<string, number>>({});
  protected readonly paymentMethod = signal<'cash' | 'qr_transfer'>('cash');
  protected readonly submitting = signal(false);
  protected readonly reviewingSaleId = signal<string | null>(null);
  protected readonly lastSale = signal<ApiSale | null>(null);
  protected readonly errorMessage = signal<string | null>(null);
  protected readonly reviewMessage = signal<string | null>(null);

  // Filtros POS
  protected readonly posSearch = signal('');
  protected readonly posStandFilter = signal('');
  protected readonly posStockFilter = signal('');

  // Filtros Verificar pagos
  protected readonly verifySearch = signal('');
  protected readonly verifyOperatorFilter = signal('');

  // Filtros Historial de ventas
  protected readonly historySearch = signal('');
  protected readonly historyStatusFilter = signal('');
  protected readonly historyMethodFilter = signal('');
  protected readonly historyOperatorFilter = signal('');

  protected buyerEmail = 'padre.familia@saintandrews.edu.bo';
  protected buyerName = '';

  protected readonly standNames = computed(() =>
    Array.from(new Set(this.products().map((p) => p.stand_name))).sort()
  );

  protected readonly operatorNames = computed(() =>
    Array.from(new Set(this.sales().map((s) => s.operator_name))).sort()
  );

  protected readonly filteredPosProducts = computed(() => {
    const q = this.posSearch().trim().toLowerCase();
    const stand = this.posStandFilter();
    const stock = this.posStockFilter();

    return this.products().filter((p) => {
      if (stand && p.stand_name !== stand) return false;
      if (stock === 'available' && p.available_quantity <= 0) return false;
      if (stock === 'low' && (p.available_quantity <= 0 || p.available_quantity > 15)) return false;
      if (q) {
        const hay = `${p.name} ${p.code} ${p.stand_name}`.toLowerCase();
        if (!hay.includes(q)) return false;
      }
      return true;
    });
  });

  protected readonly pending = computed(() =>
    this.sales().filter((s) => s.status === 'pending_payment')
  );

  protected readonly filteredPending = computed(() => {
    const q = this.verifySearch().trim().toLowerCase();
    const op = this.verifyOperatorFilter();
    return this.pending().filter((s) => {
      if (op && s.operator_name !== op) return false;
      if (q) {
        const hay = `${s.ticket?.ticket_number ?? ''} ${s.buyer_name ?? ''} ${s.buyer_email} ${this.describeItems(s)}`.toLowerCase();
        if (!hay.includes(q)) return false;
      }
      return true;
    });
  });

  protected readonly filteredSales = computed(() => {
    const q = this.historySearch().trim().toLowerCase();
    const status = this.historyStatusFilter();
    const method = this.historyMethodFilter();
    const op = this.historyOperatorFilter();

    return this.sales().filter((s) => {
      if (status && s.status !== status) return false;
      if (method && s.payment.method !== method) return false;
      if (op && s.operator_name !== op) return false;
      if (q) {
        const hay = `${s.ticket?.ticket_number ?? ''} ${s.buyer_name ?? ''} ${s.buyer_email} ${s.operator_name} ${this.describeItems(s)}`.toLowerCase();
        if (!hay.includes(q)) return false;
      }
      return true;
    });
  });

  protected readonly cartLines = computed(() => {
    const prods = this.products();
    return Object.entries(this.cart()).map(([id, qty]) => {
      const p = prods.find((prod) => prod.id === id);
      return {
        id,
        name: p?.name || 'Producto',
        unitPrice: p?.unit_price || 0,
        qty,
        available: p?.available_quantity || 0,
        subtotal: (p?.unit_price || 0) * qty,
      };
    });
  });

  protected readonly itemCount = computed(() => this.cartLines().reduce((t, l) => t + l.qty, 0));
  protected readonly total = computed(() => this.cartLines().reduce((t, l) => t + l.subtotal, 0));

  ngOnInit(): void {
    this.loadCatalog();
    this.loadSales();
  }

  resetPosFilters(): void {
    this.posSearch.set('');
    this.posStandFilter.set('');
    this.posStockFilter.set('');
  }

  resetHistoryFilters(): void {
    this.historySearch.set('');
    this.historyStatusFilter.set('');
    this.historyMethodFilter.set('');
    this.historyOperatorFilter.set('');
  }

  async loadCatalog(): Promise<void> {
    const orgId = this.ctx.orgId;
    const eventId = this.ctx.active().id;
    try {
      const res = await firstValueFrom(
        this.http.get<{ data: ApiProduct[] }>(`/organizations/${orgId}/events/${eventId}/catalog/products?page_size=100`)
      );
      if (res?.data) {
        this.products.set(res.data);
      }
    } catch (err: unknown) {
      console.warn('[SalesComponent] No se pudo cargar catálogo del backend:', err);
    }
  }

  async loadSales(): Promise<void> {
    const orgId = this.ctx.orgId;
    const eventId = this.ctx.active().id;
    try {
      const res = await firstValueFrom(
        this.http.get<{ data: ApiSale[] }>(`/organizations/${orgId}/events/${eventId}/sales?page_size=100`)
      );
      if (res?.data) {
        this.sales.set(res.data);
      }
    } catch (err: unknown) {
      console.warn('[SalesComponent] No se pudieron cargar ventas del backend:', err);
    }
  }

  add(id: string): void {
    const p = this.products().find((item) => item.id === id);
    if (!p) return;
    const currentQty = this.cart()[id] ?? 0;
    if (currentQty >= p.available_quantity) return;

    this.cart.update((c) => ({ ...c, [id]: currentQty + 1 }));
    this.lastSale.set(null);
    this.errorMessage.set(null);
  }

  remove(id: string): void {
    this.cart.update((c) => {
      const n = { ...c };
      if (n[id] > 1) {
        n[id]--;
      } else {
        delete n[id];
      }
      return n;
    });
  }

  async checkout(): Promise<void> {
    if (!this.buyerEmail) {
      this.errorMessage.set('Por favor ingresa el correo del comprador.');
      return;
    }

    const items = Object.entries(this.cart()).map(([productId, quantity]) => ({
      event_product_id: productId,
      quantity,
    }));

    if (items.length === 0) return;

    this.submitting.set(true);
    this.errorMessage.set(null);

    const orgId = this.ctx.orgId;
    const eventId = this.ctx.active().id;
    const idempotencyKey = `pos-${Date.now()}-${Math.random().toString(36).substring(2, 8)}`;

    try {
      const res = await firstValueFrom(
        this.http.post<{ data: ApiSale }>(
          `/organizations/${orgId}/events/${eventId}/sales`,
          {
            buyer_email: this.buyerEmail.trim(),
            buyer_name: this.buyerName ? this.buyerName.trim() : null,
            payment_method: this.paymentMethod(),
            items,
          },
          {
            headers: {
              'Idempotency-Key': idempotencyKey,
            },
          }
        )
      );

      if (res?.data) {
        this.lastSale.set(res.data);
        this.cart.set({});
        await Promise.all([this.loadCatalog(), this.loadSales()]);
      }
    } catch (err: unknown) {
      const anyErr = err as { message?: string; originalError?: { error?: { message?: string } } };
      const msg = anyErr?.originalError?.error?.message || anyErr?.message || 'Error al procesar la venta en el backend.';
      this.errorMessage.set(msg);
    } finally {
      this.submitting.set(false);
    }
  }

  describeItems(s: ApiSale): string {
    return s.items.map((i) => `${i.quantity} × ${i.product_name}`).join(' · ');
  }

  async reviewPayment(sale: ApiSale, decision: 'confirm' | 'reject'): Promise<void> {
    this.reviewingSaleId.set(sale.id);
    this.errorMessage.set(null);
    this.reviewMessage.set(null);

    const orgId = this.ctx.orgId;
    const eventId = this.ctx.active().id;
    const idempotencyKey = `pay-rev-${sale.id}-${decision}-${Date.now()}`;

    try {
      await firstValueFrom(
        this.http.post(
          `/organizations/${orgId}/events/${eventId}/sales/${sale.id}/payment/review`,
          {
            decision,
            provider_reference: `BNB-QR-${Date.now().toString().slice(-6)}`,
          },
          {
            headers: {
              'Idempotency-Key': idempotencyKey,
            },
          }
        )
      );

      const ticketLabel = sale.ticket?.ticket_number || sale.id.slice(0, 8);
      if (decision === 'confirm') {
        this.reviewMessage.set(`Pago QR de ${ticketLabel} confirmado. El ticket ya está habilitado para canje.`);
      } else {
        this.reviewMessage.set(`Pago QR de ${ticketLabel} rechazado. Se anuló el ticket y se restituyó el stock al catálogo.`);
      }

      await Promise.all([this.loadSales(), this.loadCatalog()]);
    } catch (err: unknown) {
      const anyErr = err as { message?: string; originalError?: { error?: { message?: string } } };
      this.errorMessage.set(
        anyErr?.originalError?.error?.message || anyErr?.message || 'No se pudo procesar la revisión del pago.'
      );
    } finally {
      this.reviewingSaleId.set(null);
    }
  }
}
