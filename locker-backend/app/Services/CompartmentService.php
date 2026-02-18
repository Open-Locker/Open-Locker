<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Compartment;
use Illuminate\Database\Eloquent\Collection;

class CompartmentService
{
    /**
     * Get all compartments with their current contents.
     *
     * This is used by the mobile app to render a read-only overview.
     *
     * @return Collection<int, Compartment>
     */
    public function listWithContents(): Collection
    {
        return Compartment::query()
            ->with([
                'lockerBank',
                'item.activeLoan',
            ])
            ->orderBy('locker_bank_id')
            ->orderBy('number')
            ->get();
    }
}
