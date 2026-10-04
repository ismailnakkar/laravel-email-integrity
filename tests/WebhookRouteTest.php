<?php

declare(strict_types=1);

namespace EmailIntegrity\Tests;

use EmailIntegrity\EmailIntegrityServiceProvider;
use EmailIntegrity\Http\SuppressionWebhook;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;

/** Routes register in boot(), so each test sets its config and boots the provider again. */
class WebhookRouteTest extends TestCase
{
    /** @param array<string, mixed> $config */
    private function boot(array $config): void
    {
        config($config);
        (new EmailIntegrityServiceProvider($this->app))->boot();
    }

    private function webhookRoute(): ?Route
    {
        $routes = $this->app['router']->getRoutes();
        $routes->refreshNameLookups();

        return $routes->getByName('email-integrity.webhook');
    }

    public function test_the_default_route_is_named_throttled_and_reaches_the_controller(): void
    {
        $this->boot(['email-integrity.suppression.enabled' => true]);

        $route = $this->webhookRoute();

        $this->assertInstanceOf(Route::class, $route);
        $this->assertSame('email-integrity/webhook', $route->uri());
        $this->assertSame(['POST'], $route->methods());
        $this->assertSame(SuppressionWebhook::class, $route->getActionName());
        $this->assertContains(ThrottleRequests::with(120, 1, 'email-integrity-webhook'), $route->middleware());
        $this->assertNotContains('web', $route->middleware());

        // No signing secret: the controller answers 401, so it was reached.
        $this->post('/email-integrity/webhook')->assertUnauthorized();
    }

    public function test_a_custom_path_and_domain_are_honoured(): void
    {
        $this->boot([
            'email-integrity.suppression.enabled'        => true,
            'email-integrity.suppression.webhook.path'   => '/hooks/mail/',
            'email-integrity.suppression.webhook.domain' => 'hooks.example.com',
        ]);

        $route = $this->webhookRoute();

        $this->assertSame('hooks/mail', $route->uri());
        $this->assertSame('hooks.example.com', $route->getDomain());
    }

    public function test_a_null_path_registers_no_route(): void
    {
        $this->boot(['email-integrity.suppression.enabled' => true, 'email-integrity.suppression.webhook.path' => null]);

        $this->assertNull($this->webhookRoute());
    }

    public function test_no_route_while_suppression_is_off(): void
    {
        $this->boot(['email-integrity.suppression.enabled' => false]);

        $this->assertNull($this->webhookRoute());
        $this->post('/email-integrity/webhook')->assertNotFound();
    }
}
