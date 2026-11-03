import { Component, computed, inject, signal, effect } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HttpClient } from '@angular/common/http';
import { firstValueFrom } from 'rxjs';
import { ButtonModule } from 'primeng/button';
import { TableModule } from 'primeng/table';
import { DialogModule } from 'primeng/dialog';
import { InputTextModule } from 'primeng/inputtext';
import { InputNumberModule } from 'primeng/inputnumber';
import { ToggleSwitchModule } from 'primeng/toggleswitch';
import { EventContextService } from '../../core/services/event-context.service';
import { Stand } from '../../core/models/domain';
import { describeLoadError } from '../../core/services/load-error';
import { LoadErrorBannerComponent } from '../../core/ui/load-error-banner.component';

interface ApiCatalogProduct {
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
  status: string;
  active: boolean;
  version: number;
}

@Component({
  selector: 'app-catalog',
  standalone: true,
  imports: [
    CommonModule,
    FormsModule,
    ButtonModule,
    TableModule,
    DialogModule,
    InputTextModule,
    InputNumberModule,
    ToggleSwitchModule,
    LoadErrorBannerComponent,
  ],
  template: `
    <div class="page">
      <app-load-error-banner [message]="loadError()" (retry)="reload()" />
      <div class="page-header">
        <div>
          <span class="eyebrow"><i class="pi pi-shop"></i> Configuración del evento &bull; Backend MariaDB</span>
          <h1>Puestos y catálogo de productos</h1>
          <p>Precios oficiales en unidades menores y disponibilidad en tiempo real sincronizados con la API Symfony.</p>
        </div>
        <div class="page-actions">
          <p-button label="Actualizar" icon="pi pi-refresh" severity="secondary" [outlined]="true" (onClick)="reload()" />
          <p-button label="Nuevo puesto" icon="pi pi-shop" severity="secondary" [outlined]="true" (onClick)="openStandDialog()" [disabled]="ctx.readOnly()" />
          <p-button label="Nuevo producto" icon="pi pi-plus" (onClick)="openNewDialog()" [disabled]="ctx.readOnly()" />
        </div>
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

      <!-- Tarjetas selectoras de Puesto -->
      <div class="stands">
        <button class="stand" [class.active]="standFilter() === 'all'" (click)="standFilter.set('all')">
          <div class="stand-top">
            <i class="pi pi-th-large"></i>
            <span class="pill blue">{{ products().length }} ítems</span>
          </div>
          <b>Todos los puestos</b>
          <small>Catálogo general del evento</small>
        </button>
        @for (s of stands(); track s.id) {
          <button class="stand" [class.active]="standFilter() === s.name" (click)="standFilter.set(s.name)">
            <div class="stand-top">
              <i [class]="'pi ' + s.icon"></i>
              <span class="pill gray">{{ countByStand(s.name) }} ítems</span>
            </div>
            <b>{{ s.name }}</b>
            <small>Resp.: {{ s.manager }}</small>
          </button>
        }
      </div>

      <section class="panel">
        <div class="panel-head">
          <h2><i class="pi pi-box"></i> Inventario y Precios de Productos</h2>
          <span>Moneda oficial: BOB</span>
        </div>

        <!-- Regla de Grid: Buscador Rápido + Espacio para Búsqueda en Filtros -->
        <div class="grid-toolbar">
          <div class="grid-search">
            <i class="pi pi-search search-icon"></i>
            <input
              type="text"
              [(ngModel)]="quickSearch"
              placeholder="Búsqueda rápida por producto, código SKU o puesto..."
            />
            @if (quickSearch()) {
              <button type="button" class="clear-btn" (click)="quickSearch.set('')" title="Limpiar búsqueda">
                <i class="pi pi-times"></i>
              </button>
            }
          </div>

          <div class="grid-filters">
            <span class="filter-label"><i class="pi pi-filter"></i> Filtros:</span>

            <select class="filter-select" [ngModel]="standFilter()" (ngModelChange)="standFilter.set($event)">
              <option value="all">Puesto: Todos</option>
              @for (s of stands(); track s.id) {
                <option [value]="s.name">{{ s.name }}</option>
              }
            </select>

            <select class="filter-select" [ngModel]="stockFilter()" (ngModelChange)="stockFilter.set($event)">
              <option value="all">Disponibilidad: Todas</option>
              <option value="available">Con stock disponible</option>
              <option value="low">Stock crítico (<= 20%)</option>
              <option value="out">Agotados (0 disp.)</option>
            </select>

            <select class="filter-select" [ngModel]="statusFilter()" (ngModelChange)="statusFilter.set($event)">
              <option value="all">Estado: Todos</option>
              <option value="active">Activos para venta</option>
              <option value="paused">Pausados</option>
            </select>

            @if (hasActiveFilters()) {
              <button type="button" class="filter-reset" (click)="resetFilters()">
                <i class="pi pi-filter-slash"></i> Limpiar filtros
              </button>
            }
          </div>

          <span class="grid-meta">{{ filtered().length }} de {{ products().length }} productos</span>
        </div>

        <p-table
          [value]="filtered()"
          [paginator]="true"
          [rows]="5"
          [rowsPerPageOptions]="[5, 10, 20, 50]"
          [showCurrentPageReport]="true"
          currentPageReportTemplate="Mostrando {first} a {last} de {totalRecords} productos"
          [scrollable]="true"
        >
          <ng-template #header>
            <tr>
              <th>Código SKU</th>
              <th>Producto</th>
              <th>Puesto</th>
              <th>Precio Unitario</th>
              <th>Vendidos / Stock</th>
              <th>Disponibilidad</th>
              <th>Activo</th>
              <th>Acciones</th>
            </tr>
          </ng-template>
          <ng-template #body let-p>
            <tr>
              <td>
                <span class="pill gray mono">{{ p.code || (p.id | slice:0:8) }}</span>
              </td>
              <td>
                <div class="prod-cell">
                  <span class="emoji">{{ p.emoji }}</span>
                  <div>
                    <b>{{ p.name }}</b>
                    <small class="block muted">Versión v{{ p.version || 1 }}</small>
                  </div>
                </div>
              </td>
              <td>
                <span class="pill blue">{{ p.standName || standName(p.standId) }}</span>
              </td>
              <td class="money">Bs {{ p.price | number:'1.2-2' }}</td>
              <td>
                <b>{{ p.sold }}</b> / {{ p.stock }} u.
              </td>
              <td class="stock">
                <div class="stock-top">
                  <small [class.danger-text]="p.stock - p.sold <= 0">
                    {{ p.stock - p.sold <= 0 ? 'Agotado' : (p.stock - p.sold) + ' disponibles' }}
                  </small>
                  <small class="muted">{{ p.stock ? (((p.stock - p.sold) / p.stock) * 100 | number:'1.0-0') : 0 }}%</small>
                </div>
                <div class="bar">
                  <i
                    [style.width.%]="p.stock ? ((p.stock - p.sold) / p.stock) * 100 : 0"
                    [class.out]="p.sold >= p.stock"
                  ></i>
                </div>
              </td>
              <td>
                <p-toggleswitch
                  [ngModel]="p.active"
                  (ngModelChange)="toggleActive(p, $event)"
                  [disabled]="ctx.readOnly() || saving()"
                />
              </td>
              <td>
                <p-button
                  icon="pi pi-pencil"
                  [text]="true"
                  [rounded]="true"
                  [disabled]="ctx.readOnly()"
                  (onClick)="openEditDialog(p)"
                  aria-label="Editar"
                />
              </td>
            </tr>
          </ng-template>
          <ng-template #emptymessage>
            <tr>
              <td colspan="8" class="empty-grid-state">
                <i class="pi pi-box"></i>
                <p>No se encontraron productos que coincidan con la búsqueda o filtros seleccionados.</p>
              </td>
            </tr>
          </ng-template>
        </p-table>
      </section>

      <div class="notice info">
        <i class="pi pi-database"></i>
        <span>Los precios e inventario provienen de MariaDB vía <code>/api/v1/.../catalog/products</code>. Cada cambio incrementa la versión del catálogo y genera auditoría.</span>
      </div>
    </div>

    <p-dialog
      [header]="editingProductId() ? 'Editar producto del catálogo' : 'Nuevo producto de catálogo'"
      [visible]="dialog()"
      (visibleChange)="dialog.set($event)"
      [modal]="true"
      [style]="{ width: '450px' }"
      [draggable]="false"
    >
      <div class="form-row">
        <label>Nombre del producto</label>
        <input pInputText class="w-full" [(ngModel)]="formName" placeholder="Ej. Sándwich de chola" />
      </div>
      @if (!editingProductId()) {
        <div class="form-row">
          <label>Código SKU</label>
          <input pInputText class="w-full mono" [(ngModel)]="formCode" placeholder="Ej. COM-09" />
        </div>
        <div class="form-row">
          <label>Puesto asignado</label>
          <select class="native" [(ngModel)]="formStandName">
            @for (s of stands(); track s.id) {
              <option [value]="s.name">{{ s.name }}</option>
            }
          </select>
        </div>
      }
      <div class="form-row">
        <label>Precio unitario (Bs)</label>
        <p-inputnumber [(ngModel)]="formPrice" [min]="1" mode="decimal" [minFractionDigits]="2" styleClass="w-full" />
      </div>
      <div class="form-row">
        <label>Stock total</label>
        <p-inputnumber [(ngModel)]="formStock" [min]="1" styleClass="w-full" />
      </div>
      <ng-template #footer>
        <p-button label="Cancelar" severity="secondary" [text]="true" (onClick)="dialog.set(false)" />
        <p-button
          [label]="editingProductId() ? 'Actualizar cambios' : 'Crear en MariaDB'"
          icon="pi pi-check"
          [loading]="saving()"
          (onClick)="saveProduct()"
        />
      </ng-template>
    </p-dialog>

    <p-dialog
      header="Nuevo puesto del evento"
      [visible]="standDialog()"
      (visibleChange)="standDialog.set($event)"
      [modal]="true"
      [style]="{ width: '430px' }"
      [draggable]="false"
    >
      <div class="form-row">
        <label>Nombre del puesto</label>
        <input pInputText class="w-full" [(ngModel)]="newStandName" placeholder="Ej. Cafetería y Api" />
      </div>
      <div class="form-row">
        <label>Responsable / Delegado</label>
        <input pInputText class="w-full" [(ngModel)]="newStandManager" placeholder="Ej. Comité 2.º Secundaria" />
      </div>
      <ng-template #footer>
        <p-button label="Cancelar" severity="secondary" [text]="true" (onClick)="standDialog.set(false)" />
        <p-button label="Crear puesto" icon="pi pi-check" [loading]="saving()" (onClick)="saveStand()" />
      </ng-template>
    </p-dialog>
  `,
  styles: [`
    .stands {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(195px, 1fr));
      gap: 0.85rem;
    }
    .stand {
      text-align: left;
      background: #ffffff;
      border: 1px solid var(--kf-line);
      border-radius: 14px;
      padding: 1rem;
      cursor: pointer;
      display: flex;
      flex-direction: column;
      gap: 0.25rem;
      font: inherit;
      color: var(--kf-ink);
      box-shadow: var(--kf-shadow-sm);
      transition: all 0.15s ease;
      &:hover {
        border-color: var(--kf-cyan);
        transform: translateY(-2px);
      }
      &.active {
        border-color: var(--kf-primary);
        background: var(--kf-primary-soft);
        box-shadow: 0 0 0 2px rgba(6, 59, 115, 0.12);
      }
    }
    .stand-top {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 0.25rem;
      i { color: var(--kf-primary); font-size: 1.2rem; }
    }
    .stand small { color: var(--kf-muted); font-size: 0.76rem; }
    .prod-cell {
      display: flex;
      align-items: center;
      gap: 0.65rem;
    }
    .emoji { font-size: 1.45rem; }
    .block { display: block; font-size: 0.72rem; }
    .stock { min-width: 175px; }
    .stock-top {
      display: flex;
      justify-content: space-between;
      font-size: 0.78rem;
    }
    .danger-text { color: var(--kf-red); font-weight: 700; }
    .bar i.out { background: var(--kf-red); }
    .native { padding: 0.6rem; border: 1px solid var(--kf-line); border-radius: 8px; font: inherit; }
  `],
})
export class CatalogComponent {
  private readonly http = inject(HttpClient);
  protected readonly ctx = inject(EventContextService);

  protected readonly stands = signal<Stand[]>([]);
  protected readonly products = signal<any[]>([]);
  protected readonly loadError = signal<string | null>(null);
  protected readonly dialog = signal(false);
  protected readonly standDialog = signal(false);
  protected readonly saving = signal(false);
  protected readonly editingProductId = signal<string | null>(null);
  protected readonly feedbackMessage = signal<string | null>(null);
  protected readonly errorMessage = signal<string | null>(null);

  // Campos del formulario modal
  protected formName = '';
  protected formCode = '';
  protected formStandName = '';
  protected formPrice = 25;
  protected formStock = 80;

  protected newStandName = '';
  protected newStandManager = '';

  // Estado de Búsqueda Rápida y Filtros del Grid
  protected readonly quickSearch = signal('');
  protected readonly standFilter = signal<string>('all');
  protected readonly stockFilter = signal<string>('all');
  protected readonly statusFilter = signal<string>('all');

  protected readonly hasActiveFilters = computed(
    () =>
      this.quickSearch().trim() !== '' ||
      this.standFilter() !== 'all' ||
      this.stockFilter() !== 'all' ||
      this.statusFilter() !== 'all'
  );

  protected readonly filtered = computed(() => {
    const q = this.quickSearch().trim().toLowerCase();
    const stand = this.standFilter();
    const stock = this.stockFilter();
    const status = this.statusFilter();

    return this.products().filter((p) => {
      const pStandName = p.standName || this.standName(p.standId);
      if (stand !== 'all' && pStandName !== stand && p.standId !== stand) {
        return false;
      }

      const avail = p.stock - p.sold;
      const ratio = p.stock > 0 ? avail / p.stock : 0;
      if (stock === 'available' && avail <= 0) return false;
      if (stock === 'low' && (avail <= 0 || ratio > 0.2)) return false;
      if (stock === 'out' && avail > 0) return false;

      if (status === 'active' && !p.active) return false;
      if (status === 'paused' && p.active) return false;

      if (q) {
        const matchName = (p.name || '').toLowerCase().includes(q);
        const matchCode = (p.code || '').toLowerCase().includes(q);
        const matchStand = pStandName.toLowerCase().includes(q);
        if (!matchName && !matchCode && !matchStand) return false;
      }

      return true;
    });
  });

  constructor() {
    effect(() => {
      const activeEvent = this.ctx.active();
      if (activeEvent?.id) {
        this.loadCatalogFromApi(activeEvent.id);
      }
    });
  }

  reload(): void {
    const activeEvent = this.ctx.active();
    if (activeEvent?.id) {
      this.loadCatalogFromApi(activeEvent.id);
    }
  }

  openNewDialog(): void {
    this.editingProductId.set(null);
    this.formName = '';
    this.formCode = `PRD-${Math.floor(10 + Math.random() * 89)}`;
    this.formStandName = this.stands()[0]?.name ?? '';
    this.formPrice = 20;
    this.formStock = 60;
    this.errorMessage.set(null);
    this.dialog.set(true);
  }

  openEditDialog(p: any): void {
    this.editingProductId.set(p.id);
    this.formName = p.name;
    this.formCode = p.code;
    this.formStandName = p.standName || this.standName(p.standId);
    this.formPrice = p.price;
    this.formStock = p.stock;
    this.errorMessage.set(null);
    this.dialog.set(true);
  }

  async saveProduct(): Promise<void> {
    if (!this.formName.trim()) {
      this.errorMessage.set('Ingresa el nombre del producto.');
      return;
    }
    if (!this.formStandName) {
      this.errorMessage.set('Crea o selecciona un puesto antes de guardar el producto.');
      return;
    }

    this.saving.set(true);
    this.errorMessage.set(null);
    this.feedbackMessage.set(null);

    const orgId = this.ctx.orgId;
    const eventId = this.ctx.requireActive().id;
    const unitPriceMinor = Math.round((this.formPrice || 1) * 100);
    const stock = Math.max(1, Math.round(this.formStock || 1));

    try {
      const editId = this.editingProductId();
      if (editId) {
        await firstValueFrom(
          this.http.patch(
            `/organizations/${orgId}/events/${eventId}/catalog/products/${editId}`,
            {
              name: this.formName.trim(),
              unit_price_minor: unitPriceMinor,
              stock,
            },
            {
              headers: {
                'Idempotency-Key': `cat-upd-${editId}-${Date.now()}`,
              },
            }
          )
        );
        this.feedbackMessage.set(`Producto "${this.formName}" actualizado en MariaDB.`);
      } else {
        await firstValueFrom(
          this.http.post(
            `/organizations/${orgId}/events/${eventId}/catalog/products`,
            {
              stand_id: this.formStandName,
              code: this.formCode.trim() || `PRD-${Date.now().toString().slice(-3)}`,
              name: this.formName.trim(),
              unit_price_minor: unitPriceMinor,
              stock,
            },
            {
              headers: {
                'Idempotency-Key': `cat-new-${Date.now()}`,
              },
            }
          )
        );
        this.feedbackMessage.set(`Nuevo producto "${this.formName}" creado en el catálogo de MariaDB.`);
      }

      this.dialog.set(false);
      await this.loadCatalogFromApi(eventId);
    } catch (err: unknown) {
      const anyErr = err as { message?: string; originalError?: { error?: { message?: string } } };
      this.errorMessage.set(
        anyErr?.originalError?.error?.message || anyErr?.message || 'No se pudo guardar el producto.'
      );
    } finally {
      this.saving.set(false);
    }
  }

  async toggleActive(p: any, active: boolean): Promise<void> {
    p.active = active;
    const orgId = this.ctx.orgId;
    const eventId = this.ctx.requireActive().id;

    try {
      await firstValueFrom(
        this.http.patch(
          `/organizations/${orgId}/events/${eventId}/catalog/products/${p.id}`,
          { active },
          {
            headers: {
              'Idempotency-Key': `cat-tgl-${p.id}-${Date.now()}`,
            },
          }
        )
      );
      this.feedbackMessage.set(
        `Producto "${p.name}" ${active ? 'activado para la venta' : 'pausado temporalmente'}.`
      );
      await this.loadCatalogFromApi(eventId);
    } catch (err: unknown) {
      p.active = !active;
      const anyErr = err as { message?: string; originalError?: { error?: { message?: string } } };
      this.errorMessage.set(
        anyErr?.originalError?.error?.message || anyErr?.message || 'Error al actualizar el estado del producto.'
      );
    }
  }

  private async loadCatalogFromApi(eventId: string): Promise<void> {
    this.loadError.set(null);
    try {
      const [res, standsRes] = await Promise.all([
        firstValueFrom(
          this.http.get<{ data: ApiCatalogProduct[] }>(
            `/organizations/${this.ctx.orgId}/events/${eventId}/catalog/products?page_size=100`
          )
        ),
        firstValueFrom(
          this.http.get<{ data: Array<{ id: string; name: string; manager: string }> }>(
            `/organizations/${this.ctx.orgId}/events/${eventId}/catalog/stands`
          )
        ),
      ]);

      const icons = ['pi-sparkles', 'pi-star', 'pi-heart', 'pi-ticket', 'pi-shop'];
      this.stands.set(
        (standsRes?.data ?? []).map((s, idx) => ({
          id: s.id,
          name: s.name,
          manager: s.manager,
          icon: icons[idx % icons.length],
        }))
      );

      const emojis: Record<string, string> = {
        'COM-01': '🍖',
        'COM-02': '🥩',
        'COM-03': '🍔',
        'BEB-01': '🥤',
        'BEB-02': '🍹',
        'POS-01': '🧁',
        'POS-02': '🍰',
        'JUE-01': '🎃',
      };

      const mapped = (res?.data ?? []).map((p) => ({
        id: p.id,
        code: p.code,
        standId: p.stand_id,
        standName: p.stand_name,
        name: p.name,
        price: p.unit_price,
        stock: p.stock,
        sold: p.sold,
        active: p.active,
        version: p.version,
        emoji: emojis[p.code] || '🍽️',
      }));
      this.products.set(mapped);
    } catch (err) {
      this.stands.set([]);
      this.products.set([]);
      this.loadError.set(describeLoadError(err, 'No se pudo cargar el catálogo del evento.'));
    }
  }

  openStandDialog(): void {
    this.newStandName = '';
    this.newStandManager = '';
    this.errorMessage.set(null);
    this.standDialog.set(true);
  }

  async saveStand(): Promise<void> {
    if (!this.newStandName.trim() || !this.newStandManager.trim()) {
      this.errorMessage.set('Completa el nombre y el responsable del puesto.');
      return;
    }

    this.saving.set(true);
    this.errorMessage.set(null);
    const eventId = this.ctx.requireActive().id;

    try {
      await firstValueFrom(
        this.http.post(
          `/organizations/${this.ctx.orgId}/events/${eventId}/catalog/stands`,
          {
            name: this.newStandName.trim(),
            manager: this.newStandManager.trim(),
          },
          {
            headers: {
              'Idempotency-Key': `std-new-${Date.now()}`,
            },
          }
        )
      );
      this.standDialog.set(false);
      this.feedbackMessage.set(`Puesto "${this.newStandName}" creado en MariaDB.`);
      await this.loadCatalogFromApi(eventId);
    } catch (err: unknown) {
      const anyErr = err as { message?: string; originalError?: { error?: { message?: string } } };
      this.errorMessage.set(
        anyErr?.originalError?.error?.message || anyErr?.message || 'Error al crear el puesto.'
      );
    } finally {
      this.saving.set(false);
    }
  }

  resetFilters(): void {
    this.quickSearch.set('');
    this.standFilter.set('all');
    this.stockFilter.set('all');
    this.statusFilter.set('all');
  }

  countByStand(standName: string): number {
    return this.products().filter(
      (p) => (p.standName || this.standName(p.standId)) === standName
    ).length;
  }

  standName(id: string): string {
    return this.stands().find((s) => s.id === id)?.name ?? '—';
  }
}
