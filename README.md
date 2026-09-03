# Laravel Email Integrity

Refuse disposable and unroutable email domains at the point they are entered, and collapse
alias spellings so one mailbox cannot become several accounts.

Two questions, both about the **domain**, neither of which sends anything:

- **Is it a throwaway?** A local hash-set lookup against an aggregated list, plus your own
  allow/deny entries.
- **Can it receive mail at all?** An MX / A / AAAA lookup, which catches the dead typo
  (`gmial.cm`) that no blocklist will ever carry.

Neither says the mailbox exists — only a verification link does that, and this package stops
short of it so your app decides when to demand one. It is not a substitute either way: a
typo-squat like `gmai.com` has a live MX and accepts mail, so the user clicks the link and
verifies fine. Verification alone does not stop that; the list does. Use both.

## Install

```bash
composer require ismailnakkar/laravel-email-integrity
php artisan vendor:publish --tag=email-integrity-config
php artisan email-integrity:update
```

Upstream publishes daily, so refresh daily — and alert on failure, because a list that
silently stops updating is a list that silently stops working:

```php
Schedule::command('email-integrity:update')->dailyAt('03:00');
```

## Use

```php
public function rules(): array
{
    return [
        'email' => 'bail|required|max:255|email:strict|unique:users|not_disposable|routable_domain',
    ];
}
```

`not_disposable` and `routable_domain` are registered for you — no import, no `app()` — and
carry the package's messages in the app's current locale.

Every part of that order is load-bearing:

- **`bail`** is not optional. Without it a failed `email` stops nothing, so a malformed
  address still reaches the DNS lookup — several blocking resolutions on a name the attacker
  chose, for input already known to be invalid, on an unauthenticated endpoint.
- **`email:strict`, not `email`.** Bare `email` accepts the address literal
  `attacker@[192.0.2.1]`, which has no domain to read, so both rules pass it and the package
  goes inert. `strict` rejects it, along with quoted local parts and bare TLDs.
- **`max:255` before `email`**, because bounding the input is one length check and the parser
  is a full lexer pass over whatever was pasted.
- **Disposable before routable.** Disposable is a free in-memory lookup that can refuse on its
  own; routable is the network. Never put the network first.

Rule objects exist if you want the type back. Same decision, one difference in how you re-word
the message: both forms honour the package's own `email-integrity::messages.*` lines, but a
`validation.custom.email.not_disposable` line reaches only the string form — a rule object's
message goes straight into the error bag without passing Laravel's message lookup.

```php
use EmailIntegrity\Rules\NotDisposable;
use EmailIntegrity\Rules\RoutableDomain;

'email' => ['bail', 'required', 'max:255', 'email:strict', 'unique:users',
    app(NotDisposable::class), app(RoutableDomain::class)],
```

Outside validation:

```php
use EmailIntegrity\EmailIntegrity;

app(EmailIntegrity::class)->isDisposable($address);
app(EmailIntegrity::class)->hostResolves($address);
app(EmailIntegrity::class)->canonical($address);
```

## Where not to put the routable check

Not on a payout or login path. It is the one rule here that can fail for reasons outside the
user's control, and a DNS blip should never hold someone's money. It belongs on entry forms,
where a failure means "check your typo" and the user can act on it.

`fail_open` (default `true`) decides what an unreachable resolver means. A dead domain is
still refused while the resolver is healthy — the lookup probes a root server to tell "no such
record" from "resolver unavailable". During a real outage the two are indistinguishable, and
`fail_open` decides.

## One mailbox, one account

`a+1@gmail.com`, `a.b@gmail.com` and `ab@googlemail.com` are **one inbox** and three rows past
`unique:users`. Canonicalise before you validate and core's `unique` does the rest — what you
check is what you store:

```php
protected function prepareForValidation(): void
{
    $this->merge(['email' => app(EmailIntegrity::class)->canonical($this->email) ?? $this->email]);
}
```

Keep the unique index on `email`. The rule is the friendly error; the index is the
enforcement, and only the index survives two simultaneous signups.

The cost: you store `ab@gmail.com`, not `A.B+promo@GoogleMail.com`. Delivery is identical —
the canonical form reaches the same inbox by definition — but the user's tag is gone.

**To keep the address as typed**, add a nullable unique `email_canonical` column and let the
cast fill it, then validate `unique:users,email_canonical`:

```php
use EmailIntegrity\Casts\CanonicalEmail;

protected function casts(): array
{
    return ['email' => CanonicalEmail::class];
}
```

A cast, not a `saving` hook: `saveQuietly()` fires no events, and a null left there is a row
the unique index will not police. `CanonicalEmail::class . ':identity'` renames the column.

Keep that column out of `$fillable`. The cast writes it; the request must not. With
`$guarded = []` an `email_canonical` key in the request body overwrites what the cast
computed, and `User::create($request->all())` registers unlimited accounts on one mailbox.

Backfill before adding the index — an existing table usually has collisions:

```php
foreach (User::lazyById() as $user) {
    $user->forceFill(['email_canonical' => app(EmailIntegrity::class)->canonical($user->email) ?? $user->email])
        ->saveQuietly();
}
```

```sql
select email_canonical, count(*) from users group by email_canonical having count(*) > 1;
```

### What it will and will not merge

- **Dots collapse for Gmail only.** Outlook, Yahoo, iCloud, Proton, Fastmail and every
  self-hosted domain treat `a.b@` and `ab@` as two people. Merging them would refuse a real
  signup, which is worse than the duplicate it prevents.
- **`+` is stripped everywhere** — every mass provider, Postfix and Exim treat it as a tag.
  **`-` never is:** it is qmail's delimiter but an ordinary name character elsewhere, so
  folding `mary-jane@` onto `mary@` would lock out a real person.
- **A quoted (`"a+b"@example.com`) or empty (`+3@example.com`) local part has no canonical
  form.** Store the address as typed instead — a nullable unique index accepts unlimited NULLs.
- **Nothing beyond Gmail is folded.** Shared MX is not proof of a shared namespace;
  yahoo.com and ymail.com are the same infrastructure and different people.

## Configuration

| Key                      | Purpose                                                                            |
|--------------------------|------------------------------------------------------------------------------------|
| `enabled`                | Master switch. Off leaves both rules inert, so a bad list needs no deploy to undo.  |
| `allow`                  | Wins over everything, including the fetched list.                                   |
| `deny`                   | Your own additions, for lookalikes the lists have not caught yet (`gmail2.gq`).     |
| `disposable.sources`     | The lists to merge.                                                                 |
| `disposable.min_domains` | A fetch returning fewer aborts the update; the previous list survives.              |
| `host.fail_open`         | What an unreachable resolver means.                                                 |

`allow` and `deny` cover the domain **and every subdomain**. The fetched list is matched
exactly, so a provider with a wildcard MX (`anything.mailinator.com` is a live inbox) is a
one-line `deny` entry.

## Notes

- The domain is taken from the **last** `@`. Splitting on the first hands you the wrong domain
  for a quoted local part (`"a@b"@example.com`) — a blocklist bypass using a legal address.
- Domains are normalised to punycode first: `灵.cc` and `xn--5nx.cc` are one lookup, and the
  lists publish only the second. Needs `ext-intl`; without it an IDN is matched as typed.
- The list is loaded once per request and cached — O(1) membership, so 75k entries cost
  nothing per address. Updates are write-then-rename, so a reader never sees a partial file.

## Testing

```bash
composer check
```
