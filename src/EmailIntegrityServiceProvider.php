<?php

declare(strict_types=1);

namespace EmailIntegrity;

use Closure;
use EmailIntegrity\Console\LiftSuppressionCommand;
use EmailIntegrity\Console\SuppressInvalidCommand;
use EmailIntegrity\Console\UpdateDisposableDomainsCommand;
use EmailIntegrity\Http\SuppressionWebhook;
use EmailIntegrity\Listeners\DropSuppressedMail;
use EmailIntegrity\Listeners\SkipUnsendableMail;
use EmailIntegrity\Mail\GuardedMailManager;
use EmailIntegrity\Rules\NotDisposable;
use EmailIntegrity\Rules\NotSuppressed;
use EmailIntegrity\Rules\RoutableDomain;
use EmailIntegrity\Rules\UnclaimedMailbox;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\MailManager;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Factory as ValidationFactory;
use Illuminate\Validation\InvokableValidationRule;
use Illuminate\Validation\Validator;

/** @internal Registered by package auto-discovery. */
final class EmailIntegrityServiceProvider extends ServiceProvider
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

        // Every SMTP transport then suppresses a recipient its server refuses as invalid, read at send time.
        $this->app->extend('mail.manager', static fn (MailManager $manager): MailManager => GuardedMailManager::replacing($manager));
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__ . '/../lang', 'email-integrity');

        // Deferred, so a request or job that never validates does not build the factory.
        $this->callAfterResolving('validator', function (ValidationFactory $validator): void {
            $this->registerRule($validator, 'not_disposable', fn (): ValidationRule => $this->app->make(NotDisposable::class));
            $this->registerRule($validator, 'routable_domain', fn (): ValidationRule => $this->app->make(RoutableDomain::class));
            $this->registerRule($validator, 'not_suppressed', static fn (): ValidationRule => new NotSuppressed);
            $this->registerRule($validator, 'unclaimed_mailbox', static function (array $parameters, Validator $validator): ValidationRule {
                $validator->requireParameterCount(1, $parameters, 'unclaimed_mailbox');

                return (new UnclaimedMailbox(...array_slice($parameters, 0, 2)))->ignore($parameters[2] ?? null);
            });
        });

        // Inert while suppression is off, read at send time. Package providers boot first, so it runs before the app's.
        Event::listen(MessageSending::class, DropSuppressedMail::class);
        Event::listen(NotificationSending::class, SkipUnsendableMail::class);

        $this->registerWebhookRoute();

        if ($this->app->runningInConsole()) {
            $this->commands([UpdateDisposableDomainsCommand::class, LiftSuppressionCommand::class, SuppressInvalidCommand::class]);

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
                __DIR__ . '/../database/migrations/create_suppressed_addresses_table.php' => database_path('migrations/2026_10_03_000000_create_suppressed_addresses_table.php'),
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

    /**
     * The string name runs the rule object. The message is set on failure: extend()'s own is never translated, and
     * boot() runs before the locale is set.
     *
     * @param  Closure(array<int, string>, Validator): ValidationRule  $make
     */
    private function registerRule(ValidationFactory $factory, string $name, Closure $make): void
    {
        $factory->extend($name, static function (string $attribute, mixed $value, array $parameters, Validator $validator) use ($name, $make): bool {
            $rule = InvokableValidationRule::make($make($parameters, $validator))->setValidator($validator);

            if ($rule->passes($attribute, $value)) {
                return true;
            }

            $validator->fallbackMessages[$name] = $rule->message()[0];

            return false;
        });
    }

    /**
     * Only while suppression is on: the controller refuses everything otherwise. Cached routes already hold it.
     * Throttled by class, not the `throttle` alias an app may remap. No middleware group: the signature stands in for
     * CSRF.
     */
    private function registerWebhookRoute(): void
    {
        $path = config('email-integrity.suppression.webhook.path');
        $domain = config('email-integrity.suppression.webhook.domain');

        if (! config('email-integrity.suppression.enabled') || ! is_string($path) || trim($path, '/') === '' || $this->app->routesAreCached()) {
            return;
        }

        $route = Route::post(trim($path, '/'), SuppressionWebhook::class)
            ->middleware(ThrottleRequests::with(120, 1, 'email-integrity-webhook'))
            ->name('email-integrity.webhook');

        if (is_string($domain)) {
            $route->domain($domain);
        }
    }
}
