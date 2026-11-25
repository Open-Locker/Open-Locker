<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\AuditLogResource\Pages\ListAuditLog;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\AuditEvent;
use App\Models\Compartment;
use App\Models\TermsDocument;
use App\Models\TermsDocumentVersion;
use App\Models\User;
use App\Models\UserTermsAcceptance;
use App\StorableEvents\CompartmentAccessGranted;
use App\Support\Audit\AuditEventPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Admin tables must not issue a query per rendered row (#223): rendering more
 * rows has to cost the same number of queries.
 */
class AdminTableQueryCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_log_query_count_does_not_grow_with_rows(): void
    {
        $admin = User::factory()->create();
        $admin->makeAdmin();
        $this->actingAs($admin);

        $this->recordAccessGrantedEvents(2);
        $this->countQueries(fn () => Livewire::test(ListAuditLog::class));

        $few = $this->countQueries(fn () => Livewire::test(ListAuditLog::class));
        $this->recordAccessGrantedEvents(4);
        $many = $this->countQueries(fn () => Livewire::test(ListAuditLog::class));

        $this->assertSame($few, $many);
    }

    public function test_user_list_query_count_does_not_grow_with_rows(): void
    {
        $admin = User::factory()->create();
        $admin->makeAdmin();
        $this->actingAs($admin);

        $document = TermsDocument::query()->create(['name' => 'AGB']);
        $version = TermsDocumentVersion::query()->create([
            'terms_document_id' => $document->id,
            'version' => 1,
            'content' => '<p>Version 1</p>',
            'is_published' => true,
            'is_active' => true,
            'published_at' => now(),
        ]);

        $this->createUsersWithAcceptedTerms(2, $version);
        $this->countQueries(fn () => Livewire::test(ListUsers::class));

        $few = $this->countQueries(fn () => Livewire::test(ListUsers::class));
        $this->createUsersWithAcceptedTerms(4, $version);
        $many = $this->countQueries(fn () => Livewire::test(ListUsers::class));

        $this->assertSame($few, $many);
    }

    private function countQueries(callable $render): int
    {
        $this->app->forgetInstance(AuditEventPresenter::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $render();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function recordAccessGrantedEvents(int $count): void
    {
        foreach (range(1, $count) as $_) {
            AuditEvent::create([
                'aggregate_uuid' => (string) Str::uuid(),
                'aggregate_version' => 1,
                'event_version' => 1,
                'event_class' => CompartmentAccessGranted::class,
                'event_properties' => [
                    'userId' => User::factory()->create()->id,
                    'actorUserId' => User::factory()->create()->id,
                    'compartmentUuid' => (string) Compartment::factory()->create()->id,
                    'grantedAt' => now()->toIso8601String(),
                ],
                'meta_data' => [],
                'created_at' => now(),
            ]);
        }
    }

    private function createUsersWithAcceptedTerms(int $count, TermsDocumentVersion $version): void
    {
        foreach (User::factory()->count($count)->create() as $user) {
            UserTermsAcceptance::query()->create([
                'user_id' => $user->id,
                'terms_document_id' => $version->terms_document_id,
                'terms_document_version_id' => $version->id,
                'accepted_at' => now(),
            ]);
        }
    }
}
