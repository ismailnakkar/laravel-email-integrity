<?php

declare(strict_types=1);

namespace EmailIntegrity\Console;

use EmailIntegrity\SuppressedAddress;
use EmailIntegrity\SuppressionReason;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** @internal */
final class SuppressInvalidCommand extends Command
{
    private const CHUNK = 1000;

    protected $signature = 'email-integrity:suppress-invalid
        {table : The table holding the addresses}
        {column=email : The address column}
        {--dry-run : Print the counts and write nothing}';

    protected $description = 'Suppress every stored address that fails ' . SuppressedAddress::PROVIDER_FORMAT;

    public function handle(): int
    {
        /** @var string $table Required by the signature. */
        $table = $this->argument('table');
        /** @var string $column Defaulted by the signature. */
        $column = $this->argument('column');
        $dryRun = (bool)$this->option('dry-run');
        $checked = $invalid = $new = 0;

        // Paged by the column itself, so the table needs no `id`: a repeated address is one row anyway.
        $rows = DB::table($table)->select($column)->whereNotNull($column)->where($column, '<>', '')->lazyById(self::CHUNK, $column);

        foreach ($rows as $row) {
            $address = (string)$row->{$column};
            $checked++;

            if (! SuppressedAddress::malformed($address)) {
                continue;
            }

            $invalid++;
            $new += (int)($dryRun ? self::unsuppressed($address) : SuppressedAddress::suppress($address, SuppressionReason::invalid));
        }

        $this->components->info(sprintf(
            $dryRun ? 'Dry run: %s of %s addresses in %s fail %s; %s would be newly suppressed.' : '%s of %s addresses in %s fail %s; %s newly suppressed.',
            number_format($invalid),
            number_format($checked),
            "{$table}.{$column}",
            SuppressedAddress::PROVIDER_FORMAT,
            number_format($new),
        ));

        return self::SUCCESS;
    }

    /** Whether suppress() would insert a row: the address has a key no row holds yet. */
    private static function unsuppressed(string $address): bool
    {
        $key = SuppressedAddress::key($address, SuppressionReason::invalid);

        return $key !== null && ! SuppressedAddress::query()->where('email', $key)->exists();
    }
}
