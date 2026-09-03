<?php

declare(strict_types=1);

namespace EmailIntegrity;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The disposable-domain list: fetch, store, look up.
 *
 * Lookups are a hash-set membership test, so the 74k-entry list costs one load and
 * O(1) per address. The set is keyed on the domain itself; subdomain coverage is the
 * caller's job via EmailAddress::listedIn, because only the configured lists want it —
 * the fetched list is already exhaustive at the level it publishes.
 */
class DisposableDomains
{
    /** @var array<string, true>|null */
    private ?array $domains = null;

    public function __construct(
        private readonly Config $config,
        private readonly CacheFactory $cache,
    ) {}

    public function contains(string $domain): bool
    {
        return isset($this->all()[$domain]);
    }

    /** @return array<string, true> */
    public function all(): array
    {
        if ($this->domains !== null) {
            return $this->domains;
        }

        $cache = $this->config->get('email-integrity.disposable.cache');

        if (! ($cache['enabled'] ?? true)) {
            return $this->domains = $this->loadFromStorage();
        }

        // Null, not [], for an empty list: the cache treats null as a miss, so a list
        // file that is not there yet cannot be remembered as "nothing is disposable".
        return $this->domains = $this->cache->store($cache['store'] ?? null)->remember(
            $cache['key'] ?? 'email-integrity:disposable-domains',
            $cache['ttl'] ?? 86400,
            fn (): ?array => $this->loadFromStorage() ?: null,
        ) ?? [];
    }

    /**
     * Fetches every source and writes the merged list.
     *
     * Nothing is written until every source has answered and the total clears
     * `min_domains`: a half-fetched list that silently admits throwaway addresses is
     * worse than yesterday's list, so a failure leaves the old file untouched.
     *
     * @return int The number of domains written.
     */
    public function update(): int
    {
        $sources = (array)$this->config->get('email-integrity.disposable.sources', []);
        $timeout = (int)$this->config->get('email-integrity.disposable.timeout', 30);
        $minimum = (int)$this->config->get('email-integrity.disposable.min_domains', 1000);

        $merged = [];

        foreach ($sources as $url) {
            foreach ($this->fetch((string)$url, $timeout) as $domain) {
                if (is_string($domain) && ($domain = $this->normalise($domain)) !== '') {
                    $merged[$domain] = true;
                }
            }
        }

        if (count($merged) < $minimum) {
            throw new RuntimeException(sprintf(
                'Refusing to write a disposable list of %d domains; the floor is %d. The previous list is untouched.',
                count($merged),
                $minimum,
            ));
        }

        $this->write(array_keys($merged));
        $this->flush();

        return count($merged);
    }

    public function flush(): void
    {
        $this->domains = null;

        $cache = $this->config->get('email-integrity.disposable.cache');

        if ($cache['enabled'] ?? true) {
            $this->cache->store($cache['store'] ?? null)
                ->forget($cache['key'] ?? 'email-integrity:disposable-domains');
        }
    }

    /** @return array<int, mixed> */
    private function fetch(string $url, int $timeout): array
    {
        $domains = Http::timeout($timeout)->get($url)->throw()->json();

        if (! is_array($domains) || $domains === []) {
            throw new RuntimeException('The disposable-domains source returned no usable list: ' . $url);
        }

        return $domains;
    }

    /** @return array<string, true> */
    private function loadFromStorage(): array
    {
        $path = (string)$this->config->get('email-integrity.disposable.storage');

        if ($path === '' || ! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string)file_get_contents($path), true);

        if (! is_array($decoded)) {
            return [];
        }

        $domains = [];

        foreach ($decoded as $domain) {
            if (is_string($domain) && ($domain = $this->normalise($domain)) !== '') {
                $domains[$domain] = true;
            }
        }

        return $domains;
    }

    /** @param list<string> $domains */
    private function write(array $domains): void
    {
        $path = (string)$this->config->get('email-integrity.disposable.storage');
        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException('Cannot create the disposable-list directory: ' . $directory);
        }

        sort($domains);

        // Write-then-rename: a reader never sees a half-written file.
        $temporary = $path . '.' . getmypid() . '.tmp';

        if (@file_put_contents($temporary, json_encode($domains)) === false || ! @rename($temporary, $path)) {
            @unlink($temporary);

            throw new RuntimeException('Cannot write the disposable list to: ' . $path);
        }
    }

    private function normalise(string $domain): string
    {
        return rtrim(mb_strtolower(trim($domain)), '.');
    }
}
