<?php

namespace App\Http\Requests\Administration;

use App\Authorization\Permission;
use App\Services\AdministrationSecurityService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class RoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $security = app(AdministrationSecurityService::class);
        if ($this->isMethod('GET') && $this->route()->getActionMethod() !== 'permissions') {
            $security->authorizeAny($this->user(), Permission::UsersManage, Permission::RolesManage);
        } else {
            $security->authorize($this->user(), Permission::RolesManage);
        }

        if (! $this->isMethod('GET')) {
            abort_unless(strtolower(trim(explode(';', (string) $this->header('Content-Type'))[0])) === 'application/json', 415);
            try {
                $decoded = json_decode($this->getContent(), false, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw ValidationException::withMessages(['body' => ['El contenido JSON no es válido.']]);
            }
            if (! is_object($decoded)) {
                throw ValidationException::withMessages(['body' => ['El contenido debe ser un objeto JSON.']]);
            }
            if ($this->route()->getActionMethod() === 'replacePermissions' && property_exists($decoded, 'permissions') && ! is_array($decoded->permissions)) {
                throw ValidationException::withMessages(['permissions' => ['Debe ser un arreglo JSON.']]);
            }
        }

        return true;
    }

    public function validationData(): array
    {
        $data = $this->isMethod('GET') ? $this->query() : $this->json()->all();
        foreach (['name', 'q'] as $key) {
            if (is_string($data[$key] ?? null)) {
                $data[$key] = trim($data[$key]);
            }
        }

        return $data;
    }

    public function rules(): array
    {
        return match ($this->route()->getActionMethod()) {
            'index' => [
                'page' => ['sometimes', 'integer', 'min:1'],
                'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
                'q' => ['sometimes', 'nullable', 'string', 'max:100'],
                'status' => ['sometimes', 'boolean'],
            ],
            'store' => [
                'name' => ['required', 'string', 'max:50'],
                'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            ],
            'update' => [
                'name' => ['sometimes', 'required', 'string', 'max:50'],
                'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            ],
            'updateStatus' => ['status' => ['required', 'boolean']],
            'replacePermissions' => [
                'permissions' => ['present', 'array', 'list', 'max:'.count(Permission::cases())],
                'permissions.*' => ['required', 'string', 'distinct:strict', Rule::in(Permission::values())],
            ],
            default => [],
        };
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $allowed = array_filter(array_keys($this->rules()), fn ($key) => ! str_contains($key, '.'));
            foreach (array_diff(array_keys($this->validationData()), $allowed) as $field) {
                $validator->errors()->add((string) $field, 'Este campo no está permitido.');
            }
            if ($this->isMethod('GET')) {
                if (! in_array(trim($this->getContent()), ['', '[]', '{}'], true)) {
                    $validator->errors()->add('body', 'No se permite un cuerpo con datos.');
                }
            } elseif ($this->query->count() > 0) {
                $validator->errors()->add('query', 'No se permiten parámetros de consulta.');
            }
            if ($this->allFiles() !== []) {
                $validator->errors()->add('files', 'No se permiten archivos adjuntos.');
            }
            if ($this->route()->getActionMethod() === 'update' && $this->validationData() === []) {
                $validator->errors()->add('body', 'Debes proporcionar al menos un campo.');
            }
            if ($this->route()->getActionMethod() === 'updateStatus' && ! is_bool($this->validationData()['status'] ?? null)) {
                $validator->errors()->add('status', 'El estado debe ser un booleano JSON.');
            }
        }];
    }
}
