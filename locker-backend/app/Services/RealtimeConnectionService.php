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
        // `?:` so an empty VITE_REVERB_PORT falls back too, instead of becoming port 0.
        $port = (int) (($echo['wsPort'] ?? null) ?: ($tls ? 443 : 80));

        return [
            /** @var string */
            'key' => $key,
            /** @var string */
            'host' => $host,
            'port' => $port,
            'scheme' => $tls ? 'https' : 'http',
        ];
    }
}
