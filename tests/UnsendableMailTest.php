<?php

declare(strict_types=1);

namespace EmailIntegrity\Tests;

use EmailIntegrity\EmailAddress;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notifiable;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/** Symfony Mime throws mid-send on an address it cannot build, such as an imported one without its `@`. */
class UnsendableMailTest extends TestCase
{
    /** A channel beside mail, counting what reaches it. */
    private object $spy;

    protected function defineEnvironment($app): void
    {
        $app['config']->set('mail.default', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $spy = $this->spy = new class
        {
            public int $received = 0;

            public function send(mixed $notifiable, BaseNotification $notification): void
            {
                $this->received++;
            }
        };

        // A local: extend() rebinds the closure's $this to the manager.
        $this->app->make(ChannelManager::class)->extend('spy', fn () => $spy);
    }

    /** @param  string  $mail  The mail channel as via() names it: 'mail', or MailChannel::class. */
    private function notification(string $mail = 'mail'): BaseNotification
    {
        return new class($mail) extends BaseNotification
        {
            public function __construct(private string $mail) {}

            /** @return list<string> */
            public function via(mixed $notifiable): array
            {
                return [$this->mail, 'spy'];
            }

            public function toMail(mixed $notifiable): MailMessage
            {
                return (new MailMessage)->subject('Hello')->line('Hello');
            }
        };
    }

    /** @return int Messages the transport accepted. */
    private function sent(): int
    {
        return $this->app->make('mailer')->getSymfonyTransport()->messages()->count();
    }

    public function test_sendable_is_whether_a_message_can_be_addressed_to_it(): void
    {
        $this->assertTrue(EmailAddress::sendable('jane@example.com'));
        $this->assertTrue(EmailAddress::sendable('jane@灵.cc'));

        $this->assertFalse(EmailAddress::sendable('not-an-address'));
        $this->assertFalse(EmailAddress::sendable(''));
        $this->assertFalse(EmailAddress::sendable(null));
        $this->assertFalse(EmailAddress::sendable("jane@example.com\r\nBcc: x@example.com"));
        $this->assertFalse(EmailAddress::sendable("jane@example.com\n"), 'Laravel refuses a line break that Symfony trims.');
        $this->assertFalse(EmailAddress::sendable('Jane <jane@example.com>'), 'A bare address, not a header.');
    }

    public function test_an_unsendable_route_skips_only_the_mail_channel(): void
    {
        Log::spy();

        Notification::route('mail', 'not-an-address')->notify($this->notification());

        $this->assertSame(0, $this->sent());
        $this->assertSame(1, $this->spy->received);
        Log::shouldHaveReceived('info')->once()->with('Mail not sent: the address cannot receive mail.', [
            'email'        => 'not-an-address',
            'notification' => $this->notification()::class,
        ]);
    }

    public function test_one_unsendable_address_in_a_list_or_a_map_skips_the_mail(): void
    {
        Notification::route('mail', ['ok@example.com', 'not-an-address'])->notify($this->notification());
        Notification::route('mail', ['ok@example.com' => 'Ok', 'not-an-address' => 'Broken'])->notify($this->notification());

        $this->assertSame(0, $this->sent());
        $this->assertSame(2, $this->spy->received);
    }

    /** MailChannel hands a list entry to Symfony as a string, which reads `Name <address>`; a map key must be bare. */
    public function test_a_named_address_is_read_as_the_mail_channel_reads_it(): void
    {
        Notification::route('mail', 'Jane <jane@example.com>')->notify($this->notification());
        Notification::route('mail', ['Jane <jane@example.com>'])->notify($this->notification());

        $this->assertSame(2, $this->sent());

        Notification::route('mail', 'Jane <broken>')->notify($this->notification());
        Notification::route('mail', ['Jane <jane@example.com>' => 'Jane'])->notify($this->notification());

        $this->assertSame(2, $this->sent());
        $this->assertSame(4, $this->spy->received);
    }

    /** Laravel throws on a line break in any address, though Symfony would trim one off the end. */
    public function test_a_line_break_skips_the_mail(): void
    {
        Notification::route('mail', "jane@example.com\n")->notify($this->notification());
        Notification::route('mail', ["jane@example.com\n" => null])->notify($this->notification());

        $this->assertSame(0, $this->sent());
        $this->assertSame(2, $this->spy->received);
    }

    public function test_the_mail_channel_named_by_its_class_is_guarded_too(): void
    {
        Notification::route('mail', 'not-an-address')->notify($this->notification(MailChannel::class));

        $this->assertSame(0, $this->sent());
        $this->assertSame(1, $this->spy->received);
    }

    public function test_a_notifiables_own_route_is_checked_too(): void
    {
        $notifiable = new class
        {
            use Notifiable;

            public string $email = 'not-an-address';
        };

        $notifiable->notify($this->notification());

        $this->assertSame(0, $this->sent());
        $this->assertSame(1, $this->spy->received);
    }

    /** It answers null, not true: any other answer would stop the app's own NotificationSending listeners. */
    public function test_a_sendable_route_sends_and_reaches_the_other_listeners(): void
    {
        $seen = [];
        Event::listen(NotificationSending::class, function (NotificationSending $event) use (&$seen): void {
            $seen[] = $event->channel;
        });

        Notification::route('mail', ['ok@example.com' => 'Ok', 'also-ok@example.com' => null])->notify($this->notification());

        $this->assertSame(1, $this->sent());
        $this->assertSame(['mail', 'spy'], $seen);
    }
}
