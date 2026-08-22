<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Compartment;
use App\Models\User;
use App\Mqtt\Handlers\CompartmentSnapshotHandler;
use App\Mqtt\Handlers\DeviceEventHandler;
use App\Mqtt\Handlers\LockerHeartbeatHandler;
use App\Mqtt\Handlers\RegistrationHandler;
use App\Mqtt\MqttPublisher;
use App\Observability\MqttTraceContext;
use App\Observability\TraceAttributes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PhpMqtt\Client\Facades\MQTT;
use Tests\Fakes\FakeMqttClient;
use Tests\TestCase;

/**
 * Covers MQTT tracing: spans for inbound processing, the domain
 * attributes they carry, and the periodic topics deliberately left untraced.
 */
class MqttTracingTest extends TestCase
{
    use RefreshDatabase;

    private const LOCKER_UUID = '11111111-1111-1111-1111-111111111111';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        // The tracer provider is process-global, so without this every test
        // would also see the spans of the ones before it.
        $exporter = app(SpanExporterInterface::class);
        if ($exporter instanceof InMemoryExporter) {
            $exporter->getStorage()->exchangeArray([]);
        }
    }

    public function test_inbound_device_event_is_traced_with_domain_attributes(): void
    {
        app(DeviceEventHandler::class)->handleMessage(
            sprintf('locker/%s/event', self::LOCKER_UUID),
            (string) json_encode([
                'message_id' => '33333333-3333-3333-3333-333333333333',
                'event' => 'door_opened',
                'event_id' => '44444444-4444-4444-4444-444444444444',
                'timestamp' => now()->toIso8601String(),
                'data' => [
                    'compartment_number' => 7,
                ],
            ]),
        );

        $span = $this->findSpan(sprintf('mqtt process locker/%s/event', self::LOCKER_UUID));

        $this->assertNotNull($span, 'Expected a span for the inbound device event.');

        $attributes = $span->getAttributes()->toArray();

        $this->assertSame('mqtt', $attributes[TraceAttributes::MESSAGING_SYSTEM] ?? null);
        $this->assertSame(
            sprintf('locker/%s/event', self::LOCKER_UUID),
            $attributes[TraceAttributes::MESSAGING_DESTINATION] ?? null
        );
        $this->assertSame('33333333-3333-3333-3333-333333333333', $attributes[TraceAttributes::MESSAGING_MESSAGE_ID] ?? null);
        $this->assertSame(self::LOCKER_UUID, $attributes[TraceAttributes::LOCKER_UUID] ?? null);
        $this->assertSame('door_opened', $attributes[TraceAttributes::EVENT] ?? null);
        $this->assertSame(7, $attributes[TraceAttributes::COMPARTMENT_NUMBER] ?? null);
    }

    public function test_heartbeats_are_not_traced(): void
    {
        app(LockerHeartbeatHandler::class)->handleMessage(
            sprintf('locker/%s/state/heartbeat', self::LOCKER_UUID),
            (string) json_encode([
                'message_id' => '55555555-5555-5555-5555-555555555555',
                'timestamp' => now()->toIso8601String(),
                'uptime_seconds' => 120,
            ]),
        );

        $this->assertNull(
            $this->findSpan(sprintf('mqtt process locker/%s/state/heartbeat', self::LOCKER_UUID)),
            'Heartbeats must not be traced.'
        );
    }

    public function test_compartment_snapshots_are_not_traced(): void
    {
        app(CompartmentSnapshotHandler::class)->handleMessage(
            sprintf('locker/%s/state/compartments', self::LOCKER_UUID),
            (string) json_encode([
                'message_id' => '66666666-6666-6666-6666-666666666666',
                'timestamp' => now()->toIso8601String(),
                'compartments' => [
                    ['compartment_number' => 1, 'door_state' => 'closed'],
                ],
            ]),
        );

        $this->assertNull(
            $this->findSpan(sprintf('mqtt process locker/%s/state/compartments', self::LOCKER_UUID)),
            'Compartment snapshots must not be traced.'
        );
    }

    public function test_provisioning_token_never_reaches_a_span(): void
    {
        $token = str_repeat('t0ken', 12);

        // An unknown token still publishes a contract error reply.
        MQTT::shouldReceive('connection')
            ->once()
            ->with('publisher')
            ->andReturn(new FakeMqttClient);

        app(RegistrationHandler::class)->handleMessage(
            sprintf('locker/register/%s', $token),
            (string) json_encode([
                'message_id' => '77777777-7777-7777-7777-777777777777',
                'client_id' => 'prov-client-1',
                'timestamp' => now()->toIso8601String(),
            ]),
        );

        $span = $this->findSpan('mqtt process locker/register/{token}');

        $this->assertNotNull($span, 'Registration messages should still be traced.');

        // "register" is not a locker UUID; only device topics carry one.
        $this->assertArrayNotHasKey(
            TraceAttributes::LOCKER_UUID,
            $span->getAttributes()->toArray()
        );

        $encoded = (string) json_encode([
            'name' => $span->getName(),
            'attributes' => $span->getAttributes()->toArray(),
        ]);

        $this->assertStringNotContainsString($token, $encoded);
    }

    public function test_outbound_publish_stamps_its_own_span_on_the_envelope(): void
    {
        $client = new FakeMqttClient;

        MQTT::shouldReceive('connection')
            ->once()
            ->with('publisher')
            ->andReturn($client);

        $topic = sprintf('locker/%s/command', self::LOCKER_UUID);

        app(MqttPublisher::class)->publish($topic, [
            'transaction_id' => '88888888-8888-8888-8888-888888888888',
            'action' => 'open_compartment',
            'timestamp' => now()->toIso8601String(),
            'data' => ['compartment_number' => 3],
        ]);

        $span = $this->findSpan(sprintf('mqtt publish %s', $topic));

        $this->assertNotNull($span, 'Expected a span for the outbound publish.');

        $published = json_decode($client->published[0]['payload'], true);

        $this->assertIsArray($published);

        // The receiving end must continue from the publish span itself, so the
        // header carries that span's ids and not the surrounding trace's.
        $this->assertStringStartsWith(
            sprintf('00-%s-%s-', $span->getContext()->getTraceId(), $span->getContext()->getSpanId()),
            $published[MqttTraceContext::FIELD] ?? '',
        );
    }

    public function test_inbound_message_continues_the_sender_trace(): void
    {
        $traceId = '4bf92f3577b34da6a3ce929d0e0e4736';
        $senderSpanId = '00f067aa0ba902b7';

        $this->handleDeviceEvent([
            MqttTraceContext::FIELD => sprintf('00-%s-%s-01', $traceId, $senderSpanId),
        ]);

        $span = $this->findSpan(sprintf('mqtt process locker/%s/event', self::LOCKER_UUID));

        $this->assertNotNull($span);
        $this->assertSame($traceId, $span->getContext()->getTraceId());
        $this->assertSame($senderSpanId, $span->getParentContext()->getSpanId());
    }

    public function test_unusable_trace_context_starts_a_fresh_trace_instead_of_rejecting_the_message(): void
    {
        $this->handleDeviceEvent([MqttTraceContext::FIELD => 'not-a-traceparent']);

        $span = $this->findSpan(sprintf('mqtt process locker/%s/event', self::LOCKER_UUID));

        $this->assertNotNull($span, 'A malformed traceparent must not cost us the span.');
        $this->assertFalse($span->getParentContext()->isValid(), 'Expected a root span.');

        // Processing continued: the attributes come from the same payload.
        $this->assertSame(
            '33333333-3333-3333-3333-333333333333',
            $span->getAttributes()->toArray()[TraceAttributes::MESSAGING_MESSAGE_ID] ?? null,
        );
    }

    public function test_trace_context_is_transport_metadata_and_never_a_span_attribute(): void
    {
        $this->handleDeviceEvent([
            MqttTraceContext::FIELD => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
        ]);

        $span = $this->findSpan(sprintf('mqtt process locker/%s/event', self::LOCKER_UUID));

        $this->assertNotNull($span);
        $this->assertArrayNotHasKey(MqttTraceContext::FIELD, $span->getAttributes()->toArray());
    }

    public function test_open_request_and_the_command_it_publishes_share_one_trace(): void
    {
        $user = User::factory()->create();
        $user->makeAdmin();

        $compartment = Compartment::factory()->create();

        $client = new FakeMqttClient;

        MQTT::shouldReceive('connection')
            ->with('publisher')
            ->andReturn($client);

        $this->actingAs($user)
            ->postJson(route('compartments.open', $compartment->id))
            ->assertStatus(202);

        // By name, not by kind: the queue's own dispatch span is a producer too.
        $publish = $this->findSpan(sprintf('mqtt publish locker/%s/command', $compartment->locker_bank_id));
        $request = $this->findSpanByKind(SpanKind::KIND_SERVER);

        $this->assertNotNull($request, 'Expected an HTTP server span for the open request.');
        $this->assertNotNull($publish, 'Expected a producer span for the published command.');

        // The publish happens in a reactor, which runs on the sync queue here;
        // the queue boundary itself is exercised against a real worker.
        $this->assertSame(
            $request->getContext()->getTraceId(),
            $publish->getContext()->getTraceId(),
            'The MQTT command must belong to the trace of the request that caused it.',
        );
    }

    /**
     * The queued-reactor boundary is the one hop where a trace breaks silently,
     * and the sync queue the rest of the suite uses would not show it.
     * This runs the reactor through a real queue: dispatched by the request,
     * picked up afterwards by a worker.
     */
    public function test_trace_survives_the_queued_reactor_boundary(): void
    {
        config(['queue.default' => 'database']);

        $user = User::factory()->create();
        $user->makeAdmin();

        $compartment = Compartment::factory()->create();

        $client = new FakeMqttClient;

        MQTT::shouldReceive('connection')
            ->with('publisher')
            ->andReturn($client);

        $this->actingAs($user)
            ->postJson(route('compartments.open', $compartment->id))
            ->assertStatus(202);

        $this->assertEmpty($client->published, 'The reactor should still be waiting on the queue.');

        $request = $this->findSpanByKind(SpanKind::KIND_SERVER);
        $this->assertNotNull($request);

        // The job payload is what carries the trace across the boundary.
        $queued = DB::table('jobs')->get();
        $this->assertNotEmpty($queued, 'Expected the reactor to be queued.');

        foreach ($queued as $job) {
            $payload = json_decode($job->payload, true);
            $this->assertIsArray($payload);
            $this->assertStringContainsString(
                $request->getContext()->getTraceId(),
                $payload[MqttTraceContext::FIELD] ?? '',
                'A queued job must carry the trace of the request that dispatched it.',
            );
        }

        $this->artisan('queue:work', [
            '--queue' => 'events',
            '--stop-when-empty' => true,
            // The worker inherits a PHP process that has already run the rest of
            // the suite; without this it exits on the memory limit (code 12)
            // before it ever reaches the job.
            '--memory' => 4096,
        ])->assertExitCode(0);

        $this->assertNotEmpty($client->published, 'Expected the worker to publish the command.');

        $publish = $this->findSpan(sprintf('mqtt publish locker/%s/command', $compartment->locker_bank_id));

        $this->assertNotNull($publish, 'Expected a publish span from the worker.');
        $this->assertSame(
            $request->getContext()->getTraceId(),
            $publish->getContext()->getTraceId(),
            'The trace must survive the queue, not restart on the worker.',
        );
    }

    /**
     * Spans go through a batch processor, and the listener is neither an Octane
     * server nor a queue worker, so the package's worker-mode detectors never
     * fire and nothing flushes on a timer. Without an explicit flush the spans
     * sit in memory until the process ends — which for a listener is never, so
     * the return half of every trace would be invisible.
     *
     * Deliberately reads the exporter directly instead of {@see finishedSpans},
     * which force-flushes and would hide exactly the bug under test.
     */
    public function test_inbound_spans_are_flushed_without_waiting_for_shutdown(): void
    {
        $exporter = app(SpanExporterInterface::class);

        if (! $exporter instanceof InMemoryExporter) {
            $this->markTestSkipped('Span assertions require OTEL_TRACES_EXPORTER=memory.');
        }

        $this->handleDeviceEvent();

        $names = array_map(
            static fn (ImmutableSpan $span): string => $span->getName(),
            array_values($exporter->getSpans())
        );

        $this->assertContains(
            sprintf('mqtt process locker/%s/event', self::LOCKER_UUID),
            $names,
            'The span must reach the exporter without anyone forcing a flush.'
        );
    }

    /**
     * The listener runs for days, and shared log context outlives the span that
     * set it. Left alone, every later line it writes — heartbeats included —
     * would carry the trace id of the last message that happened to be traced.
     */
    public function test_trace_id_does_not_leak_into_later_log_lines(): void
    {
        $this->handleDeviceEvent();

        $captured = [];
        Log::listen(function ($message) use (&$captured): void {
            $captured[] = $message->context;
        });

        // Heartbeats are deliberately untraced, so nothing they log may claim to
        // belong to a trace.
        app(LockerHeartbeatHandler::class)->handleMessage(
            sprintf('locker/%s/state/heartbeat', self::LOCKER_UUID),
            (string) json_encode([
                'message_id' => '99999999-9999-9999-9999-999999999999',
                'timestamp' => now()->toIso8601String(),
                'uptime_seconds' => 240,
            ]),
        );

        $this->assertNotEmpty($captured, 'Expected the heartbeat handler to log.');

        foreach ($captured as $context) {
            $this->assertArrayNotHasKey('trace_id', $context);
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function handleDeviceEvent(array $extra = []): void
    {
        app(DeviceEventHandler::class)->handleMessage(
            sprintf('locker/%s/event', self::LOCKER_UUID),
            (string) json_encode([
                'message_id' => '33333333-3333-3333-3333-333333333333',
                'event' => 'door_opened',
                'event_id' => '44444444-4444-4444-4444-444444444444',
                'timestamp' => now()->toIso8601String(),
                'data' => ['compartment_number' => 7],
                ...$extra,
            ]),
        );
    }

    private function findSpanByKind(int $kind): ?ImmutableSpan
    {
        foreach ($this->finishedSpans() as $span) {
            if ($span->getKind() === $kind) {
                return $span;
            }
        }

        return null;
    }

    private function findSpan(string $name): ?ImmutableSpan
    {
        foreach ($this->finishedSpans() as $span) {
            if ($span->getName() === $name) {
                return $span;
            }
        }

        return null;
    }

    /**
     * @return list<ImmutableSpan>
     */
    private function finishedSpans(): array
    {
        // Spans go through a batch processor, so they only reach the exporter
        // once flushed.
        $tracerProvider = Globals::tracerProvider();
        if ($tracerProvider instanceof TracerProvider) {
            $tracerProvider->forceFlush();
        }

        $exporter = app(SpanExporterInterface::class);

        // phpunit.xml pins the memory exporter. Pointing the suite at a real
        // collector instead is useful for eyeballing traces, and these
        // assertions simply cannot run in that mode.
        if (! $exporter instanceof InMemoryExporter) {
            $this->markTestSkipped('Span assertions require OTEL_TRACES_EXPORTER=memory.');
        }

        return array_values($exporter->getSpans());
    }
}
