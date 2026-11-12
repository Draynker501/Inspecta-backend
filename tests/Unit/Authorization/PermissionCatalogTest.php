<?php

namespace Tests\Unit\Authorization;

use App\Authorization\Permission;
use PHPUnit\Framework\TestCase;

class PermissionCatalogTest extends TestCase
{
    public function test_valores_del_catalogo_son_unicos(): void
    {
        $values = Permission::values();

        $this->assertCount(count(array_unique($values)), $values);
    }

    public function test_valores_del_catalogo_siguen_formato_recurso_accion(): void
    {
        foreach (Permission::values() as $value) {
            $this->assertMatchesRegularExpression('/^[a-z_]+\.[a-z_]+$/', $value);
        }
    }

    public function test_catalogo_no_esta_vacio(): void
    {
        $this->assertNotEmpty(Permission::cases());
    }
}
