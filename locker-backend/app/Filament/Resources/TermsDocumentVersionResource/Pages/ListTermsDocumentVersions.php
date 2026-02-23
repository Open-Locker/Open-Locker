<?php

declare(strict_types=1);

namespace App\Filament\Resources\TermsDocumentVersionResource\Pages;

use App\Filament\Resources\TermsDocumentVersionResource;
use App\Models\TermsDocument;
use App\Models\TermsDocumentVersion;
use App\Models\User;
use App\Services\TermsService;
use Filament\Actions;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;
use Throwable;

class ListTermsDocumentVersions extends ListRecords
{
    protected static string $resource = TermsDocumentVersionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('createDraftVersion')
                ->label(__('Create or update draft'))
                ->icon('heroicon-o-plus')
                ->modalHeading(__('Create or update a draft version'))
                ->modalDescription(__('Drafts are editable. Use the Publish action in the table when ready.'))
                ->slideOver()
                ->form([
                    TextInput::make('document_name')
                        ->label(__('Document name'))
                        ->default(fn (): string => (string) (TermsDocument::query()->oldest('id')->value('name') ?? 'Terms of Service'))
                        ->required()
                        ->maxLength(255),
                    RichEditor::make('content')
                        ->label(__('Content'))
                        ->default(fn (): string => (string) (TermsDocumentVersion::query()->orderByDesc('version')->value('content') ?? ''))
                        ->helperText(__('Starts with the latest version content so you can edit only what changed.'))
                        ->required()
                        ->columnSpanFull(),
                ])
                ->action(function (array $data): void {
                    /** @var TermsService $termsService */
                    $termsService = app(TermsService::class);
                    $actor = Auth::user();

                    try {
                        $termsService->createDraftVersion(
                            documentName: (string) $data['document_name'],
                            content: (string) $data['content'],
                            actor: $actor instanceof User ? $actor : null,
                        );
                    } catch (Throwable $e) {
                        Notification::make()
                            ->title(__('Publishing failed'))
                            ->body($e->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title(__('Draft saved'))
                        ->success()
                        ->send();
                }),
        ];
    }
}
