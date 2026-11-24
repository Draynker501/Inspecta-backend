<?php

namespace App\Services;

use App\Models\ChecklistSection;
use App\Models\ChecklistVersion;
use App\Models\Question;
use App\Support\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class ChecklistOrderingService
{
    private const MAX_ORDER = 2147483647;

    /**
     * Reordena todas las secciones de una versión.
     *
     * Debe ejecutarse dentro de una transacción con
     * los bloqueos de organización, checklist y versión.
     */
    public function reorderSections(
        ChecklistVersion $version,
        array $sectionIds
    ): Collection {
        $this->ensureDraft($version);

        return $this->reorder(
            ChecklistSection::query()
                ->where('organization_id', $version->organization_id)
                ->where('checklist_version_id', $version->id),
            $sectionIds,
            'section_ids',
            'checklist_section.reordered'
        );
    }

    /**
     * Reordena todas las preguntas de una sección.
     */
    public function reorderQuestions(
        ChecklistVersion $version,
        ChecklistSection $section,
        array $questionIds
    ): Collection {
        $this->ensureDraft($version);

        if (
            $section->organization_id !== $version->organization_id
            || $section->checklist_version_id !== $version->id
        ) {
            throw ValidationException::withMessages([
                'section' => 'La sección no pertenece a la versión.',
            ]);
        }

        return $this->reorder(
            Question::query()
                ->where('organization_id', $section->organization_id)
                ->where('checklist_section_id', $section->id),
            $questionIds,
            'question_ids',
            'question.reordered'
        );
    }

    /**
     * Normaliza todas las secciones y preguntas de una versión,
     * conservando su orden relativo.
     */
    public function normalizeVersion(
        ChecklistVersion $version
    ): void {
        $this->ensureDraft($version);

        $sections = $this->reorder(
            ChecklistSection::query()
                ->where('organization_id', $version->organization_id)
                ->where('checklist_version_id', $version->id),
            null,
            'section_ids',
            'checklist_section.reordered'
        );

        foreach ($sections as $section) {
            $this->reorder(
                Question::query()
                    ->where('organization_id', $version->organization_id)
                    ->where('checklist_section_id', $section->id),
                null,
                'question_ids',
                'question.reordered'
            );
        }
    }

    /**
     * Implementación reutilizable de ordenamiento.
     *
     * Cuando $requestedIds es null, se conserva el orden
     * relativo actual y únicamente se eliminan los saltos.
     */
    private function reorder(
        Builder $query,
        ?array $requestedIds,
        string $field,
        string $auditEvent
    ): Collection {
        if (DB::transactionLevel() === 0) {
            throw new LogicException(
                'El reordenamiento requiere una transacción activa.'
            );
        }

        $records = (clone $query)
            ->orderBy('display_order')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $currentIds = $records
            ->pluck('id')
            ->all();

        $requestedIds ??= $currentIds;

        // La lista debe contener exactamente todos los elementos.
        if (
            ! array_is_list($requestedIds)
            || count($requestedIds) !== count($currentIds)
            || count(array_unique($requestedIds)) !== count($requestedIds)
            || array_diff($requestedIds, $currentIds) !== []
            || array_diff($currentIds, $requestedIds) !== []
        ) {
            throw ValidationException::withMessages([
                $field => 'Debes proporcionar todos los elementos del grupo, sin duplicados ni identificadores ajenos. Actualiza el listado e inténtalo nuevamente.',
            ]);
        }

        $recordsById = $records->keyBy('id');

        $changes = [];

        foreach ($requestedIds as $index => $id) {
            $record = $recordsById->get($id);

            $newOrder = $index + 1;
            $oldOrder = (int) $record->display_order;

            if ($oldOrder !== $newOrder) {
                $changes[$id] = [
                    'old' => $oldOrder,
                    'new' => $newOrder,
                ];
            }
        }

        // Evitar escrituras y auditorías innecesarias.
        if ($changes === []) {
            return $records;
        }

        $total = count($requestedIds);

        if ($total > self::MAX_ORDER) {
            throw ValidationException::withMessages([
                $field => 'La cantidad de elementos supera el límite permitido.',
            ]);
        }

        // Reservar todas las posiciones actuales.
        $occupied = [];

        foreach ($records as $record) {
            $occupied[(int) $record->display_order] = true;
        }

        /*
         * Primera etapa:
         * mover los registros afectados a posiciones temporales
         * que no estén ocupadas y sean superiores al rango final.
         *
         * Se buscan posiciones libres en lugar de utilizar max + N,
         * evitando desbordamientos cuando existen órdenes muy altos.
         */
        $temporaryOrders = [];
        $candidate = $total + 1;

        foreach ($changes as $id => $change) {
            while (
                $candidate <= self::MAX_ORDER
                && isset($occupied[$candidate])
            ) {
                $candidate++;
            }

            if ($candidate > self::MAX_ORDER) {
                throw ValidationException::withMessages([
                    $field => 'No hay posiciones temporales disponibles para completar el reordenamiento.',
                ]);
            }

            $temporaryOrders[$id] = $candidate;
            $occupied[$candidate] = true;

            $candidate++;
        }

        foreach ($temporaryOrders as $id => $temporaryOrder) {
            (clone $query)
                ->where('id', $id)
                ->update([
                    'display_order' => $temporaryOrder,
                ]);
        }

        /*
         * Segunda etapa:
         * asignar las posiciones definitivas.
         */
        foreach ($changes as $id => $change) {
            $record = $recordsById->get($id);

            $record->display_order = $change['new'];
            $record->save();

            app(AuditLogger::class)->record(
                $auditEvent,
                $record,
                [
                    'display_order' => $change['old'],
                ],
                [
                    'display_order' => $change['new'],
                ]
            );
        }

        return (clone $query)
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * Impide modificar versiones publicadas o archivadas.
     */
    private function ensureDraft(
        ChecklistVersion $version
    ): void {
        if (
            $version->status !== 'draft'
            || $version->published_at !== null
        ) {
            throw ValidationException::withMessages([
                'version' => 'Solo se pueden modificar versiones en borrador nunca publicadas.',
            ]);
        }
    }
}
