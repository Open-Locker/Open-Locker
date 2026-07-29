<?php

declare(strict_types=1);

namespace App\Filament\Resources\GroupResource\Pages;

use App\Filament\Resources\GroupResource;
use App\Models\User;
use App\Services\GroupAccessService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateGroup extends CreateRecord
{
    protected static string $resource = GroupResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        /** @var User|null $actor */
        $actor = Filament::auth()->user();

        return app(GroupAccessService::class)->createGroup(
            name: $data['name'],
            description: $data['description'] ?? null,
            actor: $actor,
        );
    }
}
