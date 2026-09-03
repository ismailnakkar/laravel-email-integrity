<?php

declare(strict_types=1);

namespace EmailIntegrity;

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

        $ascii = @idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

        return is_string($ascii) && $ascii !== '' ? $ascii : $domain;
    }

    /**
     * The one inbox an address actually reaches, so two spellings of it compare equal.
     *
     * Everything after `+` is a tag the provider strips, and Gmail also ignores dots in
     * the local part — `j.anedoe@gmail.com` and `jane.doe@gmail.com` are one
     * mailbox. Seen in the wild as five separately-paid accounts on one Gmail identity,
     * distinguished only by where the dot sat.
     *
     * Dots are only collapsed for providers that genuinely ignore them. Elsewhere
     * `a.b@` and `ab@` are different people, and merging them would deny a real signup.
     * `-` is left alone for the same reason: it is qmail's default delimiter and an
     * ordinary name character everywhere else, so folding `mary-jane@` onto `mary@` would
     * refuse a real person. The `+` strip is that same bet at odds worth taking — every
     * mass provider, Postfix and Exim all treat it as a tag.
     *
     * Null when there is no domain, and for a quoted local part, whose characters are
     * literal — canonicalising it would change which mailbox it names. Null is not a safe
     * value to STORE against a unique index, which accepts unlimited NULLs on both MySQL
     * and Postgres, so a caller persisting this falls back to the address as typed.
     */
    public static function canonical(mixed $email): ?string
    {
        $domain = self::domainOf($email);

        if ($domain === null) {
            return null;
        }

        // strtolower() and NOT mb_strtolower() on the local part: U+212A KELVIN SIGN is the
        // one codepoint mb_ folds into an ASCII letter (`k`), and `email:strict` accepts it.
        // Folding it would canonicalise a stranger's jacK@gmail.com onto jack@gmail.com and
        // hand them the unique-index slot. The domain is lowercased by domainOf().
        $address = trim((string)$email);
        $local = strtolower(mb_substr($address, 0, (int)mb_strrpos($address, '@')));

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
