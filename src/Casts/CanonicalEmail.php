<?php

declare(strict_types=1);

namespace EmailIntegrity\Casts;

use EmailIntegrity\EmailAddress;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Keeps a canonical-identity column filled from the address as it was typed, so a unique
 * index can refuse `a+1@gmail.com` to someone already registered as `a.b@gmail.com`.
 *
 * A cast and not a `saving` hook: `saveQuietly()` and `withoutEvents()` fire no events, and
 * a hook that silently skips leaves a NULL in the column — which a unique index accepts
 * without limit, turning the whole mechanism off for exactly the rows that dodged it.
 * Assignment cannot be skipped that way. A raw query builder insert still bypasses both,
 * which is why the index, not this class, is the enforcement.
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
