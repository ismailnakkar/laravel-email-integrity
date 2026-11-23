<?php

declare(strict_types=1);

return [
    'disposable' => 'Temporary email addresses cannot be used here. Please use an address you can receive mail at.',
    'unroutable' => 'That email domain cannot receive mail. Please check it for a typo.',
    'suppressed' => 'Mail to this address has bounced or been reported as spam. Please use an address you can receive mail at.',
    'reason'     => [
        'bounced'    => 'The address bounced',
        'complained' => 'The recipient marked a mail as spam',
        'invalid'    => 'The mail server refused the address as invalid',
    ],
];
