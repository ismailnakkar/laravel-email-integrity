<?php

declare(strict_types=1);

namespace EmailIntegrity\Tests;

use EmailIntegrity\EmailIntegrityServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [EmailIntegrityServiceProvider::class];
    }

    protected function defineDatabaseMigrations(): void
    {
        // The publish-only stub, run here so the suite exercises the schema new apps get.
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    /** @param list<string> $domains */
    protected function withDisposableList(array $domains): string
    {
        $path = sys_get_temp_dir() . '/email-integrity-' . getmypid() . '.json';
        file_put_contents($path, json_encode($domains));

        config(['email-integrity.disposable.storage' => $path]);
        config(['email-integrity.disposable.cache.enabled' => false]);

        return $path;
    }
}
