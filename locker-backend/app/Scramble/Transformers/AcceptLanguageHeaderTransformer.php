<?php

declare(strict_types=1);

namespace App\Scramble\Transformers;

use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\StringType;

/**
 * Documents the request-scoped `Accept-Language` header on every operation so
 * the OpenAPI spec reflects the locale negotiation introduced in ADR-0024.
 */
class AcceptLanguageHeaderTransformer
{
    public function __invoke(OpenApi $document): void
    {
        /** @var list<string> $supported */
        $supported = config('app.supported_locales', ['en']);

        foreach ($document->paths as $path) {
            foreach ($path->operations as $operation) {
                $parameter = Parameter::make('Accept-Language', 'header')
                    ->setSchema(Schema::fromType((new StringType)->enum($supported)))
                    ->description(
                        'Preferred language for server-rendered strings (API messages, '
                        .'web pages, and request-triggered emails). Falls back to the '
                        .'application default when omitted or unsupported.'
                    );

                $operation->parameters[] = $parameter;
            }
        }
    }
}
