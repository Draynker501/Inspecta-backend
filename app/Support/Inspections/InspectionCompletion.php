<?php

namespace App\Support\Inspections;

use App\Models\Inspection;
use App\Models\User;
use App\Policies\InspectionPolicy;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

final class InspectionCompletion
{
    /** La transacción debe iniciarse aquí, sin snapshot heredado del caller. */
    public function complete(User $actor, string $inspectionId): Inspection
    {
        if (DB::connection()->transactionLevel() !== 0) {
            throw new LogicException('La finalización requiere iniciar su propia transacción.');
        }

        $context = app(TenantContext::class);
        $organizationId = $context->requireId();

        return DB::transaction(function () use ($actor, $inspectionId, $context, $organizationId): Inspection {
            // Primera lectura dentro de la transacción: bloqueo antes del snapshot.
            $inspection = Inspection::query()
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->findOrFail($inspectionId);

            $context->flushPermissionCache($actor->id, $organizationId);
            abort_unless(app(InspectionPolicy::class)->answer($actor, $inspection), 403);

            if ($inspection->status !== 'draft') {
                throw ValidationException::withMessages([
                    'inspection' => 'La inspección no admite cambios en su estado actual.',
                ]);
            }

            InspectionCompleteness::assertComplete($inspection);
            $inspection->status = 'completed';
            $inspection->completed_at = now();
            $inspection->save();

            $context->withAuditActor($actor->id, fn () => app(AuditLogger::class)->record(
                'inspection.completed',
                $inspection,
                ['status' => 'draft', 'completed_at' => null],
                ['status' => 'completed', 'completed_at' => $inspection->completed_at->toISOString()],
            ));

            return $inspection;
        }, 1);
    }
}
