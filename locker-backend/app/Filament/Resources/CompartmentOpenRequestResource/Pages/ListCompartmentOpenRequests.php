<?php

declare(strict_types=1);

namespace App\Filament\Resources\CompartmentOpenRequestResource\Pages;

use App\Filament\Resources\CompartmentOpenRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListCompartmentOpenRequests extends ListRecords
{
    protected static string $resource = CompartmentOpenRequestResource::class;
}
