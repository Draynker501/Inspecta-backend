<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_roles', function (Blueprint $table): void {
            $table->foreign(['user_id', 'organization_id'])
                ->references(['user_id', 'organization_id'])
                ->on('organization_memberships')
                ->restrictOnDelete()
                ->cascadeOnUpdate()
                ->name('user_roles_membership_fk');

            $table->foreign(['role_id', 'organization_id'])
                ->references(['id', 'organization_id'])
                ->on('roles')
                ->restrictOnDelete()
                ->cascadeOnUpdate()
                ->name('user_roles_role_org_fk');
        });

        Schema::table('user_plants', function (Blueprint $table): void {
            $table->foreign(['user_id', 'organization_id'])
                ->references(['user_id', 'organization_id'])
                ->on('organization_memberships')
                ->restrictOnDelete()
                ->cascadeOnUpdate()
                ->name('user_plants_membership_fk');

            $table->foreign(['plant_id', 'organization_id'])
                ->references(['id', 'organization_id'])
                ->on('plants')
                ->restrictOnDelete()
                ->cascadeOnUpdate()
                ->name('user_plants_plant_org_fk');
        });

        Schema::table('user_areas', function (Blueprint $table): void {
            $table->foreign(['user_id', 'organization_id'])
                ->references(['user_id', 'organization_id'])
                ->on('organization_memberships')
                ->restrictOnDelete()
                ->cascadeOnUpdate()
                ->name('user_areas_membership_fk');

            $table->foreign(['area_id', 'organization_id'])
                ->references(['id', 'organization_id'])
                ->on('areas')
                ->restrictOnDelete()
                ->cascadeOnUpdate()
                ->name('user_areas_area_org_fk');
        });
    }

    public function down(): void
    {
        Schema::table('user_roles', function (Blueprint $table): void {
            $table->dropForeign('user_roles_membership_fk');
            $table->dropForeign('user_roles_role_org_fk');
        });

        Schema::table('user_plants', function (Blueprint $table): void {
            $table->dropForeign('user_plants_membership_fk');
            $table->dropForeign('user_plants_plant_org_fk');
        });

        Schema::table('user_areas', function (Blueprint $table): void {
            $table->dropForeign('user_areas_membership_fk');
            $table->dropForeign('user_areas_area_org_fk');
        });
    }

    private function detectarViolaciones(): array
    {
        $violations = [];

        foreach (['user_roles', 'user_plants', 'user_areas'] as $table) {
            $count = DB::table($table . ' as p')
                ->leftJoin('organization_memberships as m', function ($join) use ($table) {
                    $join->on('p.user_id', '=', 'm.user_id')
                        ->on('p.organization_id', '=', 'm.organization_id');
                })
                ->whereNull('m.user_id')
                ->count();

            if ($count > 0) {
                $violations[] = "$table: $count filas sin membresía en (user_id, organization_id)";
            }
        }

        $countRoles = DB::table('user_roles as ur')
            ->leftJoin('organization_memberships as m', function ($join) {
                $join->on('ur.user_id', '=', 'm.user_id')
                    ->on('ur.organization_id', '=', 'm.organization_id');
            })
            ->leftJoin('roles as r', function ($join) {
                $join->on('ur.role_id', '=', 'r.id')
                    ->on('ur.organization_id', '=', 'r.organization_id');
            })
            ->whereNull('m.user_id')
            ->orWhereNull('r.id')
            ->count();

        if ($countRoles > 0) {
            $violations[] = "user_roles: $countRoles filas con rol de otra organización o sin membresía";
        }

        $countPlants = DB::table('user_plants as up')
            ->leftJoin('organization_memberships as m', function ($join) {
                $join->on('up.user_id', '=', 'm.user_id')
                    ->on('up.organization_id', '=', 'm.organization_id');
            })
            ->leftJoin('plants as p', function ($join) {
                $join->on('up.plant_id', '=', 'p.id')
                    ->on('up.organization_id', '=', 'p.organization_id');
            })
            ->whereNull('m.user_id')
            ->orWhereNull('p.id')
            ->count();

        if ($countPlants > 0) {
            $violations[] = "user_plants: $countPlants filas con planta de otra organización o sin membresía";
        }

        $countAreas = DB::table('user_areas as ua')
            ->leftJoin('organization_memberships as m', function ($join) {
                $join->on('ua.user_id', '=', 'm.user_id')
                    ->on('ua.organization_id', '=', 'm.organization_id');
            })
            ->leftJoin('areas as a', function ($join) {
                $join->on('ua.area_id', '=', 'a.id')
                    ->on('ua.organization_id', '=', 'a.organization_id');
            })
            ->whereNull('m.user_id')
            ->orWhereNull('a.id')
            ->count();

        if ($countAreas > 0) {
            $violations[] = "user_areas: $countAreas filas con área de otra organización o sin membresía";
        }

        return $violations;
    }
};
