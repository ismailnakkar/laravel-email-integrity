<?php

declare(strict_types=1);

namespace EmailIntegrity\Rules;

use Closure;
use EmailIntegrity\EmailIntegrity;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Refuses throwaway domains. Local lookup only — the address never leaves the app.
 *
 * Pair it with Laravel's own `email` rule, which owns the shape complaint; this rule
 * passes anything it cannot read a domain out of rather than duplicating that message.
 */
class NotDisposable implements ValidationRule
{
    public function __construct(private readonly EmailIntegrity $email) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->email->isDisposable($value)) {
            $fail('email-integrity::messages.disposable')->translate();
        }
    }
}
