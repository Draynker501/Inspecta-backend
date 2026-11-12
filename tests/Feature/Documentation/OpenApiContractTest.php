<?php

namespace Tests\Feature\Documentation;

use Tests\TestCase;

class OpenApiContractTest extends TestCase
{
    private function spec(): string
    {
        return (string) file_get_contents(base_path('openapi.yaml'));
    }

    public function test_la_version_del_contrato_es_2_3_0(): void
    {
        $this->assertStringContainsString('version: 2.3.0', $this->spec());
    }

    public function test_documenta_la_cabecera_x_organization_id(): void
    {
        $spec = $this->spec();

        $this->assertStringContainsString('X-Organization-Id', $spec);
        $this->assertStringContainsString('organizationIdHeader', $spec);
    }

    public function test_documenta_auth_organizations(): void
    {
        $this->assertStringContainsString('/auth/organizations:', $this->spec());
    }

    public function test_el_schema_organization_incluye_type(): void
    {
        $spec = $this->spec();

        $this->assertStringContainsString('enum: [client, operator]', $spec);
    }

    public function test_la_descripcion_menciona_v1_4_0(): void
    {
        $this->assertStringContainsString('v1.4.0 (aditiva, M1.5)', $this->spec());
    }
}
