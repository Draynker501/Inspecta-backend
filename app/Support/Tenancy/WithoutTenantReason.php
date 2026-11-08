<?php

namespace App\Support\Tenancy;

/**
 * Motivos válidos para TenantContext::withoutTenant().
 *
 * Centraliza los motivos aceptados para omitir el aislamiento organizacional.
 * Cada uso debe referenciar una de estas constantes (o un motivo documentado
 * en docs/M1.5/08-motivos-withouttenant.md).
 */
final class WithoutTenantReason
{
    public const PLATFORM_ROLE_CHECK = 'platform-role-check';

    public const CONSOLE_DIAGNOSTIC = 'diagnóstico de consola';

    public const QUERY_DIAGNOSTIC = 'consulta de diagnóstico sin tenant';

    public const TEST = 'test';
}
