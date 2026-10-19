<?php

declare(strict_types=1);

namespace EmailIntegrity\Rules;

use Closure;
use EmailIntegrity\EmailIntegrity;
use Illuminate\Contracts\Validation\ValidationRule;

/** Refuses throwaway domains, by a local lookup. Passes a value with no domain: pair it with `email:strict`. */
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
