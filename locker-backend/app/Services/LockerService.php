<?php

namespace App\Services;

use App\Models\Locker;

class LockerService
{
    /**
     * Get the list of locker_ids
     *
     * @return array<Locker>
     */
    public function getLockerList(): array
    {
        return Locker::all()->toArray();
    }

    public function openLocker(Locker $locker): bool
    {
        return true;
    }

    public function getLockerStatus(Locker $locker): bool
    {
        return true;
    }
}
