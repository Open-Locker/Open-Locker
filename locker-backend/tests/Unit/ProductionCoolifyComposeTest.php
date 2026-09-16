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

        $prodServices = $prod['services'] ?? [];
        $coolifyServices = $coolify['services'] ?? [];
        $this->assertIsArray($prodServices);
        $this->assertIsArray($coolifyServices);
        $this->assertSame(array_keys($prodServices), array_keys($coolifyServices));
        $this->assertSame($prod['volumes'] ?? null, $coolify['volumes'] ?? null);

        foreach ($coolifyServices as $name => $service) {
            $this->assertIsArray($service);
            $this->assertArrayNotHasKey('extends', $service, $name.' must not use Compose extends');
            $this->assertArrayNotHasKey('include', $service, $name.' must not use Compose include');
        }

        foreach ($prodServices as $name => $service) {
            if ($name === 'mqtt') {
                continue;
            }

            $this->assertSame(
                $service,
                $coolifyServices[$name],
                $name.' must match the production compose except for Coolify MQTT wiring',
            );
        }

        $prodMqtt = $prodServices['mqtt'];
        $coolifyMqtt = $coolifyServices['mqtt'];
        $this->assertIsArray($prodMqtt);
        $this->assertIsArray($coolifyMqtt);

        $sharedMqtt = $prodMqtt;
        unset($sharedMqtt['networks'], $sharedMqtt['labels']);
        $coolifySharedMqtt = $coolifyMqtt;
        unset($coolifySharedMqtt['networks'], $coolifySharedMqtt['labels']);
        $this->assertSame($sharedMqtt, $coolifySharedMqtt);

        $this->assertContains('coolify', $coolifyMqtt['networks'] ?? []);
        $this->assertContains('default', $coolifyMqtt['networks'] ?? []);
        $this->assertContains('traefik.enable=true', $coolifyMqtt['labels'] ?? []);
        $this->assertTrue($coolify['networks']['coolify']['external'] ?? false);
    }
}
