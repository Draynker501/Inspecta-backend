<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Roles del sistema
    |--------------------------------------------------------------------------
    |
    | Nombres de los roles sembrados por RoleSeeder. Se usan para resolver
    | capacidades dentro de una organización. Los nombres son únicos por
    | organización (roles_org_name_unique), no a nivel global, desde P2.
    |
    */

    'roles' => [
        'admin' => 'Administrador',
        'supervisor' => 'Supervisor',
        'inspector' => 'Inspector',
        'platform_admin' => 'Administrador de plataforma',
    ],

    /*
    |--------------------------------------------------------------------------
    | Modo estricto de OrganizationScope
    |--------------------------------------------------------------------------
    |
    | Si está activo, consultar modelos tenant-scoped sin contexto organizacional
    | en una petición HTTP lanza MissingTenantContextException. En consola
    | (seeders, factories, artisan) y dentro de withoutTenant() se permite.
    |
    */
    'strict_mode' => env('TENANCY_STRICT_MODE', false),

    'allow_console_without_tenant' => env('TENANCY_ALLOW_CONSOLE_WITHOUT_TENANT', true),

];
