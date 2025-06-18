<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\User;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->before(function (Actions\DeleteAction $action, User $record) {
                    if ($record->is_admin_since) {
                        $adminCount = User::whereNotNull('is_admin_since')->count();
                        if ($adminCount <= 1) {
                            Notification::make()
                                ->title('Aktion abgebrochen')
                                ->body('Der letzte Admin kann nicht gelöscht werden.')
                                ->danger()
                                ->send();
                            $action->cancel();
                        }
                    }
                }),
        ];
    }
}
