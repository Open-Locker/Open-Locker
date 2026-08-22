<?php

declare(strict_types=1);

namespace App\Observability;

use OpenTelemetry\API\Globals;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;

/**
 * Pushes finished spans out of the batch processor.
 *
 * The MQTT listener runs for days at a time and is not a queue worker or an
 * Octane server, so none of the package's worker-mode detectors recognise it
 * and nothing ever triggers a periodic flush. Spans would simply accumulate in
 * memory until the process ends — which, for a listener, is "never".
 *
 * Only inbound messages worth tracing get here (heartbeats and snapshots are
 * excluded), so flushing per message is cheap and buys immediate visibility,
 * which is the point of tracing during an incident.
 */
final class SpanFlusher
{
    public static function flush(): void
    {
        $tracerProvider = Globals::tracerProvider();

        // A no-op provider when tracing is off, and the SDK's own provider when
        // it is on. Only the latter can flush.
        if ($tracerProvider instanceof TracerProviderInterface) {
            $tracerProvider->forceFlush();
        }
    }
}
