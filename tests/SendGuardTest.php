<?php

declare(strict_types=1);

namespace EmailIntegrity\Tests;

use EmailIntegrity\SuppressedAddress;
use EmailIntegrity\SuppressionReason;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/** Mail::fake() never fires MessageSending, so these send for real, on the array mailer. */
class SendGuardTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('mail.default', 'array');
        $app['config']->set('email-integrity.suppression.enabled', true);
    }

    private function sent(): int
    {
        return $this->app->make('mailer')->getSymfonyTransport()->messages()->count();
    }

    public function test_a_blocked_recipient_drops_the_whole_message(): void
    {
        Log::spy();
        SuppressedAddress::suppress('dead@example.com', SuppressionReason::bounced);

        Mail::raw('Hello', fn ($message) => $message->to('ok@example.com')->bcc('Dead@Example.com', 'Dead')->subject('Welcome'));

        $this->assertSame(0, $this->sent());
        Log::shouldHaveReceived('warning')->once()->with('Mail not sent: the address is suppressed.', [
            'email'   => 'dead@example.com',
            'reason'  => 'bounced',
            'subject' => 'Welcome',
        ]);
    }

    public function test_a_mailable_message_reaches_the_other_listeners(): void
    {
        $seen = 0;
        Event::listen(MessageSending::class, function () use (&$seen): void {
            $seen++;
        });

        Mail::raw('Hello', fn ($message) => $message->to('ok@example.com')->cc('also-ok@example.com'));

        $this->assertSame(1, $seen);
        $this->assertSame(1, $this->sent());
    }

    public function test_a_tagged_bounce_leaves_the_untagged_address_mailable(): void
    {
        SuppressedAddress::suppress('member+x@corp.example', SuppressionReason::bounced);

        Mail::raw('Hello', fn ($message) => $message->to('member@corp.example'));

        $this->assertSame(1, $this->sent());
    }

    public function test_blocking_and_the_guard_are_inert_while_suppression_is_off(): void
    {
        SuppressedAddress::suppress('dead@example.com', SuppressionReason::bounced);
        config(['email-integrity.suppression.enabled' => false]);
        DB::enableQueryLog();

        $this->assertNull(SuppressedAddress::blocking('dead@example.com'));
        Mail::raw('Hello', fn ($message) => $message->to('dead@example.com'));

        $this->assertSame(1, $this->sent());
        $this->assertSame([], DB::getQueryLog());
    }

    public function test_lift_unblocks_the_address_and_says_to_clear_the_provider_too(): void
    {
        SuppressedAddress::suppress('jane.doe@gmail.com', SuppressionReason::complained);

        $this->artisan('email-integrity:lift', ['address' => 'JaneDoe+x@gmail.com'])
            ->expectsOutputToContain('Lifted 1')
            ->expectsOutputToContain("mail provider's own suppression list")
            ->assertExitCode(0);

        $this->assertNull(SuppressedAddress::blocking('jane.doe@gmail.com'));
    }

    /** The provider never listed an address refused at send time: nothing to clear there. */
    public function test_lift_of_an_address_refused_as_invalid_says_nothing_about_the_provider(): void
    {
        SuppressedAddress::suppress('jane@gmail', SuppressionReason::invalid);

        $this->artisan('email-integrity:lift', ['address' => 'jane@gmail'])
            ->expectsOutputToContain('Lifted 1')
            ->doesntExpectOutputToContain("mail provider's own suppression list")
            ->assertExitCode(0);

        $this->assertNull(SuppressedAddress::blocking('jane@gmail'));
    }

    /** Every row it lifts counts, and they are read while suppression is off too. */
    public function test_lift_warns_of_the_provider_by_the_rows_it_lifted(): void
    {
        SuppressedAddress::suppress('jane.doe@gmail.com', SuppressionReason::invalid);
        SuppressedAddress::suppress('janedoe+x@gmail.com', SuppressionReason::complained);
        SuppressedAddress::suppress('refused@example.com', SuppressionReason::invalid);
        config(['email-integrity.suppression.enabled' => false]);

        $this->artisan('email-integrity:lift', ['address' => 'jane.doe@gmail.com'])
            ->expectsOutputToContain('Lifted 2')
            ->expectsOutputToContain("mail provider's own suppression list")
            ->assertExitCode(0);

        $this->artisan('email-integrity:lift', ['address' => 'refused@example.com'])
            ->expectsOutputToContain('Lifted 1')
            ->doesntExpectOutputToContain("mail provider's own suppression list")
            ->assertExitCode(0);
    }
}
