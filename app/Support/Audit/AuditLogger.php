<?php

namespace App\Support\Audit;

use App\Models\AuditLog;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class AuditLogger
{
    /**
     * Registra un evento de auditoría en la organización activa.
     *
     * El organization_id sale siempre de TenantContext::requireId().
     * El actor es el explícito si existe un contexto de auditoría; en otro
     * caso es el usuario autenticado (o null). Sin contexto organizacional,
     * lanza excepción y no escribe nada.
     */
    public function record(string $action, Model $auditable, array $oldValues = [], array $newValues = []): AuditLog
    {
        $context = app(TenantContext::class);
        $organizationId = $context->requireId();
        if ($context->isTenantExecution()) {
            if ($auditable instanceof Organization && $auditable->getKey() !== $organizationId) {
                throw new \InvalidArgumentException('El auditable pertenece a otra organización.');
            }
            if (! $auditable instanceof User && (array_key_exists('organization_id', $auditable->getAttributes())
                || $auditable instanceof OrganizationMembership
                || in_array(BelongsToOrganization::class, class_uses_recursive($auditable), true))
                && $auditable->getAttribute('organization_id') !== $organizationId) {
                throw new \InvalidArgumentException('El auditable pertenece a otra organización.');
            }
        }
        $actorId = $context->hasAuditActor()
            ? $context->auditActorId()
            : Auth::user()?->getAuthIdentifier();

        return AuditLog::withoutTimestamps(function () use ($action, $auditable, $oldValues, $newValues, $organizationId, $actorId) {
            return AuditLog::forceCreate([
                'id' => (string) Str::uuid7(),
                'organization_id' => $organizationId,
                'user_id' => $actorId,
                'action' => $action,
                'auditable_type' => get_class($auditable),
                'auditable_id' => (string) $auditable->getKey(),
                'old_values' => $this->redact($oldValues),
                'new_values' => $this->redact($newValues),
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Redacta recursivamente claves sensibles.
     */
    private function redact(array $values): array
    {
        $redacted = [];

        foreach ($values as $key => $value) {
            if (is_string($key) && preg_match('/password|token|secret|authorization|remember/i', $key)) {
                $redacted[$key] = '[redacted]';

                continue;
            }

            if (is_array($value)) {
                $redacted[$key] = $this->redact($value);

                continue;
            }

            $redacted[$key] = $value;
        }

        return $redacted;
    }
}
