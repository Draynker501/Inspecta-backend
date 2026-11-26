<?php

namespace Tests\Feature\Documentation;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class OpenApiProfileContractTest extends TestCase
{
    private array $contract;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contract = Yaml::parseFile(
            base_path('openapi.yaml')
        );
    }

    private function operation(string $path, string $method): array
    {
        $this->assertArrayHasKey($path, $this->contract['paths']);

        $this->assertArrayHasKey(
            $method,
            $this->contract['paths'][$path]
        );

        return $this->contract['paths'][$path][$method];
    }

    private function schema(string $name): array
    {
        $this->assertArrayHasKey(
            $name,
            $this->contract['components']['schemas']
        );

        return $this->contract['components']['schemas'][$name];
    }

    private function assertSchemaFields(
        string $schemaName,
        array $fields
    ): void {
        $schema = $this->schema($schemaName);

        $this->assertSame('object', $schema['type']);
        $this->assertFalse($schema['additionalProperties']);

        $this->assertEqualsCanonicalizing(
            $fields,
            array_keys($schema['properties'])
        );

        $this->assertEqualsCanonicalizing(
            $fields,
            $schema['required']
        );
    }

    private function assertResponseCodes(
        string $path,
        string $method,
        array $codes
    ): void {
        $operation = $this->operation($path, $method);

        $this->assertEqualsCanonicalizing(
            $codes,
            array_map('strval', array_keys($operation['responses']))
        );
    }

    private function assertResponseSchema(
        string $path,
        string $method,
        string $schemaName
    ): void {
        $operation = $this->operation($path, $method);

        $this->assertSame(
            '#/components/schemas/'.$schemaName,
            $operation['responses']['200']['content']['application/json']['schema']['$ref']
        );
    }

    private function assertRequestSchema(
        string $path,
        string $method,
        string $schemaName
    ): void {
        $operation = $this->operation($path, $method);

        $this->assertTrue(
            $operation['requestBody']['required']
        );

        $this->assertSame(
            '#/components/schemas/'.$schemaName,
            $operation['requestBody']['content']['application/json']['schema']['$ref']
        );
    }

    private function parameters(
        string $path,
        string $method
    ): array {
        $operation = $this->operation($path, $method);

        return array_merge(
            $this->contract['paths'][$path]['parameters'] ?? [],
            $operation['parameters'] ?? []
        );
    }

    private function assertNoOrganizationHeader(
        string $path,
        string $method
    ): void {
        foreach ($this->parameters($path, $method) as $parameter) {
            $this->assertNotSame(
                '#/components/parameters/organizationIdHeader',
                $parameter['$ref'] ?? null
            );

            $this->assertFalse(
                ($parameter['in'] ?? null) === 'header'
                && strtolower($parameter['name'] ?? '')
                    === 'x-organization-id'
            );
        }
    }

    private function assertRateLimitHeaders(
        string $path,
        string $method
    ): void {
        $operation = $this->operation($path, $method);

        $headers = $operation['responses']['200']['headers'];

        $this->assertSame(
            '#/components/headers/RateLimitLimit',
            $headers['X-RateLimit-Limit']['$ref']
        );

        $this->assertSame(
            '#/components/headers/RateLimitRemaining',
            $headers['X-RateLimit-Remaining']['$ref']
        );

        $this->assertSame(
            '#/components/responses/TooManyRequests',
            $operation['responses']['429']['$ref']
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Operations and authentication
    |--------------------------------------------------------------------------
    */

    public function test_profile_update_operation_is_documented(): void
    {
        $operation = $this->operation('/auth/me', 'patch');

        $this->assertContains(
            'Authentication',
            $operation['tags']
        );
    }

    public function test_password_update_operation_is_documented(): void
    {
        $operation = $this->operation('/auth/password', 'put');

        $this->assertContains(
            'Authentication',
            $operation['tags']
        );
    }

    public function test_profile_membership_operation_is_documented(): void
    {
        $operation = $this->operation(
            '/auth/me/membership',
            'get'
        );

        $this->assertContains(
            'Authentication',
            $operation['tags']
        );
    }

    public function test_existing_me_operation_is_preserved(): void
    {
        $this->operation('/auth/me', 'get');

        $this->assertResponseSchema(
            '/auth/me',
            'get',
            'MeResponse'
        );
    }

    public function test_profile_operations_require_bearer_authentication(): void
    {
        $operations = [
            ['/auth/me', 'patch'],
            ['/auth/password', 'put'],
            ['/auth/me/membership', 'get'],
        ];

        foreach ($operations as [$path, $method]) {
            $operation = $this->operation($path, $method);

            $this->assertSame(
                [['bearerAuth' => []]],
                $operation['security']
            );
        }
    }

    public function test_bearer_security_scheme_is_http_bearer(): void
    {
        $scheme = $this->contract['components']['securitySchemes']['bearerAuth'];

        $this->assertSame('http', $scheme['type']);
        $this->assertSame('bearer', $scheme['scheme']);
    }

    /*
    |--------------------------------------------------------------------------
    | Tenant context
    |--------------------------------------------------------------------------
    */

    public function test_profile_update_does_not_require_organization_header(): void
    {
        $this->assertNoOrganizationHeader('/auth/me', 'patch');
    }

    public function test_password_update_does_not_require_organization_header(): void
    {
        $this->assertNoOrganizationHeader(
            '/auth/password',
            'put'
        );
    }

    public function test_membership_requires_organization_header(): void
    {
        $parameters = $this->parameters(
            '/auth/me/membership',
            'get'
        );

        $this->assertContains(
            [
                '$ref' => '#/components/parameters/organizationIdHeader',
            ],
            $parameters
        );

        $header = $this->contract['components']['parameters']['organizationIdHeader'];

        $this->assertSame('X-Organization-Id', $header['name']);
        $this->assertSame('header', $header['in']);
        $this->assertTrue($header['required']);
        $this->assertSame('string', $header['schema']['type']);
        $this->assertSame('uuid', $header['schema']['format']);
    }

    public function test_membership_operation_has_no_request_body(): void
    {
        $operation = $this->operation(
            '/auth/me/membership',
            'get'
        );

        $this->assertArrayNotHasKey('requestBody', $operation);
    }

    /*
    |--------------------------------------------------------------------------
    | Profile update request
    |--------------------------------------------------------------------------
    */

    public function test_profile_update_uses_expected_request_schema(): void
    {
        $this->assertRequestSchema(
            '/auth/me',
            'patch',
            'ProfileUpdateInput'
        );
    }

    public function test_profile_update_accepts_only_allowed_fields(): void
    {
        $schema = $this->schema('ProfileUpdateInput');

        $this->assertSame('object', $schema['type']);
        $this->assertFalse($schema['additionalProperties']);

        $this->assertEqualsCanonicalizing(
            ['name', 'email', 'current_password'],
            array_keys($schema['properties'])
        );
    }

    public function test_profile_update_requires_name_or_email(): void
    {
        $schema = $this->schema('ProfileUpdateInput');

        $this->assertEqualsCanonicalizing(
            [
                ['required' => ['name']],
                ['required' => ['email']],
            ],
            $schema['anyOf']
        );
    }

    public function test_profile_name_constraints_are_documented(): void
    {
        $property = $this->schema('ProfileUpdateInput')['properties']['name'];

        $this->assertSame('string', $property['type']);
        $this->assertSame(1, $property['minLength']);
        $this->assertSame(150, $property['maxLength']);
    }

    public function test_profile_email_constraints_are_documented(): void
    {
        $property = $this->schema('ProfileUpdateInput')['properties']['email'];

        $this->assertSame('string', $property['type']);
        $this->assertSame('email', $property['format']);
        $this->assertSame(255, $property['maxLength']);
    }

    public function test_profile_current_password_is_write_only(): void
    {
        $property = $this->schema('ProfileUpdateInput')['properties']['current_password'];

        $this->assertSame('string', $property['type']);
        $this->assertSame('password', $property['format']);
        $this->assertTrue($property['writeOnly']);
        $this->assertSame(1024, $property['maxLength']);
    }

    public function test_profile_update_only_accepts_json(): void
    {
        $operation = $this->operation('/auth/me', 'patch');

        $this->assertSame(
            ['application/json'],
            array_keys($operation['requestBody']['content'])
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Profile update response
    |--------------------------------------------------------------------------
    */

    public function test_profile_update_documents_expected_response_codes(): void
    {
        $this->assertResponseCodes(
            '/auth/me',
            'patch',
            ['200', '401', '415', '422', '429']
        );
    }

    public function test_profile_update_uses_expected_response_schema(): void
    {
        $this->assertResponseSchema(
            '/auth/me',
            'patch',
            'ProfileUpdateResponse'
        );
    }

    public function test_profile_update_response_has_expected_fields(): void
    {
        $this->assertSchemaFields(
            'ProfileUpdateResponse',
            ['message', 'data']
        );

        $schema = $this->schema('ProfileUpdateResponse');

        $this->assertSame(
            '#/components/schemas/ProfileIdentity',
            $schema['properties']['data']['$ref']
        );
    }

    public function test_profile_identity_only_exposes_allowed_fields(): void
    {
        $this->assertSchemaFields(
            'ProfileIdentity',
            ['id', 'name', 'email', 'organization_id']
        );
    }

    public function test_profile_identity_organization_is_nullable(): void
    {
        $property = $this->schema('ProfileIdentity')['properties']['organization_id'];

        $this->assertSame('string', $property['type']);
        $this->assertSame('uuid', $property['format']);
        $this->assertTrue($property['nullable']);
    }

    public function test_profile_update_documents_rate_limit_headers(): void
    {
        $this->assertRateLimitHeaders('/auth/me', 'patch');
    }

    /*
    |--------------------------------------------------------------------------
    | Password update request
    |--------------------------------------------------------------------------
    */

    public function test_password_update_uses_expected_request_schema(): void
    {
        $this->assertRequestSchema(
            '/auth/password',
            'put',
            'ProfilePasswordInput'
        );
    }

    public function test_password_update_requires_all_expected_fields(): void
    {
        $this->assertSchemaFields(
            'ProfilePasswordInput',
            [
                'current_password',
                'password',
                'password_confirmation',
            ]
        );
    }

    public function test_all_password_fields_are_write_only(): void
    {
        $properties = $this->schema('ProfilePasswordInput')['properties'];

        foreach ($properties as $property) {
            $this->assertSame('string', $property['type']);
            $this->assertSame('password', $property['format']);
            $this->assertTrue($property['writeOnly']);
        }
    }

    public function test_current_password_constraints_are_documented(): void
    {
        $property = $this->schema('ProfilePasswordInput')['properties']['current_password'];

        $this->assertSame(1, $property['minLength']);
        $this->assertSame(1024, $property['maxLength']);
    }

    public function test_new_password_constraints_are_documented(): void
    {
        $property = $this->schema('ProfilePasswordInput')['properties']['password'];

        $this->assertSame(12, $property['minLength']);
        $this->assertSame(72, $property['maxLength']);
    }

    public function test_password_byte_limit_is_documented(): void
    {
        $property = $this->schema('ProfilePasswordInput')['properties']['password'];

        $this->assertStringContainsString(
            '72',
            $property['description']
        );

        $this->assertStringContainsString(
            'bytes',
            $property['description']
        );
    }

    public function test_password_update_only_accepts_json(): void
    {
        $operation = $this->operation('/auth/password', 'put');

        $this->assertSame(
            ['application/json'],
            array_keys($operation['requestBody']['content'])
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Password update response
    |--------------------------------------------------------------------------
    */

    public function test_password_update_documents_expected_response_codes(): void
    {
        $this->assertResponseCodes(
            '/auth/password',
            'put',
            ['200', '401', '415', '422', '429']
        );
    }

    public function test_password_update_uses_expected_response_schema(): void
    {
        $this->assertResponseSchema(
            '/auth/password',
            'put',
            'ProfilePasswordResponse'
        );
    }

    public function test_password_response_does_not_expose_sensitive_fields(): void
    {
        $this->assertSchemaFields(
            'ProfilePasswordResponse',
            ['message']
        );
    }

    public function test_password_update_documents_rate_limit_headers(): void
    {
        $this->assertRateLimitHeaders(
            '/auth/password',
            'put'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Membership response
    |--------------------------------------------------------------------------
    */

    public function test_membership_documents_expected_response_codes(): void
    {
        $this->assertResponseCodes(
            '/auth/me/membership',
            'get',
            ['200', '400', '401', '403']
        );
    }

    public function test_membership_uses_expected_response_schema(): void
    {
        $this->assertResponseSchema(
            '/auth/me/membership',
            'get',
            'ProfileMembershipResponse'
        );
    }

    public function test_membership_response_has_expected_wrapper(): void
    {
        $this->assertSchemaFields(
            'ProfileMembershipResponse',
            ['data']
        );

        $schema = $this->schema('ProfileMembershipResponse');

        $this->assertSame(
            '#/components/schemas/ProfileMembership',
            $schema['properties']['data']['$ref']
        );
    }

    public function test_membership_has_expected_fields(): void
    {
        $this->assertSchemaFields(
            'ProfileMembership',
            [
                'organization',
                'membership',
                'roles',
                'permissions',
                'locations',
            ]
        );
    }

    public function test_membership_references_expected_schemas(): void
    {
        $properties = $this->schema('ProfileMembership')['properties'];

        $this->assertSame(
            '#/components/schemas/ProfileOrganization',
            $properties['organization']['$ref']
        );

        $this->assertSame(
            '#/components/schemas/ProfileMembershipDetails',
            $properties['membership']['$ref']
        );

        $this->assertSame(
            '#/components/schemas/ProfileLocations',
            $properties['locations']['$ref']
        );
    }

    public function test_membership_organization_has_expected_fields(): void
    {
        $this->assertSchemaFields(
            'ProfileOrganization',
            ['id', 'name', 'code', 'type', 'status']
        );
    }

    public function test_membership_only_documents_active_client_organizations(): void
    {
        $properties = $this->schema('ProfileOrganization')['properties'];

        $this->assertSame(
            ['client'],
            $properties['type']['enum']
        );

        $this->assertSame(
            [true],
            $properties['status']['enum']
        );
    }

    public function test_membership_details_have_expected_fields(): void
    {
        $this->assertSchemaFields(
            'ProfileMembershipDetails',
            ['id', 'status', 'revoked_at']
        );
    }

    public function test_membership_only_documents_active_memberships(): void
    {
        $properties = $this->schema('ProfileMembershipDetails')['properties'];

        $this->assertSame(
            ['active'],
            $properties['status']['enum']
        );

        $this->assertTrue(
            $properties['revoked_at']['nullable']
        );

        $this->assertSame(
            'date-time',
            $properties['revoked_at']['format']
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Membership roles and permissions
    |--------------------------------------------------------------------------
    */

    public function test_membership_roles_use_expected_schema(): void
    {
        $property = $this->schema('ProfileMembership')['properties']['roles'];

        $this->assertSame('array', $property['type']);

        $this->assertSame(
            '#/components/schemas/ProfileRole',
            $property['items']['$ref']
        );
    }

    public function test_membership_role_has_expected_fields(): void
    {
        $this->assertSchemaFields(
            'ProfileRole',
            ['id', 'name', 'status']
        );
    }

    public function test_membership_role_status_supports_active_and_inactive_roles(): void
    {
        $property = $this->schema('ProfileRole')['properties']['status'];

        $this->assertSame('boolean', $property['type']);

        $this->assertArrayNotHasKey('enum', $property);
    }

    public function test_membership_permissions_are_unique(): void
    {
        $property = $this->schema('ProfileMembership')['properties']['permissions'];

        $this->assertSame('array', $property['type']);
        $this->assertTrue($property['uniqueItems']);
    }

    public function test_membership_permissions_use_administration_permission_keys(): void
    {
        $property = $this->schema('ProfileMembership')['properties']['permissions'];

        $this->assertSame(
            '#/components/schemas/AdministrationPermissionKey',
            $property['items']['$ref']
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Membership locations
    |--------------------------------------------------------------------------
    */

    public function test_membership_locations_have_expected_fields(): void
    {
        $this->assertSchemaFields(
            'ProfileLocations',
            ['can_view', 'view_all', 'plants', 'areas']
        );
    }

    public function test_location_visibility_flags_are_boolean(): void
    {
        $properties = $this->schema('ProfileLocations')['properties'];

        $this->assertSame(
            'boolean',
            $properties['can_view']['type']
        );

        $this->assertSame(
            'boolean',
            $properties['view_all']['type']
        );
    }

    public function test_membership_plants_use_expected_schema(): void
    {
        $property = $this->schema('ProfileLocations')['properties']['plants'];

        $this->assertSame('array', $property['type']);

        $this->assertSame(
            '#/components/schemas/ProfilePlant',
            $property['items']['$ref']
        );
    }

    public function test_membership_areas_use_expected_schema(): void
    {
        $property = $this->schema('ProfileLocations')['properties']['areas'];

        $this->assertSame('array', $property['type']);

        $this->assertSame(
            '#/components/schemas/ProfileArea',
            $property['items']['$ref']
        );
    }

    public function test_membership_plant_has_expected_fields(): void
    {
        $this->assertSchemaFields(
            'ProfilePlant',
            ['id', 'name', 'code', 'status', 'available']
        );
    }

    public function test_membership_area_has_expected_fields(): void
    {
        $this->assertSchemaFields(
            'ProfileArea',
            [
                'id',
                'plant_id',
                'plant_name',
                'name',
                'code',
                'status',
                'available',
            ]
        );
    }

    public function test_location_status_and_availability_are_boolean(): void
    {
        foreach (['ProfilePlant', 'ProfileArea'] as $name) {
            $properties = $this->schema($name)['properties'];

            $this->assertSame(
                'boolean',
                $properties['status']['type']
            );

            $this->assertSame(
                'boolean',
                $properties['available']['type']
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Error responses
    |--------------------------------------------------------------------------
    */

    public function test_profile_operations_reference_unauthorized_error(): void
    {
        $operations = [
            ['/auth/me', 'patch'],
            ['/auth/password', 'put'],
            ['/auth/me/membership', 'get'],
        ];

        foreach ($operations as [$path, $method]) {
            $operation = $this->operation($path, $method);

            $this->assertSame(
                '#/components/responses/UnauthorizedError',
                $operation['responses']['401']['$ref']
            );
        }
    }

    public function test_profile_write_operations_reference_validation_error(): void
    {
        $operations = [
            ['/auth/me', 'patch'],
            ['/auth/password', 'put'],
        ];

        foreach ($operations as [$path, $method]) {
            $operation = $this->operation($path, $method);

            $this->assertSame(
                '#/components/responses/ValidationError',
                $operation['responses']['422']['$ref']
            );
        }
    }

    public function test_profile_write_operations_document_unsupported_media_type(): void
    {
        $operations = [
            ['/auth/me', 'patch'],
            ['/auth/password', 'put'],
        ];

        foreach ($operations as [$path, $method]) {
            $operation = $this->operation($path, $method);

            $this->assertSame(
                '#/components/schemas/Error',
                $operation['responses']['415']['content']['application/json']['schema']['$ref']
            );
        }
    }

    public function test_membership_documents_missing_and_invalid_organization_errors(): void
    {
        $operation = $this->operation(
            '/auth/me/membership',
            'get'
        );

        $examples = $operation['responses']['400']['content']['application/json']['examples'];

        $this->assertSame(
            '#/components/examples/OrganizationRequired',
            $examples['organizationRequired']['$ref']
        );

        $this->assertSame(
            '#/components/examples/OrganizationInvalid',
            $examples['organizationInvalid']['$ref']
        );
    }

    public function test_membership_documents_organization_access_errors(): void
    {
        $operation = $this->operation(
            '/auth/me/membership',
            'get'
        );

        $examples = $operation['responses']['403']['content']['application/json']['examples'];

        $this->assertSame(
            '#/components/examples/OrganizationAccessDenied',
            $examples['organizationAccessDenied']['$ref']
        );

        $this->assertSame(
            '#/components/examples/OrganizationNotTenant',
            $examples['organizationNotTenant']['$ref']
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Sensitive information
    |--------------------------------------------------------------------------
    */

    public function test_profile_response_schemas_do_not_expose_sensitive_information(): void
    {
        $schemas = [
            'ProfileUpdateResponse',
            'ProfileIdentity',
            'ProfilePasswordResponse',
            'ProfileMembershipResponse',
            'ProfileMembership',
            'ProfileOrganization',
            'ProfileMembershipDetails',
            'ProfileRole',
            'ProfileLocations',
            'ProfilePlant',
            'ProfileArea',
        ];

        $sensitiveFields = [
            'password',
            'current_password',
            'password_confirmation',
            'password_hash',
            'remember_token',
            'token',
            'access_token',
            'refresh_token',
            'personal_access_tokens',
            'secret',
            'authorization',
        ];

        foreach ($schemas as $name) {
            $properties = $this->schema($name)['properties'];

            foreach ($sensitiveFields as $field) {
                $this->assertArrayNotHasKey(
                    $field,
                    $properties,
                    "El esquema {$name} expone el campo {$field}."
                );
            }
        }
    }
}
