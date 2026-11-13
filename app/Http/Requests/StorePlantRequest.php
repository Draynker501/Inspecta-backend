<?php

namespace App\Http\Requests;

use App\Models\Plant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePlantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', Plant::class);
    }

    public function validationData(): array
    {
        $data = $this->isJson() ? $this->json()->all() : [];

        if (is_string($data['code'] ?? null)) {
            $data['code'] = strtoupper(trim($data['code']));
        }

        return $data;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'code' => ['bail', 'required', 'string', 'max:50', 'regex:/\A[A-Z0-9-]+\z/', Rule::unique('plants', 'code')->where('organization_id', app(TenantContext::class)->requireId())],
            'address' => ['sometimes', 'nullable', 'string'],
            'description' => ['sometimes', 'nullable', 'string'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->validationData()), ['name', 'code', 'address', 'description']) as $field) {
                $validator->errors()->add((string) $field, 'Este campo no está permitido.');
            }

            if ($this->query->count() > 0) {
                $validator->errors()->add('query', 'No se permiten parámetros de consulta.');
            }

            if ($this->allFiles() !== []) {
                $validator->errors()->add('files', 'No se permiten archivos adjuntos.');
            }
        }];
    }
}
