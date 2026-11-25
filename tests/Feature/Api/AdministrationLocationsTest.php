<?php

namespace Tests\Feature\Api;

use App\Authorization\Permission;
use App\Models\Area;
use App\Models\Organization;
use App\Models\Plant;
use App\Models\ProductionLine;
use App\Models\RolePermission;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class AdministrationLocationsTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    private Organization $organization;

    private User $administrator;

    private Plant $plant;

    private Area $area;

    private ProductionLine $line;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->clientOrganization('ADMIN-LOCATIONS');
        $this->administrator = $this->admin($this->organization);

        $this->plant = $this->plant($this->organization, 'P-01');
        $this->area = $this->area($this->plant, 'A-01');
        $this->line = $this->productionLine($this->area, 'L-01');
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    private function asAdmin(): static
    {
        return $this->actingAsApiInOrganization(
            $this->administrator,
            $this->organization,
        );
    }

    private function asReadOnly(): static
    {
        $user = $this->user($this->organization);

        app(TenantContext::class)->runAs(
            $this->organization->id,
            function () use ($user): void {
                $role = $this->role(
                    $this->organization,
                    'Reader-'.Str::random(12),
                );

                $user->assignRole($role);

                foreach ([
                    Permission::PlantsView,
                    Permission::LocationsViewAll,
                ] as $permission) {
                    RolePermission::firstOrCreate([
                        'role_id' => $role->id,
                        'organization_id' => $this->organization->id,
                        'permission' => $permission->value,
                    ], [
                        'id' => (string) Str::uuid7(),
                    ]);
                }
            },
        );

        return $this->actingAsApiInOrganization(
            $user,
            $this->organization,
        );
    }

    private function plantUrl(): string
    {
        return '/api/plants/'.$this->plant->id;
    }

    private function areaUrl(): string
    {
        return '/api/areas/'.$this->area->id;
    }

    private function lineUrl(): string
    {
        return '/api/production-lines/'.$this->line->id;
    }

    private function locationUrls(): array
    {
        return [
            $this->plantUrl(),
            $this->areaUrl(),
            $this->lineUrl(),
        ];
    }

    private function assertLocationAudit(
        string $action,
        string $id,
        array $oldValues,
        array $newValues,
    ): void {
        $audit = DB::table('audit_logs')
            ->where('organization_id', $this->organization->id)
            ->where('auditable_id', $id)
            ->where('action', $action)
            ->first();

        $this->assertNotNull($audit);

        $this->assertSame(
            $this->administrator->id,
            $audit->user_id,
        );

        $this->assertEquals(
            $oldValues,
            json_decode($audit->old_values, true),
        );

        $this->assertEquals(
            $newValues,
            json_decode($audit->new_values, true),
        );
    }

    public function test_updates_plant(): void
    {
        $this->asAdmin()
            ->patchJson($this->plantUrl(), [
                'name' => 'Planta actualizada',
                'code' => ' p-02 ',
                'address' => 'Nueva dirección',
                'description' => 'Nueva descripción',
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $this->plant->id)
            ->assertJsonPath('data.name', 'Planta actualizada')
            ->assertJsonPath('data.code', 'P-02')
            ->assertJsonPath('data.status', true);

        $this->assertDatabaseHas('plants', [
            'id' => $this->plant->id,
            'organization_id' => $this->organization->id,
            'name' => 'Planta actualizada',
            'code' => 'P-02',
            'address' => 'Nueva dirección',
            'description' => 'Nueva descripción',
        ]);
    }

    public function test_updates_area(): void
    {
        $this->asAdmin()
            ->patchJson($this->areaUrl(), [
                'name' => 'Área actualizada',
                'code' => ' a-02 ',
                'type' => 'almacenamiento',
                'description' => 'Nueva descripción',
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $this->area->id)
            ->assertJsonPath('data.code', 'A-02')
            ->assertJsonPath('data.type', 'almacenamiento')
            ->assertJsonPath('data.plant_id', $this->plant->id);

        $this->assertDatabaseHas('areas', [
            'id' => $this->area->id,
            'plant_id' => $this->plant->id,
            'name' => 'Área actualizada',
            'code' => 'A-02',
            'type' => 'almacenamiento',
        ]);
    }

    public function test_updates_production_line(): void
    {
        $this->asAdmin()
            ->patchJson($this->lineUrl(), [
                'name' => 'Línea actualizada',
                'code' => ' l-02 ',
                'description' => 'Nueva descripción',
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $this->line->id)
            ->assertJsonPath('data.code', 'L-02')
            ->assertJsonPath('data.area_id', $this->area->id);

        $this->assertDatabaseHas('production_lines', [
            'id' => $this->line->id,
            'area_id' => $this->area->id,
            'name' => 'Línea actualizada',
            'code' => 'L-02',
        ]);
    }

    public function test_partial_update_preserves_other_fields(): void
    {
        $this->asAdmin()
            ->patchJson($this->plantUrl(), [
                'name' => 'Nuevo nombre',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Nuevo nombre')
            ->assertJsonPath('data.code', 'P-01')
            ->assertJsonPath('data.status', true);
    }

    public function test_nullable_fields_can_be_cleared(): void
    {
        $this->asAdmin()
            ->patchJson($this->areaUrl(), [
                'type' => null,
                'description' => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.type', null)
            ->assertJsonPath('data.description', null);

        $this->assertDatabaseHas('areas', [
            'id' => $this->area->id,
            'type' => null,
            'description' => null,
        ]);
    }

    public function test_area_type_cannot_exceed_fifty_characters(): void
    {
        $this->asAdmin()
            ->patchJson($this->areaUrl(), [
                'type' => str_repeat('A', 51),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type']);
    }

    public function test_updates_require_at_least_one_field(): void
    {
        $this->asAdmin();

        foreach ($this->locationUrls() as $url) {
            $this->call(
                'PATCH',
                $url,
                [],
                [],
                [],
                $this->transformHeadersToServerVars([
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ]),
                '{}',
            )
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['body']);
        }
    }

    public function test_rejects_protected_fields(): void
    {
        $this->asAdmin();

        foreach ($this->locationUrls() as $url) {
            foreach ([
                'id',
                'organization_id',
                'status',
                'created_at',
                'updated_at',
                'deleted_at',
            ] as $field) {
                $this->patchJson($url, [
                    $field => 'invalid',
                ])
                    ->assertUnprocessable()
                    ->assertJsonValidationErrors([$field]);
            }
        }
    }

    public function test_cannot_reassign_parent_locations(): void
    {
        $this->asAdmin()
            ->patchJson($this->areaUrl(), [
                'plant_id' => $this->plant->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['plant_id']);

        $this->patchJson($this->lineUrl(), [
            'area_id' => $this->area->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['area_id']);
    }

    public function test_rejects_invalid_codes(): void
    {
        $this->asAdmin();

        foreach ($this->locationUrls() as $url) {
            foreach ([
                '',
                'INVALID CODE',
                'CODE_01',
                'CODE@01',
                str_repeat('A', 51),
            ] as $code) {
                $this->patchJson($url, [
                    'code' => $code,
                ])
                    ->assertUnprocessable()
                    ->assertJsonValidationErrors(['code']);
            }
        }
    }

    public function test_existing_code_can_be_retained(): void
    {
        $this->asAdmin();

        foreach ([
            [$this->plantUrl(), 'P-01'],
            [$this->areaUrl(), 'A-01'],
            [$this->lineUrl(), 'L-01'],
        ] as [$url, $code]) {
            $this->patchJson($url, [
                'code' => $code,
            ])
                ->assertOk()
                ->assertJsonPath('data.code', $code);
        }
    }

    public function test_rejects_duplicate_codes_in_same_scope(): void
    {
        $this->plant($this->organization, 'P-02');
        $this->area($this->plant, 'A-02');
        $this->productionLine($this->area, 'L-02');

        $this->asAdmin();

        foreach ([
            [$this->plantUrl(), ' p-02 '],
            [$this->areaUrl(), ' a-02 '],
            [$this->lineUrl(), ' l-02 '],
        ] as [$url, $code]) {
            $this->patchJson($url, [
                'code' => $code,
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['code']);
        }
    }

    public function test_codes_can_be_reused_in_different_parent_locations(): void
    {
        $otherPlant = $this->plant(
            $this->organization,
            'P-02',
        );

        $otherArea = $this->area($otherPlant, 'A-02');

        $this->productionLine($otherArea, 'L-02');

        $this->asAdmin()
            ->patchJson($this->areaUrl(), [
                'code' => 'A-02',
            ])
            ->assertOk()
            ->assertJsonPath('data.code', 'A-02');

        $this->patchJson($this->lineUrl(), [
            'code' => 'L-02',
        ])
            ->assertOk()
            ->assertJsonPath('data.code', 'L-02');
    }

    public function test_plant_code_can_be_reused_in_another_organization(): void
    {
        $foreign = $this->clientOrganization('FOREIGN-CODES');

        app(TenantContext::class)->runAs(
            $foreign->id,
            fn () => $this->plant($foreign, 'P-02'),
        );

        $this->asAdmin()
            ->patchJson($this->plantUrl(), [
                'code' => 'P-02',
            ])
            ->assertOk()
            ->assertJsonPath('data.code', 'P-02');
    }

    public function test_soft_deleted_codes_remain_reserved(): void
    {
        $otherPlant = $this->plant(
            $this->organization,
            'P-02',
        );

        $otherArea = $this->area($this->plant, 'A-02');
        $otherLine = $this->productionLine($this->area, 'L-02');

        $otherPlant->delete();
        $otherArea->delete();
        $otherLine->delete();

        $this->asAdmin();

        foreach ([
            [$this->plantUrl(), 'P-02'],
            [$this->areaUrl(), 'A-02'],
            [$this->lineUrl(), 'L-02'],
        ] as [$url, $code]) {
            $this->patchJson($url, [
                'code' => $code,
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['code']);
        }
    }

    public function test_can_activate_and_deactivate_locations(): void
    {
        $this->asAdmin();

        foreach ($this->locationUrls() as $url) {
            $this->patchJson($url.'/status', [
                'status' => false,
            ])
                ->assertOk()
                ->assertJsonPath('data.status', false);

            $this->patchJson($url.'/status', [
                'status' => true,
            ])
                ->assertOk()
                ->assertJsonPath('data.status', true);
        }
    }

    public function test_status_requires_a_real_json_boolean(): void
    {
        $this->asAdmin();

        foreach ($this->locationUrls() as $url) {
            foreach ([
                'true',
                'false',
                1,
                0,
                null,
                [],
            ] as $status) {
                $this->patchJson($url.'/status', [
                    'status' => $status,
                ])
                    ->assertUnprocessable()
                    ->assertJsonValidationErrors(['status']);
            }
        }
    }

    public function test_status_endpoint_rejects_additional_fields(): void
    {
        $this->asAdmin();

        foreach ($this->locationUrls() as $url) {
            $this->patchJson($url.'/status', [
                'status' => false,
                'name' => 'Nombre no permitido',
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['name']);
        }
    }

    public function test_rejects_query_parameters(): void
    {
        $this->asAdmin();

        foreach ($this->locationUrls() as $url) {
            $this->patchJson($url.'?unexpected=true', [
                'name' => 'Nuevo nombre',
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['query']);

            $this->patchJson($url.'/status?unexpected=true', [
                'status' => false,
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['query']);
        }
    }

    public function test_rejects_non_json_content_type(): void
    {
        $this->asAdmin();

        foreach ($this->locationUrls() as $url) {
            $this->patch($url, [
                'name' => 'Nuevo nombre',
            ], [
                'Accept' => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ])->assertStatus(415);
        }
    }

    public function test_rejects_malformed_json(): void
    {
        $this->asAdmin();

        foreach ($this->locationUrls() as $url) {
            $this->call(
                'PATCH',
                $url,
                [],
                [],
                [],
                $this->transformHeadersToServerVars([
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ]),
                '{"name":',
            )
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['body']);
        }
    }

    public function test_read_only_user_cannot_modify_locations(): void
    {
        $this->asReadOnly();

        foreach ($this->locationUrls() as $url) {
            $this->getJson($url)->assertOk();

            $this->patchJson($url, [
                'name' => 'Cambio no autorizado',
            ])->assertForbidden();

            $this->patchJson($url.'/status', [
                'status' => false,
            ])->assertForbidden();
        }
    }

    public function test_cannot_modify_foreign_organization_locations(): void
    {
        $foreign = $this->clientOrganization('FOREIGN-LOCATIONS');

        [$plant, $area, $line] = app(TenantContext::class)->runAs(
            $foreign->id,
            function () use ($foreign): array {
                $plant = $this->plant($foreign, 'FP');
                $area = $this->area($plant, 'FA');
                $line = $this->productionLine($area, 'FL');

                return [$plant, $area, $line];
            },
        );

        $this->asAdmin();

        foreach ([
            '/api/plants/'.$plant->id,
            '/api/areas/'.$area->id,
            '/api/production-lines/'.$line->id,
        ] as $url) {
            $this->patchJson($url, [
                'name' => 'Cambio no autorizado',
            ])->assertNotFound();

            $this->patchJson($url.'/status', [
                'status' => false,
            ])->assertNotFound();
        }

        $this->assertDatabaseHas('plants', [
            'id' => $plant->id,
            'name' => $plant->name,
            'status' => true,
        ]);

        $this->assertDatabaseHas('areas', [
            'id' => $area->id,
            'name' => $area->name,
            'status' => true,
        ]);

        $this->assertDatabaseHas('production_lines', [
            'id' => $line->id,
            'name' => $line->name,
            'status' => true,
        ]);
    }

    public function test_cannot_modify_nonexistent_locations(): void
    {
        $id = (string) Str::uuid7();

        $this->asAdmin();

        foreach ([
            '/api/plants/'.$id,
            '/api/areas/'.$id,
            '/api/production-lines/'.$id,
        ] as $url) {
            $this->patchJson($url, [
                'name' => 'Nuevo nombre',
            ])->assertNotFound();

            $this->patchJson($url.'/status', [
                'status' => false,
            ])->assertNotFound();
        }
    }

    public function test_cannot_modify_soft_deleted_locations(): void
    {
        $this->line->delete();
        $this->area->delete();
        $this->plant->delete();

        $this->asAdmin();

        foreach ($this->locationUrls() as $url) {
            $this->patchJson($url, [
                'name' => 'Nuevo nombre',
            ])->assertNotFound();

            $this->patchJson($url.'/status', [
                'status' => true,
            ])->assertNotFound();
        }
    }

    public function test_updates_generate_audit_records(): void
    {
        $this->asAdmin();

        $operations = [
            [
                $this->plantUrl(),
                $this->plant,
                'plant.updated',
            ],
            [
                $this->areaUrl(),
                $this->area,
                'area.updated',
            ],
            [
                $this->lineUrl(),
                $this->line,
                'production_line.updated',
            ],
        ];

        foreach ($operations as [$url, $model, $action]) {
            $this->patchJson($url, [
                'name' => 'Nuevo nombre',
            ])->assertOk();

            $this->assertLocationAudit(
                $action,
                $model->id,
                ['name' => $model->name],
                ['name' => 'Nuevo nombre'],
            );
        }
    }

    public function test_status_changes_generate_audit_records(): void
    {
        $this->asAdmin();

        $operations = [
            [
                $this->plantUrl(),
                $this->plant->id,
                'plant.status_updated',
            ],
            [
                $this->areaUrl(),
                $this->area->id,
                'area.status_updated',
            ],
            [
                $this->lineUrl(),
                $this->line->id,
                'production_line.status_updated',
            ],
        ];

        foreach ($operations as [$url, $id, $action]) {
            $this->patchJson($url.'/status', [
                'status' => false,
            ])->assertOk();

            $this->assertLocationAudit(
                $action,
                $id,
                ['status' => true],
                ['status' => false],
            );
        }
    }

    public function test_repeated_status_updates_are_idempotent(): void
    {
        $this->asAdmin();

        $operations = [
            [
                $this->plantUrl(),
                $this->plant->id,
                'plant.status_updated',
            ],
            [
                $this->areaUrl(),
                $this->area->id,
                'area.status_updated',
            ],
            [
                $this->lineUrl(),
                $this->line->id,
                'production_line.status_updated',
            ],
        ];

        foreach ($operations as [$url, $id, $action]) {
            for ($i = 0; $i < 2; $i++) {
                $this->patchJson($url.'/status', [
                    'status' => false,
                ])
                    ->assertOk()
                    ->assertJsonPath('data.status', false);
            }

            $this->assertSame(
                1,
                DB::table('audit_logs')
                    ->where('auditable_id', $id)
                    ->where('action', $action)
                    ->count(),
            );
        }
    }

    public function test_unchanged_fields_do_not_generate_audit_records(): void
    {
        $this->asAdmin();

        $operations = [
            [
                $this->plantUrl(),
                $this->plant,
                'plant.updated',
            ],
            [
                $this->areaUrl(),
                $this->area,
                'area.updated',
            ],
            [
                $this->lineUrl(),
                $this->line,
                'production_line.updated',
            ],
        ];

        foreach ($operations as [$url, $model, $action]) {
            $this->patchJson($url, [
                'name' => $model->name,
                'code' => $model->code,
            ])->assertOk();

            $this->assertDatabaseMissing('audit_logs', [
                'auditable_id' => $model->id,
                'action' => $action,
            ]);
        }
    }

    public function test_deactivating_plant_preserves_descendant_states(): void
    {
        $this->asAdmin()
            ->patchJson($this->plantUrl().'/status', [
                'status' => false,
            ])->assertOk();

        $this->assertDatabaseHas('plants', [
            'id' => $this->plant->id,
            'status' => false,
        ]);

        $this->assertDatabaseHas('areas', [
            'id' => $this->area->id,
            'status' => true,
        ]);

        $this->assertDatabaseHas('production_lines', [
            'id' => $this->line->id,
            'status' => true,
        ]);

        $this->getJson('/api/locations/hierarchy')
            ->assertOk()
            ->assertJsonPath('data.0.available', false)
            ->assertJsonPath('data.0.areas.0.status', true)
            ->assertJsonPath('data.0.areas.0.available', false)
            ->assertJsonPath(
                'data.0.areas.0.production_lines.0.status',
                true,
            )
            ->assertJsonPath(
                'data.0.areas.0.production_lines.0.available',
                false,
            );
    }

    public function test_deactivating_area_preserves_plant_and_line_states(): void
    {
        $this->asAdmin()
            ->patchJson($this->areaUrl().'/status', [
                'status' => false,
            ])->assertOk();

        $this->assertDatabaseHas('plants', [
            'id' => $this->plant->id,
            'status' => true,
        ]);

        $this->assertDatabaseHas('production_lines', [
            'id' => $this->line->id,
            'status' => true,
        ]);

        $this->getJson('/api/locations/hierarchy')
            ->assertOk()
            ->assertJsonPath('data.0.available', true)
            ->assertJsonPath('data.0.areas.0.available', false)
            ->assertJsonPath(
                'data.0.areas.0.production_lines.0.available',
                false,
            );
    }

    public function test_reactivating_parent_restores_effective_availability(): void
    {
        $this->asAdmin()
            ->patchJson($this->plantUrl().'/status', [
                'status' => false,
            ])->assertOk();

        $this->patchJson($this->plantUrl().'/status', [
            'status' => true,
        ])->assertOk();

        $this->getJson('/api/locations/hierarchy')
            ->assertOk()
            ->assertJsonPath('data.0.available', true)
            ->assertJsonPath('data.0.areas.0.available', true)
            ->assertJsonPath(
                'data.0.areas.0.production_lines.0.available',
                true,
            );
    }

    public function test_reactivating_parent_does_not_activate_inactive_children(): void
    {
        $this->asAdmin()
            ->patchJson($this->lineUrl().'/status', [
                'status' => false,
            ])->assertOk();

        $this->patchJson($this->plantUrl().'/status', [
            'status' => false,
        ])->assertOk();

        $this->patchJson($this->plantUrl().'/status', [
            'status' => true,
        ])->assertOk();

        $this->getJson('/api/locations/hierarchy')
            ->assertOk()
            ->assertJsonPath('data.0.available', true)
            ->assertJsonPath('data.0.areas.0.available', true)
            ->assertJsonPath(
                'data.0.areas.0.production_lines.0.status',
                false,
            )
            ->assertJsonPath(
                'data.0.areas.0.production_lines.0.available',
                false,
            );
    }

    public function test_inactive_locations_can_still_be_edited(): void
    {
        $this->asAdmin();

        foreach ($this->locationUrls() as $url) {
            $this->patchJson($url.'/status', [
                'status' => false,
            ])->assertOk();

            $this->patchJson($url, [
                'name' => 'Ubicación inactiva editada',
            ])
                ->assertOk()
                ->assertJsonPath(
                    'data.name',
                    'Ubicación inactiva editada',
                )
                ->assertJsonPath('data.status', false);
        }
    }

    public function test_child_can_be_activated_under_inactive_parent(): void
    {
        $this->asAdmin()
            ->patchJson($this->plantUrl().'/status', [
                'status' => false,
            ])->assertOk();

        $this->patchJson($this->areaUrl().'/status', [
            'status' => false,
        ])->assertOk();

        $this->patchJson($this->areaUrl().'/status', [
            'status' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', true);

        $this->getJson('/api/locations/hierarchy')
            ->assertOk()
            ->assertJsonPath('data.0.areas.0.status', true)
            ->assertJsonPath('data.0.areas.0.available', false);
    }

    public function test_inactive_locations_remain_accessible_for_reading(): void
    {
        $this->asAdmin();

        foreach ($this->locationUrls() as $url) {
            $this->patchJson($url.'/status', [
                'status' => false,
            ])->assertOk();

            $this->getJson($url)
                ->assertOk()
                ->assertJsonPath('data.status', false);
        }
    }
}
