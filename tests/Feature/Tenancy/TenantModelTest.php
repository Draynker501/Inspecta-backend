<?php

namespace Tests\Feature\Tenancy;

use App\Exceptions\CrossTenantOperationException;
use App\Exceptions\MissingTenantContextException;
use App\Models\Area;
use App\Models\AuditLog;
use App\Models\Checklist;
use App\Models\ChecklistSection;
use App\Models\ChecklistVersion;
use App\Models\CorrectiveAction;
use App\Models\CorrectiveActionUpdate;
use App\Models\Device;
use App\Models\Evidence;
use App\Models\Incident;
use App\Models\Inspection;
use App\Models\InspectionAnswer;
use App\Models\Norm;
use App\Models\NormRequirement;
use App\Models\Organization;
use App\Models\Plant;
use App\Models\ProductionLine;
use App\Models\Question;
use App\Models\QuestionNormRequirement;
use App\Models\Role;
use App\Models\SyncChange;
use App\Models\SyncOperation;
use App\Models\User;
use App\Models\UserArea;
use App\Models\UserPlant;
use App\Models\UserRole;
use App\Support\Tenancy\OrganizationScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class TenantModelTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    /**
     * Modelos con organization_id que deben estar protegidos por el scope.
     *
     * @var list<class-string>
     */
    private const TENANT_MODELS = [
        Area::class,
        AuditLog::class,
        Checklist::class,
        ChecklistSection::class,
        ChecklistVersion::class,
        CorrectiveAction::class,
        CorrectiveActionUpdate::class,
        Evidence::class,
        Incident::class,
        Inspection::class,
        InspectionAnswer::class,
        Norm::class,
        NormRequirement::class,
        Plant::class,
        ProductionLine::class,
        Question::class,
        QuestionNormRequirement::class,
        Role::class,
        SyncChange::class,
        User::class,
        UserArea::class,
        UserPlant::class,
        UserRole::class,
    ];

    /**
     * Modelos sin organization_id: raíz o entidades sin columna de tenant.
     *
     * @var list<class-string>
     */
    private const NON_TENANT_MODELS = [
        Organization::class,
        Device::class,
        SyncOperation::class,
    ];

    public function test_todos_los_modelos_declarados_con_tenant_estan_aislados(): void
    {
        foreach (self::TENANT_MODELS as $model) {
            $this->assertArrayHasKey(
                OrganizationScope::class,
                (new $model)->getGlobalScopes(),
                "{$model} no aplica OrganizationScope."
            );
        }
    }

    public function test_los_modelos_sin_organization_id_no_estan_aislados(): void
    {
        foreach (self::NON_TENANT_MODELS as $model) {
            $this->assertArrayNotHasKey(
                OrganizationScope::class,
                (new $model)->getGlobalScopes(),
                "{$model} no tiene organization_id y no debe llevar el scope."
            );
        }
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    public function test_el_scope_filtra_por_la_organizacion_activa(): void
    {
        $orgA = $this->organization('ORG-A');
        $orgB = $this->organization('ORG-B');

        $this->plant($orgA, 'A-01');
        $this->plant($orgA, 'A-02');
        $plantB = $this->plant($orgB, 'B-01');

        $context = app(TenantContext::class);
        $context->set($orgA->id);

        $this->assertSame(['A-01', 'A-02'], Plant::query()->orderBy('code')->pluck('code')->all());
        $this->assertFalse(Plant::query()->whereKey($plantB->id)->exists());

        $this->assertSame(3, $context->withoutTenant(fn () => Plant::query()->count()));
        $this->assertSame($orgA->id, $context->id());
    }

    public function test_run_as_restaura_el_contexto_anterior(): void
    {
        $orgA = $this->organization('ORG-A');
        $orgB = $this->organization('ORG-B');
        $this->plant($orgA, 'A-01');
        $this->plant($orgB, 'B-01');

        $context = app(TenantContext::class);
        $context->set($orgA->id);

        $countInB = $context->runAs($orgB->id, fn () => Plant::query()->count());

        $this->assertSame(1, $countInB);
        $this->assertSame($orgA->id, $context->id());
        $this->assertSame(['A-01'], Plant::query()->pluck('code')->all());
    }

    public function test_crear_con_organization_id_ajena_lanza_excepcion(): void
    {
        $orgA = $this->organization('ORG-A');
        $orgB = $this->organization('ORG-B');

        app(TenantContext::class)->set($orgA->id);

        $this->expectException(CrossTenantOperationException::class);

        Plant::create([
            'id' => (string) Str::uuid7(),
            'organization_id' => $orgB->id,
            'name' => 'Planta intrusa',
            'code' => 'X-01',
        ]);
    }

    public function test_crear_sin_contexto_ni_organizacion_lanza_excepcion(): void
    {
        app(TenantContext::class)->clear();

        $this->expectException(MissingTenantContextException::class);

        Plant::create([
            'id' => (string) Str::uuid7(),
            'name' => 'Planta sin organización',
            'code' => 'NO-ORG',
        ]);
    }

    public function test_crear_con_contexto_completa_la_organizacion(): void
    {
        $orgA = $this->organization('ORG-A');

        app(TenantContext::class)->set($orgA->id);

        $plant = Plant::create([
            'id' => (string) Str::uuid7(),
            'name' => 'Planta nueva',
            'code' => 'A-NEW',
        ]);

        $this->assertSame($orgA->id, $plant->organization_id);
    }

    public function test_no_se_puede_mover_un_registro_a_otra_organizacion(): void
    {
        $orgA = $this->organization('ORG-A');
        $orgB = $this->organization('ORG-B');
        $plantA = $this->plant($orgA, 'A-01');

        app(TenantContext::class)->set($orgA->id);

        $this->expectException(CrossTenantOperationException::class);

        $plantA->update(['organization_id' => $orgB->id]);
    }

    public function test_no_se_puede_borrar_un_registro_de_otra_organizacion(): void
    {
        $orgA = $this->organization('ORG-A');
        $orgB = $this->organization('ORG-B');
        $plantB = $this->plant($orgB, 'B-01');

        app(TenantContext::class)->set($orgA->id);

        $this->expectException(CrossTenantOperationException::class);

        $plantB->delete();
    }

    public function test_un_area_no_puede_apuntar_a_una_planta_de_otra_organizacion(): void
    {
        $orgA = $this->organization('ORG-A');
        $orgB = $this->organization('ORG-B');
        $plantB = $this->plant($orgB, 'B-01');

        app(TenantContext::class)->set($orgA->id);

        $this->expectException(CrossTenantOperationException::class);

        Area::create([
            'id' => (string) Str::uuid7(),
            'organization_id' => $orgA->id,
            'plant_id' => $plantB->id,
            'name' => 'Área intrusa',
            'code' => 'X-AREA',
        ]);
    }

    public function test_una_linea_no_puede_apuntar_a_un_area_de_otra_organizacion(): void
    {
        $orgA = $this->organization('ORG-A');
        $orgB = $this->organization('ORG-B');
        $areaB = $this->area($this->plant($orgB, 'B-01'), 'B-AREA');

        app(TenantContext::class)->set($orgA->id);

        $this->expectException(CrossTenantOperationException::class);

        ProductionLine::create([
            'id' => (string) Str::uuid7(),
            'organization_id' => $orgA->id,
            'area_id' => $areaB->id,
            'name' => 'Línea intrusa',
            'code' => 'X-LINE',
        ]);
    }

    public function test_un_pivote_no_puede_mezclar_organizaciones(): void
    {
        $orgA = $this->organization('ORG-A');
        $orgB = $this->organization('ORG-B');
        $userA = $this->user($orgA);
        $roleB = $this->role($orgB, 'Rol B');

        app(TenantContext::class)->set($orgA->id);

        $this->expectException(CrossTenantOperationException::class);

        UserRole::create([
            'organization_id' => $orgA->id,
            'user_id' => $userA->id,
            'role_id' => $roleB->id,
        ]);
    }

    public function test_asignar_un_rol_de_otra_organizacion_lanza_excepcion(): void
    {
        $orgA = $this->organization('ORG-A');
        $orgB = $this->organization('ORG-B');
        $userA = $this->user($orgA);
        $roleB = $this->role($orgB, 'Rol B');

        app(TenantContext::class)->set($orgA->id);

        $this->expectException(CrossTenantOperationException::class);

        $userA->assignRole($roleB);
    }

    public function test_helpers_de_usuario_respetan_organizacion_y_rol(): void
    {
        $orgA = $this->organization('ORG-A');
        $orgB = $this->organization('ORG-B');

        $admin = $this->admin($orgA);
        $inspector = $this->user($orgA);
        $inspector->assignRole($this->role($orgA, (string) config('tenancy.roles.inspector')));

        $plantA = $this->plant($orgA, 'A-01');
        $plantB = $this->plant($orgB, 'B-01');
        $inspector->assignPlant($plantA);

        app(TenantContext::class)->set($orgA->id);

        $this->assertTrue($admin->isAdmin());
        $this->assertFalse($inspector->isAdmin());
        $this->assertTrue($inspector->hasPlantAccess($plantA->id));
        $this->assertFalse($inspector->hasPlantAccess($plantB->id));
    }

    public function test_las_factories_funcionan_sin_contexto(): void
    {
        $user = User::factory()->create();

        $this->assertNotNull($user->organization_id);
        $this->assertNotNull($user->organization);
    }
}
