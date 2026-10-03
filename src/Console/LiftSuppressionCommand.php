<?php

declare(strict_types=1);

namespace EmailIntegrity\Console;

use EmailIntegrity\SuppressedAddress;
use Illuminate\Console\Command;

/** @internal */
class LiftSuppressionCommand extends Command
{
    protected $signature = 'email-integrity:lift {address}';

    protected $description = 'Unblock an address the suppression list stops';

    public function handle(): int
    {
        $address = (string)$this->argument('address');

        $this->components->info(sprintf('Lifted %d suppression row(s) for %s.', SuppressedAddress::lift($address), $address));
        // Otherwise the next send comes back as the provider's own block and suppresses the address again.
        $this->components->warn("Also remove it from the mail provider's own suppression list.");

        return self::SUCCESS;
    }
}
