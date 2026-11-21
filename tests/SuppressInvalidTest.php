<?php

declare(strict_types=1);

namespace EmailIntegrity\Tests;

use EmailIntegrity\SuppressedAddress;
use EmailIntegrity\SuppressionReason;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SuppressInvalidTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('members', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->nullable();
        });
        DB::table('members')->insert(array_map(fn (?string $email): array => ['email' => $email], [
            'ok@example.com', 'nodot@localhost', 'space in@example.com', 'Already@Gone', 'ok2@example.org', null, '',
        ]));
        SuppressedAddress::suppress('already@gone', SuppressionReason::bounced);
    }

    public function test_a_dry_run_prints_the_counts_and_writes_nothing(): void
    {
        $this->artisan('email-integrity:suppress-invalid', ['table' => 'members', '--dry-run' => true])
            ->expectsOutputToContain('Dry run: 3 of 5 addresses in members.email fail email:strict,filter; 2 would be newly suppressed.')
            ->assertExitCode(0);

        $this->assertSame(['already@gone' => 'bounced'], $this->suppressed());
    }

    public function test_it_suppresses_each_address_the_rule_refuses_once(): void
    {
        $this->artisan('email-integrity:suppress-invalid', ['table' => 'members'])
            ->expectsOutputToContain('3 of 5 addresses in members.email fail email:strict,filter; 2 newly suppressed.')
            ->assertExitCode(0);

        $this->assertSame([
            'already@gone'         => 'bounced',
            'nodot@localhost'      => 'invalid',
            'space in@example.com' => 'invalid',
        ], $this->suppressed());

        $this->artisan('email-integrity:suppress-invalid', ['table' => 'members', 'column' => 'email'])
            ->expectsOutputToContain('3 of 5 addresses in members.email fail email:strict,filter; 0 newly suppressed.')
            ->assertExitCode(0);
    }

    public function test_it_judges_the_stored_form_so_a_variant_spelling_never_blocks_a_working_inbox(): void
    {
        config(['email-integrity.suppression.enabled' => true]);
        DB::table('members')->delete();
        DB::table('members')->insert(array_map(fn (string $email): array => ['email' => $email], [
            'bob@gmail.com', ' bob@gmail.com', 'carol@gmail.com.', 'user@münchen.de', 'jane@gmail',
        ]));

        $this->artisan('email-integrity:suppress-invalid', ['table' => 'members'])
            ->expectsOutputToContain('1 of 5 addresses in members.email fail email:strict,filter; 1 newly suppressed.')
            ->assertExitCode(0);

        $this->assertSame(['already@gone' => 'bounced', 'jane@gmail' => 'invalid'], $this->suppressed());
        $this->assertTrue(SuppressedAddress::mailable('bob@gmail.com'));
        $this->assertTrue(SuppressedAddress::mailable('carol@gmail.com'));
        $this->assertTrue(SuppressedAddress::mailable('user@münchen.de'));
    }

    /** @return array<string, string> */
    private function suppressed(): array
    {
        return DB::table('suppressed_addresses')->orderBy('email')->pluck('reason', 'email')->all();
    }
}
