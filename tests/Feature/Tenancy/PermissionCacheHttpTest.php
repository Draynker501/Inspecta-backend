<?php

namespace Tests\Feature\Tenancy;

use App\Authorization\Permission;
use App\Models\OrganizationMembership;
use App\Models\RolePermission;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class PermissionCacheHttpTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['api.auth', 'api.tenant'])->get('/_test/perm-check', function (Request $request) {
            $user = $request->user();

            return response()->json([
                'allowed' => $user->hasPermission(Permission::PlantsView),
            ]);
        });
    }

    private function token(User $user): string
    {
        return $user->createToken('tests')->plainTextToken;
    }

    public function test_cache_http_cambio_de_permiso_del_rol_entre_peticiones(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $role = $this->role($org, 'Administrador');
        $user = $this->user($org);

        app(TenantContext::class)->runAs($org->id, function () use ($user, $role) {
            $user->assignRole($role);
        });

        $rp = RolePermission::create([
            'role_id' => $role->id,
            'organization_id' => $org->id,
            'permission' => Permission::PlantsView->value,
        ]);

        $token = $this->token($user);

        // Primera petición HTTP: concedido.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Organization-Id', $org->id)
            ->getJson('/_test/perm-check')
            ->assertOk()
            ->assertJson(['allowed' => true]);

        // Cambiar un permiso del rol entre peticiones.
        $rp->delete();

        // Segunda petición HTTP: denegado (caché invalidada por evento).
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Organization-Id', $org->id)
            ->getJson('/_test/perm-check')
            ->assertOk()
            ->assertJson(['allowed' => false]);
    }

    public function test_cache_http_cambio_de_organizacion_entre_peticiones(): void
    {
        $orgA = $this->clientOrganization('ORG-A');
        $orgB = $this->clientOrganization('ORG-B');

        $roleA = $this->role($orgA, 'Administrador');
        $roleB = $this->role($orgB, 'Supervisor');

        $user = $this->user($orgA);
        OrganizationMembership::firstOrCreate(
            ['user_id' => $user->id, 'organization_id' => $orgB->id],
            ['id' => (string) Str::uuid7(), 'status' => OrganizationMembership::STATUS_ACTIVE],
        );

        app(TenantContext::class)->runAs($orgA->id, function () use ($user, $roleA) {
            $user->assignRole($roleA);
        });
        app(TenantContext::class)->runAs($orgB->id, function () use ($user, $roleB) {
            $user->assignRole($roleB);
        });

        RolePermission::create([
            'role_id' => $roleA->id,
            'organization_id' => $orgA->id,
            'permission' => Permission::PlantsView->value,
        ]);

        $token = $this->token($user);

        // Primera petición en org A: concedido.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Organization-Id', $orgA->id)
            ->getJson('/_test/perm-check')
            ->assertOk()
            ->assertJson(['allowed' => true]);

        // Segunda petición en org B: denegado (no se filtra entre orgs).
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Organization-Id', $orgB->id)
            ->getJson('/_test/perm-check')
            ->assertOk()
            ->assertJson(['allowed' => false]);
    }

    public function test_cache_http_clear_entre_peticiones_tras_cambio_directo_en_bd(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $role = $this->role($org, 'Administrador');
        $user = $this->user($org);

        app(TenantContext::class)->runAs($org->id, function () use ($user, $role) {
            $user->assignRole($role);
        });

        RolePermission::create([
            'role_id' => $role->id,
            'organization_id' => $org->id,
            'permission' => Permission::PlantsView->value,
        ]);

        $token = $this->token($user);

        // Primera petición: concedido (carga la caché).
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Organization-Id', $org->id)
            ->getJson('/_test/perm-check')
            ->assertOk()
            ->assertJson(['allowed' => true]);

        // Cambio directo en BD (sin eventos): la caché queda stale si no se limpia.
        DB::table('role_permissions')
            ->where('role_id', $role->id)
            ->where('organization_id', $org->id)
            ->delete();

        // Segunda petición: si ResetTenantContext::handle limpia la caché,
        // se recarga y refleja el cambio (denegado).
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Organization-Id', $org->id)
            ->getJson('/_test/perm-check')
            ->assertOk()
            ->assertJson(['allowed' => false]);
    }

    public function test_cache_http_revocacion_de_membresia_entre_peticiones(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $role = $this->role($org, 'Administrador');
        $user = $this->user($org);

        app(TenantContext::class)->runAs($org->id, function () use ($user, $role) {
            $user->assignRole($role);
        });

        RolePermission::create([
            'role_id' => $role->id,
            'organization_id' => $org->id,
            'permission' => Permission::PlantsView->value,
        ]);

        $token = $this->token($user);

        // Primera petición: concedido.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Organization-Id', $org->id)
            ->getJson('/_test/perm-check')
            ->assertOk()
            ->assertJson(['allowed' => true]);

        // Revocar membresía entre peticiones.
        $membership = OrganizationMembership::where('user_id', $user->id)
            ->where('organization_id', $org->id)
            ->first();
        $membership->status = OrganizationMembership::STATUS_REVOKED;
        $membership->save();

        // Segunda petición: denegado por membresía revocada (403 del middleware).
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Organization-Id', $org->id)
            ->getJson('/_test/perm-check')
            ->assertForbidden();
    }
}
