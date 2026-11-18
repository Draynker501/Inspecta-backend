<?php

namespace Tests\Feature\Documentation;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class InspectionAnswersOpenApiContractTest extends TestCase
{
    public function test_las_cuatro_operaciones_y_la_revision_estan_implementadas(): void
    {
        $spec = Yaml::parseFile(base_path('openapi.yaml'));

        $this->assertSame('2.3.0', $spec['info']['version']);

        foreach ([
            ['/inspections/{inspectionId}/checklist', 'get'],
            ['/inspections/{inspectionId}/answers', 'get'],
            ['/inspections/{inspectionId}/answers/{questionId}', 'put'],
            ['/inspections/{inspectionId}/answers/{questionId}', 'delete'],
        ] as [$path, $method]) {
            $this->assertArrayNotHasKey('x-status', $spec['paths'][$path], $path);
            $this->assertArrayNotHasKey('x-status', $spec['paths'][$path][$method], $path.' '.$method);
        }

        $this->assertSame('implemented', $spec['paths']['/inspections/{inspectionId}/status']['x-status']);
    }

    public function test_el_checklist_documenta_el_dto_historico_y_los_tipos_soportados(): void
    {
        $spec = Yaml::parseFile(base_path('openapi.yaml'));
        $operation = $spec['paths']['/inspections/{inspectionId}/checklist']['get'];
        $schema = $spec['components']['schemas']['InspectionChecklist'];

        $this->assertSame(
            '#/components/schemas/InspectionChecklist',
            $operation['responses'][200]['content']['application/json']['schema']['properties']['data']['$ref'],
        );
        $this->assertStringContainsString('congelado', $operation['description']);
        $this->assertStringContainsString('archivada', $operation['description']);
        $this->assertStringContainsString('signature', $operation['description']);
        $this->assertFalse($schema['additionalProperties']);
        $this->assertEqualsCanonicalizing(
            ['inspection_id', 'checklist', 'checklist_version', 'sections'],
            array_keys($schema['properties']),
        );

        $question = $spec['components']['schemas']['InspectionChecklistQuestion'];
        $this->assertContains('signature', $question['properties']['answer_type']['enum']);
        $this->assertStringContainsString('supported', implode(' ', array_keys($question['properties'])));
    }

    public function test_las_respuestas_documentan_dto_paginacion_idempotencia_y_estado(): void
    {
        $spec = Yaml::parseFile(base_path('openapi.yaml'));
        $list = $spec['paths']['/inspections/{inspectionId}/answers']['get'];
        $put = $spec['paths']['/inspections/{inspectionId}/answers/{questionId}']['put'];
        $delete = $spec['paths']['/inspections/{inspectionId}/answers/{questionId}']['delete'];

        $this->assertContains(['$ref' => '#/components/parameters/pageParam'], $list['parameters']);
        $this->assertContains(['$ref' => '#/components/parameters/answersPerPageParam'], $list['parameters']);
        $this->assertSame(
            100,
            $spec['components']['parameters']['answersPerPageParam']['schema']['default'],
        );
        $this->assertSame(
            100,
            $spec['components']['parameters']['answersPerPageParam']['schema']['maximum'],
        );
        $this->assertStringContainsString('predeterminado', $list['description']);
        $this->assertStringContainsString('100', $list['description']);
        $this->assertSame(
            '#/components/schemas/InspectionAnswer',
            $list['responses'][200]['content']['application/json']['schema']['properties']['data']['items']['$ref'],
        );

        $this->assertSame(
            '#/components/schemas/InspectionAnswerInput',
            $put['requestBody']['content']['application/json']['schema']['$ref'],
        );
        $input = $spec['components']['schemas']['InspectionAnswerInput'];
        $this->assertFalse($input['additionalProperties']);
        $this->assertSame(['answer'], $input['required']);
        $this->assertEqualsCanonicalizing(['answer', 'observation'], array_keys($input['properties']));
        $this->assertTrue($input['properties']['observation']['nullable']);
        $this->assertSame(65535, $input['properties']['observation']['maxLength']);

        foreach ([$put, $delete] as $operation) {
            $this->assertStringContainsString('inspections.execute', $operation['description']);
            $this->assertStringContainsString('manage', $operation['description']);
            $this->assertStringContainsString('draft', $operation['description']);
            $this->assertArrayHasKey('422', $operation['responses']);
        }

        $this->assertStringContainsString('idempotente', $put['description']);
        $this->assertStringContainsString('last-write-wins', $put['description']);
        $this->assertStringContainsString('evidencias', $delete['description']);
        $this->assertStringContainsString('offline', $delete['description'].' '.$spec['paths']['/inspections/{inspectionId}/answers']['get']['description']);
        $this->assertArrayHasKey('204', $delete['responses']);

        // Los parámetros de consulta no forman parte de la escritura.
        $this->assertStringContainsString('Parámetros de consulta', $put['description']);
        $this->assertStringContainsString('Parámetros de consulta', $delete['description']);
        $this->assertStringContainsString('cuerpo JSON', $put['description']);
    }
}
