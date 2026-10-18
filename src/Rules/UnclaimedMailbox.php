<?php

declare(strict_types=1);

namespace EmailIntegrity\Rules;

use Closure;
use EmailIntegrity\EmailAddress;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Refuses an address whose mailbox a row already holds, for a table that keeps the address as typed beside its
 * canonical form. Core `unique` compares the spelling, so an alias passes it and the unique index then throws.
 * Fails with the app's `validation.unique` message, as core `unique` does.
 */
class UnclaimedMailbox implements ValidationRule
{
    private mixed $ignore = null;

    private string $idColumn = 'id';

    public function __construct(
        private readonly string $table,
        private readonly string $canonical = 'email_canonical',
        private readonly string $email = 'email',
    ) {}

    /** Skip the row being updated, as `Rule::unique()->ignore()` does: an account may keep or re-tag its own mailbox. */
    public function ignore(mixed $id, ?string $idColumn = null): static
    {
        $this->idColumn = $idColumn ?? ($id instanceof Model ? $id->getKeyName() : 'id');
        $this->ignore = $id instanceof Model ? $id->getAttribute($this->idColumn) : $id;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $rows = DB::table($this->table)->when($this->ignore !== null, fn (Builder $rows) => $rows->where($this->idColumn, '<>', $this->ignore));

        // A row whose canonical column is NULL still holds its exact address, compared byte for byte: narrowed in SQL,
        // then compared here, whatever the column's collation.
        $taken = (clone $rows)->where($this->canonical, EmailAddress::canonical($value) ?? $value)->exists()
            || $rows->whereNull($this->canonical)->where($this->email, $value)->pluck($this->email)->contains(fn (mixed $held): bool => $held === $value);

        if ($taken) {
            $fail('validation.unique')->translate();
        }
    }
}
