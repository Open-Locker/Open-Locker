<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\CompartmentOpenRequestResource;
use App\Filament\Resources\ItemResource;
use App\Filament\Resources\LockerBankResource;
use App\Filament\Resources\TermsDocumentVersionResource;
use App\Filament\Resources\UserResource;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class FilamentAdminSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_filament_resources_can_build_table_configuration(): void
    {
        $livewire = $this->createMock(HasTable::class);

        $this->assertInstanceOf(Table::class, ItemResource::table(Table::make($livewire)));
        $this->assertInstanceOf(Table::class, UserResource::table(Table::make($livewire)));
        $this->assertInstanceOf(Table::class, LockerBankResource::table(Table::make($livewire)));
        $this->assertInstanceOf(Table::class, TermsDocumentVersionResource::table(Table::make($livewire)));
        $this->assertInstanceOf(Table::class, CompartmentOpenRequestResource::table(Table::make($livewire)));
    }

    public function test_filament_resources_do_not_use_deprecated_table_actions_namespace(): void
    {
        $resourceFiles = File::allFiles(app_path('Filament/Resources'));

        foreach ($resourceFiles as $file) {
            $contents = File::get($file->getPathname());

            $this->assertStringNotContainsString('Tables\\Actions\\', $contents, $file->getPathname());
        }
    }
}
