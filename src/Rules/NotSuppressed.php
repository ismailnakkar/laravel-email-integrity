<?php

declare(strict_types=1);

namespace EmailIntegrity\Rules;

use Closure;
use EmailIntegrity\SuppressedAddress;
use Illuminate\Contracts\Validation\ValidationRule;

/** Refuses an address the suppression list stops, so a new account cannot route mail back into it. */
class NotSuppressed implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && SuppressedAddress::blocking($value) !== null) {
            $fail('email-integrity::messages.suppressed')->translate();
        }
    }
}
