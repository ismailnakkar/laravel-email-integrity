<?php

declare(strict_types=1);

namespace EmailIntegrity;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * An address the mail provider reported as a complaint, a permanent bounce or its own block, or that an SMTP server
 * refused as invalid at send time: never mailed again.
 *
 * @property int $id
 * @property string $email
 * @property SuppressionReason $reason
 */
class SuppressedAddress extends Model
{
    /** Printable ASCII, 1 to 254 characters, not ending in a space: see key(). */
    private const STORABLE_KEY_PATTERN = '/^[\x20-\x7E]{0,253}[\x21-\x7E]$/D';

    protected $table = 'suppressed_addresses';

    protected $fillable = ['email', 'reason'];

    /**
     * The inbox or the spelling, as the reason keysInbox(). Null for no address, over 254 characters (RFC 5321), outside
     * printable ASCII (a `_ci` collation folds `ｊａｃｋ@`, `jäck@` and KELVIN SIGN onto `jack@`), or ending in a space
     * (MariaDB ignores it when comparing).
     */
    public static function key(string $address, SuppressionReason $reason): ?string
    {
        $key = $reason->keysInbox()
            ? EmailAddress::canonical(EmailAddress::bare($address)) ?? EmailAddress::literal($address)
            : EmailAddress::literal($address);

        return $key !== null && preg_match(self::STORABLE_KEY_PATTERN, $key) === 1 ? $key : null;
    }

    /**
     * The row that stops mail to any of the addresses: a complaint on its inbox, or a bounce on its spelling. Null,
     * with no query, while suppression is off.
     */
    public static function blocking(string ...$addresses): ?self
    {
        if (! config('email-integrity.suppression.enabled')) {
            return null;
        }

        return self::query()->whereIn('email', self::keys(...$addresses))->first();
    }

    /** EmailAddress::sendable() and not suppressed; false for a non-string. Unrelated to Laravel's `Mailable`. */
    public static function mailable(mixed $address): bool
    {
        return EmailAddress::sendable($address) && self::blocking($address) === null;
    }

    /** Insert-if-absent, so a redelivered event is a no-op and the first reason sticks. True for a new row. */
    public static function suppress(string $address, SuppressionReason $reason): bool
    {
        $key = self::key($address, $reason);

        if ($key === null || ! self::query()->firstOrCreate(['email' => $key], ['reason' => $reason])->wasRecentlyCreated) {
            return false;
        }

        Log::info('Mail address suppressed.', ['email' => $key, 'reason' => $reason->value]);

        // An unset env variable in the list is harmless: null is filtered out, and '' has no key.
        $own = array_values(array_filter((array)config('email-integrity.suppression.own_inboxes', []), is_string(...)));

        if (in_array($key, self::keys(...$own), true)) {
            report(new RuntimeException(sprintf(
                'The site inbox %s is now suppressed (%s). Fix its mailbox, then lift it here%s.',
                $key,
                $reason->value,
                $reason->heldByProvider() ? ' and at the mail provider' : '',
            )));
        }

        return true;
    }

    /** Deletes the rows blocking() would find for the address. */
    public static function lift(string $address): int
    {
        return self::query()->whereIn('email', self::keys($address))->delete();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['reason' => SuppressionReason::class];
    }

    /** @return list<string> */
    private static function keys(string ...$addresses): array
    {
        $keys = [];

        foreach ($addresses as $address) {
            $keys[] = self::key($address, SuppressionReason::bounced);
            $keys[] = self::key($address, SuppressionReason::complained);
        }

        return array_values(array_unique(array_filter($keys)));
    }
}
