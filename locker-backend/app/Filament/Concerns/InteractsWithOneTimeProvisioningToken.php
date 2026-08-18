<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Filament\Resources\LockerBankResource;
use Filament\Actions\Action;

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

    public function showProvisioningTokenAction(): Action
    {
        return LockerBankResource::showProvisioningTokenAction();
    }

    public function unmountAction(bool $canCancelParentActions = true): void
    {
        $isProvisioningTokenAction = data_get(
            collect($this->mountedActions)->last(),
            'name',
        ) === 'showProvisioningToken';

        parent::unmountAction($canCancelParentActions);

        if ($isProvisioningTokenAction) {
            $this->oneTimeProvisioningToken = null;
        }
    }
}
