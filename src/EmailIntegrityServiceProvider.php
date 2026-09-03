<?php

declare(strict_types=1);

namespace EmailIntegrity;

use Closure;
use EmailIntegrity\Console\UpdateDisposableDomainsCommand;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Factory as ValidationFactory;

class EmailIntegrityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/email-integrity.php', 'email-integrity');

        // Singletons: the domain list is a 74k-entry set, and resolving it per validated
        // field would reload it for every address in a batch. The container autowires
        // both constructors from their type hints.
        $this->app->singleton(DisposableDomains::class);
        $this->app->singleton(EmailIntegrity::class);
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__ . '/../lang', 'email-integrity');

        // Deferred: touching the Validator facade here would build the factory on every
        // request, including the queue workers that never validate anything.
        $this->callAfterResolving('validator', function (ValidationFactory $validator): void {
            $this->extend($validator, 'not_disposable', 'disposable', fn (EmailIntegrity $email, mixed $value): bool => ! $email->isDisposable($value));
            $this->extend($validator, 'routable_domain', 'unroutable', fn (EmailIntegrity $email, mixed $value): bool => $email->hostResolves($value));
        });

        if ($this->app->runningInConsole()) {
            $this->commands([UpdateDisposableDomainsCommand::class]);

            $this->publishes([
                __DIR__ . '/../config/email-integrity.php' => config_path('email-integrity.php'),
            ], 'email-integrity-config');
        }
    }

    /**
     * A string name for a rule, so it composes in a pipe string like any core rule.
     *
     * The message is set on the validator mid-validation rather than through
     * Validator::extend's third argument, which is stored raw and never reaches the
     * translator — it would print the key itself. Resolving it when the extension is
     * registered is no better: boot() runs before the middleware that sets the locale,
     * so every locale would get whichever one booted.
     */
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
