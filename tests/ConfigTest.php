<?php

declare(strict_types=1);

namespace EmailIntegrity\Tests;

use EmailIntegrity\EmailIntegrityServiceProvider;
use Symfony\Component\Mailer\Bridge\Resend\Webhook\ResendRequestParser;

/** An app's published file is loaded before the provider registers, so each test sets it and registers again. */
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
            'disposable' => ['cache' => ['ttl' => 60]],
            'host'       => ['fail_open' => false],
            // As published from 1.1: no webhook path or domain.
            'suppression' => ['enabled' => true, 'webhook' => ['secret' => 'whsec_x']],
        ]);

        $this->assertSame(60, config('email-integrity.disposable.cache.ttl'));
        $this->assertTrue(config('email-integrity.disposable.cache.enabled'));
        $this->assertSame(1000, config('email-integrity.disposable.min_domains'));
        $this->assertFalse(config('email-integrity.host.fail_open'));
        $this->assertSame(3600, config('email-integrity.host.cache_ttl'));
        $this->assertTrue(config('email-integrity.suppression.enabled'));
        $this->assertSame([], config('email-integrity.suppression.own_inboxes'));
        $this->assertSame('whsec_x', config('email-integrity.suppression.webhook.secret'));
        $this->assertSame(ResendRequestParser::class, config('email-integrity.suppression.webhook.parser'));
        // As through 1.4.1: a 1.1 app routes the webhook itself, so its group gains no default path.
        $this->assertNull(config('email-integrity.suppression.webhook.path'));
    }

    public function test_a_key_or_group_left_out_keeps_its_default(): void
    {
        $this->publish(['schedule' => false]);

        $this->assertSame('email-integrity/webhook', config('email-integrity.suppression.webhook.path'));
        $this->assertTrue(config('email-integrity.skip_unsendable'));
    }

    public function test_a_published_list_replaces_the_default_list(): void
    {
        // Merged by index, an empty list would keep the default source.
        foreach ([[], ['https://example.com/a.json', 'https://example.com/b.json']] as $sources) {
            $this->publish(['disposable' => ['sources' => $sources]]);

            $this->assertSame($sources, config('email-integrity.disposable.sources'));
            $this->assertSame(1000, config('email-integrity.disposable.min_domains'));
        }
    }
}
