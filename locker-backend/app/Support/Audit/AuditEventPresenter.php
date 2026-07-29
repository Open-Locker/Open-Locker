<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Models\Compartment;
use App\Models\Group;
use App\Models\LockerBank;
use App\Models\User;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;

/**
 * Turns raw {@see EloquentStoredEvent} rows into the curated, human-readable
 * shape used by the admin audit log (issue #109, ADR-0026).
 *
 * Only the event classes listed in {@see self::CATEGORIES} are considered
 * "auditable" — high-volume telemetry events (heartbeats, door-state changes,
 * raw device/command traffic) are deliberately excluded so the log stays a
 * record of *who did what*, not a firehose.
 *
 * Lookups are memoised per request so rendering a page of rows does not issue a
 * query per row for the same actor/compartment/group.
 *
 * @see docs/adr/0026-admin-audit-log.md
 */
class AuditEventPresenter
{
    private const EVENT_NAMESPACE = 'App\\StorableEvents\\';

    /**
     * Whitelist: short event class name => category key. Anything not listed
     * here is excluded from the audit log.
     */
    private const CATEGORIES = [
        // Access
        'CompartmentOpenRequested' => 'access',
        'CompartmentOpenAuthorized' => 'access',
        'CompartmentOpenDenied' => 'access',
        'CompartmentOpened' => 'access',
        'CompartmentOpeningFailed' => 'access',
        'CompartmentAccessGranted' => 'access',
        'CompartmentAccessRevoked' => 'access',
        'GroupCompartmentAccessGranted' => 'access',
        'GroupCompartmentAccessRevoked' => 'access',
        'CompartmentContentNoteUpdated' => 'access',

        // Devices / lockers
        'LockerWasProvisioned' => 'devices',
        'LockerProvisioningFailed' => 'devices',
        'LockerConnectionLost' => 'devices',
        'LockerConnectionRestored' => 'devices',
        'LockerConfigAcknowledged' => 'devices',
        'LockerConfigAckFailed' => 'devices',

        // Admin (users, groups, roles, permissions)
        'GroupCreated' => 'admin',
        'UserAddedToGroup' => 'admin',
        'UserRemovedFromGroup' => 'admin',
        'UserRoleGranted' => 'admin',
        'UserRoleRevoked' => 'admin',

        // Terms & legal
        'TermsDocumentCreated' => 'terms',
        'TermsVersionPublished' => 'terms',
        'TermsVersionActivated' => 'terms',
        'UserAcceptedTermsVersion' => 'terms',
    ];

    /** @var array<int, string|null> */
    private array $userCache = [];

    /** @var array<string, string|null> */
    private array $compartmentCache = [];

    /** @var array<string, string|null> */
    private array $groupCache = [];

    /** @var array<string, string|null> */
    private array $lockerBankCache = [];

    /**
     * Fully-qualified class names of every auditable event.
     *
     * @return list<string>
     */
    public function auditableEventClasses(): array
    {
        return array_map(
            static fn (string $short): string => self::EVENT_NAMESPACE.$short,
            array_keys(self::CATEGORIES),
        );
    }

    /**
     * Category key => translated label, for tabs and filters.
     *
     * @return array<string, string>
     */
    public function categories(): array
    {
        return [
            'access' => __('Access'),
            'devices' => __('Devices & Lockers'),
            'admin' => __('Administration'),
            'terms' => __('Terms & Legal'),
        ];
    }

    /**
     * Fully-qualified class names belonging to a category.
     *
     * @return list<string>
     */
    public function classesForCategory(string $category): array
    {
        $classes = [];

        foreach (self::CATEGORIES as $short => $cat) {
            if ($cat === $category) {
                $classes[] = self::EVENT_NAMESPACE.$short;
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

        foreach (array_keys(self::CATEGORIES) as $short) {
            $options[self::EVENT_NAMESPACE.$short] = $this->label(self::EVENT_NAMESPACE.$short);
        }

        asort($options);

        return $options;
    }

    public function categoryLabel(?string $eventClass): ?string
    {
        $category = self::CATEGORIES[$this->short($eventClass)] ?? null;

        return $category ? ($this->categories()[$category] ?? null) : null;
    }

    /**
     * Human-readable label for an event type.
     */
    public function label(?string $eventClass): string
    {
        return match ($this->short($eventClass)) {
            'CompartmentOpenRequested' => __('Open requested'),
            'CompartmentOpenAuthorized' => __('Open authorized'),
            'CompartmentOpenDenied' => __('Open denied'),
            'CompartmentOpened' => __('Compartment opened'),
            'CompartmentOpeningFailed' => __('Opening failed'),
            'CompartmentAccessGranted' => __('Access granted'),
            'CompartmentAccessRevoked' => __('Access revoked'),
            'GroupCompartmentAccessGranted' => __('Group access granted'),
            'GroupCompartmentAccessRevoked' => __('Group access revoked'),
            'CompartmentContentNoteUpdated' => __('Content note updated'),
            'LockerWasProvisioned' => __('Locker provisioned'),
            'LockerProvisioningFailed' => __('Provisioning failed'),
            'LockerConnectionLost' => __('Connection lost'),
            'LockerConnectionRestored' => __('Connection restored'),
            'LockerConfigAcknowledged' => __('Configuration acknowledged'),
            'LockerConfigAckFailed' => __('Configuration failed'),
            'GroupCreated' => __('Group created'),
            'UserAddedToGroup' => __('User added to group'),
            'UserRemovedFromGroup' => __('User removed from group'),
            'UserRoleGranted' => __('Role granted'),
            'UserRoleRevoked' => __('Role revoked'),
            'TermsDocumentCreated' => __('Terms document created'),
            'TermsVersionPublished' => __('Terms version published'),
            'TermsVersionActivated' => __('Terms version activated'),
            'UserAcceptedTermsVersion' => __('Terms accepted'),
            default => $this->short($eventClass) ?? __('Unknown'),
        };
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
            'LockerWasProvisioned' => __('Locker bank :bank was provisioned', [
                'bank' => $this->lockerBank($p['lockerBankUuid'] ?? null),
            ]),
            'LockerProvisioningFailed' => __('Locker provisioning failed (:reason)', [
                'reason' => $p['reason'] ?? '-',
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

        return $this->userCache[$id] ??= (User::find($id)?->fullName() ?: __('User #:id', ['id' => $id]));
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

        return $this->groupCache[$uuid] ??= (Group::find($uuid)?->name ?: __('Unknown'));
    }

    private function lockerBank(?string $uuid): string
    {
        if ($uuid === null) {
            return __('Unknown');
        }

        return $this->lockerBankCache[$uuid] ??= (LockerBank::find($uuid)?->name ?: __('Unknown'));
    }
}
