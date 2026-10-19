<?php

declare(strict_types=1);

namespace EmailIntegrity;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;

/** Domain checks only: neither proves the mailbox exists. */
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

    /** True for a domain on `deny` or the fetched list, unless `allow` covers it. False while disabled. */
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
     * True when the domain has an MX, or an A/AAAA (RFC 5321 §5.1's implicit MX); `fail_open` when the resolver is
     * unreachable. True without a domain, while disabled, or for an `allow` entry.
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

        if (EmailAddress::listedIn($domain, (array)$this->config->get('email-integrity.allow', []))) {
            return true;
        }

        // TTL 0 stores nothing, so a resolver blip is never cached as a verdict.
        $ttl = fn (?bool $answer): int => $answer === null ? 0 : (int)($host['cache_ttl'] ?? 3600);

        $store = $this->cache->store();

        $resolved = $store->remember(
            // Hashed: a 247-character domain passes `max:255`, and the database cache store's key is a varchar(255).
            'email-integrity:host:' . hash('xxh128', $domain),
            $ttl,
            fn (): ?bool => $store->has(self::RESOLVER_DOWN) ? null : $this->resolve($domain),
        );

        return $resolved ?? (bool)($host['fail_open'] ?? true);
    }

    /** Null when the resolver could not be reached. */
    private function resolve(string $domain): ?bool
    {
        // Not checkdnsrr(): it counts RFC 7505's null MX (`MX 0 .`) as live. dns_get_record() gives it an empty target.
        $mx = array_column(@dns_get_record($domain, DNS_MX) ?: [], 'target');

        if ($mx !== []) {
            return $mx !== [''];
        }

        foreach (['A', 'AAAA'] as $type) {
            if (@checkdnsrr($domain, $type)) {
                return true;
            }
        }

        // A name that must exist tells "no such record" from "resolver unreachable".
        if (@checkdnsrr('a.root-servers.net', 'A')) {
            return false;
        }

        // Remembered briefly: a resolver that times out would make every signup wait out all four lookups.
        $this->cache->store()->put(self::RESOLVER_DOWN, true, 30);

        return null;
    }
}
