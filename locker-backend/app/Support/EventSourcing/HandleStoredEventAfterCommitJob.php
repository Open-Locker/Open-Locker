<?php

declare(strict_types=1);

namespace App\Support\EventSourcing;

use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Spatie\EventSourcing\StoredEvents\HandleStoredEventJob;

final class HandleStoredEventAfterCommitJob extends HandleStoredEventJob implements ShouldQueueAfterCommit {}
