<?php

namespace Tests\Feature\Evidences;

use App\Authorization\Permission;
use App\Models\Checklist;
use App\Models\ChecklistSection;
use App\Models\ChecklistVersion;
use App\Models\Inspection;
use App\Models\RolePermission;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class EvidenceIntegrationTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function environment(
        string $code = 'EVD-A',
        array $permissions = [Permission::InspectionsExecute]
    ): array {
        $org = $this->clientOrganization($code);
        $user = $this->user($org);

        app(TenantContext::class)->runAs(
            $org->id,
            function () use ($org, $user, $permissions): void {
                $role = $this->role(
                    $org,
                    'evidence-'.Str::random(12)
                );

                $user->assignRole($role);

                foreach ($permissions as $permission) {
                    RolePermission::create([
                        'id' => (string) Str::uuid7(),
                        'organization_id' => $org->id,
                        'role_id' => $role->id,
                        'permission' => $permission->value,
                    ]);
                }
            }
        );

        $plant = $this->plant($org, $code.'-P');
        $area = $this->area($plant, $code.'-A');

        DB::table('user_areas')->insert([
            'id' => (string) Str::uuid7(),
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'area_id' => $area->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(TenantContext::class)->set($org->id);

        $checklist = Checklist::create([
            'id' => (string) Str::uuid7(),
            'organization_id' => $org->id,
            'name' => 'Checklist de evidencias',
            'status' => true,
        ]);

        $version = ChecklistVersion::create([
            'id' => (string) Str::uuid7(),
            'organization_id' => $org->id,
            'checklist_id' => $checklist->id,
            'created_by' => $user->id,
            'version' => '1',
            'status' => 'published',
            'published_at' => now(),
        ]);

        ChecklistSection::create([
            'id' => (string) Str::uuid7(),
            'organization_id' => $org->id,
            'checklist_version_id' => $version->id,
            'name' => 'Sección de seguridad',
            'display_order' => 1,
        ]);

        $inspection = Inspection::create([
            'id' => (string) Str::uuid7(),
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'checklist_version_id' => $version->id,
            'plant_id' => $plant->id,
            'area_id' => $area->id,
            'status' => 'draft',
            'started_at' => now(),
        ]);

        $this->actingAsApiInOrganization($user, $org);

        return compact(
            'org',
            'user',
            'plant',
            'area',
            'inspection'
        );
    }

    private function upload(
        array $env,
        array $extra = []
    ) {
        return $this->post(
            '/api/evidences',
            array_merge([
                'inspection_id' => $env['inspection']->id,
                'file' => UploadedFile::fake()
                    ->image('extintor.jpg'),
                'description' => 'Fotografía del extintor',
            ], $extra),
            ['Accept' => 'application/json']
        );
    }

    private function createEvidence(array $env): string
    {
        return $this->upload($env)
            ->assertCreated()
            ->json('data.id');
    }

    private function evidencePath(string $id): string
    {
        return DB::table('evidences')
            ->where('id', $id)
            ->value('storage_path');
    }

    public function test_subir_evidencia_y_auditar(): void
    {
        $env = $this->environment();
        $id = $this->createEvidence($env);

        $this->assertDatabaseHas('evidences', [
            'id' => $id,
            'organization_id' => $env['org']->id,
            'inspection_id' => $env['inspection']->id,
            'uploaded_by' => $env['user']->id,
        ]);

        Storage::disk('local')->assertExists(
            $this->evidencePath($id)
        );

        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $env['org']->id,
            'auditable_id' => $id,
            'action' => 'evidence.created',
        ]);
    }

    public function test_consultar_metadatos(): void
    {
        $env = $this->environment();
        $id = $this->createEvidence($env);

        $this->getJson("/api/evidences/{$id}")
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath(
                'data.inspection_id',
                $env['inspection']->id
            )
            ->assertJsonPath(
                'data.file_name',
                'extintor.jpg'
            );
    }

    public function test_listar_y_filtrar_evidencias(): void
    {
        $env = $this->environment();
        $id = $this->createEvidence($env);

        $this->getJson(
            '/api/evidences?inspection_id='
            .$env['inspection']->id
            .'&per_page=10'
        )
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $id);
    }

    public function test_descargar_evidencia(): void
    {
        $env = $this->environment();
        $id = $this->createEvidence($env);

        $response = $this->get(
            "/api/evidences/{$id}/download"
        );

        $response->assertOk();

        $this->assertStringContainsString(
            'attachment',
            $response->headers->get(
                'content-disposition'
            )
        );
    }

    public function test_visualizar_imagen(): void
    {
        $env = $this->environment();
        $id = $this->createEvidence($env);

        $response = $this->get(
            "/api/evidences/{$id}/preview"
        );

        $response->assertOk();

        $this->assertStringContainsString(
            'inline',
            $response->headers->get(
                'content-disposition'
            )
        );

        $this->assertStringContainsString(
            'image/jpeg',
            $response->headers->get('content-type')
        );
    }

    public function test_rechazar_archivo_no_permitido(): void
    {
        $env = $this->environment();

        $this->upload($env, [
            'file' => UploadedFile::fake()
                ->createWithContent(
                    'script.php',
                    '<?php echo "test";'
                ),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');

        $this->assertDatabaseCount('evidences', 0);
    }

    public function test_rechazar_archivo_mayor_a_10mb(): void
    {
        $env = $this->environment();

        $this->upload($env, [
            'file' => UploadedFile::fake()
                ->create('grande.pdf', 10241, 'application/pdf'),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');

        $this->assertDatabaseCount('evidences', 0);
    }

    public function test_rechazar_campos_protegidos(): void
    {
        $env = $this->environment();

        $this->upload($env, [
            'uploaded_by' => $env['user']->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fields');

        $this->assertDatabaseCount('evidences', 0);
    }

    public function test_rechazar_coordenadas_incompletas(): void
    {
        $env = $this->environment();

        $this->upload($env, [
            'latitude' => 20.6597,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('coordinates');

        $this->assertDatabaseCount('evidences', 0);
    }

    public function test_sin_permiso_no_puede_subir(): void
    {
        $env = $this->environment('EVD-A', []);

        $this->upload($env)->assertForbidden();

        $this->assertDatabaseCount('evidences', 0);
    }

    public function test_organizacion_ajena_no_accede(): void
    {
        $envA = $this->environment('EVD-A');
        $id = $this->createEvidence($envA);

        $envB = $this->environment('EVD-B');

        $this->getJson("/api/evidences/{$id}")
            ->assertNotFound();

        $this->get(
            "/api/evidences/{$id}/download"
        )->assertNotFound();

        $this->get(
            "/api/evidences/{$id}/preview"
        )->assertNotFound();

        $this->getJson('/api/evidences')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->upload($envB, [
            'inspection_id' => $envA['inspection']->id,
        ])->assertNotFound();
    }

    public function test_sin_ubicacion_no_accede(): void
    {
        $env = $this->environment();
        $id = $this->createEvidence($env);

        DB::table('user_areas')
            ->where('user_id', $env['user']->id)
            ->delete();

        $this->getJson("/api/evidences/{$id}")
            ->assertNotFound();

        $this->getJson('/api/evidences')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->upload($env)->assertForbidden();
    }

    public function test_archivo_inexistente_devuelve_404(): void
    {
        $env = $this->environment();
        $id = $this->createEvidence($env);

        Storage::disk('local')->delete(
            $this->evidencePath($id)
        );

        $this->get(
            "/api/evidences/{$id}/download"
        )->assertNotFound();

        $this->get(
            "/api/evidences/{$id}/preview"
        )->assertNotFound();
    }

    public function test_error_auditoria_revierte_archivo_y_bd(): void
    {
        $env = $this->environment();

        $this->mock(AuditLogger::class)
            ->shouldReceive('record')
            ->once()
            ->andThrow(
                new RuntimeException('Auditoría fallida')
            );

        $this->upload($env)
            ->assertInternalServerError();

        $this->assertDatabaseCount('evidences', 0);

        $this->assertEmpty(
            Storage::disk('local')
                ->allFiles(
                    'evidences/'.$env['org']->id
                )
        );
    }
}
