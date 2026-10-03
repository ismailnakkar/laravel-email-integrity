<?php

declare(strict_types=1);

namespace EmailIntegrity\Tests;

use EmailIntegrity\Casts\CanonicalEmail;
use EmailIntegrity\EmailAddress;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

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
}
