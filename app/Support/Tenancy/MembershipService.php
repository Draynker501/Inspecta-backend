<?php

namespace App\Support\Tenancy;

use App\Authorization\Permission;
use App\Exceptions\MembershipRevocationException;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class MembershipService
{
    public function revoke(User $actor, User $target, Organization $organization): OrganizationMembership
    {
        if ($actor->id === $target->id) {
            throw MembershipRevocationException::selfRevocation();
        }

        $context = app(TenantContext::class);

        return $context->runAs($organization->id, function () use ($actor, $target, $organization, $context) {
            if (OrganizationMembership::findActive($actor->id, $organization->id) === null) {
                throw MembershipRevocationException::unauthorized();
            }

            if (! $actor->hasPermission(Permission::MembershipsManage)) {
                throw MembershipRevocationException::unauthorized();
            }

            return DB::transaction(function () use ($actor, $target, $organization, $context) {
                $membership = OrganizationMembership::query()
                    ->where('user_id', $target->id)
                    ->where('organization_id', $organization->id)
                    ->first();

                if ($membership === null) {
                    throw MembershipRevocationException::membershipNotFound();
                }

                if ($membership->status === OrganizationMembership::STATUS_REVOKED) {
                    return $membership;
                }

                $previousStatus = (string) $membership->status;

                $membership->status = OrganizationMembership::STATUS_REVOKED;
                $membership->revoked_at = now();
                $membership->save();

                Auth::setUser($actor);

                app(AuditLogger::class)->record(
                    'membership.revoked',
                    $membership,
                    ['status' => $previousStatus],
                    [
                        'membership_id' => $membership->id,
                        'organization_id' => $organization->id,
                        'previous_status' => $previousStatus,
                        'new_status' => OrganizationMembership::STATUS_REVOKED,
                        'revoked_at' => $membership->revoked_at?->toIso8601String(),
                    ],
                );

                $context->flushPermissionCache($target->id, $organization->id);

                return $membership;
            });
        });
    }
}
