import { Component, computed, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HttpClient, HttpHeaders } from '@angular/common/http';
import { firstValueFrom } from 'rxjs';
import { ButtonModule } from 'primeng/button';
import { DialogModule } from 'primeng/dialog';
import { InputTextModule } from 'primeng/inputtext';
import { TableModule } from 'primeng/table';
import { EventContextService, onActiveEventChange } from '../../core/services/event-context.service';
import { EventRole, Member, ROLE_LABELS } from '../../core/models/domain';
import { describeLoadError } from '../../core/services/load-error';
import { LoadErrorBannerComponent } from '../../core/ui/load-error-banner.component';

const ORG_ACCESS: Record<string, string> = {
  owner: 'Propietario',
  administrator: 'Administrador',
  collaborator: 'Colaborador',
};

@Component({
  selector: 'app-access',
  standalone: true,
  imports: [CommonModule, FormsModule, ButtonModule, DialogModule, InputTextModule, TableModule, LoadErrorBannerComponent],
  template: `
    <div class="page">
      <app-load-error-banner [message]="loadError()" (retry)="reload()" />
      <div class="page-header">
        <div>
          <span class="eyebrow">Administración &bull; Backend MariaDB</span>
          <h1>Usuarios, roles e invitaciones</h1>
          <p>Membresías organizacionales, cambio transaccional de roles y outbox HMAC en tiempo real con la API Symfony.</p>
        </div>
        <p-button label="Invitar persona" icon="pi pi-user-plus" (onClick)="openInviteDialog()" [disabled]="ctx.readOnly()" />
      </div>

      @if (successMessage()) {
        <div class="notice info">
          <i class="pi pi-check-circle"></i>
          <span>{{ successMessage() }}</span>
        </div>
      }

      @if (errorMessage()) {
        <div class="notice warn">
          <i class="pi pi-exclamation-triangle"></i>
          <span>{{ errorMessage() }}</span>
        </div>
      }

      <!-- Grid 1: Personas con acceso -->
      <section class="panel">
        <div class="panel-head">
          <div>
            <h2><i class="pi pi-users"></i> Personas con acceso a {{ ctx.active()?.name }}</h2>
            <small class="muted">Control de membresías organizacionales y roles operativos por evento.</small>
          </div>
          <span class="grid-meta">{{ filteredMembers().length }} de {{ members().length }} personas</span>
        </div>

        <!-- Barra de búsqueda rápida y filtros -->
        <div class="grid-toolbar">
          <div class="grid-search">
            <i class="pi pi-search"></i>
            <input
              type="text"
              placeholder="Búsqueda rápida por nombre o correo electrónico..."
              [ngModel]="memberSearch()"
              (ngModelChange)="memberSearch.set($event)"
            />
            @if (memberSearch()) {
              <button class="clear-btn" (click)="memberSearch.set('')" title="Limpiar">
                <i class="pi pi-times"></i>
              </button>
            }
          </div>

          <div class="grid-filters">
            <span class="filter-label"><i class="pi pi-filter"></i> Filtros:</span>
            <select class="filter-select" [ngModel]="memberOrgFilter()" (ngModelChange)="memberOrgFilter.set($event)">
              <option value="">Todo acceso org.</option>
              <option value="owner">Propietario</option>
              <option value="administrator">Administrador</option>
              <option value="collaborator">Colaborador</option>
            </select>

            <select class="filter-select" [ngModel]="memberRoleFilter()" (ngModelChange)="memberRoleFilter.set($event)">
              <option value="">Todos los roles</option>
              @for (r of roleKeys; track r) {
                <option [value]="r">{{ roles[r] }}</option>
              }
            </select>

            <select class="filter-select" [ngModel]="memberStatusFilter()" (ngModelChange)="memberStatusFilter.set($event)">
              <option value="">Todos los estados</option>
              <option value="active">Activo</option>
              <option value="suspended">Suspendido</option>
            </select>

            @if (memberSearch() || memberOrgFilter() || memberRoleFilter() || memberStatusFilter()) {
              <button class="filter-reset" (click)="resetMemberFilters()">
                <i class="pi pi-filter-slash"></i> Limpiar
              </button>
            }
          </div>
        </div>

        <p-table
          [value]="filteredMembers()"
          [paginator]="true"
          [rows]="5"
          [rowsPerPageOptions]="[5, 10, 20]"
          [showCurrentPageReport]="true"
          currentPageReportTemplate="Mostrando {first} a {last} de {totalRecords} miembros"
          styleClass="p-datatable-sm"
        >
          <ng-template #header>
            <tr>
              <th>Persona</th>
              <th>Acceso organizacional</th>
              <th>Roles en el evento</th>
              <th>Estado</th>
              <th style="width: 110px; text-align: right">Acciones</th>
            </tr>
          </ng-template>
          <ng-template #body let-m>
            <tr>
              <td>
                <div class="user-cell">
                  <span class="av">{{ initials(m.name) }}</span>
                  <div class="who">
                    <b>{{ m.name }}</b>
                    <small class="muted">{{ m.email }}</small>
                  </div>
                </div>
              </td>
              <td>
                <span class="pill blue">{{ getOrgAccessLabel(m.orgAccess) }}</span>
              </td>
              <td>
                <div class="roles">
                  @for (r of m.roles; track r) {
                    <span class="pill gray">{{ getRoleLabel(r) }}</span>
                  } @empty {
                    <small class="muted">Sin roles activos</small>
                  }
                </div>
              </td>
              <td>
                <span [class]="'pill ' + (m.status === 'active' ? 'green' : 'red')">
                  {{ m.status === 'active' ? 'Activo' : 'Suspendido' }}
                </span>
              </td>
              <td style="text-align: right">
                <p-button
                  label="Roles"
                  icon="pi pi-cog"
                  size="small"
                  severity="secondary"
                  [outlined]="true"
                  (onClick)="openEditMemberDialog(m)"
                  [disabled]="ctx.readOnly()"
                />
              </td>
            </tr>
          </ng-template>
          <ng-template #emptymessage>
            <tr>
              <td colspan="5">
                <div class="empty-grid-state">
                  <i class="pi pi-users"></i>
                  <p>No se encontraron personas con los filtros seleccionados.</p>
                </div>
              </td>
            </tr>
          </ng-template>
        </p-table>
      </section>

      <div class="grid-2">
        <!-- Grid 2: Invitaciones -->
        <section class="panel">
          <div class="panel-head">
            <div>
              <h2><i class="pi pi-envelope"></i> Invitaciones en MariaDB</h2>
              <small class="muted">Enlace HMAC de un solo uso vía Outbox</small>
            </div>
            <span class="grid-meta">{{ filteredInvitations().length }} de {{ invitations().length }}</span>
          </div>

          <div class="grid-toolbar">
            <div class="grid-search">
              <i class="pi pi-search"></i>
              <input
                type="text"
                placeholder="Búsqueda rápida por correo..."
                [ngModel]="invSearch()"
                (ngModelChange)="invSearch.set($event)"
              />
              @if (invSearch()) {
                <button class="clear-btn" (click)="invSearch.set('')" title="Limpiar">
                  <i class="pi pi-times"></i>
                </button>
              }
            </div>
            <div class="grid-filters">
              <span class="filter-label"><i class="pi pi-filter"></i> Filtros:</span>
              <select class="filter-select" [ngModel]="invStatusFilter()" (ngModelChange)="invStatusFilter.set($event)">
                <option value="">Todos los estados</option>
                <option value="pending">Pendiente</option>
                <option value="accepted">Aceptada</option>
                <option value="expired">Vencida</option>
              </select>
              @if (invSearch() || invStatusFilter()) {
                <button class="filter-reset" (click)="invSearch.set(''); invStatusFilter.set('')">
                  <i class="pi pi-filter-slash"></i> Limpiar
                </button>
              }
            </div>
          </div>

          <p-table
            [value]="filteredInvitations()"
            [paginator]="true"
            [rows]="5"
            [rowsPerPageOptions]="[5, 10]"
            [showCurrentPageReport]="true"
            currentPageReportTemplate="{first}-{last} de {totalRecords}"
            styleClass="p-datatable-sm"
          >
            <ng-template #header>
              <tr>
                <th>Correo invitado</th>
                <th>Roles propuestos</th>
                <th>Estado</th>
              </tr>
            </ng-template>
            <ng-template #body let-i>
              <tr>
                <td>
                  <div class="who">
                    <b>{{ i.email }}</b>
                    <small class="muted">Vence {{ i.expires }}</small>
                  </div>
                </td>
                <td>
                  <div class="roles">
                    @for (r of i.roles; track r) {
                      <span class="pill gray">{{ getRoleLabel(r) }}</span>
                    }
                  </div>
                </td>
                <td>
                  <span [class]="'pill ' + (i.status === 'pending' ? 'yellow' : (i.status === 'accepted' ? 'green' : 'red'))">
                    {{ i.status === 'pending' ? 'Pendiente' : (i.status === 'accepted' ? 'Aceptada' : 'Vencida') }}
                  </span>
                </td>
              </tr>
            </ng-template>
            <ng-template #emptymessage>
              <tr>
                <td colspan="3">
                  <div class="empty-grid-state">
                    <i class="pi pi-envelope"></i>
                    <p>No hay invitaciones que coincidan con la búsqueda.</p>
                  </div>
                </td>
              </tr>
            </ng-template>
          </p-table>
        </section>

        <section class="panel">
          <div class="panel-head">
            <h2><i class="pi pi-shield"></i> Matriz de roles en MariaDB</h2>
          </div>
          <div class="panel-body roles-help">
            @for (r of roleKeys; track r) {
              <div class="role-row">
                <span class="pill gray">{{ roles[r] }}</span>
                <small class="mono muted">{{ r }}</small>
              </div>
            }
            <div class="notice warn">
              <i class="pi pi-lock"></i>
              <span>El permiso de <b>cerrar evento</b> aún no está asignado a ningún rol: requiere definir responsable.</span>
            </div>
          </div>
        </section>
      </div>
    </div>

    <!-- Modal: Invitar persona -->
    <p-dialog header="Invitar persona" [visible]="invite()" (visibleChange)="invite.set($event)" [modal]="true" [style]="{ width: '460px' }" [draggable]="false">
      <div class="form-row">
        <label>Correo electrónico</label>
        <input pInputText class="w-full" [(ngModel)]="inviteEmail" placeholder="persona@correo.com" />
      </div>
      <div class="form-row">
        <label>Roles en {{ ctx.active()?.name }}</label>
        <div class="checks">
          @for (r of roleKeys; track r) {
            <label>
              <input type="checkbox" [checked]="selectedRoles()[r]" (change)="toggleRole(r)" />
              {{ roles[r] }}
            </label>
          }
        </div>
      </div>
      <div class="notice info">
        <i class="pi pi-shield"></i>
        <span>Genera un outbox atómico en MariaDB con token HMAC versionado e idempotencia estricta.</span>
      </div>
      <ng-template #footer>
        <p-button label="Cancelar" severity="secondary" [text]="true" (onClick)="invite.set(false)" />
        <p-button label="Enviar invitación" icon="pi pi-send" [loading]="submitting()" (onClick)="sendInvitation()" />
      </ng-template>
    </p-dialog>

    <!-- Modal: Gestionar roles de miembro en el evento -->
    <p-dialog
      [header]="'Roles en el evento: ' + (editingMember()?.name || '')"
      [visible]="editMemberDialog()"
      (visibleChange)="editMemberDialog.set($event)"
      [modal]="true"
      [style]="{ width: '460px' }"
      [draggable]="false"
    >
      <p class="muted" style="margin-top: 0">
        Selecciona los roles operativos para <b>{{ editingMember()?.email }}</b> en <b>{{ ctx.active()?.name }}</b>:
      </p>
      <div class="checks">
        @for (r of roleKeys; track r) {
          <label>
            <input type="checkbox" [checked]="memberRolesDraft()[r]" (change)="toggleMemberDraftRole(r)" />
            {{ roles[r] }}
          </label>
        }
      </div>
      <ng-template #footer>
        @if (editingMember()?.orgAccess !== 'owner') {
          <p-button
            label="Revocar acceso al evento"
            icon="pi pi-ban"
            severity="danger"
            [text]="true"
            [loading]="submitting()"
            (onClick)="revokeEventAccess()"
          />
        }
        <p-button label="Cancelar" severity="secondary" [text]="true" (onClick)="editMemberDialog.set(false)" />
        <p-button label="Guardar roles" icon="pi pi-check" [loading]="submitting()" (onClick)="saveMemberRoles()" />
      </ng-template>
    </p-dialog>
  `,
  styles: [`
    .user-cell { display: flex; align-items: center; gap: .75rem; }
    .av { width: 36px; height: 36px; border-radius: 50%; background: var(--kf-cyan-soft); color: #0e7c86; display: grid; place-items: center; font-weight: 800; font-size: .8rem; flex-shrink: 0; }
    .who { display: flex; flex-direction: column; font-size: .86rem; }
    .roles { display: flex; gap: .3rem; flex-wrap: wrap; }
    .roles-help { display: flex; flex-direction: column; gap: .6rem; }
    .role-row { display: flex; justify-content: space-between; align-items: center; padding: .35rem 0; border-bottom: 1px dashed #e2e8f0; }
    .checks { display: grid; grid-template-columns: 1fr 1fr; gap: .4rem; font-size: .85rem; }
  `],
})
export class AccessComponent {
  private readonly http = inject(HttpClient);
  protected readonly ctx = inject(EventContextService);

  protected readonly members = signal<Member[]>([]);
  protected readonly invitations = signal<any[]>([]);
  private readonly membersError = signal<string | null>(null);
  private readonly invitationsError = signal<string | null>(null);
  protected readonly loadError = computed(() => this.membersError() ?? this.invitationsError());
  protected readonly roles = ROLE_LABELS;
  protected readonly roleKeys = Object.keys(ROLE_LABELS) as EventRole[];
  protected readonly orgAccess = ORG_ACCESS;

  // Filtros de Miembros
  protected readonly memberSearch = signal('');
  protected readonly memberOrgFilter = signal('');
  protected readonly memberRoleFilter = signal('');
  protected readonly memberStatusFilter = signal('');

  // Filtros de Invitaciones
  protected readonly invSearch = signal('');
  protected readonly invStatusFilter = signal('');

  protected readonly filteredMembers = computed(() => {
    const q = this.memberSearch().trim().toLowerCase();
    const org = this.memberOrgFilter();
    const role = this.memberRoleFilter() as EventRole | '';
    const status = this.memberStatusFilter();

    return this.members().filter((m) => {
      if (org && m.orgAccess !== org) return false;
      if (role && !m.roles.includes(role)) return false;
      if (status && m.status !== status) return false;
      if (q) {
        const hay = `${m.name} ${m.email}`.toLowerCase();
        if (!hay.includes(q)) return false;
      }
      return true;
    });
  });

  protected readonly filteredInvitations = computed(() => {
    const q = this.invSearch().trim().toLowerCase();
    const status = this.invStatusFilter();

    return this.invitations().filter((i) => {
      if (status && i.status !== status) return false;
      if (q && !i.email.toLowerCase().includes(q)) return false;
      return true;
    });
  });

  protected readonly invite = signal(false);
  protected readonly editMemberDialog = signal(false);
  protected readonly editingMember = signal<Member | null>(null);
  protected readonly memberRolesDraft = signal<Record<string, boolean>>({});
  protected readonly submitting = signal(false);
  protected readonly successMessage = signal<string | null>(null);
  protected readonly errorMessage = signal<string | null>(null);

  protected inviteEmail = '';
  protected readonly selectedRoles = signal<Record<string, boolean>>({
    SALES_OPERATOR: true,
  });

  constructor() {
    onActiveEventChange((eventId) => {
      this.members.set([]);
      this.loadMembersFromApi(eventId);
      this.loadInvitationsFromApi();
    });
  }

  reload(): void {
    const activeEvent = this.ctx.active();
    if (activeEvent) {
      this.loadMembersFromApi(activeEvent.id);
    }
    this.loadInvitationsFromApi();
  }

  resetMemberFilters(): void {
    this.memberSearch.set('');
    this.memberOrgFilter.set('');
    this.memberRoleFilter.set('');
    this.memberStatusFilter.set('');
  }

  private async loadMembersFromApi(eventId: string): Promise<void> {
    this.membersError.set(null);
    try {
      const res = await firstValueFrom(
        this.http.get<{ data: Member[] }>(
          `/organizations/${this.ctx.orgId}/events/${eventId}/members`
        )
      );
      if (this.ctx.isStale(eventId)) return;
      this.members.set(res?.data ?? []);
    } catch (err) {
      if (this.ctx.isStale(eventId)) return;
      this.members.set([]);
      this.membersError.set(describeLoadError(err, 'No se pudieron cargar las personas con acceso.'));
    }
  }

  private async loadInvitationsFromApi(): Promise<void> {
    this.invitationsError.set(null);
    try {
      const res = await firstValueFrom(
        this.http.get<{ data: any[] }>(`/organizations/${this.ctx.orgId}/invitations`)
      );
      this.invitations.set(res?.data ?? []);
    } catch (err) {
      this.invitations.set([]);
      this.invitationsError.set(describeLoadError(err, 'No se pudieron cargar las invitaciones.'));
    }
  }

  openInviteDialog(): void {
    this.inviteEmail = '';
    this.selectedRoles.set({ SALES_OPERATOR: true });
    this.errorMessage.set(null);
    this.invite.set(true);
  }

  openEditMemberDialog(member: Member): void {
    this.editingMember.set(member);
    const draft: Record<string, boolean> = {};
    for (const r of member.roles) {
      draft[r] = true;
    }
    this.memberRolesDraft.set(draft);
    this.errorMessage.set(null);
    this.editMemberDialog.set(true);
  }

  toggleRole(role: EventRole): void {
    this.selectedRoles.update((prev) => ({
      ...prev,
      [role]: !prev[role],
    }));
  }

  toggleMemberDraftRole(role: EventRole): void {
    this.memberRolesDraft.update((prev) => ({
      ...prev,
      [role]: !prev[role],
    }));
  }

  async saveMemberRoles(): Promise<void> {
    const member = this.editingMember();
    if (!member) return;

    const assignedRoles = Object.keys(this.memberRolesDraft()).filter(
      (k) => this.memberRolesDraft()[k]
    );

    if (assignedRoles.length === 0) {
      this.errorMessage.set('Selecciona al menos un rol o utiliza la opción de revocar acceso.');
      return;
    }

    this.submitting.set(true);
    this.errorMessage.set(null);
    this.successMessage.set(null);

    const eventId = this.ctx.requireActive().id;
    try {
      await firstValueFrom(
        this.http.patch(
          `/organizations/${this.ctx.orgId}/events/${eventId}/access-assignments/${member.id}/roles`,
          { roles: assignedRoles },
          {
            headers: new HttpHeaders({
              'Idempotency-Key': crypto.randomUUID(),
            }),
          }
        )
      );
      this.editMemberDialog.set(false);
      this.successMessage.set(`Roles de ${member.name} actualizados en MariaDB.`);
      await this.loadMembersFromApi(eventId);
    } catch (err: any) {
      this.errorMessage.set(
        err?.originalError?.error?.message || err?.error?.message || 'Error al actualizar roles del miembro.'
      );
    } finally {
      this.submitting.set(false);
    }
  }

  async revokeEventAccess(): Promise<void> {
    const member = this.editingMember();
    if (!member) return;

    this.submitting.set(true);
    this.errorMessage.set(null);
    this.successMessage.set(null);

    const eventId = this.ctx.requireActive().id;
    try {
      await firstValueFrom(
        this.http.delete(
          `/organizations/${this.ctx.orgId}/events/${eventId}/access-assignments/${member.id}`,
          {
            headers: new HttpHeaders({
              'Idempotency-Key': crypto.randomUUID(),
            }),
          }
        )
      );
      this.editMemberDialog.set(false);
      this.successMessage.set(`Acceso al evento revocado para ${member.name}.`);
      await this.loadMembersFromApi(eventId);
    } catch (err: any) {
      this.errorMessage.set(
        err?.originalError?.error?.message || err?.error?.message || 'Error al revocar acceso al evento.'
      );
    } finally {
      this.submitting.set(false);
    }
  }

  async sendInvitation(): Promise<void> {
    if (!this.inviteEmail.includes('@')) {
      this.errorMessage.set('Por favor ingresa un correo electrónico válido.');
      return;
    }

    const assignedRoles = Object.keys(this.selectedRoles()).filter(
      (k) => this.selectedRoles()[k]
    );

    if (assignedRoles.length === 0) {
      this.errorMessage.set('Debes seleccionar al menos un rol.');
      return;
    }

    this.submitting.set(true);
    this.errorMessage.set(null);
    try {
      const idempotencyKey = crypto.randomUUID();
      const headers = new HttpHeaders({
        'Idempotency-Key': idempotencyKey,
      });

      await firstValueFrom(
        this.http.post(
          `/organizations/${this.ctx.orgId}/invitations`,
          {
            email: this.inviteEmail.trim(),
            target_access_type: 'operational',
            event_id: this.ctx.requireActive().id,
            roles: assignedRoles,
            expires_at: new Date(Date.now() + 7 * 86400 * 1000).toISOString(),
          },
          { headers }
        )
      );

      this.successMessage.set(
        `Invitación creada y registrada en outbox transaccional para ${this.inviteEmail}.`
      );
      this.invite.set(false);
      await this.loadInvitationsFromApi();
    } catch (err: any) {
      this.errorMessage.set(
        err?.originalError?.error?.message ||
          err?.error?.message ||
          'No se pudo registrar la invitación. Verifica los permisos de tu usuario.'
      );
    } finally {
      this.submitting.set(false);
    }
  }

  getRoleLabel(code: string): string {
    return (this.roles as Record<string, string>)[code] || code;
  }

  getOrgAccessLabel(code: string): string {
    return this.orgAccess[code] || code;
  }

  initials(name: string): string {
    return name
      .split(' ')
      .map((p) => p[0])
      .slice(0, 2)
      .join('');
  }
}
