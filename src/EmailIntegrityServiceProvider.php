<?php

declare(strict_types=1);

namespace EmailIntegrity;

use Closure;
use EmailIntegrity\Console\LiftSuppressionCommand;
use EmailIntegrity\Console\UpdateDisposableDomainsCommand;
use EmailIntegrity\Http\SuppressionWebhook;
use EmailIntegrity\Listeners\DropSuppressedMail;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Factory as ValidationFactory;

class EmailIntegrityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/email-integrity.php', 'email-integrity');

        // Scoped: the 74k-entry list loads once per request or job, and a queue worker or Octane sees the next update.
        $this->app->scoped(DisposableDomains::class);
        $this->app->scoped(EmailIntegrity::class);
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__ . '/../lang', 'email-integrity');

        // Deferred: touching the Validator facade here would build the factory on every
        // request, including the queue workers that never validate anything.
        $this->callAfterResolving('validator', function (ValidationFactory $validator): void {
            $this->extend($validator, 'not_disposable', 'disposable', fn (EmailIntegrity $email, mixed $value): bool => ! $email->isDisposable($value));
            $this->extend($validator, 'routable_domain', 'unroutable', fn (EmailIntegrity $email, mixed $value): bool => $email->hostResolves($value));
            $this->extend($validator, 'not_suppressed', 'suppressed', fn (EmailIntegrity $email, mixed $value): bool => ! is_string($value) || SuppressedAddress::blocking($value) === null);
        });

        // Always listening and inert while suppression is off: the switch is read at send time. Package providers
        // boot before the app's, so this runs ahead of the app's own MessageSending listeners.
        Event::listen(MessageSending::class, DropSuppressedMail::class);

        // Cached routes already hold it; only while suppression is on, as the controller refuses everything otherwise.
        // The class, not the 'throttle' alias an app may remap. No middleware group: no session or CSRF, the provider signs the body.
        $path = config('email-integrity.suppression.webhook.path');
        $domain = config('email-integrity.suppression.webhook.domain');

        if (config('email-integrity.suppression.enabled') === true && is_string($path) && trim($path, '/') !== '' && ! $this->app->routesAreCached()) {
            $route = Route::post(trim($path, '/'), SuppressionWebhook::class)
                ->middleware(ThrottleRequests::with(120, 1, 'email-integrity-webhook'))
                ->name('email-integrity.webhook');

            if (is_string($domain)) {
                $route->domain($domain);
            }
        }

        if ($this->app->runningInConsole()) {
            $this->commands([UpdateDisposableDomainsCommand::class, LiftSuppressionCommand::class]);

            $this->publishes([
                __DIR__ . '/../config/email-integrity.php' => config_path('email-integrity.php'),
            ], 'email-integrity-config');

            // Publish-only: apps that already keep a `suppressed_addresses` table must not get a second one.
            $this->publishesMigrations([
                __DIR__ . '/../database/migrations' => database_path('migrations'),
            ], 'email-integrity-migrations');
        }
    }

    /** The message is set mid-validation: extend()'s own is never translated, and boot() runs before the locale is set. */
    private function extend(ValidationFactory $validator, string $rule, string $message, Closure $passes): void
    {
        $validator->extend($rule, function (string $attribute, mixed $value, array $parameters, $validator) use ($rule, $message, $passes): bool {
            if ($passes($this->app->make(EmailIntegrity::class), $value)) {
                return true;
            }

            $validator->fallbackMessages[$rule] = trans("email-integrity::messages.{$message}");

            return false;
        });
    }
}
