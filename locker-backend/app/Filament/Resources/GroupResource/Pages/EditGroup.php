<?php

declare(strict_types=1);

namespace App\Filament\Resources\GroupResource\Pages;

use App\Filament\Resources\GroupResource;
use App\Models\Group;
use App\Services\GroupAccessService;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;

class EditGroup extends EditRecord
{
    protected static string $resource = GroupResource::class;

    // No hard delete (v1): groups are archived, not deleted. See ADR-0020 / #106.
    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('archive')
                ->label(__('Archive'))
                ->icon('heroicon-o-archive-box')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription(__('Archiving ends this group\'s access grants for members who have no other source of access. Membership and grant history are kept.'))
                ->visible(fn (Group $record): bool => ! $record->isArchived())
                ->action(function (Group $record): void {
                    app(GroupAccessService::class)->archiveGroup($record, Filament::auth()->user());

                    $this->redirect(GroupResource::getUrl('index'));
                }),
        ];
    }
}
