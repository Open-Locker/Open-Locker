<?php

namespace App\Providers;

use App\Scramble\Transformers\NullableFieldsTransformer;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Configuration\DocumentTransformers;
use Dedoc\Scramble\Contracts\DocumentTransformer;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\ServiceProvider;
use function PHPUnit\Framework\isInstanceOf;

class AppServiceProvider extends ServiceProvider {
    /**
     * Register any application services.
     */
    public function register(): void {
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void {


        Scramble::afterOpenApiGenerated( function ( OpenApi $openApi ) {
            $openApi->secure(
                SecurityScheme::http( 'bearer', 'JWT' )
            );

        } );

        Scramble::configure()->withDocumentTransformers( new NullableFieldsTransformer());


        // Use CarbonImmutable for all date instances. Prevents date mutability.
        Date::use( CarbonImmutable::class );

        // Removes wrapping of JSON responses.
        JsonResource::withoutWrapping();
    }
}
