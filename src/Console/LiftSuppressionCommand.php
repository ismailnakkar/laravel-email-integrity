<?php

declare(strict_types=1);

namespace EmailIntegrity\Console;

use EmailIntegrity\SuppressedAddress;
use Illuminate\Console\Command;

/** @internal */
final class LiftSuppressionCommand extends Command
{
    protected $signature = 'email-integrity:lift {address}';

    protected $description = 'Unblock an address the suppression list stops';

    public function handle(): int
    {
        /** @var string $address Required by the signature. */
        $address = $this->argument('address');

        // Read before the lift deletes it. Unknown (no row, or suppression off): warn, as the provider may still hold it.
        $heldByProvider = SuppressedAddress::blocking($address)?->reason->heldByProvider() ?? true;

        $this->components->info(sprintf('Lifted %d suppression row(s) for %s.', SuppressedAddress::lift($address), $address));

        if ($heldByProvider) {
            // Otherwise the next send comes back as the provider's own block and suppresses the address again.
            $this->components->warn("Also remove it from the mail provider's own suppression list.");
        }

        return self::SUCCESS;
    }
}
