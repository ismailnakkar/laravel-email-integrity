<?php

declare(strict_types=1);

namespace EmailIntegrity;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * The two questions worth asking about an address before you trust it with money:
 * is the domain a throwaway, and can it receive mail at all.
 *
 * Both answers are about the DOMAIN. Neither says the mailbox exists — only a
 * verification link does that, and this package deliberately stops short of it so the
 * app owns when to demand one.
 */
class EmailIntegrity
{
    public function __construct(
        private readonly Config $config,
        private readonly CacheFactory $cache,
        private readonly DisposableDomains $disposable,
    ) {}

    public function enabled(): bool
    {
        return (bool)$this->config->get('email-integrity.enabled', true);
    }

    /**
     * The inbox this address actually reaches, for storing next to the address so two
     * spellings of one mailbox cannot become two accounts.
     *
     * Uniqueness stays the app's to enforce, because the package cannot migrate your users
     * table: give the canonical value its own column and unique index, and let the
     * CanonicalEmail cast keep it filled. Null when the address has no provable identity —
     * store the address as typed instead, since a unique index accepts unlimited NULLs.
     */
    public function canonical(mixed $email): ?string
    {
        return EmailAddress::canonical($email);
    }

    /**
     * True when the address should be refused as a throwaway.
     *
     * The allowlist wins over both the fetched list and your own denylist, so an
     * over-broad upstream entry is a config edit rather than a release.
     */
    public function isDisposable(mixed $email): bool
    {
        $domain = EmailAddress::domainOf($email);

        if ($domain === null || ! $this->enabled()) {
            return false;
        }

        if (EmailAddress::listedIn($domain, (array)$this->config->get('email-integrity.allow', []))) {
            return false;
        }

        return EmailAddress::listedIn($domain, (array)$this->config->get('email-integrity.deny', []))
            || $this->disposable->contains($domain);
    }

    /**
     * True when the domain can receive mail — an MX record, or an A/AAAA record, which
     * RFC 5321 §5.1 makes an implicit mail destination.
     *
     * Returns the `fail_open` value when the resolver is unreachable, so a DNS outage
     * does not become a signup outage.
     */
    public function hostResolves(mixed $email): bool
    {
        $domain = EmailAddress::domainOf($email);

        if ($domain === null) {
            return true;
        }

        $host = $this->config->get('email-integrity.host', []);

        if (! $this->enabled() || ! ($host['enabled'] ?? true)) {
            return true;
        }

        // An allowlisted domain is trusted by decision; do not spend a lookup on it.
        if (EmailAddress::listedIn($domain, (array)$this->config->get('email-integrity.allow', []))) {
            return true;
        }

        // TTL 0 makes the cache forget rather than store, so a "could not tell" never
        // persists — a ten-second blip would otherwise pin a wrong verdict for the hour.
        $ttl = fn (?bool $answer): int => $answer === null ? 0 : (int)($host['cache_ttl'] ?? 3600);

        $resolved = $this->cache->store()->remember(
            // Hashed because the domain is attacker-chosen and unbounded: a 247-character
            // one clears `max:255` and `email:strict`, and Laravel's default cache store is
            // `database`, whose key column is a varchar(255) primary key.
            'email-integrity:host:' . hash('xxh128', $domain),
            $ttl,
            fn (): ?bool => $this->resolve($domain),
        );

        return $resolved ?? (bool)($host['fail_open'] ?? true);
    }

    /** True or false when the resolver answered, null when it could not be reached. */
    private function resolve(string $domain): ?bool
    {
        // checkdnsrr() calls RFC 7505's null MX (`MX 0 .`) a live MX. Read the record
        // instead; dns_get_record strips the root label, leaving an empty target.
        $mx = array_column(@dns_get_record($domain, DNS_MX) ?: [], 'target');

        if ($mx !== []) {
            return $mx !== [''];
        }

        foreach (['A', 'AAAA'] as $type) {
            if (@checkdnsrr($domain, $type)) {
                return true;
            }
        }

        // "No such record" and "resolver unreachable" look identical from here, so probe
        // a name that must exist to tell them apart.
        return @checkdnsrr('a.root-servers.net', 'A') ? false : null;
    }
}
