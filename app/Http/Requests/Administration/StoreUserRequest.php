<?php

namespace App\Http\Requests\Administration;

use App\Authorization\Permission;
use App\Services\AdministrationSecurityService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        app(AdministrationSecurityService::class)
            ->authorize(
                $this->user(),
                Permission::UsersManage,
                Permission::MembershipsManage
            );

        $contentType = strtolower(
            trim(
                explode(
                    ';',
                    (string) $this->header('Content-Type')
                )[0]
            )
        );

        abort_unless(
            $contentType === 'application/json',
            415
        );

        try {
            $decoded = json_decode(
                $this->getContent(),
                false,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException) {
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

        return true;
    }

    public function validationData(): array
    {
        $data = $this->json()->all();

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
                'bail',
                'required',
                'string',
                'max:150',
            ],

            'email' => [
                'bail',
                'required',
                'string',
                'email:rfc',
                'max:255',
            ],

            'password' => [
                'bail',
                'required',
                'string',
                'confirmed',

                Password::min(12)
                    ->mixedCase()
                    ->numbers(),

                function (
                    string $attribute,
                    mixed $value,
                    \Closure $fail
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
                $allowed = array_keys($this->rules());

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
            'name.required' => 'El nombre del usuario es obligatorio.',

            'name.string' => 'El nombre debe ser una cadena de texto.',

            'name.max' => 'El nombre no puede superar los 150 caracteres.',

            'email.required' => 'El correo electrónico es obligatorio.',

            'email.email' => 'El formato del correo electrónico no es válido.',

            'email.max' => 'El correo no puede superar los 255 caracteres.',

            'password.required' => 'La contraseña es obligatoria.',

            'password.string' => 'La contraseña debe ser una cadena de texto.',

            'password.confirmed' => 'La confirmación de contraseña no coincide.',

            'password_confirmation.required' => 'Debes confirmar la contraseña.',
        ];
    }
}
