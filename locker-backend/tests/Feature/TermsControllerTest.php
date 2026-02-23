<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\TermsDocument;
use App\Models\TermsDocumentVersion;
use App\Models\User;
use App\Models\UserTermsAcceptance;
use App\Notifications\Terms\TermsVersionPublishedNotification;
use App\Services\TermsService;
use App\StorableEvents\UserAcceptedTermsVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use LogicException;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

class TermsControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_user_returns_terms_status_fields(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $document = TermsDocument::query()->create(['name' => 'AGB']);
        $version = TermsDocumentVersion::query()->create([
            'terms_document_id' => $document->id,
            'version' => 1,
            'content' => '<p>Version 1</p>',
            'is_published' => true,
            'is_active' => true,
            'published_at' => now(),
        ]);

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/user')
            ->assertStatus(200)
            ->assertJson([
                'terms_last_accepted_version' => null,
                'terms_current_version' => 1,
                'terms_current_accepted' => false,
            ]);

        UserTermsAcceptance::query()->create([
            'user_id' => $user->id,
            'terms_document_id' => $document->id,
            'terms_document_version_id' => $version->id,
            'accepted_at' => now(),
        ]);

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/user')
            ->assertStatus(200)
            ->assertJson([
                'terms_last_accepted_version' => 1,
                'terms_current_version' => 1,
                'terms_current_accepted' => true,
            ]);
    }

    public function test_user_can_fetch_current_terms_via_api(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $document = TermsDocument::query()->create(['name' => 'AGB']);
        TermsDocumentVersion::query()->create([
            'terms_document_id' => $document->id,
            'version' => 1,
            'content' => '<p>Current Terms</p>',
            'is_published' => true,
            'is_active' => true,
            'published_at' => now(),
        ]);

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/terms/current')
            ->assertStatus(200)
            ->assertJson([
                'document_name' => 'AGB',
                'version' => 1,
                'content' => '<p>Current Terms</p>',
                'current_accepted' => false,
            ]);
    }

    public function test_user_can_accept_current_terms_via_api(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth_token')->plainTextToken;
        $termsService = app(TermsService::class);

        $termsService->publishNewVersion('AGB', '<p>Version 1</p>', $user);

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/api/terms/accept')
            ->assertStatus(200)
            ->assertJson([
                'accepted_version' => 1,
            ]);

        $document = TermsDocument::query()->firstOrFail();
        $version = TermsDocumentVersion::query()->where('terms_document_id', $document->id)->where('version', 1)->firstOrFail();

        $this->assertDatabaseHas('user_terms_acceptances', [
            'user_id' => $user->id,
            'terms_document_id' => $document->id,
            'terms_document_version_id' => $version->id,
        ]);

        $this->assertTrue(
            EloquentStoredEvent::query()->where('event_class', UserAcceptedTermsVersion::class)->exists()
        );
    }

    public function test_terms_gate_blocks_domain_routes_until_acceptance(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth_token')->plainTextToken;
        $termsService = app(TermsService::class);

        $termsService->publishNewVersion('AGB', '<p>Version 1</p>', $user);

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/items')
            ->assertStatus(403)
            ->assertJson([
                'code' => 'terms_not_accepted',
            ]);

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->putJson('/api/profile', [
            'name' => 'Changed Name',
            'email' => $user->email,
        ])->assertStatus(200);
    }

    public function test_new_published_version_requires_reacceptance_and_sends_mail(): void
    {
        Notification::fake();

        $admin = User::factory()->create();
        $user = User::factory()->create();
        $termsService = app(TermsService::class);

        $termsService->publishNewVersion('AGB', '<p>Version 1</p>', $admin);

        Notification::assertSentTo([$admin, $user], TermsVersionPublishedNotification::class);

        $token = $user->createToken('auth_token')->plainTextToken;
        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/api/terms/accept')
            ->assertStatus(200);

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/user')
            ->assertStatus(200)
            ->assertJson([
                'terms_current_version' => 1,
                'terms_last_accepted_version' => 1,
                'terms_current_accepted' => true,
            ]);

        $termsService->publishNewVersion('AGB', '<p>Version 2</p>', $admin);

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/user')
            ->assertStatus(200)
            ->assertJson([
                'terms_current_version' => 2,
                'terms_last_accepted_version' => 1,
                'terms_current_accepted' => false,
            ]);
    }

    public function test_published_versions_are_immutable(): void
    {
        $actor = User::factory()->create();
        $termsService = app(TermsService::class);
        $version = $termsService->publishNewVersion('AGB', '<p>Version 1</p>', $actor);

        $this->expectException(LogicException::class);

        $termsService->updateUnpublishedVersion($version->fresh(), '<p>Changed</p>');
    }

    public function test_draft_publish_flow_creates_editable_draft_and_publishes_explicitly(): void
    {
        Notification::fake();

        $actor = User::factory()->create();
        $termsService = app(TermsService::class);

        $draft = $termsService->createDraftVersion('AGB', '<p>Draft v1</p>', $actor);

        $this->assertFalse((bool) $draft->is_published);
        $this->assertFalse((bool) $draft->is_active);
        $this->assertSame(1, $draft->version);
        $this->assertSame('AGB', $draft->document_name_snapshot);

        $termsService->updateUnpublishedVersion($draft, '<p>Draft v1 updated</p>');
        $published = $termsService->publishDraftVersion($draft->fresh(), $actor);

        $this->assertTrue((bool) $published->fresh()->is_published);
        $this->assertTrue((bool) $published->fresh()->is_active);
        $this->assertNotNull($published->fresh()->published_at);

        Notification::assertSentTo([$actor], TermsVersionPublishedNotification::class);
    }

    public function test_create_draft_reuses_existing_open_draft_instead_of_creating_second_one(): void
    {
        $actor = User::factory()->create();
        $termsService = app(TermsService::class);

        $firstDraft = $termsService->createDraftVersion('AGB', '<p>Draft v1</p>', $actor);
        $secondDraft = $termsService->createDraftVersion('AGB', '<p>Draft v1 changed</p>', $actor);

        $this->assertSame($firstDraft->id, $secondDraft->id);
        $this->assertSame('<p>Draft v1 changed</p>', $secondDraft->content);

        $draftCount = TermsDocumentVersion::query()
            ->where('terms_document_id', $firstDraft->terms_document_id)
            ->where('is_published', false)
            ->count();

        $this->assertSame(1, $draftCount);
    }
}
