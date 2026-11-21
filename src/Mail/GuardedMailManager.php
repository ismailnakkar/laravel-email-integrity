<?php

declare(strict_types=1);

namespace EmailIntegrity\Mail;

use Illuminate\Mail\MailManager;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * @internal Laravel's mail manager, with every SMTP transport it builds (a failover's included) behind a
 * RefusedRecipientGuard. Other transports stay as built, so a test's array transport still lists its messages.
 */
final class GuardedMailManager extends MailManager
{
    /** Keeps the custom transports an earlier extender added to the manager it replaces. */
    public static function replacing(MailManager $manager): self
    {
        $guarded = new self($manager->getApplication());
        $guarded->customCreators = $manager->customCreators;

        return $guarded;
    }

    /** @param  array<array-key, mixed>  $config */
    public function createSymfonyTransport(array $config): TransportInterface
    {
        $transport = parent::createSymfonyTransport($config);

        return $transport instanceof SmtpTransport ? new RefusedRecipientGuard($transport) : $transport;
    }
}
