<?php

namespace App\Policies;

use App\Authorization\Permission;

class NormPolicy extends EhsResourcePolicy
{
    protected function readPermission(): Permission
    {
        return Permission::NormsView;
    }

    protected function writePermission(): Permission
    {
        return Permission::NormsManage;
    }
}
