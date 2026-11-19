<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;

/**
 * Eloquent user provider that matches the email credential case-insensitively
 * (#371). Emails are stored lowercase, so lowercasing the credential covers
 * API login, admin panel login and the password broker in one place.
 */
class CaseInsensitiveUserProvider extends EloquentUserProvider
{
    /**
     * @param  array<string, mixed>  $credentials
     */
    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        if (is_string($credentials['email'] ?? null)) {
            $credentials['email'] = Str::lower($credentials['email']);
        }

        return parent::retrieveByCredentials($credentials);
    }
}
