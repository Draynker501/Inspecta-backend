<?php

namespace Tests\Feature\Incidents;

use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\IncidentFixtures;
use Tests\TestCase;

class EvidenceTest extends TestCase
{
    use RefreshDatabase;
    use IncidentFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function prepareEvidenceEnvironment(
        string $code = 'EVD-A'
    ): array {
        $env = $this->prepareIncidentEnvironment($code);

        $inspection = $this->createInspectionForEvidence($env);

        $env['inspection'] = $inspection;

        return $env;
    }

    private function uploadEvidence(array $env): array
    {
        $response = $this->post(
            '/api/evidences',
            [
                'file' => UploadedFile::fake()->image(
                    'extintor.jpg'
                ),
                'inspection_id' => $env['inspection']->id,
                'description' => 'Fotografía de extintor.',
                'latitude' => '20.659698',
                'longitude' => '-103.349609',
            ],
            ['Accept' => 'application/json']
        );

        $response->assertCreated();

        return $response->json('data');
    }

    public function test_puede_subir_imagen(): void
    {
        $env = $this->prepareEvidenceEnvironment();

        $evidence = $this->uploadEvidence($env);

        $this->assertDatabaseHas('evidences', [
            'id' => $evidence['id'],
            'organization_id' => $env['organization']->id,
            'inspection_id' => $env['inspection']->id,
            'uploaded_by' => $env['user']->id,
        ]);

        Storage::disk('local')->assertExists(
            $evidence['storage_path']
        );
    }

    public function test_puede_listar_evidencias(): void
    {
        $env = $this->prepareEvidenceEnvironment();
        $evidence = $this->uploadEvidence($env);

        $this->getJson('/api/evidences')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonFragment([
                'id' => $evidence['id'],
            ]);
    }

    public function test_puede_consultar_evidencia(): void
    {
        $env = $this->prepareEvidenceEnvironment();
        $evidence = $this->uploadEvidence($env);

        $this->getJson(
            "/api/evidences/{$evidence['id']}"
        )->assertOk()
         ->assertJsonPath(
             'data.file_name',
             'extintor.jpg'
         );
    }

    public function test_puede_descargar_imagen(): void
    {
        $env = $this->prepareEvidenceEnvironment();
        $evidence = $this->uploadEvidence($env);

        $response = $this->get(
            "/api/evidences/{$evidence['id']}/download"
        );

        $response->assertOk()
            ->assertDownload('extintor.jpg');
    }

    public function test_puede_visualizar_imagen(): void
    {
        $env = $this->prepareEvidenceEnvironment();
        $evidence = $this->uploadEvidence($env);

        $this->get(
            "/api/evidences/{$evidence['id']}/preview"
        )->assertOk()
         ->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_rechaza_archivo_sin_inspeccion(): void
    {
        $this->prepareIncidentEnvironment();

        $this->post(
            '/api/evidences',
            [
                'file' => UploadedFile::fake()->image(
                    'foto.jpg'
                ),
            ],
            ['Accept' => 'application/json']
        )->assertUnprocessable()
         ->assertJsonValidationErrors(['inspection_id']);
    }

    public function test_rechaza_archivo_demasiado_grande(): void
    {
        $env = $this->prepareEvidenceEnvironment();

        $this->post(
            '/api/evidences',
            [
                'file' => UploadedFile::fake()->create(
                    'documento.pdf',
                    11000,
                    'application/pdf'
                ),
                'inspection_id' => $env['inspection']->id,
            ],
            ['Accept' => 'application/json']
        )->assertUnprocessable()
         ->assertJsonValidationErrors(['file']);
    }

    public function test_rechaza_formato_no_permitido(): void
    {
        $env = $this->prepareEvidenceEnvironment();

        $this->post(
            '/api/evidences',
            [
                'file' => UploadedFile::fake()->create(
                    'programa.exe',
                    100,
                    'application/octet-stream'
                ),
                'inspection_id' => $env['inspection']->id,
            ],
            ['Accept' => 'application/json']
        )->assertUnprocessable()
         ->assertJsonValidationErrors(['file']);
    }

    public function test_rechaza_inspeccion_ajena(): void
    {
        $orgB = $this->prepareEvidenceEnvironment('EVD-B');

        app(TenantContext::class)->clear();

        $this->prepareIncidentEnvironment('EVD-A');

        $this->post(
            '/api/evidences',
            [
                'file' => UploadedFile::fake()->image(
                    'foto.jpg'
                ),
                'inspection_id' => $orgB['inspection']->id,
            ],
            ['Accept' => 'application/json']
        )->assertUnprocessable()
         ->assertJsonValidationErrors(['inspection_id']);
    }

    public function test_no_permite_descargar_evidencia_ajena(): void
    {
        $orgB = $this->prepareEvidenceEnvironment('EVD-B');
        $evidenceB = $this->uploadEvidence($orgB);

        app(TenantContext::class)->clear();

        $this->prepareIncidentEnvironment('EVD-A');

        $this->getJson(
            "/api/evidences/{$evidenceB['id']}"
        )->assertNotFound();

        $this->get(
            "/api/evidences/{$evidenceB['id']}/download"
        )->assertNotFound();

        $this->get(
            "/api/evidences/{$evidenceB['id']}/preview"
        )->assertNotFound();
    }

    public function test_archivo_inexistente_devuelve_404(): void
    {
        $env = $this->prepareEvidenceEnvironment();
        $evidence = $this->uploadEvidence($env);

        Storage::disk('local')->delete(
            $evidence['storage_path']
        );

        $this->get(
            "/api/evidences/{$evidence['id']}/download"
        )->assertNotFound();

        $this->get(
            "/api/evidences/{$evidence['id']}/preview"
        )->assertNotFound();

        $this->assertDatabaseHas('evidences', [
            'id' => $evidence['id'],
        ]);
    }
}