<?php

namespace App\Http\Requests;

use App\Models\Plant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class UpdateLocationStatusRequest extends FormRequest
{
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
        return $this->isJson()
            ? $this->json()->all()
            : [];
    }

    public function rules(): array
    {
        return [
            'status' => [
                'bail',
                'required',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_bool($value)) {
                        $fail(
                            'El campo status debe ser un valor booleano.',
                        );
                    }
                },
            ],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $data = $this->validationData();

                foreach (
                    array_diff(
                        array_keys($data),
                        ['status'],
                    ) as $field
                ) {
                    $validator->errors()->add(
                        (string) $field,
                        'Este campo no está permitido.',
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
}
