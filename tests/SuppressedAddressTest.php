<?php

declare(strict_types=1);

namespace EmailIntegrity\Tests;

use EmailIntegrity\EmailIntegrityServiceProvider;
use EmailIntegrity\ReleasedAddress;
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

    /** What the sweep would refuse, a send that would throw, or a refusal at send time; never a bounce or a complaint. */
    public function test_unreachable_is_an_address_no_mail_can_reach(): void
    {
        SuppressedAddress::suppress('refused@example.com', SuppressionReason::invalid);
        SuppressedAddress::suppress('bounced@example.com', SuppressionReason::bounced);
        SuppressedAddress::suppress('complained@example.com', SuppressionReason::complained);

        $this->assertTrue(SuppressedAddress::unreachable('Refused@Example.com'));
        $this->assertTrue(SuppressedAddress::unreachable('jane@gmail'));
        $this->assertTrue(SuppressedAddress::unreachable('a@192.0.2.1'));
        $this->assertTrue(SuppressedAddress::unreachable('jäck@example.com'));
        $this->assertTrue(SuppressedAddress::unreachable('carol@gmail.com.'));
        $this->assertTrue(SuppressedAddress::unreachable('Jane <jane@example.com>'));
        $this->assertTrue(SuppressedAddress::unreachable(''));

        $this->assertFalse(SuppressedAddress::unreachable('bounced@example.com'));
        $this->assertFalse(SuppressedAddress::unreachable('complained@example.com'));
        $this->assertFalse(SuppressedAddress::unreachable('someone@example.com'));
        // The form a row would hold, as the sweep judges it: these fold onto a working inbox.
        $this->assertFalse(SuppressedAddress::unreachable('bob@münchen.de'));
        $this->assertFalse(SuppressedAddress::unreachable(' bob@gmail.com'));

        config(['email-integrity.suppression.enabled' => false]);

        $this->assertFalse(SuppressedAddress::unreachable('refused@example.com'));
        $this->assertTrue(SuppressedAddress::unreachable('jane@gmail'));
    }

    /** Two rows block the spelling: a complaint on its inbox, found first by id and by key, and a refusal of the spelling. */
    public function test_a_refusal_counts_beside_a_complaint_on_the_inbox(): void
    {
        SuppressedAddress::suppress('janedoe@gmail.com', SuppressionReason::complained);
        SuppressedAddress::suppress('janedoe@googlemail.com', SuppressionReason::invalid);

        $this->assertTrue(SuppressedAddress::unreachable('janedoe@googlemail.com'));
    }

    public function test_release_lifts_and_says_whether_the_provider_may_still_hold_the_address(): void
    {
        SuppressedAddress::suppress('refused@example.com', SuppressionReason::invalid);

        $this->assertEquals(new ReleasedAddress(rows: 1, heldByProvider: false), SuppressedAddress::release('Refused@Example.com'));
        $this->assertEquals(new ReleasedAddress(rows: 0, heldByProvider: true), SuppressedAddress::release('refused@example.com'));

        // Every row lifted counts, not the first one found: the complaint on the inbox is on the provider's list.
        SuppressedAddress::suppress('jane.doe@gmail.com', SuppressionReason::invalid);
        SuppressedAddress::suppress('janedoe+x@gmail.com', SuppressionReason::complained);
        SuppressedAddress::suppress('someone@example.com', SuppressionReason::bounced);

        $this->assertEquals(new ReleasedAddress(rows: 2, heldByProvider: true), SuppressedAddress::release('jane.doe@gmail.com'));
        $this->assertSame(['someone@example.com'], SuppressedAddress::query()->pluck('email')->all());
    }

    public function test_release_reads_the_rows_while_suppression_is_off(): void
    {
        SuppressedAddress::suppress('refused@example.com', SuppressionReason::invalid);
        config(['email-integrity.suppression.enabled' => false]);

        $this->assertEquals(new ReleasedAddress(rows: 1, heldByProvider: false), SuppressedAddress::release('refused@example.com'));
    }

    public function test_each_reason_has_a_label_in_the_current_language(): void
    {
        $this->assertSame('The address bounced', SuppressionReason::bounced->label());
        $this->assertSame('The recipient marked a mail as spam', SuppressionReason::complained->label());
        $this->assertSame('The mail server refused the address as invalid', SuppressionReason::invalid->label());

        $this->app->setLocale('fr');

        $this->assertSame('Le serveur de messagerie a refusé l’adresse comme invalide', SuppressionReason::invalid->label());
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
