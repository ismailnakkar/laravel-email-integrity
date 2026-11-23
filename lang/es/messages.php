<?php

declare(strict_types=1);

return [
    'disposable' => 'Aquí no se pueden usar direcciones de correo temporales. Por favor, usa una dirección en la que puedas recibir correo.',
    'unroutable' => 'Ese dominio no puede recibir correo electrónico. Por favor, compruébalo por si hay una errata.',
    'suppressed' => 'Los correos enviados a esta dirección han rebotado o se han marcado como spam. Por favor, usa una dirección en la que puedas recibir correo.',
    'reason'     => [
        'bounced'    => 'Un correo enviado a esta dirección rebotó',
        'complained' => 'El destinatario marcó un correo como spam',
        'invalid'    => 'El servidor de correo rechazó la dirección por no válida',
    ],
];
