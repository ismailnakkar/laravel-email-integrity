<?php

declare(strict_types=1);

namespace EmailIntegrity;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * An address the mail provider reported as a complaint, a permanent bounce or its own block: never mailed again.
 *
 * @property int $id
 * @property string $email
 * @property SuppressionReason $reason
 */
class SuppressedAddress extends Model
{
    protected $table = 'suppressed_addresses';

    protected $fillable = ['email', 'reason'];

    /**
     * The row key for an address. Only the inbox's owner can complain, so a complaint keys the inbox and blocks every
     * spelling; a bounce can follow a stranger's typo (`member+x@` on a server without tags), so it keys the spelling.
     * Null when there is nothing to key, the key cannot be an address (RFC 5321 caps it at 254), it holds anything but
     * printable ASCII (a `_ci` collation folds `ｊａｃｋ@`, `jäck@` and the KELVIN SIGN onto `jack@`), or it ends in a
     * space (MariaDB pads spaces when it compares, so `jack@example.com ` would block `jack@example.com`).
     */
    public static function key(string $address, SuppressionReason $reason): ?string
    {
        $key = $reason === SuppressionReason::complained
            ? EmailAddress::canonical(EmailAddress::bare($address)) ?? EmailAddress::literal($address)
            : EmailAddress::literal($address);

        return $key !== null && preg_match('/^[\x20-\x7E]{0,253}[\x21-\x7E]$/D', $key) === 1 ? $key : null;
    }

    /** The row that stops mail to any of the addresses: a complaint on its inbox, or a bounce on its spelling. */
    public static function blocking(string ...$addresses): ?self
    {
        // The one switch: the send guard, NotSuppressed and the app's own checks all pass while it is off.
        if (! config('email-integrity.suppression.enabled')) {
            return null;
        }

        return self::query()->whereIn('email', self::keys(...$addresses))->first();
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
                'The site inbox %s is now suppressed (%s). Fix its mailbox, then lift it here and at the mail provider.',
                $key,
                $reason->value,
            )));
        }

        return true;
    }

    /** Deletes the rows blocking() would find for the address. */
    public static function lift(string $address): int
    {
        return self::query()->whereIn('email', self::keys($address))->delete();
    }

    /**
     * Deletes each row with no key, logged as a warning: under a case- and accent-insensitive collation it could match
     * an ASCII lookalike. Then rewrites each row to its key; a row whose key another row already holds is deleted, as
     * the database's own collation sees it. Rewriting keeps the row's timestamps. Returns the rows changed.
     */
    public static function rekey(): int
    {
        $changed = 0;

        // Keyless rows first: left for later, `ｊｄｏｅ@` would pass for the holder of `jdoe@` below and take the real row
        // down with it.
        foreach (self::query()->lazyById() as $row) {
            if (self::key($row->email, $row->reason) === null) {
                Log::warning('Suppressed address with no key deleted.', ['email' => $row->email, 'reason' => $row->reason->value]);
                $row->delete();
                $changed++;
            }
        }

        foreach (self::query()->lazyById() as $row) {
            $key = self::key($row->email, $row->reason);

            if ($key === $row->email) {
                continue;
            }

            if (self::query()->where('email', $key)->whereKeyNot($row->getKey())->exists()) {
                $row->delete();
            } else {
                self::query()->toBase()->where('id', $row->getKey())->update(['email' => $key]);
            }

            $changed++;
        }

        return $changed;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['reason' => SuppressionReason::class];
    }

    /**
     * Both keys of each address: the spelling a bounce row holds and the inbox a complaint row holds.
     *
     * @return list<string>
     */
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
