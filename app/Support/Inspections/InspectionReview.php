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

final class InspectionReview
{
    public function review(User $actor, string $inspectionId, array $input): Inspection
    {
        if (DB::connection()->transactionLevel() !== 0) {
            throw new LogicException('La revisión requiere iniciar su propia transacción.');
        }

        $context = app(TenantContext::class);
        $organizationId = $context->requireId();

        return DB::transaction(function () use ($actor, $inspectionId, $input, $context, $organizationId): Inspection {
            $inspection = Inspection::query()->where('organization_id', $organizationId)
                ->lockForUpdate()->findOrFail($inspectionId);

            $context->flushPermissionCache($actor->id, $organizationId);
            $actor = User::query()->findOrFail($actor->id);
            abort_unless(app(InspectionPolicy::class)->review($actor, $inspection), 403);

            $action = $input['action'] ?? null;
            $observation = $input['supervisor_observations'] ?? null;
            $valid = ($action === 'confirm' && count($input) === 1)
                || ($action === 'reject' && count($input) === 2
                    && array_key_exists('supervisor_observations', $input)
                    && is_string($observation) && strlen($observation) <= 65535
                    && preg_match('/[^\s\p{Z}\x{0085}]/u', $observation) === 1);
            if (! $valid) {
                throw ValidationException::withMessages(['fields' => 'Datos de revisión inválidos.']);
            }

            if ($inspection->status !== 'completed'
                || $inspection->supervisor_id !== null
                || $inspection->supervisor_confirmed_at !== null
                || $inspection->rejected_at !== null
                || $inspection->supervisor_observations !== null) {
                throw ValidationException::withMessages(['inspection' => 'La inspección no admite revisión en su estado actual.']);
            }

            $status = $action === 'confirm' ? 'confirmed' : 'rejected';
            $dateField = $action === 'confirm' ? 'supervisor_confirmed_at' : 'rejected_at';
            $inspection->status = $status;
            $inspection->supervisor_id = $actor->id;
            $inspection->$dateField = now();
            if ($action === 'reject') {
                $inspection->supervisor_observations = $observation;
            }
            $inspection->save();

            $context->withAuditActor($actor->id, fn () => app(AuditLogger::class)->record(
                'inspection.'.$status,
                $inspection,
                ['status' => 'completed'],
                ['status' => $status, $dateField => $inspection->$dateField->toISOString()],
            ));

            return $inspection;
        }, 1);
    }
}
