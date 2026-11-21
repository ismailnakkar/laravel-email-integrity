<?php

declare(strict_types=1);

namespace EmailIntegrity;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** The fetched list, matched exactly: it names every domain it blocks. */
final class DisposableDomains
{
    /** @var array<string, true>|null */
    private ?array $domains = null;

    public function __construct(private readonly Config $config) {}

    /**
     * Exact, on a normalised domain, and blind to `allow` and `deny`: ask EmailIntegrity::isDisposable().
     *
     * @internal
     */
    public function contains(string $domain): bool
    {
        return isset($this->all()[$domain]);
    }

    /**
     * Writes the merged sources and returns the domain count. Throws, keeping the old file, unless every source
     * returns `min_domains`: a partial list would silently admit throwaways. Run it through `email-integrity:update`.
     *
     * @internal
     */
    public function update(): int
    {
        $sources = (array)$this->config->get('email-integrity.disposable.sources', []);
        $timeout = (int)$this->config->get('email-integrity.disposable.timeout', 30);
        $minimum = (int)$this->config->get('email-integrity.disposable.min_domains', 1000);

        $merged = [];

        foreach ($sources as $url) {
            foreach ($this->fetch((string)$url, $timeout, $minimum) as $domain) {
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

    /** Forgets the loaded list, so the next lookup reads `disposable.storage` again. */
    public function flush(): void
    {
        $this->domains = null;
    }

    /** @return array<string, true> */
    private function all(): array
    {
        return $this->domains ??= $this->loadFromStorage();
    }

    /** @return array<int, mixed> */
    private function fetch(string $url, int $timeout, int $minimum): array
    {
        $domains = Http::timeout($timeout)->get($url)->throw()->json();

        // Per source: a truncated second list would otherwise shrink the merged one silently.
        if (! is_array($domains) || $domains === [] || count($domains) < $minimum) {
            throw new RuntimeException(sprintf('The disposable-domains source returned no usable list of %d domains: %s', $minimum, $url));
        }

        return $domains;
    }

    /** @return array<string, true> */
    private function loadFromStorage(): array
    {
        $path = $this->path();

        if ($path === '' || ! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string)file_get_contents($path), true);

        // Normalised by update().
        return is_array($decoded) ? array_fill_keys(array_filter($decoded, is_string(...)), true) : [];
    }

    /** @param list<string> $domains */
    private function write(array $domains): void
    {
        $path = $this->path();
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

    private function path(): string
    {
        return (string)$this->config->get('email-integrity.disposable.storage');
    }

    private function normalise(string $domain): string
    {
        return rtrim(mb_strtolower(trim($domain)), '.');
    }
}
