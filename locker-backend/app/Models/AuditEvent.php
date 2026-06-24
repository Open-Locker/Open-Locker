<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Audit\AuditEventPresenter;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;

/**
 * Read-only view over the event store ({@see EloquentStoredEvent}) used as the
 * Filament model behind the admin audit log (issue #109, ADR-0026). Shares the
 * `stored_events` table and is never written to directly.
 *
 * The curated whitelist that scopes which events are admin-visible lives in
 * {@see AuditEventPresenter} (single source of truth); the resource applies it
 * in its query.
 */
class AuditEvent extends EloquentStoredEvent {}
