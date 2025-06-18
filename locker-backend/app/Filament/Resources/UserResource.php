<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Filament\Resources\UserResource\RelationManagers;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required(),
                Forms\Components\TextInput::make('email')
                    ->email()
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('email')
                    ->searchable(),
                Tables\Columns\TextColumn::make('email_verified_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('is_admin_since')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('setAsAdmin')
                    ->hidden(fn (User $record): bool => !!$record->is_admin_since)
                    ->label('zum Admin machen')
                    ->icon('heroicon-o-shield-check')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Nutzer zum Admin machen')
                    ->modalDescription('Soll dieser Nutzer Adminrechte erhalten?')
                    ->modalSubmitActionLabel('Ja, gib diesem Nutzer Adminrechte')
                    ->action(function (Model $record) {
                        $record->is_admin_since = now();
                        $record->save();

                        Notification::make()
                            ->title('Nutzer ist nun Admin')
                            ->success()
                            ->send();
                    }),
                Tables\Actions\Action::make('removeAdmin')
                    ->hidden(fn (User $record): bool => !$record->is_admin_since)
                    ->label('Adminrechte entziehen')
                    ->icon('heroicon-o-shield-exclamation')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Nutzer Adminrechte entziehen')
                    ->modalDescription('Sollen diesem Nutzer Adminrechte entzogen werden?')
                    ->modalSubmitActionLabel('Ja, nimm diesem Nutzer die Adminrechte')
                    ->action(function (Model $record) {

                        $adminCount = User::whereNot('is_admin_since', false)
                            ->where('id', '!=', $record->id)
                            ->count();

                        if ($adminCount === 0) {
                            Notification::make()
                                ->title('Aktion abgebrochen')
                                ->body('Dem letzten Admin können nicht die Adminrechte entzogen werden.')
                                ->danger()
                                ->send();

                            return;
                        }

                        $record->is_admin_since = null;
                        $record->save();

                        Notification::make()
                            ->title('Nutzer ist nun nicht mehr Admin')
                            ->success()
                            ->send();
                    }),
            ])->actionsAlignment('left')
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->before(function (Tables\Actions\DeleteBulkAction $action, Collection $records) {
                            $adminCount = User::whereNotNull('is_admin_since')->count();
                            $deletedAdmins = $records->filter(fn(User $record) => $record->is_admin_since)->count();

                            if ($adminCount - $deletedAdmins < 1) {
                                Notification::make()
                                    ->title('Aktion abgebrochen')
                                    ->body('Der letzte Admin kann nicht gelöscht werden.')
                                    ->danger()
                                    ->send();
                                $action->cancel();
                            }
                        })
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
