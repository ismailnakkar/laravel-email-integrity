<?php

declare(strict_types=1);

namespace EmailIntegrity;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
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
    /**
     * @internal Laravel's check of an address a mail provider takes: `strict` refuses a domain without a dot, and `filter`
     * (PHP's own check) one of bare digits such as `192.0.2.1`.
     */
    public const PROVIDER_FORMAT = 'email:strict,filter';

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

    /**
     * Whether no mail can reach the address: by its form (unreachableByForm()), or, while suppression is on, because an
     * SMTP server refused it as invalid. A bounce or a complaint is not counted: that inbox exists.
     */
    public static function unreachable(string $address): bool
    {
        return self::unreachableByForm($address)
            || (config('email-integrity.suppression.enabled')
                && self::query()->whereIn('email', self::keys($address))->where('reason', SuppressionReason::invalid)->exists());
    }

    /**
     * @internal unreachable() without the table, which Rules\AddressRules refuses, so a form never takes an address the
     * members' notice then calls unreachable: a send to it would throw (EmailAddress::sendable()), or the form a row
     * would hold fails PROVIDER_FORMAT (malformed()).
     */
    public static function unreachableByForm(string $address): bool
    {
        return ! EmailAddress::sendable($address) || self::malformed($address);
    }

    /**
     * @internal Whether the form a row would hold, key() for `invalid`, fails PROVIDER_FORMAT: ` bob@gmail.com`,
     * `bob@gmail.com.` and `bob@münchen.de` fail as typed but fold onto a working inbox. True with no such form (outside
     * printable ASCII, or over 254 characters).
     */
    public static function malformed(string $address): bool
    {
        $key = self::key($address, SuppressionReason::invalid);

        return $key === null || Validator::make(['email' => $key], ['email' => self::PROVIDER_FORMAT])->fails();
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

    /**
     * Deletes the rows blocking() would find for the address, saying whether the mail provider may still hold it
     * (ReleasedAddress::$heldByProvider), from the rows read before they go. Reads and deletes while suppression is off
     * too.
     */
    public static function release(string $address): ReleasedAddress
    {
        $rows = self::query()->whereIn('email', self::keys($address))->get();

        // Nothing lifted says nothing of the provider's own list: it may hold the address still.
        if ($rows->isEmpty()) {
            return new ReleasedAddress(rows: 0, heldByProvider: true);
        }

        return new ReleasedAddress(
            rows: self::query()->whereKey($rows->modelKeys())->delete(),
            heldByProvider: $rows->contains(fn (self $row): bool => $row->reason->heldByProvider()),
        );
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
