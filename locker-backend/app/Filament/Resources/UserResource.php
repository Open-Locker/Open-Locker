<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Filament\Resources\UserResource\RelationManagers\CompartmentAccessesRelationManager;
use App\Models\User;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Schema $form): Schema
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
                Tables\Columns\IconColumn::make('terms_current_accepted')
                    ->label('Current terms accepted')
                    ->boolean()
                    ->state(fn (User $record): bool => $record->hasAcceptedCurrentTerms()),
                Tables\Columns\TextColumn::make('latest_terms_version')
                    ->label('Last accepted terms version')
                    ->state(fn (User $record): ?int => $record->latestAcceptedTermsVersion())
                    ->placeholder('-'),
            ])
            ->filters([
                //
            ])
            ->actions([
                \Filament\Actions\EditAction::make(),
            ])->actionsAlignment('left')
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make()
                        ->before(function (\Filament\Actions\DeleteBulkAction $action, Collection $records) {
                            $adminCount = User::whereNotNull('is_admin_since')->count();
                            $deletedAdmins = $records->filter(fn (User $record) => $record->is_admin_since)->count();

                            if ($adminCount - $deletedAdmins < 1) {
                                Notification::make()
                                    ->title('Aktion abgebrochen')
                                    ->body('Der letzte Admin kann nicht gelöscht werden.')
                                    ->danger()
                                    ->send();
                                $action->cancel();
                            }
                        }),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            CompartmentAccessesRelationManager::class,
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
