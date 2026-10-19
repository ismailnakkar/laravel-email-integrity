# Laravel Email Integrity

Validation rules that refuse disposable and unroutable email domains, a canonical form that makes one mailbox one
account, and an opt-in suppression list that stops mail to addresses your mail provider reports as bounced or
complained.

The domain checks never send mail, so they cannot prove a mailbox exists: keep your verification link.

## Requirements

PHP 8.2+ and Laravel 12.1+ or 13. Without `ext-intl` an internationalised domain is matched as typed, so the unicode
spelling of a listed domain is not caught.

Suppression's webhook also needs `symfony/webhook` `^7.4 || ^8.0` and your provider's Symfony bridge: for Resend,
`symfony/resend-mailer` `^7.4.18 || ^8.1.6` (older versions conflict).

Your app provides:

- A running scheduler, and an alert when `email-integrity:update` fails (it exits non-zero and passes the exception to
  `report()`).
- `bail`, `max:255` and `email:strict` ahead of the rules ([Validation](#validation)).
- The unique index that makes one mailbox one account, with a binary collation on MySQL and MariaDB
  ([Collation](#collation)).
- For suppression: the `suppressed_addresses` table, `mail.from.address` on the provider's sending domain, trusted
  proxies when the parser checks the provider's IP (Postmark, Brevo), and one recipient per message wherever someone
  else typed the recipient: an event suppresses every address on the message's To list.

## Install

```bash
composer require ismailnakkar/laravel-email-integrity
php artisan vendor:publish --tag=email-integrity-config
php artisan email-integrity:update
```

The package schedules `email-integrity:update` daily at 03:00 on every server, as each keeps its own list file; do not
schedule it again.

## Configuration

| Key                          | Default                   | Meaning                                                   |
|------------------------------|---------------------------|-----------------------------------------------------------|
| `enabled`                    | `true`                    | Off, both domain checks pass everything.                  |
| `schedule`                   | `true`                    | Schedules the daily update; `false` leaves it to you.     |
| `allow`                      | `[]`                      | Domains never refused as disposable, nor looked up.       |
| `deny`                       | `[]`                      | Domains refused as disposable.                            |
| `disposable.sources`         | one URL                   | URLs of JSON arrays of domains, merged by the update.     |
| `disposable.storage`         | `storage/framework/…`     | Full path of the merged list file.                        |
| `disposable.timeout`         | `30`                      | Seconds per source request.                               |
| `disposable.min_domains`     | `1000`                    | Fewer from any source keeps the previous list.            |
| `host.enabled`               | `true`                    | Off, `routable_domain` passes everything.                 |
| `host.fail_open`             | `true`                    | The answer while the resolver is unreachable.             |
| `host.cache_ttl`             | `3600`                    | Seconds a resolver's answer is cached.                    |
| `suppression.enabled`        | `false`                   | Suppression on or off; `enabled` does not touch it.       |
| `suppression.own_inboxes`    | `[]`                      | Your own inboxes: a block on one is passed to `report()`. |
| `suppression.webhook.path`   | `email-integrity/webhook` | The webhook's URI; null registers no route.               |
| `suppression.webhook.domain` | `null`                    | The one host it answers on; null answers on any.          |
| `suppression.webhook.parser` | `ResendRequestParser`     | The Symfony `RequestParserInterface` that reads events.   |
| `suppression.webhook.secret` | `RESEND_WEBHOOK_SECRET`   | The parser's signing secret.                              |

`enabled` and `host.enabled` read `EMAIL_INTEGRITY_ENABLED` and `EMAIL_INTEGRITY_HOST_CHECK`. The default source is
`disposable/disposable-email-domains` on jsDelivr. An `allow` or `deny` entry covers the domain and its subdomains; the
fetched list is matched exactly, so a provider with a wildcard MX (`anything.mailinator.com`) needs a `deny` entry.

A key the published file leaves out keeps its default, inside a group too. A list you set replaces the default whole.

## Validation

```php
'email' => 'bail|required|max:255|email:strict|unique:users|not_disposable|routable_domain',
```

Keep that order:

- **`bail`**, or a malformed address still reaches the DNS lookup.
- **`email:strict`**, not `email`: bare `email` accepts the address literal `a@[192.0.2.1]`, which has no domain, so
  both rules pass it.
- **Cheapest first:** `max:255` before the `email` parser, `not_disposable` (in memory) before `routable_domain` (DNS).

`not_disposable`, `routable_domain`, `not_suppressed` and `unclaimed_mailbox` are registered for you. The rule objects:

```php
use EmailIntegrity\Rules\NotDisposable;
use EmailIntegrity\Rules\NotSuppressed;
use EmailIntegrity\Rules\RoutableDomain;

'email' => ['bail', 'required', 'max:255', 'email:strict', 'unique:users',
    app(NotDisposable::class), new NotSuppressed, app(RoutableDomain::class)],
```

`not_suppressed` refuses an address the [suppression](#suppression) list stops, and passes everything while suppression
is off. A `validation.custom.email.*` line rewords only the string form; [Translations](#translations) reword both.

Outside validation:

```php
use EmailIntegrity\EmailAddress;
use EmailIntegrity\EmailIntegrity;

app(EmailIntegrity::class)->isDisposable($address);
app(EmailIntegrity::class)->hostResolves($address);
EmailAddress::sendable($address); // false where a send to this bare address would throw
```

### The routable check

Keep `routable_domain` on entry forms, not on payout or login paths: it depends on DNS. It passes a domain with an MX
or an A/AAAA record, and refuses an RFC 7505 null MX. An unreachable resolver is remembered for 30 seconds, not cached
for `cache_ttl`.

## One mailbox, one account

`EmailAddress::canonical()` names the inbox an address reaches: lowercased, `+` tag stripped and, for Gmail, dots
dropped and `googlemail.com` read as `gmail.com`. `ab+1@gmail.com`, `a.b@gmail.com` and `AB@googlemail.com` are all
`ab@gmail.com`. Nothing else is folded: dots name different people at other providers, `-` is part of a name, and a
shared MX is not a shared namespace. A quoted (`"a+b"@example.com`) or empty (`+3@example.com`) local part has no
canonical form: null.

Canonicalise before validating, and core `unique` catches every alias:

```php
use EmailIntegrity\EmailAddress;

protected function prepareForValidation(): void
{
    $this->merge(['email' => EmailAddress::canonical($this->email) ?? $this->email]);
}
```

You then store `ab@gmail.com`, not what was typed, so canonicalise at sign-in and password reset too. Keep the unique
index on `email`: the rule is the friendly error, only the index survives two simultaneous signups.

### Keeping the address as typed

Add a nullable unique `email_canonical` column and let the cast fill it on assignment, `saveQuietly()` included:

```php
use EmailIntegrity\Casts\CanonicalEmail;

protected function casts(): array
{
    return ['email' => CanonicalEmail::class]; // CanonicalEmail::class . ':identity' fills `identity` instead
}
```

Where there is no canonical form the cast stores the address as typed. Keep the column out of `$fillable`: with
`$guarded = []` an `email_canonical` key in the request overwrites the cast's value, and one mailbox registers
unlimited accounts.

Validate with `unclaimed_mailbox:users` in place of `unique:users`, which passes an alias the index then throws on. Its
parameters are `{table},{canonical_column},{ignore_id}` (defaults `email_canonical`, none); the object is
`new UnclaimedMailbox($table, $canonical)`, with `->ignore($id, $idColumn)` as on `Rule::unique()`. It fails with your
`validation.unique` message; reword it under `unclaimed_mailbox`, not `unique`.

Find the account at sign-in and password reset by `email_canonical`:

```php
$user = User::where('email_canonical', EmailAddress::canonical($typed) ?? $typed)->first();
```

### Collation

On MySQL and MariaDB the column the unique index sits on needs a binary collation. The defaults (`utf8mb4_unicode_ci`,
`utf8mb4_0900_ai_ci`) fold lookalikes such as `jäck@gmail.com` onto `jack@gmail.com`, so a stranger who registers one
first locks the owner out. `canonical()` lowercases ASCII itself, so the binary comparison is exact:

```php
$table->string('email_canonical')->nullable()->unique()->collation('utf8mb4_bin');
```

A collation belongs to the column, not the index, so a binary `users.email` makes every `where email = ?`
case-sensitive, Laravel's stock sign-in and password reset included. Keeping the address as typed, leave `users.email`
on its default collation, drop its unique index and rely on `email_canonical`. PostgreSQL and SQLite compare exactly by
default.

## Suppression

Off by default. Once on, a complaint, a permanent bounce or the provider's own block puts the address in
`suppressed_addresses`, and no mail reaches it until you lift it.

1. Install the webhook's packages: `composer require symfony/webhook symfony/resend-mailer`.
2. Create the table:

   ```bash
   php artisan vendor:publish --tag=email-integrity-migrations
   php artisan migrate
   ```
3. In the published config, set `suppression.enabled` to a literal `true`, not an env switch a deploy can forget, and
   add your contact or admin inboxes to `own_inboxes` (empty entries are skipped).
4. Put the signing secret in `.env`: `RESEND_WEBHOOK_SECRET=whsec_…`.
5. Point the provider at `https://your-app.example/email-integrity/webhook`. For Resend, subscribe it to
   `email.bounced`, `email.complained` and `email.suppressed` only: its `contact.*` and `suppression.*` events fail
   Symfony's payload check with a 406, and Svix retries them until it disables the endpoint.

The package registers that route (`POST`, named `email-integrity.webhook`, throttled to 120 a minute) only while
suppression is on. It has no middleware group, so no session or CSRF check. Set `path` to null to route
`SuppressionWebhook` yourself.

**Another provider:** set `suppression.webhook.parser` to its Symfony parser (for example
`Symfony\Component\Mailer\Bridge\Postmark\Webhook\PostmarkRequestParser`) and the secret to what it expects.
Complaints are recorded for any provider; a bounce only when the payload marks it permanent as Resend's does
(`data.bounce.type`). Amazon SES has no Symfony parser; write a `RequestParserInterface` for it.

### What is recorded

| Event (Symfony name)                                                       | Writes                           |
|----------------------------------------------------------------------------|----------------------------------|
| `MailerEngagementEvent::SPAM`                                              | `complained`, keyed on the inbox |
| `MailerDeliveryEvent::BOUNCE` marked permanent (Resend `data.bounce.type`) | `bounced`, keyed on the spelling |
| Resend `email.suppressed` (`DROPPED`)                                      | `bounced`, keyed on the spelling |
| anything else, temporary bounces and `email.failed` included               | nothing                          |

- A complaint blocks every spelling of the inbox (its `canonical()` form): only the inbox's owner can complain. A
  bounce blocks only its own spelling, so a stranger who types `member+x@corp.example` cannot block
  `member@corp.example`.
- A key is lowercase printable ASCII of at most 254 characters with a punycode domain. Any other address is never
  stored, since a `_ci` collation would match it to its ASCII lookalike; the provider's own list still drops it.
- Writes are insert-if-absent: a redelivered event changes nothing, and the first reason sticks.
- An `own_inboxes` entry is blocked like any other.

The webhook:

- Writes only signed events. It answers 401 while the secret is missing or gives a key under 16 bytes; a secret
  starting `whsec_` (or any secret, for Resend's parser) is base64-decoded strictly after the prefix.
- Answers 204, with a warning, to a signed event type the parser does not model. A signed Resend bounce, complaint
  or block the parser cannot read is passed to `report()` and refused, so the provider retries it once the parser is
  fixed. Any other refusal keeps the parser's status (406). Each refusal logs a warning, at most once per reason every
  five minutes.
- When the payload names a sender, ignores (and logs at info) an event from outside `mail.from.address`'s domain and
  its subdomains.
- Throws a `LogicException` when it runs, never at boot, while suppression is off, when `symfony/webhook` or the parser
  is missing, and when an event names a sender while `mail.from.address` is unset (the provider retries for about a
  day).

### Sending

Every To, Cc and Bcc address, mail notifications included, is checked at send time, so a row added after a mail was
queued still stops it. A hit drops the whole message and logs the warning `Mail not sent: the address is suppressed.`
with `email`, `reason` and `subject`. Otherwise the guard answers null and your own `MessageSending` listeners run
after it; one that runs before it and returns anything but null skips it.

Ask first where a send that silently does not happen would lose something, such as an alert marked as sent:

```php
use EmailIntegrity\SuppressedAddress;
use EmailIntegrity\SuppressionReason;

SuppressedAddress::blocking($user->email);                            // the row that stops mail to it, or null
SuppressedAddress::blocking(...$admins->pluck('email')->all());       // any of several
SuppressedAddress::mailable($address);                                // EmailAddress::sendable() and not suppressed
SuppressedAddress::suppress($address, SuppressionReason::complained); // true for a new row
SuppressedAddress::lift($address);                                    // the number of rows deleted
```

While suppression is off, `blocking()` answers null without a query. A new row fires Eloquent's `created` event.

To unblock an address, run `php artisan email-integrity:lift someone@example.com`, and remove it from the provider's
own list too, or the next send comes back as the provider's block and suppresses it again.

## Unsendable notifications

A mail notification routed to an address Symfony Mime cannot build (an imported row without its `@`, a line break) is
skipped instead of throwing mid-send, and logged at info as `Mail not sent: the address cannot receive mail.` with
`email` and `notification`. Only the mail channel is skipped; the others still send. The route is read as the mail
channel reads it: one address or a list, either may be `Name <address>`, or a bare address => name map.

A notification whose `toMail()` returns a `Mailable` is judged on the notifiable's route, not the `Mailable`'s own
recipients. A `Mailable` sent with `Mail::to()` is not covered: ask `EmailAddress::sendable()` first.

## Translations

The package ships en, fr, ar and es: `email-integrity::messages.disposable`, `unroutable` and `suppressed`. To reword
one, put only that key in `lang/vendor/email-integrity/{locale}/messages.php`; the others keep the package's lines.
Add a language the same way, with every key.

## Testing your app

- `Mail::fake()` never fires `MessageSending`, and `Notification::fake()` never fires `NotificationSending`, so test
  both guards by sending on the `array` mailer.
- Build rows with `SuppressedAddress::suppress()`.
- Sign a Resend event the way Svix does:

  ```php
  $body = json_encode($payload);
  // $key: the secret after `whsec_`, base64-decoded
  $signature = base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", $key, true));

  $this->call('POST', '/email-integrity/webhook', server: [
      'CONTENT_TYPE' => 'application/json',
      'HTTP_SVIX_ID' => $id,
      'HTTP_SVIX_TIMESTAMP' => (string) $timestamp, // within 300 seconds of now
      'HTTP_SVIX_SIGNATURE' => "v1,{$signature}",
  ], content: $body);
  ```

## Upgrading

### From 1.x

- `EmailIntegrity::canonical()` is gone; call `EmailAddress::canonical()`.
- `skip_unsendable` is gone: a mail notification the send would throw on is always skipped. Delete the key from a
  published config.
- `disposable.cache` is no longer read: the list loads from `disposable.storage` once per request or job. Delete the
  group from a published config.
- A published `suppression.webhook` group without `path` now gets the default `email-integrity/webhook` while
  suppression is on. Set `path` to null to route `SuppressionWebhook` yourself.
- The package now ships fr, ar and es, so those languages read its lines where your app has none. In your
  `lang/vendor/email-integrity/{fr,ar,es}/messages.php`, delete the keys you don't reword on purpose, and a file left
  empty.
- `unclaimed_mailbox` checks only the canonical column: a row whose `email_canonical` is NULL no longer holds its exact
  address. While you keep such rows, refuse that address with your own rule.
- `UnclaimedMailbox` drops its `$email` argument and `unclaimed_mailbox` its `{email_column}` parameter, so
  `{ignore_id}` is now the third: `unclaimed_mailbox:users,email_canonical,email,5` becomes
  `unclaimed_mailbox:users,email_canonical,5`.
- `SuppressedAddress::rekey()` is gone. Where you call it, copy its two passes (built on `SuppressedAddress::key()`)
  from 1.x: delete each row with no key, then rewrite each row to its key, deleting one whose key another row holds.

## Development

`composer check` runs Pint (`--test`), PHPStan, then PHPUnit on SQLite in memory.

## Licence

MIT, see [LICENSE](LICENSE).
