<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DemoSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        if (! DemoData::allowed()) {
            throw new \RuntimeException('DemoSeeder solo puede ejecutarse en entornos local y testing.');
        }

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
