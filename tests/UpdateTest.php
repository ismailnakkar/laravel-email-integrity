<?php

declare(strict_types=1);

namespace EmailIntegrity\Tests;

use EmailIntegrity\DisposableDomains;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use RuntimeException;

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

        $this->expectException(RequestException::class);

        try {
            $this->domains()->update();
        } finally {
            $this->assertSame($before, file_get_contents($this->path));
        }
    }

    /** Checked per source: a truncated second list would otherwise shrink the merged one without failing. */
    public function test_a_short_source_is_refused_even_when_the_total_clears_the_floor(): void
    {
        config(['email-integrity.disposable.sources' => ['https://list.test/a.json', 'https://list.test/b.json']]);
        Http::fake([
            'list.test/a.json' => Http::response(['one.test', 'two.test']),
            'list.test/b.json' => Http::response(['three.test']),
        ]);

        $before = file_get_contents($this->path);

        $this->expectException(RuntimeException::class);

        try {
            $this->domains()->update();
        } finally {
            $this->assertSame($before, file_get_contents($this->path));
        }
    }

    public function test_a_failed_download_exits_non_zero_and_is_reported(): void
    {
        Exceptions::fake();
        Http::fake(['list.test/*' => Http::response('', 500)]);

        $this->artisan('email-integrity:update')->assertExitCode(1);

        Exceptions::assertReported(RequestException::class);
    }

    public function test_the_command_succeeds_on_a_good_fetch(): void
    {
        Http::fake(['list.test/*' => Http::response(['one.test', 'two.test'])]);

        $this->artisan('email-integrity:update')->assertExitCode(0);
    }
}
