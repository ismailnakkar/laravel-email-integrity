<?php

declare(strict_types=1);

namespace EmailIntegrity\Tests;

use EmailIntegrity\DisposableDomains;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class UpdateTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = $this->withDisposableList(['already-here.test']);
        config([
            'email-integrity.disposable.sources'     => ['https://list.test/domains.json'],
            'email-integrity.disposable.min_domains' => 2,
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);

        parent::tearDown();
    }

    private function domains(): DisposableDomains
    {
        return $this->app->make(DisposableDomains::class);
    }

    public function test_a_successful_fetch_replaces_the_list(): void
    {
        Http::fake(['list.test/*' => Http::response(['One.TEST', 'two.test', 'two.test'])]);

        $this->assertSame(2, $this->domains()->update());
        $this->assertSame(['one.test', 'two.test'], json_decode((string)file_get_contents($this->path), true));
    }

    /** A truncated source must never quietly replace a good list with a shorter one. */
    public function test_a_short_list_is_refused_and_the_previous_one_survives(): void
    {
        Http::fake(['list.test/*' => Http::response(['only-one.test'])]);

        $before = file_get_contents($this->path);

        $this->expectException(RuntimeException::class);

        try {
            $this->domains()->update();
        } finally {
            $this->assertSame($before, file_get_contents($this->path));
        }
    }

    public function test_a_failed_fetch_leaves_the_previous_list_in_place(): void
    {
        Http::fake(['list.test/*' => Http::response('', 500)]);

        $before = file_get_contents($this->path);

        try {
            $this->domains()->update();
            $this->fail('The update should not have succeeded.');
        } catch (Throwable) {
            $this->assertSame($before, file_get_contents($this->path));
        }
    }

    public function test_the_command_reports_failure_with_a_non_zero_exit(): void
    {
        Http::fake(['list.test/*' => Http::response('', 500)]);

        $this->artisan('email-integrity:update')->assertExitCode(1);
    }

    public function test_the_command_succeeds_on_a_good_fetch(): void
    {
        Http::fake(['list.test/*' => Http::response(['one.test', 'two.test'])]);

        $this->artisan('email-integrity:update')->assertExitCode(0);
    }
}
