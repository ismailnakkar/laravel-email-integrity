<?php

declare(strict_types=1);

namespace EmailIntegrity\Http;

use EmailIntegrity\EmailAddress;
use EmailIntegrity\SuppressedAddress;
use EmailIntegrity\SuppressionReason;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\Mailer\Bridge\Resend\Webhook\ResendRequestParser;
use Symfony\Component\RemoteEvent\Event\Mailer\AbstractMailerEvent;
use Symfony\Component\RemoteEvent\Event\Mailer\MailerDeliveryEvent;
use Symfony\Component\RemoteEvent\Event\Mailer\MailerEngagementEvent;
use Symfony\Component\RemoteEvent\Exception\ParseException;
use Symfony\Component\RemoteEvent\RemoteEvent;
use Symfony\Component\Webhook\Client\RequestParserInterface;
use Symfony\Component\Webhook\Exception\RejectWebhookException;

/** Suppresses the recipients of a complaint, a permanent bounce or the provider's block, as the parser reads them. */
final class SuppressionWebhook
{
    private const PERMANENT_BOUNCE = 'permanent';

    /** A shorter signing key is guessable. */
    private const MIN_KEY_BYTES = 16;

    private const WARNING_KEY_PREFIX = 'email-integrity:webhook-warning:';

    private const WARNING_WINDOW_SECONDS = 300;

    public function __invoke(Request $request): Response
    {
        $parser = $this->parser();
        $secret = $this->secret($parser);

        if ($secret === null) {
            $this->warnOnce('Mail provider webhook refused: the signing secret is missing or too short.');

            return response()->noContent(Response::HTTP_UNAUTHORIZED);
        }

        try {
            // Not $request: Laravel's toArray() is the input after TrimStrings and ConvertEmptyStringsToNull, not
            // the signed body.
            $events = Arr::wrap($parser->parse(new SymfonyRequest(server: $request->server->all(), content: $request->getContent()), $secret));
        } catch (RejectWebhookException $e) {
            if ($e->getPrevious() instanceof ParseException) {
                $type = $request->json('type');

                // Signed, but a type the parser does not model: answered, or the provider retries it for days.
                if (! is_string($type) || ResendEvent::tryFrom($type) === null) {
                    Log::warning('Mail provider event the parser does not model ignored.', ['reason' => $e->getMessage()]);

                    return response()->noContent();
                }

                // Signed and blocking, so a parser bug (Symfony's "Invalid date"): refused, to retry once fixed.
                report($e->getPrevious());
            }

            // Laravel never reports an HttpException, so a rotated secret would otherwise fail without a trace.
            $this->warnOnce('Mail provider webhook refused.', ['status' => $e->getStatusCode(), 'reason' => $e->getMessage()]);

            throw $e;
        }

        foreach ($events as $event) {
            $this->record($event);
        }

        return response()->noContent();
    }

    /**
     * Logged at most once per message and context every five minutes: anyone can post to the webhook URL, and each
     * warning may be an event in the app's error tracker.
     *
     * @param  array<string, mixed>  $context
     */
    private function warnOnce(string $message, array $context = []): void
    {
        // Fails open: a cache that throws or keeps nothing (the null store's add() answers false) logs every one.
        $first = rescue(function () use ($message, $context): bool {
            $key = self::WARNING_KEY_PREFIX . hash('xxh128', serialize([$message, $context]));

            return Cache::add($key, true, self::WARNING_WINDOW_SECONDS) || ! Cache::has($key);
        }, true, false);

        if ($first) {
            Log::warning($message, $context);
        }
    }

    private function parser(): RequestParserInterface
    {
        if (! config('email-integrity.suppression.enabled')) {
            throw new LogicException('Set email-integrity.suppression.enabled to true before routing the suppression webhook.');
        }

        $class = config('email-integrity.suppression.webhook.parser');

        // interface_exists() first: loading a bridge's parser without symfony/webhook is a fatal Error, not false.
        if (! interface_exists(RequestParserInterface::class) || ! is_string($class) || ! is_subclass_of($class, RequestParserInterface::class)) {
            throw new LogicException(sprintf(
                'The suppression webhook parser [%s] is missing or is not a %s. Install symfony/webhook and its bridge (for Resend: composer require symfony/webhook symfony/resend-mailer).',
                is_string($class) ? $class : get_debug_type($class),
                RequestParserInterface::class,
            ));
        }

        return app($class);
    }

    /** The secret, or null when it is missing or gives a key under MIN_KEY_BYTES. */
    private function secret(RequestParserInterface $parser): ?string
    {
        $secret = config('email-integrity.suppression.webhook.secret');

        if (! is_string($secret)) {
            return null;
        }

        // Symfony's Resend parser decodes `whsec_<base64>` leniently: `whsec_` or a non-base64 tail verifies a forgery
        // signed with an empty key. The prefix also catches a wrapper of that final class.
        $key = $parser instanceof ResendRequestParser || str_starts_with($secret, 'whsec_')
            ? base64_decode(Str::chopStart($secret, 'whsec_'), true)
            : $secret;

        return is_string($key) && strlen($key) >= self::MIN_KEY_BYTES ? $secret : null;
    }

    private function record(RemoteEvent $event): void
    {
        $reason = $this->reason($event);

        if ($reason === null || ! $event instanceof AbstractMailerEvent) {
            return;
        }

        $from = Arr::get($event->getPayload(), 'data.from');

        // One provider account may send for several domains, and each endpoint receives every event. A provider with
        // one webhook per domain sends no sender: nothing to filter.
        if ($from !== null) {
            // Thrown, not skipped: a 204 here would drop every event for good, and a 500 makes the provider retry.
            $own = EmailAddress::domainOf(EmailAddress::bare((string)config('mail.from.address')))
                ?? throw new LogicException("Set mail.from.address to an address on the provider's sending domain before routing the suppression webhook.");
            $domain = is_string($from) ? EmailAddress::domainOf(EmailAddress::bare($from)) : null;

            if ($domain === null || ! EmailAddress::listedIn($domain, [$own])) {
                Log::info('Mail provider event for another sending domain ignored.', ['event' => $event->getName(), 'from' => $from]);

                return;
            }
        }

        $to = Arr::get($event->getPayload(), 'data.to');

        foreach (is_array($to) && array_is_list($to) ? $to : [$event->getRecipientEmail()] as $recipient) {
            if (is_string($recipient)) {
                SuppressedAddress::suppress($recipient, $reason);
            }
        }
    }

    private function reason(RemoteEvent $event): ?SuppressionReason
    {
        if ($event instanceof MailerEngagementEvent) {
            return $event->getName() === MailerEngagementEvent::SPAM ? SuppressionReason::complained : null;
        }

        if (! $event instanceof MailerDeliveryEvent) {
            return null;
        }

        $payload = $event->getPayload();
        $bounce = Arr::get($payload, 'data.bounce.type');
        $type = Arr::get($payload, 'type');

        return match ($event->getName()) {
            // Permanent only: a full mailbox must not lock anyone out of a password reset.
            MailerDeliveryEvent::BOUNCE => is_string($bounce) && strcasecmp($bounce, self::PERMANENT_BOUNCE) === 0 ? SuppressionReason::bounced : null,
            // Resend's own block; `email.failed`, the other DROPPED, is a quota and blocks nothing.
            MailerDeliveryEvent::DROPPED => is_string($type) && ResendEvent::tryFrom($type) === ResendEvent::suppressed ? SuppressionReason::bounced : null,
            default                      => null,
        };
    }
}
