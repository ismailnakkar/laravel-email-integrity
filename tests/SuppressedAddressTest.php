<?php

declare(strict_types=1);

namespace EmailIntegrity\Tests;

use EmailIntegrity\EmailIntegrityServiceProvider;
use EmailIntegrity\SuppressedAddress;
use EmailIntegrity\SuppressionReason;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class SuppressedAddressTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('email-integrity.suppression.enabled', true);
    }

    /** Only the inbox's owner can complain; a bounce may follow a stranger's typo, so it keys only its own spelling. */
    public function test_a_complaint_keys_the_inbox_and_a_bounce_keys_the_spelling(): void
    {
        $this->assertSame('janedoe@gmail.com', SuppressedAddress::key('Jane <Jane.Doe+News@GoogleMail.com>', SuppressionReason::complained));
        $this->assertSame('jane.doe+news@googlemail.com', SuppressedAddress::key('Jane <Jane.Doe+News@GoogleMail.com>', SuppressionReason::bounced));
    }

    /** A quoted local part has no canonical form, so a complaint falls back to the literal spelling. */
    public function test_a_quoted_complaint_keys_its_literal_spelling(): void
    {
        $this->assertSame('"a b"@example.com', SuppressedAddress::key('"A B"@Example.com', SuppressionReason::complained));
    }

    public function test_an_address_with_no_key_is_never_suppressed(): void
    {
        $tooLong = str_repeat('a', 243) . '@example.com';

        $this->assertNull(SuppressedAddress::key('undisclosed recipients', SuppressionReason::bounced));
        $this->assertNull(SuppressedAddress::key($tooLong, SuppressionReason::complained));
        // MariaDB pads spaces when it compares, so a key ending in one would block the address without it.
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

    /** Providers redeliver: the second event is a no-op, and the first reason sticks. */
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

    /** Rows written before 1.1 hold the address as received; rekey() moves them to their key, one row per key. */
    public function test_rekey_rewrites_old_rows_and_merges_the_duplicates(): void
    {
        DB::table('suppressed_addresses')->insert([
            ['email' => 'Jane <Jane.Doe@gmail.com>', 'reason' => 'complained'],
            ['email' => 'janedoe+x@gmail.com', 'reason' => 'complained'],
            ['email' => 'Member+X@Corp.Example', 'reason' => 'bounced'],
            ['email' => 'dead@example.com', 'reason' => 'bounced'],
            ['email' => 'undisclosed recipients', 'reason' => 'bounced'],
        ]);

        $this->assertSame(4, SuppressedAddress::rekey());
        $this->assertSame(
            ['janedoe@gmail.com', 'member+x@corp.example', 'dead@example.com'],
            SuppressedAddress::query()->orderBy('id')->pluck('email')->all(),
        );
        $this->assertSame(0, SuppressedAddress::rekey());
    }

    /** A keyless spelling could match an ASCII lookalike under a _ci collation: rekey() deletes it, and says so. */
    public function test_rekey_deletes_keyless_rows(): void
    {
        Log::spy();
        DB::table('suppressed_addresses')->insert([
            ['email' => 'jäck@example.com', 'reason' => 'bounced'],
            ['email' => 'dead@example.com', 'reason' => 'bounced'],
        ]);

        $this->assertSame(1, SuppressedAddress::rekey());
        $this->assertSame(['dead@example.com'], SuppressedAddress::query()->pluck('email')->all());
        Log::shouldHaveReceived('warning')->once()->with('Suppressed address with no key deleted.', ['email' => 'jäck@example.com', 'reason' => 'bounced']);
    }

    /** Rewriting an email is housekeeping: the row keeps its timestamps. */
    public function test_rekey_keeps_timestamps(): void
    {
        DB::table('suppressed_addresses')->insert([
            'email'      => 'Dead@Example.com', 'reason' => 'bounced',
            'created_at' => '2020-01-01 00:00:00', 'updated_at' => '2020-01-02 00:00:00',
        ]);

        SuppressedAddress::rekey();

        $row = DB::table('suppressed_addresses')->first();
        $this->assertSame('dead@example.com', $row->email);
        $this->assertSame('2020-01-01 00:00:00', $row->created_at);
        $this->assertSame('2020-01-02 00:00:00', $row->updated_at);
    }

    /** The site's own inbox is blocked like any other (the provider skips it anyway), and someone is told. */
    public function test_a_block_on_an_own_inbox_is_reported(): void
    {
        Exceptions::fake();
        config(['email-integrity.suppression.own_inboxes' => ['Site <Contact@Example.com>', null, '']]);

        SuppressedAddress::suppress('contact+x@example.com', SuppressionReason::bounced);
        Exceptions::assertNothingReported();

        SuppressedAddress::suppress('Contact+x@example.com', SuppressionReason::complained);
        Exceptions::assertReported(fn (RuntimeException $e): bool => str_contains($e->getMessage(), 'contact@example.com')
            && str_contains($e->getMessage(), 'complained'));
    }

    /** Apps that already have the table must not get a second one on `migrate`. */
    public function test_the_migration_is_published_never_loaded(): void
    {
        $this->assertNotEmpty(ServiceProvider::pathsToPublish(EmailIntegrityServiceProvider::class, 'email-integrity-migrations'));
        $this->assertSame([], $this->app->make('migrator')->paths());
    }
}
