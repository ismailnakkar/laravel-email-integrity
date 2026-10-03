<?php

declare(strict_types=1);

namespace EmailIntegrity;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;

/** Is the domain a throwaway, and can it receive mail at all: both about the domain, never the mailbox. */
class EmailIntegrity
{
    private const RESOLVER_DOWN = 'email-integrity:resolver-down';

    public function __construct(
        private readonly Config $config,
        private readonly CacheFactory $cache,
        private readonly DisposableDomains $disposable,
    ) {}

    public function enabled(): bool
    {
        return (bool)$this->config->get('email-integrity.enabled', true);
    }

    /** @deprecated Use EmailAddress::canonical(); removed in 2.0. */
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

        $store = $this->cache->store();

        $resolved = $store->remember(
            // Hashed because the domain is attacker-chosen and unbounded: a 247-character
            // one clears `max:255` and `email:strict`, and Laravel's default cache store is
            // `database`, whose key column is a varchar(255) primary key.
            'email-integrity:host:' . hash('xxh128', $domain),
            $ttl,
            fn (): ?bool => $store->has(self::RESOLVER_DOWN) ? null : $this->resolve($domain),
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
        if (@checkdnsrr('a.root-servers.net', 'A')) {
            return false;
        }

        // Remembered briefly: a resolver that times out would make every signup wait out all four lookups.
        $this->cache->store()->put(self::RESOLVER_DOWN, true, 30);

        return null;
    }
}
