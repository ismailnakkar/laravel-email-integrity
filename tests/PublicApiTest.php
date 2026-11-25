<?php

declare(strict_types=1);

namespace EmailIntegrity\Tests;

use EmailIntegrity\DisposableDomains;
use EmailIntegrity\EmailAddress;
use EmailIntegrity\EmailIntegrity;
use EmailIntegrity\EmailIntegrityServiceProvider;
use EmailIntegrity\Rules\AddressRules;
use EmailIntegrity\Rules\NotDisposable;
use EmailIntegrity\Rules\NotSuppressed;
use EmailIntegrity\Rules\RoutableDomain;
use EmailIntegrity\Rules\UnclaimedMailbox;
use PHPUnit\Framework\Attributes\TestWith;
use ReflectionClass;
use ReflectionMethod;

/** 3.0 and 4.0 closed these; reopening one commits the package to it again. */
class PublicApiTest extends TestCase
{
    #[TestWith([AddressRules::class])]
    #[TestWith([NotDisposable::class])]
    #[TestWith([NotSuppressed::class])]
    #[TestWith([RoutableDomain::class])]
    #[TestWith([UnclaimedMailbox::class])]
    #[TestWith([DisposableDomains::class])]
    #[TestWith([EmailIntegrityServiceProvider::class])]
    public function test_a_leaf_class_is_final(string $class): void
    {
        $this->assertTrue((new ReflectionClass($class))->isFinal());
    }

    #[TestWith([DisposableDomains::class, 'all'])]
    #[TestWith([EmailAddress::class, 'coveredBy'])]
    #[TestWith([EmailIntegrity::class, 'enabled'])]
    public function test_a_helper_is_private(string $class, string $method): void
    {
        $this->assertTrue((new ReflectionMethod($class, $method))->isPrivate());
    }

    /** Shorten partial-mocks it. */
    public function test_email_integrity_stays_open(): void
    {
        $this->assertFalse((new ReflectionClass(EmailIntegrity::class))->isFinal());
    }
}
