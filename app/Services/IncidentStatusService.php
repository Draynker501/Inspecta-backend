<?php

namespace App\Services;

use App\Models\Incident;
use App\Models\CorrectiveAction;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

class IncidentStatusService
{
    private const TRANSITIONS = [
        'open' => ['in_progress'],
        'in_progress' => ['resolved'],
        'resolved' => ['closed'],
        'closed' => [],
    ];

    /**
     * Obtiene las acciones correctivas de la incidencia
     * dentro de la organización activa.
     */
    private function actions(Incident $incident)
    {
        $organizationId = app(TenantContext::class)->requireId();

        abort_unless(
            $incident->organization_id === $organizationId,
            404
        );

        return CorrectiveAction::query()
            ->where('organization_id', $organizationId)
            ->where('incident_id', $incident->id);
    }

    /**
     * Determina si la incidencia tiene acciones correctivas.
     */
    public function hasActions(Incident $incident): bool
    {
        return $this->actions($incident)->exists();
    }

    /**
     * Comprueba que no existan acciones pendientes
     * o en proceso.
     */
    public function allActionsCompleted(Incident $incident): bool
    {
        return !$this->actions($incident)
            ->whereNotIn('status', ['completed', 'verified'])
            ->exists();
    }

    /**
     * Comprueba que todas las acciones estén verificadas.
     */
    public function allActionsVerified(Incident $incident): bool
    {
        return !$this->actions($incident)
            ->where('status', '!=', 'verified')
            ->exists();
    }

    /**
     * Valida una transición manual de la incidencia.
     */
    public function validateTransition(
        Incident $incident,
        string $requested
    ): void {
        $current = $incident->status;

        if ($current === $requested) {
            return;
        }

        if (
            !in_array(
                $requested,
                self::TRANSITIONS[$current] ?? [],
                true
            )
        ) {
            throw ValidationException::withMessages([
                'status' => 'La transición de estado no está permitida.',
            ]);
        }

        if (
            $requested === 'resolved'
            && !$this->allActionsCompleted($incident)
        ) {
            throw ValidationException::withMessages([
                'status' => 'No se puede resolver la incidencia mientras existan acciones correctivas pendientes o en proceso.',
            ]);
        }

        if (
            $requested === 'closed'
            && !$this->allActionsVerified($incident)
        ) {
            throw ValidationException::withMessages([
                'status' => 'No se puede cerrar la incidencia mientras existan acciones correctivas sin verificar.',
            ]);
        }
    }

    /**
     * Valida que se puedan incorporar nuevas acciones.
     */
    public function validateActionCreation(
        Incident $incident
    ): void {
        if (
            in_array(
                $incident->status,
                ['resolved', 'closed'],
                true
            )
        ) {
            throw ValidationException::withMessages([
                'incident_id' => 'No se pueden agregar acciones correctivas a una incidencia resuelta o cerrada.',
            ]);
        }
    }

    /**
     * Sincroniza automáticamente el estado de la incidencia.
     *
     * Debe ejecutarse dentro de una transacción que
     * mantenga bloqueada la organización correspondiente.
     */
    public function synchronize(
        Incident $incident,
        ?string $correctiveActionId = null
    ): void {
        if (
            in_array(
                $incident->status,
                ['resolved', 'closed'],
                true
            )
        ) {
            return;
        }

        if (!$this->hasActions($incident)) {
            return;
        }

        // Si alguna acción ya comenzó, iniciar seguimiento.
        if ($incident->status === 'open') {
            $hasStartedActions = $this->actions($incident)
                ->whereIn('status', [
                    'in_progress',
                    'completed',
                    'verified',
                ])
                ->exists();

            if ($hasStartedActions) {
                $this->changeAutomatically(
                    $incident,
                    'in_progress',
                    $correctiveActionId
                );
            }
        }

        // Resolver cuando todas las acciones estén completadas.
        if (
            $incident->status === 'in_progress'
            && $this->allActionsCompleted($incident)
        ) {
            $this->changeAutomatically(
                $incident,
                'resolved',
                $correctiveActionId
            );
        }

        // El cierre siempre será manual.
    }

    /**
     * Actualiza y registra una transición automática.
     */
    private function changeAutomatically(
        Incident $incident,
        string $status,
        ?string $correctiveActionId
    ): void {
        $before = $incident->status;

        if ($before === $status) {
            return;
        }

        $this->validateTransition($incident, $status);

        $incident->update([
            'status' => $status,
        ]);

        app(AuditLogger::class)->record(
            'incident.status_changed',
            $incident,
            [
                'status' => $before,
            ],
            [
                'status' => $status,
                'automatic' => true,
                'source' => 'corrective_action',
                'corrective_action_id' => $correctiveActionId,
            ]
        );
    }
}