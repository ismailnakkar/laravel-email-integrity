<?php

declare(strict_types=1);

namespace EmailIntegrity\Tests;

use EmailIntegrity\SuppressedAddress;
use EmailIntegrity\SuppressionReason;
use EmailIntegrity\Tests\Fixtures\SingleRecipientParser;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Symfony\Component\RemoteEvent\Exception\ParseException;

/** Signed the way Resend signs (Svix), with the `Name <address>` sender and recipients Resend really sends. */
class SuppressionWebhookTest extends TestCase
{
    private const KEY = 'a-32-byte-shared-key-for-signing';

    protected function defineEnvironment($app): void
    {
        $app['config']->set('mail.default', 'array');
        $app['config']->set('mail.from.address', 'hello@example.com');
        $app['config']->set('email-integrity.suppression.enabled', true);
        $app['config']->set('email-integrity.suppression.webhook.secret', 'whsec_' . base64_encode(self::KEY));
    }

    public function test_a_complaint_blocks_every_spelling_of_the_inbox(): void
    {
        $this->deliver($this->event('email.complained', ['Jane <Jane.Doe+News@gmail.com>']))->assertNoContent();

        $this->assertSame(SuppressionReason::complained, SuppressedAddress::blocking('janedoe@googlemail.com')?->reason);
        $this->assertNotNull(SuppressedAddress::blocking('j.anedoe+other@gmail.com'));
    }

    public function test_a_permanent_bounce_blocks_only_its_literal_address(): void
    {
        $this->deliver($this->event('email.bounced', ['Member+Shop@Corp.Example'], ['bounce' => ['type' => 'Permanent', 'subType' => 'General']]))
            ->assertNoContent();

        $this->assertSame(SuppressionReason::bounced, SuppressedAddress::blocking('member+shop@corp.example')?->reason);
        $this->assertNull(SuppressedAddress::blocking('member@corp.example'));
        $this->assertNull(SuppressedAddress::blocking('member+other@corp.example'));
    }

    /** A stranger typed `member+x@` into a public form and it bounced on a server without tags. */
    public function test_a_strangers_tagged_bounce_leaves_the_member_mailable(): void
    {
        $this->deliver($this->event('email.bounced', ['member+x@corp.example'], ['bounce' => ['type' => 'Permanent']]))
            ->assertNoContent();

        Mail::raw('Your reset link', fn ($message) => $message->to('member@corp.example'));

        $this->assertSame(1, $this->app->make('mailer')->getSymfonyTransport()->messages()->count());
    }

    /** A `_ci` collation folds these onto `jack@`, so a stranger's bounce on one would block the real inbox. */
    public function test_a_lookalike_spelling_never_blocks_the_ascii_address(): void
    {
        $this->deliver($this->event('email.bounced', ['ｊａｃｋ@example.com', "jac\u{212A}@example.com", 'jäck@example.com'], ['bounce' => ['type' => 'Permanent']]))
            ->assertNoContent();

        $this->assertSame(0, SuppressedAddress::query()->count());
        Mail::raw('Your reset link', fn ($message) => $message->to('jack@example.com'));
        $this->assertSame(1, $this->app->make('mailer')->getSymfonyTransport()->messages()->count());
    }

    /** Resend skipped the send against its own list. */
    public function test_a_suppressed_event_blocks_the_address(): void
    {
        $this->deliver($this->event('email.suppressed', ['listed@example.com'], ['suppressed' => ['message' => 'On the account suppression list']]))
            ->assertNoContent();

        $this->assertSame(SuppressionReason::bounced, SuppressedAddress::blocking('listed@example.com')?->reason);
    }

    /** @return array<string, array{string, array<string, mixed>}> */
    public static function harmlessEvents(): array
    {
        return [
            'a temporary bounce'    => ['email.bounced', ['bounce' => ['type' => 'Transient', 'subType' => 'MailboxFull']]],
            'a bounce with no type' => ['email.bounced', []],
            'a quota failure'       => ['email.failed', ['failed' => ['reason' => 'reached_daily_quota']]],
            'a delivery'            => ['email.delivered', []],
            'an open'               => ['email.opened', []],
        ];
    }

    /** @param array<string, mixed> $data */
    #[DataProvider('harmlessEvents')]
    public function test_an_event_that_blocks_nothing_writes_nothing(string $type, array $data): void
    {
        $this->deliver($this->event($type, ['someone@example.com'], $data))->assertNoContent();

        $this->assertSame(0, SuppressedAddress::query()->count());
    }

    /** Signed, but a type the parser does not model: answered, or the provider retries it for days. */
    public function test_an_unsupported_signed_type_is_answered(): void
    {
        Log::spy();

        $this->deliver($this->event('email.scheduled', ['someone@example.com']))->assertNoContent();

        $this->assertSame(0, SuppressedAddress::query()->count());
        Log::shouldHaveReceived('warning')->with('Mail provider event the parser does not model ignored.', ['reason' => 'Unsupported event "email.scheduled".']);
    }

    /** A bounce the parser cannot read (Symfony's "Invalid date") must be retried once fixed, not answered and lost. */
    public function test_an_unreadable_event_of_a_type_that_blocks_is_refused_and_reported(): void
    {
        Exceptions::fake();

        $this->deliver(['created_at' => '2026-10-03 09:15:42'] + $this->event('email.bounced', ['someone@example.com'], ['bounce' => ['type' => 'Permanent']]))
            ->assertStatus(406);

        $this->assertSame(0, SuppressedAddress::query()->count());
        Exceptions::assertReported(ParseException::class);
    }

    /** @return array<string, array{string, int, bool}> */
    public static function forgeries(): array
    {
        return [
            'a wrong key'       => ['the-wrong-key-entirely-for-svix!', 0, true],
            'a stale timestamp' => [self::KEY, 301, true],
            'no signature'      => [self::KEY, 0, false],
        ];
    }

    /** Nothing else reports a 406, so a rotated secret would otherwise fail silently until the provider gives up. */
    #[DataProvider('forgeries')]
    public function test_a_forgery_is_refused_with_the_parsers_status(string $key, int $age, bool $signed): void
    {
        Log::spy();

        $this->deliver($this->event('email.complained', ['victim@example.com']), $key, $age, $signed)->assertStatus(406);

        $this->assertSame(0, SuppressedAddress::query()->count());
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $message === 'Mail provider webhook refused.'
            && $context['status'] === 406);
    }

    /** @return array<string, array{?string}> */
    public static function weakSecrets(): array
    {
        return [
            'missing'              => [null],
            'empty'                => [''],
            'the prefix alone'     => ['whsec_'],
            'a non-base64 tail'    => ['whsec_!!!'],
            'unprefixed junk'      => ['x'],
            'a key under 16 bytes' => ['whsec_' . base64_encode('fifteen-bytes!!')],
        ];
    }

    /** Resend's parser decodes the secret leniently: each of these signs with an empty or guessable key. */
    #[DataProvider('weakSecrets')]
    public function test_a_weak_secret_refuses_everything(?string $secret): void
    {
        Log::spy();
        config(['email-integrity.suppression.webhook.secret' => $secret]);

        $this->deliver($this->event('email.complained', ['victim@example.com']), key: base64_decode(Str::chopStart((string)$secret, 'whsec_')))
            ->assertUnauthorized();

        $this->assertSame(0, SuppressedAddress::query()->count());
        Log::shouldHaveReceived('warning')->with('Mail provider webhook refused: the signing secret is missing or too short.');
    }

    /** A wrapper around Resend's final parser still gets its `whsec_` secret decoded strictly. */
    public function test_a_whsec_secret_is_decoded_strictly_for_any_parser(): void
    {
        config([
            'email-integrity.suppression.webhook.parser' => SingleRecipientParser::class,
            'email-integrity.suppression.webhook.secret' => 'whsec_' . str_repeat('!', 16),
        ]);

        $this->call('POST', '/email-integrity/webhook', server: ['CONTENT_TYPE' => 'application/json'], content: '{"recipient": "victim@example.com"}')
            ->assertUnauthorized();
    }

    /** @return array<string, array{string}> */
    public static function foreignSenders(): array
    {
        return [
            'another domain on the same account' => ['Other <hello@example.org>'],
            'a lookalike domain'                 => ['Example <hello@evil-example.com>'],
        ];
    }

    /** One provider account sends for several domains, and each endpoint receives every one of them. */
    #[DataProvider('foreignSenders')]
    public function test_an_event_for_another_sending_domain_is_ignored(string $from): void
    {
        Log::spy();

        $this->deliver($this->event('email.complained', ['someone@example.com'], ['from' => $from]))->assertNoContent();

        $this->assertSame(0, SuppressedAddress::query()->count());
        Log::shouldHaveReceived('info')->with('Mail provider event for another sending domain ignored.', ['event' => 'spam', 'from' => $from]);
    }

    public function test_a_subdomain_of_the_sending_domain_counts_as_ours(): void
    {
        $this->deliver($this->event('email.complained', ['someone@example.com'], ['from' => 'Example <notify@mail.example.com>']))
            ->assertNoContent();

        $this->assertNotNull(SuppressedAddress::blocking('someone@example.com'));
    }

    /** TrimStrings turns "" into null, and the parser would then call the signed event malformed for ever. */
    public function test_an_empty_subject_survives_the_input_middleware(): void
    {
        $this->deliver($this->event('email.complained', ['someone@example.com'], ['subject' => '']))->assertNoContent();

        $this->assertNotNull(SuppressedAddress::blocking('someone@example.com'));
    }

    /** Another provider: a plain secret used as it is, one recipient, and no sender to filter on. */
    public function test_another_parser_with_no_sender_names_its_one_recipient(): void
    {
        config([
            'email-integrity.suppression.webhook.parser' => SingleRecipientParser::class,
            'email-integrity.suppression.webhook.secret' => 'plain-shared-secret-of-32-chars!',
        ]);

        $this->call('POST', '/email-integrity/webhook', server: [
            'CONTENT_TYPE'  => 'application/json',
            'HTTP_X_SECRET' => 'plain-shared-secret-of-32-chars!',
        ], content: '{"recipient": "Jane <Jane.Doe@gmail.com>"}')->assertNoContent();

        $this->assertSame(SuppressionReason::complained, SuppressedAddress::blocking('janedoe@gmail.com')?->reason);
    }

    /** Without the app's own sending domain every event would look foreign and be dropped with a 204, for good. */
    public function test_an_unset_sender_address_is_refused_so_the_provider_retries(): void
    {
        config(['mail.from.address' => null]);

        $this->withoutExceptionHandling();
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('mail.from.address');

        $this->deliver($this->event('email.complained', ['someone@example.com']));
    }

    /** Routing the webhook while the guard is off would store blocks that stop nothing. */
    public function test_the_webhook_refuses_to_run_while_suppression_is_off(): void
    {
        config(['email-integrity.suppression.enabled' => false]);

        $this->withoutExceptionHandling();
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('email-integrity.suppression.enabled');

        $this->deliver($this->event('email.complained', ['someone@example.com']));
    }

    /** @return array<string, array{string}> */
    public static function unusableParsers(): array
    {
        return [
            'a class that is not installed' => ['Vendor\\Missing\\RequestParser'],
            'a class that is not a parser'  => [stdClass::class],
        ];
    }

    #[DataProvider('unusableParsers')]
    public function test_an_unusable_parser_names_the_install(string $parser): void
    {
        config(['email-integrity.suppression.webhook.parser' => $parser]);

        $this->withoutExceptionHandling();
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('composer require');

        $this->deliver($this->event('email.complained', ['someone@example.com']));
    }

    /**
     * @param  list<string>  $to
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function event(string $type, array $to, array $data = []): array
    {
        return [
            'type'       => $type,
            'created_at' => '2026-10-03T09:15:42.126Z',
            'data'       => [
                'created_at' => '2026-10-03T09:15:41.894719+00:00',
                'email_id'   => '56761188-7520-42d8-8898-ff6fc54ce618',
                'from'       => 'Example <hello@example.com>',
                'to'         => $to,
                'subject'    => 'Confirm your email address',
                ...$data,
            ],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function deliver(array $payload, string $key = self::KEY, int $age = 0, bool $signed = true): TestResponse
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $id = 'msg_2abc';
        $timestamp = time() - $age;
        $signature = base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", $key, true));

        return $this->call('POST', '/email-integrity/webhook', server: ['CONTENT_TYPE' => 'application/json'] + ($signed ? [
            'HTTP_SVIX_ID'        => $id,
            'HTTP_SVIX_TIMESTAMP' => (string)$timestamp,
            // Space-separated entries: a secret rotation puts two in flight.
            'HTTP_SVIX_SIGNATURE' => 'v1,b2xkZXIta2V5LXNpZ25hdHVyZQ== v1,' . $signature,
        ] : []), content: $body);
    }
}
