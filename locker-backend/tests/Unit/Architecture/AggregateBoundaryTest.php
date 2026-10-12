<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use PHPUnit\Architecture\ArchitectureAsserts;
use PHPUnit\Framework\TestCase;

/**
 * Events are recorded through aggregates only from services, where
 * authorization and orchestration live. Filament, controllers, MQTT handlers,
 * models and everything else call a service instead.
 */
class AggregateBoundaryTest extends TestCase
{
    use ArchitectureAsserts;

    public function test_only_services_and_aggregates_depend_on_aggregates(): void
    {
        $aggregates = $this->layer()->leaveByNameStart('App\\Aggregates');
        $everythingElse = $this->layer()
            ->leaveByNameStart('App\\')
            ->excludeByNameStart('App\\Aggregates')
            ->excludeByNameStart('App\\Services');

        $this->assertDoesNotDependOn($everythingElse, $aggregates);
    }
}
