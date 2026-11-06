<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Public Reverb connection settings a client needs to open the websocket of
 * this server (ADR-0087). Reads the same configuration the Filament panel's
 * Echo client uses, so the public socket address is configured once.
 */
class RealtimeConnectionService
{
    /**
     * Null when no public Reverb host or key is configured.
     *
     * @return array{key: string, host: string, port: int, scheme: 'https'|'http'}|null
     */
    public function publicSettings(): ?array
    {
        $echo = (array) config('filament.broadcasting.echo');
        $key = $echo['key'] ?? null;
        $host = $echo['wsHost'] ?? null;

        if (! is_string($key) || $key === '' || ! is_string($host) || $host === '') {
            return null;
        }

        $tls = (bool) ($echo['forceTLS'] ?? true);

        // The casts are for Scramble, which does not narrow through is_string() and
        // would otherwise publish key and host as nullable.
        return [
            'key' => (string) $key,
            'host' => (string) $host,
            'port' => (int) ($echo['wsPort'] ?? ($tls ? 443 : 80)),
            'scheme' => $tls ? 'https' : 'http',
        ];
    }
}
