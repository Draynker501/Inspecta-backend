<?php

namespace App\Http\Requests\Administration;

use App\Authorization\Permission;
use App\Services\AdministrationSecurityService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class ReplaceUserLocationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        app(AdministrationSecurityService::class)->authorize(
            $this->user(),
            Permission::UsersManage,
            Permission::PlantsManage,
            Permission::PlantsView
        );

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

        foreach (['plant_ids', 'area_ids'] as $field) {
            if (
                property_exists($decoded, $field)
                && ! is_array($decoded->{$field})
            ) {
                throw ValidationException::withMessages([
                    $field => [
                        'Debe ser un arreglo JSON.',
                    ],
                ]);
            }
        }

        return true;
    }

    public function validationData(): array
    {
        $data = $this->json()->all();

        foreach (['plant_ids', 'area_ids'] as $field) {
            if (is_array($data[$field] ?? null)) {
                $data[$field] = array_map(
                    fn ($id) => is_string($id)
                        ? strtolower($id)
                        : $id,
                    $data[$field]
                );
            }
        }

        return $data;
    }

    public function rules(): array
    {
        return [
            'plant_ids' => [
                'present',
                'array',
                'list',
            ],

            'plant_ids.*' => [
                'required',
                'string',
                'uuid',
                'distinct:strict',
            ],

            'area_ids' => [
                'present',
                'array',
                'list',
            ],

            'area_ids.*' => [
                'required',
                'string',
                'uuid',
                'distinct:strict',
            ],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $allowed = [
                    'plant_ids',
                    'area_ids',
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
}
