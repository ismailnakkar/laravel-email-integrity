<?php

declare(strict_types=1);

namespace EmailIntegrity\Rules;

use Closure;
use EmailIntegrity\EmailIntegrity;
use Illuminate\Contracts\Validation\ValidationRule;

/** Refuses a domain that cannot receive mail. For entry forms, not payout or login paths: it depends on DNS. */
final class RoutableDomain implements ValidationRule
{
    public function __construct(private readonly EmailIntegrity $email) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $this->email->hostResolves($value)) {
            $fail('email-integrity::messages.unroutable')->translate();
        }
    }
}
