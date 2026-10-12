<?php

declare(strict_types=1);

namespace App\Providers;

use App\Scramble\Transformers\AcceptLanguageHeaderTransformer;
use App\Scramble\Transformers\AccessibleCompartmentsNullableTransformer;
use App\Scramble\Transformers\NullableFieldsTransformer;
use App\Support\Audit\AuditEventPresenter;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
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
        // are memoised across rows when rendering a page.
        $this->app->singleton(AuditEventPresenter::class);
    }

    /**
     * Bootstrap any application services.
     *
     * Lazy loading throws in local and testing, so N+1 queries surface where
     * they are written, and is only logged elsewhere, where an exception would
     * reach users. Password-reset requests get their own rate-limit bucket, so
     * failed logins cannot block a valid reset link.
     */
    public function boot(): void
    {
        Model::preventLazyLoading();
        if (! $this->app->environment(['local', 'testing'])) {
            Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation): void {
                Log::warning('Lazy loading violation', ['model' => $model::class, 'relation' => $relation]);
            });
        }

        RateLimiter::for('password-reset', fn (Request $request): Limit => Limit::perMinute(6)->by((string) $request->ip()));

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
