<?php

declare(strict_types=1);

namespace EmailIntegrity\Rules;

use Closure;
use EmailIntegrity\EmailIntegrity;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Refuses domains that cannot receive mail at all — the dead typo (`gmial.cm`) no
 * blocklist will ever carry, caught while the user is still looking at the field.
 *
 * Belongs on entry forms, not on a payout path: this is the one rule here that can
 * fail for reasons outside the user's control.
 */
class RoutableDomain implements ValidationRule
{
    public function __construct(private readonly EmailIntegrity $email) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $this->email->hostResolves($value)) {
            $fail('email-integrity::messages.unroutable')->translate();
        }
    }
}
