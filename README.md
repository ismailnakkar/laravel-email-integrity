# Laravel Email Integrity

Keeps bad email addresses out of a Laravel app, and stops mail to the ones that go bad:

- **Validation rules** that refuse disposable domains and domains that cannot receive mail.
- **One mailbox, one account:** a canonical form (`+` tags and Gmail dots folded), with a cast and a `unique` rule
  built on it.
- **Suppression (opt-in):** your mail provider's webhook records bounces and complaints, an SMTP server's refusal of
  an invalid recipient is recorded at send time, and no mail reaches those addresses again.
- **Unsendable notifications:** a mail notification to an address that would throw mid-send is skipped.

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
php artisan email-integrity:update
```

The update downloads the disposable list; until it has run, only `deny` entries are refused as disposable. Publish the
config only to change a default: `php artisan vendor:publish --tag=email-integrity-config`.

## Schedule

The package schedules `email-integrity:update` daily at 03:00 on every server, as each keeps its own list file; do not
schedule it again. To run it at another time, set `schedule` to `false` and schedule the command yourself.

## Validation

```php
'email' => 'bail|required|max:255|email:strict|unique:users|not_disposable|routable_domain',
```

Keep that order:

- **`bail`**, or a malformed address still reaches the DNS lookup.
- **`email:strict`**, not `email`: bare `email` accepts the address literal `a@[192.0.2.1]`, which has no domain, so
  both rules pass it.
- **Cheapest first:** `max:255` before the `email` parser, `not_disposable` (in memory) before `routable_domain` (DNS).

The package registers `not_disposable`, `routable_domain`, `not_suppressed` and `unclaimed_mailbox`. Each runs the
rule object of the same name:

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

`AddressRules::for()` is the whole list for such a table, in the [Validation](#validation) order: `bail`, `email:strict`,
a check of the address's form, `UnclaimedMailbox` on `email_canonical`, `not_suppressed`, `not_disposable`,
`routable_domain`. Put `required`, `string` and your column's `max:` ahead of it, and any rule of your own after it:

```php
use EmailIntegrity\Rules\AddressRules;

'email' => ['required', 'string', 'max:255', ...AddressRules::for('users', ignore: $user)],
```

`ignore` is the row an update form changes, a model or its id, as `UnclaimedMailbox::ignore()` takes it. The form check
refuses, with your `validation.email` message, what `email:strict` passes and [`unreachable()`](#sending) counts by
form: a local part outside printable ASCII (`jäck@gmail.com`) or a domain of bare digits (`a@192.0.2.1`). So a form
never takes an address that a notice built on `unreachable()` then asks the member to change. `filter: true`
makes the format `email:strict,filter`, which also refuses an internationalised domain as typed (`bob@münchen.de`,
whose punycode form mail reaches): whether to accept one is your app's choice.

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

Off by default. Once on, a complaint, a permanent bounce, the provider's own block or an SMTP server's refusal of the
recipient puts the address in `suppressed_addresses`, and no mail reaches it until you lift it.

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

| Event (Symfony name)                                                           | Writes                           |
|--------------------------------------------------------------------------------|----------------------------------|
| `MailerEngagementEvent::SPAM`                                                  | `complained`, keyed on the inbox |
| `MailerDeliveryEvent::BOUNCE` marked permanent (Resend `data.bounce.type`)     | `bounced`, keyed on the spelling |
| Resend `email.suppressed` (`DROPPED`)                                          | `bounced`, keyed on the spelling |
| an SMTP refusal of the recipient at send time ([below](#refused-at-send-time)) | `invalid`, keyed on the spelling |
| anything else, temporary bounces and `email.failed` included                   | nothing                          |

- A complaint blocks every spelling of the inbox (its `canonical()` form): only the inbox's owner can complain. A
  bounce or a refusal blocks only its own spelling, so a stranger who types `member+x@corp.example` cannot block
  `member@corp.example`.
- A key is lowercase printable ASCII of at most 254 characters with a punycode domain. Any other address is never
  stored, since a `_ci` collation would match it to its ASCII lookalike; the provider's own list still drops it.
- Writes are insert-if-absent: a redelivered event changes nothing, and the first reason sticks.
- An `own_inboxes` entry is blocked like any other.

### The webhook

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
SuppressedAddress::unreachable($address);                             // no mail can ever reach it (below)
SuppressedAddress::suppress($address, SuppressionReason::complained); // true for a new row
SuppressedAddress::lift($address);                                    // the number of rows deleted
SuppressedAddress::release($address);                                 // lift(), and whether the provider holds it too
```

While suppression is off, `blocking()` answers null without a query. A new row fires Eloquent's `created` event.

`unreachable()` is for a notice that asks a member to change their address. It is true when a send to the address
would throw (`EmailAddress::sendable()`), when the form a row would hold fails `email:strict,filter`, as the
[sweep](#addresses-already-stored) judges it, or, while suppression is on, when an SMTP server refused it (`invalid`).
A bounce or a complaint is not counted: that inbox exists. Where any block matters, ask `blocking()` or `mailable()`.
A page that offers a verification link says it could not arrive when
`! SuppressedAddress::mailable($address) || SuppressedAddress::unreachable($address)`: blocked for any reason, or
unreachable.

To unblock an address, run `php artisan email-integrity:lift someone@example.com`. For a row the provider's webhook
recorded (`$row->reason->heldByProvider()`: `bounced`, `complained`), remove it from the provider's own list too, or the
next send comes back as the provider's block and suppresses it again; the command says so. An `invalid` row never
reached that list.

From your own page, `release()` does what the command does: it reads the rows that block the address, while
suppression is off too, deletes them and returns a `ReleasedAddress`. Its `rows` is the number deleted (0 when nothing
blocked the address) and `heldByProvider` says whether to tell whoever lifted it to clear the provider's list: true
when a lifted row is `bounced` or `complained`, or when nothing was lifted, as that says nothing of the provider's list.
`$row->reason->label()` says why a row blocks mail, in the current language.

### Refused at send time

While suppression is on, a recipient an SMTP server refuses for good as invalid is suppressed (`invalid`) and the send
returns null instead of throwing, so a queued mail or notification ends with no retry and no failed job. It logs the
warning `Mail not sent: the server refused the address.` with `email`, `reply` and `subject`, and the guard above drops
later mail to the address.

- **What counts:** a permanent (5xx) reply with an RFC 3463 destination status (`5.1.1`, `5.1.2`, `5.1.3`, `5.1.6`,
  `5.1.10`) or the words `Invalid recipient` (Resend: `550 Invalid recipient address`), when the message has one
  recipient or the reply names exactly one of them (`550 5.1.1 <b@example.com>: …`). Anything else, a refused sender, a
  temporary reply, a rejected message or a refusal on a message to several that names none of them, throws as before.
- **Where:** `mail.manager` is the package's subclass of Laravel's, which wraps every SMTP transport it builds (a
  failover's or round robin's included, and one from `Mail::extend()`) in `Mail\RefusedRecipientGuard`. An `smtp`
  mailer's `getSymfonyTransport()` is that guard; its `transport` property is the `EsmtpTransport`. API transports
  (Resend's, SES) and a test's `array` transport are left as built: only an SMTP reply names a recipient.

### Addresses already stored

To suppress, once, the stored addresses a provider would refuse, count them first:

```bash
php artisan email-integrity:suppress-invalid users --dry-run
php artisan email-integrity:suppress-invalid users
```

Its arguments are `{table} {column=email}`. It checks each non-empty value against Laravel's `email:strict,filter`
(`strict` refuses a domain without a dot; `filter`, PHP's own check, also refuses one of bare digits such as
`192.0.2.1`) and suppresses each failure as `invalid`, keyed on the spelling; one outside printable ASCII is counted but
never stored. It checks the form a row would hold, trimmed, lowercased, without a trailing dot and with the domain in
punycode, so ` bob@gmail.com` or `bob@münchen.de` passes rather than block the inbox it folds onto. It prints
`3 of 5 addresses in users.email fail email:strict,filter; 2 newly suppressed.` (with `Dry run:` and `would be newly
suppressed` for `--dry-run`, which writes nothing). It pages by the column, so the table needs no `id`, and a second
run suppresses nothing new. Expect about 15 seconds per 100,000 rows.

## Unsendable notifications

A mail notification routed to an address Symfony Mime cannot build (an imported row without its `@`, a line break) is
skipped instead of throwing mid-send, and logged at info as `Mail not sent: the address cannot receive mail.` with
`email` and `notification`. Only the mail channel is skipped; the others still send. The route is read as the mail
channel reads it: one address or a list, either may be `Name <address>`, or a bare address => name map.

A notification whose `toMail()` returns a `Mailable` is judged on the notifiable's route, not the `Mailable`'s own
recipients. A `Mailable` sent with `Mail::to()` is not covered: ask `EmailAddress::sendable()` first.

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

## Translations

The package ships en, fr, ar and es: `email-integrity::messages.disposable`, `unroutable` and `suppressed` (the rules'
messages) and `reason.bounced`, `reason.complained` and `reason.invalid` (`SuppressionReason::label()`). To reword one,
put only that key in `lang/vendor/email-integrity/{locale}/messages.php`; the others keep the package's lines. Add a
language the same way, with every key.

## API

All classes are under `EmailIntegrity\`.

- **`EmailIntegrity`** (resolve from the container): `isDisposable($email)`, `hostResolves($email)`.
- **`EmailAddress`** (static):
  - `domainOf($email)`: lowercase punycode domain, or null.
  - `canonical($email)`: the inbox it reaches, or null.
  - `sendable($email)`: whether Symfony Mime accepts the bare address.
  - `listedIn($domain, $entries)`: whether an entry is the domain or a parent of it, both normalised as
    `domainOf()` does (`Mail.Example.com.` is covered by `example.com`).
- **`SuppressedAddress`** (Eloquent model): `blocking(...$addresses)`, `mailable($address)`, `unreachable($address)`,
  `suppress($address, $reason)`, `lift($address)`, `release($address)`, `key($address, $reason)` (the stored form, or
  null).
- **`ReleasedAddress`** (readonly, from `release()`): `rows`, `heldByProvider`.
- **`SuppressionReason`** (enum): `bounced`, `complained`, `invalid`; `heldByProvider()` (whether the provider keeps its
  own block too), `label()` (why mail is blocked, translated).
- **`Mail\RefusedRecipientGuard`**: an SMTP mailer's transport; `transport` is the one it wraps.
- **`DisposableDomains`** (final, resolve from the container): `flush()` (reload from the file on next use).
- **Rules** (final): `Rules\NotDisposable`, `Rules\RoutableDomain` (resolve from the container),
  `Rules\NotSuppressed`, `Rules\UnclaimedMailbox`; `Rules\AddressRules::for($table, $ignore = null, $filter = false)`,
  the list for an entered address.
- **Cast:** `Casts\CanonicalEmail`.
- **Controller:** `Http\SuppressionWebhook`.
- **Commands:** `email-integrity:update`, `email-integrity:lift {address}`,
  `email-integrity:suppress-invalid {table} {column=email} {--dry-run}`.

## Testing your app

- Set `EMAIL_INTEGRITY_HOST_CHECK` to `false` in `phpunit.xml`, so no test waits on DNS.
- Refuse a domain in a test with `config(['email-integrity.deny' => ['throwaway.test']])`. To test the fetched list's
  exact matching, write a JSON array of lowercase punycode domains, no trailing dot, to a file, set
  `email-integrity.disposable.storage` to its path, then call `app(DisposableDomains::class)->flush()`, as the list
  loads once per request or job.
- `Mail::fake()` never fires `MessageSending`, and `Notification::fake()` never fires `NotificationSending`, so test
  both guards by sending on the `array` mailer.
- Build rows with `SuppressedAddress::suppress()`.
- A test that inspects an `smtp` mailer's `EsmtpTransport` reads `Mail::mailer('smtp')->getSymfonyTransport()->transport`.
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

### From 3.0

3.1 adds `SuppressedAddress::unreachable()` and `release()`, `SuppressionReason::label()` and `Rules\AddressRules`;
nothing to change. `email-integrity:lift` now decides its provider warning from every row it lifts, read while
suppression is off too: before, it read the first row `blocking()` found, and warned for any row while suppression was
off. An app's own lang override needs no new key: `reason.*` falls back to the package's line.

### From 2.1

3.0 closes API no app needs:

- `Rules\NotDisposable`, `Rules\NotSuppressed`, `Rules\RoutableDomain` and `Rules\UnclaimedMailbox` are final: a
  rule that extends one wraps it instead, taking it in the constructor and calling its `validate()`.
- `DisposableDomains` is final too: where you extended it to change the list's source or file, set
  `disposable.sources` or `disposable.storage` instead.
- `EmailAddress::coveredBy($domain, $entry)` is private: call `EmailAddress::listedIn($domain, [$entry])`.
- `DisposableDomains::all()` is private. To test an address, ask `app(EmailIntegrity::class)->isDisposable($address)`
  (it also applies `allow`, `deny` and `enabled`). To read the fetched list itself, decode the JSON list of domains in
  the file at `config('email-integrity.disposable.storage')`.
- `EmailIntegrity::enabled()` is private: read `config('email-integrity.enabled')`.
- `DisposableDomains::contains()` and `update()`, `EmailAddress::bare()` and `literal()` are `@internal`: public, but
  free to change in any release. Ask `isDisposable()` instead of `contains()` (it applies `allow` and `deny`), and run
  `email-integrity:update` instead of `update()`.

`listedIn()` now normalises `$domain` as it does each entry, so a domain as typed matches. A truthy
`suppression.enabled` (`1`, `'1'`) now registers the webhook route too, as it already blocked mail.

3.0 also suppresses a recipient an SMTP server refuses ([Refused at send time](#refused-at-send-time)):

- While suppression is on, such a send returns null instead of throwing `UnexpectedResponseException`. Code that
  catches it to retry or alert no longer sees those replies; a failed-job alert no longer fires for them.
- `SuppressionReason::invalid` is new: a `match` on the enum needs an arm for it.
- `mail.manager` is now `EmailIntegrity\Mail\GuardedMailManager`, a subclass of Laravel's `MailManager`, and an
  `smtp` mailer's `getSymfonyTransport()` is a `Mail\RefusedRecipientGuard`. A test that asserts an `EsmtpTransport`
  reads the guard's `transport` property instead.
- After the deploy, suppress the addresses already stored once: `php artisan email-integrity:suppress-invalid users
  --dry-run`, then without `--dry-run` ([Addresses already stored](#addresses-already-stored)).

### From 2.0

2.1 rewords `suppressed` in every language to say why the address is refused and what to do. Nothing to change but
a test that asserts the old line. A `suppressed` key in your `lang/vendor/email-integrity/{locale}/messages.php` still
wins; delete it to read the package's line.

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
