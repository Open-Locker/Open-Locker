<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\TermsDocumentVersionResource\Pages;
use App\Models\TermsDocumentVersion;
use App\Models\User;
use App\Services\TermsService;
use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Throwable;

class TermsDocumentVersionResource extends Resource
{
    protected static ?string $model = TermsDocumentVersion::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Terms & Policies';

    protected static string|\UnitEnum|null $navigationGroup = 'Legal';

    protected static ?string $modelLabel = 'legal document version';

    protected static ?string $pluralModelLabel = 'legal document versions';

    public static function getNavigationLabel(): string
    {
        return __('Terms & Policies');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Legal');
    }

    public static function getModelLabel(): string
    {
        return __('legal document version');
    }

    public static function getPluralModelLabel(): string
    {
        return __('legal document versions');
    }

    public static function form(Schema $form): Schema
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('version', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('version')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('document.name')
                    ->label('Document')
                    ->state(fn (TermsDocumentVersion $record): string => (string) ($record->document_name_snapshot ?: $record->document?->name ?: '-'))
                    ->searchable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->label('Active'),
                Tables\Columns\IconColumn::make('is_published')
                    ->boolean()
                    ->label('Published'),
                Tables\Columns\TextColumn::make('createdByUser.name')
                    ->label('Created by')
                    ->placeholder('-'),
                Tables\Columns\TextColumn::make('published_at')
                    ->dateTime()
                    ->placeholder('-')
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                Action::make('editDraft')
                    ->label('Edit draft')
                    ->icon('heroicon-o-pencil-square')
                    ->visible(fn (TermsDocumentVersion $record): bool => ! $record->is_published)
                    ->form([
                        RichEditor::make('content')
                            ->label(__('Content'))
                            ->required()
                            ->columnSpanFull(),
                    ])
                    ->fillForm(fn (TermsDocumentVersion $record): array => [
                        'content' => (string) $record->content,
                    ])
                    ->action(function (TermsDocumentVersion $record, array $data): void {
                        /** @var TermsService $termsService */
                        $termsService = app(TermsService::class);

                        try {
                            $termsService->updateUnpublishedVersion(
                                version: $record,
                                content: (string) $data['content'],
                            );
                        } catch (Throwable $e) {
                            Notification::make()
                                ->title(__('Draft update failed'))
                                ->body($e->getMessage())
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title(__('Draft updated'))
                            ->success()
                            ->send();
                    }),
                Action::make('publishDraft')
                    ->label('Publish')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('success')
                    ->visible(fn (TermsDocumentVersion $record): bool => ! $record->is_published)
                    ->requiresConfirmation()
                    ->modalHeading(__('Publish this draft version?'))
                    ->modalDescription(__('Publishing activates this version and keeps older versions immutable.'))
                    ->action(function (TermsDocumentVersion $record): void {
                        /** @var TermsService $termsService */
                        $termsService = app(TermsService::class);
                        $actor = Auth::user();

                        try {
                            $termsService->publishDraftVersion(
                                draft: $record,
                                actor: $actor instanceof User ? $actor : null,
                            );
                        } catch (Throwable $e) {
                            Notification::make()
                                ->title(__('Publish failed'))
                                ->body($e->getMessage())
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title(__('Version published'))
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTermsDocumentVersions::route('/'),
        ];
    }
}
