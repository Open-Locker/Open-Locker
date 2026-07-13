<?php

namespace App\Providers;

use App\Scramble\Transformers\AcceptLanguageHeaderTransformer;
use App\Scramble\Transformers\AccessibleCompartmentsNullableTransformer;
use App\Scramble\Transformers\NullableFieldsTransformer;
use App\Support\Audit\AuditEventPresenter;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Shared per-request so the audit log's actor/compartment/group lookups
        // are memoised across rows when rendering a page. See ADR-0026.
        $this->app->singleton(AuditEventPresenter::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Force HTTPS in production or if explicitly enabled
        if ($this->app->environment('production') || config('app.force_https')) {
            URL::forceScheme('https');
        }

        Scramble::afterOpenApiGenerated(function (OpenApi $openApi) {
            $openApi->secure(
                SecurityScheme::http('bearer', 'JWT')
            );
        });

        Scramble::configure()->withDocumentTransformers([
            new AccessibleCompartmentsNullableTransformer,
            new NullableFieldsTransformer,
            new AcceptLanguageHeaderTransformer,
        ]);

        // Use CarbonImmutable for all date instances. Prevents date mutability.
        Date::use(CarbonImmutable::class);

        // Removes wrapping of JSON responses.
        JsonResource::withoutWrapping();
    }
}
