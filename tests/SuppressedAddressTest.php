<?php

declare(strict_types=1);

namespace EmailIntegrity\Tests;

use EmailIntegrity\EmailIntegrityServiceProvider;
use EmailIntegrity\SuppressedAddress;
use EmailIntegrity\SuppressionReason;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class SuppressedAddressTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('email-integrity.suppression.enabled', true);
    }

    public function test_a_complaint_keys_the_inbox_and_a_bounce_keys_the_spelling(): void
    {
        $this->assertSame('janedoe@gmail.com', SuppressedAddress::key('Jane <Jane.Doe+News@GoogleMail.com>', SuppressionReason::complained));
        $this->assertSame('jane.doe+news@googlemail.com', SuppressedAddress::key('Jane <Jane.Doe+News@GoogleMail.com>', SuppressionReason::bounced));
    }

    public function test_a_quoted_complaint_keys_its_literal_spelling(): void
    {
        $this->assertSame('"a b"@example.com', SuppressedAddress::key('"A B"@Example.com', SuppressionReason::complained));
    }

    public function test_an_address_with_no_key_is_never_suppressed(): void
    {
        $tooLong = str_repeat('a', 243) . '@example.com';

        $this->assertNull(SuppressedAddress::key('undisclosed recipients', SuppressionReason::bounced));
        $this->assertNull(SuppressedAddress::key($tooLong, SuppressionReason::complained));
        $this->assertNull(SuppressedAddress::key('jack@example.com .', SuppressionReason::bounced));
        $this->assertNull(SuppressedAddress::key("jack@example.com\n.", SuppressionReason::bounced));
        $this->assertFalse(SuppressedAddress::suppress('undisclosed recipients', SuppressionReason::complained));
        $this->assertFalse(SuppressedAddress::suppress($tooLong, SuppressionReason::bounced));
        $this->assertSame(0, SuppressedAddress::query()->count());
    }

    public function test_a_complaint_blocks_every_spelling_and_a_bounce_only_its_own(): void
    {
        SuppressedAddress::suppress('Jane.Doe@gmail.com', SuppressionReason::complained);
        SuppressedAddress::suppress('member+x@corp.example', SuppressionReason::bounced);

        $this->assertSame(SuppressionReason::complained, SuppressedAddress::blocking('j.a.n.e.doe+x@googlemail.com')?->reason);
        $this->assertNotNull(SuppressedAddress::blocking('nobody@example.com', 'Member <MEMBER+X@corp.example>'));
        $this->assertNull(SuppressedAddress::blocking('member@corp.example'));
        $this->assertNull(SuppressedAddress::blocking());
    }

    public function test_mailable_is_sendable_and_not_suppressed(): void
    {
        SuppressedAddress::suppress('jane.doe@gmail.com', SuppressionReason::complained);

        $this->assertFalse(SuppressedAddress::mailable(null));
        $this->assertFalse(SuppressedAddress::mailable('a(c)@example.com'));
        $this->assertFalse(SuppressedAddress::mailable('janedoe+x@gmail.com'));
        $this->assertTrue(SuppressedAddress::mailable('someone@example.com'));

        config(['email-integrity.suppression.enabled' => false]);

        $this->assertTrue(SuppressedAddress::mailable('janedoe+x@gmail.com'));
    }

    public function test_suppress_inserts_once_and_keeps_the_first_reason(): void
    {
        $this->assertTrue(SuppressedAddress::suppress('dead@example.com', SuppressionReason::bounced));
        $this->assertFalse(SuppressedAddress::suppress('Dead@Example.com', SuppressionReason::bounced));
        $this->assertFalse(SuppressedAddress::suppress('dead@example.com', SuppressionReason::complained));

        $this->assertSame(1, SuppressedAddress::query()->count());
        $this->assertSame(SuppressionReason::bounced, SuppressedAddress::query()->sole()->reason);
    }

    public function test_lift_removes_exactly_what_blocks_the_address(): void
    {
        SuppressedAddress::suppress('Jane.Doe@gmail.com', SuppressionReason::complained);
        SuppressedAddress::suppress('member+x@corp.example', SuppressionReason::bounced);

        $this->assertSame(0, SuppressedAddress::lift('member@corp.example'));
        $this->assertSame(1, SuppressedAddress::lift('janedoe+x@gmail.com'));
        $this->assertNull(SuppressedAddress::blocking('jane.doe@gmail.com'));
        $this->assertNotNull(SuppressedAddress::blocking('member+x@corp.example'));
    }

    public function test_a_block_on_an_own_inbox_is_reported(): void
    {
        Exceptions::fake();
        config(['email-integrity.suppression.own_inboxes' => ['Site <Contact@Example.com>', null, '']]);

        SuppressedAddress::suppress('contact+x@example.com', SuppressionReason::bounced);
        Exceptions::assertNothingReported();

        SuppressedAddress::suppress('Contact+x@example.com', SuppressionReason::complained);
        Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage()
            === 'The site inbox contact@example.com is now suppressed (complained). Fix its mailbox, then lift it here and at the mail provider.');
    }

    /** A refusal at send time never reached the provider's list. */
    public function test_an_own_inbox_refused_as_invalid_is_reported_without_the_provider(): void
    {
        Exceptions::fake();
        config(['email-integrity.suppression.own_inboxes' => ['contact@example.com']]);

        SuppressedAddress::suppress('Contact@Example.com', SuppressionReason::invalid);

        Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage()
            === 'The site inbox contact@example.com is now suppressed (invalid). Fix its mailbox, then lift it here.');
    }

    public function test_the_migration_is_published_dated_never_loaded(): void
    {
        $published = ServiceProvider::pathsToPublish(EmailIntegrityServiceProvider::class, 'email-integrity-migrations');

        $this->assertSame([database_path('migrations/2026_10_03_000000_create_suppressed_addresses_table.php')], array_values($published));
        $this->assertSame([], $this->app->make('migrator')->paths());
    }
}
