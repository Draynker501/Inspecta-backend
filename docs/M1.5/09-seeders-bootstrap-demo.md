# Seeders: arranque vs demostración (D-37)

## Qué siembra cada seeder

- **BootstrapSeeder** (siempre): organización operadora `INSPECTA-OP` y el rol de plataforma.
- **DemoSeeder** (solo local y testing): `OrganizationSeeder`, `RoleSeeder`, `PlantSeeder`, `AreaSeeder`, `ProductionLineSeeder`, `UserSeeder`, `ChecklistSeeder`, `NormSeeder`.
- **DatabaseSeeder**: llama siempre a `BootstrapSeeder`; llama a `DemoSeeder` solo si `DemoData::allowed()` es true.

## Regla de entorno

- Los datos de demostración solo se siembran en entornos `local` y `testing`.
- En `production` y `staging` solo se siembra el arranque (operadora + rol de plataforma).
- La condición centralizada está en `Database\Seeders\DemoData::allowed()`.
- `UserSeeder` conserva su guarda como defensa en profundidad, reutilizando la misma condición.
- `DemoSeeder` ejecutado directamente en un entorno no permitido lanza `RuntimeException`.

## Aviso para M2

En M2, los roles y normas de cada cliente real deberán aprovisionarse con un servicio al crear la organización, no mediante seeders.

## D-37

Propuesta implementada en esta rama: separar la siembra en `BootstrapSeeder` (siempre) y `DemoSeeder` (solo local y testing).