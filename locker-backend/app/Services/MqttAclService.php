<?php

declare(strict_types=1);

namespace App\Services;

class MqttAclService
{
    /**
     * Check if a topic matches an ACL pattern, with %u/%c substitutions.
     */
    public function topicMatches(string $pattern, string $topic, string $username, string $clientId): bool
    {
        $pattern = str_replace(['%u', '%c'], [$username, $clientId], $pattern);

        $patternParts = explode('/', $pattern);
        $topicParts = explode('/', $topic);

        $i = 0;
        $j = 0;

        while ($i < count($patternParts)) {
            $seg = $patternParts[$i];

            if ($seg === '#') {
                return true; // multi-level wildcard matches rest
            }

            if ($j >= count($topicParts)) {
                return false; // topic exhausted but pattern expects more
            }

            if ($seg === '+') {
                if ($topicParts[$j] === '') {
                    return false; // '+' must match exactly one non-empty level
                }
                $i++;
                $j++;

                continue;
            }

            if ($seg !== $topicParts[$j]) {
                return false;
            }

            $i++;
            $j++;
        }

        return $j === count($topicParts);
    }
}
