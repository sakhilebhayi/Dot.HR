<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Hr\HrAccess;

/** Reading the audit log is admin-only. There is no update or delete ability at all. */
class HrAuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return HrAccess::current($user)?->isAdmin() ?? false;
    }
}
