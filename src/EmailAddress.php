<?php

declare(strict_types=1);

namespace EmailIntegrity;

use InvalidArgumentException;
use Symfony\Component\Mime\Address;

final class EmailAddress
{
    private function __construct() {}

    /** Lowercase, punycode, no trailing dot. Null without a usable domain, which the `email` rule reports. */
    public static function domainOf(mixed $email): ?string
    {
        if (! is_string($email)) {
            return null;
        }

        $email = mb_strtolower(trim($email));
        // The last `@`: a quoted local part may hold one (`"a@b"@example.com`), and the first would bypass the lists.
        $at = mb_strrpos($email, '@');

        if ($at === false) {
            return null;
        }

        $domain = mb_substr($email, $at + 1);

        // An address literal has no domain; a NUL byte makes the DNS functions throw a ValueError `@` cannot hide.
        if (str_starts_with($domain, '[') || str_contains($domain, "\0")) {
            return null;
        }

        // A trailing dot names the same domain.
        $domain = rtrim($domain, '.');

        return $domain === '' ? null : self::ascii($domain);
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

        // One inbox under two domains. Not folded in domainOf(): an allow or deny entry may name either.
        if ($domain === 'googlemail.com') {
            $domain = 'gmail.com';
        }

        if ($domain === 'gmail.com') {
            $local = str_replace('.', '', $local);
        }

        return $local === '' ? null : $local . '@' . $domain;
    }

    /**
     * The address inside a trailing `<…>` (providers send `Name <address>`), trimmed. Trailing only: a quoted local
     * part may hold `<victim@…>`.
     *
     * @internal
     */
    public static function bare(string $address): string
    {
        return trim(preg_match('/<([^<>]+)>\s*$/', $address, $match) === 1 ? $match[1] : $address);
    }

    /**
     * Bare, lowercased, punycode domain, nothing folded. Null without a local part or domain.
     *
     * @internal
     */
    public static function literal(string $address): ?string
    {
        $address = self::bare($address);
        $domain = self::domainOf($address);
        $local = self::local($address);

        return $domain === null || $local === '' ? null : $local . '@' . $domain;
    }

    /**
     * True when Symfony Mime's Address accepts it, so a send would not throw. A bare address, not `Name <…>`. A line
     * break fails: Laravel refuses one that Symfony would trim.
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

    /**
     * True when any string entry is `$domain` or a parent of it. Both sides are normalised as domainOf() does, so
     * `Mail.Example.com.` is covered by `example.com` and an entry typed `灵.cc` matches `xn--5nx.cc`.
     *
     * @param  iterable<mixed>  $entries
     */
    public static function listedIn(string $domain, iterable $entries): bool
    {
        $domain = self::normalised($domain);

        foreach ($entries as $entry) {
            if (is_string($entry) && self::coveredBy($domain, self::normalised($entry))) {
                return true;
            }
        }

        return false;
    }

    /** True when `$domain` is the entry itself or a subdomain of it, both normalised. An empty entry covers nothing. */
    private static function coveredBy(string $domain, string $entry): bool
    {
        return $entry !== '' && ($domain === $entry || str_ends_with($domain, '.' . $entry));
    }

    /** Trimmed, lowercased, no trailing dot, punycode. */
    private static function normalised(string $domain): string
    {
        return self::ascii(rtrim(mb_strtolower(trim($domain)), '.'));
    }

    /** Punycode, as the lists publish it (`灵.cc` is `xn--5nx.cc`). As typed without ext-intl, when empty or malformed. */
    private static function ascii(string $domain): string
    {
        // idn_to_ascii('') throws a ValueError `@` cannot hide.
        if ($domain === '' || ! function_exists('idn_to_ascii')) {
            return $domain;
        }

        // Nontransitional, as Symfony Mime sends it: transitional folds `straße.de` onto `strasse.de`, another domain.
        $ascii = @idn_to_ascii($domain, IDNA_DEFAULT | IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);

        return is_string($ascii) && $ascii !== '' ? $ascii : $domain;
    }

    /** strtolower, not mb_: mb folds U+212A KELVIN SIGN onto `k`, giving a stranger the owner's jack@. */
    private static function local(string $address): string
    {
        $address = trim($address);

        return strtolower(mb_substr($address, 0, (int)mb_strrpos($address, '@')));
    }
}
