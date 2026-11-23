<?php

declare(strict_types=1);

namespace EmailIntegrity\Rules;

use Closure;
use EmailIntegrity\SuppressedAddress;

/**
 * The rules an address someone enters (sign-up, an email change, an admin's edit) passes, in the README's order, for a
 * table that keeps the address as typed beside its canonical form (Casts\CanonicalEmail).
 */
final class AddressRules
{
    private function __construct() {}

    /**
     * `bail`, `email:strict`, the reachable-form check, UnclaimedMailbox, `not_suppressed`, `not_disposable`,
     * `routable_domain`. Put your `required`, `string` and `max:` ahead.
     *
     * @param  mixed  $ignore  The row an update form changes, as UnclaimedMailbox::ignore() takes it: it may re-tag its own mailbox.
     * @param  bool  $filter  Adds Laravel's `filter`, which also refuses an internationalised domain as typed.
     * @return list<mixed>
     */
    public static function for(string $table, mixed $ignore = null, bool $filter = false): array
    {
        return [
            'bail',
            $filter ? 'email:strict,filter' : 'email:strict',
            self::reachableForm(...),
            (new UnclaimedMailbox($table))->ignore($ignore),
            'not_suppressed',
            // In memory, ahead of the DNS lookup.
            'not_disposable',
            'routable_domain',
        ];
    }

    /**
     * What `email:strict` passes and SuppressedAddress::unreachable() still counts by form, a local part outside printable
     * ASCII or a domain of bare digits, fails as a malformed address: no mail reaches it.
     */
    private static function reachableForm(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && SuppressedAddress::unreachableByForm($value)) {
            $fail('validation.email')->translate();
        }
    }
}
