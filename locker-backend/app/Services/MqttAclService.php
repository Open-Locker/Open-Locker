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
     * - `%l` matches exactly the given locker uuid as a single level (literal, no wildcard expansion).
     *
     * Important security note:
     * `%u`, `%c` and `%l` are matched as literals to prevent wildcard injection (e.g. username '#' or '+').
     * A device's authorised locker is looked up rather than read off its username, so `%l` carries a value
     * the client never supplies. Interpolating that value into the pattern instead would match as a
     * pattern, which is the same injection hole the placeholders exist to avoid.
     */
    public function topicMatches(string $pattern, string $topic, string $username, string $clientId, ?string $lockerUuid = null): bool
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

            if ($seg === '%l') {
                // No mapped locker means no authorised topics, so fail closed
                // rather than letting the pattern match anything.
                if ($lockerUuid === null || $lockerUuid === '' || $topicParts[$j] !== $lockerUuid) {
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
