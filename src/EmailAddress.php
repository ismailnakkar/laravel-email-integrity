<?php

declare(strict_types=1);

namespace EmailIntegrity;

use InvalidArgumentException;
use Symfony\Component\Mime\Address;

/**
 * Domain extraction, kept in one place because getting it wrong is silent.
 *
 * Splitting on the FIRST `@` hands you the wrong domain for a quoted local part
 * (`"a@b"@example.com`), which is how a blocklist gets bypassed by a legal address.
 */
final class EmailAddress
{
    private function __construct() {}

    /** Null when there is no usable domain — the `email` rule owns that complaint, not this one. */
    public static function domainOf(mixed $email): ?string
    {
        if (! is_string($email)) {
            return null;
        }

        $email = mb_strtolower(trim($email));
        $at = mb_strrpos($email, '@');

        if ($at === false || $at === mb_strlen($email) - 1) {
            return null;
        }

        $domain = mb_substr($email, $at + 1);

        // An address literal ([192.0.2.1], [IPv6:...]) has no domain to match a list against,
        // and a NUL byte is not a hostname either: dns_get_record() and checkdnsrr() throw a
        // ValueError on one, which `@` does not suppress, so it would surface as a 500.
        if (str_starts_with($domain, '[') || str_contains($domain, "\0")) {
            return null;
        }

        // A trailing dot is the same domain, fully qualified. Normalise so the
        // list lookup and the DNS query agree.
        $domain = rtrim($domain, '.');

        if ($domain === '') {
            return null;
        }

        return self::ascii($domain);
    }

    /**
     * Punycode: `灵.cc` and `xn--5nx.cc` are one DNS name and the lists publish only the
     * second, so converting at the DNS call was too late — the list lookup had already
     * missed. A no-op on ASCII. Falls back to the typed spelling on a malformed domain
     * and without ext-intl, which is the pre-existing miss rather than a new one.
     */
    private static function ascii(string $domain): string
    {
        if (! function_exists('idn_to_ascii')) {
            return $domain;
        }

        // Nontransitional, as Symfony Mime sends it: transitional folds `straße.de` onto `strasse.de`, another domain.
        $ascii = @idn_to_ascii($domain, IDNA_DEFAULT | IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);

        return is_string($ascii) && $ascii !== '' ? $ascii : $domain;
    }

    /**
     * The one inbox an address reaches: `+` tags stripped, Gmail's dots dropped (README, "One mailbox, one account").
     * Null without a domain or for a quoted local part, whose characters are literal.
     */
    public static function canonical(mixed $email): ?string
    {
        $domain = self::domainOf($email);

        if ($domain === null) {
            return null;
        }

        $local = self::local((string)$email);

        if ($local === '' || str_starts_with($local, '"')) {
            return null;
        }

        if (($tag = mb_strpos($local, '+')) !== false) {
            $local = mb_substr($local, 0, $tag);
        }

        // googlemail.com IS gmail.com: one MX, one mailbox. Folded here and not in
        // domainOf, because that answers "which DNS name" — where the two are separate
        // names an allow/deny entry can address — while this answers "which inbox".
        if ($domain === 'googlemail.com') {
            $domain = 'gmail.com';
        }

        if ($domain === 'gmail.com') {
            $local = str_replace('.', '', $local);
        }

        return $local === '' ? null : $local . '@' . $domain;
    }

    /**
     * The text inside a closing `<…>`, trimmed: providers echo the header, `Name <address>`. Only a closing one, since
     * a bare address never ends in `>` but its quoted local part may hold `<victim@…>`.
     */
    public static function bare(string $address): string
    {
        return trim(preg_match('/<([^<>]+)>\s*$/', $address, $match) === 1 ? $match[1] : $address);
    }

    /**
     * The mailbox exactly as it was addressed: bare, lowercased, the domain in punycode. Unlike canonical() it folds
     * no tag and no dot, so it names this one spelling. Null without a local part or a usable domain.
     */
    public static function literal(string $address): ?string
    {
        $address = self::bare($address);
        $domain = self::domainOf($address);
        $local = self::local($address);

        return $domain === null || $local === '' ? null : $local . '@' . $domain;
    }

    /** strtolower, not mb_: mb folds U+212A KELVIN SIGN onto `k`, handing a stranger's jac\u{212A}@ the owner's jack@. */
    private static function local(string $address): string
    {
        $address = trim($address);

        return strtolower(mb_substr($address, 0, (int)mb_strrpos($address, '@')));
    }

    /**
     * True when a message can be addressed to it: Symfony Mime's own Address, which the send would otherwise throw on
     * (an imported row may lack its `@`). A bare address, not `Name <…>`. Laravel refuses a line break, which Symfony
     * would trim off the end.
     */
    public static function sendable(mixed $email): bool
    {
        if (! is_string($email) || strpbrk($email, "\r\n") !== false) {
            return false;
        }

        try {
            new Address($email);
        } catch (InvalidArgumentException) {
            return false;
        }

        return true;
    }

    /** True when `$domain` is the entry itself or a subdomain of it. */
    public static function coveredBy(string $domain, string $entry): bool
    {
        // Same punycode pass as domainOf, or an entry typed as `灵.cc` would never match
        // the `xn--5nx.cc` the lookup now asks about.
        $entry = self::ascii(rtrim(mb_strtolower(trim($entry)), '.'));

        return $entry !== '' && ($domain === $entry || str_ends_with($domain, '.' . $entry));
    }

    /**
     * @param  iterable<mixed>  $entries
     */
    public static function listedIn(string $domain, iterable $entries): bool
    {
        foreach ($entries as $entry) {
            if (is_string($entry) && self::coveredBy($domain, $entry)) {
                return true;
            }
        }

        return false;
    }
}
