<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

class ProductionCoolifyComposeTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function parseCompose(string $filename): array
    {
        $parsed = Yaml::parseFile(dirname(__DIR__, 2).'/'.$filename);
        $this->assertIsArray($parsed);

        return $parsed;
    }

    public function test_coolify_compose_is_self_contained_and_aligned_with_prod(): void
    {
        $prod = $this->parseCompose('docker-compose.prod.yml');
        $coolify = $this->parseCompose('docker-compose.prod.coolify.yml');

        $prodServices = array_keys($prod['services'] ?? []);
        $coolifyServices = array_keys($coolify['services'] ?? []);

        $this->assertSame($prodServices, $coolifyServices);

        foreach ($coolify['services'] as $name => $service) {
            $this->assertIsArray($service);
            $this->assertArrayNotHasKey('extends', $service, $name.' must not use Compose extends');
        }

        foreach (['app', 'queue-worker', 'event-worker', 'mqtt-listener', 'scheduler', 'reverb'] as $name) {
            $this->assertSame(
                $prod['services'][$name]['image'] ?? null,
                $coolify['services'][$name]['image'] ?? null,
                $name.' image must match the production compose',
            );
        }

        $this->assertSame(
            $prod['services']['mqtt']['image'] ?? null,
            $coolify['services']['mqtt']['image'] ?? null,
        );

        $mqtt = $coolify['services']['mqtt'];
        $this->assertContains('coolify', $mqtt['networks'] ?? []);
        $this->assertContains('default', $mqtt['networks'] ?? []);
        $this->assertContains('traefik.enable=true', $mqtt['labels'] ?? []);
        $this->assertTrue($coolify['networks']['coolify']['external'] ?? false);
    }
}
