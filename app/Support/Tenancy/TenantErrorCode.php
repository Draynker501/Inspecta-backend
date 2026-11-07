<?php

namespace App\Support\Tenancy;

final class TenantErrorCode
{
    public const ORGANIZATION_REQUIRED = 'organization_required';

    public const ORGANIZATION_INVALID = 'organization_invalid';

    public const ORGANIZATION_ACCESS_DENIED = 'organization_access_denied';

    public const ORGANIZATION_NOT_TENANT = 'organization_not_tenant';
}
