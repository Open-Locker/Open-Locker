<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\StorableEvents\GroupArchived;
use App\Support\Audit\Audited;
use App\Support\Audit\AuditEventPresenter;
use App\Support\Audit\NotAudited;
use ReflectionClass;
use Tests\TestCase;

/**
 * Every stored event makes a conscious audit-log decision (#202). Without this,
 * a new event is silently absent from the log until someone notices, as
 * happened with GroupArchived.
 */
class AuditEventClassificationTest extends TestCase
{
    public function test_the_classification_sees_every_stored_event(): void
    {
        // Every other test here would pass on an empty list, so make sure the
        // discovery actually finds the events, including a known one.
        $classes = AuditEventPresenter::storedEventClasses();

        $this->assertNotEmpty($classes);
        $this->assertContains(GroupArchived::class, $classes);
        // Discovery reads app/StorableEvents without descending into folders.
        $this->assertSame([], glob(app_path('StorableEvents/*'), GLOB_ONLYDIR) ?: []);
    }

    public function test_every_stored_event_is_either_audited_or_not(): void
    {
        $unclassified = [];
        $both = [];

        foreach (AuditEventPresenter::storedEventClasses() as $class) {
            $reflection = new ReflectionClass($class);
            $audited = $reflection->getAttributes(Audited::class) !== [];
            $notAudited = $reflection->getAttributes(NotAudited::class) !== [];

            if (! $audited && ! $notAudited) {
                $unclassified[] = $class;
            }

            if ($audited && $notAudited) {
                $both[] = $class;
            }
        }

        $this->assertSame([], $unclassified, 'Add #[Audited(AuditCategory::..., \'Label\')] to show these events in the admin audit log, or #[NotAudited(\'reason\')] to keep them out.');
        $this->assertSame([], $both, 'These events are marked both Audited and NotAudited; keep one.');
    }

    public function test_audited_events_have_a_label_with_a_german_translation(): void
    {
        /** @var array<string, string> $german */
        $german = json_decode((string) file_get_contents(lang_path('de.json')), true, flags: JSON_THROW_ON_ERROR);
        $missing = [];

        foreach (AuditEventPresenter::storedEventClasses() as $class) {
            $attribute = (new ReflectionClass($class))->getAttributes(Audited::class)[0] ?? null;

            if ($attribute === null) {
                continue;
            }

            $label = $attribute->newInstance()->label;

            if (trim($label) === '' || ! array_key_exists($label, $german)) {
                $missing[] = "{$class}: '{$label}'";
            }
        }

        $this->assertSame([], $missing, 'Audited labels must be non-empty and translated in lang/de.json.');
    }

    public function test_excluded_events_say_why(): void
    {
        $withoutReason = [];

        foreach (AuditEventPresenter::storedEventClasses() as $class) {
            $attribute = (new ReflectionClass($class))->getAttributes(NotAudited::class)[0] ?? null;

            if ($attribute !== null && trim($attribute->newInstance()->reason) === '') {
                $withoutReason[] = $class;
            }
        }

        $this->assertSame([], $withoutReason);
    }
}
