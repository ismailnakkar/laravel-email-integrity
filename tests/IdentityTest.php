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
use InvalidArgumentException;

/**
 * Canonicalising is worth nothing until the value reaches the column the unique index sits
 * on. Two ways to get it there: canonicalise before validating, so the address you check is
 * the address you store, or keep the typed address and fill a second column with the cast.
 */
class IdentityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->increments('id');
            // Case-insensitive, as MySQL's default collation is: unclaimed_mailbox's byte compare tells "Q b" from "q b".
            $table->string('email')->collation('nocase')->unique();
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

    /**
     * The documented default: canonicalise the input the way prepareForValidation() does, and
     * core's own `unique` catches every other spelling of a registered mailbox. That is the
     * whole mechanism — no second column, and no rule from this package.
     */
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

    /** The same rule on the address as typed misses every alias — this is what it buys. */
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

    /** saveQuietly() fires no events, so a saving hook would leave the column null here. */
    public function test_a_quiet_save_still_writes_the_identity(): void
    {
        $user = $this->user();
        $user->email = 'a+1@gmail.com';
        $user->saveQuietly();

        $this->assertSame('a@gmail.com', DB::table('users')->value('email_canonical'));
    }

    /** A unique index accepts unlimited NULLs, so an unresolvable address must not store one. */
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

    /** Kept as typed, so core `unique` would let these aliases through to the index; unclaimed_mailbox refuses them. */
    public function test_unclaimed_mailbox_refuses_an_alias_of_a_held_mailbox(): void
    {
        $this->user()->fill(['email' => 'ab@gmail.com'])->save();

        $check = fn (string $typed): bool => Validator::make(['email' => $typed], ['email' => 'unclaimed_mailbox:users'])->passes();

        $this->assertFalse($check('A.B+x@gmail.com'));
        $this->assertFalse($check('AB@googlemail.com'));
        $this->assertTrue($check('someone-else@gmail.com'));
    }

    /** A row whose canonical column is NULL still holds its exact address, compared byte for byte. */
    public function test_unclaimed_mailbox_refuses_the_exact_address_of_a_null_canonical_row(): void
    {
        DB::table('users')->insert(['email' => '"q b"@example.com', 'email_canonical' => null]);

        foreach (['unclaimed_mailbox:users', new UnclaimedMailbox('users')] as $rule) {
            $validator = Validator::make(['email' => '"q b"@example.com'], ['email' => [$rule]]);

            $this->assertFalse($validator->passes());
            $this->assertSame('The email has already been taken.', $validator->errors()->first('email'));
            $this->assertTrue(Validator::make(['email' => '"Q b"@example.com'], ['email' => [$rule]])->passes());
            $this->assertTrue(Validator::make(['email' => ['x']], ['email' => [$rule]])->passes());
        }
    }

    /** As Rule::unique()->ignore(): an account being updated may keep or re-tag its own mailbox, not take another's. */
    public function test_unclaimed_mailbox_ignores_the_row_being_updated(): void
    {
        $user = $this->user()->fill(['email' => 'ab@gmail.com']);
        $user->save();
        $this->user()->fill(['email' => 'cd@gmail.com'])->save();
        $held = DB::table('users')->insertGetId(['email' => '"q b"@example.com', 'email_canonical' => null]);

        $passes = fn (string $typed, mixed $rule): bool => Validator::make(['email' => $typed], ['email' => [$rule]])->passes();

        $this->assertTrue($passes('a.b+new@gmail.com', (new UnclaimedMailbox('users'))->ignore($user->getKey())));
        $this->assertTrue($passes('a.b+new@gmail.com', (new UnclaimedMailbox('users'))->ignore($user)));
        $this->assertTrue($passes('a.b+new@gmail.com', "unclaimed_mailbox:users,email_canonical,email,{$user->getKey()}"));
        $this->assertTrue($passes('"q b"@example.com', (new UnclaimedMailbox('users'))->ignore($held)));

        // A model keyed by another column is skipped by that key, as Rule::unique()->ignore($model) does.
        $byEmail = (new class extends Model
        {
            protected $primaryKey = 'email';

            public $incrementing = false;
        })->forceFill(['email' => 'ab@gmail.com']);
        $this->assertTrue($passes('a.b+new@gmail.com', (new UnclaimedMailbox('users'))->ignore($byEmail)));
        $this->assertFalse($passes('cd+new@gmail.com', (new UnclaimedMailbox('users'))->ignore($user)));
        $this->assertFalse($passes('a.b+new@gmail.com', new UnclaimedMailbox('users')));
    }

    public function test_a_bare_unclaimed_mailbox_says_it_needs_a_table(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Validation rule unclaimed_mailbox requires at least 1 parameters.');

        Validator::make(['email' => 'a@example.com'], ['email' => 'unclaimed_mailbox'])->passes();
    }
}
