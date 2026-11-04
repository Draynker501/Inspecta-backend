<?php

namespace Tests\Feature\Tenancy;

use App\Exceptions\CrossTenantOperationException;
use App\Models\AuditLog;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class TestParentModel extends Model
{
    use BelongsToOrganization;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'test_parent_models';

    protected $fillable = ['id', 'organization_id', 'user_id', 'title'];

    protected function tenantParentRelations(): array
    {
        return ['user_id' => User::class];
    }
}

class UserParentMembershipTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('test_parent_models', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('organization_id', 36);
            $table->char('user_id', 36)->nullable();
            $table->string('title');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('test_parent_models');
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

    public function test_t04_un_usuario_tiene_roles_distintos_en_dos_organizaciones(): void
    {
        $orgA = $this->organization('ORG-A');
        $orgB = $this->organization('ORG-B');

        $juan = $this->user($orgA);
        // membresía activa en B también
        OrganizationMembership::firstOrCreate(
            ['user_id' => $juan->id, 'organization_id' => $orgB->id],
            ['id' => (string) Str::uuid7(), 'status' => OrganizationMembership::STATUS_ACTIVE]
        );

        // legacy organization_id = A
        $juan->organization_id = $orgA->id;
        $juan->save();

        app(TenantContext::class)->set($orgA->id);
        $roleA = $this->role($orgA, 'Inspector');
        $juan->assignRole($roleA);

        app(TenantContext::class)->set($orgB->id);
        $roleB = $this->role($orgB, 'Inspector');
        $juan->assignRole($roleB);

        $this->assertDatabaseHas('user_roles', ['user_id' => $juan->id, 'organization_id' => $orgA->id]);
        $this->assertDatabaseHas('user_roles', ['user_id' => $juan->id, 'organization_id' => $orgB->id]);
    }

    public function test_crear_en_b_con_usuario_activo_en_b_y_origen_a(): void
    {
        $orgA = $this->organization('ORG-A');
        $orgB = $this->organization('ORG-B');

        $juan = $this->user($orgA);
        OrganizationMembership::firstOrCreate(
            ['user_id' => $juan->id, 'organization_id' => $orgB->id],
            ['id' => (string) Str::uuid7(), 'status' => OrganizationMembership::STATUS_ACTIVE]
        );
        $juan->organization_id = $orgA->id;
        $juan->save();

        app(TenantContext::class)->set($orgB->id);

        $record = TestParentModel::create([
            'id' => (string) Str::uuid7(),
            'user_id' => $juan->id,
            'title' => 'registro en B',
        ]);

        $this->assertNotNull($record->id);
    }

    public function test_t12b_no_se_puede_asignar_a_un_usuario_sin_membresia(): void
    {
        $orgB = $this->organization('ORG-B');
        $sinMembresia = User::factory()->create();

        app(TenantContext::class)->set($orgB->id);

        $this->expectException(CrossTenantOperationException::class);

        TestParentModel::create([
            'id' => (string) Str::uuid7(),
            'user_id' => $sinMembresia->id,
            'title' => 'sin membresia',
        ]);
    }

    public function test_usuario_con_membresia_revocada_rechazado_al_crear(): void
    {
        $orgB = $this->organization('ORG-B');
        $user = $this->user($orgB);

        OrganizationMembership::where('user_id', $user->id)
            ->where('organization_id', $orgB->id)
            ->update(['status' => OrganizationMembership::STATUS_REVOKED]);

        app(TenantContext::class)->set($orgB->id);

        $this->expectException(CrossTenantOperationException::class);

        TestParentModel::create([
            'id' => (string) Str::uuid7(),
            'user_id' => $user->id,
            'title' => 'revocado',
        ]);
    }

    public function test_columna_legacy_no_autoriza(): void
    {
        $orgA = $this->organization('ORG-A');
        $user = User::factory()->create();
        $user->organization_id = $orgA->id;
        $user->save();

        app(TenantContext::class)->set($orgA->id);

        $this->expectException(CrossTenantOperationException::class);

        TestParentModel::create([
            'id' => (string) Str::uuid7(),
            'user_id' => $user->id,
            'title' => 'legacy',
        ]);
    }

    public function test_t11b_editar_registro_historico_con_responsable_revocado(): void
    {
        $orgB = $this->organization('ORG-B');
        $user = $this->user($orgB);

        app(TenantContext::class)->set($orgB->id);

        $record = TestParentModel::create([
            'id' => (string) Str::uuid7(),
            'user_id' => $user->id,
            'title' => 'original',
        ]);

        OrganizationMembership::where('user_id', $user->id)
            ->where('organization_id', $orgB->id)
            ->update(['status' => OrganizationMembership::STATUS_REVOKED]);

        $record->title = 'actualizado';
        $record->save();

        $this->assertSame('actualizado', $record->fresh()->title);
    }

    public function test_cambiar_user_id_a_usuario_sin_membresia_rechazado(): void
    {
        $orgB = $this->organization('ORG-B');
        $user = $this->user($orgB);
        $sinMembresia = User::factory()->create();

        app(TenantContext::class)->set($orgB->id);

        $record = TestParentModel::create([
            'id' => (string) Str::uuid7(),
            'user_id' => $user->id,
            'title' => 'original',
        ]);

        $this->expectException(CrossTenantOperationException::class);

        $record->user_id = $sinMembresia->id;
        $record->save();
    }

    public function test_cambiar_user_id_a_usuario_con_membresia_permitido(): void
    {
        $orgB = $this->organization('ORG-B');
        $userA = $this->user($orgB);
        $userB = $this->user($orgB);

        app(TenantContext::class)->set($orgB->id);

        $record = TestParentModel::create([
            'id' => (string) Str::uuid7(),
            'user_id' => $userA->id,
            'title' => 'original',
        ]);

        $record->user_id = $userB->id;
        $record->save();

        $this->assertSame($userB->id, $record->fresh()->user_id);
    }

    public function test_auditlog_con_actor_revocado_se_crea(): void
    {
        $orgB = $this->organization('ORG-B');
        $user = $this->user($orgB);

        OrganizationMembership::where('user_id', $user->id)
            ->where('organization_id', $orgB->id)
            ->update(['status' => OrganizationMembership::STATUS_REVOKED]);

        app(TenantContext::class)->set($orgB->id);

        $log = AuditLog::withoutTimestamps(function () use ($orgB, $user) {
            return AuditLog::forceCreate([
                'id' => (string) Str::uuid7(),
                'organization_id' => $orgB->id,
                'user_id' => $user->id,
                'action' => 'test',
                'auditable_type' => 'App\\\\Models\\\\User',
                'auditable_id' => (string) Str::uuid7(),
                'created_at' => now(),
            ]);
        });

        $this->assertNotNull($log->id);
    }

    public function test_auditlog_con_actor_de_otra_organizacion_se_crea(): void
    {
        $orgA = $this->organization('ORG-A');
        $orgB = $this->organization('ORG-B');

        $userA = $this->user($orgA);

        app(TenantContext::class)->set($orgB->id);

        $log = AuditLog::withoutTimestamps(function () use ($orgB, $userA) {
            return AuditLog::forceCreate([
                'id' => (string) Str::uuid7(),
                'organization_id' => $orgB->id,
                'user_id' => $userA->id,
                'action' => 'test',
                'auditable_type' => 'App\\\\Models\\\\User',
                'auditable_id' => (string) Str::uuid7(),
                'created_at' => now(),
            ]);
        });

        $this->assertNotNull($log->id);
    }

    public function test_fk_a_plant_de_otra_organizacion_sigue_rechazada(): void
    {
        $orgA = $this->organization('ORG-A');
        $orgB = $this->organization('ORG-B');

        $userB = $this->user($orgB);

        $plantA = $this->plant($orgA, 'PLANT-A');

        app(TenantContext::class)->set($orgB->id);

        $this->expectException(CrossTenantOperationException::class);

        $userB->assignPlant($plantA);
    }
}
