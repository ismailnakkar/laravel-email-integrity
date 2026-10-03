<?php

declare(strict_types=1);

namespace EmailIntegrity\Tests\Fixtures;

use SensitiveParameter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcher\MethodRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;
use Symfony\Component\RemoteEvent\Event\Mailer\MailerEngagementEvent;
use Symfony\Component\Webhook\Client\AbstractRequestParser;
use Symfony\Component\Webhook\Exception\RejectWebhookException;

/** A provider shaped unlike Resend: a plain shared secret, one recipient, no sender in the payload. */
final class SingleRecipientParser extends AbstractRequestParser
{
    protected function getRequestMatcher(): RequestMatcherInterface
    {
        return new MethodRequestMatcher('POST');
    }

    protected function doParse(Request $request, #[SensitiveParameter] string $secret): MailerEngagementEvent
    {
        if (! hash_equals($secret, (string)$request->headers->get('X-Secret'))) {
            throw new RejectWebhookException(406, 'Bad secret.');
        }

        $event = new MailerEngagementEvent(MailerEngagementEvent::SPAM, 'event-1', $request->toArray());
        $event->setRecipientEmail((string)$request->toArray()['recipient']);

        return $event;
    }
}
