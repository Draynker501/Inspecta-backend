<?php

namespace App\Http\Requests\Auth;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use JsonException;

class UpdatePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_unless(
            strtolower(
                trim(
                    explode(
                        ';',
                        (string) $this->header('Content-Type')
                    )[0]
                )
            ) === 'application/json',
            415
        );

        try {
            $decoded = json_decode(
                $this->getContent(),
                false,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            throw ValidationException::withMessages([
                'body' => [
                    'El contenido JSON no es válido.',
                ],
            ]);
        }

        if (! is_object($decoded)) {
            throw ValidationException::withMessages([
                'body' => [
                    'El contenido debe ser un objeto JSON.',
                ],
            ]);
        }

        return $this->user() !== null;
    }

    public function validationData(): array
    {
        return json_decode(
            $this->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }

    public function rules(): array
    {
        return [
            'current_password' => [
                'bail',
                'required',
                'string',
                'max:1024',
            ],

            'password' => [
                'bail',
                'required',
                'string',
                'confirmed',
                'different:current_password',

                Password::min(12)
                    ->mixedCase()
                    ->numbers(),

                function (
                    string $attribute,
                    mixed $value,
                    Closure $fail
                ): void {
                    if (
                        is_string($value)
                        && strlen($value) > 72
                    ) {
                        $fail(
                            'La contraseña no puede superar los 72 bytes.'
                        );
                    }

                    if (
                        is_string($value)
                        && str_contains($value, "\0")
                    ) {
                        $fail(
                            'La contraseña contiene caracteres no permitidos.'
                        );
                    }
                },
            ],

            'password_confirmation' => [
                'required',
                'string',
            ],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $allowed = [
                    'current_password',
                    'password',
                    'password_confirmation',
                ];

                foreach (
                    array_diff(
                        array_keys($this->validationData()),
                        $allowed
                    ) as $field
                ) {
                    $validator->errors()->add(
                        (string) $field,
                        'Este campo no está permitido.'
                    );
                }

                if ($this->query->count() > 0) {
                    $validator->errors()->add(
                        'query',
                        'No se permiten parámetros de consulta.'
                    );
                }

                if ($this->allFiles() !== []) {
                    $validator->errors()->add(
                        'files',
                        'No se permiten archivos adjuntos.'
                    );
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'current_password.required' => 'La contraseña actual es obligatoria.',

            'current_password.string' => 'La contraseña actual debe ser una cadena de texto.',

            'current_password.max' => 'La contraseña actual supera la longitud permitida.',

            'password.required' => 'La nueva contraseña es obligatoria.',

            'password.string' => 'La nueva contraseña debe ser una cadena de texto.',

            'password.confirmed' => 'La confirmación de contraseña no coincide.',

            'password.different' => 'La nueva contraseña debe ser diferente de la actual.',

            'password.min' => 'La contraseña debe contener al menos 12 caracteres.',

            'password.mixed' => 'La contraseña debe contener mayúsculas y minúsculas.',

            'password.numbers' => 'La contraseña debe contener al menos un número.',

            'password_confirmation.required' => 'Debes confirmar la nueva contraseña.',

            'password_confirmation.string' => 'La confirmación debe ser una cadena de texto.',
        ];
    }
}
