<?php

declare(strict_types=1);

namespace EmailIntegrity\Tests;

use EmailIntegrity\EmailIntegrity;
use EmailIntegrity\Rules\NotDisposable;
use EmailIntegrity\Rules\NotSuppressed;
use EmailIntegrity\Rules\RoutableDomain;
use EmailIntegrity\SuppressedAddress;
use EmailIntegrity\SuppressionReason;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class RulesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withDisposableList(['mailinator.com']);
        config(['email-integrity.host.enabled' => false]);
    }

    private function validate(string $email, array $rules): bool
    {
        return Validator::make(['email' => $email], ['email' => $rules])->passes();
    }

    public function test_the_disposable_rule_fails_a_listed_domain(): void
    {
        $rule = $this->app->make(NotDisposable::class);

        $this->assertFalse($this->validate('a@mailinator.com', ['email', $rule]));
        $this->assertTrue($this->validate('a@gmail.com', ['email', $rule]));
    }

    public function test_the_disposable_rule_reports_a_translated_message(): void
    {
        $validator = Validator::make(
            ['email' => 'a@mailinator.com'],
            ['email' => [$this->app->make(NotDisposable::class)]],
        );

        $this->assertFalse($validator->passes());
        $this->assertStringContainsString(
            'Temporary email addresses',
            $validator->errors()->first('email'),
        );
    }

    /** With the check off the rule must be inert, not merely lenient. */
    public function test_the_host_rule_passes_when_the_check_is_disabled(): void
    {
        $this->assertTrue($this->validate('a@nonexistent.invalid', [
            $this->app->make(RoutableDomain::class),
        ]));
    }

    public function test_the_host_rule_rejects_a_domain_that_cannot_receive_mail(): void
    {
        config([
            'email-integrity.host.enabled'   => true,
            'email-integrity.host.fail_open' => false,
        ]);

        // .invalid is reserved by RFC 2606 and can never resolve, so this needs no network.
        $validator = Validator::make(
            ['email' => 'a@definitely-not-real.invalid'],
            ['email' => [$this->app->make(RoutableDomain::class)]],
        );

        $this->assertFalse($validator->passes());
        $this->assertStringContainsString('cannot receive mail', $validator->errors()->first('email'));
    }

    public function test_an_allowlisted_domain_skips_the_host_lookup(): void
    {
        config([
            'email-integrity.host.enabled'   => true,
            'email-integrity.host.fail_open' => false,
            'email-integrity.allow'          => ['definitely-not-real.invalid'],
        ]);

        $this->assertTrue($this->validate('a@definitely-not-real.invalid', [
            $this->app->make(RoutableDomain::class),
        ]));
    }

    /**
     * fail_open only fires during a real outage. With a working resolver the root-server
     * probe succeeds, so a genuinely dead domain is still refused — and that verdict, being
     * definitive, is the only kind written to the cache.
     */
    public function test_fail_open_still_refuses_a_dead_domain_while_the_resolver_works(): void
    {
        if (! @checkdnsrr('a.root-servers.net', 'A')) {
            $this->markTestSkipped('No resolver: the probe cannot tell an outage from a dead domain.');
        }

        config([
            'email-integrity.host.enabled'   => true,
            'email-integrity.host.fail_open' => true,
        ]);

        $this->assertFalse($this->validate('a@definitely-not-real.invalid', [
            $this->app->make(RoutableDomain::class),
        ]));

        $this->assertTrue(Cache::has('email-integrity:host:' . hash('xxh128', 'definitely-not-real.invalid')));
    }

    /** A resolver that times out would make every signup wait out four lookups, so an outage is remembered briefly. */
    public function test_a_remembered_outage_answers_fail_open_without_a_lookup(): void
    {
        if (! @checkdnsrr('a.root-servers.net', 'A')) {
            $this->markTestSkipped('No resolver: a dead domain would answer fail_open anyway.');
        }

        config([
            'email-integrity.host.enabled'   => true,
            'email-integrity.host.fail_open' => true,
        ]);
        Cache::put('email-integrity:resolver-down', true, 30);

        $this->assertTrue($this->validate('a@definitely-not-real.invalid', [
            $this->app->make(RoutableDomain::class),
        ]));
        $this->assertFalse(Cache::has('email-integrity:host:' . hash('xxh128', 'definitely-not-real.invalid')));
    }

    /** RFC 7505: example.com publishes `MX 0 .` beside a live A record, which says it takes no mail. */
    public function test_a_null_mx_refuses_mail_despite_an_a_record(): void
    {
        if (! @checkdnsrr('a.root-servers.net', 'A')) {
            $this->markTestSkipped('No resolver: the lookup cannot run.');
        }

        config(['email-integrity.host.enabled' => true]);

        $this->assertFalse($this->app->make(EmailIntegrity::class)->hostResolves('a@example.com'));
    }

    /**
     * Why the README says `email:strict` and not `email`. An address literal has no domain
     * to read, so both rules here pass it by design — which makes bare `email` a hole big
     * enough to drive the whole package through. This fails if that stops being true.
     */
    public function test_an_address_literal_needs_strict_to_be_refused(): void
    {
        $rules = [$this->app->make(NotDisposable::class), $this->app->make(RoutableDomain::class)];

        $this->assertTrue($this->validate('attacker@[192.0.2.1]', ['email', ...$rules]));
        $this->assertFalse($this->validate('attacker@[192.0.2.1]', ['email:strict', ...$rules]));
    }

    public function test_the_string_rule_names_work_in_a_pipe_string(): void
    {
        $rules = 'bail|required|max:255|email:strict|not_disposable|routable_domain';

        $this->assertTrue($this->validate('a@gmail.com', explode('|', $rules)));
        $this->assertFalse($this->validate('a@mailinator.com', explode('|', $rules)));
    }

    /**
     * The string rule must carry the package's own message. Validator::extend stores its
     * third argument raw and never translates it, so getting this wrong prints the key.
     */
    public function test_the_string_rule_reports_the_translated_message(): void
    {
        $validator = Validator::make(['email' => 'a@mailinator.com'], ['email' => 'not_disposable']);

        $this->assertFalse($validator->passes());
        $this->assertStringContainsString('Temporary email addresses', $validator->errors()->first('email'));
    }

    /** A complaint keeps the inbox out under any spelling, so a new account cannot route mail back into it. */
    public function test_the_suppression_rule_refuses_a_blocked_address(): void
    {
        config(['email-integrity.suppression.enabled' => true]);
        SuppressedAddress::suppress('jane.doe@gmail.com', SuppressionReason::complained);

        $validator = Validator::make(['email' => 'janedoe+new@gmail.com'], ['email' => [new NotSuppressed]]);

        $this->assertFalse($validator->passes());
        $this->assertSame('This email address cannot receive our mail.', $validator->errors()->first('email'));
        $this->assertTrue($this->validate('someone@gmail.com', [new NotSuppressed]));
    }

    public function test_the_suppression_rule_has_a_string_name(): void
    {
        config(['email-integrity.suppression.enabled' => true]);
        SuppressedAddress::suppress('jane.doe@gmail.com', SuppressionReason::complained);

        $validator = Validator::make(['email' => 'janedoe+new@gmail.com'], ['email' => 'bail|email:strict|not_suppressed']);

        $this->assertFalse($validator->passes());
        $this->assertSame('This email address cannot receive our mail.', $validator->errors()->first('email'));
        $this->assertTrue($this->validate('someone@gmail.com', ['not_suppressed']));
    }
}
