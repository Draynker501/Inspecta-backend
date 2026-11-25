<?php

namespace App\Http\Requests\Administration;

use App\Authorization\Permission;
use App\Services\AdministrationSecurityService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class ReplaceUserRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        app(AdministrationSecurityService::class)->authorize(
            $this->user(), Permission::UsersManage, Permission::RolesManage
        );
        abort_unless(strtolower(trim(explode(';', (string) $this->header('Content-Type'))[0])) === 'application/json', 415);
        try {
            $decoded = json_decode($this->getContent(), false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::withMessages(['body' => ['El contenido JSON no es válido.']]);
        }
        if (! is_object($decoded)) {
            throw ValidationException::withMessages(['body' => ['El contenido debe ser un objeto JSON.']]);
        }
        if (property_exists($decoded, 'role_ids') && ! is_array($decoded->role_ids)) {
            throw ValidationException::withMessages(['role_ids' => ['Debe ser un arreglo JSON.']]);
        }

        return true;
    }

    public function validationData(): array
    {
        $data = $this->json()->all();
        if (is_array($data['role_ids'] ?? null)) {
            $data['role_ids'] = array_map(fn ($id) => is_string($id) ? strtolower($id) : $id, $data['role_ids']);
        }

        return $data;
    }

    public function rules(): array
    {
        return [
            'role_ids' => ['present', 'array', 'list'],
            'role_ids.*' => ['required', 'string', 'uuid', 'distinct:strict'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->validationData()), ['role_ids']) as $field) {
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
