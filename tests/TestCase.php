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
        // Publish-only in apps; loaded here so the suite runs the shipped schema.
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    /** @param list<string> $domains */
    protected function withDisposableList(array $domains): string
    {
        $path = sys_get_temp_dir() . '/email-integrity-' . getmypid() . '.json';
        file_put_contents($path, json_encode($domains));
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        config(['email-integrity.disposable.storage' => $path]);

        return $path;
    }
}
