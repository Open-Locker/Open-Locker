<?php

declare(strict_types=1);

namespace App\Filament\Resources\OrganizationResource\Pages;

use App\Filament\Resources\OrganizationResource;
use Filament\Resources\Pages\EditRecord;

/**
 * Renaming an operator, and correcting a slug.
 *
 * Deliberately no delete action: an organization owns locker banks,
 * compartments, grants, terms and an immutable event history, and there is no
 * safe casual answer to what happens to those. The database already refuses to
 * drop an organization that still owns a locker bank; removing an operator for
 * real is an operational procedure, not a button.
 */
class EditOrganization extends EditRecord
{
    protected static string $resource = OrganizationResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
