<?php

declare(strict_types=1);

namespace EmailIntegrity\Tests;

use EmailIntegrity\Casts\CanonicalEmail;
use EmailIntegrity\EmailAddress;
use EmailIntegrity\Rules\UnclaimedMailbox;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class IdentityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('email')->unique();
            $table->string('email_canonical')->nullable()->unique();
        });
    }

    private function user(string $cast = CanonicalEmail::class): Model
    {
        return (new class extends Model
        {
            protected $table = 'users';

            protected $guarded = [];

            public $timestamps = false;
        })->mergeCasts(['email' => $cast]);
    }

    public function test_canonicalising_before_validation_makes_core_unique_catch_aliases(): void
    {
        DB::table('users')->insert(['email' => 'ab@gmail.com']);

        $check = fn (string $typed): bool => Validator::make(
            ['email' => EmailAddress::canonical($typed) ?? $typed],
            ['email' => 'unique:users,email'],
        )->passes();

        $this->assertFalse($check('a.b+promo@gmail.com'));
        $this->assertFalse($check('AB@googlemail.com'));
        $this->assertFalse($check('ab@gmail.com'));
        $this->assertTrue($check('someone-else@gmail.com'));
    }

    public function test_the_raw_address_slips_past_core_unique(): void
    {
        DB::table('users')->insert(['email' => 'ab@gmail.com']);

        $this->assertTrue(Validator::make(
            ['email' => 'a.b+promo@gmail.com'],
            ['email' => 'unique:users,email'],
        )->passes());
    }

    public function test_the_cast_fills_the_identity_column_and_leaves_the_address_as_typed(): void
    {
        $user = $this->user();
        $user->email = 'A.B+promo@GoogleMail.com';

        $this->assertSame('A.B+promo@GoogleMail.com', $user->email);
        $this->assertSame('ab@gmail.com', $user->getAttributes()['email_canonical']);
    }

    public function test_a_quiet_save_still_writes_the_identity(): void
    {
        $user = $this->user();
        $user->email = 'a+1@gmail.com';
        $user->saveQuietly();

        $this->assertSame('a@gmail.com', DB::table('users')->value('email_canonical'));
    }

    public function test_an_address_with_no_canonical_form_is_stored_as_typed(): void
    {
        $user = $this->user();
        $user->email = '"a+b"@example.com';

        $this->assertSame('"a+b"@example.com', $user->getAttributes()['email_canonical']);
    }

    public function test_the_identity_column_can_be_renamed(): void
    {
        $user = $this->user(CanonicalEmail::class . ':identity');
        $user->email = 'a+1@gmail.com';

        $this->assertSame('a@gmail.com', $user->getAttributes()['identity']);
    }

    public function test_unclaimed_mailbox_refuses_an_alias_of_a_held_mailbox(): void
    {
        $this->user()->fill(['email' => 'ab@gmail.com'])->save();
        $this->user()->fill(['email' => '"q b"@example.com'])->save();

        foreach (['unclaimed_mailbox:users', new UnclaimedMailbox('users')] as $rule) {
            $check = fn (mixed $typed): bool => Validator::make(['email' => $typed], ['email' => [$rule]])->passes();
            $validator = Validator::make(['email' => 'A.B+x@gmail.com'], ['email' => [$rule]]);

            $this->assertFalse($validator->passes());
            $this->assertSame('The email has already been taken.', $validator->errors()->first('email'));
            $this->assertFalse($check('AB@googlemail.com'));
            $this->assertFalse($check('"q b"@example.com'));
            $this->assertTrue($check('someone-else@gmail.com'));
            $this->assertTrue($check(['x']));
        }
    }

    public function test_unclaimed_mailbox_ignores_the_row_being_updated(): void
    {
        $user = $this->user()->fill(['email' => 'ab@gmail.com']);
        $user->save();
        $this->user()->fill(['email' => 'cd@gmail.com'])->save();

        $passes = fn (string $typed, mixed $rule): bool => Validator::make(['email' => $typed], ['email' => [$rule]])->passes();

        $this->assertTrue($passes('a.b+new@gmail.com', (new UnclaimedMailbox('users'))->ignore($user->getKey())));
        $this->assertTrue($passes('a.b+new@gmail.com', (new UnclaimedMailbox('users'))->ignore($user)));
        $this->assertTrue($passes('a.b+new@gmail.com', "unclaimed_mailbox:users,email_canonical,{$user->getKey()}"));

        // A model keyed by another column is skipped by that key.
        $byEmail = (new class extends Model
        {
            protected $primaryKey = 'email';

            public $incrementing = false;
        })->forceFill(['email' => 'ab@gmail.com']);
        $this->assertTrue($passes('a.b+new@gmail.com', (new UnclaimedMailbox('users'))->ignore($byEmail)));
        $this->assertFalse($passes('cd+new@gmail.com', (new UnclaimedMailbox('users'))->ignore($user)));
        $this->assertFalse($passes('a.b+new@gmail.com', new UnclaimedMailbox('users')));
    }
}
