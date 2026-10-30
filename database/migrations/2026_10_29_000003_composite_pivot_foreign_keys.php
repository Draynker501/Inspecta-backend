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
                ->cascadeOnUpdate();
            $table->foreign(['role_id', 'organization_id'])
                ->references(['id', 'organization_id'])
                ->on('roles')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
        });
        Schema::table('user_plants', function (Blueprint $table): void {
            $table->foreign(['user_id', 'organization_id'])
                ->references(['user_id', 'organization_id'])
                ->on('organization_memberships')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->foreign(['plant_id', 'organization_id'])
                ->references(['id', 'organization_id'])
                ->on('plants')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
        });
        Schema::table('user_areas', function (Blueprint $table): void {
            $table->foreign(['user_id', 'organization_id'])
                ->references(['user_id', 'organization_id'])
                ->on('organization_memberships')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->foreign(['area_id', 'organization_id'])
                ->references(['id', 'organization_id'])
                ->on('areas')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
        });
    }
    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver !== 'sqlite') {
            Schema::table('user_roles', function (Blueprint $table): void {
                $table->dropForeign('user_roles_user_id_organization_id_foreign');
                $table->dropForeign('user_roles_role_id_organization_id_foreign');
            });
        }
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
        return $violations;
    }
};
