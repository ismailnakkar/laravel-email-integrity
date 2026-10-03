# Laravel Email Integrity

Refuse disposable and unroutable email domains at the point they are entered, collapse alias spellings so one
mailbox cannot become several accounts, and, once you turn it on, stop mailing the addresses your mail provider
reports as bounced or complained.

The two domain checks never send anything:

- **Is it a throwaway?** A local hash-set lookup against an aggregated list, plus your own allow/deny entries.
- **Can it receive mail at all?** An MX / A / AAAA lookup, which catches the dead typo (`gmial.cm`) that no blocklist
  will ever carry.

Neither says the mailbox exists — only a verification link does that, and this package stops short of it so your app
decides when to demand one. It is not a substitute either way: a typo-squat like `gmai.com` has a live MX and accepts
mail, so the user clicks the link and verifies fine. Verification alone does not stop that; the list does. Use both.

## Requirements

PHP 8.2+ and Laravel 12.1+ or 13. `ext-intl` is suggested: without it an internationalised domain is matched as typed,
so the unicode spelling of a listed domain is not caught.

Suppression's webhook also needs `symfony/webhook` (`^7.4 || ^8.0`) and your provider's Symfony bridge: for Resend,
`symfony/resend-mailer` `^7.4.18 || ^8.1.6`, the first to refuse a replayed event and to read `email.suppressed` (an
older one is a Composer conflict). Nothing is required while suppression is off.

Left to your app:

- Refresh the disposable list daily, and alert on failure.
- `bail`, `max:255` and `email:strict` ahead of the rules (see [Use](#use)).
- The unique index that makes one mailbox one account, with a binary collation on MySQL and MariaDB.
- For suppression: the `suppressed_addresses` table, the webhook route (outside CSRF, rate limited), a provider
  endpoint subscribed to the right events only, `mail.from.address` on the provider's sending domain, and clearing the
  provider's own list when you lift an address.
- One recipient per message wherever someone else typed a recipient: the provider's event names the message's whole To
  list, and every address on it is suppressed.
- A parser that checks the provider's IP addresses (Postmark, Brevo) needs your trusted proxies set, or it sees your
  proxy's address instead.

## Install

```bash
composer require ismailnakkar/laravel-email-integrity
php artisan vendor:publish --tag=email-integrity-config
php artisan email-integrity:update
```

Upstream publishes daily, so refresh daily — and alert on failure, because a list that silently stops updating is a
list that silently stops working:

```php
Schedule::command('email-integrity:update')->dailyAt('03:00');
```

A failed update exits non-zero and passes the exception to `report()`, so it reaches your exception handler with no
`onFailure` hook.

## Setup

| Key                             | Purpose                                                                           |
|---------------------------------|-----------------------------------------------------------------------------------|
| `enabled`                       | Master switch. Off leaves both domain rules inert, so a bad list needs no deploy.  |
| `allow`                         | Wins over everything, including the fetched list.                                  |
| `deny`                          | Your own additions, for lookalikes the lists have not caught yet (`gmail2.gq`).    |
| `disposable.sources`            | The lists to merge.                                                                |
| `disposable.min_domains`        | A source returning fewer aborts the update; the previous list survives.            |
| `host.fail_open`                | What an unreachable resolver means.                                                |
| `suppression.enabled`           | Suppression on or off. Off by default.                                             |
| `suppression.own_inboxes`       | Your contact or admin inboxes: a block on one is reported as an error.             |
| `suppression.webhook.parser`    | The Symfony `RequestParserInterface` that reads your provider's events.            |
| `suppression.webhook.secret`    | Its signing secret, `RESEND_WEBHOOK_SECRET` by default.                            |

`allow` and `deny` cover the domain **and every subdomain**. The fetched list is matched exactly, so a provider with a
wildcard MX (`anything.mailinator.com` is a live inbox) is a one-line `deny` entry. The master switch does not touch
suppression: that list is yours, not a third party's.

### Suppression

An opt-in blocklist: a complaint, a permanent bounce or the provider's own block puts the address in
`suppressed_addresses`, and no mail reaches it again until you lift it.

1. Install the webhook's packages:

   ```bash
   composer require symfony/webhook symfony/resend-mailer
   ```

2. Create the table. A new app publishes the migration:

   ```bash
   php artisan vendor:publish --tag=email-integrity-migrations
   php artisan migrate
   ```

   **Adopting an existing table.** A `suppressed_addresses` table with `id`, `email` (a unique index on it), `reason`
   as `bounced` or `complained`, and timestamps works as it is. The unique index is what makes concurrent deliveries
   for one address write one row. Run `SuppressedAddress::rekey()` once, for example in a migration whose `down()`
   does nothing: each row is rewritten to its key (a complaint to its inbox, a bounce to its lowercase, punycode
   spelling), and a row whose key another row already holds is deleted, as your database's collation sees it. A row
   with no key is deleted first, and logged as a warning with its `email` and `reason`. Preview both first (the `''`
   group holds the rows with no key):

   ```php
   SuppressedAddress::query()->get()
       ->groupBy(fn (SuppressedAddress $row) => SuppressedAddress::key($row->email, $row->reason))
       ->filter(fn ($rows, $key) => $key === '' || $rows->count() > 1);
   ```

   `rekey()` recomputes each key from the stored `email`, so it cannot unfold a domain stored already in transitional
   punycode (`strasse.de` for `straße.de`). Check rows whose domain holds `ss` or `xn--` against the original
   addresses first.

3. Turn it on in the published config, with a literal `true` rather than an env switch a deploy can forget:

   ```php
   'suppression' => [
       'enabled' => true,
       'own_inboxes' => [env('MAIL_CONTACT_ADDRESS')],
       // ...
   ],
   ```

   Empty entries in `own_inboxes` are skipped, so an unset variable is harmless.

4. Put the provider's signing secret in `.env` (`RESEND_WEBHOOK_SECRET=whsec_…`).

5. Route the webhook outside the `web` group, so it has no CSRF check and no session, behind a rate limiter:

   ```php
   use EmailIntegrity\Http\SuppressionWebhook;

   Route::post('webhooks/mail', SuppressionWebhook::class)->middleware('throttle:60,1');
   ```

6. Point the provider at that URL. For Resend, subscribe the endpoint to `email.bounced`, `email.complained` and
   `email.suppressed` only: its `contact.*` and `suppression.*` events fail Symfony's payload check with a 406 that
   looks like a forgery, and Svix retries them until it disables the endpoint.

**Another provider:** set `suppression.webhook.parser` to its Symfony parser (for example
`Symfony\Component\Mailer\Bridge\Postmark\Webhook\PostmarkRequestParser`, from `symfony/postmark-mailer`) and the
secret to what that parser expects. Complaints are recorded for any provider. A bounce is recorded only when the payload
marks it permanent the way Resend's does (`data.bounce.type`), so another provider's bounces write nothing. Amazon SES
has no Symfony parser; write your own `RequestParserInterface` for it.

## Use

```php
public function rules(): array
{
    return [
        'email' => 'bail|required|max:255|email:strict|unique:users|not_disposable|routable_domain',
    ];
}
```

`not_disposable`, `routable_domain` and `not_suppressed` are registered for you — no import, no `app()` — and carry the
package's messages in the app's current locale.

Every part of that order is load-bearing:

- **`bail`** is not optional. Without it a failed `email` stops nothing, so a malformed address still reaches the DNS
  lookup — several blocking resolutions on a name the attacker chose, for input already known to be invalid, on an
  unauthenticated endpoint.
- **`email:strict`, not `email`.** Bare `email` accepts the address literal `attacker@[192.0.2.1]`, which has no domain
  to read, so both rules pass it and the package goes inert. `strict` rejects it, along with quoted local parts and bare
  TLDs.
- **`max:255` before `email`**, because bounding the input is one length check and the parser is a full lexer pass over
  whatever was pasted.
- **Disposable before routable.** Disposable is a free in-memory lookup that can refuse on its own; routable is the
  network. Never put the network first.

Rule objects exist if you want the type back. Same decision, one difference in how you re-word the message: both forms
honour the package's own `email-integrity::messages.*` lines, but a `validation.custom.email.not_disposable` line
reaches only the string form — a rule object's message goes straight into the error bag without passing Laravel's
message lookup.

```php
use EmailIntegrity\Rules\NotDisposable;
use EmailIntegrity\Rules\NotSuppressed;
use EmailIntegrity\Rules\RoutableDomain;

'email' => ['bail', 'required', 'max:255', 'email:strict', 'unique:users',
    app(NotDisposable::class), app(NotSuppressed::class), app(RoutableDomain::class)],
```

`not_suppressed` (`NotSuppressed`) refuses an address the suppression list stops, so a new account cannot route mail
back into an inbox that complained; it passes everything while suppression is off. Its message is
`email-integrity::messages.suppressed`; translate it in `lang/vendor/email-integrity/{locale}/messages.php`.

Outside validation:

```php
use EmailIntegrity\EmailAddress;
use EmailIntegrity\EmailIntegrity;

app(EmailIntegrity::class)->isDisposable($address);
app(EmailIntegrity::class)->hostResolves($address);
EmailAddress::canonical($address);
```

### Where not to put the routable check

Not on a payout or login path. It is the one rule here that can fail for reasons outside the user's control, and a DNS
blip should never hold someone's money. It belongs on entry forms, where a failure means "check your typo" and the user
can act on it.

`fail_open` (default `true`) decides what an unreachable resolver means. A dead domain is still refused while the
resolver is healthy — the lookup probes a root server to tell "no such record" from "resolver unavailable". During a
real outage the two are indistinguishable, and `fail_open` decides. An unreachable resolver is remembered for 30
seconds, so a resolver that times out costs one round of lookups, not one per signup.

### One mailbox, one account

`a+1@gmail.com`, `a.b@gmail.com` and `ab@googlemail.com` are **one inbox** and three rows past `unique:users`.
Canonicalise before you validate and core's `unique` does the rest — what you check is what you store:

```php
use EmailIntegrity\EmailAddress;

protected function prepareForValidation(): void
{
    $this->merge(['email' => EmailAddress::canonical($this->email) ?? $this->email]);
}
```

Keep the unique index on `email`, with a binary collation (see [Collation](#collation)). The rule is the friendly error;
the index is the enforcement, and only the index survives two simultaneous signups.

The cost: you store `ab@gmail.com`, not `A.B+promo@GoogleMail.com`, so canonicalise the address at sign-in and password
reset too.
Delivery is identical on every provider that treats `+` as a tag, but the user's tag is gone.

**To keep the address as typed**, add a nullable unique `email_canonical` column and let the cast fill it, then
validate `unique:users,email_canonical`:

```php
use EmailIntegrity\Casts\CanonicalEmail;

protected function casts(): array
{
    return ['email' => CanonicalEmail::class];
}
```

A cast, not a `saving` hook: `saveQuietly()` fires no events, and a null left there is a row the unique index will not
police. `CanonicalEmail::class . ':identity'` renames the column.

Keep that column out of `$fillable`. The cast writes it; the request must not. With `$guarded = []` an
`email_canonical` key in the request body overwrites what the cast computed, and `User::create($request->all())`
registers unlimited accounts on one mailbox.

Backfill before adding the index — an existing table usually has collisions:

```php
foreach (User::lazyById() as $user) {
    $user->forceFill(['email_canonical' => EmailAddress::canonical($user->email) ?? $user->email])
        ->saveQuietly();
}
```

```sql
select email_canonical, count(*) from users group by email_canonical having count(*) > 1;
```

#### Collation

On MySQL and MariaDB the column the unique index sits on needs a binary collation. Their defaults
(`utf8mb4_unicode_ci`, `utf8mb4_0900_ai_ci`) fold `ｊａｃｋ@gmail.com`, `jäck@gmail.com` and `jac\u{212A}@gmail.com` (KELVIN
SIGN) onto `jack@gmail.com`. `email:strict` accepts all three and `canonical()` keeps them apart, so under such a
collation a stranger who registers one first locks the real owner out. `canonical()` lowercases ASCII itself, so the
binary comparison is exact:

```php
$table->string('email_canonical')->nullable()->unique()->collation('utf8mb4_bin');
```

Any other column that decides whether an address is taken needs the same: give `users.email` (or the canonical column)
a binary collation. A collation belongs to the column on MySQL and MariaDB, not the index, so a binary `users.email` makes
every `where email = ?` case-sensitive, including Laravel's stock sign-in and password-reset lookups. When you keep the
address as typed, leave `users.email` under its default collation, drop its unique index, and rely on `email_canonical`
alone. PostgreSQL and SQLite compare exactly by default.

In both modes, look the account up at sign-in and password reset by the binary column, not by `email`:
`where email_canonical = EmailAddress::canonical($typed) ?? $typed`. Under a case-insensitive `email`, a lookup by
`email` can return a lookalike's row.

What it will and will not merge:

- **Dots collapse for Gmail only.** Outlook, Yahoo, iCloud, Proton, Fastmail and every self-hosted domain treat `a.b@`
  and `ab@` as two people. Merging them would refuse a real signup, which is worse than the duplicate it prevents.
- **`+` is stripped everywhere** — every mass provider, Postfix and Exim treat it as a tag. **`-` never is:** it is
  qmail's delimiter but an ordinary name character elsewhere, so folding `mary-jane@` onto `mary@` would lock out a real
  person.
- **A quoted (`"a+b"@example.com`) or empty (`+3@example.com`) local part has no canonical form.** Store the address as
  typed instead — a nullable unique index accepts unlimited NULLs.
- **Nothing beyond Gmail is folded.** Shared MX is not proof of a shared namespace; yahoo.com and ymail.com are the
  same infrastructure and different people.

### Suppressed addresses

The send guard needs nothing from you. Ask first where a send that silently does not happen would lose something — an
alert marked as sent, a pending address cleared:

```php
use EmailIntegrity\SuppressedAddress;
use EmailIntegrity\SuppressionReason;

SuppressedAddress::blocking($user->email);                            // the row that stops mail to it, or null
SuppressedAddress::blocking(...$admins->pluck('email')->all());       // any of several
SuppressedAddress::suppress($address, SuppressionReason::complained); // true for a new row
SuppressedAddress::lift($address);                                    // the rows deleted
```

To unblock an address:

```bash
php artisan email-integrity:lift someone@example.com
```

Remove it from the provider's own suppression list too, or the next send comes back as the provider's block and
suppresses it again.

A new row fires Eloquent's `created` event, so `SuppressedAddress::created(fn ($row) => ...)` reacts to it.
`EmailAddress::bare()` (the address inside `Name <…>`) and `EmailAddress::literal()` (that address lowercased, with a
punycode domain) are the address rules the keys are built from.

## Guarantees

- A failed or truncated fetch never replaces the disposable list: nothing is written until every source answers with at
  least `min_domains` domains, and the write is write-then-rename, so a reader never sees a partial file.
- The domain is taken from the **last** `@`. Splitting on the first hands you the wrong domain for a quoted local part
  (`"a@b"@example.com`) — a blocklist bypass using a legal address.
- Domains are compared in punycode, nontransitional as Symfony Mime sends them: `灵.cc` and `xn--5nx.cc` are one
  lookup, and `straße.de` stays apart from `strasse.de`.
- The list is loaded once per request or queued job and cached — O(1) membership, so 75k entries cost nothing per
  address, and a long-running worker sees the next update.
- A DNS verdict is cached only when the resolver answered; "could not tell" is never stored.

Suppression:

| Event (Symfony name)                                                          | Writes                                  |
|-------------------------------------------------------------------------------|-----------------------------------------|
| `MailerEngagementEvent::SPAM`                                                 | `complained`, keyed on the inbox        |
| `MailerDeliveryEvent::BOUNCE` marked permanent (Resend `data.bounce.type`)    | `bounced`, keyed on the spelling        |
| Resend `email.suppressed` (`DROPPED`; `email.failed` is `DROPPED` too)        | `bounced`, keyed on the spelling        |
| anything else, temporary bounces and `email.failed` included                  | nothing                                 |

- **A complaint blocks every spelling of the inbox** (its `canonical()` form): only the inbox's owner can complain.
  **A bounce blocks only the address that bounced**: a stranger who types `member+x@corp.example` into a public form,
  on a server without `+` tags, cannot block `member@corp.example`.
- **Only a printable-ASCII key is stored or looked up.** A case-insensitive collation such as `utf8mb4_unicode_ci`
  treats `ｊａｃｋ@`, `jäck@` and the KELVIN SIGN in `jac\u{212A}@` as `jack@`, so a stranger's bounce on one would
  block the real inbox. The cost: an SMTPUTF8 mailbox, or a unicode domain without `ext-intl`, is never suppressed
  here; the provider's own list still drops it.
  `rekey()` deletes a row whose email has no key, since under such a collation it could still match an ASCII lookalike.
- Writes are insert-if-absent: a redelivered event changes nothing, and the first reason sticks.
- Only signed events write. The webhook answers 401 to everything while its secret is missing or gives a key under 16
  bytes. For Resend, or any secret starting `whsec_`, the key is the base64 after `whsec_`, decoded strictly (Symfony's
  parser alone accepts events forged with an empty key when the secret is `whsec_` or has a non-base64 tail); for
  another parser it is the secret itself.
- A signed event type the parser does not model is answered 204, so the provider does not retry it. A signed Resend
  bounce, complaint or block the parser cannot read (Symfony's `Invalid date`) is passed to `report()` and refused, so
  the provider retries it once the parser is fixed. Any other refusal keeps the parser's own status (406), which the
  provider retries. Each refusal, the 401 included, logs a warning, since Laravel never reports the 406.
- Misconfiguration is checked when the webhook runs, never at boot, so `artisan` and CI keep working: it throws a
  `LogicException` naming the `composer require` to run when `symfony/webhook` or the configured parser is missing or
  is not a `RequestParserInterface`, when `suppression.enabled` is off, and when an event names a sender while
  `mail.from.address` is unset (a reported 500; the provider retries for about a day, so set it before then or replay
  the failed messages).
- The parser reads the raw body, so `TrimStrings` and `ConvertEmptyStringsToNull` cannot change what the signature
  covers.
- When the payload names a sender, an event from another sending domain (neither `mail.from.address`'s domain nor a
  subdomain of it; `evil-example.com` is not `example.com`) is ignored and logged at info. One provider account often
  sends for several domains.
- The guard checks every To, Cc and Bcc address at send time, so a row added after a mail was queued still stops it.
  A hit drops the whole message and logs `Mail not sent: the address is suppressed.` (warning, with `email`, `reason`
  and `subject`). Otherwise it answers null, so your own `MessageSending` listeners still run, after it. Mail
  notifications pass the same event. A `MessageSending` listener that runs before the guard and returns anything but
  null skips it, so return nothing from your own.
- A block on one of `own_inboxes` is passed to `report()`, naming the address and the reason. The inbox is blocked like
  any other: the provider skips it anyway.
- Off, `blocking()` answers null before touching the table, so the guard, `NotSuppressed` and your own checks all
  pass.

## Testing

In your own tests:

- `Mail::fake()` never fires `MessageSending`, so test the guard by sending on the `array` mailer.
- Build rows with `SuppressedAddress::suppress()`.
- Sign a Resend event the way Svix does:

  ```php
  $body = json_encode($payload);
  $signature = base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", $key, true)); // $key: base64-decoded secret

  $this->call('POST', '/webhooks/mail', server: [
      'CONTENT_TYPE' => 'application/json',
      'HTTP_SVIX_ID' => $id,
      'HTTP_SVIX_TIMESTAMP' => (string) $timestamp, // within 300 seconds of now
      'HTTP_SVIX_SIGNATURE' => "v1,{$signature}",
  ], content: $body);
  ```

Developing the package: `composer check` (Pint, then PHPUnit on SQLite in memory).

## Upgrading from 1.0

No API breaks, and suppression stays off until you turn it on. Stored canonical values for some domains change:

- **Nontransitional IDNA.** `straße.de` now becomes `xn--strae-oqa.de`, as it is sent, instead of folding onto
  `strasse.de`, a different domain. Recompute stored `canonical()` values whose domain holds `ß`, `ς` or a zero-width
  joiner or non-joiner — rerun the backfill above for rows whose domain is not ASCII.
- **Collation.** Check the identity column's collation against [Collation](#collation).
- **`disposable.min_domains` applies to each source**, as its comment always said, not to the merged total. A small
  extra source belongs in `deny` instead.
- **`EmailIntegrity::canonical()` is deprecated**; `EmailAddress::canonical()` is the same rule. It goes in 2.0.
- `disposable.cache.key` is no longer read; delete it from a published config.
- **The `symfony/resend-mailer` conflict applies even with suppression off.** The package now conflicts with
  `<7.4.18 || >=8.0,<8.1.6`, so an app that sends through Resend on an older bridge must raise it, or Composer refuses 1.1.
- **`UpdateDisposableDomainsCommand` is `@internal`.** Schedule it by name, `'email-integrity:update'`, not by class.

## Licence

MIT, see [LICENSE](LICENSE).
