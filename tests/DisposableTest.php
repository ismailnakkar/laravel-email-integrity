<?php

declare(strict_types=1);

namespace EmailIntegrity\Tests;

use EmailIntegrity\EmailIntegrity;

class DisposableTest extends TestCase
{
    private function integrity(): EmailIntegrity
    {
        return $this->app->make(EmailIntegrity::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withDisposableList(['mailinator.com', 'gmai.com']);
        config(['email-integrity.host.enabled' => false]);
    }

    public function test_a_listed_domain_is_disposable(): void
    {
        $this->assertTrue($this->integrity()->isDisposable('someone@mailinator.com'));
    }

    /** The whole point: the Gmail typo-squat is a live catch-all, so verification alone misses it. */
    public function test_a_typo_squat_on_the_list_is_disposable(): void
    {
        $this->assertTrue($this->integrity()->isDisposable('rober3851@gmai.com'));
    }

    public function test_an_ordinary_domain_is_not(): void
    {
        $this->assertFalse($this->integrity()->isDisposable('someone@gmail.com'));
    }

    public function test_matching_ignores_case_and_a_trailing_dot(): void
    {
        $this->assertTrue($this->integrity()->isDisposable('Someone@MailiNator.CoM.'));
    }

    /**
     * A quoted local part may contain '@'. Splitting on the first one yields
     * "b"@example.com -> b"@example.com, and the blocklist silently misses.
     */
    public function test_a_quoted_local_part_does_not_hide_the_domain(): void
    {
        $this->assertTrue($this->integrity()->isDisposable('"weird@address"@mailinator.com'));
    }

    public function test_the_deny_list_blocks_domains_the_fetched_list_lacks(): void
    {
        config(['email-integrity.deny' => ['gmail2.gq']]);

        $this->assertTrue($this->integrity()->isDisposable('alex21cab@gmail2.gq'));
    }

    public function test_the_deny_list_covers_subdomains(): void
    {
        config(['email-integrity.deny' => ['throwaway.test']]);

        $this->assertTrue($this->integrity()->isDisposable('a@mail.throwaway.test'));
        $this->assertFalse($this->integrity()->isDisposable('a@notthrowaway.test'));
    }

    public function test_the_allow_list_wins_over_the_fetched_list(): void
    {
        config(['email-integrity.allow' => ['mailinator.com']]);

        $this->assertFalse($this->integrity()->isDisposable('someone@mailinator.com'));
    }

    public function test_the_allow_list_wins_over_the_deny_list(): void
    {
        config([
            'email-integrity.deny'  => ['example.test'],
            'email-integrity.allow' => ['example.test'],
        ]);

        $this->assertFalse($this->integrity()->isDisposable('a@example.test'));
    }

    public function test_the_master_switch_makes_the_check_inert(): void
    {
        config(['email-integrity.enabled' => false]);

        $this->assertFalse($this->integrity()->isDisposable('someone@mailinator.com'));
    }

    /** Shape is the `email` rule's complaint; this one must not double up on it. */
    public function test_an_unreadable_address_is_not_reported_as_disposable(): void
    {
        $this->assertFalse($this->integrity()->isDisposable('no-at-sign'));
        $this->assertFalse($this->integrity()->isDisposable('trailing@'));
        $this->assertFalse($this->integrity()->isDisposable(null));
        $this->assertFalse($this->integrity()->isDisposable(['an', 'array']));
    }

    /**
     * The lists publish `xn--` only. Converting at the DNS call was too late: the list
     * lookup had already missed, so the unicode spelling of a listed domain walked past.
     *
     * Both sides need the same pass: the lookup now asks about `xn--5nx.cc`, so an allow
     * or deny entry typed in unicode has to reach it too. An `allow` that silently misses
     * is a real user refused.
     */
    public function test_a_unicode_domain_does_not_dodge_a_punycode_entry(): void
    {
        if (! function_exists('idn_to_ascii')) {
            $this->markTestSkipped('ext-intl is absent, so an IDN stays as typed.');
        }

        $this->withDisposableList(['xn--5nx.cc']);

        $this->assertTrue($this->integrity()->isDisposable("a@\u{7075}.cc"));

        config(['email-integrity.allow' => ["\u{7075}.cc"]]);
        $this->assertFalse($this->integrity()->isDisposable('a@xn--5nx.cc'));

        config(['email-integrity.allow' => [], 'email-integrity.deny' => ["\u{7075}.cc"]]);
        $this->withDisposableList([]);
        $this->assertTrue($this->integrity()->isDisposable('a@xn--5nx.cc'));
    }

    public function test_an_address_literal_is_not_matched_against_the_list(): void
    {
        $this->assertFalse($this->integrity()->isDisposable('a@[192.0.2.1]'));
    }

    /**
     * checkdnsrr() and dns_get_record() throw a ValueError on a NUL byte, and `@` suppresses
     * diagnostics but not Errors — so without the guard this is an uncaught 500 from a form
     * field, reachable through the documented facade call as well as the rule.
     */
    public function test_a_nul_byte_in_the_domain_is_refused_before_it_reaches_the_resolver(): void
    {
        config(['email-integrity.host.enabled' => true, 'email-integrity.host.fail_open' => false]);

        $this->assertTrue($this->integrity()->hostResolves("a@exam\0ple.com"));
        $this->assertFalse($this->integrity()->isDisposable("a@mailinator\0.com"));
    }

    public function test_a_missing_list_file_blocks_nothing(): void
    {
        config(['email-integrity.disposable.storage' => '/nonexistent/domains.json']);

        $this->assertFalse($this->integrity()->isDisposable('someone@mailinator.com'));
    }
}
