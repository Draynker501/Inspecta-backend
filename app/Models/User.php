<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'organization_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

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