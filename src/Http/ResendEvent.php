<?php

declare(strict_types=1);

namespace EmailIntegrity\Http;

/** @internal The Resend event types the README subscribes the webhook to, all of which can suppress an address. */
enum ResendEvent: string
{
    case bounced = 'email.bounced';
    case complained = 'email.complained';
    case suppressed = 'email.suppressed';
}
