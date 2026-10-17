<?php

declare(strict_types=1);

namespace App\Scramble\Transformers;

use App\Http\Middleware\ResolveOrganization;
use Dedoc\Scramble\Contracts\OperationTransformer;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\RouteInfo;

/**
 * Documents organization selection only on routes that resolve an organization.
 */
class OrganizationHeaderTransformer implements OperationTransformer
{
    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        if (! in_array('organization', $routeInfo->route->gatherMiddleware(), true)) {
            return;
        }

        $operation->parameters[] = Parameter::make(ResolveOrganization::HEADER, 'header')
            ->setSchema(Schema::fromType((new StringType)->format('uuid')))
            ->description(
                'Organization to act in. Must identify one of the authenticated user\'s '
                .'organizations; platform administrators may select any organization. '
                .'When omitted, the API selects the user\'s first organization by name.'
            );

        $operation->addResponse(
            Response::make(403)
                ->setDescription('The requested organization is invalid or inaccessible.')
                ->setContent('application/json', Schema::fromType(
                    (new ObjectType)
                        ->addProperty('message', new StringType)
                        ->addProperty(
                            'code',
                            (new StringType)->enum(['organization_forbidden'])
                        )
                        ->setRequired(['message', 'code'])
                ))
        );
    }
}
