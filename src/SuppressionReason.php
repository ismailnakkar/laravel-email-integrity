<?php

declare(strict_types=1);

namespace EmailIntegrity;

enum SuppressionReason: string
{
    case bounced = 'bounced';
    case complained = 'complained';
    /** Refused at send time: the SMTP server called the recipient invalid or unknown. */
    case invalid = 'invalid';

    /**
     * Whether the mail provider keeps its own block on the address, so a lift here alone does not unblock it: its
     * webhook reported the bounce or the complaint. A refusal at send time never reached its list.
     */
    public function heldByProvider(): bool
    {
        // No default: a new case has to pick a side here.
        return match ($this) {
            self::bounced, self::complained => true,
            self::invalid                   => false,
        };
    }

    /**
     * @internal Whether a row for this reason keys the inbox (its canonical() form) rather than the spelling: only the
     * inbox's owner can complain, while a bounce or a refusal may follow a stranger's typo.
     */
    public function keysInbox(): bool
    {
        // No default: a new case has to pick a side here.
        return match ($this) {
            self::complained             => true,
            self::bounced, self::invalid => false,
        };
    }
}
