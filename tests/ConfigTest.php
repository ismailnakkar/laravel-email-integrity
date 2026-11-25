<?php

declare(strict_types=1);

namespace EmailIntegrity\Tests;

use EmailIntegrity\EmailIntegrityServiceProvider;
use Symfony\Component\Mailer\Bridge\Resend\Webhook\ResendRequestParser;

/** The app's config loads before register(), so each test sets it and registers again. */
class ConfigTest extends TestCase
{
    /** @param array<string, mixed> $published */
    private function publish(array $published): void
    {
        config(['email-integrity' => $published]);
        (new EmailIntegrityServiceProvider($this->app))->register();
    }

    public function test_a_partial_group_keeps_its_other_defaults(): void
    {
        $this->publish([
            'disposable'  => ['min_domains' => 50],
            'host'        => ['fail_open' => false],
            'suppression' => ['enabled' => true, 'webhook' => ['secret' => 'whsec_x']],
        ]);

        $this->assertSame(50, config('email-integrity.disposable.min_domains'));
        $this->assertSame(storage_path('framework/disposable_domains.json'), config('email-integrity.disposable.storage'));
        $this->assertFalse(config('email-integrity.host.fail_open'));
        $this->assertTrue(config('email-integrity.host.enabled'));
        $this->assertTrue(config('email-integrity.suppression.enabled'));
        $this->assertSame([], config('email-integrity.suppression.own_inboxes'));
        $this->assertSame('whsec_x', config('email-integrity.suppression.webhook.secret'));
        $this->assertSame(ResendRequestParser::class, config('email-integrity.suppression.webhook.parser'));
        $this->assertSame('email-integrity/webhook', config('email-integrity.suppression.webhook.path'));
    }

    public function test_a_key_or_group_left_out_keeps_its_default(): void
    {
        $this->publish(['schedule' => false]);

        $this->assertSame('email-integrity/webhook', config('email-integrity.suppression.webhook.path'));
        $this->assertTrue(config('email-integrity.enabled'));
    }

    public function test_a_published_list_replaces_the_default_list(): void
    {
        foreach ([[], ['https://example.com/a.json', 'https://example.com/b.json']] as $sources) {
            $this->publish(['disposable' => ['sources' => $sources]]);

            $this->assertSame($sources, config('email-integrity.disposable.sources'));
            $this->assertSame(1000, config('email-integrity.disposable.min_domains'));
        }
    }
}
