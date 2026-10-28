<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\OrganizationMembership;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['id', 'name', 'email', 'password', 'organization_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use BelongsToOrganization, HasApiTokens, HasFactory, Notifiable;

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
     * Indica si el usuario tiene el rol indicado dentro de su organización.
     */
    public function hasRole(string $role): bool
    {
        return $this->roles()
            ->where('roles.name', $role)
            ->exists();
    }

    /**
     * Administrador de la organización (no existe super-admin global).
     */
    public function isAdmin(): bool
    {
        return $this->hasRole((string) config('tenancy.roles.admin'));
    }

    public function hasPlantAccess(string $plantId): bool
    {
        return $this->plants()->whereKey($plantId)->exists();
    }

    public function hasAreaAccess(string $areaId): bool
    {
        return $this->areas()->whereKey($areaId)->exists();
    }

    /**
     * Asignaciones con organization_id explícito: las tablas pivote lo
     * exigen como NOT NULL y attach() no lo rellenaría.
     */
    public function assignRole(Role $role): UserRole
    {
        return UserRole::firstOrCreate(
            ['user_id' => $this->id, 'role_id' => $role->id],
            ['organization_id' => $this->organization_id],
        );
    }

    public function assignPlant(Plant $plant): UserPlant
    {
        return UserPlant::firstOrCreate(
            ['user_id' => $this->id, 'plant_id' => $plant->id],
            ['organization_id' => $this->organization_id],
        );
    }

    public function assignArea(Area $area): UserArea
    {
        return UserArea::firstOrCreate(
            ['user_id' => $this->id, 'area_id' => $area->id],
            ['organization_id' => $this->organization_id],
        );
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

    /**
     * Doble escritura de membresía (M1.5, P1.4).
     *
     * Después de guardar un User cuya organization_id se creó o cambió,
     * asegura la membresía de (user_id, organization_id):
     *  - si no existe, la crea activa;
     *  - si existe revocada, la reactiva en la misma fila;
     *  - si existe activa, no hace nada;
     *  - no revoca la membresía de la organización anterior.
     *
     * No choca con bootBelongsToOrganization: este hook solo escribe en
     * organization_memberships, tabla que no usa ese trait.
     */
    protected static function booted(): void
    {
        static::saved(function (User $user): void {
            self::ensureMembership($user);
        });
    }

    protected static function ensureMembership(User $user): void
    {
        if (blank($user->organization_id)) {
            return;
        }

        $membership = OrganizationMembership::where('user_id', $user->id)
            ->where('organization_id', $user->organization_id)
            ->first();

        if (!$membership) {
            OrganizationMembership::create([
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'status' => OrganizationMembership::STATUS_ACTIVE,
            ]);
        } elseif ($membership->status === OrganizationMembership::STATUS_REVOKED) {
            $membership->update([
                'status' => OrganizationMembership::STATUS_ACTIVE,
                'revoked_at' => null,
            ]);
        }
    }
}
