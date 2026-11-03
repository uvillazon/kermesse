import { Component, computed, inject, signal, OnInit } from '@angular/core';
import { CommonModule, DecimalPipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HttpClient } from '@angular/common/http';
import { ActivatedRoute } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { ButtonModule } from 'primeng/button';
import { InputTextModule } from 'primeng/inputtext';
import { describeLoadError } from '../../core/services/load-error';
import { LoadErrorBannerComponent } from '../../core/ui/load-error-banner.component';
import { FechaPipe } from '../../core/ui/fecha.pipe';

type Step = 'menu' | 'pay' | 'done';

interface PublicProduct {
  id: string;
  stand_id: string;
  stand_name: string;
  code: string;
  name: string;
  description: string | null;
  unit_price: number;
  stock: number;
  sold: number;
  available_quantity: number;
}

@Component({
  selector: 'app-public-store',
  standalone: true,
  imports: [FechaPipe, CommonModule, FormsModule, DecimalPipe, ButtonModule, InputTextModule, LoadErrorBannerComponent],
  template: `
    <div class="store">
      <header class="hero">
        <div class="hero-in">
          <span class="org">{{ orgName() }}</span>
          <h1>{{ eventName() || (loadError() ? 'Tienda no disponible' : 'Cargando tienda…') }}</h1>
          <p>Compra tus platos, bebidas y fichas en línea. Solo necesitas tu correo: recibirás tu ticket QR oficial para canjear en cada puesto.</p>
          @if (eventDate()) {
            <span class="date"><i class="pi pi-calendar"></i> {{ eventDate() | fecha:"EEEE d 'de' MMMM 'de' y" }}</span>
          }
        </div>
      </header>
      <app-load-error-banner [message]="loadError()" (retry)="loadPublicCatalog(slug())" />

      <main class="body">
        @if (errorMessage()) {
          <div class="notice warn mb-3">
            <i class="pi pi-exclamation-triangle"></i>
            <span>{{ errorMessage() }}</span>
          </div>
        }

        @switch (step()) {
          @case ('menu') {
            <div class="layout">
              <div class="catalog-box panel">
                <!-- Buscador rápido y filtro por puesto en la tienda pública -->
                <div class="grid-toolbar">
                  <div class="grid-search">
                    <i class="pi pi-search"></i>
                    <input
                      type="text"
                      placeholder="Búsqueda rápida de platos, postres o bebidas..."
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
                    <span class="filter-label"><i class="pi pi-filter"></i> Puesto:</span>
                    <select class="filter-select" [ngModel]="standFilter()" (ngModelChange)="standFilter.set($event)">
                      <option value="">Todos los puestos</option>
                      @for (st of standNames(); track st) {
                        <option [value]="st">{{ st }}</option>
                      }
                    </select>
                    @if (search() || standFilter()) {
                      <button class="filter-reset" (click)="search.set(''); standFilter.set('')">
                        <i class="pi pi-filter-slash"></i> Limpiar
                      </button>
                    }
                  </div>
                </div>

                <section class="menu">
                  @for (p of filteredProducts(); track p.id) {
                    <article class="item">
                      <div class="food">
                        <span>{{ getEmoji(p.code) }}</span>
                        <small class="stand-pill">{{ p.stand_name }}</small>
                      </div>
                      <h3>{{ p.name }}</h3>
                      <div class="price-row">
                        <span class="price">Bs {{ p.unit_price | number:'1.2-2' }}</span>
                        <small class="muted">{{ p.available_quantity }} disp.</small>
                      </div>
                      @if (cart()[p.id]) {
                        <div class="qty">
                          <button (click)="dec(p.id)">−</button>
                          <b>{{ cart()[p.id] }}</b>
                          <button (click)="inc(p.id)" [disabled]="cart()[p.id] >= p.available_quantity">+</button>
                        </div>
                      } @else {
                        <p-button
                          [fluid]="true"
                          label="Agregar"
                          icon="pi pi-plus"
                          (onClick)="inc(p.id)"
                          [disabled]="p.available_quantity <= 0"
                        />
                      }
                    </article>
                  } @empty {
                    <div class="empty-grid-state" style="grid-column: 1 / -1">
                      <i class="pi pi-shopping-bag"></i>
                      <p>No se encontraron productos disponibles con ese filtro.</p>
                    </div>
                  }
                </section>
              </div>

              <aside class="summary">
                <h2><i class="pi pi-shopping-cart"></i> Tu pedido</h2>
                @for (l of lines(); track l.id) {
                  <div class="sl">
                    <span>{{ l.qty }} × {{ l.name }}</span>
                    <b>Bs {{ l.subtotal | number:'1.2-2' }}</b>
                  </div>
                } @empty {
                  <p class="muted">Aún no agregaste productos.</p>
                }
                <div class="tot">
                  <span>Total</span>
                  <b>Bs {{ total() | number:'1.2-2' }}</b>
                </div>
                <div class="form-row">
                  <label>Tu nombre</label>
                  <input pInputText [(ngModel)]="buyerName" placeholder="Ej. Familia Rojas" class="w-full" />
                </div>
                <div class="form-row">
                  <label>Tu correo electrónico</label>
                  <input pInputText type="email" [(ngModel)]="email" placeholder="tucorreo@gmail.com" class="w-full" />
                </div>
                <p-button
                  [fluid]="true"
                  label="Continuar al pago QR"
                  icon="pi pi-arrow-right"
                  iconPos="right"
                  [disabled]="!total() || !email.includes('@')"
                  (onClick)="step.set('pay')"
                />
              </aside>
            </div>
          }
          @case ('pay') {
            <section class="card-center">
              <h2>Paga con QR bancario</h2>
              <p class="muted">Escanea el código con la app de tu banco y transfiere exactamente <b>Bs {{ total() | number:'1.2-2' }}</b>.</p>
              <div class="qr">
                <i class="pi pi-qrcode"></i>
                <small>QR Simple BNB / Banco Unión</small>
              </div>
              <p class="muted small">
                Tu pedido quedará <b>por verificar</b>. En cuanto tesorería confirme el abono, tu ticket QR quedará activo para canjear en los puestos.
              </p>
              <div class="row">
                <p-button label="Volver al menú" severity="secondary" [text]="true" (onClick)="step.set('menu')" />
                <p-button label="Ya realicé el pago" icon="pi pi-check" [loading]="submitting()" (onClick)="submitOrder()" />
              </div>
            </section>
          }
          @case ('done') {
            <section class="card-center">
              <i class="pi pi-check-circle ok"></i>
              <h2>¡Pedido registrado!</h2>
              <p>Reserva registrada: <b class="mono ticket-highlight">{{ createdOrder()?.ticket_number }}</b></p>
              <p class="muted">
                Tu compra por <b>Bs {{ createdOrder()?.total | number:'1.2-2' }}</b> está reservada durante 15 minutos y aparece en <b>Verificar pagos</b>. El ticket se habilita al confirmar el pago.
              </p>
              <p-button label="Hacer otro pedido" [outlined]="true" (onClick)="reset()" />
            </section>
          }
        }
      </main>
      <footer class="foot">Con la tecnología de <b>Kermes<span>fera</span></b></footer>
    </div>
  `,
  styles: [`
    .store { min-height: 100vh; background: var(--kf-bg); display: flex; flex-direction: column; }
    .hero { background: linear-gradient(135deg, #063b73 0%, #04284f 100%); color: #fff; padding: 2.5rem 1.25rem 3.5rem; }
    .hero-in { max-width: 1120px; margin: 0 auto; }
    .org { color: var(--kf-accent); font-weight: 700; font-size: .8rem; text-transform: uppercase; letter-spacing: .08em; }
    .hero h1 { font-size: 2.3rem; margin: .5rem 0; } .hero p { max-width: 580px; color: #c9d7ea; }
    .date { display: inline-block; margin-top: .5rem; background: rgba(255,255,255,.12); padding: .35rem .8rem; border-radius: 999px; font-size: .82rem; }
    .body { max-width: 1120px; width: 100%; margin: -2rem auto 2rem; padding: 0 1.25rem; flex: 1; }
    .layout { display: grid; grid-template-columns: 1fr 340px; gap: 1.25rem; align-items: start; }
    .catalog-box { overflow: hidden; }
    .menu { display: grid; grid-template-columns: repeat(auto-fill, minmax(195px, 1fr)); gap: 1rem; padding: 1.15rem; }
    .item { background: #fff; border: 1px solid var(--kf-line); border-radius: 14px; padding: 1rem; display: flex; flex-direction: column; justify-content: space-between; }
    .food { height: 92px; border-radius: 10px; background: linear-gradient(135deg, var(--kf-accent-soft), var(--kf-cyan-soft)); display: flex; flex-direction: column; align-items: center; justify-content: center; font-size: 2.3rem; position: relative; }
    .stand-pill { font-size: .66rem; font-weight: 800; color: var(--kf-primary); background: rgba(255,255,255,.85); padding: .1rem .45rem; border-radius: 999px; margin-top: .2rem; }
    .item h3 { font-size: .95rem; margin: .7rem 0 .25rem; color: var(--kf-primary); }
    .price-row { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: .65rem; }
    .price { font-weight: 800; color: var(--kf-coral); font-size: 1.12rem; }
    .qty { display: flex; justify-content: space-between; align-items: center; border: 1px solid var(--kf-line); border-radius: 8px; padding: .2rem; }
    .qty button { border: 0; background: var(--kf-primary-soft); color: var(--kf-primary); width: 34px; height: 30px; border-radius: 6px; cursor: pointer; font-size: 1.1rem; font-weight: 700; }
    .summary, .card-center { background: #fff; border: 1px solid var(--kf-line); border-radius: 14px; padding: 1.25rem; box-shadow: var(--kf-shadow-sm); }
    .summary { position: sticky; top: 1rem; } .summary h2, .card-center h2 { margin: 0 0 .75rem; color: var(--kf-primary); font-size: 1.1rem; }
    .sl { display: flex; justify-content: space-between; font-size: .86rem; padding: .35rem 0; border-bottom: 1px dashed #e2e8f0; }
    .tot { display: flex; justify-content: space-between; border-top: 1px solid var(--kf-line); margin: .75rem 0 1rem; padding-top: .75rem; }
    .tot b { font-size: 1.3rem; color: var(--kf-primary); }
    .card-center { max-width: 480px; margin: 0 auto; text-align: center; }
    .qr { border: 2px dashed var(--kf-cyan); border-radius: 14px; padding: 1.5rem; margin: 1rem auto; width: 220px; display: flex; flex-direction: column; gap: .5rem; }
    .qr i { font-size: 7rem; color: var(--kf-primary); } .qr small { color: var(--kf-muted); font-size: .72rem; }
    .small { font-size: .82rem; } .row { display: flex; justify-content: center; gap: .5rem; margin-top: 1rem; }
    .ok { font-size: 3.5rem; color: var(--kf-green); }
    .ticket-highlight { font-size: 1.25rem; color: var(--kf-primary); background: var(--kf-primary-soft); padding: .2rem .6rem; border-radius: 6px; }
    .mb-3 { margin-bottom: 1rem; }
    .foot { text-align: center; padding: 1.25rem; font-size: .8rem; color: var(--kf-muted); } .foot span { color: #c79a00; }
    @media (max-width: 900px) { .layout { grid-template-columns: 1fr; } .summary { position: static; } .hero h1 { font-size: 1.8rem; } }
  `],
})
export class PublicStoreComponent implements OnInit {
  private readonly http = inject(HttpClient);
  private readonly route = inject(ActivatedRoute);

  protected readonly orgName = signal('');
  protected readonly eventName = signal('');
  protected readonly eventDate = signal('');
  protected readonly slug = signal('');
  protected readonly loadError = signal<string | null>(null);

  protected readonly products = signal<PublicProduct[]>([]);
  protected readonly search = signal('');
  protected readonly standFilter = signal('');

  protected readonly step = signal<Step>('menu');
  protected readonly cart = signal<Record<string, number>>({});
  protected readonly submitting = signal(false);
  protected readonly errorMessage = signal<string | null>(null);
  protected readonly createdOrder = signal<{ ticket_number: string; total: string; reservation_expires_at: string } | null>(null);
  private orderIdempotencyKey = crypto.randomUUID();

  protected buyerName = '';
  protected email = '';

  protected readonly standNames = computed(() =>
    Array.from(new Set(this.products().map((p) => p.stand_name))).sort()
  );

  protected readonly filteredProducts = computed(() => {
    const q = this.search().trim().toLowerCase();
    const stand = this.standFilter();
    return this.products().filter((p) => {
      if (stand && p.stand_name !== stand) return false;
      if (q && !`${p.name} ${p.code} ${p.stand_name}`.toLowerCase().includes(q)) return false;
      return true;
    });
  });

  protected readonly lines = computed(() => {
    const prods = this.products();
    return Object.entries(this.cart()).map(([id, qty]) => {
      const p = prods.find((item) => item.id === id);
      return {
        id,
        name: p?.name || 'Producto',
        qty,
        subtotal: qty * (p?.unit_price || 0),
      };
    });
  });

  protected readonly total = computed(() => this.lines().reduce((t, l) => t + l.subtotal, 0));

  ngOnInit(): void {
    const paramSlug = this.route.snapshot.paramMap.get('slug') ?? '';
    this.slug.set(paramSlug);
    this.loadPublicCatalog(paramSlug);
  }

  async loadPublicCatalog(slug: string): Promise<void> {
    this.loadError.set(null);
    try {
      const res = await firstValueFrom(
        this.http.get<{
          data: {
            event: { organization_name: string; name: string; starts_at: string | null; slug: string };
            products: PublicProduct[];
          };
        }>(`/public/events/${slug}/catalog`)
      );
      this.orgName.set(res?.data?.event?.organization_name ?? '');
      this.eventName.set(res?.data?.event?.name ?? '');
      this.eventDate.set(res?.data?.event?.starts_at ?? '');
      this.products.set(res?.data?.products ?? []);
    } catch (err) {
      this.orgName.set('');
      this.eventName.set('');
      this.eventDate.set('');
      this.products.set([]);
      this.loadError.set(describeLoadError(err, 'No se pudo cargar la tienda de este evento.'));
    }
  }

  inc(id: string): void {
    const p = this.products().find((item) => item.id === id);
    if (!p) return;
    const current = this.cart()[id] ?? 0;
    if (current >= p.available_quantity) return;
    this.cart.update((c) => ({ ...c, [id]: current + 1 }));
  }

  dec(id: string): void {
    this.cart.update((c) => {
      const n = { ...c };
      n[id] > 1 ? n[id]-- : delete n[id];
      return n;
    });
  }

  async submitOrder(): Promise<void> {
    const items = Object.entries(this.cart()).map(([event_product_id, quantity]) => ({
      event_product_id,
      quantity,
    }));
    if (items.length === 0) return;

    this.submitting.set(true);
    this.errorMessage.set(null);

    try {
      const res = await firstValueFrom(
        this.http.post<{ data: { ticket_number: string; total: string; reservation_expires_at: string } }>(
          `/public/events/${this.slug()}/orders`,
          {
            buyer_email: this.email.trim(),
            buyer_name: this.buyerName.trim() || null,
            items,
          },
          { headers: { 'Idempotency-Key': this.orderIdempotencyKey } }
        )
      );
      if (res?.data) {
        this.createdOrder.set(res.data);
        this.step.set('done');
        await this.loadPublicCatalog(this.slug());
      }
    } catch (err: unknown) {
      const anyErr = err as { message?: string; originalError?: { error?: { message?: string } } };
      this.errorMessage.set(
        anyErr?.originalError?.error?.message || anyErr?.message || 'Error al registrar tu compra en línea.'
      );
    } finally {
      this.submitting.set(false);
    }
  }

  reset(): void {
    this.cart.set({});
    this.email = '';
    this.buyerName = '';
    this.createdOrder.set(null);
    this.orderIdempotencyKey = crypto.randomUUID();
    this.step.set('menu');
  }

  getEmoji(code: string): string {
    const map: Record<string, string> = {
      'COM-01': '🍖',
      'COM-02': '🥩',
      'COM-03': '🍔',
      'BEB-01': '🥤',
      'BEB-02': '🍹',
      'POS-01': '🧁',
      'POS-02': '🍰',
      'JUE-01': '🎃',
    };
    return map[code] || '🍽️';
  }
}
