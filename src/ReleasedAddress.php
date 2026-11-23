<?php

declare(strict_types=1);

namespace EmailIntegrity;

/** What SuppressedAddress::release() lifted. */
final readonly class ReleasedAddress
{
    public function __construct(
        /** The rows deleted: 0 when nothing here blocked the address. */
        public int $rows,
        /**
         * Whether to remove the address from the mail provider's own list too, or its next send comes back as the
         * provider's block and suppresses it again: a lifted row was a bounce or a complaint its webhook reported
         * (SuppressionReason::heldByProvider()), or nothing was lifted. False when every lifted row was `invalid`.
         */
        public bool $heldByProvider,
    ) {}
}
