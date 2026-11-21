<?php

declare(strict_types=1);

namespace EmailIntegrity\Mail;

use EmailIntegrity\SuppressedAddress;
use EmailIntegrity\SuppressionReason;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * Wraps an SMTP transport. When the server refuses a recipient as invalid for good, the address is suppressed and the
 * send ends without an error, so a queued mail neither retries nor fails. Inert while suppression is off.
 */
final class RefusedRecipientGuard implements TransportInterface
{
    /**
     * Symfony's wording of an unexpected reply, then a permanent reply about a bad destination address: an RFC 3463
     * status (5.1.1 no such mailbox, 5.1.2 no such domain, 5.1.3 bad syntax, 5.1.6 moved, 5.1.10 null MX) or Resend's
     * "Invalid recipient address". Not 5.1.7 or 5.1.8, which are about the sender.
     */
    private const INVALID_RECIPIENT = '/with message "5\d\d[ -](?:5\.1\.(?:1|2|3|6|10)\b|Invalid recipient\b)/i';

    public function __construct(public readonly TransportInterface $transport) {}

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        try {
            return $this->transport->send($message, $envelope);
        } catch (UnexpectedResponseException $e) {
            $refused = config('email-integrity.suppression.enabled')
                ? self::refused($e, ($envelope ?? Envelope::create($message))->getRecipients())
                : null;

            if ($refused === null) {
                throw $e;
            }

            SuppressedAddress::suppress($refused, SuppressionReason::invalid);
            Log::warning('Mail not sent: the server refused the address.', [
                'email'   => $refused,
                'reply'   => $e->getMessage(),
                'subject' => $message instanceof Email ? $message->getSubject() : null,
            ]);

            return null;
        }
    }

    public function __toString(): string
    {
        return (string)$this->transport;
    }

    /**
     * The one recipient the refusal is about: the only one, or the only one the reply names. Null when it may be about
     * the sender, the message or another recipient.
     *
     * @param  array<Address>  $recipients
     */
    private static function refused(UnexpectedResponseException $e, array $recipients): ?string
    {
        $reply = $e->getMessage();

        if (preg_match(self::INVALID_RECIPIENT, $reply) !== 1) {
            return null;
        }

        $addresses = array_map(static fn (Address $recipient): string => $recipient->getAddress(), $recipients);
        $named = count($addresses) === 1
            ? $addresses
            : array_values(array_filter($addresses, static fn (string $address): bool => stripos($reply, $address) !== false));

        return count($named) === 1 ? $named[0] : null;
    }
}
