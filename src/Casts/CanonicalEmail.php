<?php

declare(strict_types=1);

namespace EmailIntegrity\Casts;

use EmailIntegrity\EmailAddress;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Fills the canonical-identity column on assignment: a cast, because `saveQuietly()` skips a `saving` hook.
 *
 * @implements CastsAttributes<string|null, string|null>
 */
final readonly class CanonicalEmail implements CastsAttributes
{
    public function __construct(private string $column = 'email_canonical') {}

    /** @param array<string, mixed> $attributes */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        // A quoted or empty local part has no provable identity, so fall back to the
        // address as typed: unique on something beats a NULL the index will not police.
        return [
            $key          => $value,
            $this->column => EmailAddress::canonical($value) ?? (is_string($value) ? $value : null),
        ];
    }
}
