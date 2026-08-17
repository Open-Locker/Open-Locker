<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

trait InteractsWithOneTimeProvisioningToken
{
    /**
     * Protected properties are rendered in the issuing request but are not
     * dehydrated into Livewire's client-side snapshot.
     */
    protected ?string $oneTimeProvisioningToken = null;

    public function setOneTimeProvisioningToken(string $token): void
    {
        $this->oneTimeProvisioningToken = $token;
    }

    public function getOneTimeProvisioningToken(): string
    {
        return $this->oneTimeProvisioningToken ?? '';
    }
}
