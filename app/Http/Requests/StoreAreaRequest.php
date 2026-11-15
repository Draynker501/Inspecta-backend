<?php

namespace App\Http\Requests;

use App\Models\Area;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class StoreAreaRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('create', Area::class);

        if (strtolower(trim(explode(';', (string) $this->header('Content-Type'))[0])) !== 'application/json') {
            abort(415);
        }

        try {
            $decoded = json_decode($this->getContent(), false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::withMessages(['body' => 'El contenido JSON no es válido.']);
        }

        if (! is_object($decoded)) {
            throw ValidationException::withMessages(['body' => 'El contenido debe ser un objeto JSON.']);
        }

        return true;
    }

    public function validationData(): array
    {
        $data = $this->json()->all();
        if (is_string($data['code'] ?? null)) {
            $data['code'] = strtoupper(trim($data['code']));
        }

        return $data;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'code' => ['bail', 'required', 'string', 'max:50', 'regex:/\A[A-Z0-9-]+\z/'],
            'type' => ['sometimes', 'nullable', 'string', 'max:50'],
            'description' => ['sometimes', 'nullable', 'string'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->validationData()), ['name', 'code', 'type', 'description']) as $field) {
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
