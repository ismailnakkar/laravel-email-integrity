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
 * `unique` by mailbox, for a table that keeps the address as typed beside its canonical form: core `unique` passes an
 * alias the index then throws on. Fails with the app's `validation.unique` message.
 */
class UnclaimedMailbox implements ValidationRule
{
    private mixed $ignore = null;

    private string $idColumn = 'id';

    public function __construct(
        private readonly string $table,
        private readonly string $canonical = 'email_canonical',
    ) {}

    /** Skips the row being updated, as `Rule::unique()->ignore()` does, so an account may re-tag its own mailbox. */
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

        $taken = DB::table($this->table)
            ->when($this->ignore !== null, fn (Builder $rows) => $rows->where($this->idColumn, '<>', $this->ignore))
            ->where($this->canonical, EmailAddress::canonical($value) ?? $value)
            ->exists();

        if ($taken) {
            $fail('validation.unique')->translate();
        }
    }
}
