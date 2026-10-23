<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Scramble\Transformers\OrganizationHeaderTransformer;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Routing\Route;
use Tests\TestCase;

class OpenApiOrganizationContractTest extends TestCase
{
    public function test_organization_routes_document_selection_header_and_forbidden_response(): void
    {
        $route = new Route(['GET'], 'api/user', fn (): null => null);
        $route->middleware('organization');
        $operation = Operation::make('get');

        (new OrganizationHeaderTransformer)->handle($operation, new RouteInfo($route, 'GET'));

        $document = $operation->toArray();

        $this->assertSame('X-Organization', $document['parameters'][0]['name']);
        $this->assertSame('uuid', $document['parameters'][0]['schema']['format']);
        $this->assertSame(
            ['organization_forbidden'],
            $document['responses'][403]['content']['application/json']['schema']['properties']['code']['enum']
        );
    }

    public function test_routes_without_organization_middleware_are_unchanged(): void
    {
        $route = new Route(['GET'], 'api/organizations', fn (): null => null);
        $operation = Operation::make('get');

        (new OrganizationHeaderTransformer)->handle($operation, new RouteInfo($route, 'GET'));

        $this->assertSame([], $operation->parameters);
        $this->assertSame([], $operation->responses);
    }
}
