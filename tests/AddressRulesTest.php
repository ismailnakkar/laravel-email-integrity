<?php

declare(strict_types=1);

namespace EmailIntegrity\Tests;

use EmailIntegrity\Rules\AddressRules;
use EmailIntegrity\SuppressedAddress;
use EmailIntegrity\SuppressionReason;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\TestWith;

class AddressRulesTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('email-integrity.suppression.enabled', true);
        $app['config']->set('email-integrity.host.enabled', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('email')->unique();
            $table->string('email_canonical')->nullable()->unique();
        });
        $this->withDisposableList(['mailinator.com']);
    }

    /**
     * @param  list<mixed>  $rules
     * @return list<string>
     */
    private function errors(string $email, array $rules): array
    {
        return Validator::make(['email' => $email], ['email' => $rules])->errors()->get('email');
    }

    public function test_each_rule_refuses_its_address_and_bail_stops_at_the_first(): void
    {
        DB::table('users')->insert(['email' => 'ab@gmail.com', 'email_canonical' => 'ab@gmail.com']);
        DB::table('users')->insert(['email' => 'x@mailinator.com', 'email_canonical' => 'x@mailinator.com']);
        SuppressedAddress::suppress('dead@example.com', SuppressionReason::bounced);
        $rules = AddressRules::for('users');

        $this->assertSame([], $this->errors('someone@example.com', $rules));
        $this->assertSame(['The email field must be a valid email address.'], $this->errors('a@[192.0.2.1]', $rules));
        $this->assertSame(['The email field must be a valid email address.'], $this->errors('jäck@gmail.com', $rules));
        $this->assertSame(['The email has already been taken.'], $this->errors('A.B+x@gmail.com', $rules));
        $this->assertSame(['The email has already been taken.'], $this->errors('x@mailinator.com', $rules));
        $this->assertSame([trans('email-integrity::messages.suppressed')], $this->errors('dead@example.com', $rules));
        $this->assertSame([trans('email-integrity::messages.disposable')], $this->errors('a@mailinator.com', $rules));

        config(['email-integrity.host.enabled' => true, 'email-integrity.host.fail_open' => false]);

        // RFC 2606 reserves .invalid: it never resolves.
        $this->assertSame([trans('email-integrity::messages.unroutable')], $this->errors('a@definitely-not-real.invalid', $rules));
    }

    /** A form never takes an address the members' notice then calls unreachable, with `filter` or without. */
    #[TestWith(['jäck@gmail.com', false])]
    #[TestWith(['ünï@exämple.com', false])]
    #[TestWith(['a@192.0.2.1', false])]
    #[TestWith(['a@x.123', false])]
    #[TestWith(['jane@gmail', false])]
    #[TestWith(['"a b"@example.com', false])]
    #[TestWith(['bob@münchen.de', true])]
    #[TestWith(["o'brien@example.com", true])]
    #[TestWith(['someone@example.com', true])]
    public function test_it_takes_an_address_only_where_unreachable_does_not_count_it(string $address, bool $taken): void
    {
        $this->assertSame($taken, $this->errors($address, AddressRules::for('users')) === []);
        $this->assertSame($taken, ! SuppressedAddress::unreachable($address));

        if (! $taken) {
            $this->assertNotSame([], $this->errors($address, AddressRules::for('users', filter: true)));
        }
    }

    public function test_the_row_being_updated_keeps_its_own_mailbox(): void
    {
        $id = DB::table('users')->insertGetId(['email' => 'ab@gmail.com', 'email_canonical' => 'ab@gmail.com']);

        $this->assertSame([], $this->errors('a.b+new@gmail.com', AddressRules::for('users', $id)));
        $this->assertNotSame([], $this->errors('a.b+new@gmail.com', AddressRules::for('users')));
    }

    public function test_filter_is_the_apps_choice(): void
    {
        $this->assertSame([], $this->errors('bob@münchen.de', AddressRules::for('users')));
        $this->assertNotSame([], $this->errors('bob@münchen.de', AddressRules::for('users', filter: true)));
    }
}
