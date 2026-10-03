<?php

declare(strict_types=1);

namespace EmailIntegrity;

enum SuppressionReason: string
{
    case bounced = 'bounced';
    case complained = 'complained';
}
