<?php

declare(strict_types=1);

namespace EmailIntegrity\Tests;

use EmailIntegrity\EmailAddress;

/**
 * Aliasing is how one person becomes many accounts without a second inbox. Measured on
 * one provider showed five separately-paid accounts on one Gmail identity, told apart only by where the
 * dot sat in the local part.
 */
class CanonicalTest extends TestCase
{
    private function canonical(mixed $email): ?string
    {
        return EmailAddress::canonical($email);
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

    /**
     * Since 2010 `straße.de` and `strasse.de` are two registrable domains. Transitional IDNA folds ß to ss and
     * hands one the other's identity; Symfony Mime puts the nontransitional form on the wire.
     */
    public function test_a_nontransitional_domain_keeps_its_own_identity(): void
    {
        if (! function_exists('idn_to_ascii')) {
            $this->markTestSkipped('ext-intl is absent, so an IDN stays as typed.');
        }

        $this->assertSame('x@xn--strae-oqa.de', $this->canonical("x@stra\u{DF}e.de"));
        $this->assertNotSame($this->canonical('x@strasse.de'), $this->canonical("x@stra\u{DF}e.de"));
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

    /** Providers echo the header, so a recipient or sender arrives as `Name <address>`. */
    public function test_bare_reads_the_address_inside_the_last_angle_brackets(): void
    {
        $this->assertSame('Jane.Doe@Example.com', EmailAddress::bare(' "Doe, <Jane>" < Jane.Doe@Example.com > '));
        $this->assertSame('jane@example.com', EmailAddress::bare(' jane@example.com '));
    }

    /** The mailbox as it was addressed: only case and the domain's spelling are folded. */
    public function test_literal_keeps_the_local_part_and_folds_case_and_the_domain(): void
    {
        $this->assertSame('jane.doe+news@gmail.com', EmailAddress::literal('Jane <Jane.Doe+News@GMAIL.com.>'));

        if (function_exists('idn_to_ascii')) {
            $this->assertSame('x@xn--strae-oqa.de', EmailAddress::literal("x@stra\u{DF}e.de"));
        }

        $this->assertNull(EmailAddress::literal('undisclosed recipients'));
        $this->assertNull(EmailAddress::literal('@example.com'));
        $this->assertNull(EmailAddress::literal('a@[192.0.2.1]'));
    }

    /** A quoted local part may hold `<…>`; reading it as the header form would key a stranger's bounce on the victim. */
    public function test_literal_does_not_read_angle_brackets_inside_a_quoted_local_part(): void
    {
        $this->assertSame('"x<victim@corp.example>"@attacker.example', EmailAddress::literal('"x<victim@corp.example>"@attacker.example'));
    }
}
