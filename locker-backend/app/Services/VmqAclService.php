<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Log;

class VmqAclService
{
    /**
     * Check if a topic matches an ACL pattern, with %u/%c substitutions.
     */
    public function topicMatches(string $pattern, string $topic, string $username, string $clientId): bool
    {

        Log::info('Pattern: '.$pattern.' Topic: '.$topic.' Username: '.$username.' ClientId: '.$clientId);
        // Substitute placeholders first
        $pattern = str_replace(['%u', '%c'], [$username, $clientId], $pattern);

        $patternParts = explode('/', $pattern);
        $topicParts = explode('/', $topic);

        $i = 0; // index for pattern
        $j = 0; // index for topic

        while ($i < count($patternParts)) {
            $seg = $patternParts[$i];

            if ($seg === '#') {
                // '#' matches the rest
                return true;
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

        // Pattern fully consumed; topic must also be fully consumed
        return $j === count($topicParts);
    }
}
