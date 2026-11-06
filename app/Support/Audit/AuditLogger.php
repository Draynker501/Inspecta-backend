<?php

namespace App\Support\Audit;

use App\Models\AuditLog;
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
     * El actor es el usuario autenticado de la petición actual (o null
     * en consola). Sin contexto organizacional, lanza excepción y no
     * escribe nada.
     */
    public function record(string $action, Model $auditable, array $oldValues = [], array $newValues = []): AuditLog
    {
        $organizationId = app(TenantContext::class)->requireId();

        $actor = Auth::user();

        return AuditLog::withoutTimestamps(function () use ($action, $auditable, $oldValues, $newValues, $organizationId, $actor) {
            return AuditLog::forceCreate([
                'id' => (string) Str::uuid7(),
                'organization_id' => $organizationId,
                'user_id' => $actor?->getAuthIdentifier(),
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
