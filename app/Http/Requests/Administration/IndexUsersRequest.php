<?php

namespace App\Http\Requests\Administration;

use App\Authorization\Permission;
use App\Services\AdministrationSecurityService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class IndexUsersRequest extends FormRequest
{
    public function authorize(): bool
    {
        app(AdministrationSecurityService::class)
            ->authorizeAny(
                $this->user(),
                Permission::UsersManage,
                Permission::MembershipsManage
            );

        return true;
    }

    public function validationData(): array
    {
        $data = $this->query();

        if (is_string($data['q'] ?? null)) {
            $data['q'] = trim($data['q']);
        }

        return $data;
    }

    public function rules(): array
    {
        return [
            'page' => [
                'sometimes',
                'integer',
                'min:1',
            ],

            'per_page' => [
                'sometimes',
                'integer',
                'min:1',
                'max:100',
            ],

            'q' => [
                'sometimes',
                'nullable',
                'string',
                'max:100',
            ],

            'status' => [
                'sometimes',
                'string',
                Rule::in(['active', 'revoked']),
            ],

            'role_id' => [
                'sometimes',
                'uuid',
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
                        'Este parámetro no está permitido.'
                    );
                }

                $content = trim($this->getContent());

                if (
                    $content !== ''
                    && ! in_array($content, ['[]', '{}'], true)
                ) {
                    $validator->errors()->add(
                        'body',
                        'No se permite un cuerpo con datos en esta petición.'
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
            'page.integer' => 'La página debe ser un número entero.',

            'page.min' => 'La página debe ser mayor o igual a 1.',

            'per_page.integer' => 'La cantidad de registros debe ser un número entero.',

            'per_page.min' => 'Debe solicitarse al menos un registro.',

            'per_page.max' => 'No se pueden solicitar más de 100 registros por página.',

            'q.string' => 'La búsqueda debe ser una cadena de texto.',

            'q.max' => 'La búsqueda no puede superar los 100 caracteres.',

            'status.in' => 'El estado debe ser active o revoked.',

            'role_id.uuid' => 'El identificador del rol no es válido.',
        ];
    }
}
