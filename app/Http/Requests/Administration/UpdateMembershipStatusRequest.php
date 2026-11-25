<?php

namespace App\Http\Requests\Administration;

use App\Authorization\Permission;
use App\Services\AdministrationSecurityService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class UpdateMembershipStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        app(AdministrationSecurityService::class)
            ->authorize(
                $this->user(),
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
        return $this->json()->all();
    }

    public function rules(): array
    {
        return [
            'status' => [
                'bail',
                'required',
                'string',
                Rule::in([
                    'active',
                    'revoked',
                ]),
            ],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach (
                    array_diff(
                        array_keys($this->validationData()),
                        ['status']
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
            'status.required' => 'El estado de la membresía es obligatorio.',

            'status.string' => 'El estado debe ser una cadena de texto.',

            'status.in' => 'El estado debe ser active o revoked.',
        ];
    }
}
