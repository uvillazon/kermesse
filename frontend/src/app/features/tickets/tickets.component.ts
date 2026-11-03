import { Component, computed, effect, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HttpClient } from '@angular/common/http';
import { firstValueFrom } from 'rxjs';
import { ButtonModule } from 'primeng/button';
import { InputTextModule } from 'primeng/inputtext';
import { TableModule } from 'primeng/table';
import { EventContextService } from '../../core/services/event-context.service';
import { describeLoadError } from '../../core/services/load-error';
import { LoadErrorBannerComponent } from '../../core/ui/load-error-banner.component';

type Tab = 'redeem' | 'list' | 'courses';

export interface ApiTicketItem {
  id: string;
  sale_item_id: string;
  product_id: string;
  product_name: string;
  stand_id: string | null;
  stand_name: string;
  quantity: number;
  redeemed_quantity: number;
  remaining_quantity: number;
}

export interface ApiTicketRedemption {
  id: string;
  ticket_item_id: string;
  product_name: string;
  quantity: number;
  redeemed_by_user_id: string;
  redeemed_by_name: string;
  redeemed_at: string;
}

export interface ApiTicket {
  id: string;
  organization_id: string;
  event_id: string;
  sale_id: string;
  ticket_number: string;
  qr_code: string;
  status: 'active' | 'partially_redeemed' | 'redeemed' | 'pending' | 'cancelled';
  buyer_email: string;
  buyer_name: string | null;
  total: string;
  currency: string;
  payment_method: string;
  payment_status: string;
  total_quantity: number;
  redeemed_quantity: number;
  remaining_quantity: number;
  items_summary: string;
  items: ApiTicketItem[];
  redemptions: ApiTicketRedemption[];
  issued_at: string;
}

@Component({
  selector: 'app-tickets',
  standalone: true,
  imports: [CommonModule, FormsModule, ButtonModule, InputTextModule, TableModule, LoadErrorBannerComponent],
  template: `
    <div class="page">
      <app-load-error-banner [message]="loadError()" (retry)="reload()" />
      <div class="page-header">
        <div>
          <span class="eyebrow"><i class="pi pi-qrcode"></i> Operación en puesto &bull; Backend MariaDB</span>
          <h1>Tickets y control de canjes</h1>
          <p>Validación atómica de vouchers QR con bloqueo de concurrencia e historial auditable por ítem.</p>
        </div>
        <div class="segmented">
          <button [class.active]="tab() === 'redeem'" (click)="tab.set('redeem')">
            <i class="pi pi-qrcode"></i> Validar y canjear
          </button>
          <button [class.active]="tab() === 'list'" (click)="tab.set('list')">
            <i class="pi pi-ticket"></i> Tickets emitidos ({{ tickets().length }})
          </button>
          <button [class.active]="tab() === 'courses'" (click)="tab.set('courses')">
            <i class="pi pi-users"></i> Asignaciones por curso
          </button>
        </div>
      </div>

      <!-- KPIs rápidos de Tickets -->
      <div class="kpi-grid">
        <div class="kpi">
          <div class="kpi-label">Tickets emitidos</div>
          <div class="kpi-value">{{ tickets().length }}</div>
          <div class="kpi-foot">{{ totalItemsCount() }} productos acreditados en total</div>
        </div>
        <div class="kpi">
          <div class="kpi-label">Disponibles para canje</div>
          <div class="kpi-value">{{ activeTicketsCount() }}</div>
          <div class="kpi-foot">Con ítems pendientes de entrega</div>
        </div>
        <div class="kpi">
          <div class="kpi-label">Ítems ya entregados</div>
          <div class="kpi-value">{{ redeemedItemsCount() }} / {{ totalItemsCount() }}</div>
          <div class="kpi-foot">Control de canje único en MariaDB</div>
        </div>
        <div class="kpi">
          <div class="kpi-label">Canjes completados</div>
          <div class="kpi-value">{{ fullyRedeemedCount() }}</div>
          <div class="kpi-foot">Tickets 100% entregados</div>
        </div>
      </div>

      @if (feedbackMessage()) {
        <div class="notice info">
          <i class="pi pi-check-circle"></i>
          <span>{{ feedbackMessage() }}</span>
        </div>
      }

      @if (errorMessage()) {
        <div class="notice danger">
          <i class="pi pi-exclamation-triangle"></i>
          <span>{{ errorMessage() }}</span>
        </div>
      }

      @switch (tab()) {
        @case ('redeem') {
          <div class="grid-2">
            <!-- Panel de lectura QR / código -->
            <section class="scanner-card">
              <div class="scanner-top">
                <h2><i class="pi pi-camera"></i> Escanear QR o ingresar código</h2>
                <span class="scanner-badge">Tiempo real</span>
              </div>

              <div class="scan-box">
                <i class="pi pi-qrcode"></i>
                <b>Lector de código QR / N° de Ticket</b>
                <span>En la app móvil se utiliza la cámara del dispositivo. En el panel web puedes escanear con lector USB o escribir el código.</span>
              </div>

              <div class="search-bar">
                <input
                  pInputText
                  [(ngModel)]="scanInput"
                  placeholder="Ej. TKT-FC569E84, KERMESFERA:... o nombre del comprador"
                  class="w-full"
                  (keyup.enter)="lookupTicket()"
                />
                <p-button label="Buscar" icon="pi pi-search" (onClick)="lookupTicket()" />
              </div>

              <div class="quick-picks">
                <small>Tickets reales en MariaDB (clic para probar):</small>
                <div class="chips">
                  @for (t of tickets().slice(0, 6); track t.id) {
                    <button
                      type="button"
                      class="ticket-chip"
                      [class.selected]="selectedTicket()?.id === t.id"
                      (click)="selectTicketDirectly(t)"
                    >
                      <span class="mono">{{ t.ticket_number }}</span>
                      <span class="chip-status" [class]="t.status">{{ getStatusShort(t.status) }}</span>
                    </button>
                  }
                </div>
              </div>
            </section>

            <!-- Panel de detalle y entrega de ítems -->
            <section class="panel">
              <div class="panel-head">
                <h2><i class="pi pi-check-square"></i> Estado del Ticket y Entrega</h2>
                @if (selectedTicket(); as t) {
                  <span class="mono">ID: {{ t.id | slice:0:8 }}</span>
                }
              </div>
              <div class="panel-body">
                @if (selectedTicket(); as t) {
                  <div class="ticket-header-card">
                    <div>
                      <span class="ticket-num mono">{{ t.ticket_number }}</span>
                      <div class="buyer-info">
                        <b>{{ t.buyer_name || 'Comprador en puesto' }}</b>
                        <small class="muted">{{ t.buyer_email }}</small>
                      </div>
                    </div>
                    <div class="text-right">
                      <span [class]="'pill ' + getStatusPillClass(t.status)">
                        {{ getStatusLabel(t.status) }}
                      </span>
                      <div class="money mt-1">Bs {{ t.total }}</div>
                    </div>
                  </div>

                  <div class="progress-box">
                    <div class="progress-labels">
                      <span>Progreso de entrega</span>
                      <b>{{ t.redeemed_quantity }} de {{ t.total_quantity }} productos entregados</b>
                    </div>
                    <div class="bar">
                      <i [style.width.%]="t.total_quantity ? (t.redeemed_quantity / t.total_quantity) * 100 : 0"></i>
                    </div>
                  </div>

                  @if (t.status === 'pending') {
                    <div class="notice warn mb-3">
                      <i class="pi pi-clock"></i>
                      <span>El pago por QR/Transferencia aún está <b>pendiente de verificación</b>. Debe confirmarse antes de entregar productos.</span>
                    </div>
                  } @else if (t.status === 'cancelled') {
                    <div class="notice danger mb-3">
                      <i class="pi pi-ban"></i>
                      <span>Este ticket fue <b>anulado</b> y no admite canjes.</span>
                    </div>
                  } @else if (t.status === 'redeemed') {
                    <div class="notice info mb-3">
                      <i class="pi pi-verified"></i>
                      <span><b>Canje completado:</b> Todos los productos de este ticket ya fueron entregados.</span>
                    </div>
                  }

                  <h3 class="sub-heading">Desglose de productos autorizados</h3>
                  <div class="ticket-items-list">
                    @for (item of t.items; track item.id) {
                      <div class="t-item" [class.done]="item.remaining_quantity === 0">
                        <div class="t-item-info">
                          <span class="stand-badge">{{ item.stand_name }}</span>
                          <b>{{ item.product_name }}</b>
                          <small class="muted">
                            Entregados: <b>{{ item.redeemed_quantity }}</b> de <b>{{ item.quantity }}</b>
                            ({{ item.remaining_quantity }} disponibles)
                          </small>
                        </div>

                        <div class="t-item-actions">
                          @if (item.remaining_quantity > 0 && t.status !== 'pending' && t.status !== 'cancelled') {
                            <p-button
                              size="small"
                              label="Entregar 1 u."
                              icon="pi pi-check"
                              [loading]="redeeming()"
                              [disabled]="ctx.readOnly() || redeeming()"
                              (onClick)="redeemSingleItem(t, item, 1)"
                            />
                            @if (item.remaining_quantity > 1) {
                              <p-button
                                size="small"
                                [label]="'Todo (' + item.remaining_quantity + ')'"
                                severity="secondary"
                                [outlined]="true"
                                [loading]="redeeming()"
                                [disabled]="ctx.readOnly() || redeeming()"
                                (onClick)="redeemSingleItem(t, item, item.remaining_quantity)"
                              />
                            }
                          } @else if (item.remaining_quantity === 0) {
                            <span class="pill green"><i class="pi pi-check"></i> Entregado</span>
                          }
                        </div>
                      </div>
                    }
                  </div>

                  @if (t.remaining_quantity > 0 && t.status !== 'pending' && t.status !== 'cancelled') {
                    <div class="redeem-all-bar">
                      <p-button
                        [fluid]="true"
                        [label]="'Confirmar entrega total pendiente (' + t.remaining_quantity + ' ítems)'"
                        icon="pi pi-check-circle"
                        [loading]="redeeming()"
                        [disabled]="ctx.readOnly() || redeeming()"
                        (onClick)="redeemAllRemaining(t)"
                      />
                    </div>
                  }

                  @if (t.redemptions && t.redemptions.length > 0) {
                    <h3 class="sub-heading mt-3">Bitácora de entregas registradas</h3>
                    <div class="redemptions-log">
                      @for (r of t.redemptions; track r.id) {
                        <div class="r-row">
                          <i class="pi pi-history"></i>
                          <div>
                            <b>{{ r.quantity }} × {{ r.product_name }}</b>
                            <small class="muted">Entregado por {{ r.redeemed_by_name }} &bull; {{ r.redeemed_at | date:'short' }}</small>
                          </div>
                        </div>
                      }
                    </div>
                  }
                } @else if (searched()) {
                  <div class="notice danger">
                    <i class="pi pi-times-circle"></i>
                    <span>No se encontró ningún ticket que coincida con "<b>{{ scanInput }}</b>" en este evento.</span>
                  </div>
                } @else {
                  <div class="empty-grid-state">
                    <i class="pi pi-qrcode"></i>
                    <p>Selecciona un ticket de prueba o ingresa su código para validar y registrar entregas.</p>
                  </div>
                }
              </div>
            </section>
          </div>
        }

        @case ('list') {
          <section class="panel">
            <div class="panel-head">
              <h2><i class="pi pi-list"></i> Padrón de Tickets Emitidos</h2>
              <p-button
                size="small"
                label="Actualizar"
                icon="pi pi-refresh"
                severity="secondary"
                [outlined]="true"
                (onClick)="loadTickets()"
              />
            </div>

            <!-- Regla de Grid: Buscador Rápido + Espacio para Búsqueda en Filtros -->
            <div class="grid-toolbar">
              <div class="grid-search">
                <i class="pi pi-search search-icon"></i>
                <input
                  type="text"
                  [(ngModel)]="quickSearch"
                  placeholder="Búsqueda rápida por N° ticket, QR, comprador, correo o producto..."
                />
                @if (quickSearch()) {
                  <button type="button" class="clear-btn" (click)="quickSearch.set('')" title="Limpiar búsqueda">
                    <i class="pi pi-times"></i>
                  </button>
                }
              </div>

              <div class="grid-filters">
                <span class="filter-label"><i class="pi pi-filter"></i> Filtros:</span>

                <select class="filter-select" [ngModel]="statusFilter()" (ngModelChange)="statusFilter.set($event)">
                  <option value="all">Estado: Todos</option>
                  <option value="active">Disponibles (sin canje)</option>
                  <option value="partially_redeemed">Canje parcial</option>
                  <option value="redeemed">Canjeados (100%)</option>
                  <option value="pending">Pago pendiente</option>
                </select>

                <select class="filter-select" [ngModel]="methodFilter()" (ngModelChange)="methodFilter.set($event)">
                  <option value="all">Medio de pago: Todos</option>
                  <option value="cash">Efectivo</option>
                  <option value="qr_transfer">QR / Transferencia</option>
                </select>

                <select class="filter-select" [ngModel]="standFilter()" (ngModelChange)="standFilter.set($event)">
                  <option value="all">Puesto: Todos</option>
                  <option value="Gastronomía">Gastronomía</option>
                  <option value="Bebidas">Bebidas</option>
                  <option value="Repostería">Repostería</option>
                  <option value="Juegos">Juegos</option>
                </select>

                @if (hasActiveFilters()) {
                  <button type="button" class="filter-reset" (click)="resetFilters()">
                    <i class="pi pi-filter-slash"></i> Limpiar filtros
                  </button>
                }
              </div>

              <span class="grid-meta">{{ filteredTickets().length }} de {{ tickets().length }} tickets</span>
            </div>

            <p-table
              [value]="filteredTickets()"
              [paginator]="true"
              [rows]="5"
              [rowsPerPageOptions]="[5, 10, 20, 50]"
              [showCurrentPageReport]="true"
              currentPageReportTemplate="Mostrando {first} a {last} de {totalRecords} tickets"
              [scrollable]="true"
            >
              <ng-template #header>
                <tr>
                  <th>N° Ticket / QR</th>
                  <th>Comprador</th>
                  <th>Productos acreditados</th>
                  <th>Medio</th>
                  <th>Total</th>
                  <th>Progreso de canje</th>
                  <th>Estado</th>
                  <th>Acción</th>
                </tr>
              </ng-template>
              <ng-template #body let-t>
                <tr>
                  <td>
                    <b class="mono text-primary">{{ t.ticket_number }}</b>
                    <small class="block muted">{{ t.issued_at | date:'short' }}</small>
                  </td>
                  <td>
                    <b>{{ t.buyer_name || 'Venta rápida' }}</b>
                    <small class="block muted">{{ t.buyer_email }}</small>
                  </td>
                  <td>{{ t.items_summary }}</td>
                  <td>
                    <span class="pill gray">
                      {{ t.payment_method === 'cash' ? 'Efectivo' : 'QR Banco' }}
                    </span>
                  </td>
                  <td class="money">Bs {{ t.total }}</td>
                  <td style="min-width: 140px;">
                    <small>{{ t.redeemed_quantity }} / {{ t.total_quantity }} entregados</small>
                    <div class="bar">
                      <i [style.width.%]="t.total_quantity ? (t.redeemed_quantity / t.total_quantity) * 100 : 0"></i>
                    </div>
                  </td>
                  <td>
                    <span [class]="'pill ' + getStatusPillClass(t.status)">
                      {{ getStatusLabel(t.status) }}
                    </span>
                  </td>
                  <td>
                    <p-button
                      size="small"
                      [label]="t.status === 'redeemed' ? 'Ver detalle' : 'Canjear'"
                      [icon]="t.status === 'redeemed' ? 'pi pi-eye' : 'pi pi-qrcode'"
                      [outlined]="t.status === 'redeemed'"
                      (onClick)="openInScanner(t)"
                    />
                  </td>
                </tr>
              </ng-template>
              <ng-template #emptymessage>
                <tr>
                  <td colspan="8" class="empty-grid-state">
                    <i class="pi pi-inbox"></i>
                    <p>No se encontraron tickets con los criterios de búsqueda o filtros seleccionados.</p>
                  </td>
                </tr>
              </ng-template>
            </p-table>
          </section>
        }

        @case ('courses') {
          <div class="notice warn">
            <i class="pi pi-info-circle"></i>
            <span>Las asignaciones por curso <b>no son ventas ni ingresos</b> y se controlan por separado como talonarios entregados a las familias.</span>
          </div>

          <section class="panel">
            <div class="panel-head">
              <h2><i class="pi pi-users"></i> Control de Asignaciones por Curso</h2>
            </div>

            <!-- Regla de Grid: Buscador Rápido + Espacio para Búsqueda en Filtros -->
            <div class="grid-toolbar">
              <div class="grid-search">
                <i class="pi pi-search search-icon"></i>
                <input
                  type="text"
                  [(ngModel)]="courseSearch"
                  placeholder="Búsqueda rápida por nombre de curso..."
                />
                @if (courseSearch()) {
                  <button type="button" class="clear-btn" (click)="courseSearch.set('')">
                    <i class="pi pi-times"></i>
                  </button>
                }
              </div>

              <div class="grid-filters">
                <span class="filter-label"><i class="pi pi-filter"></i> Filtros:</span>
                <select class="filter-select" [ngModel]="courseLevelFilter()" (ngModelChange)="courseLevelFilter.set($event)">
                  <option value="all">Nivel: Todos</option>
                  <option value="Primaria">Primaria</option>
                  <option value="Secundaria">Secundaria</option>
                </select>
                <select class="filter-select" [ngModel]="courseStatusFilter()" (ngModelChange)="courseStatusFilter.set($event)">
                  <option value="all">Avance: Todos</option>
                  <option value="complete">Meta alcanzada (>= 90%)</option>
                  <option value="pending">En progreso (< 90%)</option>
                </select>
              </div>

              <span class="grid-meta">{{ filteredCourses().length }} cursos</span>
            </div>

            <p-table
              [value]="filteredCourses()"
              [paginator]="true"
              [rows]="5"
              [rowsPerPageOptions]="[5, 10, 20]"
              [showCurrentPageReport]="true"
              currentPageReportTemplate="Mostrando {first} a {last} de {totalRecords} cursos"
            >
              <ng-template #header>
                <tr>
                  <th>Curso / Delegado</th>
                  <th>Producto y Puesto</th>
                  <th>Asignados</th>
                  <th>Entregados</th>
                  <th>Devueltos</th>
                  <th>Avance</th>
                  <th>Estado</th>
                  <th style="text-align: right">Acciones</th>
                </tr>
              </ng-template>
              <ng-template #body let-c>
                <tr>
                  <td>
                    <b>{{ c.course }}</b>
                    @if (c.delegate) {
                      <small class="block muted">{{ c.delegate }}</small>
                    }
                  </td>
                  <td>
                    <b>{{ c.product || 'Talonario general' }}</b>
                    @if (c.stand) {
                      <small class="block muted">{{ c.stand }}</small>
                    }
                  </td>
                  <td class="mono">{{ c.assigned || c.tickets }}</td>
                  <td class="mono"><b>{{ c.delivered }}</b></td>
                  <td class="mono">{{ c.returned || 0 }}</td>
                  <td style="min-width: 165px;">
                    <small>{{ (((c.delivered + (c.returned || 0)) / (c.assigned || c.tickets)) * 100) | number:'1.0-0' }}% conciliado</small>
                    <div class="bar">
                      <i [style.width.%]="((c.delivered + (c.returned || 0)) / (c.assigned || c.tickets)) * 100"></i>
                    </div>
                  </td>
                  <td>
                    <span class="pill" [class.green]="(c.delivered + (c.returned || 0)) >= (c.assigned || c.tickets)" [class.yellow]="(c.delivered + (c.returned || 0)) < (c.assigned || c.tickets)">
                      {{ (c.delivered + (c.returned || 0)) >= (c.assigned || c.tickets) ? 'Conciliado' : (c.remaining ?? ((c.assigned || c.tickets) - c.delivered)) + ' pend.' }}
                    </span>
                  </td>
                  <td style="text-align: right">
                    @if (c.id && (c.remaining ?? 0) > 0) {
                      <div class="t-item-actions" style="justify-content: flex-end">
                        <p-button
                          size="small"
                          label="+1 ent."
                          icon="pi pi-check"
                          [disabled]="ctx.readOnly() || redeeming()"
                          (onClick)="recordCourseAllocation(c.id, 'deliver', 1)"
                        />
                        <p-button
                          size="small"
                          label="+1 dev."
                          severity="secondary"
                          [outlined]="true"
                          [disabled]="ctx.readOnly() || redeeming()"
                          (onClick)="recordCourseAllocation(c.id, 'return', 1)"
                        />
                      </div>
                    } @else {
                      <span class="muted">—</span>
                    }
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
    .scanner-card {
      background: linear-gradient(145deg, #063b73 0%, #04284f 100%);
      color: #ffffff;
      border-radius: 16px;
      padding: 1.5rem;
      display: flex;
      flex-direction: column;
      gap: 1rem;
      box-shadow: var(--kf-shadow-md);
    }
    .scanner-top {
      display: flex;
      justify-content: space-between;
      align-items: center;
      h2 { margin: 0; font-size: 1.08rem; display: flex; align-items: center; gap: 0.5rem; }
    }
    .scanner-badge {
      background: rgba(255, 196, 0, 0.2);
      color: #ffc400;
      border: 1px solid rgba(255, 196, 0, 0.4);
      padding: 0.2rem 0.6rem;
      border-radius: 999px;
      font-size: 0.72rem;
      font-weight: 700;
    }
    .scan-box {
      border: 2px dashed rgba(255, 196, 0, 0.65);
      background: rgba(255, 255, 255, 0.04);
      border-radius: 14px;
      padding: 1.5rem 1.2rem;
      display: flex;
      flex-direction: column;
      align-items: center;
      text-align: center;
      gap: 0.4rem;
      color: #ffe9a3;
      font-size: 0.84rem;
      i { font-size: 2.6rem; color: #ffc400; margin-bottom: 0.25rem; }
      b { color: #ffffff; font-size: 0.95rem; }
    }
    .search-bar {
      display: flex;
      gap: 0.5rem;
    }
    .quick-picks {
      display: flex;
      flex-direction: column;
      gap: 0.45rem;
      small { color: #cbd5e1; font-size: 0.76rem; font-weight: 600; }
    }
    .chips {
      display: flex;
      flex-wrap: wrap;
      gap: 0.4rem;
    }
    .ticket-chip {
      border: 1px solid rgba(255, 255, 255, 0.2);
      background: rgba(255, 255, 255, 0.08);
      color: #ffffff;
      border-radius: 8px;
      padding: 0.35rem 0.65rem;
      font-size: 0.76rem;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 0.4rem;
      transition: all 0.15s ease;
      &:hover, &.selected {
        background: #ffc400;
        color: #063b73;
        border-color: #ffc400;
        font-weight: 700;
      }
    }
    .chip-status {
      font-size: 0.66rem;
      padding: 0.1rem 0.35rem;
      border-radius: 4px;
      background: rgba(0, 0, 0, 0.25);
    }
    .ticket-header-card {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      padding: 1rem;
      background: #f8fafc;
      border: 1px solid var(--kf-line);
      border-radius: 12px;
      margin-bottom: 1rem;
    }
    .ticket-num {
      font-size: 1.2rem;
      font-weight: 800;
      color: var(--kf-primary);
      display: block;
    }
    .buyer-info {
      display: flex;
      flex-direction: column;
      margin-top: 0.25rem;
      font-size: 0.88rem;
    }
    .text-right { text-align: right; }
    .mt-1 { margin-top: 0.35rem; }
    .mt-3 { margin-top: 1.25rem; }
    .mb-3 { margin-bottom: 1rem; }
    .block { display: block; }
    .text-primary { color: var(--kf-primary); }
    .progress-box {
      margin-bottom: 1.15rem;
      .progress-labels {
        display: flex;
        justify-content: space-between;
        font-size: 0.82rem;
        margin-bottom: 0.25rem;
      }
    }
    .sub-heading {
      font-size: 0.82rem;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: var(--kf-muted);
      margin: 0 0 0.65rem;
      font-weight: 700;
    }
    .ticket-items-list {
      display: flex;
      flex-direction: column;
      gap: 0.6rem;
    }
    .t-item {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 0.75rem;
      padding: 0.75rem 1rem;
      border: 1px solid var(--kf-line);
      border-radius: 12px;
      background: #ffffff;
      flex-wrap: wrap;
      &.done {
        background: #f8fafc;
        opacity: 0.85;
      }
    }
    .t-item-info {
      display: flex;
      flex-direction: column;
      gap: 0.15rem;
      b { font-size: 0.92rem; color: var(--kf-ink); }
    }
    .stand-badge {
      font-size: 0.68rem;
      font-weight: 800;
      text-transform: uppercase;
      color: var(--kf-cyan);
    }
    .t-item-actions {
      display: flex;
      gap: 0.45rem;
      align-items: center;
    }
    .redeem-all-bar {
      margin-top: 1.1rem;
      padding-top: 1rem;
      border-top: 1px dashed var(--kf-line);
    }
    .redemptions-log {
      display: flex;
      flex-direction: column;
      gap: 0.5rem;
      background: #f8fafc;
      padding: 0.85rem;
      border-radius: 10px;
      border: 1px solid var(--kf-line);
    }
    .r-row {
      display: flex;
      align-items: center;
      gap: 0.65rem;
      font-size: 0.82rem;
      i { color: var(--kf-primary); }
      div { display: flex; flex-direction: column; }
    }
  `],
})
export class TicketsComponent {
  private readonly http = inject(HttpClient);
  protected readonly ctx = inject(EventContextService);

  protected readonly tab = signal<Tab>('redeem');
  protected readonly tickets = signal<ApiTicket[]>([]);
  protected readonly selectedTicketId = signal<string | null>(null);
  protected readonly searched = signal(false);
  protected readonly redeeming = signal(false);
  protected readonly feedbackMessage = signal<string | null>(null);
  protected readonly errorMessage = signal<string | null>(null);

  protected scanInput = '';

  // Filtros y búsqueda rápida para Grid de Tickets
  protected readonly quickSearch = signal('');
  protected readonly statusFilter = signal('all');
  protected readonly methodFilter = signal('all');
  protected readonly standFilter = signal('all');

  // Filtros y búsqueda rápida para Grid de Cursos
  protected readonly courses = signal<any[]>([]);
  private readonly ticketsError = signal<string | null>(null);
  private readonly coursesError = signal<string | null>(null);
  protected readonly loadError = computed(() => this.ticketsError() ?? this.coursesError());
  protected readonly courseSearch = signal('');
  protected readonly courseLevelFilter = signal('all');
  protected readonly courseStatusFilter = signal('all');

  protected readonly selectedTicket = computed(() => {
    const id = this.selectedTicketId();
    if (!id) return null;
    return this.tickets().find((t) => t.id === id) ?? null;
  });

  protected readonly totalItemsCount = computed(() =>
    this.tickets().reduce((sum, t) => sum + t.total_quantity, 0)
  );
  protected readonly redeemedItemsCount = computed(() =>
    this.tickets().reduce((sum, t) => sum + t.redeemed_quantity, 0)
  );
  protected readonly activeTicketsCount = computed(() =>
    this.tickets().filter((t) => t.status === 'active' || t.status === 'partially_redeemed').length
  );
  protected readonly fullyRedeemedCount = computed(() =>
    this.tickets().filter((t) => t.status === 'redeemed').length
  );

  protected readonly hasActiveFilters = computed(() =>
    this.quickSearch().trim() !== '' ||
    this.statusFilter() !== 'all' ||
    this.methodFilter() !== 'all' ||
    this.standFilter() !== 'all'
  );

  protected readonly filteredTickets = computed(() => {
    const q = this.quickSearch().trim().toLowerCase();
    const status = this.statusFilter();
    const method = this.methodFilter();
    const stand = this.standFilter();

    return this.tickets().filter((t) => {
      if (status !== 'all' && t.status !== status) return false;
      if (method !== 'all' && t.payment_method !== method) return false;
      if (stand !== 'all' && !t.items.some((i) => i.stand_name === stand)) return false;

      if (q) {
        const matchNumber = t.ticket_number.toLowerCase().includes(q);
        const matchQr = t.qr_code.toLowerCase().includes(q);
        const matchBuyer = (t.buyer_name || '').toLowerCase().includes(q);
        const matchEmail = t.buyer_email.toLowerCase().includes(q);
        const matchItems = t.items_summary.toLowerCase().includes(q);
        if (!matchNumber && !matchQr && !matchBuyer && !matchEmail && !matchItems) {
          return false;
        }
      }
      return true;
    });
  });

  protected readonly filteredCourses = computed(() => {
    const q = this.courseSearch().trim().toLowerCase();
    const level = this.courseLevelFilter();
    const status = this.courseStatusFilter();

    return this.courses().filter((c) => {
      const hay = `${c.course} ${c.delegate || ''} ${c.product || ''} ${c.stand || ''}`.toLowerCase();
      if (q && !hay.includes(q)) return false;
      if (level !== 'all' && !c.course.includes(level)) return false;
      const total = c.assigned || c.tickets || 1;
      const ratio = (c.delivered + (c.returned || 0)) / total;
      if (status === 'complete' && ratio < 0.9) return false;
      if (status === 'pending' && ratio >= 0.9) return false;
      return true;
    });
  });

  constructor() {
    effect(() => {
      const ev = this.ctx.active();
      if (ev?.id) {
        this.loadTickets();
        this.loadCourseAllocations();
      }
    });
  }

  reload(): void {
    this.loadTickets();
    this.loadCourseAllocations();
  }

  async loadCourseAllocations(): Promise<void> {
    const orgId = this.ctx.orgId;
    const eventId = this.ctx.requireActive().id;
    this.coursesError.set(null);
    try {
      const res = await firstValueFrom(
        this.http.get<{ data: any[] }>(`/organizations/${orgId}/events/${eventId}/course-allocations`)
      );
      this.courses.set(res?.data ?? []);
    } catch (err) {
      this.courses.set([]);
      this.coursesError.set(describeLoadError(err, 'No se pudieron cargar las asignaciones por curso.'));
    }
  }

  async recordCourseAllocation(allocationId: string, action: 'deliver' | 'return', quantity: number): Promise<void> {
    this.redeeming.set(true);
    this.feedbackMessage.set(null);
    this.errorMessage.set(null);

    const orgId = this.ctx.orgId;
    const eventId = this.ctx.requireActive().id;

    try {
      await firstValueFrom(
        this.http.post(
          `/organizations/${orgId}/events/${eventId}/course-allocations/${allocationId}/record`,
          { action, quantity },
          {
            headers: {
              'Idempotency-Key': `crs-${allocationId}-${action}-${Date.now()}`,
            },
          }
        )
      );
      this.feedbackMessage.set(
        action === 'deliver'
          ? 'Se registró +1 unidad entregada en la asignación del curso en MariaDB.'
          : 'Se registró +1 unidad devuelta en la asignación del curso en MariaDB.'
      );
      await this.loadCourseAllocations();
    } catch (err: unknown) {
      const anyErr = err as { message?: string; originalError?: { error?: { message?: string } } };
      this.errorMessage.set(
        anyErr?.originalError?.error?.message || anyErr?.message || 'Error al registrar movimiento del curso.'
      );
    } finally {
      this.redeeming.set(false);
    }
  }

  async loadTickets(): Promise<void> {
    const orgId = this.ctx.orgId;
    const eventId = this.ctx.requireActive().id;
    this.ticketsError.set(null);
    try {
      const res = await firstValueFrom(
        this.http.get<{ data: ApiTicket[] }>(`/organizations/${orgId}/events/${eventId}/tickets`)
      );
      const list = res?.data ?? [];
      this.tickets.set(list);
      if (!list.some((t) => t.id === this.selectedTicketId())) {
        this.selectedTicketId.set(list[0]?.id ?? null);
      }
    } catch (err) {
      this.tickets.set([]);
      this.selectedTicketId.set(null);
      this.ticketsError.set(describeLoadError(err, 'No se pudieron cargar los tickets del evento.'));
    }
  }

  lookupTicket(): void {
    this.searched.set(true);
    this.feedbackMessage.set(null);
    this.errorMessage.set(null);

    const term = this.scanInput.trim().toLowerCase();
    if (!term) return;

    const match = this.tickets().find(
      (t) =>
        t.ticket_number.toLowerCase() === term ||
        t.qr_code.toLowerCase() === term ||
        t.ticket_number.toLowerCase().includes(term) ||
        (t.buyer_name && t.buyer_name.toLowerCase().includes(term)) ||
        t.buyer_email.toLowerCase().includes(term)
    );

    this.selectedTicketId.set(match ? match.id : null);
  }

  selectTicketDirectly(t: ApiTicket): void {
    this.scanInput = t.ticket_number;
    this.searched.set(true);
    this.feedbackMessage.set(null);
    this.errorMessage.set(null);
    this.selectedTicketId.set(t.id);
  }

  openInScanner(t: ApiTicket): void {
    this.selectTicketDirectly(t);
    this.tab.set('redeem');
  }

  async redeemSingleItem(ticket: ApiTicket, item: ApiTicketItem, quantity: number): Promise<void> {
    await this.executeRedemption(ticket.id, [{ ticket_item_id: item.id, quantity }]);
  }

  async redeemAllRemaining(ticket: ApiTicket): Promise<void> {
    const pendingItems = ticket.items
      .filter((i) => i.remaining_quantity > 0)
      .map((i) => ({
        ticket_item_id: i.id,
        quantity: i.remaining_quantity,
      }));

    if (pendingItems.length === 0) return;
    await this.executeRedemption(ticket.id, pendingItems);
  }

  private async executeRedemption(
    ticketId: string,
    items: Array<{ ticket_item_id: string; quantity: number }>
  ): Promise<void> {
    this.redeeming.set(true);
    this.feedbackMessage.set(null);
    this.errorMessage.set(null);

    const orgId = this.ctx.orgId;
    const eventId = this.ctx.requireActive().id;
    const idempotencyKey = `redeem-${Date.now()}-${Math.random().toString(36).substring(2, 8)}`;

    try {
      const res = await firstValueFrom(
        this.http.post<{
          data: {
            ticket_number: string;
            status: string;
            redeemed_quantity: number;
            total_quantity: number;
          };
        }>(
          `/organizations/${orgId}/events/${eventId}/tickets/${ticketId}/redemptions`,
          { items },
          { headers: { 'Idempotency-Key': idempotencyKey } }
        )
      );

      if (res?.data) {
        this.feedbackMessage.set(
          `Canje registrado en MariaDB para ${res.data.ticket_number} (${res.data.redeemed_quantity}/${res.data.total_quantity} entregados).`
        );
        await this.loadTickets();
      }
    } catch (err: unknown) {
      const anyErr = err as { message?: string; originalError?: { error?: { message?: string } } };
      const msg =
        anyErr?.originalError?.error?.message ||
        anyErr?.message ||
        'No se pudo procesar el canje del ticket.';
      this.errorMessage.set(msg);
    } finally {
      this.redeeming.set(false);
    }
  }

  resetFilters(): void {
    this.quickSearch.set('');
    this.statusFilter.set('all');
    this.methodFilter.set('all');
    this.standFilter.set('all');
  }

  getStatusLabel(status: string): string {
    const labels: Record<string, string> = {
      active: 'Disponible',
      partially_redeemed: 'Canje parcial',
      redeemed: 'Canjeado total',
      pending: 'Pago por verificar',
      cancelled: 'Anulado',
    };
    return labels[status] || status;
  }

  getStatusShort(status: string): string {
    const labels: Record<string, string> = {
      active: 'Activo',
      partially_redeemed: 'Parcial',
      redeemed: 'Canjeado',
      pending: 'Pendiente',
      cancelled: 'Anulado',
    };
    return labels[status] || status;
  }

  getStatusPillClass(status: string): string {
    const map: Record<string, string> = {
      active: 'green',
      partially_redeemed: 'cyan',
      redeemed: 'blue',
      pending: 'yellow',
      cancelled: 'red',
    };
    return map[status] || 'gray';
  }
}
