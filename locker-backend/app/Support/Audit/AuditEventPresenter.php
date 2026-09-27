<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Models\Compartment;
use App\Models\Group;
use App\Models\LockerBank;
use App\Models\User;
use ReflectionClass;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;

/**
 * Turns raw {@see EloquentStoredEvent} rows into the curated, human-readable
 * shape used by the admin audit log (#109).
 *
 * Which events appear, under which category and label, is declared on each
 * event class with {@see Audited} or {@see NotAudited} (#202): high-volume
 * telemetry and raw device/command traffic are marked NotAudited so the log
 * stays a record of *who did what*, not a firehose.
 *
 * Lookups are memoised per request so rendering a page of rows does not issue a
 * query per row for the same actor/compartment/group.
 */
class AuditEventPresenter
{
    private const EVENT_NAMESPACE = 'App\\StorableEvents\\';

    /**
     * Audited event class => its attribute, read once from the event classes.
     *
     * @var array<string, Audited>|null
     */
    private ?array $audited = null;

    /** @var array<int, string|null> */
    private array $userCache = [];

    /** @var array<string, string|null> */
    private array $compartmentCache = [];

    /** @var array<string, string|null> */
    private array $groupCache = [];

    /** @var array<string, string|null> */
    private array $lockerBankCache = [];

    /**
     * Every class in app/StorableEvents, auditable or not. Shared with
     * AuditEventClassificationTest so both look at the same set.
     *
     * @return list<string>
     */
    public static function storedEventClasses(): array
    {
        $files = glob(app_path('StorableEvents/*.php')) ?: [];
        sort($files);

        return array_map(
            static fn (string $file): string => self::EVENT_NAMESPACE.basename($file, '.php'),
            $files,
        );
    }

    /**
     * Fully-qualified class names of every auditable event.
     *
     * @return list<string>
     */
    public function auditableEventClasses(): array
    {
        return array_keys($this->audited());
    }

    /**
     * Category key => translated label, for tabs and filters.
     *
     * @return array<string, string>
     */
    public function categories(): array
    {
        $categories = [];

        foreach (AuditCategory::cases() as $category) {
            $categories[$category->value] = $category->label();
        }

        return $categories;
    }

    /**
     * Fully-qualified class names belonging to a category.
     *
     * @return list<string>
     */
    public function classesForCategory(string $category): array
    {
        $classes = [];

        foreach ($this->audited() as $class => $audited) {
            if ($audited->category->value === $category) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * Event class => translated label options, for the type filter.
     *
     * @return array<string, string>
     */
    public function eventTypeOptions(): array
    {
        $options = [];

        foreach (array_keys($this->audited()) as $class) {
            $options[$class] = $this->label($class);
        }

        asort($options);

        return $options;
    }

    public function categoryLabel(?string $eventClass): ?string
    {
        return ($this->audited()[$eventClass ?? ''] ?? null)?->category->label();
    }

    /**
     * Translated label of an audited event type; the short class name for any
     * other class, which the log never lists.
     */
    public function label(?string $eventClass): string
    {
        $audited = $this->audited()[$eventClass ?? ''] ?? null;

        if ($audited !== null) {
            return __($audited->label);
        }

        // Only reachable for classes the log never lists: a NotAudited event,
        // or an old stored event whose class no longer exists.
        return $this->short($eventClass) ?? __('Unknown');
    }

    /**
     * One-line, human-readable description of what happened.
     */
    public function describe(EloquentStoredEvent $event): string
    {
        $p = $event->event_properties;
        $short = $this->short($event->event_class);

        return match ($short) {
            'CompartmentOpenRequested' => __(':actor requested to open compartment :compartment', [
                'actor' => $this->user($p['actorUserId'] ?? null),
                'compartment' => $this->compartment($p['compartmentUuid'] ?? null),
            ]),
            'CompartmentOpenAuthorized' => __('Opening of compartment :compartment authorized for :actor (:type)', [
                'compartment' => $this->compartment($p['compartmentUuid'] ?? null),
                'actor' => $this->user($p['actorUserId'] ?? null),
                'type' => $p['authorizationType'] ?? '-',
            ]),
            'CompartmentOpenDenied' => __('Opening of compartment :compartment denied for :actor (:reason)', [
                'compartment' => $this->compartment($p['compartmentUuid'] ?? null),
                'actor' => $this->user($p['actorUserId'] ?? null),
                'reason' => $p['reason'] ?? '-',
            ]),
            'CompartmentOpened' => __('Compartment :compartment was opened', [
                'compartment' => $this->compartment($p['compartmentUuid'] ?? null),
            ]),
            'CompartmentOpenAcknowledged' => __('Unlock pulse sent to compartment :compartment', [
                'compartment' => $this->compartment($p['compartmentUuid'] ?? null),
            ]),
            'CompartmentDoorOpenDetected' => __('Compartment :compartment door was observed open', [
                'compartment' => $this->compartment($p['compartmentUuid'] ?? null),
            ]),
            'CompartmentDoorAlreadyOpen' => __('Compartment :compartment was already open when the pulse was sent', [
                'compartment' => $this->compartment($p['compartmentUuid'] ?? null),
            ]),
            'CompartmentOpenNotDetected' => __('Compartment :compartment did not open after the unlock pulse (:error)', [
                'compartment' => $this->compartment($p['compartmentUuid'] ?? null),
                'error' => $p['errorCode'] ?? '-',
            ]),
            'CompartmentUncommandedOpenDetected' => __('Compartment :compartment was opened with no command behind it', [
                'compartment' => $this->compartment($p['compartmentUuid'] ?? null),
            ]),
            'CompartmentOpeningFailed' => __('Opening of compartment :compartment failed (:error)', [
                'compartment' => $this->compartment($p['compartmentUuid'] ?? null),
                'error' => $p['errorCode'] ?? $p['message'] ?? '-',
            ]),
            'CompartmentAccessGranted' => __(':actor granted :user access to compartment :compartment', [
                'actor' => $this->user($p['actorUserId'] ?? null),
                'user' => $this->user($p['userId'] ?? null),
                'compartment' => $this->compartment($p['compartmentUuid'] ?? null),
            ]),
            'CompartmentAccessRevoked' => __(':actor revoked access to compartment :compartment from :user', [
                'actor' => $this->user($p['actorUserId'] ?? null),
                'compartment' => $this->compartment($p['compartmentUuid'] ?? null),
                'user' => $this->user($p['userId'] ?? null),
            ]),
            'GroupCompartmentAccessGranted' => __(':actor granted group :group access to compartment :compartment', [
                'actor' => $this->user($p['actorUserId'] ?? null),
                'group' => $this->group($p['groupUuid'] ?? null),
                'compartment' => $this->compartment($p['compartmentUuid'] ?? null),
            ]),
            'GroupCompartmentAccessRevoked' => __(':actor revoked access to compartment :compartment from group :group', [
                'actor' => $this->user($p['actorUserId'] ?? null),
                'compartment' => $this->compartment($p['compartmentUuid'] ?? null),
                'group' => $this->group($p['groupUuid'] ?? null),
            ]),
            'CompartmentContentNoteUpdated' => __(':actor updated the content note of compartment :compartment', [
                'actor' => $this->user($p['actorUserId'] ?? null),
                'compartment' => $this->compartment($p['compartmentUuid'] ?? null),
            ]),
            // The audit log is the durable record if the email is lost, so it keeps the words.
            'CompartmentHelpRequested' => __(':actor asked for help with compartment :compartment: ":message"', [
                'actor' => $this->user($p['actorUserId'] ?? null),
                'compartment' => $this->compartment($p['compartmentUuid'] ?? null),
                'message' => $p['message'] ?? '',
            ]),
            'LockerWasProvisioned' => __('Locker bank :bank was provisioned', [
                'bank' => $this->lockerBank($p['lockerBankUuid'] ?? null),
            ]),
            'LockerProvisioningFailed' => __('Locker provisioning failed (:reason)', [
                'reason' => $p['reason'] ?? '-',
            ]),
            // "Provisioned" was already recorded, but the device never got its
            // MQTT credentials; without this entry the log reads as a success.
            // The stored reason is the raw exception message, which can carry SQL
            // bindings such as the MQTT credential hash, so it stays in the log.
            'LockerProvisioningReplyFailed' => __('Sending the credentials to locker bank :bank failed. Details are in the server log.', [
                'bank' => $this->lockerBank($p['lockerBankUuid'] ?? null),
            ]),
            // Never renders token material: the event does not carry it.
            'LockerProvisioningReset' => __(':actor reset provisioning for locker bank :bank', [
                'actor' => $this->user($p['actorUserId'] ?? null),
                'bank' => $this->lockerBank($p['lockerBankUuid'] ?? null),
            ]),
            'LockerProvisioningTokenIssued' => __(':actor issued a provisioning token for locker bank :bank', [
                'actor' => $this->user($p['actorUserId'] ?? null),
                'bank' => $this->lockerBank($p['lockerBankUuid'] ?? null),
            ]),
            'LockerConnectionLost' => __('Connection to locker bank :bank was lost (:reason)', [
                'bank' => $this->lockerBank($p['lockerBankUuid'] ?? null),
                'reason' => $p['reason'] ?? '-',
            ]),
            'LockerConnectionRestored' => __('Connection to locker bank :bank was restored', [
                'bank' => $this->lockerBank($p['lockerBankUuid'] ?? null),
            ]),
            'LockerConfigAcknowledged' => __('Locker bank :bank acknowledged its configuration', [
                'bank' => $this->lockerBank($p['lockerBankUuid'] ?? null),
            ]),
            'LockerConfigAckFailed' => __('Locker bank :bank failed to apply its configuration (:error)', [
                'bank' => $this->lockerBank($p['lockerBankUuid'] ?? null),
                'error' => $p['errorCode'] ?? $p['message'] ?? '-',
            ]),
            'GroupCreated' => __(':actor created group :group', [
                'actor' => $this->user($p['actorUserId'] ?? null),
                'group' => $p['name'] ?? $this->group($p['groupUuid'] ?? null),
            ]),
            'GroupArchived' => __(':actor archived group :group', [
                'actor' => $this->user($p['actorUserId'] ?? null),
                'group' => $this->group($p['groupUuid'] ?? null),
            ]),
            'UserAddedToGroup' => __(':actor added :user to group :group', [
                'actor' => $this->user($p['actorUserId'] ?? null),
                'user' => $this->user($p['userId'] ?? null),
                'group' => $this->group($p['groupUuid'] ?? null),
            ]),
            'UserRemovedFromGroup' => __(':actor removed :user from group :group', [
                'actor' => $this->user($p['actorUserId'] ?? null),
                'user' => $this->user($p['userId'] ?? null),
                'group' => $this->group($p['groupUuid'] ?? null),
            ]),
            'UserRoleGranted' => __(':actor granted role :role to :user', [
                'actor' => $this->user($p['actorUserId'] ?? null),
                'role' => $p['role'] ?? '-',
                'user' => $this->user($p['userId'] ?? null),
            ]),
            'UserRoleRevoked' => __(':actor revoked role :role from :user', [
                'actor' => $this->user($p['actorUserId'] ?? null),
                'role' => $p['role'] ?? '-',
                'user' => $this->user($p['userId'] ?? null),
            ]),
            'TermsDocumentCreated' => __(':actor created terms document :name', [
                'actor' => $this->user($p['createdByUserId'] ?? null),
                'name' => $p['name'] ?? '-',
            ]),
            'TermsVersionPublished' => __(':actor published version :version of :name', [
                'actor' => $this->user($p['publishedByUserId'] ?? null),
                'version' => $p['version'] ?? '-',
                'name' => $p['documentNameSnapshot'] ?? ('#'.($p['documentId'] ?? '-')),
            ]),
            'TermsVersionActivated' => __(':actor activated version :version of document #:document', [
                'actor' => $this->user($p['activatedByUserId'] ?? null),
                'version' => $p['version'] ?? '-',
                'document' => $p['documentId'] ?? '-',
            ]),
            'UserAcceptedTermsVersion' => __(':user accepted terms version :version', [
                'user' => $this->user($p['userId'] ?? null),
                'version' => $p['version'] ?? '-',
            ]),
            default => $this->label($event->event_class),
        };
    }

    /**
     * The `event_properties` keys that hold the id of the user who *performed*
     * an event (as opposed to a target user). Used by the audit log's actor
     * filter. `userId` is intentionally excluded — it is the target in most
     * events and only the actor in {@see UserAcceptedTermsVersion}.
     *
     * @return list<string>
     */
    public function actorJsonKeys(): array
    {
        return ['actorUserId', 'createdByUserId', 'publishedByUserId', 'activatedByUserId'];
    }

    /**
     * Display name of the user who caused the event, if any. System- and
     * device-originated events have no actor and return null.
     */
    public function actorName(EloquentStoredEvent $event): ?string
    {
        $p = $event->event_properties;

        $actorId = $p['actorUserId']
            ?? $p['createdByUserId']
            ?? $p['publishedByUserId']
            ?? $p['activatedByUserId']
            // UserAcceptedTermsVersion is self-acted by the user.
            ?? ($this->short($event->event_class) === 'UserAcceptedTermsVersion' ? ($p['userId'] ?? null) : null);

        return $actorId !== null ? $this->user((int) $actorId) : null;
    }

    /**
     * @return array<string, Audited>
     */
    private function audited(): array
    {
        if ($this->audited !== null) {
            return $this->audited;
        }

        $audited = [];

        foreach (self::storedEventClasses() as $class) {
            if (! class_exists($class)) {
                continue;
            }

            $attribute = (new ReflectionClass($class))->getAttributes(Audited::class)[0] ?? null;

            if ($attribute !== null) {
                $audited[$class] = $attribute->newInstance();
            }
        }

        return $this->audited = $audited;
    }

    private function short(?string $eventClass): ?string
    {
        if ($eventClass === null) {
            return null;
        }

        return class_basename($eventClass);
    }

    private function user(int|string|null $id): string
    {
        if ($id === null) {
            return __('System');
        }

        $id = (int) $id;

        return $this->userCache[$id] ??= (User::find($id)?->fullName() ?: (string) __('User #:id', ['id' => $id]));
    }

    private function compartment(?string $uuid): string
    {
        if ($uuid === null) {
            return __('Unknown');
        }

        return $this->compartmentCache[$uuid] ??= (function () use ($uuid): string {
            $compartment = Compartment::with('lockerBank')->find($uuid);

            if ($compartment === null) {
                return __('Unknown');
            }

            $bank = $compartment->lockerBank?->name;

            return $bank !== null
                ? $bank.' / #'.$compartment->number
                : '#'.$compartment->number;
        })();
    }

    private function group(?string $uuid): string
    {
        if ($uuid === null) {
            return __('Unknown');
        }

        return $this->groupCache[$uuid] ??= (Group::find($uuid)?->name ?: (string) __('Unknown'));
    }

    private function lockerBank(?string $uuid): string
    {
        if ($uuid === null) {
            return __('Unknown');
        }

        return $this->lockerBankCache[$uuid] ??= (LockerBank::find($uuid)?->name ?: (string) __('Unknown'));
    }
}
