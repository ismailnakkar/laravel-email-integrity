<?php

declare(strict_types=1);

namespace EmailIntegrity\Listeners;

use EmailIntegrity\SuppressedAddress;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Address;

/**
 * Drops a whole message when any To, Cc or Bcc address is suppressed. At send time, so a row added after the mail
 * was queued still stops it.
 *
 * @internal
 */
final class DropSuppressedMail
{
    // Null, not true, when it sends: any non-null answer stops the app's own MessageSending listeners.
    public function handle(MessageSending $event): ?bool
    {
        $message = $event->message;
        $row = SuppressedAddress::blocking(...array_map(
            static fn (Address $address): string => $address->getAddress(),
            [...$message->getTo(), ...$message->getCc(), ...$message->getBcc()],
        ));

        if ($row === null) {
            return null;
        }

        Log::warning('Mail not sent: the address is suppressed.', [
            'email'   => $row->email,
            'reason'  => $row->reason->value,
            'subject' => $message->getSubject(),
        ]);

        return false;
    }
}
