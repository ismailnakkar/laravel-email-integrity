<?php

declare(strict_types=1);

namespace EmailIntegrity\Tests;

use EmailIntegrity\Mail\GuardedMailManager;
use EmailIntegrity\Mail\RefusedRecipientGuard;
use EmailIntegrity\Tests\Fixtures\QueuedNotice;
use EmailIntegrity\Tests\Fixtures\ScriptedSmtpStream;
use Illuminate\Mail\MailManager;
use Illuminate\Mail\SentMessage;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\Transport\NullTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;

/** Sends for real through Symfony's SMTP transport, to a scripted server. */
class RefusedRecipientTest extends TestCase
{
    private const RESEND_REFUSAL = '550 Invalid recipient address. The email address needs to follow the email@example.com format.';

    protected function defineEnvironment($app): void
    {
        $app['config']->set('email-integrity.suppression.enabled', true);
        $app['config']->set('mail.default', 'scripted');
        $app['config']->set('mail.mailers.scripted', ['transport' => 'scripted']);
        $app['config']->set('queue.default', 'sync');
    }

    public function test_a_recipient_the_server_refuses_as_invalid_is_suppressed_and_the_send_ends_quietly(): void
    {
        Log::spy();

        $this->assertNull($this->send(['250 sender ok', self::RESEND_REFUSAL, '250 reset'], ['member@localhost']));

        $this->assertSame(['member@localhost' => 'invalid'], $this->suppressed());
        Log::shouldHaveReceived('warning')->with('Mail not sent: the server refused the address.', [
            'email'   => 'member@localhost',
            'reply'   => 'Expected response code "250/251/252" but got code "550", with message "' . self::RESEND_REFUSAL . '".',
            'subject' => 'Welcome',
        ])->once();

        // The next mail stops before the server: its script has no reply left.
        Mail::raw('Hello again', fn ($message) => $message->to('Member@Localhost')->subject('Again'));
        Log::shouldHaveReceived('warning')->with('Mail not sent: the address is suppressed.', [
            'email'   => 'member@localhost',
            'reason'  => 'invalid',
            'subject' => 'Again',
        ])->once();
    }

    public function test_a_queued_notification_to_a_refused_address_neither_fails_nor_retries(): void
    {
        $this->script(['250 sender ok', self::RESEND_REFUSAL, '250 reset']);
        $failed = false;
        Queue::failing(function () use (&$failed): void {
            $failed = true;
        });

        Notification::route('mail', 'member@localhost')->notify(new QueuedNotice);

        $this->assertFalse($failed);
        $this->assertSame(['member@localhost' => 'invalid'], $this->suppressed());
    }

    public function test_the_one_recipient_a_reply_names_is_suppressed_on_a_message_to_several(): void
    {
        $refusal = '550 5.1.1 <b@example.com>: Recipient address rejected: User unknown in virtual mailbox table';

        $this->assertNull($this->send(['250 sender ok', '250 ok', $refusal, '250 reset'], ['a@example.com', 'b@example.com']));

        $this->assertSame(['b@example.com' => 'invalid'], $this->suppressed());
    }

    /** Not about one known recipient, or not for good: the send fails as before and nothing is suppressed. */
    #[TestWith([['250 sender ok', '250 ok', self::RESEND_REFUSAL, '250 reset'], ['a@example.com', 'b@localhost']], 'several recipients, none named')]
    #[TestWith([['550 5.7.1 Sender domain is not verified', '250 reset'], ['member@example.com']], 'a refused sender')]
    #[TestWith([['250 sender ok', '550 5.1.8 Bad sender address', '250 reset'], ['member@example.com']], 'a sender status at RCPT')]
    #[TestWith([['250 sender ok', '450 4.1.1 Mailbox busy', '250 reset'], ['member@example.com']], 'a temporary refusal')]
    #[TestWith([['250 sender ok', '250 ok', '354 go', '554 5.7.1 Message rejected as spam', '250 reset'], ['member@example.com']], 'a refused message')]
    public function test_another_refusal_still_fails_the_send(array $replies, array $to): void
    {
        try {
            $this->send($replies, $to);
            $this->fail('The send did not fail.');
        } catch (UnexpectedResponseException) {
        }

        $this->assertSame([], $this->suppressed());
    }

    public function test_the_guard_is_inert_while_suppression_is_off(): void
    {
        config(['email-integrity.suppression.enabled' => false]);

        $this->expectException(UnexpectedResponseException::class);

        try {
            $this->send(['250 sender ok', self::RESEND_REFUSAL, '250 reset'], ['member@localhost']);
        } finally {
            $this->assertSame([], $this->suppressed());
        }
    }

    public function test_only_smtp_transports_are_wrapped(): void
    {
        config(['mail.mailers.smtp' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 2525]]);

        $smtp = Mail::mailer('smtp')->getSymfonyTransport();
        $this->assertInstanceOf(RefusedRecipientGuard::class, $smtp);
        $this->assertInstanceOf(EsmtpTransport::class, $smtp->transport);
        $this->assertInstanceOf(ArrayTransport::class, Mail::mailer('array')->getSymfonyTransport());
    }

    public function test_the_manager_keeps_the_transports_an_earlier_extender_added(): void
    {
        $base = new MailManager($this->app);
        $null = new NullTransport;
        $base->extend('null-ish', fn (): TransportInterface => $null);

        $this->assertSame($null, GuardedMailManager::replacing($base)->createSymfonyTransport(['transport' => 'null-ish']));
    }

    /** @param  list<string>  $replies */
    private function script(array $replies): void
    {
        Mail::extend('scripted', fn (): SmtpTransport => new SmtpTransport(new ScriptedSmtpStream($replies)));
        Mail::purge('scripted');
    }

    /**
     * @param  list<string>  $replies
     * @param  list<string>  $to
     */
    private function send(array $replies, array $to): ?SentMessage
    {
        $this->script($replies);

        return Mail::raw('Hello', fn ($message) => $message->to($to)->subject('Welcome'));
    }

    /** @return array<string, string> */
    private function suppressed(): array
    {
        return DB::table('suppressed_addresses')->orderBy('email')->pluck('reason', 'email')->all();
    }
}
