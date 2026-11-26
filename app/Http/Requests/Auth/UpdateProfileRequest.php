<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use JsonException;

class UpdateProfileRequest extends FormRequest
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
        $data = json_decode(
            $this->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        if (is_string($data['name'] ?? null)) {
            $data['name'] = trim($data['name']);
        }

        if (is_string($data['email'] ?? null)) {
            $data['email'] = Str::lower(
                trim($data['email'])
            );
        }

        return $data;
    }

    public function rules(): array
    {
        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:150',
            ],

            'email' => [
                'sometimes',
                'required',
                'string',
                'email:rfc',
                'max:255',
                Rule::unique('users', 'email')
                    ->ignore($this->user()->getKey()),
            ],

            'current_password' => [
                'sometimes',
                'required',
                'string',
                'max:1024',
            ],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $data = $this->validationData();

                $allowed = [
                    'name',
                    'email',
                    'current_password',
                ];

                foreach (
                    array_diff(
                        array_keys($data),
                        $allowed
                    ) as $field
                ) {
                    $validator->errors()->add(
                        (string) $field,
                        'Este campo no está permitido.'
                    );
                }

                if (
                    ! array_key_exists('name', $data)
                    && ! array_key_exists('email', $data)
                ) {
                    $validator->errors()->add(
                        'body',
                        'Debes proporcionar al menos un campo para actualizar.'
                    );
                }

                if (
                    is_string($data['email'] ?? null)
                    && $data['email'] !== $this->user()->email
                    && ! array_key_exists(
                        'current_password',
                        $data
                    )
                ) {
                    $validator->errors()->add(
                        'current_password',
                        'Debes proporcionar tu contraseña actual para modificar el correo electrónico.'
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
            'name.required' => 'El nombre no puede estar vacío.',

            'name.string' => 'El nombre debe ser una cadena de texto.',

            'name.max' => 'El nombre no puede superar los 150 caracteres.',

            'email.required' => 'El correo electrónico no puede estar vacío.',

            'email.string' => 'El correo electrónico debe ser una cadena de texto.',

            'email.email' => 'El formato del correo electrónico no es válido.',

            'email.max' => 'El correo electrónico no puede superar los 255 caracteres.',

            'email.unique' => 'El correo electrónico ya está registrado.',

            'current_password.required' => 'La contraseña actual es obligatoria.',

            'current_password.string' => 'La contraseña actual debe ser una cadena de texto.',

            'current_password.max' => 'La contraseña actual supera la longitud permitida.',
        ];
    }
}
