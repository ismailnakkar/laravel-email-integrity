<?php

declare(strict_types=1);

use Symfony\Component\Mailer\Bridge\Resend\Webhook\ResendRequestParser;

return [
    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    |
    | Off leaves the disposable and host rules inert and always passing, so a bad
    | list or a DNS outage can be neutralised without a deploy. Identity
    | uniqueness is not affected — a duplicate account is not a DNS outage.
    |
    */
    'enabled' => env('EMAIL_INTEGRITY_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Domain lists
    |--------------------------------------------------------------------------
    |
    | `allow` wins over everything, including the fetched list — a false positive
    | upstream is a config edit, never a release. `deny` is your own additions,
    | for typo-squats and lookalikes the aggregated lists have not picked up yet
    | (gmail2.gq, gmai1.com). An entry covers the domain and every subdomain.
    |
    */
    'allow' => [],

    'deny' => [],

    /*
    |--------------------------------------------------------------------------
    | Disposable list
    |--------------------------------------------------------------------------
    |
    | `email-integrity:update` fetches each source, merges them and writes the
    | result to `storage`. A failed fetch aborts before the write, so the
    | previous list stays in place rather than being replaced by an empty one.
    |
    */
    'disposable' => [
        'sources' => [
            'https://cdn.jsdelivr.net/gh/disposable/disposable-email-domains@master/domains.json',
        ],

        'storage' => storage_path('framework/disposable_domains.json'),

        'timeout' => 30,

        // A source returning fewer than this is treated as truncated, not as a
        // shrinking list — the whole update aborts.
        'min_domains' => 1000,

        'cache' => [
            'enabled' => true,
            'store'   => null, // null = the default store
            'ttl'     => 86400,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Host resolution
    |--------------------------------------------------------------------------
    |
    | Catches dead typo domains that no list will ever carry. A domain with no MX
    | but a valid A record still accepts mail per RFC 5321 §5.1, so both count.
    |
    | `fail_open` decides what an unreachable resolver means. True is the right
    | default on a signup form: a DNS blip must not stop real registrations. Set
    | it false only where a wrong answer is costlier than a blocked user.
    |
    */
    'host' => [
        'enabled'   => env('EMAIL_INTEGRITY_HOST_CHECK', true),
        'fail_open' => true,
        'cache_ttl' => 3600,
    ],

    /*
    |--------------------------------------------------------------------------
    | Suppression
    |--------------------------------------------------------------------------
    |
    | The addresses your mail provider reported as a complaint, a permanent bounce
    | or its own block, never mailed again. Turn it on with a literal `true` in the
    | published file, not an env switch a deploy can forget. The master switch
    | above does not touch it: this list is yours, not a third party's.
    |
    | A block on one of `own_inboxes` (your contact or admin inbox) is reported as
    | an error, so someone fixes it. Empty entries are skipped.
    |
    */
    'suppression' => [
        'enabled' => false,

        'own_inboxes' => [],

        'webhook' => [
            // The URL the provider posts to; null registers no route (route it yourself with SuppressionWebhook).
            'path' => 'email-integrity/webhook',

            // The one host it answers on; null answers on any.
            'domain' => null,

            'parser' => ResendRequestParser::class,
            'secret' => env('RESEND_WEBHOOK_SECRET'),
        ],
    ],
];
