<?php

declare(strict_types=1);

namespace EmailIntegrity\Console;

use EmailIntegrity\DisposableDomains;
use Illuminate\Console\Command;
use Throwable;

class UpdateDisposableDomainsCommand extends Command
{
    protected $signature = 'email-integrity:update';

    protected $description = 'Refresh the disposable email domain list from its configured sources';

    public function handle(DisposableDomains $domains): int
    {
        try {
            $count = $domains->update();
        } catch (Throwable $e) {
            // Non-zero so a scheduled run is visibly failing rather than quietly serving
            // a list that is getting older every day.
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf('Stored %s disposable domains.', number_format($count)));

        return self::SUCCESS;
    }
}
