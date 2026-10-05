<?php

declare(strict_types=1);

namespace EmailIntegrity\Listeners;

use EmailIntegrity\EmailAddress;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Symfony\Component\Mime\Address;

/**
 * Skips a mail notification routed to any address Symfony Mime cannot build, which would otherwise throw mid-send.
 * Only the mail channel: the notification's other channels still go.
 *
 * @internal
 */
final class SkipUnsendableMail
{
    // Null, not true, when it sends: any non-null answer stops the app's own NotificationSending listeners.
    public function handle(NotificationSending $event): ?bool
    {
        // via() may name the channel by its class, which the ChannelManager resolves as well.
        if ($event->channel !== 'mail' && $event->channel !== MailChannel::class) {
            return null;
        }

        $route = $event->notifiable->routeNotificationFor('mail', $event->notification);

        // Read as MailChannel reads it: one address, a list of addresses or notifiables, or an address => name map.
        foreach (new Collection(is_string($route) ? [$route] : $route) as $key => $recipient) {
            $address = is_string($key) ? $key : (is_string($recipient) ? $recipient : $recipient->email ?? null);

            if (! (is_string($key) ? EmailAddress::sendable($key) : self::parses($address))) {
                Log::info('Mail not sent: the address cannot receive mail.', [
                    'email'        => $address,
                    'notification' => $event->notification::class,
                ]);

                return false;
            }
        }

        return null;
    }

    /**
     * A map key becomes `new Address($key)`, which sendable() mirrors. A list entry reaches Symfony as the string itself,
     * read by Address::create(), so `Name <address>` sends there; Laravel still refuses a line break in it.
     */
    private static function parses(mixed $address): bool
    {
        if (! is_string($address) || strpbrk($address, "\r\n") !== false) {
            return false;
        }

        try {
            Address::create($address);
        } catch (InvalidArgumentException) {
            return false;
        }

        return true;
    }
}
