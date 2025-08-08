<?php

namespace App\Filament\Resources\LockerBankResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Log;

class CompartmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'compartments';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('number')
                    ->numeric()
                    ->required()
                    ->step(1)
                    ->minValue(1)
                    ->helperText('The number of the compartment'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('number')
            ->columns([
                Tables\Columns\TextColumn::make('number')->sortable()->label('Compartment Number'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Action::make('open')
                    ->label('Open')
                    ->icon('heroicon-m-bolt')
                    ->requiresConfirmation()
                    ->action(function ($record) {
                        // TODO: Integrate with LockerBankAggregate to emit CompartmentOpeningRequested
                        // Placeholder: for now just log; the actual MQTT publish will be handled by Reactor
                        Log::info('Filament open action requested', [
                            'compartment_id' => $record->id,
                            'locker_bank_id' => $record->locker_bank_id,
                            'number' => $record->number,
                        ]);
                    }),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
