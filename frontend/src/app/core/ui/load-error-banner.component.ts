import { Component, input, output } from '@angular/core';

/**
 * Aviso visible cuando una pantalla no pudo cargar sus datos de la API.
 * Sustituye al antiguo comportamiento de mostrar datos de ejemplo en silencio.
 */
@Component({
  selector: 'app-load-error-banner',
  standalone: true,
  template: `
    @if (message(); as msg) {
      <div class="notice danger load-error" role="alert">
        <i class="pi pi-exclamation-triangle"></i>
        <span>{{ msg }}</span>
        <button type="button" class="retry" (click)="retry.emit()">Reintentar</button>
      </div>
    }
  `,
  styles: [`
    .load-error { margin-bottom: 1.25rem; align-items: center; }
    .retry { margin-left: auto; font-weight: 600; background: none; border: 0; cursor: pointer; color: inherit; text-decoration: underline; }
  `],
})
export class LoadErrorBannerComponent {
  readonly message = input<string | null>(null);
  readonly retry = output<void>();
}
