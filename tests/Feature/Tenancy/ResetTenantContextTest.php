<?php

namespace Tests\Feature\Tenancy;

use App\Authorization\Permission;
use App\Http\Middleware\ResetTenantContext;
use App\Models\RolePermission;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class ResetTenantContextTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    public function test_reset_tenant_context_limpia_cache_de_permisos(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $role = $this->role($org, 'Administrador');
        $user = $this->user($org);

        app(TenantContext::class)->runAs($org->id, function () use ($user, $role): void {
            $user->assignRole($role);
        });

        RolePermission::create([
            'role_id' => $role->id,
            'organization_id' => $org->id,
            'permission' => Permission::PlantsView->value,
        ]);

        $context = app(TenantContext::class);
        $context->set($org->id);

        // Carga la caché mediante el comportamiento público del núcleo.
        $this->assertTrue($user->hasPermission(Permission::PlantsView));
        $this->assertNotNull($context->getPermissionCache($user->id, $org->id));

        $middleware = app(ResetTenantContext::class);
        $request = Request::create('/_test', 'GET');
        $middleware->handle($request, static function ($req) {
            return response('ok');
        });

        $this->assertNull($context->getPermissionCache($user->id, $org->id));
    }
}
