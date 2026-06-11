<?php

declare(strict_types=1);

namespace App\Filament\Resources\GroupResource\Pages;

use App\Filament\Resources\GroupResource;
use Filament\Resources\Pages\EditRecord;

class EditGroup extends EditRecord
{
    protected static string $resource = GroupResource::class;

    // No delete action (v1): groups cannot be deleted. See ADR-0020 / #106.
    protected function getHeaderActions(): array
    {
        return [];
    }
}
