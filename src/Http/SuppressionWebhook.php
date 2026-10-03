<?php

declare(strict_types=1);

namespace EmailIntegrity\Http;

use EmailIntegrity\EmailAddress;
use EmailIntegrity\SuppressedAddress;
use EmailIntegrity\SuppressionReason;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
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

/**
 * Reads the mail provider's events through its Symfony parser and suppresses the recipients of a complaint, a
 * permanent bounce or the provider's own block. Route it yourself, outside CSRF, behind a rate limiter.
 */
final class SuppressionWebhook
{
    public function __invoke(Request $request): Response
    {
        $parser = $this->parser();
        $secret = $this->secret($parser);

        if ($secret === null) {
            Log::warning('Mail provider webhook refused: the signing secret is missing or too short.');

            return response()->noContent(Response::HTTP_UNAUTHORIZED);
        }

        try {
            // Rebuilt from the raw body: Laravel's toArray() would hand the parser the input after TrimStrings and
            // ConvertEmptyStringsToNull, which the signature does not cover.
            $events = Arr::wrap($parser->parse(new SymfonyRequest(server: $request->server->all(), content: $request->getContent()), $secret));
        } catch (RejectWebhookException $e) {
            if ($e->getPrevious() instanceof ParseException) {
                // Signed, but a type the parser does not model: answered, or the provider retries it for days.
                if (! in_array($request->json('type'), ['email.bounced', 'email.complained', 'email.suppressed'], true)) {
                    Log::warning('Mail provider event the parser does not model ignored.', ['reason' => $e->getMessage()]);

                    return response()->noContent();
                }

                // Signed and one that blocks, so a parser bug (Symfony's "Invalid date"): refused, to be retried once fixed.
                report($e->getPrevious());
            }

            // Laravel never reports an HttpException, so a rotated secret would otherwise fail without a trace.
            Log::warning('Mail provider webhook refused.', ['status' => $e->getStatusCode(), 'reason' => $e->getMessage()]);

            throw $e;
        }

        foreach ($events as $event) {
            $this->record($event);
        }

        return response()->noContent();
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

    /** The secret, or null when it is missing or gives a key short enough to guess. */
    private function secret(RequestParserInterface $parser): ?string
    {
        $secret = config('email-integrity.suppression.webhook.secret');

        if (! is_string($secret)) {
            return null;
        }

        // Only because Symfony's Resend parser decodes `whsec_<base64>` leniently: `whsec_` or a non-base64 tail would
        // verify a forgery signed with an empty key. Strict for that parser and any wrapper of it (it is final, so a
        // wrapper is how an app customises it); any other parser uses its secret as it is.
        $key = $parser instanceof ResendRequestParser || str_starts_with($secret, 'whsec_')
            ? base64_decode(Str::chopStart($secret, 'whsec_'), true)
            : $secret;

        return is_string($key) && strlen($key) >= 16 ? $secret : null;
    }

    private function record(RemoteEvent $event): void
    {
        $reason = $this->reason($event);

        if ($reason === null) {
            return;
        }

        $from = Arr::get($event->getPayload(), 'data.from');

        // One provider account can send for several domains, and each endpoint then receives them all. A provider
        // with one webhook per domain sends no sender, and needs no filter.
        if ($from !== null) {
            // Thrown, not skipped: a 204 here would drop every event for good, and a 500 makes the provider retry.
            $own = EmailAddress::domainOf(EmailAddress::bare((string)config('mail.from.address')))
                ?? throw new LogicException("Set mail.from.address to an address on the provider's sending domain before routing the suppression webhook.");
            $domain = is_string($from) ? EmailAddress::domainOf(EmailAddress::bare($from)) : null;

            if ($domain === null || ! EmailAddress::coveredBy($domain, $own)) {
                Log::info('Mail provider event for another sending domain ignored.', ['event' => $event->getName(), 'from' => $from]);

                return;
            }
        }

        $to = Arr::get($event->getPayload(), 'data.to');

        /** @var AbstractMailerEvent $event reason() is null for anything else. */
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

        return match ($event->getName()) {
            // Permanent only: a full mailbox must not lock anyone out of a password reset.
            MailerDeliveryEvent::BOUNCE => is_string($bounce) && strcasecmp($bounce, 'permanent') === 0 ? SuppressionReason::bounced : null,
            // Resend's own block; `email.failed`, the other DROPPED, is a quota and blocks nothing.
            MailerDeliveryEvent::DROPPED => Arr::get($payload, 'type') === 'email.suppressed' ? SuppressionReason::bounced : null,
            default                      => null,
        };
    }
}
