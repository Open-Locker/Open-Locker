<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Compartment;
use App\Models\CompartmentAccess;
use App\Models\User;
use App\Models\UserGroupCompartmentAccess;

class CompartmentStatusBroadcastService
{
    /**
     * Users who should receive realtime compartment status for this compartment:
     * active access holders and all admins.
     *
     * @return list<int>
     */
    public function recipientUserIdsForCompartment(Compartment $compartment): array
    {
        $accessUserIds = CompartmentAccess::query()
            ->where('compartment_id', $compartment->id)
            ->active()
            ->pluck('user_id')
            ->all();

        $groupAccessUserIds = UserGroupCompartmentAccess::query()
            ->where('compartment_id', $compartment->id)
            ->active()
            ->pluck('user_id')
            ->all();

        $adminIds = User::query()
            ->whereNotNull('is_admin_since')
            ->pluck('id')
            ->all();

        return array_values(array_unique(array_merge($accessUserIds, $groupAccessUserIds, $adminIds)));
    }
}
