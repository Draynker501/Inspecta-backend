<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * El orden respeta las dependencias de claves foráneas:
     * 1. organizations (raíz, sin dependencias)
     * 2. roles (depende de organizations)
     * 3. plants (depende de organizations)
     * 4. areas (depende de plants)
     * 5. production_lines (depende de areas)
     * 6. users (depende de organizations)
     * 7. user_roles, user_plants, user_areas (dependen de users, roles, plants, areas)
     * 8. checklists (depende de organizations)
     * 9. norms (depende de organizations)
     */
    public function run(): void
    {
        $this->call([
            OrganizationSeeder::class,
            RoleSeeder::class,
            PlantSeeder::class,
            AreaSeeder::class,
            ProductionLineSeeder::class,
            UserSeeder::class,
            ChecklistSeeder::class,
            NormSeeder::class,
        ]);
    }
}
