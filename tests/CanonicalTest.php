<?php

declare(strict_types=1);

namespace EmailIntegrity\Tests;

use EmailIntegrity\EmailIntegrity;

/**
 * Aliasing is how one person becomes many accounts without a second inbox. Measured on
 * one provider showed five separately-paid accounts on one Gmail identity, told apart only by where the
 * dot sat in the local part.
 */
class CanonicalTest extends TestCase
{
    private function canonical(mixed $email): ?string
    {
        return $this->app->make(EmailIntegrity::class)->canonical($email);
    }

    public function test_a_plus_tag_is_stripped(): void
    {
        $this->assertSame('a@example.com', $this->canonical('a+1@example.com'));
        $this->assertSame('a@example.com', $this->canonical('a+anything+else@example.com'));
    }

    public function test_gmail_dots_collapse_to_one_identity(): void
    {
        $spellings = [
            'Janedoe1209@gmail.com',
            'J.anedoe1209@gmail.com',
            'Ja.nedoe1209@gmail.com',
            'Jane.doe1209@gmail.com',
        ];

        $canonical = array_unique(array_map(fn (string $e): ?string => $this->canonical($e), $spellings));

        $this->assertSame(['janedoe1209@gmail.com'], array_values($canonical));
    }

    /** One MX, one mailbox — so it has to reduce to the gmail.com spelling, not its own. */
    public function test_googlemail_is_the_same_mailbox_as_gmail(): void
    {
        $this->assertSame('ab@gmail.com', $this->canonical('a.b+tag@googlemail.com'));
        $this->assertSame($this->canonical('ab@gmail.com'), $this->canonical('AB@googlemail.com.'));
    }

    /** Two spellings of one DNS name, and only the punycode one is ever on a list. */
    public function test_a_unicode_domain_and_its_punycode_are_one_identity(): void
    {
        if (! function_exists('idn_to_ascii')) {
            $this->markTestSkipped('ext-intl is absent, so an IDN stays as typed.');
        }

        $this->assertSame('a@xn--5nx.cc', $this->canonical("a@\u{7075}.cc"));
        $this->assertSame($this->canonical('a@xn--5nx.cc'), $this->canonical("a@\u{7075}.cc"));
    }

    /** Elsewhere a dot is part of the name; collapsing it would merge two real people. */
    public function test_dots_are_kept_outside_the_providers_that_ignore_them(): void
    {
        $this->assertSame('a.b@example.com', $this->canonical('a.b@example.com'));
        $this->assertNotSame($this->canonical('ab@example.com'), $this->canonical('a.b@example.com'));
    }

    public function test_case_and_a_trailing_dot_do_not_make_a_second_identity(): void
    {
        $this->assertSame(
            $this->canonical('Someone@Example.COM'),
            $this->canonical('someone@example.com.'),
        );
    }

    /** A quoted local part is literal, so trimming it would name a different mailbox. */
    public function test_a_quoted_local_part_has_no_canonical_form(): void
    {
        $this->assertNull($this->canonical('"a+b"@example.com'));
    }

    public function test_an_unusable_address_has_no_canonical_form(): void
    {
        $this->assertNull($this->canonical('no-at-sign'));
        $this->assertNull($this->canonical('trailing@'));
        $this->assertNull($this->canonical('@example.com'));
        $this->assertNull($this->canonical('+only@example.com'));
        $this->assertNull($this->canonical(null));
    }

    /**
     * U+212A KELVIN SIGN lowercases to an ASCII `k` under mb_strtolower, and `email:strict`
     * accepts it. Folding it would let a stranger's address canonicalise onto jack@gmail.com
     * and take the unique-index slot, locking the real owner out of registering at all.
     */
    public function test_a_unicode_homograph_does_not_collide_onto_an_ascii_identity(): void
    {
        $this->assertSame('jack@gmail.com', $this->canonical('JACK@Gmail.COM'));
        $this->assertNotSame($this->canonical('jack@gmail.com'), $this->canonical("jac\u{212A}@gmail.com"));
    }
}
