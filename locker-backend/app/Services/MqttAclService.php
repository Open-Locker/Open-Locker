<?php

declare(strict_types=1);

namespace App\Services;

class MqttAclService
{
    /**
     * Check if a topic matches an ACL pattern.
     *
     * Supported pattern features:
     * - `+` matches exactly one non-empty level.
     * - `#` matches the remainder of the topic (multi-level wildcard).
     * - `%u` matches exactly the given username as a single level (literal, no wildcard expansion).
     * - `%c` matches exactly the given client id as a single level (literal, no wildcard expansion).
     *
     * Important security note:
     * `%u` and `%c` are matched as literals to prevent wildcard injection (e.g. username '#' or '+').
     */
    public function topicMatches(string $pattern, string $topic, string $username, string $clientId): bool
    {
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

            if ($seg === '%u') {
                if ($topicParts[$j] === '' || $topicParts[$j] !== $username) {
                    return false;
                }

                $i++;
                $j++;

                continue;
            }

            if ($seg === '%c') {
                if ($topicParts[$j] === '' || $topicParts[$j] !== $clientId) {
                    return false;
                }

                $i++;
                $j++;

                continue;
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
