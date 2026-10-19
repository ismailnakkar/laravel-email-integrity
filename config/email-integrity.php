<?php

declare(strict_types=1);

use Symfony\Component\Mailer\Bridge\Resend\Webhook\ResendRequestParser;

return [
    // Off, the disposable and host checks pass everything: a bad list or a DNS outage needs no deploy.
    'enabled' => env('EMAIL_INTEGRITY_ENABLED', true),

    // Each entry covers the domain and its subdomains. `allow` wins over `deny` and the fetched list.
    'allow' => [],

    'deny' => [],

    // Runs email-integrity:update daily at 03:00 on every server. False leaves scheduling to you.
    'schedule' => true,

    'disposable' => [
        'sources' => [
            'https://cdn.jsdelivr.net/gh/disposable/disposable-email-domains@master/domains.json',
        ],

        'storage' => storage_path('framework/disposable_domains.json'),

        'timeout' => 30,

        // A source returning fewer aborts the update and keeps the previous list.
        'min_domains' => 1000,
    ],

    // An MX, or an A/AAAA record (RFC 5321 §5.1). `fail_open` is the answer while the resolver is unreachable.
    'host' => [
        'enabled'   => env('EMAIL_INTEGRITY_HOST_CHECK', true),
        'fail_open' => true,
        'cache_ttl' => 3600,
    ],

    // Turn on with a literal true, not an env switch a deploy can forget. `enabled` above does not touch it.
    // A block on one of `own_inboxes` (your contact or admin inbox) is reported as an error.
    'suppression' => [
        'enabled' => false,

        'own_inboxes' => [],

        'webhook' => [
            // Null registers no route: route SuppressionWebhook yourself.
            'path' => 'email-integrity/webhook',

            // The one host it answers on; null answers on any.
            'domain' => null,

            'parser' => ResendRequestParser::class,
            'secret' => env('RESEND_WEBHOOK_SECRET'),
        ],
    ],
];
