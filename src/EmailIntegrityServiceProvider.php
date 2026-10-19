<?php

declare(strict_types=1);

namespace EmailIntegrity;

use Closure;
use EmailIntegrity\Console\LiftSuppressionCommand;
use EmailIntegrity\Console\UpdateDisposableDomainsCommand;
use EmailIntegrity\Http\SuppressionWebhook;
use EmailIntegrity\Listeners\DropSuppressedMail;
use EmailIntegrity\Listeners\SkipUnsendableMail;
use EmailIntegrity\Rules\UnclaimedMailbox;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Factory as ValidationFactory;
use Illuminate\Validation\InvokableValidationRule;
use Illuminate\Validation\Validator;

class EmailIntegrityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Not mergeConfigFrom(): a published group would lose its other defaults. Not replaceConfigRecursivelyFrom():
        // it merges lists by index, so an empty `disposable.sources` would keep the default.
        if (! ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached())) {
            $config = $this->app->make('config');
            $config->set('email-integrity', self::merge(require __DIR__ . '/../config/email-integrity.php', (array)$config->get('email-integrity', [])));
        }

        // Scoped: the 74k-entry list loads once per request or job, and a queue worker or Octane sees the next update.
        $this->app->scoped(DisposableDomains::class);
        $this->app->scoped(EmailIntegrity::class);
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__ . '/../lang', 'email-integrity');

        // Deferred, so a request or job that never validates does not build the factory.
        $this->callAfterResolving('validator', function (ValidationFactory $validator): void {
            $this->extend($validator, 'not_disposable', 'disposable', fn (EmailIntegrity $email, mixed $value): bool => ! $email->isDisposable($value));
            $this->extend($validator, 'routable_domain', 'unroutable', fn (EmailIntegrity $email, mixed $value): bool => $email->hostResolves($value));
            $this->extend($validator, 'not_suppressed', 'suppressed', fn (EmailIntegrity $email, mixed $value): bool => ! is_string($value) || SuppressedAddress::blocking($value) === null);

            // Not $this->extend(): it takes parameters, and fails with UnclaimedMailbox's message (validation.unique).
            $validator->extend('unclaimed_mailbox', static function (string $attribute, mixed $value, array $parameters, Validator $validator): bool {
                $validator->requireParameterCount(1, $parameters, 'unclaimed_mailbox');

                $rule = InvokableValidationRule::make((new UnclaimedMailbox(...array_slice($parameters, 0, 2)))->ignore($parameters[2] ?? null))
                    ->setValidator($validator);

                if ($rule->passes($attribute, $value)) {
                    return true;
                }

                $validator->fallbackMessages['unclaimed_mailbox'] = $rule->message()[0];

                return false;
            });
        });

        // Inert while suppression is off, read at send time. Package providers boot first, so it runs before the app's.
        Event::listen(MessageSending::class, DropSuppressedMail::class);

        Event::listen(NotificationSending::class, SkipUnsendableMail::class);

        // Cached routes already hold it. Only while suppression is on: the controller refuses everything otherwise. By
        // class, not the 'throttle' alias an app may remap. No middleware group: the signature stands in for CSRF.
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

            // No onOneServer() or withoutOverlapping(): each server keeps its own list, and both lock the shared cache.
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
                if (config('email-integrity.schedule')) {
                    $schedule->command('email-integrity:update')->dailyAt('03:00');
                }
            });

            $this->publishes([
                __DIR__ . '/../config/email-integrity.php' => config_path('email-integrity.php'),
            ], 'email-integrity-config');

            // Publish-only: suppression is opt-in, so an app that never turns it on gets no table.
            $this->publishesMigrations([
                __DIR__ . '/../database/migrations' => database_path('migrations'),
            ], 'email-integrity-migrations');
        }
    }

    /**
     * The app's values over the defaults: a group merges key by key, anything else (a list included) replaces.
     *
     * @param  array<array-key, mixed>  $defaults
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private static function merge(array $defaults, array $values): array
    {
        foreach ($values as $key => $value) {
            $default = $defaults[$key] ?? null;

            $defaults[$key] = is_array($value) && is_array($default) && ! array_is_list($default)
                ? self::merge($default, $value)
                : $value;
        }

        return $defaults;
    }

    /** The message is set on failure: extend()'s own is never translated, and boot() runs before the locale is set. */
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
