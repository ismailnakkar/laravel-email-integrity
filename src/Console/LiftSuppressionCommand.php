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

        $released = SuppressedAddress::release($address);

        $this->components->info(sprintf('Lifted %d suppression row(s) for %s.', $released->rows, $address));

        if ($released->heldByProvider) {
            $this->components->warn("Also remove it from the mail provider's own suppression list.");
        }

        return self::SUCCESS;
    }
}
