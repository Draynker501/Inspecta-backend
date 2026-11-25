<?php

namespace App\Http\Requests;

use App\Models\Area;
use App\Models\Plant;
use App\Models\ProductionLine;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class UpdateLocationRequest extends FormRequest
{
    private ?Model $location = null;

    public function authorize(): bool
    {
        Gate::authorize('create', Plant::class);

        if (strtolower(trim(explode(';', (string) $this->header('Content-Type'))[0])) !== 'application/json') {
            abort(415);
        }

        try {
            $decoded = json_decode(
                $this->getContent(),
                false,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException) {
            throw ValidationException::withMessages([
                'body' => 'El contenido JSON no es válido.',
            ]);
        }

        if (! is_object($decoded)) {
            throw ValidationException::withMessages([
                'body' => 'El contenido debe ser un objeto JSON.',
            ]);
        }

        return true;
    }

    public function validationData(): array
    {
        $data = $this->isJson()
            ? $this->json()->all()
            : [];

        if (is_string($data['code'] ?? null)) {
            $data['code'] = strtoupper(trim($data['code']));
        }

        if (is_string($data['name'] ?? null)) {
            $data['name'] = trim($data['name']);
        }

        if (is_string($data['type'] ?? null)) {
            $data['type'] = trim($data['type']);
        }

        return $data;
    }

    public function rules(): array
    {
        $location = $this->resolveLocation();

        $organizationId = app(TenantContext::class)->requireId();

        $uniqueCode = Rule::unique(
            $location->getTable(),
            'code',
        )
            ->where('organization_id', $organizationId)
            ->ignore($location->getKey(), 'id');

        if ($location instanceof Area) {
            $uniqueCode->where(
                'plant_id',
                $location->plant_id,
            );
        }

        if ($location instanceof ProductionLine) {
            $uniqueCode->where(
                'area_id',
                $location->area_id,
            );
        }

        $rules = [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:150',
            ],

            'code' => [
                'bail',
                'sometimes',
                'required',
                'string',
                'max:50',
                'regex:/\A[A-Z0-9-]+\z/',
                $uniqueCode,
            ],

            'description' => [
                'sometimes',
                'nullable',
                'string',
            ],
        ];

        if ($location instanceof Plant) {
            $rules['address'] = [
                'sometimes',
                'nullable',
                'string',
            ];
        }

        if ($location instanceof Area) {
            $rules['type'] = [
                'sometimes',
                'nullable',
                'string',
                'max:50',
            ];
        }

        return $rules;
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $data = $this->validationData();

                $allowedFields = $this->allowedFields();

                foreach (
                    array_diff(
                        array_keys($data),
                        $allowedFields,
                    ) as $field
                ) {
                    $validator->errors()->add(
                        (string) $field,
                        'Este campo no está permitido.',
                    );
                }

                if (
                    array_intersect(
                        array_keys($data),
                        $allowedFields,
                    ) === []
                ) {
                    $validator->errors()->add(
                        'body',
                        'Debe proporcionar al menos un campo para actualizar.',
                    );
                }

                if (! $this->isJson()) {
                    $validator->errors()->add(
                        'body',
                        'La solicitud debe utilizar formato JSON.',
                    );
                }

                if ($this->query->count() > 0) {
                    $validator->errors()->add(
                        'query',
                        'No se permiten parámetros de consulta.',
                    );
                }

                if ($this->allFiles() !== []) {
                    $validator->errors()->add(
                        'files',
                        'No se permiten archivos adjuntos.',
                    );
                }
            },
        ];
    }

    private function allowedFields(): array
    {
        $location = $this->resolveLocation();

        return match (true) {
            $location instanceof Plant => [
                'name',
                'code',
                'address',
                'description',
            ],

            $location instanceof Area => [
                'name',
                'code',
                'type',
                'description',
            ],

            $location instanceof ProductionLine => [
                'name',
                'code',
                'description',
            ],

            default => [],
        };
    }

    private function resolveLocation(): Model
    {
        if ($this->location !== null) {
            return $this->location;
        }

        $organizationId = app(TenantContext::class)->requireId();

        if ($this->route('plantId') !== null) {
            return $this->location = Plant::query()
                ->where('organization_id', $organizationId)
                ->whereKey($this->route('plantId'))
                ->firstOrFail();
        }

        if ($this->route('areaId') !== null) {
            return $this->location = Area::query()
                ->where('organization_id', $organizationId)
                ->whereKey($this->route('areaId'))
                ->whereHas('plant')
                ->firstOrFail();
        }

        if ($this->route('productionLineId') !== null) {
            return $this->location = ProductionLine::query()
                ->where('organization_id', $organizationId)
                ->whereKey($this->route('productionLineId'))
                ->whereHas('area.plant')
                ->firstOrFail();
        }

        abort(404);
    }
}
