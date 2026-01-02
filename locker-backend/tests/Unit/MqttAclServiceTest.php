<?php

namespace Tests\Unit;

use App\Services\MqttAclService;
use PHPUnit\Framework\TestCase;

class MqttAclServiceTest extends TestCase
{
    public function test_matches_username_and_clientid_as_literals(): void
    {
        $acl = new MqttAclService;

        $this->assertTrue($acl->topicMatches('locker/%u/command', 'locker/device_1/command', 'device_1', 'c1'));
        $this->assertFalse($acl->topicMatches('locker/%u/command', 'locker/device_2/command', 'device_1', 'c1'));

        $this->assertTrue($acl->topicMatches('locker/provisioning/reply/%c', 'locker/provisioning/reply/c1', 'u', 'c1'));
        $this->assertFalse($acl->topicMatches('locker/provisioning/reply/%c', 'locker/provisioning/reply/c2', 'u', 'c1'));
    }

    public function test_prevents_wildcard_injection_via_username_or_clientid(): void
    {
        $acl = new MqttAclService;

        // If a username contains '#', it must not be treated as a wildcard.
        $this->assertFalse($acl->topicMatches('locker/%u/command', 'locker/anything/command', '#', 'c1'));

        // Same for '+' as a single-level wildcard.
        $this->assertFalse($acl->topicMatches('locker/%u/command', 'locker/anything/command', '+', 'c1'));

        // Client IDs should not be able to inject wildcards either.
        $this->assertFalse($acl->topicMatches('locker/provisioning/reply/%c', 'locker/provisioning/reply/anything', 'u', '#'));
        $this->assertFalse($acl->topicMatches('locker/provisioning/reply/%c', 'locker/provisioning/reply/anything', 'u', '+'));
    }

    public function test_plus_matches_exactly_one_non_empty_level(): void
    {
        $acl = new MqttAclService;

        $this->assertTrue($acl->topicMatches('locker/register/+', 'locker/register/device-123', 'u', 'c'));
        $this->assertFalse($acl->topicMatches('locker/register/+', 'locker/register/', 'u', 'c'));
        $this->assertFalse($acl->topicMatches('locker/register/+', 'locker/register/device-123/extra', 'u', 'c'));
    }

    public function test_hash_matches_remainder_of_topic(): void
    {
        $acl = new MqttAclService;

        $this->assertTrue($acl->topicMatches('#', 'any/topic', 'u', 'c'));
        $this->assertTrue($acl->topicMatches('locker/#', 'locker/a/b/c', 'u', 'c'));
        $this->assertFalse($acl->topicMatches('locker/#', 'server/status', 'u', 'c'));
    }
}
