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
    ],

];
