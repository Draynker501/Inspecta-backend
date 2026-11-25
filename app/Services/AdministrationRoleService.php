<?php

namespace App\Services;

use App\Authorization\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Locations\LocationReadScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdministrationRoleService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AdministrationSecurityService $security,
        private readonly AuditLogger $audit
    ) {}

    private function query(): Builder
    {
        return Role::query()->where('organization_id', $this->context->requireId());
    }

    public function index(User $actor, array $filters = []): array
    {
        $this->security->authorizeAny($actor, Permission::UsersManage, Permission::RolesManage);
        $query = $this->query();
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['q'])) {
            $query->whereRaw("name LIKE ? ESCAPE '!'", ['%'.LocationReadScope::escapeLike(trim($filters['q'])).'%']);
        }
        $page = $query->orderBy('name')->orderBy('id')->paginate(
            $filters['per_page'] ?? 15, ['*'], 'page', $filters['page'] ?? 1
        );

        return [
            'data' => $page->getCollection()->map(fn (Role $role): array => $this->serialize($role))->values()->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ];
    }

    public function show(User $actor, string $roleId): array
    {
        $this->security->authorizeAny($actor, Permission::UsersManage, Permission::RolesManage);

        return $this->serialize($this->query()->whereKey($roleId)->firstOrFail());
    }

    public function permissions(User $actor): array
    {
        $this->security->authorize($actor, Permission::RolesManage);

        return array_map(fn (Permission $permission): array => [
            'key' => $permission->value,
            'assignable' => $actor->hasPermission($permission),
        ], Permission::cases());
    }

    public function create(User $actor, array $data): array
    {
        return $this->security->transaction($actor, function () use ($actor, $data): array {
            $this->security->authorize($actor, Permission::RolesManage);
            $name = trim($data['name']);
            $this->ensureUniqueName($name);
            $role = Role::create([
                'id' => (string) Str::uuid7(),
                'organization_id' => $this->context->requireId(),
                'name' => $name,
                'description' => $data['description'] ?? null,
                'status' => true,
            ]);
            $result = $this->serialize($role);
            $this->audit->record('role.created', $role, [], $result);

            return $result;
        });
    }

    public function update(User $actor, string $roleId, array $data): array
    {
        return $this->mutate($actor, $roleId, 'role.updated', function (Role $role) use ($data): void {
            if (array_key_exists('name', $data)) {
                $name = trim($data['name']);
                $this->ensureUniqueName($name, $role->id);
                $role->name = $name;
            }
            if (array_key_exists('description', $data)) {
                $role->description = $data['description'];
            }
            if ($role->isDirty()) {
                $role->save();
            }
        });
    }

    public function updateStatus(User $actor, string $roleId, bool $status): array
    {
        return $this->mutate($actor, $roleId, 'role.status_updated', function (Role $role) use ($status): void {
            if ($role->status !== $status) {
                $role->status = $status;
                $role->save();
            }
        });
    }

    public function replacePermissions(User $actor, string $roleId, array $permissions): array
    {
        return $this->mutate($actor, $roleId, 'role.permissions_updated', function (Role $role) use ($actor, $permissions): void {
            $this->security->ensureCanDelegatePermissions($actor, $permissions);
            $previous = $this->security->getRolePermissions($role);
            $removed = array_values(array_diff($previous, $permissions));
            $added = array_values(array_diff($permissions, $previous));
            $organizationId = $this->context->requireId();

            RolePermission::query()->where('organization_id', $organizationId)
                ->where('role_id', $role->id)->whereIn('permission', $removed)->delete();

            foreach ($added as $permission) {
                RolePermission::create([
                    'organization_id' => $organizationId,
                    'role_id' => $role->id,
                    'permission' => $permission,
                ]);
            }
            $this->context->flushPermissionCache(null, $organizationId);
        });
    }

    private function mutate(User $actor, string $roleId, string $event, callable $change): array
    {
        return $this->security->transaction($actor, function () use ($actor, $roleId, $event, $change): array {
            $this->security->authorize($actor, Permission::RolesManage);
            $role = $this->query()->whereKey($roleId)->lockForUpdate()->firstOrFail();
            $this->security->ensureCanManageRole($actor, $role);
            $before = $this->serialize($role);
            $change($role);
            $after = $this->serialize($role);
            if ($before !== $after) {
                $this->audit->record($event, $role, $before, $after);
            }

            return $after;
        });
    }

    private function ensureUniqueName(string $name, ?string $except = null): void
    {
        $query = $this->query()->where('name', $name);
        if ($except !== null) {
            $query->where('id', '!=', $except);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages(['name' => ['Ya existe un rol con este nombre.']]);
        }
    }

    private function serialize(Role $role): array
    {
        $organizationId = $this->context->requireId();
        $permissions = $this->security->getRolePermissions($role);
        sort($permissions);
        $count = DB::table('user_roles')
            ->join('organization_memberships', function ($join): void {
                $join->on('organization_memberships.user_id', '=', 'user_roles.user_id')
                    ->on('organization_memberships.organization_id', '=', 'user_roles.organization_id');
            })
            ->where('user_roles.organization_id', $organizationId)
            ->where('user_roles.role_id', $role->id)
            ->where('organization_memberships.status', 'active')
            ->distinct()->count('user_roles.user_id');

        return [
            'id' => $role->id,
            'name' => $role->name,
            'description' => $role->description,
            'system_key' => $role->system_key,
            'status' => (bool) $role->status,
            'permissions' => $permissions,
            'users_count' => $count,
        ];
    }
}
