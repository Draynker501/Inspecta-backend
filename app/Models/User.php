<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Exceptions\CrossTenantOperationException;
use App\Exceptions\MissingTenantContextException;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['id', 'name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'organization_id' => 'string',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    /**
     * Membresías de este usuario en organizaciones (M1.5, P1).
     *
     * Fuente de pertenencia paralela a users.organization_id. No usa
     * BelongsToOrganization: se consulta directamente por user_id.
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class, 'user_id');
    }

    /**
     * Indica si el usuario es operador de plataforma.
     *
     * La organización operadora se resuelve de forma determinista por su
     * tipo (operator), estado activo y ausencia de borrado lógico. Debe
     * existir exactamente una; en caso contrario se deniega (fail-closed).
     * La pertenencia se verifica contra organization_memberships, nunca
     * contra users.organization_id ni TenantContext.
     */
    public function isPlatformOperator(): bool
    {
        $operatorOrganizationId = $this->platformOperatorOrganizationId();

        $status = $this->getAttribute('status');

        if ($status === null && $this->exists) {
            $status = $this->fresh()?->getAttribute('status');
        }

        if (! (bool) $status) {
            return false;
        }

        if ($operatorOrganizationId === null) {
            return false;
        }

        return OrganizationMembership::findActive($this->id, $operatorOrganizationId) !== null;
    }

    /**
     * Indica si el usuario tiene el rol de plataforma en la organización
     * operadora, con coherencia de organización (D-07).
     *
     * Se ejecuta fuera del TenantContext vigente, porque el contexto será
     * el de una organización cliente. El contexto previo se restaura
     * incluso si la consulta lanza una excepción.
     *
     * No autoriza por sí solo: debe combinarse con isPlatformOperator().
     */
    public function hasPlatformRole(): bool
    {
        $operatorOrganizationId = $this->platformOperatorOrganizationId();

        if ($operatorOrganizationId === null) {
            return false;
        }

        if (OrganizationMembership::findActive($this->id, $operatorOrganizationId) === null) {
            return false;
        }

        $platformRoleName = config('tenancy.roles.platform_admin');

        if (! is_string($platformRoleName) || $platformRoleName === '') {
            return false;
        }

        $context = app(TenantContext::class);

        return (bool) $context->withoutTenant('platform-role-check', function () use ($operatorOrganizationId, $platformRoleName) {
            return UserRole::query()
                ->where('user_id', $this->id)
                ->where('organization_id', $operatorOrganizationId)
                ->whereHas('role', function ($query) use ($operatorOrganizationId, $platformRoleName) {
                    $query->where('organization_id', $operatorOrganizationId)
                        ->where('name', $platformRoleName);
                })
                ->exists();
        });
    }

    /**
     * Identificador de la organización operadora si existe una sola y está
     * activa/no eliminada; en caso contrario null (fail-closed).
     */
    private function platformOperatorOrganizationId(): ?string
    {
        $ids = Organization::query()
            ->where('type', Organization::TYPE_OPERATOR)
            ->where('status', true)
            ->whereNull('deleted_at')
            ->pluck('id');

        if ($ids->count() !== 1) {
            return null;
        }

        return (string) $ids->first();
    }

    /**
     * Indica si el usuario tiene el rol indicado dentro de la organización activa.
     *
     * La organización se resuelve exclusivamente del TenantContext activo.
     * Sin contexto, devuelve false. Nunca usa users.organization_id como fallback.
     */
    public function hasRole(string $role): bool
    {
        $organizationId = app(TenantContext::class)->id();

        if ($organizationId === null) {
            return false;
        }

        return $this->roles()
            ->where('roles.name', $role)
            ->where('roles.organization_id', $organizationId)
            ->exists();
    }

    /**
     * Administrador de la organización activa (no existe super-admin global).
     */
    public function isAdmin(): bool
    {
        return $this->hasRole((string) config('tenancy.roles.admin'));
    }

    public function hasPlantAccess(string $plantId): bool
    {
        $organizationId = app(TenantContext::class)->id();

        if ($organizationId === null) {
            return false;
        }

        return $this->plants()
            ->whereKey($plantId)
            ->wherePivot('organization_id', $organizationId)
            ->exists();
    }

    public function hasAreaAccess(string $areaId): bool
    {
        $organizationId = app(TenantContext::class)->id();

        if ($organizationId === null) {
            return false;
        }

        return $this->areas()
            ->whereKey($areaId)
            ->wherePivot('organization_id', $organizationId)
            ->exists();
    }

    /**
     * Asigna un rol al usuario en la organización activa.
     *
     * Valida membresía activa del usuario y que el rol pertenezca a
     * TenantContext. Rechaza asignaciones existentes incoherentes.
     */
    public function assignRole(Role $role): UserRole
    {
        $organizationId = app(TenantContext::class)->id();

        if ($organizationId === null) {
            throw MissingTenantContextException::for(UserRole::class);
        }

        if ($role->organization_id !== $organizationId) {
            throw CrossTenantOperationException::write();
        }

        $membership = OrganizationMembership::findActive($this->id, $organizationId);

        if (! $membership) {
            throw CrossTenantOperationException::write();
        }

        return DB::transaction(function () use ($role, $organizationId) {
            $userRole = UserRole::firstOrCreate(
                ['user_id' => $this->id, 'role_id' => $role->id],
                ['organization_id' => $organizationId],
            );

            if ($userRole->organization_id !== $organizationId) {
                throw CrossTenantOperationException::write();
            }

            if ($userRole->wasRecentlyCreated) {
                app(AuditLogger::class)->record('role.assigned', $userRole, [], [
                    'user_id' => $this->id,
                    'role_id' => $role->id,
                    'organization_id' => $organizationId,
                ]);
            }

            return $userRole;
        });
    }

    /**
     * Asigna una planta al usuario en la organización activa.
     *
     * Valida membresía activa del usuario y que la planta pertenezca a
     * TenantContext. Rechaza asignaciones existentes incoherentes.
     */
    public function assignPlant(Plant $plant): UserPlant
    {
        $organizationId = app(TenantContext::class)->id();

        if ($organizationId === null) {
            throw MissingTenantContextException::for(UserPlant::class);
        }

        if ($plant->organization_id !== $organizationId) {
            throw CrossTenantOperationException::write();
        }

        $membership = OrganizationMembership::findActive($this->id, $organizationId);

        if (! $membership) {
            throw CrossTenantOperationException::write();
        }

        return DB::transaction(function () use ($plant, $organizationId) {
            $userPlant = UserPlant::firstOrCreate(
                ['user_id' => $this->id, 'plant_id' => $plant->id],
                ['organization_id' => $organizationId],
            );

            if ($userPlant->organization_id !== $organizationId) {
                throw CrossTenantOperationException::write();
            }

            if ($userPlant->wasRecentlyCreated) {
                app(AuditLogger::class)->record('plant.assigned', $userPlant, [], [
                    'user_id' => $this->id,
                    'plant_id' => $plant->id,
                    'organization_id' => $organizationId,
                ]);
            }

            return $userPlant;
        });
    }

    /**
     * Asigna un área al usuario en la organización activa.
     *
     * Valida membresía activa del usuario y que el área pertenezca a
     * TenantContext. Rechaza asignaciones existentes incoherentes.
     */
    public function assignArea(Area $area): UserArea
    {
        $organizationId = app(TenantContext::class)->id();

        if ($organizationId === null) {
            throw MissingTenantContextException::for(UserArea::class);
        }

        if ($area->organization_id !== $organizationId) {
            throw CrossTenantOperationException::write();
        }

        $membership = OrganizationMembership::findActive($this->id, $organizationId);

        if (! $membership) {
            throw CrossTenantOperationException::write();
        }

        return DB::transaction(function () use ($area, $organizationId) {
            $userArea = UserArea::firstOrCreate(
                ['user_id' => $this->id, 'area_id' => $area->id],
                ['organization_id' => $organizationId],
            );

            if ($userArea->organization_id !== $organizationId) {
                throw CrossTenantOperationException::write();
            }

            if ($userArea->wasRecentlyCreated) {
                app(AuditLogger::class)->record('area.assigned', $userArea, [], [
                    'user_id' => $this->id,
                    'area_id' => $area->id,
                    'organization_id' => $organizationId,
                ]);
            }

            return $userArea;
        });
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles', 'user_id', 'role_id');
    }

    public function plants(): BelongsToMany
    {
        return $this->belongsToMany(Plant::class, 'user_plants', 'user_id', 'plant_id');
    }

    public function areas(): BelongsToMany
    {
        return $this->belongsToMany(Area::class, 'user_areas', 'user_id', 'area_id');
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(Inspection::class, 'user_id');
    }

    public function supervisedInspections(): HasMany
    {
        return $this->hasMany(Inspection::class, 'supervisor_id');
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class, 'reported_by');
    }

    public function correctiveActions(): HasMany
    {
        return $this->hasMany(CorrectiveAction::class, 'assigned_to');
    }

    public function correctiveActionUpdates(): HasMany
    {
        return $this->hasMany(CorrectiveActionUpdate::class, 'user_id');
    }

    public function evidences(): HasMany
    {
        return $this->hasMany(Evidence::class, 'uploaded_by');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'user_id');
    }

    public function checklistVersions(): HasMany
    {
        return $this->hasMany(ChecklistVersion::class, 'created_by');
    }
}
