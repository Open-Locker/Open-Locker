<?php

declare(strict_types=1);

namespace App\Filament\Resources\CompartmentResource\Pages;

use App\Filament\Resources\CompartmentResource;
use Filament\Resources\Pages\ListRecords;

class ListCompartments extends ListRecords
{
    protected static string $resource = CompartmentResource::class;
}
