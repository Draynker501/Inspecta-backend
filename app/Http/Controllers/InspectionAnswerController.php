<?php

namespace App\Http\Controllers;

use App\Models\Inspection;
use App\Models\InspectionAnswer;
use App\Models\Question;
use App\Models\User;
use App\Policies\InspectionPolicy;
use App\Support\Audit\AuditLogger;
use App\Support\Inspections\InspectionAnswerValidator;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Respuestas de una inspección en borrador.
 *
 * La identidad funcional es (inspección, pregunta): `PUT` reemplaza el
 * contenido editable completo y `DELETE` es idempotente. No se crean filas
 * vacías ni se admite más de una respuesta por pregunta.
 */
class InspectionAnswerController extends TenantController
{
    public function index(Request $request, string $inspectionId): JsonResponse
    {
        Gate::authorize('viewAny', Inspection::class);

        // La visibilidad del recurso se resuelve antes de validar la
        // paginación: una inspección no visible responde 404 con independencia
        // de los parámetros de consulta, de modo que la ruta de denegación no
        // sea distinguible por el código de respuesta.
        $inspection = $this->resolveInspection($request->user(), $inspectionId);

        if (array_diff(array_keys($request->query()), ['page', 'per_page']) !== []) {
            throw ValidationException::withMessages(['fields' => 'La solicitud contiene campos no permitidos.']);
        }

        $data = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $organizationId = app(TenantContext::class)->requireId();

        $page = InspectionAnswer::query()
            ->where('organization_id', $organizationId)
            ->where('inspection_id', $inspection->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate($data['per_page'] ?? 100, ['*'], 'page', $data['page'] ?? 1);

        return response()->json([
            'data' => array_map($this->dto(...), $page->items()),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function upsert(Request $request, string $inspectionId, string $questionId): JsonResponse
    {
        Gate::authorize('viewAny', Inspection::class);

        $inspection = $this->resolveInspection($request->user(), $inspectionId);
        $this->assertWritable($request->user(), $inspection);

        $this->assertNoQueryParameters($request);

        // El cuerpo es la única fuente del valor: los parámetros de consulta
        // no pueden completar ni eludir el contrato JSON cerrado.
        $body = json_decode($request->getContent(), true);
        if (! $request->isJson() || ! is_array($body) || (array_is_list($body) && $body !== [])) {
            throw ValidationException::withMessages(['fields' => 'La solicitud contiene campos no permitidos o no es un objeto JSON.']);
        }

        if (array_diff(array_keys($body), ['answer', 'observation']) !== []) {
            throw ValidationException::withMessages(['fields' => 'La solicitud contiene campos no permitidos o no es un objeto JSON.']);
        }

        $data = Validator::make($body, [
            'answer' => ['required', 'string'],
            'observation' => ['sometimes', 'nullable', 'string', static function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && strlen($value) > 65535) {
                    $fail('La observación supera 65535 bytes.');
                }
            }],
        ])->validate();

        $answer = DB::transaction(function () use ($request, $inspection, $questionId, $data): InspectionAnswer {
            $context = app(TenantContext::class);
            $organizationId = $context->requireId();

            // Bloqueo proporcional: la inspección serializa sus escrituras.
            $locked = Inspection::query()
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->findOrFail($inspection->id);

            $context->flushPermissionCache($request->user()->id, $organizationId);
            $this->assertWritable($request->user(), $locked);
            $this->assertDraft($locked);

            $question = $this->resolveQuestion($locked, $questionId, $organizationId);
            if (! $question->status) {
                throw ValidationException::withMessages(['question' => 'La pregunta no está activa.']);
            }

            InspectionAnswerValidator::validate($question, $data['answer']);
            $observation = $data['observation'] ?? null;

            $existing = InspectionAnswer::query()
                ->where('organization_id', $organizationId)
                ->where('inspection_id', $locked->id)
                ->where('question_id', $question->id)
                ->first();

            if ($existing === null) {
                $answer = InspectionAnswer::create([
                    'id' => (string) Str::uuid7(),
                    'organization_id' => $organizationId,
                    'inspection_id' => $locked->id,
                    'question_id' => $question->id,
                    'checklist_version_id' => $locked->checklist_version_id,
                    'checklist_section_id' => $question->checklist_section_id,
                    'answer' => $data['answer'],
                    'observation' => $observation,
                ]);

                app(AuditLogger::class)->record('inspection.answer_created', $answer, [], $this->auditMetadata($answer, $question));

                return $answer;
            }

            $changed = [];
            if ($existing->answer !== $data['answer']) {
                $changed[] = 'answer';
            }
            if ($existing->observation !== $observation) {
                $changed[] = 'observation';
            }

            if ($changed === []) {
                return $existing;
            }

            $before = $this->auditMetadata($existing, $question);
            $existing->answer = $data['answer'];
            $existing->observation = $observation;
            $existing->save();

            app(AuditLogger::class)->record(
                'inspection.answer_updated',
                $existing,
                $before,
                $this->auditMetadata($existing, $question) + ['changed' => $changed],
            );

            return $existing;
        });

        return response()->json(['data' => $this->dto($answer)]);
    }

    public function destroy(Request $request, string $inspectionId, string $questionId): Response
    {
        Gate::authorize('viewAny', Inspection::class);

        $inspection = $this->resolveInspection($request->user(), $inspectionId);
        $this->assertWritable($request->user(), $inspection);

        $this->assertNoQueryParameters($request);

        DB::transaction(function () use ($request, $inspection, $questionId): void {
            $context = app(TenantContext::class);
            $organizationId = $context->requireId();

            $locked = Inspection::query()
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->findOrFail($inspection->id);

            $context->flushPermissionCache($request->user()->id, $organizationId);
            $this->assertWritable($request->user(), $locked);
            $this->assertDraft($locked);

            $question = $this->resolveQuestion($locked, $questionId, $organizationId);
            if (! $question->status) {
                throw ValidationException::withMessages(['question' => 'La pregunta no está activa.']);
            }

            $existing = InspectionAnswer::query()
                ->where('organization_id', $organizationId)
                ->where('inspection_id', $locked->id)
                ->where('question_id', $question->id)
                ->first();

            if ($existing === null) {
                return;
            }

            // La FK entrante de evidencias es RESTRICT: se rechaza antes de
            // llegar al motor para no responder 500 ni borrar evidencias.
            if (DB::table('evidences')->where('inspection_answer_id', $existing->id)->exists()) {
                throw ValidationException::withMessages([
                    'answer' => 'La respuesta tiene evidencias asociadas y no puede eliminarse.',
                ]);
            }

            $before = $this->auditMetadata($existing, $question);
            try {
                $existing->delete();
            } catch (QueryException $exception) {
                // Defensa adicional: si una evidencia se insertó entre la
                // comprobación y el DELETE, la FK RESTRICT impide el borrado.
                // Solo se traduce esa causa; cualquier otro error SQL se
                // propaga para no ocultar defectos de integridad.
                if ($this->isEvidencesForeignKeyViolation($exception, $existing)) {
                    throw ValidationException::withMessages([
                        'answer' => 'La respuesta tiene evidencias asociadas y no puede eliminarse.',
                    ]);
                }

                throw $exception;
            }

            app(AuditLogger::class)->record('inspection.answer_deleted', $existing, $before, []);
        });

        return response()->noContent();
    }

    private function resolveInspection(User $user, string $inspectionId): Inspection
    {
        $inspection = Inspection::query()
            ->where('organization_id', app(TenantContext::class)->requireId())
            ->findOrFail($inspectionId);

        abort_unless(app(InspectionPolicy::class)->viewAnswers($user, $inspection), 404);

        return $inspection;
    }

    private function assertWritable(User $user, Inspection $inspection): void
    {
        abort_unless(app(InspectionPolicy::class)->answer($user, $inspection), 403);
    }

    private function assertDraft(Inspection $inspection): void
    {
        if ($inspection->status !== 'draft') {
            throw ValidationException::withMessages([
                'inspection' => 'La inspección no admite cambios en su estado actual.',
            ]);
        }
    }

    private function resolveQuestion(Inspection $inspection, string $questionId, string $organizationId): Question
    {
        return Question::query()
            ->where('organization_id', $organizationId)
            ->whereKey($questionId)
            ->whereHas('checklistSection', function ($query) use ($organizationId, $inspection): void {
                $query->where('organization_id', $organizationId)
                    ->where('checklist_version_id', $inspection->checklist_version_id);
            })
            ->firstOrFail();
    }

    /**
     * Auditoría minimizada: identificadores, tipo y presencia de contenido.
     * Nunca valores de respuesta, observaciones ni firmas.
     *
     * @return array<string, mixed>
     */
    private function auditMetadata(InspectionAnswer $answer, Question $question): array
    {
        return [
            'inspection_id' => $answer->inspection_id,
            'question_id' => $answer->question_id,
            'answer_type' => $question->answer_type,
            'answer_present' => $answer->answer !== null && $answer->answer !== '',
            'observation_present' => $answer->observation !== null && $answer->observation !== '',
        ];
    }

    /**
     * Superficie cerrada: las escrituras no aceptan parámetros de consulta.
     *
     * Evita que un parámetro de consulta complete el cuerpo o altere la
     * validación del valor almacenado.
     */
    private function assertNoQueryParameters(Request $request): void
    {
        if ($request->query() !== []) {
            throw ValidationException::withMessages(['fields' => 'La solicitud contiene campos no permitidos.']);
        }
    }

    /**
     * Determina si el error corresponde a la FK entrante de `evidences`.
     *
     * Solo se acepta una violación de clave foránea cuya causa sea la tabla
     * `evidences`: en MariaDB se compara la tabla hija exacta que el motor
     * nombra en el error 1451; en SQLite, que no la nombra, se comprueba qué
     * tablas referenciantes tienen filas apuntando a esta respuesta. Cualquier
     * otro error SQL (otra tabla referenciante, una restricción NOT NULL, un
     * interbloqueo o una espera agotada) se propaga sin traducir.
     */
    private function isEvidencesForeignKeyViolation(QueryException $exception, InspectionAnswer $answer): bool
    {
        if ((string) ($exception->errorInfo[0] ?? '') !== '23000') {
            return false;
        }

        $driver = DB::connection()->getDriverName();
        $message = (string) $exception->getMessage();

        if (in_array($driver, ['mariadb', 'mysql'], true)) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1451) {
                return false;
            }

            // Error 1451: «... foreign key constraint fails (`db`.`tabla`, ...)».
            // Se exige la tabla hija exacta; una tabla como `archived_evidences`
            // no debe confundirse con `evidences`.
            if (preg_match('/foreign key constraint fails \(`(?:[^`]+)`\.`([^`]+)`/i', $message, $matches) !== 1) {
                return false;
            }

            return Str::lower($matches[1]) === 'evidences';
        }

        if ($driver !== 'sqlite' || ! Str::contains(Str::lower($message), 'foreign key constraint failed')) {
            return false;
        }

        return $this->referencingTablesWithRows($answer) === ['evidences'];
    }

    /**
     * Tablas con al menos una fila que referencia esta respuesta.
     *
     * SQLite no identifica la tabla en el mensaje de violación de FK, por lo
     * que se consultan las relaciones reales del esquema y se comprueba qué
     * tablas tienen filas que apuntan a la respuesta.
     *
     * @return list<string>
     */
    private function referencingTablesWithRows(InspectionAnswer $answer): array
    {
        $referencing = [];

        foreach (DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'") as $table) {
            $name = (string) $table->name;
            $quoted = '"'.str_replace('"', '""', $name).'"';

            foreach (DB::select('PRAGMA foreign_key_list('.$quoted.')') as $foreignKey) {
                if (Str::lower((string) $foreignKey->table) !== 'inspection_answers') {
                    continue;
                }

                $column = '"'.str_replace('"', '""', (string) $foreignKey->from).'"';
                if (DB::selectOne('SELECT 1 AS found FROM '.$quoted.' WHERE '.$column.' = ? LIMIT 1', [$answer->id]) !== null) {
                    $referencing[] = Str::lower($name);
                }
            }
        }

        return array_values(array_unique($referencing));
    }

    /**
     * @return array<string, mixed>
     */
    private function dto(InspectionAnswer $answer): array
    {
        return [
            'id' => $answer->id,
            'inspection_id' => $answer->inspection_id,
            'question_id' => $answer->question_id,
            'answer' => $answer->answer,
            'observation' => $answer->observation,
            'created_at' => $answer->created_at?->toISOString(),
            'updated_at' => $answer->updated_at?->toISOString(),
        ];
    }
}
